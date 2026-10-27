"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  ArrowRightLeft, BarChart3, ChartNoAxesCombined, ChevronDown, CircleHelp, CreditCard,
  Crown, HandCoins, Landmark, LayoutDashboard, LogOut, Menu, PanelLeftClose, PlayCircle, Settings2, UploadCloud, X,
} from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { EscolhaPlanoInicial } from "@/components/escolha-plano-inicial";
import { possuiRecurso, ProvedorRecursosPlano, RECURSOS_PLANO, type RecursoPlano } from "@/components/acesso-recursos-plano";
import { Aviso, Botao } from "@/components/interface";
import { TourAplicacao, type EtapaTour } from "@/components/tour-aplicacao";
import { useDados } from "@/hooks/use-dados";
import type { Assinatura } from "@/lib/tipos";

const navegacao = [
  { href: "/visao-geral", rotulo: "Visão geral", descricao: "Esta é a central de acompanhamento da sua vida financeira.", funcionalidades: ["Consultar saldos, entradas e saídas do período.", "Visualizar gastos por categoria e faturas dos cartões.", "Acompanhar contas, limites e gastos previstos do próximo mês."], Icone: LayoutDashboard, recurso: RECURSOS_PLANO.VISAO_GERAL },
  { href: "/relatorios", rotulo: "Relatórios", descricao: "Use os relatórios para entender a evolução dos seus gastos.", funcionalidades: ["Analisar períodos de 30 dias, 6 meses ou 12 meses.", "Comparar categorias, contas e cartões.", "Comparar dois meses e consultar gráficos e tabelas detalhadas."], Icone: BarChart3, recurso: RECURSOS_PLANO.VISAO_GERAL },
  { href: "/contas", rotulo: "Minhas contas", descricao: "Aqui ficam suas contas bancárias, dinheiro e poupança.", funcionalidades: ["Cadastrar e editar contas.", "Informar ou ajustar o saldo gerencial.", "Arquivar contas sem apagar o histórico financeiro."], Icone: Landmark, recurso: RECURSOS_PLANO.CONTAS_MOVIMENTACOES },
  { href: "/cartoes", rotulo: "Cartões", descricao: "Gerencie cartões de crédito separadamente das contas bancárias.", funcionalidades: ["Cadastrar cartões, limites, fechamento e vencimento.", "Registrar compras e consultar faturas.", "Pagar, adiantar ou estornar valores mantendo o histórico."], Icone: CreditCard, recurso: RECURSOS_PLANO.CONTAS_MOVIMENTACOES },
  { href: "/movimentacoes", rotulo: "Movimentações", descricao: "Consulte e organize tudo o que entrou ou saiu das suas contas.", funcionalidades: ["Registrar entradas, saídas e transferências.", "Filtrar e classificar lançamentos por categoria.", "Criar categorias e definir limites mensais de gastos."], Icone: ArrowRightLeft, recurso: RECURSOS_PLANO.CONTAS_MOVIMENTACOES },
  { href: "/emprestimos", rotulo: "Empréstimos", descricao: "Acompanhe contratos e pagamentos de empréstimos.", funcionalidades: ["Cadastrar contratos PRICE, SAC ou com total combinado.", "Consultar parcelas, juros e saldo devedor.", "Registrar pagamentos ou quitar o saldo antecipadamente."], Icone: HandCoins, recurso: RECURSOS_PLANO.EMPRESTIMOS },
  { href: "/importacoes", rotulo: "Importar extrato", descricao: "Transforme arquivos de extrato em movimentações revisadas.", funcionalidades: ["Enviar arquivos OFX, CSV ou PDF compatível.", "Revisar duplicidades e corrigir lançamentos antes de confirmar.", "Consultar o histórico e reverter uma importação preservando a auditoria."], Icone: UploadCloud, recurso: RECURSOS_PLANO.IMPORTACAO_EXTRATOS },
];

const CHAVE_TOUR_CONCLUIDO = "vida-financeira:tour-principal:v1";

function chaveTourUsuario(usuarioId: string) {
  return `${CHAVE_TOUR_CONCLUIDO}:${usuarioId}`;
}

const recursosPorRota: Record<string, RecursoPlano> = Object.fromEntries(
  navegacao.map(({ href, recurso }) => [href, recurso]),
) as Record<string, RecursoPlano>;

const fecharModal = "?.modal-cabecalho button[aria-label=\"Fechar\"]";

function etapasInternasDaRota(href: string, recursos: string[] | null): EtapaTour[] {
  const etapa = (dados: Omit<EtapaTour, "href">): EtapaTour => ({ ...dados, href });

  switch (href) {
    case "/visao-geral":
      return [
        etapa({ titulo: "Filtros da visão geral", descricao: "Escolha o mês que deseja analisar. O botão de visibilidade oculta ou mostra todos os valores sensíveis da tela.", seletor: ".cabecalho-acoes-visao-geral" }),
        etapa({ titulo: "Resumo financeiro", descricao: "Estes cartões mostram o saldo consolidado e os totais de entradas, saídas, faturas e valores previstos no período selecionado.", seletor: ".metricas-grade" }),
        etapa({ titulo: "Gastos por categoria", descricao: "Use este gráfico para identificar quais categorias mais consomem seu orçamento. O link leva aos lançamentos que formam os valores.", seletor: ".bloco-grafico" }),
        etapa({ titulo: "Acessos rápidos", descricao: "Estes atalhos levam diretamente aos cadastros e consultas mais usados da aplicação.", seletor: ".bloco-acoes" }),
        etapa({ titulo: "Próximos compromissos", descricao: "Aqui aparecem recorrências e parcelas previstas para o próximo mês, ajudando a antecipar o impacto no saldo.", seletor: ".historico-previstos" }),
        etapa({ titulo: "Resumo das contas", descricao: "Consulte o saldo de cada conta e use Gerenciar para abrir os detalhes das suas contas.", seletor: ".dashboard-conta-unica" }),
      ];
    case "/relatorios":
      return [
        etapa({ titulo: "Tipos de relatório", descricao: "Alterne entre Evolução, para acompanhar tendências, e Comparação, para confrontar dois meses.", alvo: "relatorios-modos" }),
        etapa({ titulo: "Filtros da evolução", descricao: "Selecione o período e, quando necessário, restrinja o resultado por conta, cartão ou categoria.", funcionalidades: ["30 dias detalha o período recente.", "6 e 12 meses mostram tendências mensais.", "Ocultar valores protege a visualização sem alterar os dados."], acoes: ['[data-tour="relatorios-evolucao"]'], alvo: "relatorios-filtros" }),
        etapa({ titulo: "Leitura da evolução", descricao: "Os gráficos e tabelas exibem entradas, saídas, saldo e composição dos gastos conforme os filtros escolhidos.", alvo: "relatorios-painel-evolucao" }),
        etapa({ titulo: "Comparar períodos", descricao: "Escolha dois meses para ver a variação total e quais categorias aumentaram ou diminuíram.", acoes: ['[data-tour="relatorios-comparacao"]'], alvo: "relatorios-filtros" }),
        etapa({ titulo: "Resultado da comparação", descricao: "Consulte os totais lado a lado e use as diferenças para ajustar seu planejamento.", alvo: "relatorios-painel-comparacao" }),
      ];
    case "/contas":
      return [
        etapa({ titulo: "Suas contas", descricao: "Cada cartão representa uma conta. Abra uma conta para editar seus dados ou arquive-a sem perder o histórico.", acoes: [fecharModal], alvo: "contas-lista" }),
        etapa({ titulo: "Como cadastrar uma conta", descricao: "O formulário foi aberto apenas para demonstração. Preencha os dados e use Salvar somente quando quiser concluir o cadastro.", funcionalidades: ["Informe nome, tipo e moeda.", "Defina o saldo inicial com a data correta.", "Instituição e observações são opcionais."], acoes: [fecharModal, '[data-tour="contas-adicionar"]'], alvo: "contas-formulario" }),
      ];
    case "/cartoes": {
      const recorrencias = possuiRecurso(recursos, RECURSOS_PLANO.PAGAMENTOS_RECORRENTES) ? [
        etapa({ titulo: "Compras recorrentes no cartão", descricao: "Esta aba reúne cobranças que se repetem automaticamente no planejamento.", acoes: [fecharModal, '[data-tour="cartoes-aba-recorrentes"]'], alvo: "cartoes-painel-recorrentes" }),
        etapa({ titulo: "Como cadastrar uma recorrência", descricao: "Informe descrição, valor, categoria, frequência e o cartão. A aplicação projetará as próximas cobranças.", funcionalidades: ["Use a data inicial como referência do ciclo.", "Defina uma data final apenas se a cobrança tiver término.", "Revise o cartão antes de salvar."], acoes: [fecharModal, '[data-tour="cartoes-nova-recorrencia"]'], alvo: "cartoes-recorrencia-formulario" }),
      ] : [];
      return [
        etapa({ titulo: "Áreas dos cartões", descricao: "Use as abas para alternar entre cartões cadastrados, histórico de compras e cobranças recorrentes.", acoes: [fecharModal], alvo: "cartoes-abas" }),
        etapa({ titulo: "Cartões cadastrados", descricao: "Consulte limite, fechamento, vencimento e fatura. Abra um cartão para editar ou executar ações permitidas.", acoes: [fecharModal, '[data-tour="cartoes-aba-lista"]'], alvo: "cartoes-painel-cartoes" }),
        etapa({ titulo: "Como cadastrar um cartão", descricao: "O formulário foi aberto para mostrar todos os campos; o tour não salvará nada.", funcionalidades: ["Informe nome, bandeira e os quatro últimos dígitos.", "Defina limite, dia de fechamento e vencimento.", "Vincule uma conta pagadora agora ou somente ao pagar a fatura."], acoes: [fecharModal, '[data-tour="cartoes-adicionar"]'], alvo: "cartoes-formulario" }),
        etapa({ titulo: "Histórico de compras", descricao: "Filtre e consulte compras, parcelas, faturas e operações anteriores do cartão.", acoes: [fecharModal, '[data-tour="cartoes-aba-historico"]'], alvo: "cartoes-painel-historico" }),
        etapa({ titulo: "Como registrar uma compra", descricao: "Selecione o cartão e preencha descrição, valor, categoria, data e quantidade de parcelas antes de salvar.", funcionalidades: ["Compras parceladas são distribuídas nas próximas faturas.", "Confira a data para usar o ciclo correto.", "O tour não confirma o lançamento."], acoes: [fecharModal, '[data-tour="cartoes-nova-compra"]'], alvo: "cartoes-compra-formulario" }),
        ...recorrencias,
      ];
    }
    case "/movimentacoes": {
      const recorrencias = possuiRecurso(recursos, RECURSOS_PLANO.PAGAMENTOS_RECORRENTES) ? [
        etapa({ titulo: "Pagamentos recorrentes", descricao: "Use esta aba para consultar compromissos repetidos e suas próximas ocorrências.", acoes: [fecharModal, '[data-tour="movimentacoes-aba-recorrentes"]'], alvo: "movimentacoes-painel-recorrentes" }),
        etapa({ titulo: "Como cadastrar um pagamento recorrente", descricao: "Informe conta, descrição, valor, categoria, frequência e vigência. As próximas ocorrências serão projetadas automaticamente.", acoes: [fecharModal, '[data-tour="movimentacoes-nova-recorrencia"]'], alvo: "movimentacoes-recorrencia-formulario" }),
      ] : [];
      const metas = possuiRecurso(recursos, RECURSOS_PLANO.METAS_FINANCEIRAS) ? [
        etapa({ titulo: "Metas financeiras", descricao: "Acompanhe objetivos, valor acumulado, prazo e progresso nesta aba.", acoes: [fecharModal, '[data-tour="movimentacoes-aba-metas"]'], alvo: "movimentacoes-painel-metas" }),
        etapa({ titulo: "Como cadastrar uma meta", descricao: "Defina nome, valor desejado e prazo opcional. Depois, atualize o progresso registrando aportes.", acoes: [fecharModal, '[data-tour="metas-adicionar"]'], alvo: "metas-formulario" }),
      ] : [];
      return [
        etapa({ titulo: "Áreas das movimentações", descricao: "As abas organizam histórico, recorrências, metas e categorias conforme os recursos do seu plano.", acoes: [fecharModal], alvo: "movimentacoes-abas" }),
        etapa({ titulo: "Histórico de movimentações", descricao: "Consulte entradas e saídas, aplique filtros e abra um item para editar, cancelar ou estornar quando permitido.", acoes: [fecharModal, '[data-tour="movimentacoes-aba-historico"]'], alvo: "movimentacoes-painel-movimentacoes" }),
        etapa({ titulo: "Como registrar uma movimentação", descricao: "Escolha entrada ou saída, informe conta, descrição, valor, categoria e data. Revise tudo antes de salvar.", funcionalidades: ["A conta define qual saldo será afetado.", "A categoria melhora relatórios e limites.", "O tour não confirma o lançamento."], acoes: [fecharModal, '[data-tour="movimentacoes-novo-lancamento"]'], alvo: "movimentacoes-formulario" }),
        etapa({ titulo: "Como fazer uma transferência", descricao: "Selecione contas de origem e destino, valor e data. Ao salvar, a aplicação registra os dois lados da transferência.", acoes: [fecharModal, '[data-tour="movimentacoes-transferir"]'], alvo: "transferencia-formulario" }),
        ...recorrencias,
        ...metas,
        etapa({ titulo: "Categorias", descricao: "Crie categorias para classificar lançamentos e, quando disponível, definir limites mensais de gasto.", acoes: [fecharModal, '[data-tour="movimentacoes-aba-categorias"]'], alvo: "movimentacoes-painel-categorias" }),
        etapa({ titulo: "Como cadastrar uma categoria", descricao: "Informe nome, tipo e limite mensal opcional para despesas. Depois, selecione a categoria nos lançamentos correspondentes.", acoes: [fecharModal, '[data-tour="categorias-adicionar"]'], alvo: "categorias-formulario" }),
      ];
    }
    case "/emprestimos":
      return [
        etapa({ titulo: "Contratos de empréstimo", descricao: "Consulte parcelas, juros, pagamentos e saldo devedor. Abra um contrato para registrar pagamento ou quitação.", acoes: [fecharModal], seletor: ".grade-emprestimos, .nota-informativa" }),
        etapa({ titulo: "Como cadastrar um empréstimo", descricao: "O formulário mostra os dados necessários para montar o cronograma sem registrar nada durante o tour.", funcionalidades: ["Informe credor, conta de recebimento e valores.", "Escolha PRICE, SAC ou total combinado.", "Defina parcelas, juros e datas; revise se o recebimento afetará o saldo."], acoes: [fecharModal, '[data-tour="emprestimos-adicionar"]'], alvo: "emprestimos-formulario" }),
      ];
    case "/importacoes":
      return [
        etapa({ titulo: "Etapas da importação", descricao: "O processo separa envio, revisão e confirmação para impedir que dados entrem sem conferência.", alvo: "importacoes-etapas" }),
        etapa({ titulo: "Como importar um extrato", descricao: "Selecione a conta, a instituição e um arquivo compatível. Depois, gere a prévia e revise cada linha antes de confirmar.", funcionalidades: ["OFX e CSV são os formatos preferenciais; PDF depende do modelo compatível.", "Possíveis duplicidades ficam destacadas.", "Somente a confirmação final cria movimentações."], alvo: "importacoes-formulario" }),
        etapa({ titulo: "Histórico de importações", descricao: "Consulte lotes já processados e reverta uma importação quando necessário, preservando o registro de auditoria.", alvo: "importacoes-historico" }),
      ];
    default:
      return [];
  }
}

export function EstruturaApp({ children }: { children: React.ReactNode }) {
  const { usuario, carregando, erro, recarregar, sair } = useAutenticacao();
  const [menuAberto, definirMenuAberto] = useState(false);
  const [menuRecolhido, definirMenuRecolhido] = useState(false);
  const [saindo, definirSaindo] = useState(false);
  const [tourAtivo, definirTourAtivo] = useState(false);
  const [etapaTour, definirEtapaTour] = useState(0);
  const tourUsuarioAtual = useRef<string | null>(null);
  const caminho = usePathname();
  const roteador = useRouter();

  useEffect(() => { if (!carregando && !usuario && !erro) roteador.replace("/entrar"); }, [carregando, usuario, erro, roteador]);

  useEffect(() => {
    if (!usuario) {
      tourUsuarioAtual.current = null;
      return;
    }
    if (tourUsuarioAtual.current === usuario.id) return;
    tourUsuarioAtual.current = usuario.id;

    try {
      if (localStorage.getItem(chaveTourUsuario(usuario.id)) !== "concluido") {
        const temporizador = window.setTimeout(() => {
          definirMenuRecolhido(false);
          definirMenuAberto(true);
          definirTourAtivo(true);
        }, 0);
        return () => window.clearTimeout(temporizador);
      }
    } catch {
      // O tour permanece disponível pelo botão se o armazenamento local estiver bloqueado.
    }
  }, [usuario]);

  if (carregando) return <div className="tela-carregando"><span className="marca-simbolo"><ChartNoAxesCombined size={24} /></span><p>Preparando seu espaço...</p></div>;
  if (erro) return <div className="tela-carregando"><h2>Não foi possível conectar.</h2><p>Abra o iniciador da aplicação e tente novamente.</p><Botao onClick={recarregar}>Tentar novamente</Botao></div>;
  if (!usuario) return null;
  const usuarioId = usuario.id;

  async function encerrar() {
    definirSaindo(true);
    try { await sair(); } finally { definirSaindo(false); }
  }

  function iniciarTour() {
    definirEtapaTour(0);
    definirMenuRecolhido(false);
    definirMenuAberto(true);
    definirTourAtivo(true);
  }

  function concluirTour() {
    definirTourAtivo(false);
    definirMenuAberto(false);
    try { localStorage.setItem(chaveTourUsuario(usuarioId), "concluido"); } catch { /* armazenamento opcional */ }
  }

  return <AcessoComPlano usuarioAdministrador={usuario.papel === "administrador"} sair={sair}>
    {(recursos) => {
      const opcoesDisponiveis = navegacao.filter(({ recurso }) => possuiRecurso(recursos, recurso));
      const etapasTour: EtapaTour[] = [
        { titulo: "Conheça seu espaço financeiro", descricao: "O tour abrirá cada opção do menu e mostrará como consultar, cadastrar e usar seus recursos. Nenhum formulário será salvo automaticamente." },
        ...opcoesDisponiveis.flatMap(({ href, rotulo, descricao, funcionalidades }) => [
          { titulo: rotulo, descricao, funcionalidades, alvo: href, href },
          ...etapasInternasDaRota(href, recursos),
        ]),
        { titulo: "Assinatura", descricao: "Consulte e gerencie o acesso ao serviço.", funcionalidades: ["Ver o plano atual, situação e próxima cobrança.", "Conferir os recursos incluídos.", "Conhecer e selecionar outras modalidades disponíveis."], alvo: "/assinatura", href: "/assinatura" },
        { titulo: "Seu plano atual", descricao: "Aqui você confere modalidade, valor, situação, próxima cobrança e período de acesso. Se não houver assinatura ativa, a tela informa isso claramente.", alvo: "assinatura-atual", href: "/assinatura" },
        { titulo: "Planos disponíveis", descricao: "Compare preço, periodicidade e recursos. A contratação só começa quando você selecionar conscientemente o botão do plano; o tour não fará isso.", alvo: "assinatura-planos", href: "/assinatura" },
        { titulo: "Pagamento protegido", descricao: "Quando houver pagamento, os dados do cartão são enviados diretamente ao provedor e não ficam armazenados na Vida Financeira.", alvo: "assinatura-seguranca", href: "/assinatura" },
        { titulo: "Configurações", descricao: "Gerencie seus dados e suas escolhas de privacidade.", funcionalidades: ["Consultar os dados básicos da conta.", "Exportar uma cópia das informações em JSON.", "Solicitar a exclusão da conta com confirmação de senha."], alvo: "/configuracoes", href: "/configuracoes" },
        { titulo: "Dados do acesso", descricao: "Confira o nome e o e-mail associados à sua conta.", alvo: "config-dados", href: "/configuracoes" },
        { titulo: "Exportar seus dados", descricao: "Use Exportar para baixar uma cópia em JSON de contas, movimentações, importações e dados da conta. O tour não inicia o download.", alvo: "config-exportar", href: "/configuracoes" },
        { titulo: "Excluir a conta", descricao: "Esta opção apaga permanentemente os dados financeiros. Ela exige senha e a palavra EXCLUIR; o tour nunca abre nem confirma essa operação.", alvo: "config-excluir", href: "/configuracoes" },
        { titulo: "Sair com segurança", descricao: "Use este botão ao terminar, principalmente em um computador compartilhado.", alvo: "config-sair", href: "/configuracoes" },
        { titulo: "Tour sempre disponível", descricao: "Quando quiser rever estas explicações, use o botão Fazer tour nesta área de ajuda.", alvo: "ajuda" },
      ];
      const irParaEtapaTour = (indice: number) => {
        const proximaEtapa = Math.max(0, Math.min(indice, etapasTour.length - 1));
        const etapaDestino = etapasTour[proximaEtapa];
        const destino = etapaDestino.href;
        if (destino && destino !== caminho) roteador.push(destino);
        definirMenuAberto(Boolean(etapaDestino.alvo?.startsWith("/")) || etapaDestino.alvo === "ajuda");
        definirEtapaTour(proximaEtapa);
      };
      const avancarTour = () => etapaTour >= etapasTour.length - 1 ? concluirTour() : irParaEtapaTour(etapaTour + 1);

      return <ProvedorRecursosPlano recursos={recursos}><div className={`estrutura-app ${menuRecolhido ? "menu-lateral-recolhido" : ""} ${tourAtivo ? "tour-ativo" : ""}`}>
    {menuAberto && <button className="fundo-menu-mobile" aria-label="Fechar menu" onClick={() => definirMenuAberto(false)} />}
    <aside id="menu-lateral" className={`barra-lateral ${menuAberto ? "barra-lateral-aberta" : ""}`} aria-hidden={(menuRecolhido || tourAtivo) || undefined} inert={menuRecolhido || undefined}>
      <div className="barra-topo"><Link className="marca" href="/visao-geral" onClick={() => definirMenuAberto(false)}><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link>
        <button className="botao-icone alternar-menu-lateral" onClick={() => definirMenuRecolhido(true)} aria-label="Esconder menu lateral" title="Esconder menu lateral"><PanelLeftClose size={19} /></button>
        <button className="botao-icone fechar-menu" onClick={() => definirMenuAberto(false)} aria-label="Fechar menu"><X size={20} /></button></div>
      <div className="barra-secao"><span className="barra-rotulo">MENU PRINCIPAL</span><nav aria-label="Principal">{opcoesDisponiveis.map(({ href, rotulo, Icone }) =>
        <Link key={href} href={href} data-tour={href} onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === href ? "ativo" : ""}`}><Icone size={19} strokeWidth={1.9} />{rotulo}</Link>)}</nav></div>
      <div className="barra-inferior"><span className="barra-rotulo">SUA CONTA</span>
        <Link href="/assinatura" data-tour="/assinatura" onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === "/assinatura" ? "ativo" : ""}`}><Crown size={19} /> Assinatura</Link>
        <Link href="/configuracoes" data-tour="/configuracoes" onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === "/configuracoes" ? "ativo" : ""}`}><Settings2 size={19} /> Configurações</Link>
        <div className="barra-ajuda" data-tour="ajuda"><CircleHelp size={19} /><div><strong>Precisa de ajuda?</strong><span>Reveja o funcionamento das opções.</span><button type="button" onClick={iniciarTour}><PlayCircle size={14} /> Fazer tour</button></div></div>
        <button className="barra-usuario" onClick={encerrar} disabled={saindo} title="Sair da conta">
          <span className="avatar">{usuario.nome.slice(0, 1).toUpperCase()}</span><span className="barra-usuario-info"><strong>{usuario.nome}</strong><small>{usuario.email}</small></span><LogOut size={17} />
        </button>
      </div>
    </aside>
    <div className="conteudo-app" aria-hidden={tourAtivo || undefined}><header className="barra-superior">
      <div className="barra-superior-esquerda"><button className="botao-icone abrir-menu" onClick={() => { definirMenuRecolhido(false); definirMenuAberto(true); }} aria-label="Mostrar menu lateral" title="Mostrar menu lateral" aria-controls="menu-lateral"><Menu size={22} /></button><span className="barra-local">Espaço pessoal <ChevronDown size={14} /></span></div>
      <div className="barra-superior-direita"><span className="barra-data">{new Intl.DateTimeFormat("pt-BR", { day: "numeric", month: "long", year: "numeric" }).format(new Date())}</span><span className="avatar avatar-pequeno">{usuario.nome.slice(0, 1).toUpperCase()}</span></div>
    </header><main className="conteudo-principal">{recursoPermitido(caminho, recursos) ? children : <RecursoBloqueado />}</main></div>
    {tourAtivo && <TourAplicacao etapas={etapasTour} etapaAtual={etapaTour} voltar={() => irParaEtapaTour(etapaTour - 1)} avancar={avancarTour} encerrar={concluirTour} />}
  </div></ProvedorRecursosPlano>;
    }}
  </AcessoComPlano>;
}

function recursoPermitido(caminho: string, recursos: string[] | null) {
  const rota = Object.keys(recursosPorRota).find((item) => caminho === item || caminho.startsWith(`${item}/`));

  return !rota || possuiRecurso(recursos, recursosPorRota[rota]);
}

function RecursoBloqueado() {
  return <div className="tela-carregando"><h2>Recurso não incluído no seu plano.</h2><p>Consulte os planos disponíveis para liberar este recurso.</p><Link className="botao" href="/assinatura"><Crown size={17} /> Ver planos</Link></div>;
}

function AcessoComPlano({ usuarioAdministrador, sair, children }: {
  usuarioAdministrador: boolean;
  sair: () => Promise<void>;
  children: (recursos: string[] | null) => React.ReactNode;
}) {
  const assinatura = useDados<{ assinatura: Assinatura | null }>(usuarioAdministrador ? null : "financeiro/assinatura");
  if (usuarioAdministrador) return children(null);
  if (assinatura.carregando) return <div className="tela-carregando"><p>Verificando seu plano...</p></div>;
  if (assinatura.erro) return <div className="tela-carregando"><Aviso>{assinatura.erro}</Aviso><Botao onClick={assinatura.recarregar}>Tentar novamente</Botao></div>;
  const situacao = assinatura.dados?.assinatura?.situacao;
  if (situacao === "ativa" || situacao === "periodo_graca") return children(assinatura.dados?.assinatura?.recursos ?? []);
  return <EscolhaPlanoInicial concluir={assinatura.recarregar} sair={sair} />;
}
