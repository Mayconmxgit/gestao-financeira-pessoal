"use client";

import { Download } from "lucide-react";
import { useState } from "react";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Cartao } from "@/components/interface";
import { GraficoCrescimentoAdmin, GraficoSerieAdmin } from "@/components/admin-graficos";
import { CabecalhoAdmin, EstadoAdmin } from "@/components/painel-admin-visao-geral";

type Relatorio = { tipo: string; medicao_desde: string | null; resumo?: Record<string, string | number | null>; serie: Array<Record<string, string | number | null>>; tabela: Array<Record<string, string | number | null>> };
const hoje = new Date().toISOString().slice(0, 10);
const inicioPadrao = new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10);
const rotulos: Record<string, string> = { crescimento: "Crescimento", "receita-recorrente": "Receita recorrente contratada", churn: "Churn", operacoes: "Operações" };

export default function PaginaRelatoriosAdmin() {
  const [tipo, definirTipo] = useState("crescimento"); const [intervalo, definirIntervalo] = useState("30d");
  const [de, definirDe] = useState(inicioPadrao); const [ate, definirAte] = useState(hoje);
  const parametros = intervalo === "personalizado" ? `intervalo=personalizado&de=${de}&ate=${ate}` : `intervalo=${intervalo}&referencia=${hoje}`;
  const consulta = useDados<Relatorio>(`admin/relatorios/${tipo}?${parametros}`);
  const linhas = consulta.dados?.tabela.length ? consulta.dados.tabela : consulta.dados?.serie ?? [];
  const colunas = linhas[0] ? Object.keys(linhas[0]) : [];
  return <div className="admin-pagina"><CabecalhoAdmin titulo="Relatórios" descricao="Indicadores agregados para decisões comerciais e operacionais."><label className="admin-filtro"><span>Período</span><select value={intervalo} onChange={(e) => definirIntervalo(e.target.value)}><option value="7d">7 dias</option><option value="30d">30 dias</option><option value="90d">90 dias</option><option value="12m">12 meses</option><option value="personalizado">Personalizado</option></select></label>{intervalo === "personalizado" && <><label className="admin-filtro"><span>De</span><input type="date" value={de} max={ate} onChange={(e) => definirDe(e.target.value)} /></label><label className="admin-filtro"><span>Até</span><input type="date" value={ate} min={de} max={hoje} onChange={(e) => definirAte(e.target.value)} /></label></>}<a className="botao botao-secundario" href={`/api/admin/relatorios/${tipo}/exportacao.csv?${parametros}`}><Download size={16} /> Exportar CSV</a></CabecalhoAdmin>
    <div className="admin-abas" role="tablist" aria-label="Tipos de relatório">{Object.entries(rotulos).map(([id, nome]) => <button role="tab" aria-selected={tipo === id} className={tipo === id ? "ativo" : ""} onClick={() => definirTipo(id)} key={id}>{nome}</button>)}</div>
    {consulta.erro && <Aviso>{consulta.erro}</Aviso>}{consulta.carregando && <EstadoAdmin texto="Gerando relatório..." />}
    {consulta.dados && <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>{rotulos[tipo]}</h2><p>{consulta.dados.medicao_desde ? `Histórico exato desde ${consulta.dados.medicao_desde}.` : "Histórico ainda indisponível; a medição começa no primeiro snapshot."}</p></div></div>{tipo === "crescimento" && consulta.dados.serie.length > 0 && <GraficoCrescimentoAdmin dados={consulta.dados.serie as never} />}{tipo === "receita-recorrente" && consulta.dados.serie.length > 0 && <GraficoSerieAdmin dados={consulta.dados.serie} campo="mrr_contratado" nome="MRR contratado" />}{tipo === "churn" && consulta.dados.serie.length > 0 && <GraficoSerieAdmin dados={consulta.dados.serie} campo="churn_percentual" nome="Churn (%)" />}{linhas.length ? <div className="tabela-rolagem"><table className="tabela"><thead><tr>{colunas.map((coluna) => <th key={coluna}>{coluna.replaceAll("_", " ")}</th>)}</tr></thead><tbody>{linhas.map((linha, indice) => <tr key={indice}>{colunas.map((coluna) => <td key={coluna}>{linha[coluna] ?? "Sem base"}</td>)}</tr>)}</tbody></table></div> : <EstadoAdmin texto="Não há dados confiáveis para este relatório no período." />}</Cartao>}
  </div>;
}
