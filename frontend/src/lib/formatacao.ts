export function formatarMoeda(valor: string | number, moeda = "BRL") {
  const numero = typeof valor === "number" ? valor : Number(valor);
  if (!Number.isFinite(numero)) return "—";
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: moeda }).format(numero);
}

export function formatarData(data: string | null | undefined) {
  if (!data) return "—";
  const parte = data.slice(0, 10);
  const [ano, mes, dia] = parte.split("-");
  return ano && mes && dia ? `${dia}/${mes}/${ano}` : parte;
}

export function dataHojeLocal(data = new Date()) {
  const ano = data.getFullYear();
  const mes = String(data.getMonth() + 1).padStart(2, "0");
  const dia = String(data.getDate()).padStart(2, "0");
  return `${ano}-${mes}-${dia}`;
}

export function intervaloMes(data = new Date()) {
  const ano = data.getFullYear();
  const mes = data.getMonth();
  const inicio = `${ano}-${String(mes + 1).padStart(2, "0")}-01`;
  const fim = `${ano}-${String(mes + 1).padStart(2, "0")}-${String(new Date(ano, mes + 1, 0).getDate()).padStart(2, "0")}`;
  return { inicio, fim };
}

export function nomeMes(data = new Date()) {
  return new Intl.DateTimeFormat("pt-BR", { month: "long", year: "numeric" }).format(data);
}

export function mensagemErro(erro: unknown) {
  return erro instanceof Error ? erro.message : "Não foi possível concluir a operação.";
}

export const nomesNatureza: Record<string, string> = {
  entrada: "Entrada", saida: "Saída", compra_cartao: "Compra no cartão",
  transferencia: "Transferência", pagamento_fatura: "Pagamento de fatura",
  estorno: "Estorno", ajuste: "Ajuste",
  emprestimo_recebido: "Empréstimo recebido", pagamento_emprestimo: "Pagamento de empréstimo",
};
