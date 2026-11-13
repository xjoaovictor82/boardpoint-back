import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { consultar, executar } from "../db.js";
import { exigirLogin } from "../autenticacao.js";
import { corpoJson, texto } from "../corpo.js";
import { lerPaginacao, respostaPaginada } from "../paginacao.js";
import { erro, sucesso } from "../respostas.js";
import { lerId, validarEmail } from "../validacoes.js";

interface LinhaContato extends RowDataPacket {
  id: number;
  nome: string;
  email: string;
  mensagem: string;
  lida: number;
  criado_em: string;
}

// A data já sai como texto "2026-08-05 20:59:21", igual ao PHP (sem conversão de fuso).
const COLUNAS =
  "id, nome, email, mensagem, lida, DATE_FORMAT(criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em";

function normalizarContato(c: LinhaContato) {
  return {
    id: Number(c.id),
    nome: c.nome,
    email: c.email,
    mensagem: c.mensagem,
    lida: Boolean(c.lida),
    criado_em: c.criado_em,
  };
}

/** Confere nome, e-mail e mensagem. Devolve os três já limpos, ou interrompe com erro 422. */
function validarDadosContato(nomeBruto: string, emailBruto: string, mensagemBruta: string) {
  const nome = nomeBruto.trim();
  const email = emailBruto.trim().toLowerCase();
  const mensagem = mensagemBruta.trim();

  if (nome === "" || email === "" || mensagem === "") {
    erro(422, "Preencha nome, e-mail e mensagem.");
  }
  if ([...nome].length > 150) {
    erro(422, "O nome pode ter no máximo 150 caracteres.");
  }
  if (!validarEmail(email)) {
    erro(422, "E-mail inválido.");
  }
  if ([...mensagem].length > 5000) {
    erro(422, "A mensagem pode ter no máximo 5000 caracteres.");
  }
  return { nome, email, mensagem };
}

/** Aceita true, 1, "1", "true", "on" e "yes" como verdadeiro. O resto é falso. */
function paraBooleano(valor: unknown): boolean {
  if (valor === true || valor === 1) return true;
  if (typeof valor === "string") {
    return ["1", "true", "on", "yes"].includes(valor.trim().toLowerCase());
  }
  return false;
}

/** CRUD administrativo das mensagens de contato. Tudo aqui exige login de admin. (Era o contato.php.) */
export async function rotasContatos(app: FastifyInstance): Promise<void> {
  // Roda antes de TODA rota deste arquivo: sem token de admin, nem chega nelas.
  app.addHook("preHandler", async (requisicao) => {
    exigirLogin(requisicao, "admin");
  });

  // Lista paginada: GET /api/contatos?pagina=1&por_pagina=10&lida=0
  app.get<{ Querystring: Record<string, unknown> }>("/", async (requisicao) => {
    const consulta = requisicao.query;
    const { pagina, porPagina, offset } = lerPaginacao(consulta);

    // Filtro opcional: só mensagens lidas (1) ou não lidas (0).
    let filtro = "";
    const parametros: unknown[] = [];
    const lida = texto(consulta["lida"]);
    if (lida === "0" || lida === "1") {
      filtro = "WHERE lida = ?";
      parametros.push(Number(lida));
    }

    const contagem = await consultar<RowDataPacket>(
      `SELECT COUNT(*) AS total FROM contatos ${filtro}`,
      parametros
    );
    const total = Number(contagem[0]?.["total"] ?? 0);

    const linhas = await consultar<LinhaContato>(
      `SELECT ${COLUNAS} FROM contatos ${filtro}
       ORDER BY contatos.criado_em DESC, id DESC LIMIT ? OFFSET ?`,
      [...parametros, porPagina, offset]
    );
    return respostaPaginada(linhas.map(normalizarContato), total, pagina, porPagina);
  });

  // Uma mensagem: GET /api/contatos/3
  app.get<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const linhas = await consultar<LinhaContato>(
      `SELECT ${COLUNAS} FROM contatos WHERE id = ?`,
      [id]
    );
    const contato = linhas[0];
    if (contato === undefined) {
      erro(404, "Mensagem não encontrada.");
    }
    return sucesso(normalizarContato(contato));
  });

  // Cadastrar pelo painel: POST /api/contatos  { nome, email, mensagem }
  app.post("/", async (requisicao, resposta) => {
    const dados = corpoJson(requisicao);
    const { nome, email, mensagem } = validarDadosContato(
      texto(dados["nome"]),
      texto(dados["email"]),
      texto(dados["mensagem"])
    );

    const resultado = await executar(
      "INSERT INTO contatos (nome, email, mensagem) VALUES (?, ?, ?)",
      [nome, email, mensagem]
    );
    const linhas = await consultar<LinhaContato>(
      `SELECT ${COLUNAS} FROM contatos WHERE id = ?`,
      [resultado.insertId]
    );
    const criado = linhas[0];
    if (criado === undefined) {
      erro(500, "Erro interno do servidor.");
    }

    resposta.code(201);
    return sucesso(normalizarContato(criado), "Mensagem cadastrada com sucesso.");
  });

  // Editar: PUT /api/contatos/3  { nome?, email?, mensagem?, lida? }
  app.put<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const existente = await consultar<LinhaContato>(
      `SELECT ${COLUNAS} FROM contatos WHERE id = ?`,
      [id]
    );
    const atual = existente[0];
    if (atual === undefined) {
      erro(404, "Mensagem não encontrada.");
    }

    const dados = corpoJson(requisicao);
    const editaveis = ["nome", "email", "mensagem", "lida"];
    if (!editaveis.some((campo) => campo in dados)) {
      erro(422, "Informe o que deseja alterar: nome, email, mensagem ou lida.");
    }

    // Campo que não veio no pedido mantém o valor que já estava no banco.
    const { nome, email, mensagem } = validarDadosContato(
      "nome" in dados ? texto(dados["nome"]) : atual.nome,
      "email" in dados ? texto(dados["email"]) : atual.email,
      "mensagem" in dados ? texto(dados["mensagem"]) : atual.mensagem
    );
    const lida = "lida" in dados ? paraBooleano(dados["lida"]) : Boolean(atual.lida);

    await executar(
      "UPDATE contatos SET nome = ?, email = ?, mensagem = ?, lida = ? WHERE id = ?",
      [nome, email, mensagem, lida ? 1 : 0, id]
    );
    return sucesso({ id, nome, email, mensagem, lida }, "Mensagem atualizada.");
  });

  // Excluir: DELETE /api/contatos/3
  app.delete<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const resultado = await executar("DELETE FROM contatos WHERE id = ?", [id]);
    if (resultado.affectedRows === 0) {
      erro(404, "Mensagem não encontrada.");
    }
    return sucesso(null, "Mensagem removida.");
  });
}