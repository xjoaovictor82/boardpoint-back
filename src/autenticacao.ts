import jwt from "jsonwebtoken";
import type { FastifyRequest } from "fastify";
import { config } from "./config.js";
import { erro } from "./respostas.js";

export type TipoLogin = "admin" | "cliente";

/** O que fica guardado dentro do token. */
export interface PayloadToken {
  sub: number; // id do admin ou do cliente
  tipo: TipoLogin;
  usuario?: string | undefined; // só no token de admin
  nome?: string | undefined; // só no token de cliente
}

/** Cria o JWT (equivale ao emitirToken() do PHP). Vale 8 horas. */
export function emitirToken(
  id: number,
  tipo: TipoLogin,
  extra: Record<string, string> = {}
): string {
  if (config.jwt.segredo === "") {
    erro(500, "Servidor sem JWT_SECRET configurado.");
  }
  return jwt.sign({ ...extra, sub: id, tipo }, config.jwt.segredo, {
    algorithm: "HS256",
    expiresIn: config.jwt.validadeSegundos,
  });
}

/** Pega o token do cabeçalho "Authorization: Bearer xxxx". */
function tokenDaRequisicao(requisicao: FastifyRequest): string | null {
  const cabecalho = requisicao.headers.authorization ?? "";
  const encontrado = /^Bearer\s+(\S+)$/i.exec(cabecalho);
  return encontrado?.[1] ?? null;
}

/** Confere assinatura e validade do token. Devolve null se algo estiver errado. */
function lerPayload(token: string): PayloadToken | null {
  try {
    const decodificado = jwt.verify(token, config.jwt.segredo, { algorithms: ["HS256"] });
    if (typeof decodificado === "string") return null;

    const sub = Number(decodificado.sub);
    const tipo: unknown = decodificado["tipo"];
    if (!Number.isInteger(sub) || sub <= 0) return null;
    if (tipo !== "admin" && tipo !== "cliente") return null;

    const usuario: unknown = decodificado["usuario"];
    const nome: unknown = decodificado["nome"];

    return {
      sub,
      tipo,
      usuario: typeof usuario === "string" ? usuario : undefined,
      nome: typeof nome === "string" ? nome : undefined,
    };
  } catch {
    return null; // assinatura errada, token expirado ou mal formado
  }
}

/**
 * Exige um token válido do tipo pedido ('admin' ou 'cliente') e devolve o payload.
 * 401 = sem token / token inválido ou expirado.
 * 403 = token válido, mas do tipo errado.
 */
export function exigirLogin(requisicao: FastifyRequest, tipo: TipoLogin = "admin"): PayloadToken {
  const token = tokenDaRequisicao(requisicao);
  const payload =
    token !== null && config.jwt.segredo !== "" ? lerPayload(token) : null;

  if (payload === null) {
    erro(401, "Não autenticado. Faça login novamente.");
  }
  if (payload.tipo !== tipo) {
    erro(403, "Você não tem permissão para esta ação.");
  }
  return payload;
}