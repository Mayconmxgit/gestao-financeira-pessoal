"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { ChartNoAxesCombined, LockKeyhole, ShieldCheck } from "lucide-react";
import { useAutenticacaoAdmin } from "@/components/autenticacao-admin";
import { Aviso, Botao, Campo } from "@/components/interface";
import { mensagemErro } from "@/lib/formatacao";

export default function EntradaAdministrativa() {
  const { usuario, carregando, entrar } = useAutenticacaoAdmin();
  const [email, definirEmail] = useState(""); const [senha, definirSenha] = useState("");
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  const roteador = useRouter();
  useEffect(() => { if (!carregando && usuario) roteador.replace("/admin"); }, [carregando, usuario, roteador]);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await entrar(email, senha); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <main className="admin-entrada"><section className="admin-entrada-marca"><span className="marca-simbolo"><ChartNoAxesCombined size={25} /></span><div><span className="sobretitulo">VIDA FINANCEIRA</span><h1>Central administrativa</h1><p>Ambiente reservado para gestão de planos, assinaturas e eventos de pagamento.</p></div><div className="admin-seguranca"><ShieldCheck size={21} /><span><strong>Acesso separado e protegido</strong><small>A sessão administrativa não interfere no acesso pessoal à visão geral.</small></span></div></section><section className="admin-entrada-formulario"><form onSubmit={enviar}><span className="admin-login-icone"><LockKeyhole size={23} /></span><h2>Entrar como administrador</h2><p>Use uma conta com permissão administrativa.</p>{erro && <Aviso>{erro}</Aviso>}<Campo rotulo="E-mail administrativo" type="email" autoComplete="username" required value={email} onChange={(e) => definirEmail(e.target.value)} /><Campo rotulo="Senha" type="password" autoComplete="current-password" required value={senha} onChange={(e) => definirSenha(e.target.value)} /><Botao type="submit" carregando={enviando}>Acessar painel</Botao><a href="/entrar">Voltar ao acesso pessoal</a></form></section></main>;
}
