export class ErroApi extends Error {
  constructor(
    mensagem: string,
    public readonly status: number,
    public readonly codigo?: string,
    public readonly campos?: Record<string, string[]>,
    public readonly detalhes?: Record<string, unknown>,
  ) { super(mensagem); }
}

export async function api<T>(caminho: string, opcoes: RequestInit = {}): Promise<T> {
  const corpo = opcoes.body;
  const cabecalhos = new Headers(opcoes.headers);
  if (corpo && !(corpo instanceof FormData)) cabecalhos.set("Content-Type", "application/json");
  const resposta = await fetch(`/api/${caminho.replace(/^\/+/, "")}`, {
    ...opcoes, headers: cabecalhos, credentials: "same-origin", cache: "no-store",
  });
  if (!resposta.ok) {
    const dados = await resposta.json().catch(() => ({})) as {
      codigo?: string; mensagem?: string; erros?: Record<string, string[]>;
    };
    const erroCampo = Object.values(dados.erros ?? {})[0]?.[0];
    const detalhe = erroCampo?.replace(/^[A-Z_]{4,}:\s*/, "");
    const mensagem = resposta.status === 422 && detalhe
      ? (detalhe === "ARQUIVO_NAO_SUPORTADO" ? "Formato não reconhecido. Use um arquivo OFX, CSV ou PDF válido do Itaú." : detalhe)
      : dados.mensagem ?? detalhe
        ?? (resposta.status === 401 ? "Sua sessão expirou. Entre novamente." : "Não foi possível concluir a operação.");
    if (resposta.status === 401 && typeof window !== "undefined") {
      if (/^admin(?:-auth)?\//.test(caminho)) window.dispatchEvent(new Event("sessao-admin-expirada"));
      else if (caminho !== "auth/eu") window.dispatchEvent(new Event("sessao-expirada"));
    }
    throw new ErroApi(mensagem, resposta.status, dados.codigo, dados.erros, dados as Record<string, unknown>);
  }
  return resposta.json() as Promise<T>;
}

export function enviar<T>(caminho: string, dados: Record<string, unknown>, metodo = "POST") {
  return api<T>(caminho, { method: metodo, body: JSON.stringify(dados) });
}

export async function baixarExportacao() {
  const resposta = await fetch("/api/financeiro/usuarios/eu/exportacao", {
    credentials: "same-origin", cache: "no-store",
  });
  if (!resposta.ok) throw new ErroApi("Não foi possível exportar seus dados.", resposta.status);
  const arquivo = new Blob([await resposta.arrayBuffer()], { type: "application/json" });
  const url = URL.createObjectURL(arquivo);
  const link = document.createElement("a");
  link.href = url;
  link.download = "vida-financeira-dados.json";
  link.click();
  URL.revokeObjectURL(url);
}
