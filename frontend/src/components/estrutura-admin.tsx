"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { BarChart3, ChartNoAxesCombined, CreditCard, LayoutDashboard, LogOut, Menu, ShieldCheck, Users, Webhook, X } from "lucide-react";
import { useAutenticacaoAdmin } from "@/components/autenticacao-admin";
import { Botao } from "@/components/interface";

export function EstruturaAdmin({ children }: { children: React.ReactNode }) {
  const { usuario, carregando, erro, sair } = useAutenticacaoAdmin();
  const caminho = usePathname(); const roteador = useRouter();
  const [menuAberto, definirMenuAberto] = useState(false);
  const entrada = caminho === "/admin/entrar";
  useEffect(() => { if (!entrada && !carregando && !usuario) roteador.replace("/admin/entrar"); }, [entrada, carregando, usuario, roteador]);
  if (entrada) return children;
  if (carregando) return <div className="tela-carregando"><ShieldCheck size={28} /><p>Validando acesso administrativo...</p></div>;
  if (erro) return <div className="tela-carregando"><h2>Não foi possível conectar.</h2><p>{erro}</p></div>;
  if (!usuario) return null;

  const itens = [
    ["/admin", "Visão geral", LayoutDashboard], ["/admin/usuarios", "Usuários", Users],
    ["/admin/assinaturas", "Assinaturas", CreditCard], ["/admin/relatorios", "Relatórios", BarChart3],
    ["/admin/operacoes", "Operações", Webhook],
  ] as const;
  return <div className="admin-estrutura">
    <aside className={`admin-sidebar ${menuAberto ? "aberto" : ""}`}>
      <div className="admin-sidebar-marca"><Link className="marca marca-inversa" href="/admin" onClick={() => definirMenuAberto(false)}><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link><button className="admin-menu-fechar" onClick={() => definirMenuAberto(false)} aria-label="Fechar menu"><X /></button></div>
      <div className="admin-sidebar-contexto"><ShieldCheck size={16} /><span><strong>Backoffice</strong><small>Operação SaaS</small></span></div>
      <nav aria-label="Navegação administrativa">{itens.map(([href, rotulo, Icone]) => <Link key={href} href={href} className={caminho === href ? "ativo" : ""} onClick={() => definirMenuAberto(false)}><Icone size={18} />{rotulo}</Link>)}</nav>
      <div className="admin-sidebar-usuario"><span><strong>{usuario.nome}</strong><small>{usuario.email}</small></span><button onClick={sair} aria-label="Sair"><LogOut size={18} /></button></div>
    </aside>
    {menuAberto && <button className="admin-menu-fundo" onClick={() => definirMenuAberto(false)} aria-label="Fechar menu" />}
    <div className="admin-area"><header className="admin-barra"><button className="admin-menu-botao" onClick={() => definirMenuAberto(true)} aria-label="Abrir menu"><Menu /></button><span className="admin-contexto"><ShieldCheck size={16} /> Administração</span><div className="admin-identidade"><span><strong>{usuario.nome}</strong><small>{usuario.email}</small></span><Botao variante="texto" onClick={sair}><LogOut size={16} /> Sair</Botao></div></header><main className="admin-conteudo">{children}</main></div>
  </div>;
}
