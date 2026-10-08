"use client";

import { createContext, useContext } from "react";

export const RECURSOS_PLANO = {
  VISAO_GERAL: "Visão geral e relatórios",
  CONTAS_MOVIMENTACOES: "Contas e movimentações",
  IMPORTACAO_EXTRATOS: "Importação de extratos",
  EMPRESTIMOS: "Empréstimos",
  PAGAMENTOS_RECORRENTES: "Pagamentos recorrentes",
  PLANEJAMENTO_FINANCEIRO: "Planejamento financeiro",
  METAS_FINANCEIRAS: "Metas financeiras",
} as const;

export type RecursoPlano = typeof RECURSOS_PLANO[keyof typeof RECURSOS_PLANO];

const ContextoRecursosPlano = createContext<string[] | null | undefined>(undefined);

export function ProvedorRecursosPlano({ recursos, children }: { recursos: string[] | null; children: React.ReactNode }) {
  return <ContextoRecursosPlano.Provider value={recursos}>{children}</ContextoRecursosPlano.Provider>;
}

export function possuiRecurso(recursos: string[] | null, recurso: RecursoPlano) {
  return recursos === null || recursos.includes(recurso);
}

export function useRecursosPlano() {
  const recursos = useContext(ContextoRecursosPlano);
  if (recursos === undefined) throw new Error("O provedor de recursos do plano não foi configurado.");

  return {
    recursos,
    possui: (recurso: RecursoPlano) => possuiRecurso(recursos, recurso),
  };
}
