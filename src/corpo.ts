import type { FastifyRequest } from "fastify";

/** Corpo JSON da requisição como objeto. Se não for um objeto, devolve {}. */
export function corpoJson(requisicao: FastifyRequest): Record<string, unknown> {
  const corpo = requisicao.body;
  if (typeof corpo === "object" && corpo !== null && !Array.isArray(corpo)) {
    return corpo as Record<string, unknown>;
  }
  return {};
}

/** Converte qualquer valor em texto. Ausente (ou tipo estranho) vira "". */
export function texto(valor: unknown): string {
  if (typeof valor === "string") return valor;
  if (typeof valor === "number") return String(valor);
  return "";
}
