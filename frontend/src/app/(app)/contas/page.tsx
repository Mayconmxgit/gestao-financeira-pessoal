"use client";

import { FormEvent, useState } from "react";
import { ArrowLeft, ArrowRight, CreditCard, Landmark, Plus, SlidersHorizontal, Wallet } from "lucide-react";
import { api, ErroApi } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, intervaloMes, mensagemErro } from "@/lib/formatacao";
import type { Conta, FaturaCartao, Resumo } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";

const tipos = [
  ["corrente", "Conta corrente"], ["poupanca", "Poupança"],
  ["dinheiro", "Dinheiro"], ["outro", "Outra conta"],
];

type ModoCadastro = "conta" | "cartao";

export default function Contas() {
  const [modal, definirModal] = useState<"criar" | "ajustar" | "editar" | "faturas" | null>(null);
  const [selecionada, selecionar] = useState<Conta | null>(null);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const [moedaEscolhida, definirMoeda] = useState("");
  const moedas = [...new Set(contas.dados?.dados.map((conta) => conta.moeda) ?? [])];
  const moeda = moedas.includes(moedaEscolhida) ? moedaEscolhida : moedas[0] ?? "BRL";
  const periodo = intervaloMes();
  const resumo = useDados<Resumo>(`financeiro/resumo?inicio=${periodo.inicio}&fim=${periodo.fim}&moeda=${moeda}`);
  const todasContas = contas.dados?.dados ?? [];
  const contasBancarias = todasContas.filter((conta) => conta.tipo !== "cartao_credito");
  const cartoes = todasContas.filter((conta) => conta.tipo === "cartao_credito");
  const cartoesSemVinculo = cartoes.filter((cartao) => !cartao.conta_pagamento_id && cartao.moeda === moeda);
  const contasExibidas = contasBancarias.filter((conta) => conta.moeda === moeda);

  function abrir(tipo: "ajustar" | "editar" | "faturas", conta: Conta) { selecionar(conta); definirModal(tipo); }
  function atualizar() { contas.recarregar(); resumo.recarregar(); }
  function concluir() { definirModal(null); atualizar(); }

  return <div>
    <CabecalhoPagina titulo="Minhas contas" descricao="Organize suas contas e acompanhe o saldo gerencial de cada uma."
      acao={<Botao onClick={() => definirModal("criar")}><Plus size={18} /> Adicionar conta</Botao>} />
    {contas.erro && <Aviso>{contas.erro}</Aviso>}
    <div className="nota-informativa"><Landmark size={21} /><p><strong>Saldo gerencial</strong> é o valor inicial que você informou mais as movimentações confirmadas. Ele pode ser diferente do saldo mostrado pelo banco.</p></div>
    {moedas.length > 1 && <div className="filtros"><label>Moeda <select value={moeda} onChange={(e) => definirMoeda(e.target.value)}>{moedas.map((item) => <option key={item} value={item}>{item}</option>)}</select></label></div>}
    {contasExibidas.length || cartoesSemVinculo.length ? <div className="grade-contas">{contasExibidas.map((conta) => {
      const posicao = resumo.dados?.contas.find((linha) => linha.conta_id === conta.id);
      const cartoesVinculados = cartoes.filter((cartao) => cartao.conta_pagamento_id === conta.id);
      return <Cartao key={conta.id} className={`cartao-conta ${conta.situacao === "arquivada" ? "cartao-conta-arquivada" : ""}`}>
        <div className="conta-topo"><span className="conta-icone"><Wallet size={23} /></span><span className={`etiqueta ${conta.situacao === "ativa" ? "etiqueta-verde" : ""}`}>{conta.situacao === "ativa" ? "Ativa" : "Arquivada"}</span></div>
        <div className={`conta-cabecalho-integrado ${cartoesVinculados.length ? "" : "sem-cartoes"}`}>
          <div><h2>{conta.nome}</h2><p className="conta-instituicao">{conta.instituicao || "Sem instituição"} · {tipos.find(([chave]) => chave === conta.tipo)?.[1] ?? conta.tipo}</p></div>
          {cartoesVinculados.length > 0 && <div className="cartoes-vinculados">{cartoesVinculados.map((cartao) => <div key={cartao.id} className={`cartao-vinculado-resumo ${cartao.situacao === "arquivada" ? "arquivado" : ""}`}>
            <span>{cartao.nome}</span><strong>{formatarMoeda(cartao.limite_credito ?? "0", cartao.moeda)}</strong>
          </div>)}</div>}
        </div>
        <div className="conta-saldo"><small>Saldo gerencial neste mês</small><strong>{resumo.carregando ? "—" : formatarMoeda(posicao?.saldo_final ?? conta.saldo_inicial, conta.moeda)}</strong></div>
        <div className="conta-rodape"><span>Saldo inicial: {formatarMoeda(conta.saldo_inicial, conta.moeda)}</span><div><button onClick={() => abrir("editar", conta)} className="link-acao">Editar <ArrowRight size={14} /></button>{conta.situacao === "ativa" && <button onClick={() => abrir("ajustar", conta)} className="link-acao">Ajustar <SlidersHorizontal size={14} /></button>}</div></div>
      </Cartao>;
    })}{cartoesSemVinculo.map((cartao) => <Cartao key={cartao.id} className="cartao-conta cartao-sem-vinculo">
      <div className="conta-topo"><span className="conta-icone"><CreditCard size={23} /></span><span className="etiqueta etiqueta-alerta">Ação necessária</span></div>
      <h2>{cartao.nome}</h2><p className="conta-instituicao">Cartão de crédito sem conta vinculada</p>
      <Aviso tipo="info">Vincule este cartão a uma conta bancária para gerenciar limite e faturas.</Aviso>
      <div className="conta-rodape"><span>Limite: {formatarMoeda(cartao.limite_credito ?? "0", cartao.moeda)}</span><button onClick={() => abrir("editar", cartao)} className="link-acao">Vincular cartão <ArrowRight size={14} /></button></div>
    </Cartao>)}</div> : !contas.carregando && <Cartao><Vazio titulo="Sua primeira conta começa aqui" descricao="Adicione uma conta e informe o saldo gerencial inicial." /><Botao onClick={() => definirModal("criar")}><Plus size={17} /> Adicionar conta</Botao></Cartao>}
    {modal === "criar" && <Modal titulo="Adicionar conta" fechar={() => definirModal(null)}><FormularioConta contas={contas.dados?.dados ?? []} concluir={concluir} /></Modal>}
    {modal === "ajustar" && selecionada && <Modal titulo={`Ajustar ${selecionada.nome}`} fechar={() => definirModal(null)}><FormularioAjuste conta={selecionada} concluir={concluir} /></Modal>}
    {modal === "editar" && selecionada && <Modal titulo={`Editar ${selecionada.nome}`} fechar={() => definirModal(null)} largo={selecionada.tipo !== "cartao_credito"}>
      {selecionada.tipo === "cartao_credito"
        ? <FormularioEdicaoCartao cartao={selecionada} contas={todasContas} concluir={concluir} />
        : <FormularioGerenciamentoConta conta={selecionada} contas={todasContas} concluir={concluir} atualizar={atualizar} abrirFaturas={(cartao) => abrir("faturas", cartao)} />}
    </Modal>}
    {modal === "faturas" && selecionada && <Modal titulo={`Faturas de ${selecionada.nome}`} fechar={() => definirModal(null)} largo><PainelFaturas conta={selecionada} concluir={concluir} /></Modal>}
  </div>;
}

function PainelFaturas({ conta, concluir }: { conta: Conta; concluir: () => void }) {
  const faturas = useDados<{ faturas: FaturaCartao[] }>(`financeiro/contas/${conta.id}/faturas`);
  const [pagamento, definirPagamento] = useState<FaturaCartao | null>(null);
  const [adiantamento, definirAdiantamento] = useState<FaturaCartao | null>(null);
  const [saldoInsuficiente, definirSaldoInsuficiente] = useState<Record<string, unknown> | null>(null);

  return <div className="painel-faturas">
    {faturas.erro && <Aviso>{faturas.erro}</Aviso>}
    <div className="fatura-limite"><span>Limite total <strong>{formatarMoeda(conta.limite_credito ?? "0", conta.moeda)}</strong></span><span>Utilizado <strong>{formatarMoeda(conta.limite_utilizado ?? "0", conta.moeda)}</strong></span><span>Disponível <strong>{formatarMoeda(conta.limite_disponivel ?? "0", conta.moeda)}</strong></span></div>
    <div className="lista-faturas">{faturas.dados?.faturas.map((fatura) => <div className="fatura-linha" key={fatura.id}>
      <div><strong>{formatarData(fatura.inicio_periodo)} a {formatarData(fatura.fim_periodo)}</strong><small>Fecha em {formatarData(fatura.fechamento)} · vence em {formatarData(fatura.vencimento)}</small></div>
      <strong>{formatarMoeda(fatura.valor_total, conta.moeda)}</strong>
      <span className={`situacao situacao-${fatura.situacao}`}>{fatura.situacao}</span>
      {fatura.situacao === "aberta" && Number(fatura.valor_total) > 0.01 && <Botao type="button" variante="secundario" onClick={() => { definirPagamento(null); definirAdiantamento(fatura); }}>Adiantar valor</Botao>}
      {(["fechada", "vencida"] as string[]).includes(fatura.situacao) && Number(fatura.valor_total) > 0 && <Botao type="button" variante="secundario" onClick={() => { definirAdiantamento(null); definirPagamento(fatura); }}>Pagar fatura</Botao>}
    </div>)}</div>
    {!faturas.carregando && !faturas.dados?.faturas.length && <Vazio titulo="Nenhuma fatura" descricao="A primeira fatura será criada automaticamente." />}
    {adiantamento && <FormularioAdiantamentoFatura conta={conta} fatura={adiantamento} cancelar={() => definirAdiantamento(null)} concluido={() => { definirAdiantamento(null); faturas.recarregar(); concluir(); }} saldoInsuficiente={definirSaldoInsuficiente} />}
    {pagamento && <FormularioPagamentoFatura conta={conta} fatura={pagamento} cancelar={() => definirPagamento(null)} concluido={() => { definirPagamento(null); faturas.recarregar(); concluir(); }} saldoInsuficiente={definirSaldoInsuficiente} />}
    {saldoInsuficiente && <Modal titulo="Saldo insuficiente" fechar={() => definirSaldoInsuficiente(null)}><div className="saldo-insuficiente"><Aviso>Nenhuma movimentação foi criada. A fatura permanece pendente.</Aviso><dl><div><dt>Conta</dt><dd>{String(saldoInsuficiente.conta ?? "—")}</dd></div><div><dt>Saldo disponível</dt><dd>{formatarMoeda(String(saldoInsuficiente.saldo_disponivel ?? 0), conta.moeda)}</dd></div><div><dt>Valor solicitado</dt><dd>{formatarMoeda(String(saldoInsuficiente.valor_fatura ?? 0), conta.moeda)}</dd></div><div><dt>Valor faltante</dt><dd>{formatarMoeda(String(saldoInsuficiente.valor_faltante ?? 0), conta.moeda)}</dd></div></dl></div></Modal>}
  </div>;
}

function FormularioAdiantamentoFatura({ conta, fatura, cancelar, concluido, saldoInsuficiente }: {
  conta: Conta; fatura: FaturaCartao; cancelar: () => void; concluido: () => void;
  saldoInsuficiente: (detalhes: Record<string, unknown>) => void;
}) {
  const [data, definirData] = useState(dataHojeLocal());
  const [valor, definirValor] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);
  const maximo = (Number(fatura.valor_total) - 0.01).toFixed(2);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try {
      await api(`financeiro/contas/${conta.id}/faturas/${fatura.id}/adiantamentos`, {
        method: "POST", body: JSON.stringify({ data, valor }),
      });
      concluido();
    } catch (falha) {
      if (falha instanceof ErroApi && falha.codigo === "saldo_insuficiente" && falha.detalhes) saldoInsuficiente(falha.detalhes);
      else definirErro(mensagemErro(falha));
    } finally { definirEnviando(false); }
  }

  return <form className="pagamento-fatura" onSubmit={enviar}>
    <h3>Adiantar parte da fatura</h3>
    <p>O valor será debitado de <strong>{conta.conta_pagamento_nome}</strong>, reduzirá a fatura de {formatarMoeda(fatura.valor_total, conta.moeda)} e liberará limite no cartão.</p>
    {erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Valor do adiantamento" type="number" min="0.01" max={maximo} step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} dica={`Informe até ${formatarMoeda(maximo, conta.moeda)} para manter o pagamento parcial.`} />
    <Campo rotulo="Data do adiantamento" type="date" max={dataHojeLocal()} required value={data} onChange={(e) => definirData(e.target.value)} />
    <div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={enviando}>Confirmar adiantamento</Botao></div>
  </form>;
}

function FormularioPagamentoFatura({ conta, fatura, cancelar, concluido, saldoInsuficiente }: {
  conta: Conta; fatura: FaturaCartao; cancelar: () => void; concluido: () => void;
  saldoInsuficiente: (detalhes: Record<string, unknown>) => void;
}) {
  const [data, definirData] = useState(dataHojeLocal());
  const [usarExistente, definirUsarExistente] = useState(false);
  const [transacao, definirTransacao] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try {
      await api(`financeiro/contas/${conta.id}/faturas/${fatura.id}/pagamento`, { method: "POST", body: JSON.stringify({ data, codigo_movimentacao: usarExistente ? transacao : null }) });
      concluido();
    } catch (falha) {
      if (falha instanceof ErroApi && falha.codigo === "saldo_insuficiente" && falha.detalhes) saldoInsuficiente(falha.detalhes);
      else definirErro(mensagemErro(falha));
    } finally { definirEnviando(false); }
  }

  return <form className="pagamento-fatura" onSubmit={enviar}>
    <h3>Pagar {formatarMoeda(fatura.valor_total, conta.moeda)}</h3>
    <p>O débito integral será lançado em <strong>{conta.conta_pagamento_nome}</strong>.</p>
    {erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Data do pagamento" type="date" max={dataHojeLocal()} required value={data} onChange={(e) => definirData(e.target.value)} />
    <label className="opcao-checkbox"><input type="checkbox" checked={usarExistente} onChange={(e) => definirUsarExistente(e.target.checked)} /> Vincular um débito existente</label>
    {usarExistente && <Campo rotulo="Código da movimentação" placeholder="#123456789" pattern="#?[1-9][0-9]{8}" maxLength={10} required value={transacao} onChange={(e) => definirTransacao(e.target.value)} dica="Use o código exibido no histórico. O débito deve estar confirmado, não conciliado e ter o mesmo valor da fatura." />}
    <div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={enviando}>Confirmar pagamento</Botao></div>
  </form>;
}

function FormularioConta({ contas, concluir }: { contas: Conta[]; concluir: () => void }) {
  const [modo, definirModo] = useState<ModoCadastro>("conta");
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

  return <div className="formulario-com-alternador">
    <AlternadorCadastro modo={modo} alterar={definirModo} />
    {modo === "cartao" ? <FormularioCartao contas={contas} concluir={concluir} /> : <form className="formulario-grade" onSubmit={enviar}>
      {erro && <Aviso>{erro}</Aviso>}
      <Campo rotulo="Nome da conta" placeholder="Ex.: Conta principal" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
      <Seletor rotulo="Tipo de conta" value={tipo} onChange={(e) => definirTipo(e.target.value)}>{tipos.map(([chave, rotulo]) => <option key={chave} value={chave}>{rotulo}</option>)}</Seletor>
      <Campo rotulo="Moeda" placeholder="BRL" required minLength={3} maxLength={3} value={moeda} onChange={(e) => definirMoeda(e.target.value)} />
      <Campo rotulo="Saldo inicial" type="number" step="0.01" required value={saldo} onChange={(e) => definirSaldo(e.target.value)} dica="Use um valor negativo se a conta começou no vermelho." />
      <Campo rotulo="Instituição (opcional)" placeholder="Ex.: Banco Horizonte" maxLength={120} value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} />
      <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Salvar conta <ArrowRight size={17} /></Botao></div>
    </form>}
  </div>;
}

function AlternadorCadastro({ modo, alterar }: { modo: ModoCadastro; alterar: (modo: ModoCadastro) => void }) {
  return <div className="alternador-cadastro" role="tablist" aria-label="Tipo de cadastro">
    <button type="button" role="tab" aria-selected={modo === "conta"} className={modo === "conta" ? "ativo" : ""} onClick={() => alterar("conta")}><Landmark size={17} /> Conta bancária</button>
    <button type="button" role="tab" aria-selected={modo === "cartao"} className={modo === "cartao" ? "ativo" : ""} onClick={() => alterar("cartao")}><CreditCard size={17} /> Cartão de crédito</button>
  </div>;
}

function FormularioCartao({ contas, concluir, cartao, contaVinculada, cancelar }: {
  contas: Conta[]; concluir: () => void; cartao?: Conta; contaVinculada?: Conta; cancelar?: () => void;
}) {
  const contasBancarias = contas.filter((conta) => conta.tipo !== "cartao_credito" && conta.situacao === "ativa");
  const [nome, definirNome] = useState(cartao?.nome ?? "");
  const [contaPagamento, definirContaPagamento] = useState(cartao?.conta_pagamento_id ?? contaVinculada?.id ?? contasBancarias[0]?.id ?? "");
  const [limite, definirLimite] = useState(cartao?.limite_credito ?? "");
  const [diaFechamento, definirDiaFechamento] = useState(String(cartao?.dia_fechamento ?? 10));
  const [diaVencimento, definirDiaVencimento] = useState(String(cartao?.dia_vencimento ?? 17));
  const [situacao, definirSituacao] = useState<Conta["situacao"]>(cartao?.situacao ?? "ativa");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    const dados = {
      nome, conta_pagamento_id: contaPagamento, limite_credito: limite,
      dia_fechamento: Number(diaFechamento), dia_vencimento: Number(diaVencimento),
      ...(cartao ? { situacao } : { tipo: "cartao_credito" }),
    };
    try {
      await api(cartao ? `financeiro/contas/${cartao.id}` : "financeiro/contas", { method: cartao ? "PATCH" : "POST", body: JSON.stringify(dados) });
      concluir();
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>
    {cancelar && <button type="button" className="voltar-gerenciador" onClick={cancelar}><ArrowLeft size={15} /> Voltar aos cartões</button>}
    {erro && <Aviso>{erro}</Aviso>}
    {!contasBancarias.length && <Aviso tipo="info">Cadastre primeiro uma conta bancária ativa para vincular o cartão.</Aviso>}
    <Campo rotulo="Nome do cartão" placeholder="Ex.: Cartão Platinum" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
    <Seletor rotulo="Conta bancária vinculada" required value={contaPagamento} onChange={(e) => definirContaPagamento(e.target.value)}><option value="">Selecione</option>{contasBancarias.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome} · {conta.instituicao || "Sem instituição"}</option>)}</Seletor>
    <div className="formulario-duas-colunas">
      <Campo rotulo="Limite total" type="number" min="0.01" step="0.01" required value={limite} onChange={(e) => definirLimite(e.target.value)} />
      {cartao && <Seletor rotulo="Situação" value={situacao} onChange={(e) => definirSituacao(e.target.value as Conta["situacao"])}><option value="ativa">Ativa</option><option value="arquivada">Arquivada</option></Seletor>}
      <Campo rotulo="Dia de fechamento" type="number" min="1" max="31" required value={diaFechamento} onChange={(e) => definirDiaFechamento(e.target.value)} />
      <Campo rotulo="Dia de vencimento" type="number" min="1" max="31" required value={diaVencimento} onChange={(e) => definirDiaVencimento(e.target.value)} />
    </div>
    <div className="modal-acoes"><Botao type="submit" carregando={enviando} disabled={!contasBancarias.length}>{cartao ? "Salvar cartão" : "Cadastrar cartão"} <ArrowRight size={17} /></Botao></div>
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

function FormularioGerenciamentoConta({ conta, contas, concluir, atualizar, abrirFaturas }: {
  conta: Conta; contas: Conta[]; concluir: () => void; atualizar: () => void; abrirFaturas: (cartao: Conta) => void;
}) {
  const [modo, definirModo] = useState<ModoCadastro>("conta");
  const [cartaoSelecionado, selecionarCartao] = useState<Conta | "novo" | null>(null);
  const cartoes = contas.filter((item) => item.tipo === "cartao_credito" && item.conta_pagamento_id === conta.id);

  function alterarModo(novoModo: ModoCadastro) {
    definirModo(novoModo); selecionarCartao(null);
  }

  function concluirCartao() {
    selecionarCartao(null); atualizar();
  }

  return <div className="formulario-com-alternador">
    <AlternadorCadastro modo={modo} alterar={alterarModo} />
    {modo === "conta" ? <FormularioDadosConta conta={conta} concluir={concluir} /> : cartaoSelecionado ?
      <FormularioCartao contas={contas} contaVinculada={conta} cartao={cartaoSelecionado === "novo" ? undefined : cartaoSelecionado}
        concluir={concluirCartao} cancelar={() => selecionarCartao(null)} /> :
      <div className="gerenciador-cartoes">
        <div className="gerenciador-cartoes-cabecalho"><div><h3>Cartões vinculados</h3><p>Cadastre e gerencie os cartões desta conta.</p></div><Botao type="button" variante="secundario" onClick={() => selecionarCartao("novo")}><Plus size={16} /> Novo cartão</Botao></div>
        {cartoes.length ? <div className="gerenciador-cartoes-lista">{cartoes.map((cartao) => <div className={`gerenciador-cartao-item ${cartao.situacao === "arquivada" ? "arquivado" : ""}`} key={cartao.id}>
          <span className="gerenciador-cartao-icone"><CreditCard size={19} /></span>
          <div><strong>{cartao.nome}</strong><small>Limite {formatarMoeda(cartao.limite_credito ?? "0", cartao.moeda)} · fecha dia {cartao.dia_fechamento} · vence dia {cartao.dia_vencimento}</small></div>
          <span className={`etiqueta ${cartao.situacao === "ativa" ? "etiqueta-verde" : ""}`}>{cartao.situacao === "ativa" ? "Ativo" : "Arquivado"}</span>
          <div className="gerenciador-cartao-acoes"><button type="button" className="link-acao" onClick={() => selecionarCartao(cartao)}>Editar <ArrowRight size={14} /></button><button type="button" className="link-acao" onClick={() => abrirFaturas(cartao)}>Faturas <CreditCard size={14} /></button></div>
        </div>)}</div> : <Vazio titulo="Nenhum cartão vinculado" descricao="Cadastre o primeiro cartão de crédito desta conta." />}
      </div>}
  </div>;
}

function FormularioDadosConta({ conta, concluir }: { conta: Conta; concluir: () => void }) {
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

function FormularioEdicaoCartao({ cartao, contas, concluir }: { cartao: Conta; contas: Conta[]; concluir: () => void }) {
  return <FormularioCartao cartao={cartao} contas={contas} concluir={concluir} />;
}
