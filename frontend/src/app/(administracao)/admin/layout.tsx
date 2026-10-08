import { ProvedorAutenticacaoAdmin } from "@/components/autenticacao-admin";
import { EstruturaAdmin } from "@/components/estrutura-admin";

export default function LayoutAdministracao({ children }: { children: React.ReactNode }) {
  return <ProvedorAutenticacaoAdmin><EstruturaAdmin>{children}</EstruturaAdmin></ProvedorAutenticacaoAdmin>;
}
