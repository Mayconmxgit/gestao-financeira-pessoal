"use client";

import { useState } from "react";
import { Check, LogOut } from "lucide-react";
import { useDados } from "@/hooks/use-dados";
import { api } from "@/lib/api";
import { formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { PlanoAssinatura } from "@/lib/tipos";
import { Aviso, Botao } from "@/components/interface";

export function EscolhaPlanoInicial({ concluir, sair }: { concluir: () => void; sair: () => Promise<void> }) {
  const planos = useDados<{ dados: PlanoAssinatura[] }>("financeiro/planos");
  const [processando, definirProcessando] = useState<string | null>(null);
  const [erro, definirErro] = useState("");

  async function escolher(plano: PlanoAssinatura) {
    if (Number(plano.valor) > 0) return;
    definirErro("");
    definirProcessando(plano.id);
    try {
      await api("financeiro/assinatura/checkout", {
        method: "POST", body: JSON.stringify({ plano_id: plano.id }),
      });
      concluir();
    } catch (falha) {
      definirErro(mensagemErro(falha));
    } finally {
      definirProcessando(null);
    }
  }

  return <main className="escolha-plano" role="dialog" aria-modal="true" aria-labelledby="titulo-escolha-plano">
    <button type="button" className="escolha-plano-sair" onClick={() => sair()}><LogOut size={17} /> Sair</button>
    <header className="escolha-plano-cabecalho">
      <span className="sobretitulo">NOSSOS PLANOS</span>
      <h1 id="titulo-escolha-plano">Escolha seu melhor plano</h1>
      <p>Selecione uma modalidade para começar a organizar sua vida financeira.</p>
    </header>
    {(erro || planos.erro) && <Aviso>{erro || planos.erro}</Aviso>}
    {planos.carregando && <p className="escolha-plano-carregando">Carregando planos...</p>}
    <section className="escolha-planos-grade" aria-label="Planos disponíveis">
      {planos.dados?.dados.map((plano) => <CartaoEscolhaPlano
        key={plano.id}
        plano={plano}
        processando={processando === plano.id}
        escolher={() => escolher(plano)}
      />)}
    </section>
    {!planos.carregando && planos.dados?.dados.length === 0 && <Aviso tipo="info">Nenhum plano está disponível no momento.</Aviso>}
  </main>;
}

function CartaoEscolhaPlano({ plano, processando, escolher }: {
  plano: PlanoAssinatura;
  processando: boolean;
  escolher: () => void;
}) {
  const gratuito = Number(plano.valor) === 0;
  return <article className={`escolha-plano-cartao ${gratuito ? "escolha-plano-cartao-destaque" : ""}`}>
    <div className="escolha-plano-topo">
      <span>{gratuito ? "PARA COMEÇAR" : "PLANO COMPLETO"}</span>
      <h2>{plano.nome}</h2>
      <strong>{gratuito ? "Grátis" : formatarMoeda(plano.valor, plano.moeda)}</strong>
      <small>{gratuito ? "sem cobrança" : `por ${plano.periodicidade === "mensal" ? "mês" : "ano"}`}</small>
    </div>
    <div className="escolha-plano-corpo">
      <p>{plano.descricao}</p>
      <ul>{plano.recursos.map((recurso) => <li key={recurso}><Check size={17} /> <span>{recurso}</span></li>)}</ul>
      <Botao
        type="button"
        className="escolha-plano-botao"
        carregando={processando}
        disabled={!gratuito}
        onClick={escolher}
      >Escolha</Botao>
      {!gratuito && <small className="escolha-plano-indisponivel">Pagamento disponível em breve</small>}
    </div>
  </article>;
}
