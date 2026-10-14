"use client";

import { FormEvent, useState } from "react";
import { CheckCircle2, Edit3, Gift, Plus, RefreshCw, Webhook } from "lucide-react";
import { useDados } from "@/hooks/use-dados";
import { api } from "@/lib/api";
import { formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Assinatura, EventoAssinatura, PlanoAssinatura, ResumoAssinaturas } from "@/lib/tipos";
import { Aviso, Botao, Campo, Cartao, Modal, Seletor } from "@/components/interface";
import { RECURSOS_PLANO } from "@/components/acesso-recursos-plano";

type UsuarioAdmin = { id: string; nome: string; email: string; papel: string };
type Pagina<T, Cursor = number> = { dados: T[]; proximo_cursor: Cursor | null };
type DadosPlano = { nome: string; descricao: string; valor: string; periodicidade: "mensal" | "anual"; recursos: string[]; ativo: boolean };
const recursosDisponiveis = [
  RECURSOS_PLANO.VISAO_GERAL,
  RECURSOS_PLANO.CONTAS_MOVIMENTACOES,
  RECURSOS_PLANO.IMPORTACAO_EXTRATOS,
  RECURSOS_PLANO.EMPRESTIMOS,
  RECURSOS_PLANO.PLANEJAMENTO_FINANCEIRO,
  RECURSOS_PLANO.PAGAMENTOS_RECORRENTES,
  RECURSOS_PLANO.METAS_FINANCEIRAS,
];
const planoVazio: DadosPlano = { nome: "", descricao: "", valor: "", periodicidade: "mensal", recursos: [], ativo: true };
const LIMITE_USUARIOS_CORTESIA = 50;

function corpoPlano(plano: DadosPlano) {
  return {
    nome: plano.nome, descricao: plano.descricao || null, valor: plano.valor,
    periodicidade: plano.periodicidade, recursos: plano.recursos, ativo: plano.ativo,
  };
}

export function PainelAssinaturasAdmin() {
  const resumo = useDados<ResumoAssinaturas>("admin/assinaturas/resumo");
  const planos = useDados<{ dados: PlanoAssinatura[] }>("admin/assinaturas/planos");
  const [situacao, definirSituacao] = useState("");
  const [visao, definirVisao] = useState<"atuais" | "historico">("atuais");
  const assinaturas = useDados<Pagina<Assinatura>>(`admin/assinaturas?limite=30&visao=${visao}${situacao ? `&situacao=${situacao}` : ""}`);
  const eventos = useDados<Pagina<EventoAssinatura>>("admin/assinaturas/eventos?limite=30");
  const usuarios = useDados<Pagina<UsuarioAdmin, string>>(`admin/usuarios?limite=${LIMITE_USUARIOS_CORTESIA}`);
  const [editor, definirEditor] = useState<PlanoAssinatura | "novo" | null>(null);
  const [cortesia, definirCortesia] = useState(false);
  const [mensagem, definirMensagem] = useState<{ texto: string; tipo: "erro" | "sucesso" } | null>(null);
  const [processando, definirProcessando] = useState<string | null>(null);
  const [assinaturasAdicionais, definirAssinaturasAdicionais] = useState<Assinatura[]>([]);
  const [eventosAdicionais, definirEventosAdicionais] = useState<EventoAssinatura[]>([]);
  const [cursorAssinaturas, definirCursorAssinaturas] = useState<number | null | undefined>();
  const [cursorEventos, definirCursorEventos] = useState<number | null | undefined>();

  function atualizarTudo(texto: string) {
    definirAssinaturasAdicionais([]); definirEventosAdicionais([]);
    definirCursorAssinaturas(undefined); definirCursorEventos(undefined);
    resumo.recarregar(); planos.recarregar(); assinaturas.recarregar(); eventos.recarregar();
    definirMensagem({ texto, tipo: "sucesso" });
  }

  async function reconciliar(id: string) {
    definirProcessando(id); definirMensagem(null);
    try { await api(`admin/assinaturas/${id}/reconciliacao`, { method: "POST" }); atualizarTudo("Assinatura reconciliada com o provedor."); }
    catch (falha) { definirMensagem({ texto: mensagemErro(falha), tipo: "erro" }); }
    finally { definirProcessando(null); }
  }

  async function alternarPlano(plano: PlanoAssinatura) {
    if (plano.ativo && !window.confirm(`Desativar o plano ${plano.nome}? Assinaturas existentes serão preservadas.`)) return;
    definirProcessando(`plano-${plano.id}`); definirMensagem(null);
    try {
      await api(`admin/assinaturas/planos/${plano.id}`, {
        method: "PATCH", body: JSON.stringify(corpoPlano({ ...plano, descricao: plano.descricao ?? "", ativo: !plano.ativo })),
      });
      atualizarTudo(plano.ativo ? "Plano desativado para novas assinaturas." : "Plano reativado.");
    } catch (falha) { definirMensagem({ texto: mensagemErro(falha), tipo: "erro" }); }
    finally { definirProcessando(null); }
  }

  async function carregarMaisAssinaturas() {
    const cursor = cursorAssinaturas === undefined ? assinaturas.dados?.proximo_cursor : cursorAssinaturas;
    if (!cursor) return;
    definirProcessando("mais-assinaturas");
    try {
      const pagina = await api<Pagina<Assinatura>>(`admin/assinaturas?limite=30&visao=${visao}&apos=${cursor}${situacao ? `&situacao=${situacao}` : ""}`);
      definirAssinaturasAdicionais((atuais) => [...atuais, ...pagina.dados]); definirCursorAssinaturas(pagina.proximo_cursor);
    } catch (falha) { definirMensagem({ texto: mensagemErro(falha), tipo: "erro" }); }
    finally { definirProcessando(null); }
  }

  async function carregarMaisEventos() {
    const cursor = cursorEventos === undefined ? eventos.dados?.proximo_cursor : cursorEventos;
    if (!cursor) return;
    definirProcessando("mais-eventos");
    try {
      const pagina = await api<Pagina<EventoAssinatura>>(`admin/assinaturas/eventos?limite=30&apos=${cursor}`);
      definirEventosAdicionais((atuais) => [...atuais, ...pagina.dados]); definirCursorEventos(pagina.proximo_cursor);
    } catch (falha) { definirMensagem({ texto: mensagemErro(falha), tipo: "erro" }); }
    finally { definirProcessando(null); }
  }

  const falha = resumo.erro || planos.erro || assinaturas.erro || eventos.erro || usuarios.erro;
  return <div className="admin-assinaturas">
    {(falha || mensagem) && <Aviso tipo={mensagem?.tipo ?? "erro"}>{falha || mensagem?.texto}</Aviso>}
    <div className="metricas-grade admin-metricas"><Metrica titulo="MRR contratado" valor={formatarMoeda(resumo.dados?.receita_mensal_estimada ?? 0)} /><Metrica titulo="Assinaturas ativas" valor={resumo.dados?.assinaturas_ativas ?? "—"} /><Metrica titulo="Pendentes" valor={resumo.dados?.assinaturas_pendentes ?? "—"} /><Metrica titulo="Inadimplentes" valor={resumo.dados?.assinaturas_inadimplentes ?? "—"} /></div>
    <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Planos</h2><p>O preço é copiado para cada contrato e alterações não mudam o histórico.</p></div><Botao onClick={() => definirEditor("novo")}><Plus size={16} /> Novo plano</Botao></div><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Plano</th><th>Preço</th><th>Periodicidade</th><th>Status</th><th>Ações</th></tr></thead><tbody>{planos.dados?.dados.map((plano) => <tr key={plano.id}><td><strong>{plano.nome}</strong></td><td>{formatarMoeda(plano.valor)}</td><td>{plano.periodicidade}</td><td><span className={`situacao situacao-${plano.ativo ? "ativa" : "cancelada"}`}>{plano.ativo ? "Ativo" : "Inativo"}</span></td><td><div className="acoes-linha"><button className="botao-icone" onClick={() => definirEditor(plano)} aria-label={`Editar ${plano.nome}`}><Edit3 size={16} /></button><Botao variante={plano.ativo ? "perigo" : "secundario"} carregando={processando === `plano-${plano.id}`} onClick={() => alternarPlano(plano)}>{plano.ativo ? "Desativar" : "Ativar"}</Botao></div></td></tr>)}</tbody></table></div></Cartao>
    <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>{visao === "atuais" ? "Contratos atuais" : "Histórico completo"}</h2><p>{visao === "atuais" ? "Somente contratos vigentes ou que exigem acompanhamento." : "Registros encerrados são preservados para auditoria."}</p></div><div className="cabecalho-acoes"><div className="admin-segmentado" role="group" aria-label="Visão das assinaturas"><button className={visao === "atuais" ? "ativo" : ""} onClick={() => { definirVisao("atuais"); definirSituacao(""); definirAssinaturasAdicionais([]); }}>Atuais</button><button className={visao === "historico" ? "ativo" : ""} onClick={() => { definirVisao("historico"); definirSituacao(""); definirAssinaturasAdicionais([]); }}>Histórico</button></div><select aria-label="Filtrar situação" value={situacao} onChange={(e) => { definirSituacao(e.target.value); definirAssinaturasAdicionais([]); definirCursorAssinaturas(undefined); }}><option value="">Todas as situações</option><option value="ativa">Ativas</option><option value="pendente">Pendentes</option><option value="inadimplente">Inadimplentes</option>{visao === "historico" && <option value="cancelada">Canceladas</option>}</select><Botao variante="secundario" onClick={() => definirCortesia(true)}><Gift size={16} /> Conceder cortesia</Botao></div></div><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Cliente</th><th>Plano</th><th>Valor</th><th>Situação</th><th>Próxima cobrança</th><th>Ação</th></tr></thead><tbody>{[...(assinaturas.dados?.dados ?? []), ...assinaturasAdicionais].map((item) => <tr key={item.id}><td><strong>{item.usuario_nome}</strong><br /><small>{item.usuario_email}</small></td><td>{item.plano_nome}{item.substituida_por_cortesia && <small className="admin-historico-nota">Substituída por cortesia</small>}</td><td>{formatarMoeda(item.valor)}</td><td><span className={`situacao situacao-${item.situacao}`}>{item.situacao.replaceAll("_", " ")}</span></td><td>{formatarData(item.proxima_cobranca_em)}</td><td>{item.provedor === "mercado_pago" && item.situacao !== "cancelada" ? <Botao variante="texto" carregando={processando === item.id} onClick={() => reconciliar(item.id)}><RefreshCw size={15} /> Reconciliar</Botao> : "—"}</td></tr>)}</tbody></table></div>{(cursorAssinaturas === undefined ? assinaturas.dados?.proximo_cursor : cursorAssinaturas) && <div className="carregar-mais"><Botao variante="texto" carregando={processando === "mais-assinaturas"} onClick={carregarMaisAssinaturas}>Carregar mais</Botao></div>}</Cartao>
    <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Eventos do webhook</h2><p>Monitoramento de processamento e idempotência.</p></div><span className="icone-cabecalho"><Webhook size={21} /></span></div><div className="tabela-rolagem"><table className="tabela"><thead><tr><th>Recebido</th><th>Tipo</th><th>Ação</th><th>Situação</th><th>Erro</th></tr></thead><tbody>{[...(eventos.dados?.dados ?? []), ...eventosAdicionais].map((evento) => <tr key={evento.id}><td>{formatarData(evento.recebido_em)}</td><td>{evento.tipo}</td><td>{evento.acao ?? "—"}</td><td><span className={`situacao situacao-${evento.situacao}`}>{evento.situacao}</span></td><td>{evento.codigo_erro ?? "—"}</td></tr>)}</tbody></table></div>{(cursorEventos === undefined ? eventos.dados?.proximo_cursor : cursorEventos) && <div className="carregar-mais"><Botao variante="texto" carregando={processando === "mais-eventos"} onClick={carregarMaisEventos}>Carregar mais</Botao></div>}</Cartao>
    {editor && <Modal titulo={editor === "novo" ? "Novo plano" : "Editar plano"} fechar={() => definirEditor(null)}><FormularioPlano plano={editor === "novo" ? null : editor} concluir={(texto) => { definirEditor(null); atualizarTudo(texto); }} /></Modal>}
    {cortesia && <Modal titulo="Conceder cortesia" fechar={() => definirCortesia(false)}><FormularioCortesia usuarios={usuarios.dados?.dados ?? []} planos={planos.dados?.dados.filter((p) => p.ativo) ?? []} concluir={() => { definirCortesia(false); atualizarTudo("Cortesia concedida e registrada na auditoria."); }} /></Modal>}
  </div>;
}

function Metrica({ titulo, valor }: { titulo: string; valor: string | number }) { return <Cartao className="metrica"><span className="metrica-rotulo">{titulo}<CheckCircle2 size={17} /></span><strong>{valor}</strong></Cartao>; }

function FormularioPlano({ plano, concluir }: { plano: PlanoAssinatura | null; concluir: (texto: string) => void }) {
  const [dados, definirDados] = useState<DadosPlano>(plano ? { nome: plano.nome, descricao: plano.descricao ?? "", valor: plano.valor, periodicidade: plano.periodicidade, recursos: plano.recursos, ativo: plano.ativo } : planoVazio);
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  const opcoes = Array.from(new Set([...recursosDisponiveis, ...(plano?.recursos ?? [])]));
  function alternarRecurso(recurso: string, marcado: boolean) { definirDados({ ...dados, recursos: marcado ? [...dados.recursos, recurso] : dados.recursos.filter((item) => item !== recurso) }); }
  async function enviar(evento: FormEvent) { evento.preventDefault(); if (!dados.recursos.length) { definirErro("Selecione pelo menos um recurso."); return; } definirErro(""); definirEnviando(true); try { await api(`admin/assinaturas/planos${plano ? `/${plano.id}` : ""}`, { method: plano ? "PATCH" : "POST", body: JSON.stringify(corpoPlano(dados)) }); concluir(plano ? "Plano atualizado." : "Plano criado."); } catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); } }
  return <form className="formulario-grade" onSubmit={enviar}>{erro && <Aviso>{erro}</Aviso>}<Campo rotulo="Nome" required value={dados.nome} onChange={(e) => definirDados({ ...dados, nome: e.target.value })} /><Campo rotulo="Descrição" value={dados.descricao} onChange={(e) => definirDados({ ...dados, descricao: e.target.value })} /><div className="formulario-duas-colunas"><Campo rotulo="Valor" type="number" min="0" step="0.01" required value={dados.valor} onChange={(e) => definirDados({ ...dados, valor: e.target.value })} /><Seletor rotulo="Periodicidade" value={dados.periodicidade} onChange={(e) => definirDados({ ...dados, periodicidade: e.target.value as DadosPlano["periodicidade"] })}><option value="mensal">Mensal</option><option value="anual">Anual</option></Seletor></div><fieldset className="recursos-opcoes"><legend>Recursos do plano</legend>{opcoes.map((recurso) => <label key={recurso}><input type="checkbox" checked={dados.recursos.includes(recurso)} onChange={(e) => alternarRecurso(recurso, e.target.checked)} /><span>{recurso}</span></label>)}</fieldset><label className="opcao-saldo"><input type="checkbox" checked={dados.ativo} onChange={(e) => definirDados({ ...dados, ativo: e.target.checked })} /><span><strong>Plano ativo</strong><small>Disponível para novas assinaturas.</small></span></label><div className="modal-acoes"><Botao type="submit" carregando={enviando}>Salvar plano</Botao></div></form>;
}

function FormularioCortesia({ usuarios, planos, concluir }: { usuarios: UsuarioAdmin[]; planos: PlanoAssinatura[]; concluir: () => void }) {
  const [dados, definirDados] = useState({ usuario_id: "", plano_id: "", fim: "", motivo: "" }); const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);
  async function enviar(evento: FormEvent) { evento.preventDefault(); definirErro(""); definirEnviando(true); try { await api("admin/assinaturas/cortesia", { method: "POST", body: JSON.stringify(dados) }); concluir(); } catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); } }
  return <form className="formulario-grade" onSubmit={enviar}>{erro && <Aviso>{erro}</Aviso>}<Seletor rotulo="Usuário" required value={dados.usuario_id} onChange={(e) => definirDados({ ...dados, usuario_id: e.target.value })}><option value="">Selecione</option>{usuarios.map((usuario) => <option key={usuario.id} value={usuario.id}>{usuario.nome} — {usuario.email}</option>)}</Seletor><Seletor rotulo="Plano" required value={dados.plano_id} onChange={(e) => definirDados({ ...dados, plano_id: e.target.value })}><option value="">Selecione</option>{planos.map((plano) => <option key={plano.id} value={plano.id}>{plano.nome}</option>)}</Seletor><Campo rotulo="Acesso até" type="date" required value={dados.fim} onChange={(e) => definirDados({ ...dados, fim: e.target.value })} /><Campo rotulo="Motivo para auditoria" required minLength={5} value={dados.motivo} onChange={(e) => definirDados({ ...dados, motivo: e.target.value })} /><div className="modal-acoes"><Botao type="submit" carregando={enviando}><Gift size={16} /> Conceder</Botao></div></form>;
}
