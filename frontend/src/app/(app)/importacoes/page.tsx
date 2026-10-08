"use client";

import { FormEvent, useState } from "react";
import { ArrowRight, Check, FileSpreadsheet, FileUp, RotateCcw, UploadCloud, X } from "lucide-react";
import { api } from "@/lib/api";
import { formatarData, mensagemErro } from "@/lib/formatacao";
import type { Categoria, Conta, Lote, Previa } from "@/lib/tipos";
import { useDados } from "@/hooks/use-dados";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal, Seletor, Vazio } from "@/components/interface";
import { RevisaoLote } from "@/components/revisao-lote";

export default function Importacoes() {
  const [loteId, definirLoteId] = useState<string | null>(null);
  const [arquivo, definirArquivo] = useState<File | null>(null);
  const [contaId, definirContaId] = useState("");
  const [instituicao, definirInstituicao] = useState("");
  const [enviando, definirEnviando] = useState(false);
  const [erro, definirErro] = useState("");
  const [sucesso, definirSucesso] = useState("");
  const [reverter, definirReverter] = useState<Lote | null>(null);
  const contas = useDados<{ dados: Conta[] }>("financeiro/contas");
  const categorias = useDados<{ dados: Categoria[] }>("financeiro/categorias");
  const lotes = useDados<{ dados: Lote[] }>("financeiro/importacoes");
  const previa = useDados<Previa>(loteId ? `financeiro/importacoes/${loteId}/previa` : null);

  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirSucesso("");
    if (!arquivo || !contaId) { definirErro("Selecione uma conta e um arquivo OFX, CSV ou PDF do Itaú."); return; }
    if (arquivo.size > 5 * 1024 * 1024) { definirErro("O arquivo deve ter até 5 MiB."); return; }
    const dados = new FormData();
    dados.set("arquivo", arquivo); dados.set("conta_id", contaId);
    if (instituicao.trim()) dados.set("instituicao", instituicao.trim());
    definirEnviando(true);
    try {
      const lote = await api<{ id: string }>("financeiro/importacoes", { method: "POST", body: dados });
      definirLoteId(lote.id); lotes.recarregar();
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  async function cancelar() {
    if (!loteId) return;
    definirErro("");
    try { await api(`financeiro/importacoes/${loteId}/cancelamento`, { method: "POST", body: "{}" }); definirLoteId(null); lotes.recarregar(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
  }

  function confirmado(resultado: { importadas: number; ignoradas: number }) {
    definirLoteId(null); definirArquivo(null); definirSucesso(`Importação concluída: ${resultado.importadas} lançamentos importados e ${resultado.ignoradas} ignorados.`);
    lotes.recarregar();
  }

  return <div>
    <CabecalhoPagina titulo="Importar extrato" descricao="Transforme seu extrato em uma visão financeira confiável, com revisão antes de confirmar." />
    {erro && <Aviso>{erro}</Aviso>}{sucesso && <Aviso tipo="sucesso">{sucesso}</Aviso>}
    <div className="etapas-importacao"><span className="etapa ativa"><b>1</b> Enviar arquivo</span><span className={`etapa ${loteId ? "ativa" : ""}`}><b>2</b> Revisar prévia</span><span className="etapa"><b>3</b> Confirmar</span></div>
    {loteId ? <>
      {previa.erro && <Aviso>{previa.erro}</Aviso>}
      {previa.dados && <RevisaoLote previa={previa.dados} moeda={contas.dados?.dados.find((conta) => conta.id === previa.dados?.lote.conta_id)?.moeda ?? "BRL"} categorias={categorias.dados?.dados ?? []} recarregar={previa.recarregar} confirmado={confirmado} cancelar={cancelar} />}
      {previa.carregando && <Cartao><div className="carregando-conteudo">Preparando a prévia do extrato...</div></Cartao>}
    </> : <Cartao className="upload-cartao"><div className="cartao-cabecalho"><div><h2>Selecione seu extrato</h2><p>Você poderá revisar todos os lançamentos antes de alterar o saldo.</p></div><span className="icone-cabecalho"><FileUp size={22} /></span></div>
      <form onSubmit={enviar} className="formulario-importacao">
        <Seletor rotulo="Conta de destino" required value={contaId} onChange={(e) => definirContaId(e.target.value)}><option value="">Selecione uma conta</option>{contas.dados?.dados.filter((c) => c.situacao === "ativa").map((c) => <option key={c.id} value={c.id}>{c.nome} · {c.moeda}</option>)}</Seletor>
        <Campo rotulo="Instituição (opcional nesta etapa)" maxLength={120} placeholder="Ex.: Meu banco" value={instituicao} onChange={(e) => definirInstituicao(e.target.value)} />
        <label className={`area-upload ${arquivo ? "area-upload-selecionado" : ""}`}><input type="file" accept=".ofx,.csv,.pdf" onChange={(e) => definirArquivo(e.target.files?.[0] ?? null)} aria-label="Selecionar extrato OFX, CSV ou PDF do Itaú" />
          <span className="area-upload-icone"><UploadCloud size={29} /></span><strong>{arquivo ? arquivo.name : "Clique para escolher seu arquivo"}</strong><small>{arquivo ? `${(arquivo.size / 1024).toFixed(0)} KB selecionados` : "OFX, CSV ou PDF do Itaú · até 5 MiB"}</small></label>
        <div className="upload-rodape"><span><Check size={17} /> Sem alterações até você confirmar</span><Botao type="submit" carregando={enviando}>Ver prévia <ArrowRight size={17} /></Botao></div>
      </form>
    </Cartao>}
    <section className="secao-historico-importacoes"><div className="cartao-cabecalho"><div><h2>Histórico de importações</h2><p>Acompanhe lotes e reverta uma confirmação quando necessário.</p></div></div>
      {lotes.dados?.dados.length ? <div className="lista-lotes">{lotes.dados.dados.map((lote) => <Cartao key={lote.id} className="lote-cartao"><span className="lote-icone"><FileSpreadsheet size={22} /></span><div className="lote-info"><strong>{lote.nome_arquivo}</strong><span>{contas.dados?.dados.find((c) => c.id === lote.conta_id)?.nome ?? "Conta"} · {lote.instituicao || "Instituição não informada"}</span><small>{formatarData(lote.periodo_inicio)} — {formatarData(lote.periodo_fim)} · {lote.linhas_lidas} linhas</small></div><div className="lote-acoes"><span className={`etiqueta etiqueta-${lote.situacao}`}>{rotuloSituacao(lote.situacao)}</span>{lote.situacao === "previa" && <button className="link-acao" onClick={() => definirLoteId(lote.id)}>Revisar <ArrowRight size={15} /></button>}{lote.situacao === "confirmado" && <button className="link-acao" onClick={() => definirReverter(lote)}>Reverter <RotateCcw size={15} /></button>}</div></Cartao>)}</div>
        : !lotes.carregando && <Cartao><Vazio titulo="Nenhum extrato importado" descricao="Seus lotes aparecerão aqui depois do primeiro envio." /></Cartao>}
    </section>
    {reverter && <Modal titulo="Reverter importação" fechar={() => definirReverter(null)}><FormularioReversao lote={reverter} concluir={() => { definirReverter(null); lotes.recarregar(); definirSucesso("Lote revertido. O histórico foi preservado."); }} /></Modal>}
  </div>;
}

function rotuloSituacao(situacao: string) {
  return ({ previa: "A revisar", confirmado: "Confirmado", revertido: "Revertido", cancelado: "Cancelado" } as Record<string, string>)[situacao] ?? situacao;
}

function FormularioReversao({ lote, concluir }: { lote: Lote; concluir: () => void }) {
  const [motivo, definirMotivo] = useState(""); const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);
  async function enviar(evento: FormEvent) {
    evento.preventDefault(); definirErro(""); definirEnviando(true);
    try { await api(`financeiro/importacoes/${lote.id}/reversao`, { method: "POST", body: JSON.stringify({ confirmar: true, motivo }) }); concluir(); }
    catch (falha) { definirErro(mensagemErro(falha)); } finally { definirEnviando(false); }
  }
  return <form className="formulario-grade" onSubmit={enviar}><Aviso tipo="info">Os efeitos deste lote serão estornados. As linhas e decisões continuarão no histórico para auditoria.</Aviso>{erro && <Aviso>{erro}</Aviso>}
    <div className="resumo-formulario"><strong>{lote.nome_arquivo}</strong><span>{lote.linhas_importadas} importadas</span></div>
    <Campo rotulo="Motivo da reversão" required minLength={5} maxLength={300} value={motivo} onChange={(e) => definirMotivo(e.target.value)} />
    <div className="modal-acoes"><Botao variante="perigo" type="submit" carregando={enviando}><X size={17} /> Confirmar reversão</Botao></div>
  </form>;
}
