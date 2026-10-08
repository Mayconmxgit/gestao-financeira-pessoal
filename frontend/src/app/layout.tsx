import type { Metadata } from "next";
import { connection } from "next/server";
import { ProvedorAutenticacao } from "@/components/autenticacao";
import "./globals.css";

export const metadata: Metadata = {
  title: "Vida Financeira | Clareza para o seu dinheiro",
  description: "Organize contas, confira extratos e acompanhe seu saldo gerencial em um só lugar.",
};

export default async function LayoutRaiz({ children }: Readonly<{ children: React.ReactNode }>) {
  await connection();
  return <html lang="pt-BR" data-scroll-behavior="smooth"><body><ProvedorAutenticacao>{children}</ProvedorAutenticacao></body></html>;
}
