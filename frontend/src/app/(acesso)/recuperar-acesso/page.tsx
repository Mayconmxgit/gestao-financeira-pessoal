"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { ArrowLeft, ArrowRight } from "lucide-react";
import { api } from "@/lib/api";
import { mensagemErro } from "@/lib/formatacao";
import { TelaAcesso } from "@/components/tela-acesso";
import { Aviso, Botao, Campo } from "@/components/interface";

export default function RecuperarAcesso() {
  const [email, definirEmail] = useState("");
  const [erro, definirErro] = useState("");
  const [sucesso, definirSucesso] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirSucesso(""); definirEnviando(true);
    try {
      const resposta = await api<{ mensagem: string }>("auth/recuperar", { method: "POST", body: JSON.stringify({ email }) });
      definirSucesso(resposta.mensagem);
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <TelaAcesso titulo="Vamos recuperar seu acesso." subtitulo="Informe seu e-mail para receber as instruções."
    rodape={<Link href="/entrar"><ArrowLeft size={15} /> Voltar para entrar</Link>}>
    <form onSubmit={enviar} className="formulario-acesso">
      {erro && <Aviso>{erro}</Aviso>}{sucesso && <Aviso tipo="sucesso">{sucesso}</Aviso>}
      <Campo rotulo="E-mail da conta" type="email" autoComplete="email" placeholder="seu@email.com" required value={email} onChange={(e) => definirEmail(e.target.value)} />
      <Botao type="submit" carregando={enviando} className="botao-largura">Enviar instruções <ArrowRight size={18} /></Botao>
    </form>
  </TelaAcesso>;
}
