"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import {
  ArrowRightLeft, ChartNoAxesCombined, ChevronDown, CircleHelp, CreditCard,
  Crown, HandCoins, LayoutDashboard, LogOut, Menu, Settings2, UploadCloud, X,
} from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { EscolhaPlanoInicial } from "@/components/escolha-plano-inicial";
import { possuiRecurso, ProvedorRecursosPlano, RECURSOS_PLANO, type RecursoPlano } from "@/components/acesso-recursos-plano";
import { Aviso, Botao } from "@/components/interface";
import { useDados } from "@/hooks/use-dados";
import type { Assinatura } from "@/lib/tipos";

const navegacao = [
  { href: "/visao-geral", rotulo: "Visão geral", Icone: LayoutDashboard, recurso: RECURSOS_PLANO.VISAO_GERAL },
  { href: "/contas", rotulo: "Minhas contas", Icone: CreditCard, recurso: RECURSOS_PLANO.CONTAS_MOVIMENTACOES },
  { href: "/movimentacoes", rotulo: "Movimentações", Icone: ArrowRightLeft, recurso: RECURSOS_PLANO.CONTAS_MOVIMENTACOES },
  { href: "/emprestimos", rotulo: "Empréstimos", Icone: HandCoins, recurso: RECURSOS_PLANO.EMPRESTIMOS },
  { href: "/importacoes", rotulo: "Importar extrato", Icone: UploadCloud, recurso: RECURSOS_PLANO.IMPORTACAO_EXTRATOS },
];

const recursosPorRota: Record<string, RecursoPlano> = Object.fromEntries(
  navegacao.map(({ href, recurso }) => [href, recurso]),
) as Record<string, RecursoPlano>;

export function EstruturaApp({ children }: { children: React.ReactNode }) {
  const { usuario, carregando, erro, recarregar, sair } = useAutenticacao();
  const [menuAberto, definirMenuAberto] = useState(false);
  const [saindo, definirSaindo] = useState(false);
  const caminho = usePathname();
  const roteador = useRouter();

  useEffect(() => { if (!carregando && !usuario && !erro) roteador.replace("/entrar"); }, [carregando, usuario, erro, roteador]);

  if (carregando) return <div className="tela-carregando"><span className="marca-simbolo"><ChartNoAxesCombined size={24} /></span><p>Preparando seu espaço...</p></div>;
  if (erro) return <div className="tela-carregando"><h2>Não foi possível conectar.</h2><p>Abra o iniciador da aplicação e tente novamente.</p><Botao onClick={recarregar}>Tentar novamente</Botao></div>;
  if (!usuario) return null;

  async function encerrar() {
    definirSaindo(true);
    try { await sair(); } finally { definirSaindo(false); }
  }

  return <AcessoComPlano usuarioAdministrador={usuario.papel === "administrador"} sair={sair}>
    {(recursos) => <ProvedorRecursosPlano recursos={recursos}><div className="estrutura-app">
    {menuAberto && <button className="fundo-menu-mobile" aria-label="Fechar menu" onClick={() => definirMenuAberto(false)} />}
    <aside className={`barra-lateral ${menuAberto ? "barra-lateral-aberta" : ""}`}>
      <div className="barra-topo"><Link className="marca" href="/visao-geral" onClick={() => definirMenuAberto(false)}><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link>
        <button className="botao-icone fechar-menu" onClick={() => definirMenuAberto(false)} aria-label="Fechar menu"><X size={20} /></button></div>
      <div className="barra-secao"><span className="barra-rotulo">MENU PRINCIPAL</span><nav aria-label="Principal">{navegacao.filter(({ recurso }) => possuiRecurso(recursos, recurso)).map(({ href, rotulo, Icone }) =>
        <Link key={href} href={href} onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === href ? "ativo" : ""}`}><Icone size={19} strokeWidth={1.9} />{rotulo}</Link>)}</nav></div>
      <div className="barra-inferior"><span className="barra-rotulo">SUA CONTA</span>
        <Link href="/assinatura" onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === "/assinatura" ? "ativo" : ""}`}><Crown size={19} /> Assinatura</Link>
        <Link href="/configuracoes" onClick={() => definirMenuAberto(false)} className={`barra-link ${caminho === "/configuracoes" ? "ativo" : ""}`}><Settings2 size={19} /> Configurações</Link>
        <div className="barra-ajuda"><CircleHelp size={19} /><div><strong>Precisa de ajuda?</strong><span>Consulte as orientações em cada tela.</span></div></div>
        <button className="barra-usuario" onClick={encerrar} disabled={saindo} title="Sair da conta">
          <span className="avatar">{usuario.nome.slice(0, 1).toUpperCase()}</span><span className="barra-usuario-info"><strong>{usuario.nome}</strong><small>{usuario.email}</small></span><LogOut size={17} />
        </button>
      </div>
    </aside>
    <div className="conteudo-app"><header className="barra-superior">
      <div className="barra-superior-esquerda"><button className="botao-icone abrir-menu" onClick={() => definirMenuAberto(true)} aria-label="Abrir menu"><Menu size={22} /></button><span className="barra-local">Espaço pessoal <ChevronDown size={14} /></span></div>
      <div className="barra-superior-direita"><span className="barra-data">{new Intl.DateTimeFormat("pt-BR", { day: "numeric", month: "long", year: "numeric" }).format(new Date())}</span><span className="avatar avatar-pequeno">{usuario.nome.slice(0, 1).toUpperCase()}</span></div>
    </header><main className="conteudo-principal">{recursoPermitido(caminho, recursos) ? children : <RecursoBloqueado />}</main></div>
  </div></ProvedorRecursosPlano>}
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
