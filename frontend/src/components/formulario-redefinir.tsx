"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { api } from "@/lib/api";
import { mensagemErro } from "@/lib/formatacao";
import { TelaAcesso } from "@/components/tela-acesso";
import { Aviso, Botao, Campo } from "@/components/interface";
import { MENSAGEM_POLITICA_SENHA, senhaAtendePolitica } from "@/lib/politica-senha";

export function FormularioRedefinir({ email, token }: { email: string; token: string }) {
  const [senha, definirSenha] = useState("");
  const [confirmacao, definirConfirmacao] = useState("");
  const [erro, definirErro] = useState("");
  const [sucesso, definirSucesso] = useState(false);
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault();
    if (!senhaAtendePolitica(senha)) { definirErro(MENSAGEM_POLITICA_SENHA); return; }
    if (senha !== confirmacao) { definirErro("As senhas não são iguais."); return; }
    definirErro(""); definirEnviando(true);
    try { await api("auth/redefinir", { method: "POST", body: JSON.stringify({ email, token, senha }) }); definirSucesso(true); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <TelaAcesso titulo="Defina uma nova senha." subtitulo="Escolha uma senha forte para proteger seus dados."
    rodape={<Link href="/entrar"><ArrowLeft size={15} /> Voltar para entrar</Link>}>
    {!email || !token ? <Aviso>Link inválido. Solicite uma nova recuperação de acesso.</Aviso> : sucesso
      ? <div className="formulario-acesso"><Aviso tipo="sucesso">Senha alterada com sucesso.</Aviso><Link className="botao botao-primario botao-largura" href="/entrar">Entrar <ArrowRight size={18} /></Link></div>
      : <form onSubmit={enviar} className="formulario-acesso">
        {erro && <Aviso>{erro}</Aviso>}
        <Campo rotulo="Nova senha" type="password" autoComplete="new-password" placeholder="8+ caracteres, maiúscula, minúscula e especial" minLength={8} maxLength={128} required value={senha} onChange={(e) => definirSenha(e.target.value)} />
        <Campo rotulo="Confirme a senha" type="password" autoComplete="new-password" minLength={8} maxLength={128} required value={confirmacao} onChange={(e) => definirConfirmacao(e.target.value)} />
        <Botao type="submit" carregando={enviando} className="botao-largura">Salvar nova senha <ArrowRight size={18} /></Botao>
      </form>}
  </TelaAcesso>;
}
