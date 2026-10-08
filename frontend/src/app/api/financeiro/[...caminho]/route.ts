import { NextRequest } from "next/server";
import {
  clienteIpDaRequisicao, corpoLimitado, erroLocal, indisponivel, origemValida,
  respostaComSessao, solicitarComSessao,
} from "@/lib/servidor-api";

type Contexto = { params: Promise<{ caminho: string[] }> };

async function encaminhar(requisicao: NextRequest, contexto: Contexto) {
  const metodo = requisicao.method;
  if (metodo !== "GET" && !origemValida(requisicao))
    return erroLocal("ORIGEM_INVALIDA", "Origem não autorizada.", 403);
  const { caminho } = await contexto.params;
  if (!caminho.length || caminho.some((parte) => !/^[a-zA-Z0-9_-]+$/.test(parte)))
    return erroLocal("CAMINHO_INVALIDO", "Rota inválida.", 400);
  if (caminho[0] === "admin" || (caminho[0] === "usuarios" && caminho.length === 1))
    return erroLocal("ACESSO_ADMINISTRATIVO_SEPARADO", "Use o acesso administrativo.", 403);
  const corpo = metodo === "GET" ? null : await corpoLimitado(requisicao);
  if (metodo !== "GET" && !corpo) return erroLocal("CORPO_INVALIDO", "Arquivo ou solicitação muito grande.", 413);
  const busca = requisicao.nextUrl.search;
  try {
    const resultado = await solicitarComSessao(caminho.join("/") + busca, {
      method: metodo,
      body: corpo,
      contentType: requisicao.headers.get("content-type"),
      clienteIp: clienteIpDaRequisicao(requisicao),
    });
    return respostaComSessao(resultado);
  } catch {
    return indisponivel();
  }
}

export const GET = encaminhar;
export const POST = encaminhar;
export const PATCH = encaminhar;
export const DELETE = encaminhar;
