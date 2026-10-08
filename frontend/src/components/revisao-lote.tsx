"use client";

import { FormEvent, useState } from "react";
import { AlertTriangle, ArrowLeft, ArrowRight, Check, CircleHelp, FileCheck2, Pencil, X } from "lucide-react";
import { api } from "@/lib/api";
import { formatarData, formatarMoeda, mensagemErro } from "@/lib/formatacao";
import type { Categoria, LinhaImportacao, Previa } from "@/lib/tipos";
import { Aviso, Botao, Campo, Cartao, Modal } from "@/components/interface";

type Escolha = { decisao?: "vincular" | "separada" | "ignorar"; categoria?: string; motivo?: string };
const ITENS_POR_PAGINA = 20;

export function RevisaoLote({ previa, moeda, categorias, recarregar, confirmado, cancelar }: {
  previa: Previa; moeda: string; categorias: Categoria[]; recarregar: () => void;
  confirmado: (resultado: { importadas: number; ignoradas: number }) => void;
  cancelar: () => Promise<void>;
}) {
  const [instituicao, definirInstituicao] = useState(previa.lote.instituicao ?? "");
  const [escolhas, definirEscolhas] = useState<Record<string, Escolha>>({});
  const [corrigir, definirCorrigir] = useState<LinhaImportacao | null>(null);
  const [erro, definirErro] = useState("");
  const [confirmando, definirConfirmando] = useState(false);
  const [pagina, definirPagina] = useState(1);
  const ambiguas = previa.linhas.filter((linha) => linha.situacao_previa === "possivel_duplicidade");
  const invalidas = previa.linhas.filter((linha) => linha.situacao_previa === "invalida");
  const resolvidas = ambiguas.filter((linha) => escolhas[linha.id]?.decisao).length;
  const totalPaginas = Math.max(1, Math.ceil(previa.linhas.length / ITENS_POR_PAGINA));
  const paginaAtual = Math.min(pagina, totalPaginas);
  const indiceInicial = (paginaAtual - 1) * ITENS_POR_PAGINA;
  const linhasVisiveis = previa.linhas.slice(indiceInicial, indiceInicial + ITENS_POR_PAGINA);

  function escolher(id: string, atualizacao: Partial<Escolha>) {
    definirEscolhas((atuais) => ({ ...atuais, [id]: { ...atuais[id], ...atualizacao } }));
  }

  async function confirmar() {
    definirErro("");
    if (ambiguas.some((linha) => !escolhas[linha.id]?.decisao)) {
      definirErro("Resolva todas as possíveis duplicidades antes de confirmar."); return;
    }
    if (instituicao.trim().length < 2) { definirErro("Informe o nome da instituição para confirmar o lote."); return; }
    const decisoes = previa.linhas.flatMap((linha) => {
      if (["invalida", "duplicada_confirmada"].includes(linha.situacao_previa)) return [];
      const escolha = escolhas[linha.id] ?? {};
      if (!escolha.decisao && !escolha.categoria) return [];
      return [{
        linha_id: linha.id,
        decisao: escolha.decisao ?? "importar",
        ...(escolha.categoria ? { categoria_id: escolha.categoria } : {}),
        ...(escolha.decisao === "vincular" && linha.transacao_candidata_id ? { transacao_candidata_id: linha.transacao_candidata_id } : {}),
        ...(escolha.motivo ? { motivo: escolha.motivo } : {}),
      }];
    });
    definirConfirmando(true);
    try {
      const resultado = await api<{ importadas: number; ignoradas: number }>(`financeiro/importacoes/${previa.lote.id}/confirmacao`, {
        method: "POST", body: JSON.stringify({ instituicao: instituicao.trim(), decisoes }),
      });
      confirmado(resultado);
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirConfirmando(false); }
  }

  return <div className="revisao-lote">
    <Cartao className="revisao-resumo"><div><span className="sobretitulo">PRÉVIA DO EXTRATO</span><h2>{previa.lote.nome_arquivo}</h2><p>{previa.lote.linhas_lidas} linhas encontradas · {formatarData(previa.lote.periodo_inicio)} a {formatarData(previa.lote.periodo_fim)}</p></div><div className="revisao-totais"><div><small>Entradas</small><strong className="valor-positivo">{formatarMoeda(previa.total_entradas, moeda)}</strong></div><div><small>Saídas</small><strong>{formatarMoeda(previa.total_saidas, moeda)}</strong></div></div></Cartao>
    <Aviso tipo="info"><FileCheck2 size={18} /> Esta é apenas uma prévia. O saldo gerencial não muda até você confirmar.</Aviso>
    {(ambiguas.length > 0 || invalidas.length > 0) && <div className="revisao-alertas">{ambiguas.length > 0 && <span><CircleHelp size={17} /> {resolvidas} de {ambiguas.length} duplicidades resolvidas</span>}{invalidas.length > 0 && <span><AlertTriangle size={17} /> {invalidas.length} linha{invalidas.length > 1 ? "s" : ""} inválida{invalidas.length > 1 ? "s" : ""} será{invalidas.length > 1 ? "ão" : ""} ignorada{invalidas.length > 1 ? "s" : ""} se não for corrigida{invalidas.length > 1 ? "s" : ""}</span>}</div>}
    <Cartao className="tabela-cartao"><div className="cartao-cabecalho"><div><h2>Revisar lançamentos</h2><p>Confira os valores e resolva os itens destacados. Suas escolhas são mantidas ao trocar de página.</p></div></div>
      <div className="tabela-rolagem"><table className="tabela tabela-importacao"><thead><tr><th>#</th><th>Data e descrição</th><th>Valor</th><th>Situação</th><th>Classificação e ação</th></tr></thead><tbody>{linhasVisiveis.map((linha) => {
        const escolha = escolhas[linha.id] ?? {};
        const situacao = linha.situacao_previa;
        return <tr key={linha.id} className={situacao === "invalida" || situacao === "possivel_duplicidade" ? "linha-atencao" : ""}>
          <td>{linha.numero}</td><td><strong>{linha.descricao_original || "Descrição ausente"}</strong><small>{formatarData(linha.data_efetiva)}</small></td>
          <td className={Number(linha.valor) > 0 ? "valor-positivo tabela-valor" : "tabela-valor"}>{linha.valor ? formatarMoeda(linha.valor, moeda) : "—"}</td>
          <td><span className={`etiqueta etiqueta-${situacao}`}>{rotuloSituacao(situacao)}</span>{linha.transacao_candidata_id && <small className="linha-candidato">Transação semelhante #{linha.transacao_candidata_id}</small>}{linha.linha_candidata_id && <small className="linha-candidato">Linha semelhante neste arquivo</small>}</td>
          <td>{situacao === "invalida" ? <button type="button" className="link-acao" onClick={() => definirCorrigir(linha)}><Pencil size={15} /> Corrigir linha</button>
            : situacao === "duplicada_confirmada" ? <span className="texto-suave">Ignorada automaticamente</span>
            : <ControlesLinha linha={linha} escolha={escolha} categorias={categorias} escolher={escolher} />}
          </td></tr>;
      })}</tbody></table></div>
      {totalPaginas > 1 && <Paginacao pagina={paginaAtual} totalPaginas={totalPaginas} totalItens={previa.linhas.length} mudar={definirPagina} />}
    </Cartao>
    <Cartao className="revisao-confirmacao"><div><h2>Pronto para confirmar?</h2><p>Informe a instituição. A confirmação grava todas as linhas aceitas em uma única operação.</p><Campo rotulo="Instituição financeira" required minLength={2} maxLength={120} placeholder="Nome do banco" value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} /></div><div className="revisao-botoes"><Botao variante="texto" onClick={cancelar}><X size={17} /> Cancelar lote</Botao><Botao onClick={confirmar} carregando={confirmando}><Check size={18} /> Confirmar importação</Botao></div></Cartao>
    {erro && <Aviso>{erro}</Aviso>}
    {corrigir && <Modal titulo={`Corrigir linha ${corrigir.numero}`} fechar={() => definirCorrigir(null)}><FormularioCorrecao loteId={previa.lote.id} linha={corrigir} concluir={() => { definirCorrigir(null); recarregar(); }} /></Modal>}
  </div>;
}

function ControlesLinha({ linha, escolha, categorias, escolher }: {
  linha: LinhaImportacao; escolha: Escolha; categorias: Categoria[];
  escolher: (id: string, atualizacao: Partial<Escolha>) => void;
}) {
  const exigeDecisao = linha.situacao_previa === "possivel_duplicidade";
  const permiteCategoria = escolha.decisao !== "ignorar" && escolha.decisao !== "vincular";
  const categoriasCompativeis = categorias.filter((categoria) => categoria.tipo === (Number(linha.valor) > 0 ? "receita" : "despesa"));

  return <div className="linha-controles">
    {exigeDecisao && <label><span>O que fazer com a possível duplicidade?</span><select aria-label={`Tratamento da duplicidade da linha ${linha.numero}`} value={escolha.decisao ?? ""} onChange={(e) => escolher(linha.id, { decisao: e.target.value as Escolha["decisao"] })}>
      <option value="">Escolha uma ação</option>
      {linha.transacao_candidata_id && <option value="vincular">É a mesma transação — não importar novamente</option>}
      <option value="separada">É outro lançamento — importar normalmente</option>
      <option value="ignorar">Não quero importar esta linha</option>
    </select><small>{explicacaoDecisao(escolha.decisao)}</small></label>}
    {permiteCategoria && <label><span>Categoria <em>(opcional)</em></span><select aria-label={`Categoria da linha ${linha.numero}`} value={escolha.categoria ?? ""} onChange={(e) => escolher(linha.id, { categoria: e.target.value })}>
      <option value="">Classificar depois</option>
      {categoriasCompativeis.map((categoria) => <option key={categoria.id} value={categoria.id}>{categoria.nome}</option>)}
    </select><small>A categoria apenas organiza o lançamento; ela não altera o valor.</small></label>}
  </div>;
}

function explicacaoDecisao(decisao: Escolha["decisao"]) {
  if (decisao === "vincular") return "A transação existente será mantida e esta linha não será importada novamente.";
  if (decisao === "separada") return "Esta linha será criada como um novo lançamento.";
  if (decisao === "ignorar") return "Esta linha não será incluída na conta.";

  return "Compare a data, a descrição e o valor antes de escolher.";
}

function Paginacao({ pagina, totalPaginas, totalItens, mudar }: {
  pagina: number; totalPaginas: number; totalItens: number; mudar: (pagina: number) => void;
}) {
  const inicio = (pagina - 1) * ITENS_POR_PAGINA + 1;
  const fim = Math.min(pagina * ITENS_POR_PAGINA, totalItens);

  return <nav className="paginacao-tabela" aria-label="Paginação dos lançamentos">
    <span>Exibindo {inicio}–{fim} de {totalItens}</span>
    <div><button type="button" disabled={pagina === 1} onClick={() => mudar(pagina - 1)} aria-label="Página anterior"><ArrowLeft size={15} /> Anterior</button>
      <strong>Página {pagina} de {totalPaginas}</strong>
      <button type="button" disabled={pagina === totalPaginas} onClick={() => mudar(pagina + 1)} aria-label="Próxima página">Próxima <ArrowRight size={15} /></button></div>
  </nav>;
}

function rotuloSituacao(situacao: string) {
  return ({ pronta: "Pronta", invalida: "Inválida", duplicada_confirmada: "Duplicada", possivel_duplicidade: "Revisar" } as Record<string, string>)[situacao] ?? situacao;
}

function FormularioCorrecao({ loteId, linha, concluir }: { loteId: string; linha: LinhaImportacao; concluir: () => void }) {
  const [data, definirData] = useState(linha.data_efetiva ?? "");
  const [valor, definirValor] = useState(linha.valor ?? "");
  const [descricao, definirDescricao] = useState(linha.descricao_original ?? "");
  const [identificador, definirIdentificador] = useState(linha.identificador_bancario ?? "");
  const [documento, definirDocumento] = useState(linha.documento ?? "");
  const [erro, definirErro] = useState(""); const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try {
      await api(`financeiro/importacoes/${loteId}/linhas/${linha.id}`, { method: "PATCH", body: JSON.stringify({ data, valor, descricao, identificador_bancario: identificador || null, documento: documento || null }) });
      concluir();
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}>{erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Data" type="date" required value={data} onChange={(e) => definirData(e.target.value)} />
    <Campo rotulo="Valor com sinal" type="number" step="0.01" required placeholder="Ex.: -40.00" value={valor} onChange={(e) => definirValor(e.target.value)} />
    <Campo rotulo="Descrição" required minLength={2} maxLength={500} value={descricao} onChange={(e) => definirDescricao(e.target.value)} />
    <Campo rotulo="Identificador bancário (opcional)" maxLength={150} value={identificador} onChange={(e) => definirIdentificador(e.target.value)} />
    <Campo rotulo="Documento (opcional)" maxLength={120} value={documento} onChange={(e) => definirDocumento(e.target.value)} />
    <div className="modal-acoes"><Botao type="submit" carregando={enviando}>Salvar correção <ArrowRight size={17} /></Botao></div>
  </form>;
}
