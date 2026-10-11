"use client";

import { FormEvent, useState } from "react";
import {
  ArrowDownLeft, ArrowRightLeft, ArrowUpRight, ChevronDown, Pencil, Plus, RotateCcw, Tags, X,
} from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, intervaloMes, mensagemErro, nomesNatureza } from "@/lib/formatacao";
import type { Categoria, Conta, Transacao } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";
import { MetasFinanceiras, PagamentosRecorrentes } from "@/components/planejamento-financeiro";
import { RECURSOS_PLANO, useRecursosPlano } from "@/components/acesso-recursos-plano";

type Acao = "lancamento" | "edicao" | "transferencia" | "estorno" | "contrapartida" | "categoria" | null;
type PaginaTransacoes = { dados: Transacao[]; proximo_cursor: string | null };
type FiltrosHistorico = {
  inicio: string; fim: string; contaId: string; busca: string;
  categoria: string; natureza: string; origem: string; formaPagamento: "debito" | "cartao_credito";
};

const nomesOrigem: Record<string, string> = {
  manual: "Manual", importacao: "Importado", conciliacao: "Conciliação", emprestimo: "Empréstimo",
};

function montarConsulta(filtros: FiltrosHistorico) {
  const parametros = new URLSearchParams({
    inicio: filtros.inicio, fim: filtros.fim, limite: "100", ordem: "desc",
  });
  if (filtros.contaId) parametros.set("conta_id", filtros.contaId);
  if (filtros.busca.trim()) parametros.set("busca", filtros.busca.trim());
  if (filtros.categoria === "sem_categoria") parametros.set("sem_categoria", "1");
  else if (filtros.categoria) parametros.set("categoria_id", filtros.categoria);
  if (filtros.natureza) parametros.set("natureza", filtros.natureza);
  if (filtros.origem) parametros.set("origem", filtros.origem);
  parametros.set("forma_pagamento", filtros.formaPagamento);

  return `financeiro/transacoes?${parametros.toString()}`;
}

export default function Movimentacoes() {
  const { possui } = useRecursosPlano();
  const permiteRecorrentes = possui(RECURSOS_PLANO.PAGAMENTOS_RECORRENTES);
  const permiteMetas = possui(RECURSOS_PLANO.METAS_FINANCEIRAS);
  const mesAtual = intervaloMes();
  const [inicio, definirInicio] = useState(mesAtual.inicio);
  const [fim, definirFim] = useState(mesAtual.fim);
  const [contaId, definirContaId] = useState("");
  const [busca, definirBusca] = useState("");
  const [categoriaFiltro, definirCategoriaFiltro] = useState("");
  const [naturezaFiltro, definirNaturezaFiltro] = useState("");
  const [origemFiltro, definirOrigemFiltro] = useState("");
  const [formaPagamento, definirFormaPagamento] = useState<"debito" | "cartao_credito">("debito");
  const [aba, definirAba] = useState<"movimentacoes" | "recorrentes" | "metas" | "categorias">("movimentacoes");
  const [acao, definirAcao] = useState<Acao>(null);
  const [selecionada, selecionar] = useState<Transacao | null>(null);
  const [categoriaSelecionada, selecionarCategoria] = useState<Categoria | null>(null);
  const [continuacao, definirContinuacao] = useState<{ consulta: string; extras: Transacao[]; cursor: string | null } | null>(null);
  const [erro, definirErro] = useState("");
  const [carregandoMais, definirCarregandoMais] = useState(false);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const categorias = useDados<{ dados: Categoria[] }>("financeiro/categorias");
  const consulta = montarConsulta({ inicio, fim, contaId, busca, categoria: categoriaFiltro, natureza: naturezaFiltro, origem: origemFiltro, formaPagamento });
  const pagina = useDados<PaginaTransacoes>(consulta);
  const extras = continuacao?.consulta === consulta ? continuacao.extras : [];
  const proximo = continuacao?.consulta === consulta ? continuacao.cursor : pagina.dados?.proximo_cursor;
  const registros = [...(pagina.dados?.dados ?? []), ...extras];
  const contasHistorico = contas.dados?.dados.filter((conta) =>
    formaPagamento === "cartao_credito" ? conta.tipo === "cartao_credito" : conta.tipo !== "cartao_credito",
  ) ?? [];

  async function carregarMais() {
    if (!proximo) return;
    definirCarregandoMais(true); definirErro("");
    try {
      const resposta = await api<PaginaTransacoes>(`${consulta}&apos=${proximo}`);
      definirContinuacao((atual) => ({ consulta, extras: [...(atual?.consulta === consulta ? atual.extras : []), ...resposta.dados], cursor: resposta.proximo_cursor }));
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirCarregandoMais(false); }
  }

  function concluir(dataEfetiva?: string) {
    definirAcao(null); selecionarCategoria(null); definirContinuacao(null);
    if (dataEfetiva && dataEfetiva < inicio) definirInicio(dataEfetiva);
    if (dataEfetiva && dataEfetiva > fim) definirFim(dataEfetiva);
    pagina.recarregar(); categorias.recarregar();
  }
  function abrir(tipo: Acao, transacao?: Transacao) { selecionar(transacao ?? null); definirAcao(tipo); }
  function abrirCategoria(categoria: Categoria | null = null) { selecionarCategoria(categoria); definirAcao("categoria"); }
  function limparFiltros() {
    const periodo = intervaloMes();
    definirInicio(periodo.inicio); definirFim(periodo.fim); definirContaId(""); definirBusca("");
    definirCategoriaFiltro(""); definirNaturezaFiltro(""); definirOrigemFiltro(""); definirContinuacao(null);
  }
  function alterarFormaPagamento(novaForma: "debito" | "cartao_credito") {
    definirFormaPagamento(novaForma); definirContaId(""); definirContinuacao(null);
  }
  async function classificar(transacao: Transacao, categoriaId: string) {
    definirErro("");
    try { await api(`financeiro/transacoes/${transacao.id}/classificacao`, { method: "PATCH", body: JSON.stringify({ categoria_id: categoriaId || null }) }); definirContinuacao(null); pagina.recarregar(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
  }

  return <div>
    <CabecalhoPagina titulo="Movimentações" descricao="Acompanhe, classifique e complemente seus lançamentos."
      acao={<div className="cabecalho-acoes"><Botao variante="secundario" onClick={() => abrir("transferencia")}><ArrowRightLeft size={17} /> Transferir</Botao><Botao onClick={() => abrir("lancamento")}><Plus size={18} /> Novo lançamento</Botao></div>} />
    <div className="abas"><button className={aba === "movimentacoes" ? "ativa" : ""} onClick={() => definirAba("movimentacoes")}>Movimentações</button>{permiteRecorrentes && <button className={aba === "recorrentes" ? "ativa" : ""} onClick={() => definirAba("recorrentes")}>Pagamentos recorrentes</button>}{permiteMetas && <button className={aba === "metas" ? "ativa" : ""} onClick={() => definirAba("metas")}>Metas financeiras</button>}<button className={aba === "categorias" ? "ativa" : ""} onClick={() => definirAba("categorias")}>Categorias</button></div>
    {erro && <Aviso>{erro}</Aviso>}{pagina.erro && <Aviso>{pagina.erro}</Aviso>}
    {aba === "recorrentes" && permiteRecorrentes ? <PagamentosRecorrentes contas={contas.dados?.dados ?? []} categorias={categorias.dados?.dados ?? []} />
      : aba === "metas" && permiteMetas ? <MetasFinanceiras contas={contas.dados?.dados ?? []} />
      : aba === "categorias" ? <Cartao><div className="cartao-cabecalho"><div><h2>Suas categorias</h2><p>Organize receitas e despesas do seu jeito.</p></div><Botao onClick={() => abrirCategoria()}><Plus size={17} /> Nova categoria</Botao></div>
      {categorias.dados?.dados.length ? <div className="categorias-grade">{categorias.dados.dados.map((categoria) => <div key={categoria.id} className="categoria-linha"><span className={`categoria-ponto ${categoria.tipo}`} /><div className="categoria-dados"><strong>{categoria.nome}</strong><small>{descricaoCategoria(categoria)}</small></div><button type="button" className="botao-icone categoria-editar" onClick={() => abrirCategoria(categoria)} aria-label={`Editar categoria ${categoria.nome}`} title="Editar categoria"><Pencil size={16} /></button></div>)}</div>
        : <Vazio titulo="Ainda não há categorias" descricao="Crie categorias para acompanhar suas receitas e despesas classificadas." />}</Cartao>
      : <><div className="filtros">
        <label>Pesquisar <input type="search" value={busca} onChange={(e) => definirBusca(e.target.value)} placeholder="Descrição da movimentação" /></label>
        <label>De <input type="date" value={inicio} onChange={(e) => definirInicio(e.target.value)} /></label>
        <label>Até <input type="date" value={fim} min={inicio} onChange={(e) => definirFim(e.target.value)} /></label>
        <label>Conta <select value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Todas as contas</option>{contasHistorico.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</select></label>
        <label>Categoria <select value={categoriaFiltro} onChange={(e) => definirCategoriaFiltro(e.target.value)}><option value="">Todas as categorias</option><option value="sem_categoria">Sem categoria</option>{categorias.dados?.dados.map((categoria) => <option key={categoria.id} value={categoria.id}>{categoria.nome}</option>)}</select></label>
        <label>Tipo <select value={naturezaFiltro} onChange={(e) => definirNaturezaFiltro(e.target.value)}><option value="">Todos os tipos</option>{Object.entries(nomesNatureza).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</select></label>
        <label>Origem <select value={origemFiltro} onChange={(e) => definirOrigemFiltro(e.target.value)}><option value="">Todas as origens</option>{Object.entries(nomesOrigem).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</select></label>
        <Botao type="button" variante="texto" onClick={limparFiltros}><X size={16} /> Limpar filtros</Botao>
      </div>
        <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Histórico</h2><p>{registros.length} {registros.length === 1 ? "movimentação carregada" : "movimentações carregadas"}</p></div><div className="alternador-historico" role="group" aria-label="Tipo de histórico"><button type="button" className={formaPagamento === "debito" ? "ativa" : ""} onClick={() => alterarFormaPagamento("debito")}>Débito</button><button type="button" className={formaPagamento === "cartao_credito" ? "ativa" : ""} onClick={() => alterarFormaPagamento("cartao_credito")}>Cartão de crédito</button></div></div>
          {registros.length ? <div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Movimentação</th><th>Conta</th><th>Categoria</th><th>Data</th><th>Valor</th><th>Ações</th></tr></thead><tbody>{registros.map((item) => {
            const conta = contas.dados?.dados.find((c) => c.id === item.conta_id);
            const tipoCategoria = Number(item.valor) > 0 ? "receita" : "despesa";
            const classificavel = ["entrada", "saida", "compra_cartao", "estorno"].includes(item.natureza) && item.situacao === "confirmada";
            return <tr key={item.id}><td><div className="tabela-descricao"><span className={`movimento-icone ${Number(item.valor) >= 0 ? "entrada" : "saida"}`}>{Number(item.valor) >= 0 ? <ArrowDownLeft size={18} /> : <ArrowUpRight size={18} />}</span><span><strong>{item.descricao_original}</strong><small>{nomesNatureza[item.natureza] ?? item.natureza} · {nomesOrigem[item.origem] ?? item.origem}</small></span></div></td>
              <td>{conta?.nome ?? "Conta"}</td><td>{classificavel ? <select className="seletor-tabela" value={item.categoria_id ?? ""} onChange={(e) => classificar(item, e.target.value)} aria-label={`Categoria de ${item.descricao_original}`}><option value="">A classificar</option>{categorias.dados?.dados.filter((c) => c.tipo === tipoCategoria).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</select> : <span className="etiqueta">Não se aplica</span>}</td>
              <td>{formatarData(item.data_efetiva)}</td><td className={`tabela-valor ${Number(item.valor) >= 0 ? "valor-positivo" : ""}`}>{formatarMoeda(item.valor, item.moeda)}</td><td><div className="acoes-tabela">{item.editavel && <button onClick={() => abrir("edicao", item)} title="Editar movimentação" aria-label={`Editar ${item.descricao_original}`}><Pencil size={17} /></button>}{["entrada", "saida", "compra_cartao"].includes(item.natureza) && item.situacao === "confirmada" && <button onClick={() => abrir("estorno", item)} title="Registrar estorno"><RotateCcw size={17} /></button>}{["entrada", "saida"].includes(item.natureza) && !item.transacao_relacionada_id && <button onClick={() => abrir("contrapartida", item)} title="Vincular contrapartida"><ArrowRightLeft size={17} /></button>}</div></td></tr>;
          })}</tbody></table></div> : !pagina.carregando && <Vazio titulo="Nenhum lançamento neste período" descricao="Importe um extrato ou adicione uma movimentação manual." acao={{ texto: "Importar extrato", href: "/importacoes" }} />}
          {proximo && <div className="carregar-mais"><Botao variante="secundario" onClick={carregarMais} carregando={carregandoMais}>Carregar mais <ChevronDown size={17} /></Botao></div>}
        </Cartao></>}
    {acao === "lancamento" && <Modal titulo="Novo lançamento" fechar={() => definirAcao(null)}><FormularioMovimentacao contas={contas.dados?.dados ?? []} categorias={categorias.dados?.dados ?? []} concluir={concluir} /></Modal>}
    {acao === "edicao" && selecionada && <Modal titulo="Editar movimentação" fechar={() => definirAcao(null)}><FormularioMovimentacao transacao={selecionada} contas={contas.dados?.dados ?? []} categorias={categorias.dados?.dados ?? []} concluir={concluir} /></Modal>}
    {acao === "transferencia" && <Modal titulo="Transferir entre contas" fechar={() => definirAcao(null)}><FormularioTransferencia contas={contas.dados?.dados ?? []} concluir={concluir} /></Modal>}
    {acao === "estorno" && selecionada && <Modal titulo="Registrar estorno" fechar={() => definirAcao(null)}><FormularioEstorno transacao={selecionada} concluir={concluir} /></Modal>}
    {acao === "contrapartida" && selecionada && <Modal titulo="Vincular contrapartida" fechar={() => definirAcao(null)}><FormularioContrapartida transacao={selecionada} contas={contas.dados?.dados ?? []} concluir={concluir} /></Modal>}
    {acao === "categoria" && <Modal titulo={categoriaSelecionada ? "Editar categoria" : "Nova categoria"} fechar={() => definirAcao(null)}><FormularioCategoria categoria={categoriaSelecionada ?? undefined} concluir={concluir} /></Modal>}
  </div>;
}

function FormularioMovimentacao({ transacao, contas, categorias, concluir }: { transacao?: Transacao; contas: Conta[]; categorias: Categoria[]; concluir: (data?: string) => void }) {
  const [contaId, definirContaId] = useState(transacao ? String(transacao.conta_id) : "");
  const [natureza, definirNatureza] = useState(transacao?.natureza ?? "saida");
  const [data, definirData] = useState(transacao?.data_efetiva ?? dataHojeLocal());
  const [valor, definirValor] = useState(transacao ? Math.abs(Number(transacao.valor)).toFixed(2) : "");
  const [descricao, definirDescricao] = useState(transacao?.descricao_original ?? "");
  const [categoriaId, definirCategoriaId] = useState(transacao?.categoria_id ? String(transacao.categoria_id) : "");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);
  const conta = contas.find((item) => item.id === contaId);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try {
      const caminho = transacao ? `financeiro/transacoes/${transacao.id}` : "financeiro/transacoes";
      await api(caminho, { method: transacao ? "PATCH" : "POST", body: JSON.stringify({ conta_id: contaId, data, valor: natureza === "entrada" ? valor : `-${valor}`, descricao, natureza, categoria_id: categoriaId || null }) });
      concluir(data);
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form onSubmit={enviar} className="formulario-grade">
    {transacao && transacao.origem !== "manual" && <Aviso tipo="info">Em uma movimentação importada, a conta de origem é preservada para manter a conciliação bancária.</Aviso>}
    {data > dataHojeLocal() && <Aviso tipo="info">Este lançamento ficará no mês da data escolhida e não será descontado do saldo do mês atual.</Aviso>}
    {erro && <Aviso>{erro}</Aviso>}
    <Seletor rotulo="Conta" required disabled={Boolean(transacao && transacao.origem !== "manual")} value={contaId} onChange={(e) => { const nova = contas.find((c) => c.id === e.target.value); definirContaId(e.target.value); definirNatureza(nova?.tipo === "cartao_credito" ? "compra_cartao" : natureza === "compra_cartao" ? "saida" : natureza); definirCategoriaId(""); }}><option value="">Selecione</option>{contas.filter((c) => c.situacao === "ativa" || c.id === transacao?.conta_id).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Seletor>
    <Seletor rotulo="Tipo" value={natureza} onChange={(e) => { definirNatureza(e.target.value); definirCategoriaId(""); }}><option value="saida" disabled={conta?.tipo === "cartao_credito"}>Saída</option><option value="entrada">Entrada</option><option value="compra_cartao" disabled={conta?.tipo !== "cartao_credito"}>Compra no cartão</option></Seletor>
    <div className="formulario-duas-colunas"><Campo rotulo="Valor" type="number" min="0.01" step="0.01" required placeholder="0,00" value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} placeholder="Ex.: Supermercado" value={descricao} onChange={(e) => definirDescricao(e.target.value)} />
    <Seletor rotulo="Categoria (opcional)" value={categoriaId} onChange={(e) => definirCategoriaId(e.target.value)}><option value="">A classificar</option>{categorias.filter((c) => c.tipo === (natureza === "entrada" ? "receita" : "despesa")).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Seletor>
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>{transacao ? "Salvar alterações" : "Salvar lançamento"}</Botao></div>
  </form>;
}

function FormularioTransferencia({ contas, concluir }: { contas: Conta[]; concluir: () => void }) {
  const [origem, definirOrigem] = useState(""); const [destino, definirDestino] = useState("");
  const [data, definirData] = useState(dataHojeLocal());
  const [valor, definirValor] = useState(""); const [descricao, definirDescricao] = useState("");
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  const ativas = contas.filter((conta) => conta.situacao === "ativa");

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api("financeiro/transferencias", { method: "POST", body: JSON.stringify({ origem_id: origem, destino_id: destino, data, valor, descricao }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form onSubmit={enviar} className="formulario-grade"><Aviso tipo="info">Transferências próprias e pagamentos de fatura não contam como nova receita ou despesa.</Aviso>{erro && <Aviso>{erro}</Aviso>}
    <Seletor rotulo="Conta de origem" required value={origem} onChange={(e) => definirOrigem(e.target.value)}><option value="">Selecione</option>{ativas.filter((c) => c.tipo !== "cartao_credito").map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Seletor>
    <Seletor rotulo="Conta de destino" required value={destino} onChange={(e) => definirDestino(e.target.value)}><option value="">Selecione</option>{ativas.filter((c) => String(c.id) !== origem).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Seletor>
    <div className="formulario-duas-colunas"><Campo rotulo="Valor" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} placeholder="Ex.: Transferência para poupança" value={descricao} onChange={(e) => definirDescricao(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Confirmar transferência</Botao></div>
  </form>;
}

function FormularioEstorno({ transacao, concluir }: { transacao: Transacao; concluir: () => void }) {
  const [data, definirData] = useState(dataHojeLocal());
  const [motivo, definirMotivo] = useState(""); const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api(`financeiro/transacoes/${transacao.id}/estornos`, { method: "POST", body: JSON.stringify({ data, motivo }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Um estorno vinculado inverte o efeito da movimentação original sem apagá-la.</Aviso>{erro && <Aviso>{erro}</Aviso>}
    <div className="resumo-formulario"><strong>{transacao.descricao_original}</strong><span>{formatarMoeda(transacao.valor, transacao.moeda)}</span></div>
    <Campo rotulo="Data do estorno" type="date" required min={transacao.data_efetiva} value={data} onChange={(e) => definirData(e.target.value)} />
    <Campo rotulo="Motivo" required minLength={5} maxLength={300} value={motivo} onChange={(e) => definirMotivo(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Registrar estorno</Botao></div>
  </form>;
}

function FormularioContrapartida({ transacao, contas, concluir }: { transacao: Transacao; contas: Conta[]; concluir: () => void }) {
  const [contaId, definirContaId] = useState(""); const [natureza, definirNatureza] = useState("transferencia");
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api(`financeiro/transacoes/${transacao.id}/contrapartida`, { method: "POST", body: JSON.stringify({ conta_contrapartida_id: contaId, natureza }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Use apenas quando esta movimentação tiver a outra ponta em uma conta sua.</Aviso>{erro && <Aviso>{erro}</Aviso>}
    <div className="resumo-formulario"><strong>{transacao.descricao_original}</strong><span>{formatarMoeda(transacao.valor, transacao.moeda)}</span></div>
    <Seletor rotulo="Conta da outra ponta" required value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Selecione</option>{contas.filter((c) => c.id !== transacao.conta_id && c.situacao === "ativa" && c.moeda === transacao.moeda).map((c) => <option key={c.id} value={c.id}>{c.nome}</option>)}</Seletor>
    <Seletor rotulo="Natureza" value={natureza} onChange={(e) => definirNatureza(e.target.value)}><option value="transferencia">Transferência própria</option><option value="pagamento_fatura">Pagamento de fatura</option></Seletor>
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Vincular contas</Botao></div>
  </form>;
}

function FormularioCategoria({ categoria, concluir }: { categoria?: Categoria; concluir: () => void }) {
  const [nome, definirNome] = useState(categoria?.nome ?? "");
  const [tipo, definirTipo] = useState<Categoria["tipo"]>(categoria?.tipo ?? "despesa");
  const [limite, definirLimite] = useState(categoria?.limite_gastos ?? "");
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try {
      const caminho = categoria ? `financeiro/categorias/${categoria.id}` : "financeiro/categorias";
      await api(caminho, { method: categoria ? "PATCH" : "POST", body: JSON.stringify({ nome, tipo, limite_gastos: tipo === "despesa" && limite ? limite : null }) });
      concluir();
    }
    catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); }
  }
  return <form className="formulario-grade" onSubmit={enviar}>{erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Nome da categoria" required minLength={2} maxLength={80} placeholder="Ex.: Alimentação" value={nome} onChange={(e) => definirNome(e.target.value)} />
    <Seletor rotulo="Tipo" dica={categoria ? "Categorias já utilizadas preservam o tipo para proteger o histórico." : undefined} value={tipo} onChange={(e) => { const novoTipo = e.target.value as Categoria["tipo"]; definirTipo(novoTipo); if (novoTipo === "receita") definirLimite(""); }}><option value="despesa">Despesa</option><option value="receita">Receita</option></Seletor>
    {tipo === "despesa" && <Campo rotulo="Limite mensal (opcional)" dica="Deixe vazio para manter a categoria sem limite." type="number" min="0.01" step="0.01" placeholder="0,00" value={limite} onChange={(e) => definirLimite(e.target.value)} />}
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>{categoria ? <><Pencil size={17} /> Salvar alterações</> : <><Tags size={17} /> Criar categoria</>}</Botao></div>
  </form>;
}

function descricaoCategoria(categoria: Categoria) {
  if (categoria.tipo === "receita") return "Receita";
  return categoria.limite_gastos ? `Limite mensal: ${Number(categoria.limite_gastos).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}` : "Despesa · Sem limite";
}
