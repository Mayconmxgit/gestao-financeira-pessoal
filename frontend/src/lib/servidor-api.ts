import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";
import { isIP } from "node:net";

type EscopoSessao = "usuario" | "administrador";
const cookiesSessao = {
  usuario: { acesso: "vida_financeira_sessao", renovacao: "vida_financeira_renovacao" },
  administrador: { acesso: "vida_financeira_admin_sessao", renovacao: "vida_financeira_admin_renovacao" },
} as const;
const origemPermitida = process.env.APP_ORIGIN ?? "http://localhost:3000";
const backend = process.env.BACKEND_URL ?? "http://127.0.0.1:8000";

export type CredenciaisSessao = {
  token: string;
  expira_em_segundos: number;
  token_renovacao: string;
  renovacao_expira_em_segundos: number;
};

type OpcoesBackend = {
  method?: string;
  token?: string | null;
  body?: BodyInit | null;
  contentType?: string | null;
  clienteIp?: string | null;
};

type ResultadoAutenticado = {
  resposta: Response;
  credenciais: CredenciaisSessao | null;
};

export function origemValida(requisicao: NextRequest): boolean {
  return requisicao.headers.get("origin") === origemPermitida;
}

export function clienteIpDaRequisicao(requisicao: NextRequest): string | null {
  const clienteIp = requisicao.headers.get("x-real-ip")?.trim() ?? "";
  return isIP(clienteIp) ? clienteIp : null;
}

export function erroLocal(codigo: string, mensagem: string, status: number) {
  return NextResponse.json({ codigo, mensagem }, { status });
}

async function tokensAtuais(escopo: EscopoSessao) {
  const armazenados = await cookies();
  const nomes = cookiesSessao[escopo];
  return {
    acesso: armazenados.get(nomes.acesso)?.value ?? null,
    renovacao: armazenados.get(nomes.renovacao)?.value ?? null,
  };
}

function opcoesCookie(maxAge: number) {
  return {
    httpOnly: true,
    secure: origemPermitida.startsWith("https://"),
    sameSite: "strict" as const,
    path: "/",
    maxAge,
    priority: "high" as const,
  };
}

export function salvarSessao(resposta: NextResponse, dados: CredenciaisSessao, escopo: EscopoSessao = "usuario") {
  const nomes = cookiesSessao[escopo];
  resposta.cookies.set(nomes.acesso, dados.token, opcoesCookie(dados.expira_em_segundos));
  resposta.cookies.set(
    nomes.renovacao,
    dados.token_renovacao,
    opcoesCookie(dados.renovacao_expira_em_segundos),
  );
}

export function limparSessao(resposta: NextResponse, escopo: EscopoSessao = "usuario") {
  const nomes = cookiesSessao[escopo];
  resposta.cookies.delete(nomes.acesso);
  resposta.cookies.delete(nomes.renovacao);
}

export async function solicitarBackend(
  caminho: string,
  opcoes: OpcoesBackend = {},
): Promise<Response> {
  const url = new URL(`/api/v1/${caminho}`, backend);
  const cabecalhos = new Headers({ Accept: "application/json" });
  if (opcoes.token) cabecalhos.set("Authorization", `Bearer ${opcoes.token}`);
  if (opcoes.contentType) cabecalhos.set("Content-Type", opcoes.contentType);
  if (opcoes.clienteIp) cabecalhos.set("X-Vida-Cliente-IP", opcoes.clienteIp);
  return fetch(url, {
    method: opcoes.method ?? "GET",
    headers: cabecalhos,
    body: opcoes.body,
    cache: "no-store",
    redirect: "manual",
    signal: AbortSignal.timeout(60_000),
  });
}

export async function respostaBackend(resposta: Response) {
  const retorno = new NextResponse(resposta.body, { status: resposta.status });
  const tipo = resposta.headers.get("content-type");
  const disposicao = resposta.headers.get("content-disposition");
  if (tipo) retorno.headers.set("Content-Type", tipo);
  if (disposicao) retorno.headers.set("Content-Disposition", disposicao);
  retorno.headers.set("Cache-Control", "no-store");
  return retorno;
}

export function extrairCredenciais(dados: unknown): CredenciaisSessao | null {
  if (typeof dados !== "object" || dados === null) return null;
  const sessao = dados as Record<string, unknown>;
  const textosValidos = typeof sessao.token === "string" && typeof sessao.token_renovacao === "string";
  const prazosValidos = Number.isFinite(sessao.expira_em_segundos)
    && Number.isFinite(sessao.renovacao_expira_em_segundos)
    && Number(sessao.expira_em_segundos) > 0
    && Number(sessao.renovacao_expira_em_segundos) > 0;
  return textosValidos && prazosValidos ? sessao as CredenciaisSessao : null;
}

async function renovarSessao(token: string | null, clienteIp: string | null): Promise<CredenciaisSessao | null> {
  if (!token) return null;
  const resposta = await solicitarBackend("renovar", {
    method: "POST",
    body: JSON.stringify({ token }),
    contentType: "application/json",
    clienteIp,
  });
  if (resposta.status === 401) return null;
  if (!resposta.ok) throw new Error("Não foi possível renovar a sessão.");
  const credenciais = extrairCredenciais(await resposta.json());
  if (!credenciais) throw new Error("Resposta de renovação inválida.");
  return credenciais;
}

function naoAutenticado(): Response {
  return Response.json(
    { codigo: "NAO_AUTENTICADO", mensagem: "Entre novamente." },
    { status: 401 },
  );
}

export async function solicitarComSessao(
  caminho: string,
  opcoes: Omit<OpcoesBackend, "token"> = {},
  escopo: EscopoSessao = "usuario",
): Promise<ResultadoAutenticado> {
  const tokens = await tokensAtuais(escopo);
  let credenciais = tokens.acesso ? null : await renovarSessao(tokens.renovacao, opcoes.clienteIp ?? null);
  const token = tokens.acesso ?? credenciais?.token;
  if (!token) return { resposta: naoAutenticado(), credenciais: null };

  const resposta = await solicitarBackend(caminho, { ...opcoes, token });
  if (resposta.status !== 401 || credenciais || !tokens.renovacao) {
    return { resposta, credenciais };
  }

  credenciais = await renovarSessao(tokens.renovacao, opcoes.clienteIp ?? null);
  if (!credenciais) return { resposta, credenciais: null };
  await resposta.body?.cancel();
  const repetida = await solicitarBackend(caminho, { ...opcoes, token: credenciais.token });
  return { resposta: repetida, credenciais };
}

export async function respostaComSessao(resultado: ResultadoAutenticado, escopo: EscopoSessao = "usuario") {
  const retorno = await respostaBackend(resultado.resposta);
  if (resultado.resposta.status === 401) limparSessao(retorno, escopo);
  else if (resultado.credenciais) salvarSessao(retorno, resultado.credenciais, escopo);
  return retorno;
}

export async function corpoLimitado(requisicao: NextRequest, limite = 6_000_000) {
  const anunciado = Number(requisicao.headers.get("content-length") ?? 0);
  if (anunciado > limite) return null;
  const corpo = await requisicao.arrayBuffer();
  return corpo.byteLength <= limite ? corpo : null;
}

export function indisponivel() {
  return erroLocal("API_INDISPONIVEL", "Não foi possível acessar o backend. Verifique se o iniciador está aberto.", 503);
}
