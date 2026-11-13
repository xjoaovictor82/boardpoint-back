import Fastify from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { config } from "./config.js";
import { consultar } from "./db.js";
import { ErroHttp } from "./respostas.js";
import { rotasAuth } from "./rotas/auth.js";
import { rotasCategorias } from "./rotas/categorias.js";

const app = Fastify({ logger: true });

/** Lê o statusCode de um erro desconhecido. Devolve 0 se não houver. */
function statusDoErro(err: unknown): number {
  if (typeof err === "object" && err !== null && "statusCode" in err) {
    const codigo = (err as { statusCode: unknown }).statusCode;
    if (typeof codigo === "number") return codigo;
  }
  return 0;
}

// Todo erro vira { sucesso: false, erro: "..." } com o status certo.
app.setErrorHandler((err: unknown, _requisicao, resposta) => {
  if (err instanceof ErroHttp) {
    return resposta
      .code(err.status)
      .send({ sucesso: false, erro: err.message });
  }

  // JSON malformado e outros erros de requisição (4xx) do próprio Fastify
  const status = statusDoErro(err);
  if (status >= 400 && status < 500) {
    return resposta
      .code(status)
      .send({ sucesso: false, erro: "Requisição inválida." });
  }

  app.log.error(err);
  return resposta
    .code(500)
    .send({ sucesso: false, erro: "Erro interno do servidor." });
});

// Rota que não existe também responde em JSON.
app.setNotFoundHandler((_requisicao, resposta) => {
  return resposta
    .code(404)
    .send({ sucesso: false, erro: "Rota não encontrada." });
});

// Rota de teste, só para saber se o servidor está de pé.
app.get("/api/saude", async () => {
  return { sucesso: true, dados: { status: "ok" } };
});

// TEMPORÁRIA: testa a conexão com o banco. Apagamos no final.
app.get("/api/saude/db", async () => {
  const linhas = await consultar<RowDataPacket>(
    "SELECT COUNT(*) AS total FROM produtos",
  );
  return {
    sucesso: true,
    dados: { produtos: Number(linhas[0]?.["total"] ?? 0) },
  };
});

// --- Rotas da API ---
await app.register(rotasAuth, { prefix: "/api/auth" });
await app.register(rotasCategorias, { prefix: "/api/categorias" });

await app.listen({ port: config.porta, host: "0.0.0.0" });
