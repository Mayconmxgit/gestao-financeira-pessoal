"use client";

import Link from "next/link";
import { ArrowRight, CalendarClock, Target } from "lucide-react";
import { Aviso, Cartao, Vazio } from "@/components/interface";
import { useDados } from "@/hooks/use-dados";
import { dataHojeLocal, formatarData } from "@/lib/formatacao";
import type { MetaFinanceira, PagamentoRecorrente } from "@/lib/tipos";
import { RECURSOS_PLANO, useRecursosPlano } from "@/components/acesso-recursos-plano";
import { ValorMonetario } from "@/components/valor-monetario";

const nomesPeriodicidade = { semanal: "Semanal", mensal: "Mensal", anual: "Anual" } as const;

export function ResumoPlanejamentoDashboard({ valoresOcultos = false }: { valoresOcultos?: boolean }) {
  const { possui } = useRecursosPlano();
  const permiteRecorrentes = possui(RECURSOS_PLANO.PAGAMENTOS_RECORRENTES);
  const permiteMetas = possui(RECURSOS_PLANO.METAS_FINANCEIRAS);
  const recorrentes = useDados<{ dados: PagamentoRecorrente[] }>(permiteRecorrentes ? "financeiro/pagamentos-recorrentes" : null);
  const metas = useDados<{ dados: MetaFinanceira[] }>(permiteMetas ? "financeiro/metas-financeiras" : null);
  const proximos = recorrentes.dados?.dados.filter((item) => item.situacao === "ativo" && item.proximo_vencimento).slice(0, 4) ?? [];
  const metasVisiveis = metas.dados?.dados.slice(0, 4) ?? [];

  if (!permiteRecorrentes && !permiteMetas) return null;

  return <div className="dashboard-duas-colunas dashboard-planejamento">
    {permiteRecorrentes && <Cartao><Cabecalho titulo="Próximos pagamentos" descricao="Valores previstos em reais" />
      {recorrentes.erro ? <Aviso>{recorrentes.erro}</Aviso>
        : proximos.length ? <ListaRecorrencias itens={proximos} valoresOcultos={valoresOcultos} />
          : !recorrentes.carregando && <Vazio titulo="Nenhum pagamento previsto" descricao="Cadastre seus gastos frequentes para acompanhar os próximos vencimentos." />}
    </Cartao>}
    {permiteMetas && <Cartao><Cabecalho titulo="Metas financeiras" descricao="Progresso dos seus objetivos" />
      {metas.erro ? <Aviso>{metas.erro}</Aviso>
        : metasVisiveis.length ? <ListaMetas itens={metasVisiveis} valoresOcultos={valoresOcultos} />
          : !metas.carregando && <Vazio titulo="Nenhuma meta cadastrada" descricao="Crie uma meta para acompanhar seus objetivos financeiros." />}
    </Cartao>}
  </div>;
}

function Cabecalho({ titulo, descricao }: { titulo: string; descricao: string }) {
  return <div className="cartao-cabecalho"><div><h2>{titulo}</h2><p>{descricao}</p></div><Link href="/movimentacoes" className="link-ver-todos">Gerenciar <ArrowRight size={16} /></Link></div>;
}

function ListaRecorrencias({ itens, valoresOcultos }: { itens: PagamentoRecorrente[]; valoresOcultos: boolean }) {
  return <div className="lista-planejamento-dashboard">{itens.map((item) => <div className="planejamento-dashboard-linha" key={item.id}>
    <span className="planejamento-dashboard-icone"><CalendarClock size={18} /></span>
    <div><strong>{item.descricao}</strong><small>{nomesPeriodicidade[item.periodicidade]} · {situacaoVencimento(item.proximo_vencimento)}</small></div>
    <strong><ValorMonetario valor={item.valor} moeda="BRL" oculto={valoresOcultos} /></strong>
  </div>)}</div>;
}

function ListaMetas({ itens, valoresOcultos }: { itens: MetaFinanceira[]; valoresOcultos: boolean }) {
  return <div className="lista-planejamento-dashboard">{itens.map((meta) => {
    const percentual = Math.min(Number(meta.percentual_concluido), 100);
    return <div className="meta-dashboard-linha" key={meta.id}>
      <span className="planejamento-dashboard-icone"><Target size={18} /></span>
      <div><div><strong>{meta.nome}</strong><b>{percentual.toLocaleString("pt-BR", { maximumFractionDigits: 2 })}%</b></div><progress max="100" value={percentual} /><small><ValorMonetario valor={meta.valor_acumulado} moeda="BRL" oculto={valoresOcultos} /> de <ValorMonetario valor={meta.valor_objetivo} moeda="BRL" oculto={valoresOcultos} />{meta.prazo ? ` · até ${formatarData(meta.prazo)}` : ""}</small></div>
    </div>;
  })}</div>;
}

function situacaoVencimento(vencimento: string | null) {
  if (!vencimento) return "Sem próximo vencimento";
  const hoje = dataHojeLocal();
  if (vencimento < hoje) return `Atrasado desde ${formatarData(vencimento)}`;
  if (vencimento === hoje) return "Vence hoje";

  return `Vence em ${formatarData(vencimento)}`;
}
