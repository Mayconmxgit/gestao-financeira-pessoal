import { redirect } from "next/navigation";
import { cookies } from "next/headers";

export default async function Inicio() {
  redirect((await cookies()).has("vida_financeira_sessao") ? "/visao-geral" : "/entrar");
}
