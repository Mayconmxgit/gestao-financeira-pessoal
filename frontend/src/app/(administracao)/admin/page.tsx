"use client";

import { ShieldCheck } from "lucide-react";
import { PainelAssinaturasAdmin } from "@/components/painel-assinaturas-admin";
import { CabecalhoPagina } from "@/components/interface";

export default function PaginaAdministracao() {
  return <div><CabecalhoPagina titulo="Gestão de assinaturas" descricao="Planos, clientes, receita recorrente e integridade dos eventos de pagamento." acao={<span className="selo-admin"><ShieldCheck size={17} /> Área administrativa</span>} /><PainelAssinaturasAdmin /></div>;
}
