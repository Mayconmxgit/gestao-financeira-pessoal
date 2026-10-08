import { formatarMoeda } from "@/lib/formatacao";

export function ValorMonetario({ valor, moeda, oculto }: {
  valor: string | number; moeda: string; oculto: boolean;
}) {
  if (oculto) return <span aria-label="Valor oculto">••••••</span>;

  return <>{formatarMoeda(valor, moeda)}</>;
}
