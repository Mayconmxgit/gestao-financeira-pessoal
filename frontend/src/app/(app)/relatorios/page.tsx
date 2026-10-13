"use client";

import { useMemo, useState } from "react";
import { BarChart3, Eye, EyeOff } from "lucide-react";
import {
  GraficoCartao, GraficoCategorias, GraficoComparacao, GraficoConta,
} from "@/components/relatorios-graficos";
import { Aviso, Botao, CabecalhoPagina, Cartao, Vazio } from "@/components/interface";
import { ValorMonetario } from "@/components/valor-monetario";
import { useDados } from "@/hooks/use-dados";
import { dataHojeLocal, nomeMes } from "@/lib/formatacao";
import type { Conta, RelatorioComparacao, RelatorioEvolucao, VariacaoRelatorio } from "@/lib/tipos";

type Modo = "evolucao" | "comparacao";
type Intervalo = "30d" | "6m" | "12m";

export default function Relatorios() {
  const hoje = useMemo(() => dataHojeLocal(), []);
  const [modo, definirModo] = useState<Modo>("evolucao");
  const [intervalo, definirIntervalo] = useState<Intervalo>("6m");
  const [referencia, definirReferencia] = useState(hoje);
  const [mesB, definirMesB] = useState(hoje.slice(0, 7));
  const [mesA, definirMesA] = useState(() => mesAnterior(hoje));
  const [moedaEscolhida, definirMoeda] = useState("");
  const [valoresOcultos, definirValoresOcultos] = useState(true);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const moedas = [...new Set(contas.dados?.dados.map((conta) => conta.moeda) ?? [])];
  const moeda = moedas.includes(moedaEscolhida) ? moedaEscolhida : moedas[0] ?? "BRL";
  const evolucao = useDados<RelatorioEvolucao>(modo === "evolucao" ? `financeiro/relatorios/evolucao?intervalo=${intervalo}&referencia=${referencia}&moeda=${moeda}` : null);
  const comparacao = useDados<RelatorioComparacao>(modo === "comparacao" ? `financeiro/relatorios/comparacao-mensal?mes_a=${mesA}&mes_b=${mesB}&moeda=${moeda}` : null);

  return <div className="pagina-relatorios">
    <CabecalhoPagina titulo="Relatórios financeiros" descricao="Acompanhe a evolução dos gastos por categoria, conta e cartão."
      acao={<div className="cabecalho-acoes"><Botao type="button" variante="secundario" onClick={() => definirValoresOcultos((atual) => !atual)}>{valoresOcultos ? <Eye size={17} /> : <EyeOff size={17} />}{valoresOcultos ? "Mostrar valores" : "Ocultar valores"}</Botao></div>} />
    <div className="abas relatorios-abas" role="tablist" aria-label="Modo do relatório">
      <button type="button" role="tab" aria-selected={modo === "evolucao"} className={modo === "evolucao" ? "ativa" : ""} onClick={() => definirModo("evolucao")}>Evolução</button>
      <button type="button" role="tab" aria-selected={modo === "comparacao"} className={modo === "comparacao" ? "ativa" : ""} onClick={() => definirModo("comparacao")}>Comparar meses</button>
    </div>
    <Cartao className="relatorios-filtros">
      <div className="filtros">
        {moedas.length > 1 && <label><span>Moeda</span><select value={moeda} onChange={(evento) => definirMoeda(evento.target.value)}>{moedas.map((item) => <option key={item}>{item}</option>)}</select></label>}
        {modo === "evolucao" ? <>
          <label><span>Período</span><select value={intervalo} onChange={(evento) => definirIntervalo(evento.target.value as Intervalo)}><option value="30d">Últimos 30 dias</option><option value="6m">Últimos 6 meses</option><option value="12m">Últimos 12 meses</option></select></label>
          <label><span>Data de referência</span><input type="date" value={referencia} onChange={(evento) => definirReferencia(evento.target.value)} /></label>
        </> : <>
          <label><span>Mês A</span><input type="month" value={mesA} onChange={(evento) => definirMesA(evento.target.value)} /></label>
          <label><span>Mês B</span><input type="month" value={mesB} onChange={(evento) => definirMesB(evento.target.value)} /></label>
        </>}
      </div>
    </Cartao>
    {modo === "evolucao" ? <PainelEvolucao key={moeda} relatorio={evolucao.dados} carregando={evolucao.carregando} erro={evolucao.erro} moeda={moeda} valoresOcultos={valoresOcultos} />
      : <PainelComparacao key={moeda} relatorio={comparacao.dados} carregando={comparacao.carregando} erro={comparacao.erro} moeda={moeda} valoresOcultos={valoresOcultos} />}
  </div>;
}

function PainelEvolucao({ relatorio, carregando, erro, moeda, valoresOcultos }: {
  relatorio: RelatorioEvolucao | null; carregando: boolean; erro: string | null; moeda: string; valoresOcultos: boolean;
}) {
  const [categoriasPreferidas, selecionarCategorias] = useState<string[] | null>(null);
  const [contaPreferida, selecionarConta] = useState("");
  const [cartaoPreferido, selecionarCartao] = useState("");

  if (erro) return <Aviso>{erro}</Aviso>;
  if (carregando) return <Cartao><p className="relatorio-carregando">Preparando os gráficos...</p></Cartao>;
  if (!relatorio) return null;
  const categorias = relatorio.categorias;
  const contas = relatorio.contas;
  const cartoes = relatorio.cartoes;
  const categoriasSelecionadas = (categoriasPreferidas ?? categorias.slice(0, 5).map((categoria) => categoria.categoria_id))
    .filter((id) => categorias.some((categoria) => categoria.categoria_id === id));
  const contaSelecionada = contas.some((conta) => conta.conta_id === contaPreferida) ? contaPreferida : contas[0]?.conta_id ?? "";
  const cartaoSelecionado = cartoes.some((cartao) => cartao.conta_id === cartaoPreferido) ? cartaoPreferido : cartoes[0]?.conta_id ?? "";
  const categoriasAtivas = categorias.filter((categoria) => categoriasSelecionadas.includes(categoria.categoria_id));
  const conta = contas.find((item) => item.conta_id === contaSelecionada);
  const cartao = cartoes.find((item) => item.conta_id === cartaoSelecionado);

  return <div className="relatorios-conteudo">
    <div className="relatorios-metricas">
      <Metrica rotulo="Categorias com gastos" valor={String(categorias.length)} texto />
      <Metrica rotulo="Gastos sem categoria" valor={relatorio.nao_categorizado} moeda={moeda} oculto={valoresOcultos} />
      <Metrica rotulo="Contas analisadas" valor={String(contas.length)} texto />
      <Metrica rotulo="Cartões analisados" valor={String(cartoes.length)} texto />
    </div>
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Evolução dos gastos por categoria" descricao="Selecione até cinco categorias para comparar a evolução." />
      {categorias.length ? <>
        <div className="relatorio-seletor-categorias">{categorias.map((categoria) => {
          const marcada = categoriasSelecionadas.includes(categoria.categoria_id);
          const bloqueada = !marcada && categoriasSelecionadas.length >= 5;
          return <label key={categoria.categoria_id} className={bloqueada ? "desabilitado" : ""}><input type="checkbox" checked={marcada} disabled={bloqueada} onChange={() => selecionarCategorias(marcada ? categoriasSelecionadas.filter((id) => id !== categoria.categoria_id) : [...categoriasSelecionadas, categoria.categoria_id])} />{categoria.nome}</label>;
        })}</div>
        {categoriasAtivas.length ? <><GraficoCategorias categorias={categoriasAtivas} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /><TabelaCategorias categorias={categoriasAtivas} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Selecione uma categoria" descricao="Marque ao menos uma categoria para montar o gráfico." />}
      </> : <Vazio titulo="Sem gastos categorizados" descricao="Classifique suas despesas para acompanhar sua evolução." />}
    </Cartao>
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Desempenho por conta" descricao="Entradas, saídas e saldo das contas bancárias, dinheiro e poupança." />
      {contas.length && conta ? <><SeletorEntidade rotulo="Conta" valor={contaSelecionada} definir={selecionarConta} itens={contas} /><GraficoConta conta={conta} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /><TabelaConta conta={conta} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Nenhuma conta disponível" descricao="Cadastre uma conta para acompanhar o desempenho." />}
    </Cartao>
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Gastos por cartão" descricao="Cartões ficam separados das contas para não simular saldo bancário." />
      {cartoes.length && cartao ? <><SeletorEntidade rotulo="Cartão" valor={cartaoSelecionado} definir={selecionarCartao} itens={cartoes} /><GraficoCartao cartao={cartao} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /><TabelaCartao cartao={cartao} periodos={relatorio.periodos} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Nenhum cartão disponível" descricao="Cartões cadastrados aparecerão aqui separadamente." />}
    </Cartao>
  </div>;
}

function PainelComparacao({ relatorio, carregando, erro, moeda, valoresOcultos }: {
  relatorio: RelatorioComparacao | null; carregando: boolean; erro: string | null; moeda: string; valoresOcultos: boolean;
}) {
  if (erro) return <Aviso>{erro}</Aviso>;
  if (carregando) return <Cartao><p className="relatorio-carregando">Comparando os meses...</p></Cartao>;
  if (!relatorio) return null;
  const rotuloA = rotuloMes(relatorio.mes_a); const rotuloB = rotuloMes(relatorio.mes_b);
  const categorias = relatorio.categorias.slice(0, 5).map((item) => ({ nome: item.nome, valores: item }));
  const contas = relatorio.contas.map((item) => ({ nome: item.nome, valores: item.saidas }));
  const cartoes = relatorio.cartoes.map((item) => ({ nome: item.nome, valores: item.gastos }));

  return <div className="relatorios-conteudo">
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Categorias" descricao="As cinco maiores categorias pela soma dos dois meses; a tabela mantém todas." />
      {categorias.length ? <><GraficoComparacao itens={categorias} moeda={moeda} oculto={valoresOcultos} rotuloA={rotuloA} rotuloB={rotuloB} ariaLabel="Comparação de gastos por categoria" /><TabelaComparacao itens={relatorio.categorias.map((item) => ({ nome: item.nome, valores: item }))} rotuloA={rotuloA} rotuloB={rotuloB} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Sem categorias para comparar" descricao="Não há gastos categorizados nos meses selecionados." />}
    </Cartao>
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Saídas por conta" descricao="Compare quanto saiu de cada conta e consulte as demais métricas na tabela." />
      {contas.length ? <><GraficoComparacao itens={contas} moeda={moeda} oculto={valoresOcultos} rotuloA={rotuloA} rotuloB={rotuloB} ariaLabel="Comparação de saídas por conta" /><TabelaContasComparacao contas={relatorio.contas} rotuloA={rotuloA} rotuloB={rotuloB} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Sem contas para comparar" descricao="Cadastre uma conta para acompanhar o desempenho." />}
    </Cartao>
    <Cartao className="relatorio-secao">
      <TituloSecao titulo="Gastos por cartão" descricao="Comparação separada dos gastos lançados em cartões de crédito." />
      {cartoes.length ? <><GraficoComparacao itens={cartoes} moeda={moeda} oculto={valoresOcultos} rotuloA={rotuloA} rotuloB={rotuloB} ariaLabel="Comparação de gastos por cartão" /><TabelaComparacao itens={cartoes} rotuloA={rotuloA} rotuloB={rotuloB} moeda={moeda} oculto={valoresOcultos} /></> : <Vazio titulo="Sem cartões para comparar" descricao="Não há cartões cadastrados nessa moeda." />}
    </Cartao>
  </div>;
}

function Metrica({ rotulo, valor, moeda = "BRL", oculto = false, texto = false }: { rotulo: string; valor: string; moeda?: string; oculto?: boolean; texto?: boolean }) {
  return <Cartao className="relatorio-metrica"><span>{rotulo}</span><strong>{texto ? valor : <ValorMonetario valor={valor} moeda={moeda} oculto={oculto} />}</strong></Cartao>;
}

function TituloSecao({ titulo, descricao }: { titulo: string; descricao: string }) {
  return <div className="cartao-cabecalho"><div><h2>{titulo}</h2><p>{descricao}</p></div><BarChart3 size={21} aria-hidden="true" /></div>;
}

function SeletorEntidade({ rotulo, valor, definir, itens }: { rotulo: string; valor: string; definir: (valor: string) => void; itens: Array<{ conta_id: string; nome: string; situacao: string }> }) {
  return <label className="relatorio-seletor"><span>{rotulo}</span><select value={valor} onChange={(evento) => definir(evento.target.value)}>{itens.map((item) => <option key={item.conta_id} value={item.conta_id}>{item.nome}{item.situacao === "arquivada" ? " (arquivada)" : ""}</option>)}</select></label>;
}

function TabelaCategorias({ categorias, periodos, moeda, oculto }: { categorias: RelatorioEvolucao["categorias"]; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean }) {
  return <TabelaRolagem><table className="tabela"><thead><tr><th>Categoria</th>{periodos.map((periodo) => <th key={periodo.chave}>{periodo.rotulo}</th>)}<th>Total</th></tr></thead><tbody>{categorias.map((categoria) => <tr key={categoria.categoria_id}><td><strong>{categoria.nome}</strong></td>{categoria.pontos.map((ponto) => <td key={ponto.periodo}><ValorMonetario valor={ponto.total} moeda={moeda} oculto={oculto} /></td>)}<td><strong><ValorMonetario valor={categoria.total} moeda={moeda} oculto={oculto} /></strong></td></tr>)}</tbody></table></TabelaRolagem>;
}

function TabelaConta({ conta, periodos, moeda, oculto }: { conta: RelatorioEvolucao["contas"][number]; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean }) {
  return <TabelaRolagem><table className="tabela"><thead><tr><th>Período</th><th>Entradas</th><th>Saídas</th><th>Resultado</th><th>Saldo final</th></tr></thead><tbody>{conta.pontos.map((ponto, indice) => <tr key={ponto.periodo}><td><strong>{periodos[indice]?.rotulo}</strong></td>{[ponto.entradas, ponto.saidas, ponto.resultado_liquido, ponto.saldo_final].map((valor, i) => <td key={i}><ValorMonetario valor={valor} moeda={moeda} oculto={oculto} /></td>)}</tr>)}</tbody></table></TabelaRolagem>;
}

function TabelaCartao({ cartao, periodos, moeda, oculto }: { cartao: RelatorioEvolucao["cartoes"][number]; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean }) {
  return <TabelaRolagem><table className="tabela"><thead><tr><th>Período</th><th>Gastos</th></tr></thead><tbody>{cartao.pontos.map((ponto, indice) => <tr key={ponto.periodo}><td><strong>{periodos[indice]?.rotulo}</strong></td><td><ValorMonetario valor={ponto.gastos} moeda={moeda} oculto={oculto} /></td></tr>)}</tbody></table></TabelaRolagem>;
}

function TabelaComparacao({ itens, rotuloA, rotuloB, moeda, oculto }: { itens: Array<{ nome: string; valores: VariacaoRelatorio }>; rotuloA: string; rotuloB: string; moeda: string; oculto: boolean }) {
  return <TabelaRolagem><table className="tabela"><thead><tr><th>Item</th><th>{rotuloA}</th><th>{rotuloB}</th><th>Diferença</th><th>Variação</th></tr></thead><tbody>{itens.map((item) => <tr key={item.nome}><td><strong>{item.nome}</strong></td><CelulaMoeda valor={item.valores.valor_a} moeda={moeda} oculto={oculto} /><CelulaMoeda valor={item.valores.valor_b} moeda={moeda} oculto={oculto} /><CelulaMoeda valor={item.valores.variacao_valor} moeda={moeda} oculto={oculto} /><td>{oculto ? "••••" : formatarVariacao(item.valores.variacao_percentual)}</td></tr>)}</tbody></table></TabelaRolagem>;
}

function TabelaContasComparacao({ contas, rotuloA, rotuloB, moeda, oculto }: { contas: RelatorioComparacao["contas"]; rotuloA: string; rotuloB: string; moeda: string; oculto: boolean }) {
  return <TabelaRolagem><table className="tabela"><thead><tr><th>Conta / métrica</th><th>{rotuloA}</th><th>{rotuloB}</th><th>Diferença</th><th>Variação</th></tr></thead><tbody>{contas.flatMap((conta) => (["entradas", "saidas", "resultado", "saldo_final"] as const).map((metrica) => {
    const valores = conta[metrica];
    return <tr key={`${conta.conta_id}-${metrica}`}><td><strong>{conta.nome}</strong><small className="relatorio-metrica-nome">{rotuloMetrica(metrica)}</small></td><CelulaMoeda valor={valores.valor_a} moeda={moeda} oculto={oculto} /><CelulaMoeda valor={valores.valor_b} moeda={moeda} oculto={oculto} /><CelulaMoeda valor={valores.variacao_valor} moeda={moeda} oculto={oculto} /><td>{oculto ? "••••" : formatarVariacao(valores.variacao_percentual)}</td></tr>;
  }))}</tbody></table></TabelaRolagem>;
}

function TabelaRolagem({ children }: { children: React.ReactNode }) { return <div className="tabela-rolagem relatorio-tabela">{children}</div>; }
function CelulaMoeda({ valor, moeda, oculto }: { valor: string; moeda: string; oculto: boolean }) { return <td><ValorMonetario valor={valor} moeda={moeda} oculto={oculto} /></td>; }
function formatarVariacao(valor: string | null) { return valor === null ? "Sem base" : `${Number(valor).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`; }
function rotuloMetrica(metrica: "entradas" | "saidas" | "resultado" | "saldo_final") { return ({ entradas: "Entradas", saidas: "Saídas", resultado: "Resultado líquido", saldo_final: "Saldo final" })[metrica]; }
function mesAnterior(data: string) { const atual = new Date(`${data.slice(0, 7)}-01T12:00:00`); atual.setMonth(atual.getMonth() - 1); return `${atual.getFullYear()}-${String(atual.getMonth() + 1).padStart(2, "0")}`; }
function rotuloMes(mes: string) { return nomeMes(new Date(`${mes}-01T12:00:00`)); }
