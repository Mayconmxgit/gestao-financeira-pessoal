export type Usuario = { id: string; nome: string; email: string; papel: "usuario" | "administrador" };

export type PlanoAssinatura = {
  id: string; nome: string; descricao: string | null; valor: string; moeda: string;
  periodicidade: "mensal" | "anual"; intervalo: number; recursos: string[]; ativo: boolean;
};

export type Assinatura = {
  id: string; usuario_nome: string | null; usuario_email: string | null; plano_nome: string | null;
  provedor: "mercado_pago" | "cortesia" | "gratuito"; situacao: string; valor: string; moeda: string; periodicidade: string;
  recursos: string[];
  proxima_cobranca_em: string | null; periodo_acesso_fim: string | null; criada_em: string;
};

export type ResumoAssinaturas = {
  planos_ativos: number; assinaturas_ativas: number; assinaturas_pendentes: number;
  assinaturas_inadimplentes: number; receita_mensal_estimada: string;
};

export type EventoAssinatura = {
  id: string; tipo: string; acao: string | null; situacao: string;
  codigo_erro: string | null; recebido_em: string; processado_em: string | null;
};

export type Conta = {
  id: string; nome: string; tipo: string; moeda: string; saldo_inicial: string;
  instituicao: string | null; situacao: "ativa" | "arquivada";
};

export type Categoria = {
  id: string; nome: string; tipo: "receita" | "despesa"; limite_gastos: string | null;
};

export type Transacao = {
  id: string; conta_id: string; categoria_id: string | null; lote_id: string | null;
  transacao_relacionada_id: string | null; data_efetiva: string; valor: string;
  moeda: string; descricao_original: string; natureza: string;
  situacao: string; origem: string; editavel?: boolean;
};

export type PagamentoRecorrente = {
  id: string; conta_id: string; categoria_id: string | null; descricao: string; valor: string;
  moeda: "BRL"; periodicidade: "semanal" | "mensal" | "anual";
  primeiro_vencimento: string; proximo_vencimento: string | null; data_fim: string | null;
  situacao: "ativo" | "pausado" | "encerrado"; conta_nome: string; categoria_nome: string | null;
};

export type AporteMeta = {
  id: string; conta_id: string | null; transacao_id: string | null; valor: string; data: string;
  observacao: string | null; conta_nome: string | null; conta_instituicao: string | null;
};

export type MetaFinanceira = {
  id: string; nome: string; valor_objetivo: string; valor_acumulado: string;
  percentual_concluido: string; moeda: "BRL"; prazo: string | null;
  situacao: "ativa" | "pausada" | "concluida"; aportes: AporteMeta[];
};

export type Totais = {
  saldo_inicial: string; saldo_final: string; entradas: string; saidas: string;
  receitas: string; despesas: string; transferencias: string; ajustes: string;
  pendentes: number;
};

export type Resumo = {
  inicio: string; fim: string; consolidado: Totais;
  contas: Array<Totais & { conta_id: string; nome: string; tipo: string; moeda: string }>;
  despesas_por_categoria: Array<{
    categoria_id: string; nome: string; total: string; percentual: string;
    limite_gastos: string | null; percentual_limite: string | null; limite_ultrapassado: boolean;
  }>;
  dividas?: {
    endividamento_total: string; parcelas_mensais_dividas: string; saldo_devedor_total: string;
    juros_futuros_estimados: string; custo_total_dividas: string;
    percentual_renda_comprometida: string | null; quantidade_contratos_ativos: number;
  };
  compromissos_proximo_mes?: {
    inicio: string; fim: string; moeda: string; parcelas_emprestimos: string;
    pagamentos_recorrentes: string; lancamentos_futuros: string; total: string;
    itens: Array<{
      tipo: "parcela_emprestimo" | "pagamento_recorrente" | "lancamento_futuro";
      descricao: string; data: string; valor: string;
    }>;
  };
};

export type Lote = {
  id: string; conta_id: string; instituicao: string | null; nome_arquivo: string;
  formato: string; situacao: string; periodo_inicio: string | null;
  periodo_fim: string | null; linhas_lidas: number; linhas_importadas: number;
  linhas_ignoradas: number; linhas_invalidas: number;
};

export type LinhaImportacao = {
  id: string; numero: number; data_efetiva: string | null; valor: string | null;
  descricao_original: string | null; erro: string | null;
  identificador_bancario: string | null; documento: string | null;
  situacao_previa: "pronta" | "invalida" | "duplicada_confirmada" | "possivel_duplicidade";
  transacao_candidata_id: string | null; linha_candidata_id: string | null;
};

export type Previa = {
  lote: Lote; total_entradas: string; total_saidas: string; linhas: LinhaImportacao[];
};

export type ParcelaEmprestimo = {
  id: string; numero: number; vencimento: string; principal: string; juros: string;
  taxas: string; amortizacao: string; saldo_inicial: string; saldo_final: string; valor_total: string;
  valor_pago: string | null; juros_pago: string | null; amortizacao_paga: string | null;
  situacao: "pendente" | "paga" | "quitada_antecipadamente";
  situacao_exibicao: "pendente" | "atrasada" | "paga" | "quitada_antecipadamente" | "nao_se_aplica";
  data_pagamento: string | null; conta_pagamento_id: string | null;
};

export type Emprestimo = {
  id: string; conta_recebimento_id: string; credor: string; descricao: string | null;
  moeda: string; tipo_divida: "emprestimo_pessoal" | "emprestimo_consignado" | "financiamento" | "parcelamento" | "renegociacao";
  valor_principal: string; valor_recebido: string; valor_financiado: string; iof: string; taxas_totais: string;
  taxa_juros_mensal: string; taxa_juros_anual: string | null; cet_mensal: string | null; cet_anual: string | null;
  sistema_amortizacao: "price" | "sac" | "manual"; quantidade_parcelas: number;
  valor_parcela_contratada: string | null; total_contratado: string | null; afeta_saldo_recebimento: boolean;
  data_contratacao: string | null; data_recebimento: string; data_base_calculo: string | null;
  primeiro_vencimento: string; ultimo_vencimento: string | null; situacao: "ativo" | "quitado" | "atrasado" | "renegociado" | "cancelado";
  situacao_exibicao: "ativo" | "quitado" | "atrasado" | "renegociado" | "cancelado";
  quitado_em: string | null; valor_total_projetado: string; total_previsto: string; custo_total_credito: string;
  percentual_custo_sobre_valor_recebido: string | null; saldo_devedor: string; saldo_financiado: string;
  total_pendente: string; total_pago: string; juros_pagos: string; juros_futuros_estimados: string;
  valor_total: string; amortizacao_acumulada: string; parcelas_pagas: number; parcelas_pendentes: number; parcelas_restantes: number; parcelas_nao_aplicaveis: number; parcelas_acompanhadas: number; percentual_concluido: string;
  proxima_parcela: ParcelaEmprestimo | null; aviso_projecao: string; parcelas?: ParcelaEmprestimo[];
};
