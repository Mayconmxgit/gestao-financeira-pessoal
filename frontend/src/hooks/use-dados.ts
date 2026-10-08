"use client";

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { mensagemErro } from "@/lib/formatacao";

export function useDados<T>(caminho: string | null) {
  const [resultado, definirResultado] = useState<{ caminho: string | null; dados: T | null; erro: string | null }>({ caminho: null, dados: null, erro: null });
  const [versao, atualizar] = useState(0);
  const recarregar = useCallback(() => atualizar((atual) => atual + 1), []);

  useEffect(() => {
    if (!caminho) return;
    const controlador = new AbortController();
    api<T>(caminho, { signal: controlador.signal })
      .then((resposta) => { if (!controlador.signal.aborted) definirResultado({ caminho, dados: resposta, erro: null }); })
      .catch((falha) => { if (!controlador.signal.aborted) definirResultado({ caminho, dados: null, erro: mensagemErro(falha) }); });
    return () => controlador.abort();
  }, [caminho, versao]);

  const atual = resultado.caminho === caminho;
  return { dados: atual ? resultado.dados : null, carregando: Boolean(caminho) && (!atual || resultado.dados === null && resultado.erro === null), erro: atual ? resultado.erro : null, recarregar };
}
