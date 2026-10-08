"use client";

import { FormEvent, useState } from "react";
import { CalendarCheck, Check, Pencil, PiggyBank, Plus, Target } from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Categoria, Conta, MetaFinanceira, PagamentoRecorrente } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";

const periodicidades = { semanal: "Semanal", mensal: "Mensal", anual: "Anual" } as const;
const situacoesRecorrencia = { ativo: "Ativo", pausado: "Pausado", encerrado: "Encerrado" } as const;
const situacoesMeta = { ativa: "Ativa", pausada: "Pausada", concluida: "Concluída" } as const;

export function PagamentosRecorrentes({ contas, categorias }: { contas: Conta[]; categorias: Categoria[] }) {
  const lista = useDados<{ dados: PagamentoRecorrente[] }>("financeiro/pagamentos-recorrentes");
  const [editando, definirEditando] = useState<PagamentoRecorrente | null | undefined>(undefined);
  const [pagando, definirPagando] = useState<PagamentoRecorrente | null>(null);

  return <>
    {lista.erro && <Aviso>{lista.erro}</Aviso>}
    <Cartao><div className="cartao-cabecalho"><div><h2>Pagamentos recorrentes</h2><p>Planeje gastos frequentes sem alterar o saldo antes do pagamento.</p></div><Botao onClick={() => definirEditando(null)}><Plus size={17} /> Novo pagamento</Botao></div>
      {lista.dados?.dados.length ? <div className="planejamento-grade">{lista.dados.dados.map((item) => <article className="planejamento-item" key={item.id}>
        <div className="planejamento-item-topo"><span className="planejamento-icone"><CalendarCheck size={20} /></span><div><strong>{item.descricao}</strong><small>{item.conta_nome} · {item.categoria_nome ?? "A classificar"}</small></div><span className={`etiqueta situacao-${item.situacao}`}>{situacoesRecorrencia[item.situacao]}</span></div>
        <div className="planejamento-valores"><strong>{formatarMoeda(item.valor, "BRL")}</strong><span>{periodicidades[item.periodicidade]}</span></div>
        <p>Próximo vencimento: <b>{formatarData(item.proximo_vencimento)}</b>{item.data_fim ? ` · até ${formatarData(item.data_fim)}` : ""}</p>
        <div className="acoes-linha"><Botao variante="secundario" onClick={() => definirEditando(item)}><Pencil size={16} /> Editar</Botao>{item.situacao === "ativo" && item.proximo_vencimento && <Botao onClick={() => definirPagando(item)}><Check size={16} /> Marcar como pago</Botao>}</div>
      </article>)}</div> : !lista.carregando && <Vazio titulo="Nenhum pagamento recorrente" descricao="Cadastre aluguel, internet, assinaturas ou outros gastos frequentes." />}
    </Cartao>
    {editando !== undefined && <Modal titulo={editando ? "Editar pagamento recorrente" : "Novo pagamento recorrente"} fechar={() => definirEditando(undefined)}><FormularioRecorrencia pagamento={editando ?? undefined} contas={contas} categorias={categorias} concluir={() => { definirEditando(undefined); lista.recarregar(); }} /></Modal>}
    {pagando && <Modal titulo="Confirmar pagamento" fechar={() => definirPagando(null)}><FormularioPagamentoRecorrente pagamento={pagando} concluir={() => { definirPagando(null); lista.recarregar(); }} /></Modal>}
  </>;
}

function FormularioRecorrencia({ pagamento, contas, categorias, concluir }: { pagamento?: PagamentoRecorrente; contas: Conta[]; categorias: Categoria[]; concluir: () => void }) {
  const [contaId, definirConta] = useState(pagamento ? String(pagamento.conta_id) : "");
  const [categoriaId, definirCategoria] = useState(pagamento?.categoria_id ? String(pagamento.categoria_id) : "");
  const [descricao, definirDescricao] = useState(pagamento?.descricao ?? "");
  const [valor, definirValor] = useState(pagamento?.valor ?? "");
  const [periodicidade, definirPeriodicidade] = useState<PagamentoRecorrente["periodicidade"]>(pagamento?.periodicidade ?? "mensal");
  const [vencimento, definirVencimento] = useState(pagamento?.proximo_vencimento ?? dataHojeLocal());
  const [dataFim, definirDataFim] = useState(pagamento?.data_fim ?? "");
  const [situacao, definirSituacao] = useState(pagamento?.situacao === "pausado" ? "pausado" : "ativo");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const contasBrl = contas.filter((conta) => conta.moeda === "BRL" && (conta.situacao === "ativa" || conta.id === pagamento?.conta_id));

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      const comum = { conta_id: contaId, categoria_id: categoriaId || null, descricao, valor, periodicidade, data_fim: dataFim || null };
      const corpo = pagamento ? { ...comum, proximo_vencimento: vencimento, situacao } : { ...comum, primeiro_vencimento: vencimento };
      await api(pagamento ? `financeiro/pagamentos-recorrentes/${pagamento.id}` : "financeiro/pagamentos-recorrentes", { method: pagamento ? "PATCH" : "POST", body: JSON.stringify(corpo) });
      concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }

  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Valores previstos não alteram o saldo. A saída será criada somente quando o pagamento for confirmado.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Seletor rotulo="Conta em reais" required value={contaId} onChange={(e) => definirConta(e.target.value)}><option value="">Selecione</option>{contasBrl.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</Seletor>
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} value={descricao} onChange={(e) => definirDescricao(e.target.value)} placeholder="Ex.: Aluguel" />
    <div className="formulario-duas-colunas"><Campo rotulo="Valor em reais" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Seletor rotulo="Frequência" value={periodicidade} onChange={(e) => definirPeriodicidade(e.target.value as PagamentoRecorrente["periodicidade"])}>{Object.entries(periodicidades).map(([valorOpcao, nome]) => <option key={valorOpcao} value={valorOpcao}>{nome}</option>)}</Seletor></div>
    <div className="formulario-duas-colunas"><Campo rotulo={pagamento ? "Próximo vencimento" : "Primeiro vencimento"} type="date" required value={vencimento} onChange={(e) => definirVencimento(e.target.value)} /><Campo rotulo="Encerrar em (opcional)" type="date" min={vencimento} value={dataFim} onChange={(e) => definirDataFim(e.target.value)} /></div>
    <Seletor rotulo="Categoria (opcional)" value={categoriaId} onChange={(e) => definirCategoria(e.target.value)}><option value="">A classificar</option>{categorias.filter((categoria) => categoria.tipo === "despesa").map((categoria) => <option key={categoria.id} value={categoria.id}>{categoria.nome}</option>)}</Seletor>
    {pagamento && <Seletor rotulo="Situação" value={situacao} onChange={(e) => definirSituacao(e.target.value)}><option value="ativo">Ativo</option><option value="pausado">Pausado</option></Seletor>}
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{pagamento ? "Salvar alterações" : "Criar recorrência"}</Botao></div>
  </form>;
}

function FormularioPagamentoRecorrente({ pagamento, concluir }: { pagamento: PagamentoRecorrente; concluir: () => void }) {
  const [data, definirData] = useState(dataHojeLocal());
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/pagamentos-recorrentes/${pagamento.id}/pagamentos`, { method: "POST", body: JSON.stringify({ data }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Esta confirmação criará uma saída de {formatarMoeda(pagamento.valor, "BRL")} e atualizará o saldo da conta.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <div className="resumo-formulario"><strong>{pagamento.descricao}</strong><span>Vence em {formatarData(pagamento.proximo_vencimento)}</span></div>
    <Campo rotulo="Data do pagamento" type="date" required value={data} onChange={(e) => definirData(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>Confirmar pagamento</Botao></div>
  </form>;
}

export function MetasFinanceiras() {
  const lista = useDados<{ dados: MetaFinanceira[] }>("financeiro/metas-financeiras");
  const [editando, definirEditando] = useState<MetaFinanceira | null | undefined>(undefined);
  const [aportando, definirAportando] = useState<MetaFinanceira | null>(null);

  return <>
    {lista.erro && <Aviso>{lista.erro}</Aviso>}
    <Cartao><div className="cartao-cabecalho"><div><h2>Metas financeiras</h2><p>Defina objetivos em reais e acompanhe seus aportes.</p></div><Botao onClick={() => definirEditando(null)}><Target size={17} /> Nova meta</Botao></div>
      {lista.dados?.dados.length ? <div className="planejamento-grade">{lista.dados.dados.map((meta) => <article className="planejamento-item" key={meta.id}>
        <div className="planejamento-item-topo"><span className="planejamento-icone"><PiggyBank size={20} /></span><div><strong>{meta.nome}</strong><small>{meta.prazo ? `Prazo: ${formatarData(meta.prazo)}` : "Sem prazo definido"}</small></div><span className={`etiqueta situacao-${meta.situacao}`}>{situacoesMeta[meta.situacao]}</span></div>
        <div className="meta-progresso"><div><strong>{formatarMoeda(meta.valor_acumulado, "BRL")}</strong><span>de {formatarMoeda(meta.valor_objetivo, "BRL")}</span><b>{Number(meta.percentual_concluido).toLocaleString("pt-BR", { maximumFractionDigits: 2 })}%</b></div><progress max="100" value={Math.min(Number(meta.percentual_concluido), 100)} /></div>
        {meta.aportes[0] && <p>Último aporte: <b>{formatarMoeda(meta.aportes[0].valor, "BRL")}</b> em {formatarData(meta.aportes[0].data)}</p>}
        <div className="acoes-linha"><Botao variante="secundario" onClick={() => definirEditando(meta)}><Pencil size={16} /> Editar</Botao>{meta.situacao === "ativa" && <Botao onClick={() => definirAportando(meta)}><Plus size={16} /> Registrar aporte</Botao>}</div>
      </article>)}</div> : !lista.carregando && <Vazio titulo="Nenhuma meta financeira" descricao="Crie uma meta para reserva, viagem, compra ou outro objetivo." />}
    </Cartao>
    {editando !== undefined && <Modal titulo={editando ? "Editar meta financeira" : "Nova meta financeira"} fechar={() => definirEditando(undefined)}><FormularioMeta meta={editando ?? undefined} concluir={() => { definirEditando(undefined); lista.recarregar(); }} /></Modal>}
    {aportando && <Modal titulo="Registrar aporte" fechar={() => definirAportando(null)}><FormularioAporte meta={aportando} concluir={() => { definirAportando(null); lista.recarregar(); }} /></Modal>}
  </>;
}

function FormularioMeta({ meta, concluir }: { meta?: MetaFinanceira; concluir: () => void }) {
  const [nome, definirNome] = useState(meta?.nome ?? ""); const [valor, definirValor] = useState(meta?.valor_objetivo ?? "");
  const [prazo, definirPrazo] = useState(meta?.prazo ?? ""); const [situacao, definirSituacao] = useState(meta?.situacao === "pausada" ? "pausada" : "ativa");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(meta ? `financeiro/metas-financeiras/${meta.id}` : "financeiro/metas-financeiras", { method: meta ? "PATCH" : "POST", body: JSON.stringify({ nome, valor_objetivo: valor, prazo: prazo || null, ...(meta ? { situacao } : {}) }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">A meta acompanha seu progresso, mas não movimenta o saldo das contas.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Campo rotulo="Nome da meta" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} placeholder="Ex.: Reserva de emergência" />
    <div className="formulario-duas-colunas"><Campo rotulo="Valor desejado em reais" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Prazo (opcional)" type="date" value={prazo} onChange={(e) => definirPrazo(e.target.value)} /></div>
    {meta && meta.situacao !== "concluida" && <Seletor rotulo="Situação" value={situacao} onChange={(e) => definirSituacao(e.target.value)}><option value="ativa">Ativa</option><option value="pausada">Pausada</option></Seletor>}
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{meta ? "Salvar alterações" : "Criar meta"}</Botao></div>
  </form>;
}

function FormularioAporte({ meta, concluir }: { meta: MetaFinanceira; concluir: () => void }) {
  const [valor, definirValor] = useState(""); const [data, definirData] = useState(dataHojeLocal());
  const [observacao, definirObservacao] = useState(""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/metas-financeiras/${meta.id}/aportes`, { method: "POST", body: JSON.stringify({ valor, data, observacao: observacao || null }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">O aporte atualiza somente o progresso da meta e não altera o saldo bancário.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <div className="resumo-formulario"><strong>{meta.nome}</strong><span>{formatarMoeda(meta.valor_acumulado, "BRL")} de {formatarMoeda(meta.valor_objetivo, "BRL")}</span></div>
    <div className="formulario-duas-colunas"><Campo rotulo="Valor do aporte" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Observação (opcional)" maxLength={300} value={observacao} onChange={(e) => definirObservacao(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>Registrar aporte</Botao></div>
  </form>;
}
