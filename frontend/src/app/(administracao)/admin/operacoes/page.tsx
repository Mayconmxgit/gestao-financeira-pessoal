"use client";

import { RefreshCw } from "lucide-react";
import { useDados } from "@/hooks/use-dados";
import { formatarData } from "@/lib/formatacao";
import { Aviso, Botao, Cartao } from "@/components/interface";
import { CabecalhoAdmin, EstadoAdmin } from "@/components/painel-admin-visao-geral";

type Operacoes = {
  webhooks: Array<{ id: string; tipo: string; acao: string | null; situacao: string; codigo_erro: string | null; recebido_em: string }>;
  auditoria: Array<{ acao: string; entidade: string; entidade_id: string; criado_em: string; administrador: string }>;
};

export default function PaginaOperacoesAdmin() {
  const consulta = useDados<Operacoes>("admin/operacoes?limite=50");
  return <div className="admin-pagina"><CabecalhoAdmin titulo="Operações" descricao="Webhooks, falhas de integração, reprocessamentos e auditoria."><Botao variante="secundario" onClick={consulta.recarregar}><RefreshCw size={16} /> Atualizar</Botao></CabecalhoAdmin>
    {consulta.erro && <Aviso>{consulta.erro}</Aviso>}{consulta.carregando && <EstadoAdmin texto="Carregando eventos operacionais..." />}
    {consulta.dados && <div className="admin-grade-operacoes"><Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Webhooks</h2><p>Status técnico sem payloads sensíveis.</p></div></div><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Recebido</th><th>Tipo</th><th>Ação</th><th>Situação</th><th>Erro</th></tr></thead><tbody>{consulta.dados.webhooks.map((item) => <tr key={item.id}><td>{formatarData(item.recebido_em)}</td><td>{item.tipo}</td><td>{item.acao ?? "—"}</td><td><span className={`situacao situacao-${item.situacao}`}>{item.situacao}</span></td><td>{item.codigo_erro ?? "—"}</td></tr>)}{!consulta.dados.webhooks.length && <tr><td colSpan={5}>Nenhum webhook registrado.</td></tr>}</tbody></table></div></Cartao>
      <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Auditoria</h2><p>Ações administrativas rastreáveis.</p></div></div><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Data</th><th>Administrador</th><th>Ação</th><th>Entidade</th></tr></thead><tbody>{consulta.dados.auditoria.map((item, indice) => <tr key={`${item.entidade_id}-${indice}`}><td>{formatarData(item.criado_em)}</td><td>{item.administrador}</td><td>{item.acao.replaceAll("_", " ")}</td><td>{item.entidade}</td></tr>)}{!consulta.dados.auditoria.length && <tr><td colSpan={4}>Nenhuma ação auditada.</td></tr>}</tbody></table></div></Cartao></div>}
  </div>;
}
