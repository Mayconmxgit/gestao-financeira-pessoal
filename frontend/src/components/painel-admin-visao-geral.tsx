"use client";

import { AlertTriangle, ArrowUpRight, CalendarDays, CircleDollarSign, Gift, Users } from "lucide-react";
import { useState } from "react";
import { useDados } from "@/hooks/use-dados";
import { formatarMoeda } from "@/lib/formatacao";
import type { DashboardAdmin } from "@/lib/tipos";
import { Aviso, Cartao } from "@/components/interface";
import { GraficoCrescimentoAdmin, GraficoPlanosAdmin } from "@/components/admin-graficos";

const hoje = new Date().toISOString().slice(0, 10);
const inicioPadrao = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);

export function PainelAdminVisaoGeral() {
  const [intervalo, definirIntervalo] = useState("30d");
  const [referencia, definirReferencia] = useState(hoje);
  const [de, definirDe] = useState(inicioPadrao); const [ate, definirAte] = useState(hoje);
  const parametros = intervalo === "personalizado" ? `intervalo=personalizado&de=${de}&ate=${ate}` : `intervalo=${intervalo}&referencia=${referencia}`;
  const consulta = useDados<DashboardAdmin>(`admin/dashboard?${parametros}`);
  const dados = consulta.dados;
  return <div className="admin-pagina">
    <CabecalhoAdmin titulo="Visão geral" descricao="Saúde comercial e operacional do produto, sem expor finanças pessoais.">
      <label className="admin-filtro"><span>Período</span><select value={intervalo} onChange={(e) => definirIntervalo(e.target.value)}><option value="7d">7 dias</option><option value="30d">30 dias</option><option value="90d">90 dias</option><option value="12m">12 meses</option><option value="personalizado">Personalizado</option></select></label>
      {intervalo === "personalizado" ? <><label className="admin-filtro"><span>De</span><input type="date" value={de} max={ate} onChange={(e) => definirDe(e.target.value)} /></label><label className="admin-filtro"><span>Até</span><input type="date" value={ate} min={de} max={hoje} onChange={(e) => definirAte(e.target.value)} /></label></> : <label className="admin-filtro"><span>Referência</span><input type="date" value={referencia} max={hoje} onChange={(e) => definirReferencia(e.target.value)} /></label>}
    </CabecalhoAdmin>
    {consulta.erro && <Aviso>{consulta.erro}</Aviso>}
    {consulta.carregando && <EstadoAdmin texto="Carregando indicadores..." />}
    {dados && <>
      {!dados.medicao_desde && <Aviso tipo="sucesso">O histórico exato começa com o primeiro snapshot. Indicadores atuais já estão disponíveis.</Aviso>}
      <div className="admin-kpis">
        <Kpi icone={<CircleDollarSign />} rotulo="MRR contratado" valor={formatarMoeda(dados.resumo.mrr_contratado)} detalhe={`ARR projetado ${formatarMoeda(dados.resumo.arr_projetado)}`} />
        <Kpi icone={<ArrowUpRight />} rotulo="Assinaturas ativas" valor={dados.resumo.assinaturas_ativas} detalhe={`${dados.resumo.assinaturas_pagas} pagas`} />
        <Kpi icone={<Users />} rotulo="Novos usuários" valor={dados.resumo.novos_usuarios} detalhe={`${dados.resumo.usuarios_total} no total`} />
        <Kpi icone={<Gift />} rotulo="Cortesias ativas" valor={dados.resumo.assinaturas_cortesias} detalhe={`${dados.resumo.assinaturas_gratuitas} gratuitas`} />
      </div>
      <div className="admin-grade-principal">
        <Cartao className="admin-card-grafico"><div className="cartao-cabecalho"><div><h2>Evolução do período</h2><p>Novos usuários e mudanças no ciclo das assinaturas.</p></div><CalendarDays size={20} /></div><GraficoCrescimentoAdmin dados={dados.crescimento} /><TabelaCrescimento dados={dados.crescimento} /></Cartao>
        <Cartao className="admin-alertas"><div className="cartao-cabecalho"><div><h2>Alertas operacionais</h2><p>Itens que podem exigir ação.</p></div><AlertTriangle size={20} /></div><Alerta rotulo="Pendências" valor={dados.alertas.assinaturas_pendentes} /><Alerta rotulo="Inadimplências" valor={dados.alertas.assinaturas_inadimplentes} /><Alerta rotulo="Falhas de webhook" valor={dados.alertas.falhas_webhook} /><Taxa rotulo="Conversão" valor={dados.resumo.conversao_percentual} /><Taxa rotulo="Churn" valor={dados.resumo.churn_percentual} /><Taxa rotulo="Falhas de webhook" valor={dados.resumo.falha_webhooks_percentual} /></Cartao>
      </div>
      <Cartao className="admin-card-grafico"><div className="cartao-cabecalho"><div><h2>Distribuição por plano</h2><p>Contratos vigentes e MRR contratado.</p></div></div>{dados.planos.length ? <><GraficoPlanosAdmin dados={dados.planos} /><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Plano</th><th>Assinaturas</th><th>MRR contratado</th></tr></thead><tbody>{dados.planos.map((item) => <tr key={item.plano}><td>{item.plano}</td><td>{item.assinaturas}</td><td>{formatarMoeda(item.mrr_contratado)}</td></tr>)}</tbody></table></div></> : <EstadoAdmin texto="Nenhuma assinatura ativa no período." />}</Cartao>
    </>}
  </div>;
}

export function CabecalhoAdmin({ titulo, descricao, children }: { titulo: string; descricao: string; children?: React.ReactNode }) { return <header className="admin-pagina-cabecalho"><div><span>Backoffice</span><h1>{titulo}</h1><p>{descricao}</p></div>{children && <div className="admin-filtros">{children}</div>}</header>; }
export function EstadoAdmin({ texto }: { texto: string }) { return <div className="admin-estado"><span className="admin-spinner" />{texto}</div>; }
function Kpi({ icone, rotulo, valor, detalhe }: { icone: React.ReactNode; rotulo: string; valor: string | number; detalhe: string }) { return <Cartao className="admin-kpi"><span className="admin-kpi-icone">{icone}</span><span>{rotulo}</span><strong>{valor}</strong><small>{detalhe}</small></Cartao>; }
function Alerta({ rotulo, valor }: { rotulo: string; valor: number }) { return <div className={`admin-alerta ${valor ? "critico" : ""}`}><span>{rotulo}</span><strong>{valor}</strong></div>; }
function Taxa({ rotulo, valor }: { rotulo: string; valor: string | null }) { return <div className="admin-taxa"><span>{rotulo}</span><strong>{valor === null ? "Sem base" : `${valor}%`}</strong></div>; }
function TabelaCrescimento({ dados }: { dados: DashboardAdmin["crescimento"] }) { return <details className="admin-tabela-detalhes"><summary>Ver tabela de dados</summary><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Período</th><th>Novos usuários</th><th>Ativações</th><th>Cancelamentos</th></tr></thead><tbody>{dados.map((p) => <tr key={p.periodo}><td>{p.rotulo}</td><td>{p.novos_usuarios}</td><td>{p.ativacoes}</td><td>{p.cancelamentos}</td></tr>)}</tbody></table></div></details>; }
