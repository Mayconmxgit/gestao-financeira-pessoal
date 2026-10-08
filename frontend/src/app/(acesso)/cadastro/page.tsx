"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { ArrowRight } from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { TelaAcesso } from "@/components/tela-acesso";
import { Aviso, Botao, Campo, CampoSenha } from "@/components/interface";
import { mensagemErro } from "@/lib/formatacao";
import { MENSAGEM_POLITICA_SENHA, senhaAtendePolitica } from "@/lib/politica-senha";

export default function Cadastro() {
  const { cadastrar } = useAutenticacao();
  const [nome, definirNome] = useState("");
  const [email, definirEmail] = useState("");
  const [senha, definirSenha] = useState("");
  const [confirmacao, definirConfirmacao] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault();
    if (!senhaAtendePolitica(senha)) { definirErro(MENSAGEM_POLITICA_SENHA); return; }
    if (senha !== confirmacao) { definirErro("As senhas não são iguais."); return; }
    definirErro(""); definirEnviando(true);
    try { await cadastrar(nome, email, senha); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <TelaAcesso titulo="Comece com clareza." subtitulo="Crie sua conta e dê o primeiro passo para organizar seu dinheiro."
    rodape={<>Já tem uma conta? <Link href="/entrar">Entrar <ArrowRight size={15} /></Link></>}>
    <form onSubmit={enviar} className="formulario-acesso">
      {erro && <Aviso>{erro}</Aviso>}
      <Campo rotulo="Seu nome" autoComplete="name" placeholder="Como podemos chamar você?" required minLength={2} maxLength={120} value={nome} onChange={(e) => definirNome(e.target.value)} />
      <Campo rotulo="E-mail" type="email" autoComplete="email" placeholder="seu@email.com" required maxLength={254} value={email} onChange={(e) => definirEmail(e.target.value)} />
      <CampoSenha rotulo="Senha" autoComplete="new-password" placeholder="8+ caracteres, maiúscula, minúscula e especial" required minLength={8} maxLength={128} value={senha} onChange={(e) => definirSenha(e.target.value)} />
      <CampoSenha rotulo="Confirme a senha" autoComplete="new-password" placeholder="Digite novamente" required minLength={8} maxLength={128} value={confirmacao} onChange={(e) => definirConfirmacao(e.target.value)} />
      <Botao type="submit" carregando={enviando} className="botao-largura">Criar minha conta <ArrowRight size={18} /></Botao>
      <p className="formulario-nota">Seus dados financeiros pertencem a você e permanecem sob seu controle.</p>
    </form>
  </TelaAcesso>;
}
