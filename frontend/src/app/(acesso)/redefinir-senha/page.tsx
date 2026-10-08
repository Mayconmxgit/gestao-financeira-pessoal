import { FormularioRedefinir } from "@/components/formulario-redefinir";

export default async function RedefinirSenha({ searchParams }: {
  searchParams: Promise<{ email?: string; token?: string }>;
}) {
  const { email = "", token = "" } = await searchParams;
  return <FormularioRedefinir email={email} token={token} />;
}
