import { EstruturaApp } from "@/components/estrutura-app";

export default function LayoutAplicacao({ children }: { children: React.ReactNode }) {
  return <EstruturaApp>{children}</EstruturaApp>;
}
