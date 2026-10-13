"use client";

import {
  Bar, BarChart, CartesianGrid, ComposedChart, Legend, Line, LineChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { formatarMoeda } from "@/lib/formatacao";
import type { RelatorioEvolucao, VariacaoRelatorio } from "@/lib/tipos";

const CORES = ["#2f956b", "#6493ba", "#d79a6a", "#8b74bd", "#c76565"];

type Categoria = RelatorioEvolucao["categorias"][number];
type Conta = RelatorioEvolucao["contas"][number];
type Cartao = RelatorioEvolucao["cartoes"][number];

function formatarValor(valor: unknown, moeda: string, oculto: boolean) {
  return oculto ? "••••" : formatarMoeda(Number(valor), moeda);
}

export function GraficoCategorias({ categorias, periodos, moeda, oculto }: {
  categorias: Categoria[]; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean;
}) {
  const dados = periodos.map((periodo, indice) => Object.fromEntries([
    ["periodo", periodo.rotulo],
    ...categorias.map((categoria) => [categoria.categoria_id, Number(categoria.pontos[indice]?.total ?? 0)]),
  ]));

  return <div className="relatorio-grafico" role="img" aria-label="Evolução dos gastos das categorias selecionadas">
    <ResponsiveContainer width="100%" height="100%"><LineChart data={dados} margin={{ top: 12, right: 18, left: 0, bottom: 4 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="#e8eee9" />
      <XAxis dataKey="periodo" tick={{ fontSize: 11 }} stroke="#8b9c90" />
      <YAxis tick={{ fontSize: 10 }} stroke="#8b9c90" tickFormatter={(valor) => oculto ? "••" : Number(valor).toLocaleString("pt-BR")} />
      <Tooltip formatter={(valor) => formatarValor(valor, moeda, oculto)} />
      <Legend />
      {categorias.map((categoria, indice) => <Line key={categoria.categoria_id} type="monotone" dataKey={categoria.categoria_id} name={categoria.nome} stroke={CORES[indice]} strokeWidth={2.5} dot={{ r: 3 }} />)}
    </LineChart></ResponsiveContainer>
  </div>;
}

export function GraficoConta({ conta, periodos, moeda, oculto }: {
  conta: Conta; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean;
}) {
  const dados = conta.pontos.map((ponto, indice) => ({
    periodo: periodos[indice]?.rotulo ?? ponto.periodo,
    entradas: Number(ponto.entradas), saidas: Number(ponto.saidas), saldo: Number(ponto.saldo_final),
  }));

  return <div className="relatorio-grafico" role="img" aria-label={`Entradas, saídas e saldo da conta ${conta.nome}`}>
    <ResponsiveContainer width="100%" height="100%"><ComposedChart data={dados} margin={{ top: 12, right: 18, left: 0, bottom: 4 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="#e8eee9" />
      <XAxis dataKey="periodo" tick={{ fontSize: 11 }} stroke="#8b9c90" />
      <YAxis tick={{ fontSize: 10 }} stroke="#8b9c90" tickFormatter={(valor) => oculto ? "••" : Number(valor).toLocaleString("pt-BR")} />
      <Tooltip formatter={(valor) => formatarValor(valor, moeda, oculto)} />
      <Legend />
      <Bar dataKey="entradas" name="Entradas" fill="#55b387" radius={[4, 4, 0, 0]} />
      <Bar dataKey="saidas" name="Saídas" fill="#df9870" radius={[4, 4, 0, 0]} />
      <Line type="monotone" dataKey="saldo" name="Saldo final" stroke="#4777a8" strokeWidth={2.5} />
    </ComposedChart></ResponsiveContainer>
  </div>;
}

export function GraficoCartao({ cartao, periodos, moeda, oculto }: {
  cartao: Cartao; periodos: RelatorioEvolucao["periodos"]; moeda: string; oculto: boolean;
}) {
  const dados = cartao.pontos.map((ponto, indice) => ({ periodo: periodos[indice]?.rotulo ?? ponto.periodo, gastos: Number(ponto.gastos) }));

  return <div className="relatorio-grafico" role="img" aria-label={`Evolução dos gastos do cartão ${cartao.nome}`}>
    <ResponsiveContainer width="100%" height="100%"><BarChart data={dados} margin={{ top: 12, right: 18, left: 0, bottom: 4 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="#e8eee9" />
      <XAxis dataKey="periodo" tick={{ fontSize: 11 }} stroke="#8b9c90" />
      <YAxis tick={{ fontSize: 10 }} stroke="#8b9c90" tickFormatter={(valor) => oculto ? "••" : Number(valor).toLocaleString("pt-BR")} />
      <Tooltip formatter={(valor) => formatarValor(valor, moeda, oculto)} />
      <Bar dataKey="gastos" name="Gastos" fill="#d79a6a" radius={[5, 5, 0, 0]} />
    </BarChart></ResponsiveContainer>
  </div>;
}

export function GraficoComparacao({ itens, moeda, oculto, rotuloA, rotuloB, ariaLabel }: {
  itens: Array<{ nome: string; valores: VariacaoRelatorio }>;
  moeda: string; oculto: boolean; rotuloA: string; rotuloB: string; ariaLabel: string;
}) {
  const dados = itens.map((item) => ({ nome: item.nome, a: Number(item.valores.valor_a), b: Number(item.valores.valor_b) }));

  return <div className="relatorio-grafico relatorio-grafico-comparacao" role="img" aria-label={ariaLabel}>
    <ResponsiveContainer width="100%" height="100%"><BarChart data={dados} margin={{ top: 12, right: 18, left: 0, bottom: 30 }}>
      <CartesianGrid strokeDasharray="3 3" stroke="#e8eee9" />
      <XAxis dataKey="nome" tick={{ fontSize: 10 }} stroke="#8b9c90" interval={0} angle={-18} textAnchor="end" />
      <YAxis tick={{ fontSize: 10 }} stroke="#8b9c90" tickFormatter={(valor) => oculto ? "••" : Number(valor).toLocaleString("pt-BR")} />
      <Tooltip formatter={(valor) => formatarValor(valor, moeda, oculto)} />
      <Legend />
      <Bar dataKey="a" name={rotuloA} fill="#9bbfac" radius={[4, 4, 0, 0]} />
      <Bar dataKey="b" name={rotuloB} fill="#2f956b" radius={[4, 4, 0, 0]} />
    </BarChart></ResponsiveContainer>
  </div>;
}
