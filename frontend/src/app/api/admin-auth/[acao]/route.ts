import { NextRequest, NextResponse } from "next/server";
import {
  clienteIpDaRequisicao, corpoLimitado, erroLocal, extrairCredenciais, indisponivel, limparSessao,
  origemValida, respostaBackend, respostaComSessao, salvarSessao, solicitarBackend, solicitarComSessao,
} from "@/lib/servidor-api";

const escopo = "administrador" as const;

export async function GET(requisicao: NextRequest, contexto: { params: Promise<{ acao: string }> }) {
  const { acao } = await contexto.params;
  if (acao !== "eu") return erroLocal("NAO_ENCONTRADO", "Rota inexistente.", 404);
  try {
    const resultado = await solicitarComSessao("usuarios/eu", { clienteIp: clienteIpDaRequisicao(requisicao) }, escopo);
    if (!resultado.resposta.ok) return respostaComSessao(resultado, escopo);
    const usuario = await resultado.resposta.json() as Record<string, unknown>;
    if (usuario.papel !== "administrador") return negarAcesso();
    const retorno = NextResponse.json(usuario);
    retorno.headers.set("Cache-Control", "no-store");
    if (resultado.credenciais) salvarSessao(retorno, resultado.credenciais, escopo);
    return retorno;
  } catch { return indisponivel(); }
}

export async function POST(requisicao: NextRequest, contexto: { params: Promise<{ acao: string }> }) {
  const { acao } = await contexto.params;
  if (!origemValida(requisicao)) return erroLocal("ORIGEM_INVALIDA", "Origem não autorizada.", 403);
  if (acao === "sair") return encerrarSessao();
  if (acao !== "entrar") return erroLocal("NAO_ENCONTRADO", "Rota inexistente.", 404);
  const corpo = await corpoLimitado(requisicao, 16_384);
  if (!corpo) return erroLocal("CORPO_INVALIDO", "Solicitação muito grande.", 413);
  try { return autenticarAdministrador(corpo, clienteIpDaRequisicao(requisicao)); }
  catch { return indisponivel(); }
}

async function autenticarAdministrador(corpo: ArrayBuffer, clienteIp: string | null) {
  const resposta = await solicitarBackend("entrar", {
    method: "POST", body: corpo, contentType: "application/json", clienteIp,
  });
  if (!resposta.ok) return respostaBackend(resposta);
  const dados = await resposta.json() as Record<string, unknown>;
  const usuario = dados.usuario as Record<string, unknown> | undefined;
  const credenciais = extrairCredenciais(dados);
  if (!credenciais || usuario?.papel !== "administrador") return negarAcesso();
  const retorno = NextResponse.json({ usuario });
  retorno.headers.set("Cache-Control", "no-store");
  salvarSessao(retorno, credenciais, escopo);
  return retorno;
}

function negarAcesso() {
  const retorno = erroLocal("ACESSO_ADMINISTRATIVO_NEGADO", "Credenciais administrativas necessárias.", 403);
  limparSessao(retorno, escopo);
  return retorno;
}

function encerrarSessao() {
  const retorno = NextResponse.json({ mensagem: "Sessão administrativa encerrada." });
  limparSessao(retorno, escopo);
  return retorno;
}
