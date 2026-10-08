"use client";

import { createContext, useContext, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, ErroApi } from "@/lib/api";
import type { Usuario } from "@/lib/tipos";

type AutenticacaoAdmin = {
  usuario: Usuario | null; carregando: boolean; erro: string | null;
  entrar: (email: string, senha: string) => Promise<void>; sair: () => Promise<void>;
};

const Contexto = createContext<AutenticacaoAdmin | null>(null);

export function ProvedorAutenticacaoAdmin({ children }: { children: React.ReactNode }) {
  const [usuario, definirUsuario] = useState<Usuario | null>(null);
  const [carregando, definirCarregando] = useState(true);
  const [erro, definirErro] = useState<string | null>(null);
  const roteador = useRouter();

  useEffect(() => {
    const controlador = new AbortController();
    api<Usuario>("admin-auth/eu", { signal: controlador.signal })
      .then((atual) => { definirUsuario(atual); definirErro(null); })
      .catch((falha) => {
        if (controlador.signal.aborted) return;
        if (falha instanceof ErroApi && [401, 403].includes(falha.status)) definirUsuario(null);
        else definirErro("Não foi possível conectar ao backend.");
      }).finally(() => { if (!controlador.signal.aborted) definirCarregando(false); });
    return () => controlador.abort();
  }, []);

  useEffect(() => {
    const expirar = () => { definirUsuario(null); roteador.replace("/admin/entrar"); };
    window.addEventListener("sessao-admin-expirada", expirar);
    return () => window.removeEventListener("sessao-admin-expirada", expirar);
  }, [roteador]);

  async function entrar(email: string, senha: string) {
    const resposta = await api<{ usuario: Usuario }>("admin-auth/entrar", {
      method: "POST", body: JSON.stringify({ email, senha }),
    });
    definirUsuario(resposta.usuario); definirErro(null); roteador.replace("/admin");
  }

  async function sair() {
    try { await api("admin-auth/sair", { method: "POST" }); }
    finally { definirUsuario(null); roteador.replace("/admin/entrar"); }
  }

  return <Contexto.Provider value={{ usuario, carregando, erro, entrar, sair }}>{children}</Contexto.Provider>;
}

export function useAutenticacaoAdmin() {
  const contexto = useContext(Contexto);
  if (!contexto) throw new Error("ProvedorAutenticacaoAdmin ausente");
  return contexto;
}
