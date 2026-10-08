"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ErroApi } from "@/lib/api";
import type { Usuario } from "@/lib/tipos";

type Autenticacao = {
  usuario: Usuario | null; carregando: boolean; erro: string | null;
  entrar: (email: string, senha: string) => Promise<void>;
  cadastrar: (nome: string, email: string, senha: string) => Promise<void>;
  sair: () => Promise<void>; recarregar: () => void;
};

const Contexto = createContext<Autenticacao | null>(null);

export function ProvedorAutenticacao({ children }: { children: React.ReactNode }) {
  const [usuario, definirUsuario] = useState<Usuario | null>(null);
  const [carregando, definirCarregando] = useState(true);
  const [erro, definirErro] = useState<string | null>(null);
  const [versao, atualizar] = useState(0);
  const roteador = useRouter();

  useEffect(() => {
    const controlador = new AbortController();
    api<Usuario>("auth/eu", { signal: controlador.signal })
      .then((atual) => { definirUsuario(atual); definirErro(null); })
      .catch((falha) => {
        if (controlador.signal.aborted) return;
        if (falha instanceof ErroApi && falha.status === 401) definirUsuario(null);
        else definirErro("Não foi possível conectar ao backend.");
      })
      .finally(() => { if (!controlador.signal.aborted) definirCarregando(false); });
    return () => controlador.abort();
  }, [versao]);

  useEffect(() => {
    const expirar = () => { definirUsuario(null); roteador.replace("/entrar"); };
    window.addEventListener("sessao-expirada", expirar);
    return () => window.removeEventListener("sessao-expirada", expirar);
  }, [roteador]);

  async function autenticar(caminho: string, dados: Record<string, string>) {
    const resposta = await api<{ usuario: Usuario }>(`auth/${caminho}`, {
      method: "POST", body: JSON.stringify(dados),
    });
    definirUsuario(resposta.usuario);
    definirErro(null);
    roteador.replace("/visao-geral");
  }

  async function sair() {
    try { await api("auth/sair", { method: "POST" }); }
    catch { /* A sessão local é encerrada mesmo se o token já foi invalidado. */ }
    finally { definirUsuario(null); roteador.replace("/entrar"); }
  }

  return <Contexto.Provider value={{
    usuario, carregando, erro,
    entrar: (email, senha) => autenticar("entrar", { email, senha }),
    cadastrar: (nome, email, senha) => autenticar("cadastrar", { nome, email, senha }),
    sair, recarregar: () => { definirCarregando(true); atualizar((n) => n + 1); },
  }}>{children}</Contexto.Provider>;
}

export function useAutenticacao() {
  const contexto = useContext(Contexto);
  if (!contexto) throw new Error("ProvedorAutenticacao ausente");
  return contexto;
}
