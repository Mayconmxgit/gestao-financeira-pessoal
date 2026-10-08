"use client";

import { useState } from "react";
import { Check, Crown, ExternalLink } from "lucide-react";
import { useDados } from "@/hooks/use-dados";
import { api } from "@/lib/api";
import { formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Assinatura, PlanoAssinatura } from "@/lib/tipos";
import { Aviso, Botao, CabecalhoPagina, Cartao, Vazio } from "@/components/interface";

export default function PaginaAssinatura() {
  const planos = useDados<{ dados: PlanoAssinatura[] }>("financeiro/planos");
  const atual = useDados<{ assinatura: Assinatura | null }>("financeiro/assinatura");
  const [processando, definirProcessando] = useState<string | null>(null);
  const [erro, definirErro] = useState("");
  const [sucesso, definirSucesso] = useState("");

  async function assinar(plano: PlanoAssinatura) {
    definirErro(""); definirSucesso(""); definirProcessando(plano.id);
    try {
      const resposta = await api<{ url_checkout: string | null; gratuito?: boolean }>("financeiro/assinatura/checkout", {
        method: "POST", body: JSON.stringify({ plano_id: plano.id }),
      });
      if (resposta.gratuito) {
        definirSucesso("Plano gratuito ativado com sucesso."); atual.recarregar(); definirProcessando(null); return;
      }
      if (resposta.url_checkout) window.location.assign(resposta.url_checkout);
    } catch (falha) { definirErro(mensagemErro(falha)); definirProcessando(null); }
  }

  const assinatura = atual.dados?.assinatura;
  const bloqueiaNova = assinatura && ["criando", "pendente", "ativa", "periodo_graca", "pausada"].includes(assinatura.situacao);

  return <div><CabecalhoPagina titulo="Assinatura" descricao="Consulte seu plano e escolha a modalidade adequada para sua rotina." />
    {(planos.erro || atual.erro || erro) && <Aviso>{planos.erro || atual.erro || erro}</Aviso>}
    {sucesso && <Aviso tipo="sucesso">{sucesso}</Aviso>}
    {assinatura && <Cartao className="assinatura-atual"><div><span className="icone-cabecalho"><Crown size={22} /></span><div><span className="sobretitulo">PLANO ATUAL</span><h2>{assinatura.plano_nome}</h2><p>{formatarMoeda(assinatura.valor, assinatura.moeda)} / {assinatura.periodicidade}</p></div></div><div className="assinatura-atual-detalhes"><span className={`situacao situacao-${assinatura.situacao}`}>{rotuloSituacao(assinatura.situacao)}</span><small>Próxima cobrança: {formatarData(assinatura.proxima_cobranca_em)}</small><small>Acesso até: {formatarData(assinatura.periodo_acesso_fim)}</small></div></Cartao>}
    <div className="planos-grade">{planos.dados?.dados.map((plano) => <Cartao key={plano.id} className="plano-cartao">
      <div><span className="sobretitulo">{plano.periodicidade.toUpperCase()}</span><h2>{plano.nome}</h2><p>{plano.descricao}</p></div>
      <strong>{formatarMoeda(plano.valor, plano.moeda)}<small> / {plano.periodicidade === "mensal" ? "mês" : "ano"}</small></strong>
      <ul>{plano.recursos.map((recurso) => <li key={recurso}><Check size={16} />{recurso}</li>)}</ul>
      <Botao onClick={() => assinar(plano)} carregando={processando === plano.id} disabled={Boolean(bloqueiaNova)}>{Number(plano.valor) === 0 ? "Ativar plano grátis" : <>Assinar com Mercado Pago <ExternalLink size={16} /></>}</Botao>
    </Cartao>)}</div>
    {!planos.carregando && planos.dados?.dados.length === 0 && <Cartao><Vazio titulo="Nenhum plano disponível" descricao="Os planos serão exibidos aqui assim que forem publicados." /></Cartao>}
    <p className="assinatura-nota">Os dados do cartão são informados diretamente ao provedor de pagamento e não são armazenados pela Vida Financeira.</p>
  </div>;
}

function rotuloSituacao(situacao: string) {
  return ({ criando: "Preparando", pendente: "Pagamento pendente", ativa: "Ativa", periodo_graca: "Período de graça", inadimplente: "Inadimplente", pausada: "Pausada", cancelada: "Cancelada", falha: "Falha" } as Record<string, string>)[situacao] ?? situacao;
}
