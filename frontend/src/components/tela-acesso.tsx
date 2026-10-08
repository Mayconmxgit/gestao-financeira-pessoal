import Link from "next/link";
import { ArrowUpRight, ChartNoAxesCombined, CheckCircle2, Layers3, ShieldCheck } from "lucide-react";

export function TelaAcesso({ titulo, subtitulo, children, rodape }: {
  titulo: string; subtitulo: string; children: React.ReactNode; rodape: React.ReactNode;
}) {
  return <main className="acesso">
    <section className="acesso-lateral">
      <Link className="marca marca-clara" href="/"><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link>
      <div className="acesso-lateral-conteudo">
        <div className="selo"><span className="selo-ponto" /> Seu dinheiro, com mais clareza</div>
        <h1>Uma vida financeira mais <em>leve</em> começa aqui.</h1>
        <p>Organize suas contas, entenda cada movimentação e tome decisões com confiança. Tudo em um só lugar.</p>
        <div className="acesso-beneficios">
          <span><Layers3 size={18} /> Todas as contas em uma visão</span>
          <span><ShieldCheck size={18} /> Seus dados sob seu controle</span>
          <span><CheckCircle2 size={18} /> Importação com revisão antes de confirmar</span>
        </div>
      </div>
      <div className="acesso-rodape-lateral">Sua rotina. Seus números. Sua tranquilidade. <ArrowUpRight size={17} /></div>
      <div className="acesso-orbita acesso-orbita-um" /><div className="acesso-orbita acesso-orbita-dois" />
    </section>
    <section className="acesso-formulario"><div className="acesso-formulario-interno">
      <div className="acesso-mobile-marca"><Link className="marca" href="/"><span className="marca-simbolo"><ChartNoAxesCombined size={22} /></span><span>vida<span className="marca-destaque">financeira</span></span></Link></div>
      <div className="acesso-titulo"><span className="sobretitulo">BEM-VINDO(A)</span><h2>{titulo}</h2><p>{subtitulo}</p></div>
      {children}<div className="acesso-rodape">{rodape}</div>
    </div></section>
  </main>;
}
