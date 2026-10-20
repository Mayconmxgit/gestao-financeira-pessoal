"use client";

import { FormEvent, useState } from "react";
import { ArrowRightLeft, Pencil, Plus, Tags } from "lucide-react";
import { api } from "@/lib/api";
import { dataHojeLocal, mensagemErro } from "@/lib/formatacao";
import type { Categoria, Conta } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";
import { HistoricoMovimentacoes } from "@/components/historico-movimentacoes";
import { MetasFinanceiras, PagamentosRecorrentes } from "@/components/planejamento-financeiro";
import { RECURSOS_PLANO, useRecursosPlano } from "@/components/acesso-recursos-plano";

type Aba = "movimentacoes" | "recorrentes" | "metas" | "categorias";

export default function Movimentacoes() {
  const { possui } = useRecursosPlano();
  const permiteRecorrentes = possui(RECURSOS_PLANO.PAGAMENTOS_RECORRENTES);
  const permiteMetas = possui(RECURSOS_PLANO.METAS_FINANCEIRAS);
  const [aba, definirAba] = useState<Aba>("movimentacoes");
  const [transferindo, definirTransferindo] = useState(false);
  const [categoriaSelecionada, selecionarCategoria] = useState<Categoria | null | undefined>(undefined);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const categorias = useDados<{ dados: Categoria[] }>("financeiro/categorias");
  const todasContas = contas.dados?.dados ?? [];
  const todasCategorias = categorias.dados?.dados ?? [];

  return <div>
    <CabecalhoPagina titulo="Movimentações" descricao="Acompanhe entradas, saídas e transferências das suas contas."
      acao={<Botao variante="secundario" onClick={() => definirTransferindo(true)}><ArrowRightLeft size={17} /> Transferir</Botao>} />
    <div className="abas"><button className={aba === "movimentacoes" ? "ativa" : ""} onClick={() => definirAba("movimentacoes")}>Movimentações</button>{permiteRecorrentes && <button className={aba === "recorrentes" ? "ativa" : ""} onClick={() => definirAba("recorrentes")}>Pagamentos recorrentes</button>}{permiteMetas && <button className={aba === "metas" ? "ativa" : ""} onClick={() => definirAba("metas")}>Metas financeiras</button>}<button className={aba === "categorias" ? "ativa" : ""} onClick={() => definirAba("categorias")}>Categorias</button></div>
    {contas.erro && <Aviso>{contas.erro}</Aviso>}{categorias.erro && <Aviso>{categorias.erro}</Aviso>}
    {aba === "recorrentes" && permiteRecorrentes ? <PagamentosRecorrentes formaPagamento="debito" contas={todasContas} categorias={todasCategorias} />
      : aba === "metas" && permiteMetas ? <MetasFinanceiras contas={todasContas} />
      : aba === "categorias" ? <PainelCategorias categorias={todasCategorias} editar={selecionarCategoria} />
      : <HistoricoMovimentacoes formaPagamento="debito" contas={todasContas} categorias={todasCategorias} />}
    {transferindo && <Modal titulo="Transferir entre contas" fechar={() => definirTransferindo(false)}><FormularioTransferencia contas={todasContas} concluir={() => definirTransferindo(false)} /></Modal>}
    {categoriaSelecionada !== undefined && <Modal titulo={categoriaSelecionada ? "Editar categoria" : "Nova categoria"} fechar={() => selecionarCategoria(undefined)}><FormularioCategoria categoria={categoriaSelecionada ?? undefined} concluir={() => { selecionarCategoria(undefined); categorias.recarregar(); }} /></Modal>}
  </div>;
}

function PainelCategorias({ categorias, editar }: { categorias: Categoria[]; editar: (categoria: Categoria | null) => void }) {
  return <Cartao><div className="cartao-cabecalho"><div><h2>Suas categorias</h2><p>Organize receitas e despesas do seu jeito.</p></div><Botao onClick={() => editar(null)}><Plus size={17} /> Nova categoria</Botao></div>
    {categorias.length ? <div className="categorias-grade">{categorias.map((categoria) => <div key={categoria.id} className="categoria-linha"><span className={`categoria-ponto ${categoria.tipo}`} /><div className="categoria-dados"><strong>{categoria.nome}</strong><small>{descricaoCategoria(categoria)}</small></div><button type="button" className="botao-icone categoria-editar" onClick={() => editar(categoria)} aria-label={`Editar categoria ${categoria.nome}`}><Pencil size={16} /></button></div>)}</div>
      : <Vazio titulo="Ainda não há categorias" descricao="Crie categorias para acompanhar suas receitas e despesas classificadas." />}
  </Cartao>;
}

function FormularioTransferencia({ contas, concluir }: { contas: Conta[]; concluir: () => void }) {
  const [origem, definirOrigem] = useState(""); const [destino, definirDestino] = useState("");
  const [data, definirData] = useState(dataHojeLocal()); const [valor, definirValor] = useState("");
  const [descricao, definirDescricao] = useState(""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  const ativas = contas.filter((conta) => conta.situacao === "ativa" && conta.tipo !== "cartao_credito");
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try { await api("financeiro/transferencias", { method: "POST", body: JSON.stringify({ origem_id: origem, destino_id: destino, data, valor, descricao }) }); concluir(); }
    catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form onSubmit={enviar} className="formulario-grade"><Aviso tipo="info">Transferências próprias não contam como nova receita ou despesa.</Aviso>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Seletor rotulo="Conta de origem" required value={origem} onChange={(e) => definirOrigem(e.target.value)}><option value="">Selecione</option>{ativas.map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</Seletor>
    <Seletor rotulo="Conta de destino" required value={destino} onChange={(e) => definirDestino(e.target.value)}><option value="">Selecione</option>{ativas.filter((conta) => conta.id !== origem).map((conta) => <option key={conta.id} value={conta.id}>{conta.nome}</option>)}</Seletor>
    <div className="formulario-duas-colunas"><Campo rotulo="Valor" type="number" min="0.01" step="0.01" required value={valor} onChange={(e) => definirValor(e.target.value)} /><Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} /></div>
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} value={descricao} onChange={(e) => definirDescricao(e.target.value)} /><div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>Confirmar transferência</Botao></div>
  </form>;
}

function FormularioCategoria({ categoria, concluir }: { categoria?: Categoria; concluir: () => void }) {
  const [nome, definirNome] = useState(categoria?.nome ?? ""); const [tipo, definirTipo] = useState<Categoria["tipo"]>(categoria?.tipo ?? "despesa");
  const [limite, definirLimite] = useState(categoria?.limite_gastos ?? ""); const [estado, definirEstado] = useState({ erro: "", enviando: false });
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirEstado({ erro: "", enviando: true });
    try {
      await api(categoria ? `financeiro/categorias/${categoria.id}` : "financeiro/categorias", { method: categoria ? "PATCH" : "POST", body: JSON.stringify({ nome, tipo, limite_gastos: tipo === "despesa" && limite ? limite : null }) }); concluir();
    } catch (falha) { definirEstado({ erro: mensagemErro(falha), enviando: false }); }
  }
  return <form className="formulario-grade" onSubmit={enviar}>{estado.erro && <Aviso>{estado.erro}</Aviso>}
    <Campo rotulo="Nome da categoria" required minLength={2} maxLength={80} value={nome} onChange={(e) => definirNome(e.target.value)} /><Seletor rotulo="Tipo" value={tipo} onChange={(e) => definirTipo(e.target.value as Categoria["tipo"])}><option value="despesa">Despesa</option><option value="receita">Receita</option></Seletor>
    {tipo === "despesa" && <Campo rotulo="Limite mensal (opcional)" type="number" min="0.01" step="0.01" value={limite} onChange={(e) => definirLimite(e.target.value)} />}
    <div className="modal-acoes"><Botao type="submit" carregando={estado.enviando}>{categoria ? <><Pencil size={17} /> Salvar alterações</> : <><Tags size={17} /> Criar categoria</>}</Botao></div>
  </form>;
}

function descricaoCategoria(categoria: Categoria) {
  if (categoria.tipo === "receita") return "Receita";
  return categoria.limite_gastos ? `Limite mensal: ${Number(categoria.limite_gastos).toLocaleString("pt-BR", { minimumFractionDigits: 2 })}` : "Despesa · Sem limite";
}
