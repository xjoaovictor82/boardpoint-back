import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { chamarProcedimento } from "../../db.js";
import { texto } from "../../corpo.js";
import { normalizarProduto } from "../../normalizadores.js";
import type { LinhaProduto } from "../../normalizadores.js";
import { lerPaginacao } from "../../paginacao.js";
import { sucesso } from "../../respostas.js";

/**
 * Lista pública de produtos: busca, filtro por categoria e paginação.
 * Só leitura, sem login. (Era publico/produtos.php.)
 *   GET /api/publico/produtos?busca=..&categoria=slug&pagina=1&por_pagina=50
 */
export async function rotasProdutosPublicos(app: FastifyInstance): Promise<void> {
  app.get<{ Querystring: Record<string, unknown> }>("/", async (requisicao) => {
    const consulta = requisicao.query;
    const busca = texto(consulta["busca"]).trim();
    const categoriaSlug =
      consulta["categoria"] === undefined ? "todos" : texto(consulta["categoria"]).trim();
    const { pagina, porPagina, offset } = lerPaginacao(consulta, 50);

    const linhas = await chamarProcedimento<LinhaProduto>(
      "CALL sp_listar_produtos(?, ?, ?, ?)",
      [busca, categoriaSlug, porPagina, offset]
    );

    const contagem = await chamarProcedimento<RowDataPacket>(
      "CALL sp_contar_produtos(?, ?)",
      [busca, categoriaSlug]
    );
    const total = Number(contagem[0]?.["total"] ?? 0);

    return sucesso({
      produtos: linhas.map(normalizarProduto),
      total,
      pagina,
      por_pagina: porPagina,
    });
  });
}