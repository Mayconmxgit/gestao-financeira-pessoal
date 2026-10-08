"use client";

import { useEffect } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { ChartNoAxesCombined, LogOut, ShieldCheck } from "lucide-react";
import { useAutenticacaoAdmin } from "@/components/autenticacao-admin";
import { Botao } from "@/components/interface";

export function EstruturaAdmin({ children }: { children: React.ReactNode }) {
  const { usuario, carregando, erro, sair } = useAutenticacaoAdmin();
  const caminho = usePathname(); const roteador = useRouter();
  const entrada = caminho === "/admin/entrar";
  useEffect(() => { if (!entrada && !carregando && !usuario) roteador.replace("/admin/entrar"); }, [entrada, carregando, usuario, roteador]);
  if (entrada) return children;
  if (carregando) return <div className="tela-carregando"><ShieldCheck size={28} /><p>Validando acesso administrativo...</p></div>;
  if (erro) return <div className="tela-carregando"><h2>Não foi possível conectar.</h2><p>{erro}</p></div>;
  if (!usuario) return null;

  return <div className="admin-estrutura"><header className="admin-barra"><Link className="marca" href="/admin"><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link><span className="admin-contexto"><ShieldCheck size={16} /> Administração</span><div className="admin-identidade"><span><strong>{usuario.nome}</strong><small>{usuario.email}</small></span><Botao variante="texto" onClick={sair}><LogOut size={16} /> Sair</Botao></div></header><main className="admin-conteudo">{children}</main></div>;
}
