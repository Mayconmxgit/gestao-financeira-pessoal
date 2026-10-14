import { PainelAssinaturasAdmin } from "@/components/painel-assinaturas-admin";
import { CabecalhoAdmin } from "@/components/painel-admin-visao-geral";

export default function PaginaAssinaturasAdmin() {
  return <div className="admin-pagina"><CabecalhoAdmin titulo="Assinaturas" descricao="Contratos, planos, cortesias e reconciliação com histórico preservado." /><PainelAssinaturasAdmin /></div>;
}
