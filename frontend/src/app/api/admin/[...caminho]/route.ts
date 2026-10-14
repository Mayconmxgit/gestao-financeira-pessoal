import { NextRequest } from "next/server";
import {
  clienteIpDaRequisicao, corpoLimitado, erroLocal, indisponivel, origemValida, respostaComSessao, solicitarComSessao,
} from "@/lib/servidor-api";

type Contexto = { params: Promise<{ caminho: string[] }> };

async function encaminhar(requisicao: NextRequest, contexto: Contexto) {
  if (requisicao.method !== "GET" && !origemValida(requisicao))
    return erroLocal("ORIGEM_INVALIDA", "Origem não autorizada.", 403);
  const destino = await resolverDestino(contexto, requisicao.method);
  if (!destino) return erroLocal("CAMINHO_INVALIDO", "Rota administrativa inválida.", 400);
  const corpo = requisicao.method === "GET" ? null : await corpoLimitado(requisicao);
  if (requisicao.method !== "GET" && !corpo)
    return erroLocal("CORPO_INVALIDO", "Solicitação muito grande.", 413);
  try {
    const resultado = await solicitarComSessao(destino + requisicao.nextUrl.search, {
      method: requisicao.method, body: corpo, contentType: requisicao.headers.get("content-type"),
      clienteIp: clienteIpDaRequisicao(requisicao),
    }, "administrador");
    return respostaComSessao(resultado, "administrador");
  } catch { return indisponivel(); }
}

async function resolverDestino(contexto: Contexto, metodo: string) {
  const { caminho } = await contexto.params;
  if (!caminho.length || caminho.some((parte) => !/^[a-zA-Z0-9_-]+$/.test(parte))) return null;
  const rota = caminho.join("/");
  if (metodo === "GET" && /^(dashboard|usuarios|usuarios\/exportacao\.csv|operacoes)$/.test(rota)) return `admin/${rota}`;
  if (metodo === "GET" && /^relatorios\/(crescimento|receita-recorrente|churn|operacoes)(\/exportacao\.csv)?$/.test(rota)) return `admin/${rota}`;
  if (metodo === "GET" && /^(assinaturas|assinaturas\/(resumo|planos|eventos))$/.test(rota)) return `admin/${rota}`;
  if (metodo === "POST" && /^(assinaturas\/(planos|cortesia)|assinaturas\/[a-f0-9-]+\/reconciliacao)$/.test(rota)) return `admin/${rota}`;
  if (metodo === "PATCH" && /^assinaturas\/planos\/[a-f0-9-]+$/.test(rota)) return `admin/${rota}`;
  return null;
}

export const GET = encaminhar;
export const POST = encaminhar;
export const PATCH = encaminhar;
