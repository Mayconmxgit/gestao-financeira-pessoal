import { NextRequest, NextResponse } from "next/server";
import {
  clienteIpDaRequisicao, corpoLimitado, erroLocal, indisponivel, limparSessao, origemValida,
  extrairCredenciais, respostaBackend, respostaComSessao, salvarSessao,
  solicitarBackend, solicitarComSessao,
} from "@/lib/servidor-api";

const caminhos: Record<string, string> = {
  entrar: "entrar",
  cadastrar: "usuarios",
  recuperar: "recuperar-acesso",
  redefinir: "redefinir-senha",
  sair: "sair",
};

export async function GET(requisicao: NextRequest, contexto: { params: Promise<{ acao: string }> }) {
  const { acao } = await contexto.params;
  if (acao !== "eu") return erroLocal("NAO_ENCONTRADO", "Rota inexistente.", 404);
  try {
    return respostaComSessao(await solicitarComSessao("usuarios/eu", { clienteIp: clienteIpDaRequisicao(requisicao) }));
  } catch {
    return indisponivel();
  }
}

export async function POST(requisicao: NextRequest, contexto: { params: Promise<{ acao: string }> }) {
  const { acao } = await contexto.params;
  if (!origemValida(requisicao)) return erroLocal("ORIGEM_INVALIDA", "Origem não autorizada.", 403);
  const caminho = caminhos[acao];
  if (!caminho) return erroLocal("NAO_ENCONTRADO", "Rota inexistente.", 404);
  const corpo = await corpoLimitado(requisicao, 16_384);
  const clienteIp = clienteIpDaRequisicao(requisicao);
  if (!corpo) return erroLocal("CORPO_INVALIDO", "Solicitação muito grande.", 413);
  try {
    if (acao === "sair") {
      const resultado = await solicitarComSessao(caminho, {
        method: "POST", body: corpo, contentType: "application/json", clienteIp,
      });
      const retorno = await respostaBackend(resultado.resposta);
      limparSessao(retorno);
      return retorno;
    }
    const resposta = await solicitarBackend(caminho, {
      method: "POST", body: corpo, contentType: "application/json", clienteIp,
    });
    if (!(["entrar", "cadastrar"].includes(acao) && resposta.ok)) return respostaBackend(resposta);
    const dados = await resposta.json() as Record<string, unknown>;
    const credenciais = extrairCredenciais(dados);
    const usuario = dados.usuario;
    if (!credenciais || typeof usuario !== "object" || usuario === null) return indisponivel();
    const retorno = NextResponse.json({ usuario });
    retorno.headers.set("Cache-Control", "no-store");
    salvarSessao(retorno, credenciais);
    return retorno;
  } catch {
    if (acao === "sair") {
      const retorno = NextResponse.json({ mensagem: "Sessão local encerrada." });
      limparSessao(retorno);
      return retorno;
    }
    return indisponivel();
  }
}
