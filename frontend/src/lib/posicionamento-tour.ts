export type RetanguloTour = {
  left: number;
  top: number;
  right: number;
  bottom: number;
};

type DimensoesTour = {
  larguraViewport: number;
  alturaViewport: number;
  larguraCartao: number;
  alturaCartao: number;
  margem?: number;
  espacamento?: number;
};

export type PosicaoCartaoTour = { esquerda: number; topo: number };

export function calcularPosicaoCartaoTour(
  alvo: RetanguloTour,
  areasProtegidas: RetanguloTour[],
  dimensoes: DimensoesTour,
): PosicaoCartaoTour {
  const margem = dimensoes.margem ?? 16;
  const espacamento = dimensoes.espacamento ?? 16;
  const maximoX = Math.max(margem, dimensoes.larguraViewport - dimensoes.larguraCartao - margem);
  const maximoY = Math.max(margem, dimensoes.alturaViewport - dimensoes.alturaCartao - margem);
  const limitarX = (valor: number) => limitar(valor, margem, maximoX);
  const limitarY = (valor: number) => limitar(valor, margem, maximoY);
  const faixaX = maximoX - margem;
  const faixaY = maximoY - margem;

  const posicoesX = unicos([
    limitarX(alvo.right + espacamento),
    limitarX(alvo.left - dimensoes.larguraCartao - espacamento),
    limitarX(alvo.left + ((alvo.right - alvo.left - dimensoes.larguraCartao) / 2)),
    ...areasProtegidas.flatMap((area) => [
      limitarX(area.right + espacamento),
      limitarX(area.left - dimensoes.larguraCartao - espacamento),
    ]),
    margem,
    margem + (faixaX * .25),
    margem + (faixaX * .5),
    margem + (faixaX * .75),
    maximoX,
  ]);
  const posicoesY = unicos([
    limitarY(alvo.top + ((alvo.bottom - alvo.top - dimensoes.alturaCartao) / 2)),
    limitarY(alvo.bottom + espacamento),
    limitarY(alvo.top - dimensoes.alturaCartao - espacamento),
    ...areasProtegidas.flatMap((area) => [
      limitarY(area.bottom + espacamento),
      limitarY(area.top - dimensoes.alturaCartao - espacamento),
    ]),
    margem,
    margem + (faixaY * .25),
    margem + (faixaY * .5),
    margem + (faixaY * .75),
    maximoY,
  ]);

  let melhor = { esquerda: posicoesX[0], topo: posicoesY[0] };
  let menorPontuacao = Number.POSITIVE_INFINITY;

  for (const esquerda of posicoesX) {
    for (const topo of posicoesY) {
      const cartao = {
        left: esquerda,
        top: topo,
        right: esquerda + dimensoes.larguraCartao,
        bottom: topo + dimensoes.alturaCartao,
      };
      const sobreposicaoAlvo = areaInterseccao(cartao, alvo);
      const sobreposicaoConteudo = areasProtegidas.reduce((total, area) => total + areaInterseccao(cartao, area), 0);
      const distancia = Math.abs((cartao.left + cartao.right) - (alvo.left + alvo.right))
        + Math.abs((cartao.top + cartao.bottom) - (alvo.top + alvo.bottom));
      const pontuacao = (sobreposicaoConteudo * 1000) + sobreposicaoAlvo + (distancia / 1000);

      if (pontuacao < menorPontuacao) {
        menorPontuacao = pontuacao;
        melhor = { esquerda, topo };
      }
    }
  }

  return melhor;
}

export function areaInterseccao(a: RetanguloTour, b: RetanguloTour) {
  const largura = Math.max(0, Math.min(a.right, b.right) - Math.max(a.left, b.left));
  const altura = Math.max(0, Math.min(a.bottom, b.bottom) - Math.max(a.top, b.top));
  return largura * altura;
}

function limitar(valor: number, minimo: number, maximo: number) {
  return Math.min(Math.max(valor, minimo), maximo);
}

function unicos(valores: number[]) {
  return [...new Set(valores.map((valor) => Math.round(valor)))];
}
