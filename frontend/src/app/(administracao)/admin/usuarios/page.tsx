"use client";

import { Download, Search } from "lucide-react";
import { useState } from "react";
import { useDados } from "@/hooks/use-dados";
import { formatarData } from "@/lib/formatacao";
import { Aviso, Botao, Cartao } from "@/components/interface";
import { CabecalhoAdmin, EstadoAdmin } from "@/components/painel-admin-visao-geral";

type UsuarioAdmin = { id: string; nome: string; email: string; criado_em: string; assinatura: null | { plano: string; situacao: string; provedor: string; acesso_ate: string | null } };
type Pagina = { dados: UsuarioAdmin[]; proximo_cursor: string | null };

export default function PaginaUsuariosAdmin() {
  const [entrada, definirEntrada] = useState("");
  const [busca, definirBusca] = useState("");
  const [situacao, definirSituacao] = useState("");
  const consulta = useDados<Pagina>(`admin/usuarios?limite=50${busca ? `&busca=${encodeURIComponent(busca)}` : ""}${situacao ? `&situacao=${situacao}` : ""}`);
  const parametrosExportacao = new URLSearchParams({ ...(busca ? { busca } : {}), ...(situacao ? { situacao } : {}) }).toString();
  return <div className="admin-pagina"><CabecalhoAdmin titulo="Usuários" descricao="Cadastro e situação contratual, sem saldos ou movimentações pessoais."><a className="botao botao-secundario" href={`/api/admin/usuarios/exportacao.csv${parametrosExportacao ? `?${parametrosExportacao}` : ""}`}><Download size={16} /> Exportar CSV</a></CabecalhoAdmin>
    <Cartao className="admin-barra-filtros"><form onSubmit={(e) => { e.preventDefault(); definirBusca(entrada.trim()); }}><label><span>Buscar por nome ou e-mail</span><div className="admin-busca"><Search size={16} /><input value={entrada} minLength={2} maxLength={100} onChange={(e) => definirEntrada(e.target.value)} placeholder="Buscar usuário" /></div></label><label><span>Situação</span><select value={situacao} onChange={(e) => definirSituacao(e.target.value)}><option value="">Todas</option><option value="ativa">Ativa</option><option value="pendente">Pendente</option><option value="inadimplente">Inadimplente</option><option value="cancelada">Cancelada</option></select></label><Botao type="submit">Aplicar filtros</Botao></form></Cartao>
    {consulta.erro && <Aviso>{consulta.erro}</Aviso>}{consulta.carregando && <EstadoAdmin texto="Carregando usuários..." />}
    {consulta.dados && <Cartao className="tabela-cartao"><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Usuário</th><th>Cadastro</th><th>Plano</th><th>Situação</th><th>Origem</th></tr></thead><tbody>{consulta.dados.dados.map((usuario) => <tr key={usuario.id}><td><strong>{usuario.nome}</strong><br /><small>{usuario.email}</small></td><td>{formatarData(usuario.criado_em)}</td><td>{usuario.assinatura?.plano ?? "Sem contrato"}</td><td>{usuario.assinatura ? <span className={`situacao situacao-${usuario.assinatura.situacao}`}>{usuario.assinatura.situacao.replaceAll("_", " ")}</span> : "—"}</td><td>{usuario.assinatura?.provedor ?? "—"}</td></tr>)}{!consulta.dados.dados.length && <tr><td colSpan={5}>Nenhum usuário encontrado.</td></tr>}</tbody></table></div></Cartao>}
  </div>;
}
