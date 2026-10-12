"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import {
  ArrowDownLeft, ArrowRight, CalendarClock, CreditCard,
  Eye, EyeOff, FileUp, Landmark, TrendingDown, TrendingUp, Wallet,
} from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { Aviso, Botao, CabecalhoPagina, Cartao, Vazio } from "@/components/interface";
import { ResumoPlanejamentoDashboard } from "@/components/resumo-planejamento-dashboard";
import { ValorMonetario } from "@/components/valor-monetario";
import { useDados } from "@/hooks/use-dados";
import { formatarData, intervaloMes, nomeMes } from "@/lib/formatacao";
import type { Conta, Resumo } from "@/lib/tipos";

const COR_GASTOS_CATEGORIA = "#2f956b";

export default function VisaoGeral() {
  const { usuario } = useAutenticacao();
  const [mes, definirMes] = useState(() => intervaloMes().inicio.slice(0, 7));
  const [moedaEscolhida, definirMoeda] = useState("");
  const [valoresOcultos, definirValoresOcultos] = useState(true);
  const data = useMemo(() => new Date(`${mes}-01T12:00:00`), [mes]);
  const periodo = intervaloMes(data);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const moedas = [...new Set(contas.dados?.dados.map((conta) => conta.moeda) ?? [])];
  const moeda = moedas.includes(moedaEscolhida) ? moedaEscolhida : moedas[0] ?? "BRL";
  const resumo = useDados<Resumo>(`financeiro/resumo?inicio=${periodo.inicio}&fim=${periodo.fim}&moeda=${moeda}`);
  const totais = resumo.dados?.consolidado;
  const entradas = Number(totais?.entradas ?? 0);
  const saidas = Number(totais?.saidas ?? 0);
  const gastosPorCategoria = resumo.dados?.despesas_por_categoria ?? [];
  const compromissos = resumo.dados?.compromissos_proximo_mes;
  const faturas = resumo.dados?.faturas_cartao;

  return <div className="pagina-dashboard">
    <CabecalhoPagina titulo={`Olá, ${usuario?.nome.split(" ")[0] ?? "você"} 👋`}
      descricao="Acompanhe o que acontece com seu dinheiro, com clareza."
      acao={<div className="cabecalho-acoes cabecalho-acoes-visao-geral"><Botao type="button" variante="secundario" onClick={() => definirValoresOcultos((atual) => !atual)}>{valoresOcultos ? <Eye size={17} aria-hidden="true" /> : <EyeOff size={17} aria-hidden="true" />}{valoresOcultos ? "Mostrar valores" : "Ocultar valores"}</Botao>{moedas.length > 1 && <label className="filtro-mes"><span>Moeda</span><select value={moeda} onChange={(e) => definirMoeda(e.target.value)} aria-label="Selecionar moeda">{moedas.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>}<label className="filtro-mes"><span>Período</span><input type="month" value={mes} onChange={(e) => definirMes(e.target.value)} aria-label="Selecionar mês" /></label></div>} />
    {resumo.erro && <Aviso>{resumo.erro}</Aviso>}
    {contas.dados?.dados.length === 0 ? <div className="onboarding-destaque"><div><span className="sobretitulo">COMECE POR AQUI</span><h2>Seu espaço financeiro está pronto.</h2><p>Cadastre sua primeira conta e informe o saldo inicial. Depois, importe um extrato para ver seus números ganharem vida.</p><Link href="/contas" className="botao botao-claro">Adicionar minha primeira conta <ArrowRight size={17} /></Link></div><div className="onboarding-arte"><Wallet size={76} strokeWidth={1.2} /></div></div> : null}
    {!!faturas?.itens.length && <AlertasFaturas faturas={faturas} moeda={moeda} valoresOcultos={valoresOcultos} />}
    <div className="metricas-grade">
      <Cartao className="metrica metrica-principal"><div className="metrica-rotulo"><span>Saldo gerencial</span><Wallet size={20} /></div><strong>{resumo.carregando ? "—" : <ValorMonetario valor={totais?.saldo_final ?? "0"} moeda={moeda} oculto={valoresOcultos} />}</strong><p>Posição consolidada em {nomeMes(data)}</p><span className="metrica-marcador">Baseado nos seus lançamentos confirmados</span></Cartao>
      <Cartao className="metrica"><div className="metrica-rotulo"><span>Entradas do período</span><span className="icone-metrica positivo"><TrendingUp size={20} /></span></div><strong>{resumo.carregando ? "—" : <ValorMonetario valor={totais?.entradas ?? "0"} moeda={moeda} oculto={valoresOcultos} />}</strong><p>Inclui lançamentos sem categoria</p></Cartao>
      <Cartao className="metrica"><div className="metrica-rotulo"><span>Saídas do período</span><span className="icone-metrica negativo"><TrendingDown size={20} /></span></div><strong>{resumo.carregando ? "—" : <ValorMonetario valor={totais?.saidas ?? "0"} moeda={moeda} oculto={valoresOcultos} />}</strong><p>{resumo.carregando ? "Calculando participação" : descricaoPercentualDasEntradas(saidas, entradas)}</p></Cartao>
      <Cartao className="metrica"><div className="metrica-rotulo"><span>Gastos previstos</span><span className="icone-metrica neutro"><CalendarClock size={20} /></span></div><strong>{resumo.carregando ? "—" : <ValorMonetario valor={compromissos?.total ?? "0"} moeda={moeda} oculto={valoresOcultos} />}</strong><p>Total previsto para o próximo mês</p></Cartao>
    </div>
    <div className="dashboard-duas-colunas">
      <Cartao className="bloco-grafico"><div className="cartao-cabecalho"><div><h2>Gastos por categoria</h2><p>Participação nas despesas classificadas do período</p></div><Link href="/movimentacoes" className="link-ver-todos">Ver movimentações <ArrowRight size={16} /></Link></div>
        {gastosPorCategoria.length ? <ListaGastosPorCategoria categorias={gastosPorCategoria} moeda={moeda} valoresOcultos={valoresOcultos} />
          : <Vazio titulo="Sem gastos classificados neste mês" descricao="Classifique suas despesas para acompanhar a distribuição por categoria." />}
      </Cartao>
      <div className="dashboard-lateral">
        <Cartao className="bloco-acoes"><div className="cartao-cabecalho"><div><h2>Acesso rápido</h2></div></div><div className="acoes-lista">
          <Link href="/importacoes"><span className="acao-icone acao-verde"><FileUp size={20} /></span><span><strong>Importar extrato</strong><small>OFX, CSV ou PDF do Itaú, com prévia</small></span><ArrowRight size={18} /></Link>
          <Link href="/movimentacoes"><span className="acao-icone acao-azul"><ArrowDownLeft size={20} /></span><span><strong>Registrar movimentação</strong><small>Adicione um complemento ou ajuste</small></span><ArrowRight size={18} /></Link>
          <Link href="/contas"><span className="acao-icone acao-laranja"><Landmark size={20} /></span><span><strong>Gerenciar contas</strong><small>Confira saldos e instituições</small></span><ArrowRight size={18} /></Link>
        </div></Cartao>
        <HistoricoGastosPrevistos compromissos={compromissos} moeda={moeda} carregando={resumo.carregando} valoresOcultos={valoresOcultos} />
      </div>
    </div>
    <ResumoPlanejamentoDashboard valoresOcultos={valoresOcultos} />
    <div className="dashboard-conta-unica">
      <Cartao><div className="cartao-cabecalho"><div><h2>Suas contas</h2><p>Saldo gerencial por conta</p></div><Link href="/contas" className="link-ver-todos">Gerenciar <ArrowRight size={16} /></Link></div>
        {resumo.dados?.contas.length ? <div className="lista-contas-dashboard">{resumo.dados.contas.map((conta) => <div className="conta-resumo" key={conta.conta_id}><span className="conta-resumo-icone"><CreditCard size={19} /></span><div><strong>{conta.nome}</strong><small>{conta.tipo.replaceAll("_", " ")}</small></div><strong><ValorMonetario valor={conta.saldo_final} moeda={conta.moeda} oculto={valoresOcultos} /></strong></div>)}</div>
          : <Vazio titulo="Nenhuma conta cadastrada" descricao="Adicione uma conta para começar a acompanhar seus saldos." acao={{ texto: "Adicionar conta", href: "/contas" }} />}
      </Cartao>
    </div>
  </div>;
}

function AlertasFaturas({ faturas, moeda, valoresOcultos }: {
  faturas: NonNullable<Resumo["faturas_cartao"]>; moeda: string; valoresOcultos: boolean;
}) {
  return <Cartao className="alertas-faturas">
    <div className="cartao-cabecalho"><div><h2>Faturas dos cartões</h2><p>Total atual: <strong><ValorMonetario valor={faturas.total} moeda={moeda} oculto={valoresOcultos} /></strong></p></div><Link href="/contas" className="link-ver-todos">Ver faturas <ArrowRight size={16} /></Link></div>
    <div className="alertas-faturas-lista">{faturas.itens.map((item) => <div key={item.cartao_id} className={`alerta-fatura alerta-fatura-${item.alerta}`}>
      <span className="acao-icone acao-laranja"><CreditCard size={19} /></span>
      <div><strong>{item.cartao_nome}</strong><small>{textoAlertaFatura(item.alerta)} · {item.conta_pagamento_nome}{item.instituicao ? ` (${item.instituicao})` : ""}</small><small>Fechamento {formatarData(item.fechamento)} · vencimento {formatarData(item.vencimento)}</small></div>
      <strong><ValorMonetario valor={item.valor} moeda={moeda} oculto={valoresOcultos} /></strong>
    </div>)}</div>
  </Cartao>;
}

function textoAlertaFatura(alerta: "normal" | "fechamento_proximo" | "fechada" | "vencida") {
  if (alerta === "fechamento_proximo") return "Fechamento próximo";
  if (alerta === "fechada") return "Fatura fechada";
  if (alerta === "vencida") return "Fatura vencida";
  return "Fatura atual";
}

function HistoricoGastosPrevistos({ compromissos, moeda, carregando, valoresOcultos }: {
  compromissos: Resumo["compromissos_proximo_mes"]; moeda: string; carregando: boolean; valoresOcultos: boolean;
}) {
  const itens = compromissos?.itens ?? [];
  return <Cartao className="historico-previstos">
    <div className="cartao-cabecalho"><div><h2>Gastos previstos do próximo mês</h2><p>{compromissos ? `${formatarData(compromissos.inicio)} a ${formatarData(compromissos.fim)}` : "Parcelas, recorrências e lançamentos futuros"}</p></div></div>
    {carregando ? <p className="historico-previstos-vazio">Carregando...</p> : itens.length ? <div className="historico-previstos-lista">
      {itens.map((item, indice) => <div className="historico-previsto-item" key={`${item.tipo}-${item.data}-${indice}`}>
        <span className={`historico-previsto-icone ${item.tipo}`}><CalendarClock size={17} /></span>
        <div><strong>{item.descricao}</strong><small>{formatarData(item.data)}</small></div>
        <strong><ValorMonetario valor={item.valor} moeda={moeda} oculto={valoresOcultos} /></strong>
      </div>)}
    </div> : <p className="historico-previstos-vazio">Nenhum gasto previsto para o próximo mês.</p>}
  </Cartao>;
}

function descricaoPercentualDasEntradas(valor: number, entradas: number) {
  if (entradas <= 0) return "Sem base de entradas no período";

  const percentual = (valor / entradas) * 100;
  return `${percentual.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 2 })}% das entradas`;
}

function ListaGastosPorCategoria({ categorias, moeda, valoresOcultos }: {
  categorias: Resumo["despesas_por_categoria"]; moeda: string; valoresOcultos: boolean;
}) {
  return <div className="lista-gastos-categoria">{categorias.map((categoria) => {
    const percentual = Math.min(Number(categoria.percentual), 100);
    const percentualLimite = Number(categoria.percentual_limite ?? 0);
    const limiteAtingido = percentualLimite >= 100;
    return <div className="gasto-categoria" key={categoria.categoria_id}>
      <div className="gasto-categoria-info"><span className="categoria-ponto" style={{ backgroundColor: COR_GASTOS_CATEGORIA }} /><strong>{categoria.nome}</strong><span><ValorMonetario valor={categoria.total} moeda={moeda} oculto={valoresOcultos} /></span><b>{formatarPercentual(categoria.percentual)}</b></div>
      {categoria.limite_gastos && <div className="gasto-limite">
        <div className="gasto-limite-info"><span>Limite <ValorMonetario valor={categoria.limite_gastos} moeda={moeda} oculto={valoresOcultos} /></span><b className={limiteAtingido ? "ultrapassado" : ""}>{formatarPercentual(categoria.percentual_limite ?? "0")}</b></div>
        <div className={`gasto-limite-barra ${limiteAtingido ? "ultrapassado" : ""}`} role="progressbar" aria-label={`Uso do limite de ${categoria.nome}`} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.min(percentualLimite, 100)}><span style={{ width: `${Math.min(percentualLimite, 100)}%` }} /></div>
      </div>}
      <div className="gasto-categoria-barra" aria-hidden="true"><span style={{ width: `${percentual}%`, backgroundColor: COR_GASTOS_CATEGORIA }} /></div>
    </div>;
  })}</div>;
}

function formatarPercentual(valor: string) {
  return `${Number(valor).toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 2 })}%`;
}
