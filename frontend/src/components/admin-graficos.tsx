"use client";

import { Bar, BarChart, CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import type { PontoCrescimentoAdmin } from "@/lib/tipos";

const cores = { usuarios: "#3f9d72", ativacoes: "#73b99a", cancelamentos: "#d98370" };

export function GraficoCrescimentoAdmin({ dados }: { dados: PontoCrescimentoAdmin[] }) {
  return <div className="admin-grafico" role="img" aria-label="Evolução de novos usuários, ativações e cancelamentos">
    <ResponsiveContainer width="100%" height="100%"><LineChart data={dados} margin={{ top: 12, right: 12, left: -18, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" stroke="#e8ede8" /><XAxis dataKey="rotulo" tick={{ fontSize: 10 }} /><YAxis allowDecimals={false} tick={{ fontSize: 10 }} /><Tooltip /><Legend /><Line type="monotone" dataKey="novos_usuarios" name="Novos usuários" stroke={cores.usuarios} strokeWidth={2.5} dot={false} /><Line type="monotone" dataKey="ativacoes" name="Ativações" stroke={cores.ativacoes} strokeWidth={2.5} dot={false} /><Line type="monotone" dataKey="cancelamentos" name="Cancelamentos" stroke={cores.cancelamentos} strokeWidth={2.5} dot={false} /></LineChart></ResponsiveContainer>
  </div>;
}

export function GraficoPlanosAdmin({ dados }: { dados: Array<{ plano: string; assinaturas: number }> }) {
  return <div className="admin-grafico admin-grafico-menor" role="img" aria-label="Assinaturas ativas por plano">
    <ResponsiveContainer width="100%" height="100%"><BarChart data={dados} margin={{ top: 12, right: 8, left: -20, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" stroke="#e8ede8" /><XAxis dataKey="plano" tick={{ fontSize: 10 }} /><YAxis allowDecimals={false} tick={{ fontSize: 10 }} /><Tooltip /><Bar dataKey="assinaturas" name="Assinaturas" fill="#3f9d72" radius={[7, 7, 0, 0]} /></BarChart></ResponsiveContainer>
  </div>;
}

export function GraficoSerieAdmin({ dados, campo, nome }: { dados: Array<Record<string, string | number | null>>; campo: string; nome: string }) {
  const pontos = dados.map((item) => ({ ...item, [campo]: item[campo] === null ? null : Number(item[campo]) }));
  return <div className="admin-grafico" role="img" aria-label={`${nome} ao longo do período`}>
    <ResponsiveContainer width="100%" height="100%"><LineChart data={pontos} margin={{ top: 12, right: 12, left: -12, bottom: 0 }}><CartesianGrid strokeDasharray="3 3" stroke="#e8ede8" /><XAxis dataKey="rotulo" tick={{ fontSize: 10 }} /><YAxis tick={{ fontSize: 10 }} /><Tooltip /><Legend /><Line type="monotone" connectNulls={false} dataKey={campo} name={nome} stroke="#3f9d72" strokeWidth={2.5} dot={false} /></LineChart></ResponsiveContainer>
  </div>;
}
