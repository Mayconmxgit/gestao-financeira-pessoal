"use client";

import { useEffect, useRef } from "react";
import { ArrowLeft, ArrowRight, Check, X } from "lucide-react";
import { calcularPosicaoCartaoTour, type RetanguloTour } from "@/lib/posicionamento-tour";

export type EtapaTour = {
  titulo: string;
  descricao: string;
  funcionalidades?: string[];
  alvo?: string;
  seletor?: string;
  acoes?: string[];
  href?: string;
};

type PropriedadesTour = {
  etapas: EtapaTour[];
  etapaAtual: number;
  voltar: () => void;
  avancar: () => void;
  encerrar: () => void;
};

export function TourAplicacao({ etapas, etapaAtual, voltar, avancar, encerrar }: PropriedadesTour) {
  const botaoFechar = useRef<HTMLButtonElement>(null);
  const cartao = useRef<HTMLElement>(null);
  const etapa = etapas[etapaAtual];
  const ultimaEtapa = etapaAtual === etapas.length - 1;

  useEffect(() => {
    const controlador = new AbortController();
    const elementoCartao = cartao.current;
    let elemento: HTMLElement | null = null;
    let quadroReposicionamento: number | null = null;
    let observadorRedimensionamento: ResizeObserver | null = null;
    let observadorMutacoes: MutationObserver | null = null;
    const temporizadores: number[] = [];
    const seletor = etapa.seletor ?? (etapa.alvo ? `[data-tour="${etapa.alvo}"]` : null);

    const agendarReposicionamento = () => {
      if (quadroReposicionamento !== null) cancelAnimationFrame(quadroReposicionamento);
      quadroReposicionamento = requestAnimationFrame(() => {
        quadroReposicionamento = null;
        posicionarCartao(elemento, elementoCartao);
      });
    };

    async function prepararEtapa() {
      for (const seletorAcao of etapa.acoes ?? []) {
        const acaoOpcional = seletorAcao.startsWith("?");
        const seletorReal = acaoOpcional ? seletorAcao.slice(1) : seletorAcao;
        const acao = acaoOpcional
          ? document.querySelector<HTMLElement>(seletorReal)
          : await aguardarElemento(seletorReal, controlador.signal);
        if (!acao) {
          if (acaoOpcional) continue;
          return;
        }
        if (controlador.signal.aborted) return;
        acao.click();
        await aguardarRenderizacao();
      }

      if (!seletor) return;
      elemento = await aguardarElemento(seletor, controlador.signal);
      if (!elemento || controlador.signal.aborted) return;
      elemento.classList.add("tour-em-destaque");
      elemento.scrollIntoView({ block: "center", behavior: "auto" });
      await aguardarRenderizacao();
      if (controlador.signal.aborted) return;
      agendarReposicionamento();
      observadorRedimensionamento = new ResizeObserver(agendarReposicionamento);
      observadorRedimensionamento.observe(elemento);
      observadorRedimensionamento.observe(document.body);
      observadorMutacoes = new MutationObserver(agendarReposicionamento);
      observadorMutacoes.observe(document.querySelector("main") ?? document.body, { childList: true, subtree: true });
      for (const atraso of [100, 300, 700, 1500]) {
        temporizadores.push(window.setTimeout(agendarReposicionamento, atraso));
      }
    }

    void prepararEtapa();
    botaoFechar.current?.focus();

    function tratarTeclado(evento: KeyboardEvent) {
      if (evento.key === "Escape") encerrar();
      if (evento.key === "ArrowLeft" && etapaAtual > 0) voltar();
      if (evento.key === "ArrowRight") avancar();
    }

    document.addEventListener("keydown", tratarTeclado);
    window.addEventListener("resize", agendarReposicionamento);
    window.addEventListener("scroll", agendarReposicionamento, true);
    return () => {
      controlador.abort();
      if (quadroReposicionamento !== null) cancelAnimationFrame(quadroReposicionamento);
      temporizadores.forEach(window.clearTimeout);
      observadorRedimensionamento?.disconnect();
      observadorMutacoes?.disconnect();
      elemento?.classList.remove("tour-em-destaque");
      limparPosicaoCartao(elementoCartao);
      document.removeEventListener("keydown", tratarTeclado);
      window.removeEventListener("resize", agendarReposicionamento);
      window.removeEventListener("scroll", agendarReposicionamento, true);
    };
  }, [avancar, encerrar, etapa, etapaAtual, voltar]);

  return <div className="tour-camada">
    <div className="tour-sombra" aria-hidden="true" />
    <section ref={cartao} className="tour-cartao" role="dialog" aria-modal="true" aria-labelledby="tour-titulo" aria-describedby="tour-descricao">
      <div className="tour-cabecalho">
        <span>TOUR DA APLICAÇÃO</span>
        <button ref={botaoFechar} className="botao-icone" type="button" onClick={encerrar} aria-label="Encerrar tour"><X size={19} /></button>
      </div>
      <div className="tour-progresso" aria-label={`Etapa ${etapaAtual + 1} de ${etapas.length}`}>
        <span style={{ width: `${((etapaAtual + 1) / etapas.length) * 100}%` }} />
      </div>
      <div className="tour-conteudo">
        <small>Etapa {etapaAtual + 1} de {etapas.length}</small>
        <h2 id="tour-titulo">{etapa.titulo}</h2>
        <p id="tour-descricao">{etapa.descricao}</p>
        {etapa.funcionalidades?.length ? <ul className="tour-funcionalidades">
          {etapa.funcionalidades.map((funcionalidade) => <li key={funcionalidade}>{funcionalidade}</li>)}
        </ul> : null}
      </div>
      <div className="tour-acoes">
        <button className="botao botao-texto" type="button" onClick={encerrar}>Pular tour</button>
        <div>
          <button className="botao botao-secundario" type="button" onClick={voltar} disabled={etapaAtual === 0}><ArrowLeft size={16} /> Voltar</button>
          <button className="botao botao-primario" type="button" onClick={avancar}>
            {ultimaEtapa ? <><Check size={16} /> Concluir</> : <>Próximo <ArrowRight size={16} /></>}
          </button>
        </div>
      </div>
    </section>
  </div>;
}

function aguardarElemento(seletor: string, sinal: AbortSignal) {
  return new Promise<HTMLElement | null>((resolver) => {
    const atual = document.querySelector<HTMLElement>(seletor);
    if (atual) { resolver(atual); return; }

    const observador = new MutationObserver(() => {
      const encontrado = document.querySelector<HTMLElement>(seletor);
      if (!encontrado) return;
      limpar(); resolver(encontrado);
    });
    const temporizador = window.setTimeout(() => { limpar(); resolver(null); }, 2500);
    const abortar = () => { limpar(); resolver(null); };
    const limpar = () => {
      observador.disconnect();
      window.clearTimeout(temporizador);
      sinal.removeEventListener("abort", abortar);
    };

    sinal.addEventListener("abort", abortar, { once: true });
    observador.observe(document.body, { childList: true, subtree: true });
  });
}

function aguardarRenderizacao() {
  return new Promise<void>((resolver) => requestAnimationFrame(() => requestAnimationFrame(() => resolver())));
}

function posicionarCartao(alvo: HTMLElement | null, cartao: HTMLElement | null) {
  if (!alvo || !cartao || window.innerWidth <= 700) {
    limparPosicaoCartao(cartao);
    return;
  }

  const retanguloCartao = cartao.getBoundingClientRect();
  const areasProtegidas = elementosTextuais(alvo)
    .map((elemento) => elemento.getBoundingClientRect())
    .filter((retangulo) => retangulo.width > 0 && retangulo.height > 0)
    .map(retanguloSimples);
  const posicao = calcularPosicaoCartaoTour(retanguloSimples(alvo.getBoundingClientRect()), areasProtegidas, {
    larguraViewport: window.innerWidth,
    alturaViewport: window.innerHeight,
    larguraCartao: retanguloCartao.width,
    alturaCartao: retanguloCartao.height,
  });

  cartao.style.left = `${posicao.esquerda}px`;
  cartao.style.top = `${posicao.topo}px`;
  cartao.style.right = "auto";
  cartao.style.bottom = "auto";
  cartao.style.transform = "none";
}

function limparPosicaoCartao(cartao: HTMLElement | null) {
  if (!cartao) return;
  cartao.style.removeProperty("left");
  cartao.style.removeProperty("top");
  cartao.style.removeProperty("right");
  cartao.style.removeProperty("bottom");
  cartao.style.removeProperty("transform");
}

function elementosTextuais(alvo: HTMLElement) {
  const seletor = "h1,h2,h3,p,label,legend";
  return [
    ...(alvo.matches(seletor) ? [alvo] : []),
    ...alvo.querySelectorAll<HTMLElement>(seletor),
  ];
}

function retanguloSimples(retangulo: DOMRect): RetanguloTour {
  return {
    left: retangulo.left,
    top: retangulo.top,
    right: retangulo.right,
    bottom: retangulo.bottom,
  };
}
