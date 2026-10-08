"use client";

import { useEffect } from "react";

export function useAtualizacaoAutomatica(atualizar: () => void, intervaloMs = 120_000) {
  useEffect(() => {
    const atualizarSeVisivel = () => {
      if (document.visibilityState === "visible") atualizar();
    };
    const intervalo = window.setInterval(atualizarSeVisivel, intervaloMs);
    document.addEventListener("visibilitychange", atualizarSeVisivel);

    return () => {
      window.clearInterval(intervalo);
      document.removeEventListener("visibilitychange", atualizarSeVisivel);
    };
  }, [atualizar, intervaloMs]);
}
