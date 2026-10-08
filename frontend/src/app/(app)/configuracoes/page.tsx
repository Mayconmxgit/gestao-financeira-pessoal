"use client";

import { FormEvent, useState } from "react";
import { ArrowDownToLine, ArrowRight, LogOut, ShieldCheck, Trash2, UserRound } from "lucide-react";
import { useAutenticacao } from "@/components/autenticacao";
import { api, baixarExportacao } from "@/lib/api";
import { mensagemErro } from "@/lib/formatacao";
import { Aviso, Botao, CabecalhoPagina, Campo, Cartao, Modal } from "@/components/interface";

export default function Configuracoes() {
  const { usuario, sair } = useAutenticacao();
  const [exclusaoAberta, definirExclusao] = useState(false);
  const [exportando, definirExportando] = useState(false);
  const [erro, definirErro] = useState("");

  async function exportar() {
    definirErro(""); definirExportando(true);
    try { await baixarExportacao(); }
    catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirExportando(false); }
  }

  return <div><CabecalhoPagina titulo="Configurações" descricao="Sua conta, seus dados e suas escolhas de privacidade." />
    {erro && <Aviso>{erro}</Aviso>}
    <div className="configuracoes-grade"><div className="configuracoes-principal">
      <Cartao><div className="cartao-cabecalho"><div><h2>Seus dados</h2><p>Informações básicas do seu acesso.</p></div><span className="icone-cabecalho"><UserRound size={22} /></span></div><div className="perfil-linhas"><div><span>Nome</span><strong>{usuario?.nome}</strong></div><div><span>E-mail</span><strong>{usuario?.email}</strong></div></div></Cartao>
      <Cartao><div className="cartao-cabecalho"><div><h2>Privacidade e controle</h2><p>Tenha uma cópia dos seus dados sempre que quiser.</p></div><span className="icone-cabecalho"><ShieldCheck size={22} /></span></div><div className="configuracao-linha"><span className="configuracao-icone"><ArrowDownToLine size={21} /></span><div><strong>Exportar meus dados</strong><p>Baixe contas, movimentações, importações e informações da conta em JSON.</p></div><Botao variante="secundario" carregando={exportando} onClick={exportar}>Exportar <ArrowRight size={16} /></Botao></div></Cartao>
      <Cartao className="zona-perigo"><div className="cartao-cabecalho"><div><h2>Excluir conta</h2><p>Apague sua conta e os dados financeiros associados.</p></div></div><p>Esta ação exige sua senha e não pode ser desfeita. Exporte seus dados antes se quiser manter uma cópia.</p><Botao variante="perigo" onClick={() => definirExclusao(true)}><Trash2 size={17} /> Solicitar exclusão</Botao></Cartao>
    </div><aside className="configuracoes-lateral"><Cartao><span className="sobretitulo">SEU CONTROLE</span><h2>Seu dinheiro merece transparência.</h2><p>Os números exibidos vêm dos lançamentos confirmados. Importações passam por prévia, e você decide sobre possíveis duplicidades.</p><div className="configuracoes-decoracao"><ShieldCheck size={62} strokeWidth={1.2} /></div></Cartao><button className="botao botao-texto sair-configuracoes" onClick={sair}><LogOut size={18} /> Sair da conta</button></aside></div>
    {exclusaoAberta && <Modal titulo="Excluir sua conta" fechar={() => definirExclusao(false)}><FormularioExclusao concluir={sair} /></Modal>}
  </div>;
}

function FormularioExclusao({ concluir }: { concluir: () => Promise<void> }) {
  const [senha, definirSenha] = useState("");
  const [confirmacao, definirConfirmacao] = useState("");
  const [erro, definirErro] = useState("");
  const [enviando, definirEnviando] = useState(false);

  async function enviar(evento: FormEvent) {
    evento.preventDefault();
    if (confirmacao !== "EXCLUIR") { definirErro("Digite EXCLUIR para confirmar."); return; }
    definirErro(""); definirEnviando(true);
    try {
      await api("financeiro/usuarios/eu", { method: "DELETE", body: JSON.stringify({ senha, confirmar: true }) });
      await concluir();
    } catch (falha) { definirErro(mensagemErro(falha)); }
    finally { definirEnviando(false); }
  }

  return <form className="formulario-grade" onSubmit={enviar}><Aviso>Esta ação apaga a conta e os dados associados. Não há como recuperar os dados depois.</Aviso>{erro && <Aviso>{erro}</Aviso>}
    <Campo rotulo="Sua senha" type="password" autoComplete="current-password" required value={senha} onChange={(e) => definirSenha(e.target.value)} />
    <Campo rotulo="Digite EXCLUIR para confirmar" required value={confirmacao} onChange={(e) => definirConfirmacao(e.target.value)} />
    <div className="modal-acoes"><Botao variante="perigo" type="submit" carregando={enviando}><Trash2 size={17} /> Excluir definitivamente</Botao></div>
  </form>;
}
