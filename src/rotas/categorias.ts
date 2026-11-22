import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { consultar, executar, erroDoBanco } from "../db.js";
import { exigirLogin } from "../autenticacao.js";
import { corpoJson, texto } from "../corpo.js";
import { normalizarCategoria } from "../normalizadores.js";
import type { LinhaCategoria } from "../normalizadores.js";
import { lerPaginacao, respostaPaginada } from "../paginacao.js";
import { erro, sucesso } from "../respostas.js";
import { lerId } from "../validacoes.js";

/** Confere nome e slug. Devolve os dois já limpos, ou interrompe com erro 422. */
function validarDadosCategoria(dados: Record<string, unknown>): { nome: string; slug: string } {
  const nome = texto(dados["nome"]).trim();
  const slug = texto(dados["slug"]).trim().toLowerCase();

  if (nome === "" || slug === "") {
    erro(422, "Nome e slug são obrigatórios.");
  }
  if ([...nome].length > 100) {
    erro(422, "O nome pode ter no máximo 100 caracteres.");
  }
  if (slug.length > 60 || !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(slug)) {
    erro(422, "Slug inválido: use só letras minúsculas, números e hífen (máx. 60).");
  }
  return { nome, slug };
}

/** CRUD administrativo de categorias. Tudo aqui exige login de admin. (Era o categorias.php.) */
export async function rotasCategorias(app: FastifyInstance): Promise<void> {
  // Roda antes de TODA rota deste arquivo: sem token de admin, nem chega nelas.
  app.addHook("preHandler", async (requisicao) => {
    exigirLogin(requisicao, "admin");
  });

  // Lista paginada: GET /api/categorias?pagina=1&por_pagina=10
  app.get<{ Querystring: Record<string, unknown> }>("/", async (requisicao) => {
    const { pagina, porPagina, offset } = lerPaginacao(requisicao.query);

    const contagem = await consultar<RowDataPacket>("SELECT COUNT(*) AS total FROM categorias");
    const total = Number(contagem[0]?.["total"] ?? 0);

    const linhas = await consultar<LinhaCategoria>(
      "SELECT id, slug, nome FROM categorias ORDER BY nome LIMIT ? OFFSET ?",
      [porPagina, offset]
    );
    return respostaPaginada(linhas.map(normalizarCategoria), total, pagina, porPagina);
  });

  // Uma categoria: GET /api/categorias/3
  app.get<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const linhas = await consultar<LinhaCategoria>(
      "SELECT id, slug, nome FROM categorias WHERE id = ?",
      [id]
    );
    const categoria = linhas[0];
    if (categoria === undefined) {
      erro(404, "Categoria não encontrada.");
    }
    return sucesso(normalizarCategoria(categoria));
  });

  // Criar: POST /api/categorias  { nome, slug }
  app.post("/", async (requisicao, resposta) => {
    const { nome, slug } = validarDadosCategoria(corpoJson(requisicao));

    try {
      const resultado = await executar(
        "INSERT INTO categorias (slug, nome) VALUES (?, ?)",
        [slug, nome]
      );
      resposta.code(201);
      return sucesso({ id: resultado.insertId, slug, nome }, "Categoria criada com sucesso.");
    } catch (err) {
      if (erroDoBanco(err, "ER_DUP_ENTRY")) {
        erro(409, "Já existe uma categoria com esse slug.");
      }
      throw err; // qualquer outro erro cai no tratador geral (500)
    }
  });

  // Editar: PUT /api/categorias/3  { nome, slug }
  app.put<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const existe = await consultar<RowDataPacket>("SELECT id FROM categorias WHERE id = ?", [id]);
    if (existe.length === 0) {
      erro(404, "Categoria não encontrada.");
    }

    const { nome, slug } = validarDadosCategoria(corpoJson(requisicao));

    try {
      await executar("UPDATE categorias SET nome = ?, slug = ? WHERE id = ?", [nome, slug, id]);
    } catch (err) {
      if (erroDoBanco(err, "ER_DUP_ENTRY")) {
        erro(409, "Já existe uma categoria com esse slug.");
      }
      throw err;
    }
    return sucesso({ id, slug, nome }, "Categoria atualizada com sucesso.");
  });

  // Excluir: DELETE /api/categorias/3
  app.delete<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    let afetadas = 0;
    try {
      const resultado = await executar("DELETE FROM categorias WHERE id = ?", [id]);
      afetadas = resultado.affectedRows;
    } catch (err) {
      if (erroDoBanco(err, "ER_ROW_IS_REFERENCED_2")) {
        erro(409, "Não é possível excluir: existem produtos vinculados a essa categoria.");
      }
      throw err;
    }
    if (afetadas === 0) {
      erro(404, "Categoria não encontrada.");
    }
    return sucesso(null, "Categoria excluída com sucesso.");
  });
}