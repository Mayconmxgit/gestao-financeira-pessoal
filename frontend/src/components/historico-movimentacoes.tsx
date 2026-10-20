"use client";

import { FormEvent, useState } from "react";
import { ArrowDownLeft, ArrowRightLeft, ArrowUpRight, ChevronDown, Pencil, Plus, RotateCcw, Trash2, X } from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, intervaloMes, mensagemErro, nomesNatureza } from "@/lib/formatacao";
import type { Categoria, Conta, Transacao } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";

type FormaPagamento = "debito" | "cartao_credito";
type PaginaTransacoes = { dados: Transacao[]; proximo_cursor: string | null };
type Acao = "lancamento" | "edicao" | "estorno" | "cancelamento" | "contrapartida" | null;

const nomesOrigem: Record<string, string> = {
  manual: "Manual", importacao: "Importado", conciliacao: "Conciliação", emprestimo: "Empréstimo",
};

export function HistoricoMovimentacoes({ formaPagamento, contas, categorias }: {
  formaPagamento: FormaPagamento; contas: Conta[]; categorias: Categoria[];
}) {
  const periodo = intervaloMes();
  const [inicio, definirInicio] = useState(periodo.inicio);
  const [fim, definirFim] = useState(periodo.fim);
  const [contaId, definirContaId] = useState("");
  const [busca, definirBusca] = useState("");
  const [categoria, definirCategoria] = useState("");
  const [natureza, definirNatureza] = useState("");
  const [origem, definirOrigem] = useState("");
  const [acao, definirAcao] = useState<Acao>(null);
  const [selecionada, selecionar] = useState<Transacao | null>(null);
  const [continuacao, definirContinuacao] = useState<{ consulta: string; dados: Transacao[]; cursor: string | null } | null>(null);
  const [erro, definirErro] = useState("");
  const [carregandoMais, definirCarregandoMais] = useState(false);
  const consulta = montarConsulta({ inicio, fim, contaId, busca, categoria, natureza, origem, formaPagamento });
  const pagina = useDados<PaginaTransacoes>(consulta);
  const extras = continuacao?.consulta === consulta ? continuacao.dados : [];
  const registros = [...(pagina.dados?.dados ?? []), ...extras];
  const proximo = continuacao?.consulta === consulta ? continuacao.cursor : pagina.dados?.proximo_cursor;
  const entidades = contas.filter((conta) => formaPagamento === "cartao_credito"
    ? conta.tipo === "cartao_credito" : conta.tipo !== "cartao_credito");
  const naturezas = Object.entries(nomesNatureza).filter(([valor]) => formaPagamento === "cartao_credito"
    ? ["compra_cartao", "estorno", "ajuste"].includes(valor)
    : valor !== "compra_cartao");

  async function carregarMais() {
    if (!proximo) return;
    definirCarregandoMais(true); definirErro("");
    try {
      const resposta = await api<PaginaTransacoes>(`${consulta}&apos=${proximo}`);
      definirContinuacao((atual) => ({
        consulta, dados: [...(atual?.consulta === consulta ? atual.dados : []), ...resposta.dados], cursor: resposta.proximo_cursor,
      }));
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirCarregandoMais(false); }
  }

  function concluir(data?: string) {
    definirAcao(null); selecionar(null); definirContinuacao(null);
    if (data && data < inicio) definirInicio(data);
    if (data && data > fim) definirFim(data);
    pagina.recarregar();
  }

  async function classificar(transacao: Transacao, categoriaId: string) {
    definirErro("");
    try {
      await api(`financeiro/transacoes/${transacao.id}/classificacao`, {
        method: "PATCH", body: JSON.stringify({ categoria_id: categoriaId || null }),
      });
      definirContinuacao(null); pagina.recarregar();
    } catch (falha) { definirErro(mensagemErro(falha)); }
  }

  function limpar() {
    const atual = intervaloMes();
    definirInicio(atual.inicio); definirFim(atual.fim); definirContaId(""); definirBusca("");
    definirCategoria(""); definirNatureza(""); definirOrigem(""); definirContinuacao(null);
  }

  function abrir(tipo: Acao, transacao?: Transacao) { selecionar(transacao ?? null); definirAcao(tipo); }

  return <>
    {erro && <Aviso>{erro}</Aviso>}{pagina.erro && <Aviso>{pagina.erro}</Aviso>}
    <div className="filtros">
      <label>Pesquisar <input type="search" value={busca} onChange={(e) => definirBusca(e.target.value)} placeholder="Descrição da movimentação" /></label>
      <label>De <input type="date" value={inicio} onChange={(e) => definirInicio(e.target.value)} /></label>
      <label>Até <input type="date" value={fim} min={inicio} onChange={(e) => definirFim(e.target.value)} /></label>
      <label>{formaPagamento === "cartao_credito" ? "Cartão" : "Conta"} <select value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Todos</option>{entidades.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</select></label>
      <label>Categoria <select value={categoria} onChange={(e) => definirCategoria(e.target.value)}><option value="">Todas</option><option value="sem_categoria">Sem categoria</option>{categorias.map((item) => <option key={item.id} value={item.id}>{item.nome}</option>)}</select></label>
      <label>Tipo <select value={natureza} onChange={(e) => definirNatureza(e.target.value)}><option value="">Todos</option>{naturezas.map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</select></label>
      <label>Origem <select value={origem} onChange={(e) => definirOrigem(e.target.value)}><option value="">Todas</option>{Object.entries(nomesOrigem).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</select></label>
      <Botao type="button" variante="texto" onClick={limpar}><X size={16} /> Limpar filtros</Botao>
    </div>
    <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>{formaPagamento === "cartao_credito" ? "Compras e histórico" : "Histórico"}</h2><p>{registros.length} {registros.length === 1 ? "movimentação carregada" : "movimentações carregadas"}</p></div><Botao onClick={() => abrir("lancamento")}><Plus size={17} /> {formaPagamento === "cartao_credito" ? "Nova compra" : "Novo lançamento"}</Botao></div>
      {registros.length ? <Tabela registros={registros} contas={contas} categorias={categorias} formaPagamento={formaPagamento} classificar={classificar} abrir={abrir} />
        : !pagina.carregando && <Vazio titulo="Nenhum lançamento neste período" descricao={formaPagamento === "cartao_credito" ? "Registre uma compra para iniciar o histórico do cartão." : "Importe um extrato ou adicione uma movimentação manual."} />}
      {proximo && <div className="carregar-mais"><Botao variante="secundario" onClick={carregarMais} carregando={carregandoMais}>Carregar mais <ChevronDown size={17} /></Botao></div>}
    </Cartao>
    {acao === "lancamento" && <Modal titulo={formaPagamento === "cartao_credito" ? "Nova compra" : "Novo lançamento"} fechar={() => definirAcao(null)}><FormularioMovimentacao formaPagamento={formaPagamento} contas={contas} categorias={categorias} concluir={concluir} /></Modal>}
    {acao === "edicao" && selecionada && <Modal titulo="Editar movimentação" fechar={() => definirAcao(null)}><FormularioMovimentacao formaPagamento={formaPagamento} transacao={selecionada} contas={contas} categorias={categorias} concluir={concluir} /></Modal>}
    {acao === "estorno" && selecionada && <Modal titulo="Registrar estorno" fechar={() => definirAcao(null)}><FormularioEstorno transacao={selecionada} concluir={concluir} /></Modal>}
    {acao === "cancelamento" && selecionada && <Modal titulo="Excluir lançamento" fechar={() => definirAcao(null)}><FormularioCancelamento transacao={selecionada} concluir={concluir} /></Modal>}
    {acao === "contrapartida" && selecionada && <Modal titulo="Vincular contrapartida" fechar={() => definirAcao(null)}><FormularioContrapartida transacao={selecionada} contas={contas} concluir={concluir} /></Modal>}
  </>;
}

function montarConsulta(filtros: { inicio: string; fim: string; contaId: string; busca: string; categoria: string; natureza: string; origem: string; formaPagamento: FormaPagamento }) {
  const parametros = new URLSearchParams({ inicio: filtros.inicio, fim: filtros.fim, limite: "100", ordem: "desc", forma_pagamento: filtros.formaPagamento });
  if (filtros.contaId) parametros.set("conta_id", filtros.contaId);
  if (filtros.busca.trim()) parametros.set("busca", filtros.busca.trim());
  if (filtros.categoria === "sem_categoria") parametros.set("sem_categoria", "1");
  else if (filtros.categoria) parametros.set("categoria_id", filtros.categoria);
  if (filtros.natureza) parametros.set("natureza", filtros.natureza);
  if (filtros.origem) parametros.set("origem", filtros.origem);
  return `financeiro/transacoes?${parametros.toString()}`;
}

function Tabela({ registros, contas, categorias, formaPagamento, classificar, abrir }: {
  registros: Transacao[]; contas: Conta[]; categorias: Categoria[];
  formaPagamento: FormaPagamento;
  classificar: (transacao: Transacao, categoria: string) => void;
  abrir: (tipo: Acao, transacao?: Transacao) => void;
}) {
  return <div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Movimentação</th><th>Conta</th><th>Categoria</th><th>Data</th><th>Valor</th><th>Ações</th></tr></thead><tbody>{registros.map((item) => {
    const conta = contas.find((registro) => registro.id === item.conta_id);
    const tipoCategoria = Number(item.valor) > 0 ? "receita" : "despesa";
    const classificavel = ["entrada", "saida", "compra_cartao", "estorno"].includes(item.natureza) && item.situacao === "confirmada";
    return <tr key={item.id}><td><div className="tabela-descricao"><span className={`movimento-icone ${Number(item.valor) >= 0 ? "entrada" : "saida"}`}>{Number(item.valor) >= 0 ? <ArrowDownLeft size={18} /> : <ArrowUpRight size={18} />}</span><span><strong>{item.descricao_original}{item.numero_parcela && item.total_parcelas ? ` · ${item.numero_parcela}/${item.total_parcelas}` : ""}</strong><small>#{item.codigo} · {nomesNatureza[item.natureza] ?? item.natureza} · {nomesOrigem[item.origem] ?? item.origem}{item.situacao === "cancelada" ? " · Cancelado" : ""}</small></span></div></td>
      <td>{conta?.nome ?? "Conta"}</td><td>{classificavel ? <select className="seletor-tabela" value={item.categoria_id ?? ""} onChange={(e) => classificar(item, e.target.value)} aria-label={`Categoria de ${item.descricao_original}`}><option value="">A classificar</option>{categorias.filter((categoria) => categoria.tipo === tipoCategoria).map((categoria) => <option key={categoria.id} value={categoria.id}>{categoria.nome}</option>)}</select> : <span className="etiqueta">Não se aplica</span>}</td>
      <td>{formatarData(item.data_efetiva)}</td><td className={`tabela-valor ${Number(item.valor) >= 0 ? "valor-positivo" : ""}`}>{formatarMoeda(item.valor, item.moeda)}</td><td><div className="acoes-tabela">{item.editavel && <button onClick={() => abrir("edicao", item)} title="Editar movimentação"><Pencil size={17} /></button>}{item.estornavel && <button onClick={() => abrir("estorno", item)} title="Registrar estorno"><RotateCcw size={17} /></button>}{item.cancelavel && <button onClick={() => abrir("cancelamento", item)} title="Excluir lançamento"><Trash2 size={17} /></button>}{formaPagamento === "debito" && ["entrada", "saida"].includes(item.natureza) && item.situacao === "confirmada" && !item.transacao_relacionada_id && <button onClick={() => abrir("contrapartida", item)} title="Vincular contrapartida"><ArrowRightLeft size={17} /></button>}</div></td></tr>;
  })}</tbody></table></div>;
}

function FormularioMovimentacao({ formaPagamento, transacao, contas, categorias, concluir }: {
  formaPagamento: FormaPagamento; transacao?: Transacao; contas: Conta[]; categorias: Categoria[]; concluir: (data?: string) => void;
}) {
  const [contaId, definirContaId] = useState(transacao?.conta_id ?? "");
  const [natureza, definirNatureza] = useState(transacao?.natureza ?? (formaPagamento === "cartao_credito" ? "compra_cartao" : "saida"));
  const [data, definirData] = useState(transacao?.data_efetiva ?? dataHojeLocal());
  const [valor, definirValor] = useState(transacao ? Math.abs(Number(transacao.valor)).toFixed(2) : "");
  const [descricao, definirDescricao] = useState(transacao?.descricao_original ?? "");
  const [categoriaId, definirCategoriaId] = useState(transacao?.categoria_id ?? "");
  const [parcelas, definirParcelas] = useState(String(transacao?.total_parcelas ?? 1));
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const compativeis = contas.filter((conta) => (conta.situacao === "ativa" || conta.id === transacao?.conta_id)
    && (formaPagamento === "cartao_credito" ? conta.tipo === "cartao_credito" : conta.tipo !== "cartao_credito"));

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      await api(transacao ? `financeiro/transacoes/${transacao.id}` : "financeiro/transacoes", {
        method: transacao ? "PATCH" : "POST",
        body: JSON.stringify({ conta_id: contaId, data, valor: natureza === "entrada" ? valor : `-${valor}`, descricao, natureza, categoria_id: categoriaId || null, ...(!transacao && formaPagamento === "cartao_credito" ? { quantidade_parcelas: Number(parcelas) } : {}) }),
      });
      concluir(data);
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }

  return <form onSubmit={enviar} className="formulario-grade">{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Seletor rotulo={formaPagamento === "cartao_credito" ? "Cartão de crédito" : "Conta"} required disabled={Boolean(transacao && transacao.origem !== "manual")} value={contaId} onChange={(e) => { definirContaId(e.target.value); definirCategoriaId(""); }}><option value="">Selecione</option>{compativeis.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</Seletor>
    {formaPagamento === "cartao_credito" ? <Seletor rotulo="Parcelamento" value={parcelas} disabled={Boolean(transacao)} onChange={(e) => definirParcelas(e.target.value)}>{Array.from({ length: 60 }, (_, indice) => indice + 1).map((quantidade) => <option key={quantidade} value={quantidade}>{quantidade === 1 ? "À vista" : `${quantidade} parcelas`}</option>)}</Seletor>
      : <Seletor rotulo="Tipo" value={natureza} onChange={(e) => { definirNatureza(e.target.value); definirCategoriaId(""); }}><option value="saida">Saída</option><option value="entrada">Entrada</option></Seletor>}
    {formaPagamento === "cartao_credito" && Number(parcelas) > 1 && <Aviso tipo="info">O valor informado é o total da compra. As parcelas serão lançadas mensalmente e o limite total será reservado agora.</Aviso>}
    <div className="formulario-duas-colunas"><Campo rotulo="Valor" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} value={descricao} onChange={(e) => definirDescricao(e.target.value)} />
    <Seletor rotulo="Categoria (opcional)" value={categoriaId} onChange={(e) => definirCategoriaId(e.target.value)}><option value="">A classificar</option>{categorias.filter((categoria) => categoria.tipo === (natureza === "entrada" ? "receita" : "despesa")).map((categoria) => <option key={categoria.id} value={categoria.id}>{categoria.nome}</option>)}</Seletor>
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{transacao ? "Salvar alterações" : formaPagamento === "cartao_credito" ? "Registrar compra" : "Salvar lançamento"}</Botao></div>
  </form>;
}

function FormularioEstorno({ transacao, concluir }: { transacao: Transacao; concluir: () => void }) {
  const [data, definirData] = useState(dataHojeLocal());
  const [valor, definirValor] = useState(transacao.valor_estornavel ?? Math.abs(Number(transacao.valor)).toFixed(2));
  const [motivo, definirMotivo] = useState(""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/transacoes/${transacao.id}/estornos`, { method: "POST", body: JSON.stringify({ data, valor, motivo }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">O lançamento original será preservado e o valor devolvido será registrado separadamente.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <div className="formulario-duas-colunas"><Campo rotulo="Valor do estorno" type="number" min="0.01" max={transacao.valor_estornavel} step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data do estorno" type="date" required min={transacao.data_efetiva} value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Motivo" required minLength={5} maxLength={300} value={motivo} onChange={(e) => definirMotivo(e.target.value)} /><div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>Registrar estorno</Botao></div>
  </form>;
}

function FormularioCancelamento({ transacao, concluir }: { transacao: Transacao; concluir: () => void }) {
  const [motivo, definirMotivo] = useState(""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/transacoes/${transacao.id}`, { method: "DELETE", body: JSON.stringify({ motivo }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">O lançamento permanecerá no histórico como cancelado para auditoria.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Campo rotulo="Motivo da exclusão" required minLength={5} maxLength={300} value={motivo} onChange={(e) => definirMotivo(e.target.value)} /><div className="modal-acoes"><Botao type="submit" variante="perigo" carregando={estado.enviando}><Trash2 size={17} /> Confirmar exclusão</Botao></div>
  </form>;
}

function FormularioContrapartida({ transacao, contas, concluir }: { transacao: Transacao; contas: Conta[]; concluir: () => void }) {
  const [contaId, definirContaId] = useState(""); const [natureza, definirNatureza] = useState("transferencia");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      await api(`financeiro/transacoes/${transacao.id}/contrapartida`, {
        method: "POST", body: JSON.stringify({ conta_contrapartida_id: contaId, natureza }),
      });
      concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Use apenas quando esta movimentação tiver a outra ponta em uma conta sua.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Seletor rotulo="Conta da outra ponta" required value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Selecione</option>{contas.filter((conta) => conta.id !== transacao.conta_id && conta.situacao === "ativa" && conta.moeda === transacao.moeda).map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</Seletor>
    <Seletor rotulo="Natureza" value={natureza} onChange={(e) => definirNatureza(e.target.value)}><option value="transferencia">Transferência própria</option><option value="pagamento_fatura">Pagamento de fatura</option></Seletor>
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>Vincular contas</Botao></div>
  </form>;
}
