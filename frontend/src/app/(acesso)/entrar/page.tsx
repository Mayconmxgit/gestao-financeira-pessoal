"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { TelaAcesso } from "@/components/tela-acesso";
import { Aviso, Botao, Campo, CampoSenha } from "@/components/interface";
import { mensagemErro } from "@/lib/formatacao";

export default function Entrar() {
  const { entrar } = useAutenticacao();
  const [email, definirEmail] = useState("");
  const [senha, definirSenha] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await entrar(email, senha); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <TelaAcesso titulo="Bom ter você de volta." subtitulo="Entre para acompanhar sua vida financeira de perto."
    rodape={<>Ainda não tem uma conta? <Link href="/cadastro">Criar conta grátis <ArrowRight size={15} /></Link></>}>
    <form onSubmit={enviar} className="formulario-acesso">
      {erro && <Aviso>{erro}</Aviso>}
      <Campo rotulo="E-mail" type="email" autoComplete="email" placeholder="seu@email.com" required maxLength={254} value={email} onChange={(e) => definirEmail(e.target.value)} />
      <CampoSenha rotulo="Senha" autoComplete="current-password" placeholder="Digite sua senha" required value={senha} onChange={(e) => definirSenha(e.target.value)} />
      <div className="formulario-acesso-auxiliar"><Link href="/recuperar-acesso">Esqueceu a senha?</Link></div>
      <Botao type="submit" carregando={enviando} className="botao-largura">Entrar na minha conta <ArrowRight size={18} /></Botao>
    </form>
  </TelaAcesso>;
}
