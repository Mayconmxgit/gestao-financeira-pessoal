"use client";

import { FormEvent, useState } from "react";
import { ArrowRight, CreditCard, Plus } from "lucide-react";
import { api, ErroApi } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Categoria, Conta, FaturaCartao, Transacao } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";
import { HistoricoMovimentacoes } from "@/components/historico-movimentacoes";
import { PagamentosRecorrentes } from "@/components/planejamento-financeiro";
import { RECURSOS_PLANO, useRecursosPlano } from "@/components/acesso-recursos-plano";

type Aba = "cartoes" | "historico" | "recorrentes";
type ModalCartao = "criar" | "editar" | "faturas" | null;

export default function Cartoes() {
  const { possui } = useRecursosPlano();
  const permiteRecorrentes = possui(RECURSOS_PLANO.PAGAMENTOS_RECORRENTES);
  const [aba, definirAba] = useState<Aba>("cartoes");
  const [modal, definirModal] = useState<ModalCartao>(null);
  const [selecionado, selecionar] = useState<Conta | null>(null);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const categorias = useDados<{ dados: Categoria[] }>("financeiro/categorias");
  const todasContas = contas.dados?.dados ?? [];
  const cartoes = todasContas.filter((conta) => conta.tipo === "cartao_credito");

  function abrir(tipo: Exclude<ModalCartao, "criar" | null>, cartao: Conta) { selecionar(cartao); definirModal(tipo); }
  function concluir() { definirModal(null); selecionar(null); contas.recarregar(); }

  return <div>
    <CabecalhoPagina titulo="Cartões" descricao="Gerencie cartões, compras, limites e faturas em um só lugar."
      acao={aba === "cartoes" ? <Botao onClick={() => definirModal("criar")}><Plus size={18} /> Novo cartão</Botao> : undefined} />
    <div className="abas"><button className={aba === "cartoes" ? "ativa" : ""} onClick={() => definirAba("cartoes")}>Cartões e faturas</button><button className={aba === "historico" ? "ativa" : ""} onClick={() => definirAba("historico")}>Compras e histórico</button>{permiteRecorrentes && <button className={aba === "recorrentes" ? "ativa" : ""} onClick={() => definirAba("recorrentes")}>Pagamentos recorrentes</button>}</div>
    {contas.erro && <Aviso>{contas.erro}</Aviso>}{categorias.erro && <Aviso>{categorias.erro}</Aviso>}
    {aba === "historico" ? <HistoricoMovimentacoes formaPagamento="cartao_credito" contas={todasContas} categorias={categorias.dados?.dados ?? []} />
      : aba === "recorrentes" && permiteRecorrentes ? <PagamentosRecorrentes formaPagamento="cartao_credito" contas={todasContas} categorias={categorias.dados?.dados ?? []} />
      : <ListaCartoes cartoes={cartoes} carregando={contas.carregando} editar={(cartao) => abrir("editar", cartao)} faturas={(cartao) => abrir("faturas", cartao)} criar={() => definirModal("criar")} />}
    {modal === "criar" && <Modal titulo="Novo cartão" fechar={() => definirModal(null)}><FormularioCartao contas={todasContas} concluir={concluir} /></Modal>}
    {modal === "editar" && selecionado && <Modal titulo={`Editar ${selecionado.nome}`} fechar={() => definirModal(null)}><FormularioCartao cartao={selecionado} contas={todasContas} concluir={concluir} /></Modal>}
    {modal === "faturas" && selecionado && <Modal titulo={`Faturas de ${selecionado.nome}`} fechar={() => definirModal(null)} largo><PainelFaturas cartao={selecionado} contas={todasContas} atualizado={() => contas.recarregar()} /></Modal>}
  </div>;
}

function ListaCartoes({ cartoes, carregando, editar, faturas, criar }: {
  cartoes: Conta[]; carregando: boolean; editar: (cartao: Conta) => void; faturas: (cartao: Conta) => void; criar: () => void;
}) {
  if (!cartoes.length && !carregando) return <Cartao><Vazio titulo="Seu primeiro cartão começa aqui" descricao="Cadastre o cartão mesmo que ainda não tenha uma conta pagadora." /><Botao onClick={criar}><Plus size={17} /> Novo cartão</Botao></Cartao>;
  return <div className="grade-contas">{cartoes.map((cartao) => <Cartao key={cartao.id} className={`cartao-conta ${cartao.situacao === "arquivada" ? "cartao-conta-arquivada" : ""}`}>
    <div className="conta-topo"><span className="conta-icone"><CreditCard size={23} /></span><span className={`etiqueta ${cartao.conta_pagamento_id ? "etiqueta-verde" : "etiqueta-alerta"}`}>{cartao.conta_pagamento_id ? "Conta vinculada" : "Sem conta vinculada"}</span></div>
    <h2>{cartao.nome}</h2><p className="conta-instituicao">{cartao.instituicao || "Sem instituição"} · {cartao.moeda}</p>
    {!cartao.conta_pagamento_id && <Aviso tipo="info">Você poderá escolher uma conta ao pagar ou adiantar a fatura.</Aviso>}
    <div className="fatura-limite"><span>Limite <strong>{formatarMoeda(cartao.limite_credito ?? "0", cartao.moeda)}</strong></span><span>Utilizado <strong>{formatarMoeda(cartao.limite_utilizado ?? "0", cartao.moeda)}</strong></span><span>Disponível <strong>{formatarMoeda(cartao.limite_disponivel ?? "0", cartao.moeda)}</strong></span></div>
    <div className="conta-rodape"><span>Fecha dia {cartao.dia_fechamento} · vence dia {cartao.dia_vencimento}</span><div><button className="link-acao" onClick={() => editar(cartao)}>Editar <ArrowRight size={14} /></button><button className="link-acao" onClick={() => faturas(cartao)}>Faturas <CreditCard size={14} /></button></div></div>
  </Cartao>)}</div>;
}

function FormularioCartao({ contas, concluir, cartao }: { contas: Conta[]; concluir: () => void; cartao?: Conta }) {
  const [nome, definirNome] = useState(cartao?.nome ?? "");
  const [moeda, definirMoeda] = useState(cartao?.moeda ?? "BRL");
  const [instituicao, definirInstituicao] = useState(cartao?.instituicao ?? "");
  const [contaPagamento, definirContaPagamento] = useState(cartao?.conta_pagamento_id ?? "");
  const [limite, definirLimite] = useState(cartao?.limite_credito ?? "");
  const [fechamento, definirFechamento] = useState(String(cartao?.dia_fechamento ?? 10));
  const [vencimento, definirVencimento] = useState(String(cartao?.dia_vencimento ?? 17));
  const [situacao, definirSituacao] = useState<Conta["situacao"]>(cartao?.situacao ?? "ativa");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const contasCompativeis = contas.filter((conta) => conta.tipo !== "cartao_credito" && conta.situacao === "ativa" && conta.moeda === moeda);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    const dados = {
      nome, instituicao: instituicao || null, conta_pagamento_id: contaPagamento || null,
      limite_credito: limite, dia_fechamento: Number(fechamento), dia_vencimento: Number(vencimento),
      ...(cartao ? { situacao } : { tipo: "cartao_credito", moeda: moeda.toUpperCase() }),
    };
    try { await api(cartao ? `financeiro/contas/${cartao.id}` : "financeiro/contas", { method: cartao ? "PATCH" : "POST", body: JSON.stringify(dados) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Campo rotulo="Nome do cartão" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
    <div className="formulario-duas-colunas"><Campo rotulo="Moeda" required minLength={3} maxLength={3} disabled={Boolean(cartao)} value={moeda} onChange={(e) => { definirMoeda(e.target.value.toUpperCase()); definirContaPagamento(""); }} /><Campo rotulo="Instituição (opcional)" maxLength={120} value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} /></div>
    <Seletor rotulo="Conta pagadora (opcional)" value={contaPagamento} onChange={(e) => definirContaPagamento(e.target.value)} dica="Você também poderá escolher ou trocar a conta ao pagar a fatura."><option value="">Sem conta vinculada</option>{contasCompativeis.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome} · {conta.instituicao || "Sem instituição"}</option>)}</Seletor>
    <div className="formulario-duas-colunas"><Campo rotulo="Limite total" type="number" min="0.01" step="0.01" required value={limite} onChange={(e) => definirLimite(e.target.value)} />{cartao && <Seletor rotulo="Situação" value={situacao} onChange={(e) => definirSituacao(e.target.value as Conta["situacao"])}><option value="ativa">Ativa</option><option value="arquivada">Arquivada</option></Seletor>}<Campo rotulo="Dia de fechamento" type="number" min="1" max="31" required value={fechamento} onChange={(e) => definirFechamento(e.target.value)} /><Campo rotulo="Dia de vencimento" type="number" min="1" max="31" required value={vencimento} onChange={(e) => definirVencimento(e.target.value)} /></div>
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{cartao ? "Salvar cartão" : "Cadastrar cartão"}</Botao></div>
  </form>;
}

function PainelFaturas({ cartao, contas, atualizado }: { cartao: Conta; contas: Conta[]; atualizado: () => void }) {
  const faturas = useDados<{ faturas: FaturaCartao[] }>(`financeiro/contas/${cartao.id}/faturas`);
  const [pagamento, definirPagamento] = useState<FaturaCartao | null>(null);
  const [adiantamento, definirAdiantamento] = useState<FaturaCartao | null>(null);
  const [fechamento, definirFechamento] = useState<FaturaCartao | null>(null);
  const [correcao, definirCorrecao] = useState<FaturaCartao | null>(null);
  const [saldoInsuficiente, definirSaldoInsuficiente] = useState<Record<string, unknown> | null>(null);
  const concluirOperacao = () => { definirPagamento(null); definirAdiantamento(null); faturas.recarregar(); atualizado(); };

  return <div className="painel-faturas">{faturas.erro && <Aviso>{faturas.erro}</Aviso>}
    <div className="fatura-limite"><span>Limite total <strong>{formatarMoeda(cartao.limite_credito ?? "0", cartao.moeda)}</strong></span><span>Utilizado <strong>{formatarMoeda(cartao.limite_utilizado ?? "0", cartao.moeda)}</strong></span><span>Disponível <strong>{formatarMoeda(cartao.limite_disponivel ?? "0", cartao.moeda)}</strong></span></div>
    <div className="lista-faturas">{faturas.dados?.faturas.map((fatura) => <div className="fatura-linha" key={fatura.id}><div><strong>{formatarData(fatura.inicio_periodo)} a {formatarData(fatura.fim_periodo)}</strong><small>Fecha em {formatarData(fatura.fechamento)} · vence em {formatarData(fatura.vencimento)}</small></div><strong>{formatarMoeda(fatura.valor_total, cartao.moeda)}</strong><span className={`situacao situacao-${fatura.situacao}`}>{fatura.situacao}</span><div className="acoes-fatura">{fatura.fechavel && <Botao type="button" variante="secundario" onClick={() => definirFechamento(fatura)}>Fechar agora</Botao>}{fatura.correcao_permitida && <Botao type="button" variante="secundario" onClick={() => definirCorrecao(fatura)}>Corrigir fatura</Botao>}{fatura.situacao === "aberta" && Number(fatura.valor_total) > 0.01 && <Botao type="button" variante="secundario" onClick={() => definirAdiantamento(fatura)}>Adiantar valor</Botao>}{(["fechada", "vencida"] as string[]).includes(fatura.situacao) && Number(fatura.valor_total) > 0 && <Botao type="button" variante="secundario" onClick={() => definirPagamento(fatura)}>Pagar fatura</Botao>}</div></div>)}</div>
    {!faturas.carregando && !faturas.dados?.faturas.length && <Vazio titulo="Nenhuma fatura" descricao="A primeira fatura será criada automaticamente." />}
    {fechamento && <FormularioFechamento cartao={cartao} fatura={fechamento} cancelar={() => definirFechamento(null)} concluir={() => { definirFechamento(null); faturas.recarregar(); }} />}
    {correcao && <PainelCorrecao cartao={cartao} fatura={correcao} cancelar={() => definirCorrecao(null)} atualizado={() => faturas.recarregar()} />}
    {adiantamento && <FormularioMovimentoFatura tipo="adiantamento" cartao={cartao} fatura={adiantamento} contas={contas} cancelar={() => definirAdiantamento(null)} concluir={concluirOperacao} saldoInsuficiente={definirSaldoInsuficiente} />}
    {pagamento && <FormularioMovimentoFatura tipo="pagamento" cartao={cartao} fatura={pagamento} contas={contas} cancelar={() => definirPagamento(null)} concluir={concluirOperacao} saldoInsuficiente={definirSaldoInsuficiente} />}
    {saldoInsuficiente && <Modal titulo="Saldo insuficiente" fechar={() => definirSaldoInsuficiente(null)}><div className="saldo-insuficiente"><Aviso>Nenhuma movimentação foi criada. A fatura permanece pendente.</Aviso><dl><div><dt>Conta</dt><dd>{String(saldoInsuficiente.conta ?? "—")}</dd></div><div><dt>Saldo disponível</dt><dd>{formatarMoeda(String(saldoInsuficiente.saldo_disponivel ?? 0), cartao.moeda)}</dd></div><div><dt>Valor solicitado</dt><dd>{formatarMoeda(String(saldoInsuficiente.valor_fatura ?? 0), cartao.moeda)}</dd></div></dl></div></Modal>}
  </div>;
}

function FormularioMovimentoFatura({ tipo, cartao, fatura, contas, cancelar, concluir, saldoInsuficiente }: {
  tipo: "pagamento" | "adiantamento"; cartao: Conta; fatura: FaturaCartao; contas: Conta[];
  cancelar: () => void; concluir: () => void; saldoInsuficiente: (detalhes: Record<string, unknown>) => void;
}) {
  const compativeis = contas.filter((conta) => conta.tipo !== "cartao_credito" && conta.situacao === "ativa" && conta.moeda === cartao.moeda);
  const [contaId, definirContaId] = useState(cartao.conta_pagamento_id ?? compativeis[0]?.id ?? "");
  const [data, definirData] = useState(dataHojeLocal()); const [valor, definirValor] = useState("");
  const [usarExistente, definirUsarExistente] = useState(false); const [transacao, definirTransacao] = useState("");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const maximo = (Number(fatura.valor_total) - 0.01).toFixed(2);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    const caminho = tipo === "pagamento" ? "pagamento" : "adiantamentos";
    const corpo = tipo === "pagamento" ? { data, conta_pagamento_id: contaId, codigo_movimentacao: usarExistente ? transacao : null } : { data, valor, conta_pagamento_id: contaId };
    try { await api(`financeiro/contas/${cartao.id}/faturas/${fatura.id}/${caminho}`, { method: "POST", body: JSON.stringify(corpo) }); concluir(); }
    catch (falha) {
      if (falha instanceof ErroApi && falha.codigo === "saldo_insuficiente" && falha.detalhes) saldoInsuficiente(falha.detalhes);
      else definirEstado({ erro: mensagemErro(falha), enviando: false });
    }
  }

  return <form className="pagamento-fatura" onSubmit={enviar}><h3>{tipo === "pagamento" ? `Pagar ${formatarMoeda(fatura.valor_total, cartao.moeda)}` : "Adiantar parte da fatura"}</h3><p>A conta escolhida será vinculada ao cartão e usada nos próximos ciclos.</p>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    {!compativeis.length && <Aviso>Cadastre uma conta ativa em {cartao.moeda} antes de continuar.</Aviso>}
    <Seletor rotulo="Conta pagadora" required value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Selecione</option>{compativeis.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome} · {conta.instituicao || "Sem instituição"}</option>)}</Seletor>
    {tipo === "adiantamento" && <Campo rotulo="Valor do adiantamento" type="number" min="0.01" max={maximo} step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} />}
    <Campo rotulo="Data" type="date" max={dataHojeLocal()} required value={data} onChange={(e) => definirData(e.target.value)} />
    {tipo === "pagamento" && <><label className="opcao-checkbox"><input type="checkbox" checked={usarExistente} onChange={(e) => definirUsarExistente(e.target.checked)} /> Vincular um débito existente</label>{usarExistente && <Campo rotulo="Código da movimentação" pattern="#?[1-9][0-9]{8}" maxLength={10} required value={transacao} onChange={(e) => definirTransacao(e.target.value)} />}</>}
    <div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando} disabled={!compativeis.length}>Confirmar</Botao></div>
  </form>;
}

function FormularioFechamento({ cartao, fatura, cancelar, concluir }: { cartao: Conta; fatura: FaturaCartao; cancelar: () => void; concluir: () => void }) {
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/contas/${cartao.id}/faturas/${fatura.id}/fechamento`, { method: "POST", body: JSON.stringify({}) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="pagamento-fatura" onSubmit={enviar}><h3>Fechar fatura agora</h3><p>A fatura de {formatarMoeda(fatura.valor_total, cartao.moeda)} será encerrada hoje e um novo ciclo será iniciado.</p>{estado.erro && <Aviso>{estado.erro}</Aviso>}<div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando}>Confirmar fechamento</Botao></div></form>;
}

function PainelCorrecao({ cartao, fatura, cancelar, atualizado }: { cartao: Conta; fatura: FaturaCartao; cancelar: () => void; atualizado: () => void }) {
  const detalhes = useDados<{ fatura: FaturaCartao }>(`financeiro/contas/${cartao.id}/faturas/${fatura.id}`);
  const [edicao, definirEdicao] = useState<Transacao | null>(null); const [exclusao, definirExclusao] = useState<Transacao | null>(null);
  const [valor, definirValor] = useState(""); const [motivo, definirMotivo] = useState(""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function corrigir(evento: FormEvent) {
    evento.preventDefault(); if (!edicao) return; definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/contas/${cartao.id}/faturas/${fatura.id}/lancamentos/${edicao.id}`, { method: "PATCH", body: JSON.stringify({ valor }) }); definirEdicao(null); detalhes.recarregar(); atualizado(); definirEstado({ erro: "", enviando: false }); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  async function excluir(evento: FormEvent) {
    evento.preventDefault(); if (!exclusao) return; definirEstado({ erro: "", enviando: true });
    try { await api(`financeiro/contas/${cartao.id}/faturas/${fatura.id}/lancamentos/${exclusao.id}`, { method: "DELETE", body: JSON.stringify({ motivo }) }); definirExclusao(null); definirMotivo(""); detalhes.recarregar(); atualizado(); definirEstado({ erro: "", enviando: false }); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  const lancamentos = detalhes.dados?.fatura.transacoes ?? [];
  return <div className="pagamento-fatura"><h3>Corrigir fatura</h3><p>Correções ficam disponíveis após o fechamento e antes do vencimento.</p>{detalhes.erro && <Aviso>{detalhes.erro}</Aviso>}{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <div className="lista-faturas">{lancamentos.map((item) => <div className="fatura-linha" key={item.id}><div><strong>{item.descricao_original}</strong><small>{formatarData(item.data_efetiva)} · {item.situacao}</small></div><strong>{formatarMoeda(String(Math.abs(Number(item.valor))), item.moeda)}</strong><div className="acoes-tabela">{item.corrigivel && <button type="button" onClick={() => { definirExclusao(null); definirEdicao(item); definirValor(Math.abs(Number(item.valor)).toFixed(2)); }}>Editar valor</button>}{item.excluivel && <button type="button" onClick={() => { definirEdicao(null); definirExclusao(item); }}>Excluir</button>}</div></div>)}</div>
    {edicao && <form onSubmit={corrigir}><Campo rotulo="Novo valor" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><div className="modal-acoes"><Botao type="button" variante="texto" onClick={() => definirEdicao(null)}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando}>Salvar correção</Botao></div></form>}
    {exclusao && <form onSubmit={excluir}><Campo rotulo="Motivo da exclusão" minLength={5} maxLength={300} required value={motivo} onChange={(e) => definirMotivo(e.target.value)} /><div className="modal-acoes"><Botao type="button" variante="texto" onClick={() => definirExclusao(null)}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando}>Confirmar exclusão</Botao></div></form>}
    <div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Voltar às faturas</Botao></div>
  </div>;
}
