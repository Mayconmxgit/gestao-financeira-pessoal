"use client";

import { useId, useState } from "react";
import { X, LoaderCircle, ArrowRight, Eye, EyeOff } from "lucide-react";
import type { ButtonHTMLAttributes, HTMLAttributes, InputHTMLAttributes, SelectHTMLAttributes } from "react";

export function Botao({ variante = "primario", carregando, children, className = "", ...props }:
  ButtonHTMLAttributes<HTMLButtonElement> & { variante?: "primario" | "secundario" | "texto" | "perigo"; carregando?: boolean }) {
  return <button {...props} disabled={props.disabled || carregando}
    className={`botao botao-${variante} ${className}`}>
    {carregando ? <LoaderCircle size={17} className="girando" /> : children}
  </button>;
}

export function Campo({ rotulo, dica, ...props }: InputHTMLAttributes<HTMLInputElement> & { rotulo: string; dica?: string }) {
  return <label className="campo"><span>{rotulo}</span><input {...props} />{dica && <small>{dica}</small>}</label>;
}

export function CampoSenha({ rotulo, dica, id, ...props }:
  Omit<InputHTMLAttributes<HTMLInputElement>, "type"> & { rotulo: string; dica?: string }) {
  const [visivel, definirVisivel] = useState(false);
  const idGerado = useId();
  const idCampo = id ?? idGerado;
  const acao = visivel ? "Ocultar senha" : "Mostrar senha";
  const Icone = visivel ? EyeOff : Eye;

  return <div className="campo"><label className="campo-rotulo" htmlFor={idCampo}>{rotulo}</label><span className="campo-senha">
    <input {...props} id={idCampo} type={visivel ? "text" : "password"} />
    <button type="button" className="campo-senha-botao" aria-label={acao} aria-pressed={visivel}
      onClick={() => definirVisivel((atual) => !atual)}><Icone size={18} aria-hidden="true" /></button>
  </span>{dica && <small>{dica}</small>}</div>;
}

export function Seletor({ rotulo, dica, children, ...props }: SelectHTMLAttributes<HTMLSelectElement> & { rotulo: string; dica?: string }) {
  return <label className="campo"><span>{rotulo}</span><select {...props}>{children}</select>{dica && <small>{dica}</small>}</label>;
}

export function Aviso({ children, tipo = "erro" }: { children: React.ReactNode; tipo?: "erro" | "sucesso" | "info" }) {
  return <div role={tipo === "erro" ? "alert" : "status"} className={`aviso aviso-${tipo}`}>{children}</div>;
}

export function CabecalhoPagina({ titulo, descricao, acao }: { titulo: string; descricao?: string; acao?: React.ReactNode }) {
  return <div className="cabecalho-pagina"><div><h1>{titulo}</h1>{descricao && <p>{descricao}</p>}</div>{acao}</div>;
}

export function Cartao({ children, className = "", ...props }: HTMLAttributes<HTMLElement>) {
  return <section {...props} className={`cartao ${className}`}>{children}</section>;
}

export function Vazio({ titulo, descricao, acao }: { titulo: string; descricao: string; acao?: { texto: string; href: string } }) {
  return <div className="vazio"><div className="vazio-icone">✦</div><h3>{titulo}</h3><p>{descricao}</p>
    {acao && <a className="botao botao-secundario" href={acao.href}>{acao.texto}<ArrowRight size={16} /></a>}</div>;
}

export function Modal({ titulo, children, fechar, largo = false, className = "" }: {
  titulo: string; children: React.ReactNode; fechar: () => void; largo?: boolean; className?: string;
}) {
  return <div className="modal-fundo" onMouseDown={(evento) => { if (evento.target === evento.currentTarget) fechar(); }}>
    <div className={`modal ${largo ? "modal-largo" : ""} ${className}`} role="dialog" aria-modal="true" aria-label={titulo}>
      <div className="modal-cabecalho"><h2>{titulo}</h2><button type="button" onClick={fechar} aria-label="Fechar" className="botao-icone"><X size={20} /></button></div>
      {children}
    </div>
  </div>;
}
