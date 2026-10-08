"use client";

import { FormEvent, useState } from "react";
import { ArrowRight, Landmark, Plus, SlidersHorizontal, Wallet } from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, formatarMoeda, intervaloMes, mensagemErro } from "@/lib/formatacao";
import type { Conta, Resumo } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";

const tipos = [
  ["corrente", "Conta corrente"], ["poupanca", "Poupança"],
  ["cartao_credito", "Cartão de crédito"], ["dinheiro", "Dinheiro"], ["outro", "Outra conta"],
];

export default function Contas() {
  const [modal, definirModal] = useState<"criar" | "ajustar" | "editar" | null>(null);
  const [selecionada, selecionar] = useState<Conta | null>(null);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const [moedaEscolhida, definirMoeda] = useState("");
  const moedas = [...new Set(contas.dados?.dados.map((conta) => conta.moeda) ?? [])];
  const moeda = moedas.includes(moedaEscolhida) ? moedaEscolhida : moedas[0] ?? "BRL";
  const periodo = intervaloMes();
  const resumo = useDados<Resumo>(`financeiro/resumo?inicio=${periodo.inicio}&fim=${periodo.fim}&moeda=${moeda}`);

  function abrir(tipo: "ajustar" | "editar", conta: Conta) { selecionar(conta); definirModal(tipo); }
  function concluir() { definirModal(null); contas.recarregar(); resumo.recarregar(); }

  return <div>
    <CabecalhoPagina titulo="Minhas contas" descricao="Organize suas contas e acompanhe o saldo gerencial de cada uma."
      acao={<Botao onClick={() => definirModal("criar")}><Plus size={18} /> Adicionar conta</Botao>} />
    {contas.erro && <Aviso>{contas.erro}</Aviso>}
    <div className="nota-informativa"><Landmark size={21} /><p><strong>Saldo gerencial</strong> é o valor inicial que você informou mais as movimentações confirmadas. Ele pode ser diferente do saldo mostrado pelo banco.</p></div>
    {moedas.length > 1 && <div className="filtros"><label>Moeda <select value={moeda} onChange={(e) => definirMoeda(e.target.value)}>{moedas.map((item) => <option key={item} value={item}>{item}</option>)}</select></label></div>}
    {contas.dados?.dados.length ? <div className="grade-contas">{contas.dados.dados.filter((conta) => conta.moeda === moeda).map((conta) => {
      const posicao = resumo.dados?.contas.find((linha) => linha.conta_id === conta.id);
      return <Cartao key={conta.id} className={`cartao-conta ${conta.situacao === "arquivada" ? "cartao-conta-arquivada" : ""}`}>
        <div className="conta-topo"><span className="conta-icone"><Wallet size={23} /></span><span className={`etiqueta ${conta.situacao === "ativa" ? "etiqueta-verde" : ""}`}>{conta.situacao === "ativa" ? "Ativa" : "Arquivada"}</span></div>
        <h2>{conta.nome}</h2><p className="conta-instituicao">{conta.instituicao || "Sem instituição"} · {tipos.find(([chave]) => chave === conta.tipo)?.[1] ?? conta.tipo}</p>
        <div className="conta-saldo"><small>Saldo gerencial neste mês</small><strong>{resumo.carregando ? "—" : formatarMoeda(posicao?.saldo_final ?? conta.saldo_inicial, conta.moeda)}</strong></div>
        <div className="conta-rodape"><span>Saldo inicial: {formatarMoeda(conta.saldo_inicial, conta.moeda)}</span><div><button onClick={() => abrir("editar", conta)} className="link-acao">Editar <ArrowRight size={14} /></button>{conta.situacao === "ativa" && <button onClick={() => abrir("ajustar", conta)} className="link-acao">Ajustar <SlidersHorizontal size={14} /></button>}</div></div>
      </Cartao>;
    })}</div> : !contas.carregando && <Cartao><Vazio titulo="Sua primeira conta começa aqui" descricao="Adicione uma conta e informe o saldo gerencial inicial." /><Botao onClick={() => definirModal("criar")}><Plus size={17} /> Adicionar conta</Botao></Cartao>}
    {modal === "criar" && <Modal titulo="Adicionar conta" fechar={() => definirModal(null)}><FormularioConta concluir={concluir} /></Modal>}
    {modal === "ajustar" && selecionada && <Modal titulo={`Ajustar ${selecionada.nome}`} fechar={() => definirModal(null)}><FormularioAjuste conta={selecionada} concluir={concluir} /></Modal>}
    {modal === "editar" && selecionada && <Modal titulo={`Editar ${selecionada.nome}`} fechar={() => definirModal(null)}><FormularioEdicao conta={selecionada} concluir={concluir} /></Modal>}
  </div>;
}

function FormularioConta({ concluir }: { concluir: () => void }) {
  const [nome, definirNome] = useState("");
  const [tipo, definirTipo] = useState("corrente");
  const [moeda, definirMoeda] = useState("BRL");
  const [saldo, definirSaldo] = useState("0.00");
  const [instituicao, definirInstituicao] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEnviando(true); definirErro("");
    try {
      await api("financeiro/contas", { method: "POST", body: JSON.stringify({ nome, tipo, moeda: moeda.toUpperCase(), saldo_inicial: saldo, instituicao: instituicao || null }) });
      concluir();
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>
    {erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Nome da conta" placeholder="Ex.: Conta principal" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
    <Seletor rotulo="Tipo de conta" value={tipo} onChange={(e) => definirTipo(e.target.value)}>{tipos.map(([chave, rotulo]) => <option key={chave} value={chave}>{rotulo}</option>)}</Seletor>
    <Campo rotulo="Moeda" placeholder="BRL" required minLength={3} maxLength={3} value={moeda} onChange={(e) => definirMoeda(e.target.value)} />
    <Campo rotulo="Saldo inicial" type="number" step="0.01" required value={saldo} onChange={(e) => definirSaldo(e.target.value)} dica="Use um valor negativo se a conta começou no vermelho." />
    <Campo rotulo="Instituição (opcional)" placeholder="Ex.: Banco Horizonte" maxLength={120} value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Salvar conta <ArrowRight size={17} /></Botao></div>
  </form>;
}

function FormularioAjuste({ conta, concluir }: { conta: Conta; concluir: () => void }) {
  const [data, definirData] = useState(dataHojeLocal());
  const [valor, definirValor] = useState("");
  const [motivo, definirMotivo] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api(`financeiro/contas/${conta.id}/ajustes`, { method: "POST", body: JSON.stringify({ data, valor, motivo }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>
    <Aviso tipo="info">O ajuste altera o saldo desta conta e fica registrado no histórico. Não é receita nem despesa.</Aviso>
    {erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} />
    <Campo rotulo={`Valor do ajuste (${conta.moeda})`} type="number" step="0.01" required placeholder="Ex.: -50.00 ou 50.00" value={valor} onChange={(e) => definirValor(e.target.value)} />
    <Campo rotulo="Motivo" required minLength={5} maxLength={300} placeholder="Explique por que está ajustando o saldo" value={motivo} onChange={(e) => definirMotivo(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Confirmar ajuste</Botao></div>
  </form>;
}

function FormularioEdicao({ conta, concluir }: { conta: Conta; concluir: () => void }) {
  const [nome, definirNome] = useState(conta.nome);
  const [instituicao, definirInstituicao] = useState(conta.instituicao ?? "");
  const [situacao, definirSituacao] = useState(conta.situacao);
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api(`financeiro/contas/${conta.id}`, { method: "PATCH", body: JSON.stringify({ nome, instituicao: instituicao || null, situacao }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>
    {erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Nome da conta" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
    <Campo rotulo="Instituição" maxLength={120} value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} />
    <Seletor rotulo="Situação" value={situacao} onChange={(e) => definirSituacao(e.target.value as Conta["situacao"])}><option value="ativa">Ativa</option><option value="arquivada">Arquivada</option></Seletor>
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Salvar alterações</Botao></div>
  </form>;
}
