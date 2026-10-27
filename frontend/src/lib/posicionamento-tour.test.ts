import assert from "node:assert/strict";
import test from "node:test";
import { areaInterseccao, calcularPosicaoCartaoTour } from "./posicionamento-tour.ts";

test("posiciona o cartão fora do alvo quando há espaço lateral", () => {
  const alvo = { left: 300, top: 150, right: 900, bottom: 450 };
  const posicao = calcularPosicaoCartaoTour(alvo, [alvo], {
    larguraViewport: 1600,
    alturaViewport: 900,
    larguraCartao: 470,
    alturaCartao: 272,
  });
  const cartao = { left: posicao.esquerda, top: posicao.topo, right: posicao.esquerda + 470, bottom: posicao.topo + 272 };

  assert.equal(areaInterseccao(cartao, alvo), 0);
});

test("prioriza não cobrir o texto quando o alvo ocupa quase toda a tela", () => {
  const alvo = { left: 25, top: 100, right: 970, bottom: 398 };
  const explicacao = { left: 50, top: 145, right: 330, bottom: 180 };
  const posicao = calcularPosicaoCartaoTour(alvo, [explicacao], {
    larguraViewport: 996,
    alturaViewport: 498,
    larguraCartao: 470,
    alturaCartao: 272,
  });
  const cartao = { left: posicao.esquerda, top: posicao.topo, right: posicao.esquerda + 470, bottom: posicao.topo + 272 };

  assert.equal(areaInterseccao(cartao, explicacao), 0);
  assert.ok(cartao.left >= 16 && cartao.right <= 980);
  assert.ok(cartao.top >= 16 && cartao.bottom <= 482);
});

test("aproveita uma área vazia entre conteúdos protegidos de um painel grande", () => {
  const alvo = { left: 100, top: 80, right: 1500, bottom: 820 };
  const conteudos = [
    { left: 130, top: 120, right: 620, bottom: 360 },
    { left: 130, top: 500, right: 620, bottom: 760 },
  ];
  const posicao = calcularPosicaoCartaoTour(alvo, conteudos, {
    larguraViewport: 1600,
    alturaViewport: 900,
    larguraCartao: 470,
    alturaCartao: 300,
  });
  const cartao = { left: posicao.esquerda, top: posicao.topo, right: posicao.esquerda + 470, bottom: posicao.topo + 300 };

  assert.equal(conteudos.reduce((total, area) => total + areaInterseccao(cartao, area), 0), 0);
  assert.ok(cartao.left >= 620);
});
