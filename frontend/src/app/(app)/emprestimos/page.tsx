"use client";

import { FormEvent, useState } from "react";
import { CalendarClock, HandCoins, Pencil, Plus, ReceiptText, Trash2 } from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Conta, Emprestimo, ParcelaEmprestimo } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";

const nomesSistema = { price: "Parcelas fixas (PRICE)", sac: "Parcelas decrescentes (SAC)", manual: "Total combinado manualmente" };
const nomesTipo = { emprestimo_pessoal: "Empréstimo pessoal", emprestimo_consignado: "Empréstimo consignado", financiamento: "Financiamento", parcelamento: "Parcelamento", renegociacao: "Renegociação" };

export default function Emprestimos() {
  const lista = useDados<{ dados: Emprestimo[] }>("financeiro/emprestimos");
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const [modal, definirModal] = useState<"criar" | "editar" | "detalhes" | null>(null);
  const [selecionado, selecionar] = useState<string | null>(null);
  const detalhes = useDados<Emprestimo>(selecionado ? `financeiro/emprestimos/${selecionado}` : null);
  const contasValidas = contas.dados?.dados.filter((conta) => conta.situacao === "ativa" && conta.tipo !== "cartao_credito") ?? [];

  function abrirDetalhes(id: string) { selecionar(id); definirModal("detalhes"); }
  function abrirEdicao(id: string) { selecionar(id); definirModal("editar"); }
  function atualizar() { lista.recarregar(); detalhes.recarregar(); }
  function concluirCadastro() { definirModal(null); lista.recarregar(); }
  async function excluirSelecionado() {
    if (!selecionado) return;
    await api(`financeiro/emprestimos/${selecionado}`, { method: "DELETE", body: JSON.stringify({ confirmar: true }) });
    definirModal(null); selecionar(null); lista.recarregar();
  }

  return <div>
    <CabecalhoPagina titulo="Empréstimos" descricao="Acompanhe valores recebidos, parcelas, juros e saldo devedor."
      acao={<Botao onClick={() => definirModal("criar")}><Plus size={18} /> Cadastrar empréstimo</Botao>} />
    <div className="nota-informativa"><HandCoins size={21} /><p>O valor recebido aumenta o saldo da conta, mas não é receita. Somente juros e taxas pagos entram nas despesas.</p></div>
    {lista.erro && <Aviso>{lista.erro}</Aviso>}
    {lista.dados?.dados.length ? <div className="grade-emprestimos">{lista.dados.dados.map((emprestimo) =>
      <Cartao key={emprestimo.id} className="cartao-emprestimo">
        <div className="emprestimo-topo"><span className="emprestimo-icone"><ReceiptText size={22} /></span>
          <span className={`etiqueta ${emprestimo.situacao_exibicao === "ativo" ? "etiqueta-verde" : ""}`}>{emprestimo.situacao_exibicao === "ativo" ? "Em andamento" : emprestimo.situacao_exibicao}</span></div>
        <h2>{emprestimo.credor}</h2><p>{emprestimo.descricao || nomesSistema[emprestimo.sistema_amortizacao]}</p>
        <div className="emprestimo-valores"><div><small>Valor financiado</small><strong>{formatarMoeda(emprestimo.valor_financiado, emprestimo.moeda)}</strong></div>
          <div><small>Saldo devedor atual</small><strong>{formatarMoeda(emprestimo.saldo_financiado, emprestimo.moeda)}</strong></div>
          <div><small>Total contratado</small><strong>{formatarMoeda(emprestimo.total_previsto, emprestimo.moeda)}</strong></div></div>
        <div className="emprestimo-proxima"><CalendarClock size={16} />{emprestimo.proxima_parcela
          ? <span>Próxima: {formatarMoeda(emprestimo.proxima_parcela.valor_total, emprestimo.moeda)} em {formatarData(emprestimo.proxima_parcela.vencimento)}</span>
          : <span>{emprestimo.parcelas_acompanhadas === 0 ? "Nenhuma parcela se aplica após o cadastro." : "Todas as parcelas aplicáveis foram pagas."}</span>}</div>
        <div className="acoes-cartao-emprestimo">{["ativo", "atrasado"].includes(emprestimo.situacao) && <Botao variante="texto" onClick={() => abrirEdicao(emprestimo.id)}><Pencil size={15} /> Editar</Botao>}<Botao variante="secundario" onClick={() => abrirDetalhes(emprestimo.id)}>Ver parcelas</Botao></div>
      </Cartao>)}</div> : !lista.carregando && <Cartao><Vazio titulo="Nenhum empréstimo cadastrado" descricao="Cadastre um valor recebido para gerar o cronograma e acompanhar a dívida." />
      <Botao onClick={() => definirModal("criar")}><Plus size={17} /> Cadastrar empréstimo</Botao></Cartao>}
    {modal === "criar" && <Modal titulo="Cadastrar empréstimo" fechar={() => definirModal(null)} largo>
      <FormularioEmprestimo contas={contasValidas} concluir={concluirCadastro} />
    </Modal>}
    {modal === "editar" && <Modal titulo="Editar empréstimo" fechar={() => definirModal(null)} largo>
      {detalhes.erro && <Aviso>{detalhes.erro}</Aviso>}
      {detalhes.dados ? <FormularioEmprestimo contas={contasValidas} emprestimo={detalhes.dados} concluir={concluirCadastro} /> : <p>Carregando empréstimo...</p>}
    </Modal>}
    {modal === "detalhes" && <Modal titulo="Parcelas do empréstimo" fechar={() => definirModal(null)} largo className="modal-parcelas">
      {detalhes.erro && <Aviso>{detalhes.erro}</Aviso>}
      {detalhes.dados ? <DetalhesEmprestimo emprestimo={detalhes.dados} contas={contasValidas} atualizar={atualizar} excluir={excluirSelecionado} /> : <p>Carregando parcelas...</p>}
    </Modal>}
  </div>;
}

function FormularioEmprestimo({ contas, concluir, emprestimo }: { contas: Conta[]; concluir: () => void; emprestimo?: Emprestimo }) {
  const hoje = dataHojeLocal();
  const [dados, definirDados] = useState({
    conta_recebimento_id: String(emprestimo?.conta_recebimento_id ?? contas[0]?.id ?? ""), credor: emprestimo?.credor ?? "", descricao: emprestimo?.descricao ?? "",
    tipo_divida: emprestimo?.tipo_divida ?? "emprestimo_pessoal", valor_principal: emprestimo?.valor_recebido ?? emprestimo?.valor_principal ?? "",
    valor_financiado: emprestimo?.valor_financiado ?? "", iof: emprestimo?.iof ?? "0.00", taxas_totais: emprestimo?.taxas_totais ?? "0.00",
    taxa_juros_mensal: emprestimo?.taxa_juros_mensal ?? "0.00", taxa_juros_anual: emprestimo?.taxa_juros_anual ?? "",
    cet_mensal: emprestimo?.cet_mensal ?? "", cet_anual: emprestimo?.cet_anual ?? "", sistema_amortizacao: emprestimo?.sistema_amortizacao ?? "price",
    quantidade_parcelas: String(emprestimo?.quantidade_parcelas ?? 12), valor_parcela_contratada: emprestimo?.valor_parcela_contratada ?? "",
    total_contratado: emprestimo?.total_contratado ?? "", valor_total_manual: emprestimo?.sistema_amortizacao === "manual" ? emprestimo.total_previsto : "",
    data_contratacao: emprestimo?.data_contratacao ?? hoje, data_recebimento: emprestimo?.data_recebimento ?? hoje,
    data_base_calculo: emprestimo?.data_base_calculo ?? emprestimo?.data_recebimento ?? hoje, primeiro_vencimento: emprestimo?.primeiro_vencimento ?? hoje,
  });
  const [afetarSaldo, definirAfetarSaldo] = useState(emprestimo?.afeta_saldo_recebimento ?? true);
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const cronogramaBloqueado = Boolean(emprestimo && emprestimo.parcelas_pagas > 0);
  const dicaParcela = cronogramaBloqueado
    ? "Corrige o valor oficial do contrato sem recalcular as parcelas já registradas."
    : dados.sistema_amortizacao === "price"
      ? "Valor exato da prestação informado pelo banco; prevalece sobre a estimativa PRICE."
      : "No SAC, serve como referência; o cronograma continua com parcelas decrescentes.";
  const alterar = (campo: string, valor: string) => definirDados((atual) => ({ ...atual, [campo]: valor }));

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      await api(emprestimo ? `financeiro/emprestimos/${emprestimo.id}` : "financeiro/emprestimos", { method: emprestimo ? "PATCH" : "POST", body: JSON.stringify({
        ...dados, conta_recebimento_id: dados.conta_recebimento_id, quantidade_parcelas: Number(dados.quantidade_parcelas),
        descricao: dados.descricao || null, valor_total_manual: dados.sistema_amortizacao === "manual" ? dados.valor_total_manual : null,
        valor_financiado: dados.valor_financiado || undefined,
        valor_parcela_contratada: dados.sistema_amortizacao === "manual" ? null : dados.valor_parcela_contratada || null,
        total_contratado: dados.sistema_amortizacao === "manual" ? null : dados.total_contratado || null,
        taxa_juros_anual: dados.taxa_juros_anual || null,
        cet_mensal: dados.cet_mensal || null, cet_anual: dados.cet_anual || null,
        afetar_saldo_recebimento: afetarSaldo,
      }) });
      concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }

  if (!contas.length) return <Aviso tipo="info">Cadastre primeiro uma conta ativa que não seja cartão de crédito.</Aviso>;
  return <form className="formulario-grade" onSubmit={enviar}>
    {estado.erro && <Aviso>{estado.erro}</Aviso>}
    {cronogramaBloqueado && <Aviso tipo="info">Este contrato já possui pagamentos. Campos que mudariam o cronograma ficam bloqueados; valores oficiais de referência ainda podem ser corrigidos sem alterar as parcelas existentes.</Aviso>}
    <AjudaCamposEmprestimo />
    <div className="formulario-duas-colunas"><Campo rotulo="Instituição financeira ou credor" required minLength={2} maxLength={160} placeholder="Ex.: Itaú" value={dados.credor} onChange={(e) => alterar("credor", e.target.value)} />
      <Seletor rotulo="Conta que recebeu o valor" required disabled={cronogramaBloqueado} value={dados.conta_recebimento_id} onChange={(e) => alterar("conta_recebimento_id", e.target.value)}>{contas.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome} · {conta.moeda}</option>)}</Seletor></div>
    <div className="formulario-duas-colunas"><Seletor rotulo="Tipo da dívida" value={dados.tipo_divida} onChange={(e) => alterar("tipo_divida", e.target.value)}>{Object.entries(nomesTipo).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</Seletor>
      <Campo rotulo="Descrição (opcional)" maxLength={300} placeholder="Ex.: Reforma da casa" value={dados.descricao} onChange={(e) => alterar("descricao", e.target.value)} /></div>
    <div className="formulario-tres-colunas"><Campo rotulo="Valor recebido" type="number" min="0.01" step="0.01" required disabled={cronogramaBloqueado} value={dados.valor_principal} onChange={(e) => alterar("valor_principal", e.target.value)} dica="Dinheiro que efetivamente entrou na conta." />
      <Campo rotulo="Valor financiado" type="number" min="0.01" step="0.01" required disabled={cronogramaBloqueado} value={dados.valor_financiado} onChange={(e) => alterar("valor_financiado", e.target.value)} dica="Valor recebido mais IOF e outros custos financiados." />
      <Campo rotulo="IOF financiado" type="number" min="0" step="0.01" required disabled={cronogramaBloqueado} value={dados.iof} onChange={(e) => alterar("iof", e.target.value)} dica="Parte do imposto incluída no valor financiado." /></div>
    <div className="formulario-quatro-colunas"><Campo rotulo="Juros ao mês (%)" type="number" min="0" max="999.999999" step="0.000001" required disabled={cronogramaBloqueado} value={dados.taxa_juros_mensal} onChange={(e) => alterar("taxa_juros_mensal", e.target.value)} />
      <Campo rotulo="Juros ao ano (%)" type="number" min="0" step="0.000001" value={dados.taxa_juros_anual} onChange={(e) => alterar("taxa_juros_anual", e.target.value)} />
      <Campo rotulo="CET ao mês (%)" type="number" min="0" step="0.000001" value={dados.cet_mensal} onChange={(e) => alterar("cet_mensal", e.target.value)} />
      <Campo rotulo="CET ao ano (%)" type="number" min="0" step="0.000001" value={dados.cet_anual} onChange={(e) => alterar("cet_anual", e.target.value)} /></div>
    <div className="formulario-duas-colunas"><Seletor rotulo="Sistema de amortização" disabled={cronogramaBloqueado} value={dados.sistema_amortizacao} onChange={(e) => alterar("sistema_amortizacao", e.target.value)} dica="Define se as parcelas serão fixas, decrescentes ou distribuídas por um total informado.">{Object.entries(nomesSistema).map(([valor, nome]) => <option key={valor} value={valor}>{nome}</option>)}</Seletor>
      <Campo rotulo="Quantidade de parcelas" type="number" min="1" max="600" required disabled={cronogramaBloqueado} value={dados.quantidade_parcelas} onChange={(e) => alterar("quantidade_parcelas", e.target.value)} dica="Número total de prestações previsto no contrato." /></div>
    {dados.sistema_amortizacao === "manual"
      ? <Campo rotulo="Total contratado do cronograma" type="number" min="0.01" step="0.01" required disabled={cronogramaBloqueado} value={dados.valor_total_manual} onChange={(e) => alterar("valor_total_manual", e.target.value)} dica="Valor total que será distribuído entre as parcelas no modo manual." />
      : <div className="formulario-duas-colunas"><Campo rotulo="Parcela contratada (opcional)" type="number" min="0.01" step="0.01" placeholder="Ex.: 270,75" value={dados.valor_parcela_contratada} onChange={(e) => alterar("valor_parcela_contratada", e.target.value)} dica={dicaParcela} />
        <Campo rotulo="Total contratado (opcional)" type="number" min="0.01" step="0.01" placeholder="Ex.: 9.747,00" value={dados.total_contratado} onChange={(e) => alterar("total_contratado", e.target.value)} dica="Total oficial de todas as prestações; usado para mostrar o custo total do crédito." /></div>}
    <div className="formulario-quatro-colunas"><Campo rotulo="Data da contratação" type="date" max={hoje} required value={dados.data_contratacao} onChange={(e) => alterar("data_contratacao", e.target.value)} />
      <Campo rotulo="Data do recebimento" type="date" max={hoje} required disabled={cronogramaBloqueado} value={dados.data_recebimento} onChange={(e) => alterar("data_recebimento", e.target.value)} dica="Data em que o dinheiro entrou na conta." />
      <Campo rotulo="Data-base do cálculo" type="date" max={dados.primeiro_vencimento} required disabled={cronogramaBloqueado} value={dados.data_base_calculo} onChange={(e) => alterar("data_base_calculo", e.target.value)} dica="Use a data indicada no contrato. Um dia pode alterar o valor das parcelas." />
      <Campo rotulo="Primeiro vencimento" type="date" min={dados.data_base_calculo} required disabled={cronogramaBloqueado} value={dados.primeiro_vencimento} onChange={(e) => alterar("primeiro_vencimento", e.target.value)} /></div>
    <OpcaoSaldo marcada={afetarSaldo} alterar={cronogramaBloqueado ? () => undefined : definirAfetarSaldo} desabilitada={cronogramaBloqueado} titulo="Somar o valor recebido ao saldo da conta" descricao="Desative ao cadastrar um empréstimo antigo que já está refletido nos extratos importados." />
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{emprestimo ? (cronogramaBloqueado ? "Salvar alterações" : "Salvar e recalcular parcelas") : "Gerar empréstimo e parcelas"}</Botao></div>
  </form>;
}

function AjudaCamposEmprestimo() {
  return <details className="ajuda-campos-emprestimo">
    <summary>Entenda os campos financeiros e as datas</summary>
    <div className="ajuda-campos-conteudo">
      <section><h3>Valores do contrato</h3><dl>
        <div><dt>Valor recebido</dt><dd>Dinheiro que efetivamente entrou na conta.</dd></div>
        <div><dt>Valor financiado</dt><dd>Dívida inicial: valor recebido mais IOF e outros custos financiados.</dd></div>
        <div><dt>IOF financiado</dt><dd>Imposto incorporado à dívida, em vez de pago separadamente.</dd></div>
        <div><dt>Parcela contratada</dt><dd>Prestação oficial informada pelo banco. No PRICE, substitui a parcela estimada.</dd></div>
        <div><dt>Total contratado</dt><dd>Soma oficial prevista de todas as prestações, usada para calcular o custo do crédito.</dd></div>
      </dl></section>
      <section><h3>Taxas e datas</h3><dl>
        <div><dt>Juros</dt><dd>Remuneração do crédito; pode ser informada ao mês e ao ano.</dd></div>
        <div><dt>CET</dt><dd>Custo Efetivo Total, incluindo juros, impostos, tarifas e demais encargos.</dd></div>
        <div><dt>Data da contratação</dt><dd>Dia em que o contrato foi formalizado.</dd></div>
        <div><dt>Data do recebimento</dt><dd>Dia em que o dinheiro entrou na conta.</dd></div>
        <div><dt>Data-base</dt><dd>Data usada pelo banco para iniciar o cálculo financeiro.</dd></div>
        <div><dt>Primeiro vencimento</dt><dd>Data da primeira prestação prevista no contrato.</dd></div>
      </dl></section>
    </div>
  </details>;
}

function DetalhesEmprestimo({ emprestimo, contas, atualizar, excluir }: { emprestimo: Emprestimo; contas: Conta[]; atualizar: () => void; excluir: () => Promise<void> }) {
  const [parcela, selecionarParcela] = useState<ParcelaEmprestimo | null>(null);
  const [associar, definirAssociar] = useState<ParcelaEmprestimo | null>(null);
  const [desfazer, definirDesfazer] = useState<ParcelaEmprestimo | null>(null);
  const [quitacao, definirQuitacao] = useState(false);
  const [aba, definirAba] = useState<"resumo" | "parcelas" | "evolucao" | "custos" | "simulacao">("resumo");
  const [confirmarExclusao, definirConfirmarExclusao] = useState(false);
  const [estadoExclusao, definirEstadoExclusao] = useState({ erro: "", enviando: false });
  async function confirmar() {
    definirEstadoExclusao({ erro: "", enviando: true });
    try { await excluir(); }
    catch (falha) { definirEstadoExclusao({ erro: mensagemErro(falha), enviando: false }); }
  }
  const abas = { resumo: "Resumo", parcelas: "Parcelas", evolucao: "Evolução da dívida", custos: "Custos", simulacao: "Simulação de quitação" };
  return <div className="detalhes-emprestimo">
    <nav className="abas-emprestimo" aria-label="Detalhes do contrato">{Object.entries(abas).map(([id, nome]) => <button key={id} type="button" className={aba === id ? "ativa" : ""} onClick={() => definirAba(id as typeof aba)}>{nome}</button>)}</nav>
    {aba === "resumo" && <>
      <div className="resumo-emprestimo"><div><small>Instituição</small><strong>{emprestimo.credor}</strong></div><div><small>Valor recebido</small><strong>{formatarMoeda(emprestimo.valor_recebido, emprestimo.moeda)}</strong></div><div><small>Valor financiado</small><strong>{formatarMoeda(emprestimo.valor_financiado, emprestimo.moeda)}</strong></div><div><small>Saldo devedor</small><strong>{formatarMoeda(emprestimo.saldo_financiado, emprestimo.moeda)}</strong></div><div><small>Valor pago</small><strong>{formatarMoeda(emprestimo.total_pago, emprestimo.moeda)}</strong></div><div><small>Parcela contratada</small><strong>{emprestimo.valor_parcela_contratada ? formatarMoeda(emprestimo.valor_parcela_contratada, emprestimo.moeda) : "Não informada"}</strong></div></div>
      <div className="progresso-divida"><div><strong>{emprestimo.parcelas_pagas} / {emprestimo.parcelas_acompanhadas} parcelas aplicáveis pagas</strong><span>{emprestimo.percentual_concluido}% concluído</span></div><progress max="100" value={emprestimo.percentual_concluido} /></div>
      <p className="aviso-projecao">{emprestimo.aviso_projecao}</p>
    </>}
    {aba === "parcelas" && <div className="tabela-responsiva"><table className="tabela"><thead><tr><th>Parcela</th><th>Vencimento</th><th>Parcela</th><th>Juros</th><th>Amortização</th><th>Situação</th><th></th></tr></thead><tbody>
      {emprestimo.parcelas?.map((item) => <tr key={item.id}><td><strong>{item.numero}/{emprestimo.quantidade_parcelas}</strong></td><td>{formatarData(item.vencimento)}</td><td className="tabela-valor">{formatarMoeda(item.valor_total, emprestimo.moeda)}</td><td>{formatarMoeda(item.juros, emprestimo.moeda)}</td><td>{formatarMoeda(item.amortizacao, emprestimo.moeda)}</td><td><StatusParcela parcela={item} /></td><td><AcoesParcela parcela={item} pagar={selecionarParcela} associar={definirAssociar} desfazer={definirDesfazer} /></td></tr>)}
    </tbody></table></div>}
    {aba === "evolucao" && <div className="tabela-responsiva"><table className="tabela"><thead><tr><th>Parcela</th><th>Saldo inicial</th><th>Juros</th><th>Amortização</th><th>Saldo final</th></tr></thead><tbody>{emprestimo.parcelas?.map((item) => <tr key={item.id}><td>{item.numero}</td><td>{formatarMoeda(item.saldo_inicial, emprestimo.moeda)}</td><td>{formatarMoeda(item.juros, emprestimo.moeda)}</td><td>{formatarMoeda(item.amortizacao, emprestimo.moeda)}</td><td><strong>{formatarMoeda(item.saldo_final, emprestimo.moeda)}</strong></td></tr>)}</tbody></table></div>}
    {aba === "custos" && <div className="resumo-emprestimo"><div><small>IOF</small><strong>{formatarMoeda(emprestimo.iof, emprestimo.moeda)}</strong></div><div><small>Juros pagos</small><strong>{formatarMoeda(emprestimo.juros_pagos, emprestimo.moeda)}</strong></div><div><small>Juros futuros estimados</small><strong>{formatarMoeda(emprestimo.juros_futuros_estimados, emprestimo.moeda)}</strong></div><div><small>Total previsto</small><strong>{formatarMoeda(emprestimo.total_previsto, emprestimo.moeda)}</strong></div><div><small>Custo total do crédito</small><strong>{formatarMoeda(emprestimo.custo_total_credito, emprestimo.moeda)}</strong></div><div><small>Custo sobre o valor recebido</small><strong>{emprestimo.percentual_custo_sobre_valor_recebido ?? "0"}%</strong></div><div><small>Taxa mensal</small><strong>{emprestimo.taxa_juros_mensal}%</strong></div><div><small>CET mensal</small><strong>{emprestimo.cet_mensal ? `${emprestimo.cet_mensal}%` : "Não informado"}</strong></div><div><small>Data final prevista</small><strong>{emprestimo.ultimo_vencimento ? formatarData(emprestimo.ultimo_vencimento) : "—"}</strong></div></div>}
    {aba === "simulacao" && <SimulacaoQuitacao emprestimo={emprestimo} />}
    {emprestimo.situacao === "ativo" && aba === "simulacao" && <div className="modal-acoes"><Botao variante="secundario" onClick={() => definirQuitacao(true)}>Registrar quitação realizada</Botao></div>}
    {parcela && <FormularioPagamento titulo={`Pagar parcela ${parcela.numero}`} contas={contas.filter((conta) => conta.moeda === emprestimo.moeda)} permitirSemSaldo concluir={() => { selecionarParcela(null); atualizar(); }} cancelar={() => selecionarParcela(null)} enviar={(dados) => api(`financeiro/emprestimos/${emprestimo.id}/parcelas/${parcela.id}/pagamentos`, { method: "POST", body: JSON.stringify(dados) })} />}
    {associar && <AssociacaoTransacao emprestimo={emprestimo} parcela={associar} concluir={() => { definirAssociar(null); atualizar(); }} cancelar={() => definirAssociar(null)} />}
    {desfazer && <ConfirmacaoDesfazerPagamento emprestimo={emprestimo} parcela={desfazer} concluir={() => { definirDesfazer(null); atualizar(); }} cancelar={() => definirDesfazer(null)} />}
    {quitacao && <FormularioQuitacao emprestimo={emprestimo} contas={contas.filter((conta) => conta.moeda === emprestimo.moeda)} concluir={() => { definirQuitacao(false); atualizar(); }} cancelar={() => definirQuitacao(false)} />}
    <div className="zona-perigo-emprestimo">{estadoExclusao.erro && <Aviso>{estadoExclusao.erro}</Aviso>}{confirmarExclusao
      ? <><p>Excluir remove o empréstimo, suas parcelas e as movimentações geradas por ele. Essa ação não pode ser desfeita.</p><div className="modal-acoes"><Botao variante="texto" onClick={() => definirConfirmarExclusao(false)}>Cancelar</Botao><Botao variante="perigo" carregando={estadoExclusao.enviando} onClick={confirmar}>Confirmar exclusão</Botao></div></>
      : <Botao variante="perigo" onClick={() => definirConfirmarExclusao(true)}><Trash2 size={16} /> Excluir empréstimo</Botao>}</div>
  </div>;
}

function AcoesParcela({ parcela, pagar, associar, desfazer }: { parcela: ParcelaEmprestimo; pagar: (parcela: ParcelaEmprestimo) => void; associar: (parcela: ParcelaEmprestimo) => void; desfazer: (parcela: ParcelaEmprestimo) => void }) {
  if (parcela.situacao === "paga") return <button type="button" className="link-acao" onClick={() => desfazer(parcela)}>Desfazer pagamento</button>;
  if (parcela.situacao_exibicao !== "pendente") return null;
  return <div className="acoes-linha"><button type="button" className="link-acao" onClick={() => pagar(parcela)}>Marcar paga</button><button type="button" className="link-acao" onClick={() => associar(parcela)}>Associar movimentação</button></div>;
}

function StatusParcela({ parcela }: { parcela: ParcelaEmprestimo }) {
  const nomes = { pendente: "Pendente", atrasada: "Atrasada", paga: "Paga", quitada_antecipadamente: "Quitada antes", nao_se_aplica: "Não se aplica" };
  return <span className={`etiqueta parcela-${parcela.situacao_exibicao}`}>{nomes[parcela.situacao_exibicao]}</span>;
}

function AssociacaoTransacao({ emprestimo, parcela, concluir, cancelar }: { emprestimo: Emprestimo; parcela: ParcelaEmprestimo; concluir: () => void; cancelar: () => void }) {
  const [transacaoId, definirTransacaoId] = useState("");
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      await api(`financeiro/emprestimos/${emprestimo.id}/parcelas/${parcela.id}/transacao`, { method: "POST", body: JSON.stringify({ transacao_id: transacaoId }) });
      concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="acao-emprestimo" onSubmit={enviar}><h3>Associar movimentação à parcela {parcela.numero}</h3>{estado.erro && <Aviso>{estado.erro}</Aviso>}<p>Informe o identificador UUID da saída já existente no histórico. O valor deve ser exatamente {formatarMoeda(parcela.valor_total, emprestimo.moeda)}.</p><Campo rotulo="UUID da movimentação" type="text" maxLength={36} required value={transacaoId} onChange={(e) => definirTransacaoId(e.target.value)} /><div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando}>Associar e marcar paga</Botao></div></form>;
}

function ConfirmacaoDesfazerPagamento({ emprestimo, parcela, concluir, cancelar }: { emprestimo: Emprestimo; parcela: ParcelaEmprestimo; concluir: () => void; cancelar: () => void }) {
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function confirmar() {
    definirEstado({ erro: "", enviando: true });
    try {
      await api(`financeiro/emprestimos/${emprestimo.id}/parcelas/${parcela.id}/pagamentos`, { method: "DELETE" });
      concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <div className="acao-emprestimo"><h3>Desfazer pagamento da parcela {parcela.numero}?</h3>{estado.erro && <Aviso>{estado.erro}</Aviso>}<p>A parcela voltará a ficar pendente. Movimentações criadas por este pagamento serão removidas; uma movimentação existente apenas associada será preservada.</p><div className="modal-acoes"><Botao variante="texto" onClick={cancelar}>Cancelar</Botao><Botao variante="perigo" carregando={estado.enviando} onClick={confirmar}>Desfazer pagamento</Botao></div></div>;
}

type ResultadoQuitacao = { parcelas_restantes: number; soma_nominal_parcelas_restantes: string; valor_quitacao: string; economia_estimada: string; percentual_economia: string; origem_valor_quitacao: string; aviso: string };

function SimulacaoQuitacao({ emprestimo }: { emprestimo: Emprestimo }) {
  const [valor, definirValor] = useState("");
  const [resultado, definirResultado] = useState<ResultadoQuitacao | null>(null);
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function simular(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      const resposta = await api<ResultadoQuitacao>(`financeiro/emprestimos/${emprestimo.id}/simulacao-quitacao`, { method: "POST", body: JSON.stringify({ valor_quitacao: valor || null }) });
      definirResultado(resposta); definirEstado({ erro: "", enviando: false });
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <div className="simulacao-quitacao"><form onSubmit={simular}><p>Informe o valor oficial fornecido pelo banco. Sem esse valor, o Life Finance mostrará apenas uma estimativa do saldo amortizado.</p>{estado.erro && <Aviso>{estado.erro}</Aviso>}<div className="formulario-duas-colunas"><Campo rotulo="Valor de quitação informado pelo banco (opcional)" type="number" min="0" step="0.01" value={valor} onChange={(e) => definirValor(e.target.value)} /><div className="campo-com-acao"><Botao type="submit" carregando={estado.enviando}>Simular</Botao></div></div></form>{resultado && <><div className="resumo-emprestimo"><div><small>Parcelas restantes</small><strong>{resultado.parcelas_restantes}</strong></div><div><small>Soma nominal restante</small><strong>{formatarMoeda(resultado.soma_nominal_parcelas_restantes, emprestimo.moeda)}</strong></div><div><small>Valor de quitação</small><strong>{formatarMoeda(resultado.valor_quitacao, emprestimo.moeda)}</strong></div><div><small>Economia estimada</small><strong>{formatarMoeda(resultado.economia_estimada, emprestimo.moeda)}</strong></div><div><small>Percentual de economia</small><strong>{resultado.percentual_economia}%</strong></div></div><p className="aviso-projecao">{resultado.aviso}</p></>}</div>;
}

function FormularioPagamento({ titulo, contas, enviar, concluir, cancelar, children, permitirSemSaldo = false }: { titulo: string; contas: Conta[]; enviar: (dados: object) => Promise<unknown>; concluir: () => void; cancelar: () => void; children?: React.ReactNode; permitirSemSaldo?: boolean }) {
  const hoje = dataHojeLocal();
  const [conta, definirConta] = useState(String(contas[0]?.id ?? ""));
  const [data, definirData] = useState(hoje);
  const [afetarSaldo, definirAfetarSaldo] = useState(true);
  const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function confirmar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    const dados = { conta_pagamento_id: afetarSaldo ? conta : null, data_pagamento: data, ...(permitirSemSaldo ? { afetar_saldo: afetarSaldo } : {}) };
    try { await enviar(dados); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="acao-emprestimo" onSubmit={confirmar}><h3>{titulo}</h3>{estado.erro && <Aviso>{estado.erro}</Aviso>}{permitirSemSaldo && <OpcaoSaldo marcada={afetarSaldo} alterar={definirAfetarSaldo} titulo="Descontar esta parcela do saldo" descricao="Desative se o pagamento antigo já consta em um extrato importado." />}<div className="formulario-duas-colunas">{afetarSaldo && <Seletor rotulo="Conta pagadora" value={conta} onChange={(e) => definirConta(e.target.value)}>{contas.map((item) => <option key={item.id} value={item.id}>{item.nome}</option>)}</Seletor>}<Campo rotulo="Data do pagamento" type="date" max={hoje} required value={data} onChange={(e) => definirData(e.target.value)} /></div>{children && <div className="formulario-duas-colunas">{children}</div>}<div className="modal-acoes"><Botao type="button" variante="texto" onClick={cancelar}>Cancelar</Botao><Botao type="submit" carregando={estado.enviando} disabled={afetarSaldo && !conta}>Confirmar pagamento</Botao></div></form>;
}

function OpcaoSaldo({ marcada, alterar, titulo, descricao, desabilitada = false }: { marcada: boolean; alterar: (valor: boolean) => void; titulo: string; descricao: string; desabilitada?: boolean }) {
  return <label className="opcao-saldo"><input type="checkbox" checked={marcada} disabled={desabilitada} onChange={(evento) => alterar(evento.target.checked)} /><span><strong>{titulo}</strong><small>{descricao}</small></span></label>;
}

function FormularioQuitacao({ emprestimo, contas, concluir, cancelar }: { emprestimo: Emprestimo; contas: Conta[]; concluir: () => void; cancelar: () => void }) {
  const [juros, definirJuros] = useState("0.00"); const [taxas, definirTaxas] = useState("0.00");
  return <FormularioPagamento titulo={`Quitar principal de ${formatarMoeda(emprestimo.saldo_devedor, emprestimo.moeda)}`} contas={contas} concluir={concluir} cancelar={cancelar}
    enviar={(dados) => api(`financeiro/emprestimos/${emprestimo.id}/quitacao`, { method: "POST", body: JSON.stringify({ ...dados, juros, taxas }) })}>
    <Campo rotulo="Juros na quitação" value={juros} onChange={(e) => definirJuros(e.target.value)} /><Campo rotulo="Taxas na quitação" value={taxas} onChange={(e) => definirTaxas(e.target.value)} />
  </FormularioPagamento>;
}
