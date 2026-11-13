import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { chamarProcedimento, consultar, erroDoBanco, executar } from "../db.js";
import { exigirLogin } from "../autenticacao.js";
import { texto } from "../corpo.js";
import { normalizarProduto } from "../normalizadores.js";
import type { LinhaProduto } from "../normalizadores.js";
import { lerPaginacao, respostaPaginada } from "../paginacao.js";
import { erro, sucesso } from "../respostas.js";
import { apagarImagemEnviada, lerFormulario, salvarImagem } from "../uploads.js";
import { lerId } from "../validacoes.js";

interface DadosProduto {
  categoriaId: number;
  nome: string;
  preco: number | null;
  estoque: number;
}

/** Confere os campos do formulário. Devolve os dados limpos, ou interrompe com erro 422. */
async function lerDadosProduto(campos: Record<string, string>): Promise<DadosProduto> {
  const categoriaId = lerId(campos["categoria_id"]);
  const nome = texto(campos["nome"]).trim();
  const precoBruto = texto(campos["preco"]).trim();
  const estoqueBruto = texto(campos["estoque"]).trim() || "0";

  if (categoriaId === 0 || nome === "") {
    erro(422, "Categoria e nome são obrigatórios.");
  }
  if ([...nome].length > 150) {
    erro(422, "O nome pode ter no máximo 150 caracteres.");
  }

  // Sem preço = "Consulte o valor" no site.
  let preco: number | null = null;
  if (precoBruto !== "" && precoBruto !== "null") {
    const normalizado = precoBruto.replace(",", ".");
    if (!/^\d+(\.\d+)?$/.test(normalizado) || Number(normalizado) > 99999999.99) {
      erro(422, "Preço inválido.");
    }
    preco = Math.round(Number(normalizado) * 100) / 100;
  }

  if (!/^\d+$/.test(estoqueBruto) || Number(estoqueBruto) > 1000000) {
    erro(422, "Estoque inválido: use um número inteiro maior ou igual a zero.");
  }

  const categoria = await consultar<RowDataPacket>(
    "SELECT id FROM categorias WHERE id = ?",
    [categoriaId]
  );
  if (categoria.length === 0) {
    erro(422, "Categoria inexistente.");
  }

  return { categoriaId, nome, preco, estoque: Number(estoqueBruto) };
}

/** CRUD administrativo de produtos. Tudo aqui exige login de admin. (Era o produtos.php.) */
export async function rotasProdutos(app: FastifyInstance): Promise<void> {
  // Roda antes de TODA rota deste arquivo: sem token de admin, nem chega nelas.
  app.addHook("preHandler", async (requisicao) => {
    exigirLogin(requisicao, "admin");
  });

  // Lista paginada: GET /api/produtos?pagina=1&por_pagina=10&busca=..&categoria=slug
  app.get<{ Querystring: Record<string, unknown> }>("/", async (requisicao) => {
    const consulta = requisicao.query;
    const busca = texto(consulta["busca"]).trim();
    const categoriaSlug =
      consulta["categoria"] === undefined ? "todos" : texto(consulta["categoria"]).trim();
    const { pagina, porPagina, offset } = lerPaginacao(consulta);

    const linhas = await chamarProcedimento<LinhaProduto>(
      "CALL sp_listar_produtos(?, ?, ?, ?)",
      [busca, categoriaSlug, porPagina, offset]
    );
    const contagem = await chamarProcedimento<RowDataPacket>(
      "CALL sp_contar_produtos(?, ?)",
      [busca, categoriaSlug]
    );
    const total = Number(contagem[0]?.["total"] ?? 0);

    return respostaPaginada(linhas.map(normalizarProduto), total, pagina, porPagina);
  });

  // Um produto: GET /api/produtos/3
  app.get<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const linhas = await consultar<LinhaProduto>(
      `SELECT id, nome, preco, estoque, imagem, ativo, categoria_id, categoria_slug, categoria_nome
       FROM vw_produtos_completo WHERE id = ?`,
      [id]
    );
    const produto = linhas[0];
    if (produto === undefined) {
      erro(404, "Produto não encontrado.");
    }
    return sucesso(normalizarProduto(produto));
  });

  // Criar: POST /api/produtos  (multipart: categoria_id, nome, preco, estoque, imagem)
  app.post("/", async (requisicao, resposta) => {
    const { campos, imagem } = await lerFormulario(requisicao);
    const dados = await lerDadosProduto(campos);

    if (imagem === null) {
      erro(422, "A imagem do produto é obrigatória.");
    }
    const caminhoImagem = await salvarImagem(imagem);

    const resultado = await executar(
      "INSERT INTO produtos (categoria_id, nome, preco, estoque, imagem) VALUES (?, ?, ?, ?, ?)",
      [dados.categoriaId, dados.nome, dados.preco, dados.estoque, caminhoImagem]
    );

    resposta.code(201);
    return sucesso({ id: resultado.insertId, imagem: caminhoImagem }, "Produto criado com sucesso.");
  });

  // Editar: PUT /api/produtos/3  (multipart; a imagem é opcional)
  app.put<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const { campos, imagem } = await lerFormulario(requisicao);

    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    // 404 antes de validar e antes de gravar qualquer imagem (não deixa arquivo órfão).
    const existente = await consultar<RowDataPacket>("SELECT imagem FROM produtos WHERE id = ?", [id]);
    const atual = existente[0];
    if (atual === undefined) {
      erro(404, "Produto não encontrado.");
    }

    const dados = await lerDadosProduto(campos);
    const novaImagem = imagem !== null ? await salvarImagem(imagem) : null;

    if (novaImagem !== null) {
      await executar(
        "UPDATE produtos SET categoria_id = ?, nome = ?, preco = ?, estoque = ?, imagem = ? WHERE id = ?",
        [dados.categoriaId, dados.nome, dados.preco, dados.estoque, novaImagem, id]
      );
      await apagarImagemEnviada(texto(atual["imagem"]));
    } else {
      await executar(
        "UPDATE produtos SET categoria_id = ?, nome = ?, preco = ?, estoque = ? WHERE id = ?",
        [dados.categoriaId, dados.nome, dados.preco, dados.estoque, id]
      );
    }
    return sucesso({ id }, "Produto atualizado com sucesso.");
  });

  // Excluir: DELETE /api/produtos/3
  app.delete<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const linhas = await consultar<RowDataPacket>("SELECT imagem FROM produtos WHERE id = ?", [id]);
    const produto = linhas[0];
    if (produto === undefined) {
      erro(404, "Produto não encontrado.");
    }

    try {
      await executar("DELETE FROM produtos WHERE id = ?", [id]);
    } catch (err) {
      if (erroDoBanco(err, "ER_ROW_IS_REFERENCED_2")) {
        erro(409, "Não é possível excluir: este produto aparece em pedidos.");
      }
      throw err;
    }

    await apagarImagemEnviada(texto(produto["imagem"]));
    return sucesso(null, "Produto excluído com sucesso.");
  });
}