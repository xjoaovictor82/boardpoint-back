import type { FastifyInstance } from "fastify";
import type { PoolConnection, RowDataPacket } from "mysql2/promise";
import { consultar, emTransacao } from "../db.js";
import { exigirLogin } from "../autenticacao.js";
import { corpoJson, texto } from "../corpo.js";
import { gravarPedido, validarCliente, validarItens } from "../pedidos.js";
import { lerPaginacao, respostaPaginada } from "../paginacao.js";
import { erro, sucesso } from "../respostas.js";
import { lerId } from "../validacoes.js";

const STATUS_VALIDOS: string[] = ["aguardando_pagamento", "pago", "cancelado"];

interface LinhaPedido extends RowDataPacket {
  id: number;
  codigo: string;
  cliente_nome: string;
  cliente_telefone: string;
  cliente_email: string | null;
  total: string | number;
  status: string;
  criado_em: string;
}

// A data já sai como texto "2026-08-05 20:59:21", igual ao PHP (sem conversão de fuso).
const COLUNAS = `id, codigo, cliente_nome, cliente_telefone, cliente_email, total, status,
  DATE_FORMAT(criado_em, '%Y-%m-%d %H:%i:%s') AS criado_em`;

function normalizarPedido(p: LinhaPedido) {
  return {
    id: Number(p.id),
    codigo: p.codigo,
    cliente_nome: p.cliente_nome,
    cliente_telefone: p.cliente_telefone,
    cliente_email: p.cliente_email,
    total: Number(p.total),
    status: p.status,
    criado_em: p.criado_em,
  };
}

async function itensDoPedido(pedidoId: number) {
  const linhas = await consultar<RowDataPacket>(
    "SELECT produto_id, produto_nome, preco_unitario, quantidade FROM pedido_itens WHERE pedido_id = ?",
    [pedidoId]
  );
  return linhas.map((it) => ({
    produto_id: Number(it["produto_id"]),
    produto_nome: String(it["produto_nome"]),
    preco_unitario: Number(it["preco_unitario"]),
    quantidade: Number(it["quantidade"]),
  }));
}

/** Abate (true) ou devolve (false) o estoque de todos os itens do pedido. */
async function ajustarEstoque(
  conexao: PoolConnection,
  pedidoId: number,
  abater: boolean
): Promise<void> {
  const [itens] = await conexao.query<RowDataPacket[]>(
    "SELECT produto_id, quantidade FROM pedido_itens WHERE pedido_id = ?",
    [pedidoId]
  );
  const sql = abater
    ? "UPDATE produtos SET estoque = GREATEST(0, estoque - ?) WHERE id = ?"
    : "UPDATE produtos SET estoque = estoque + ? WHERE id = ?";
  for (const item of itens) {
    await conexao.query(sql, [item["quantidade"], item["produto_id"]]);
  }
}

/** Trava a linha do pedido até o fim da transação e devolve o status atual. */
async function travarStatus(conexao: PoolConnection, id: number): Promise<string> {
  const [linhas] = await conexao.query<RowDataPacket[]>(
    "SELECT status FROM pedidos WHERE id = ? FOR UPDATE",
    [id]
  );
  const linha = linhas[0];
  if (linha === undefined) {
    erro(404, "Pedido não encontrado.");
  }
  return String(linha["status"]);
}

/** CRUD administrativo de pedidos. Tudo aqui exige login de admin. (Era o pedidos.php.) */
export async function rotasPedidos(app: FastifyInstance): Promise<void> {
  // Roda antes de TODA rota deste arquivo: sem token de admin, nem chega nelas.
  app.addHook("preHandler", async (requisicao) => {
    exigirLogin(requisicao, "admin");
  });

  // Lista paginada: GET /api/pedidos?pagina=1&por_pagina=10&status=pago
  app.get<{ Querystring: Record<string, unknown> }>("/", async (requisicao) => {
    const consulta = requisicao.query;
    const { pagina, porPagina, offset } = lerPaginacao(consulta);

    let filtro = "";
    const parametros: unknown[] = [];
    const status = texto(consulta["status"]);
    if (status !== "") {
      if (!STATUS_VALIDOS.includes(status)) {
        erro(422, "Status inválido.");
      }
      filtro = "WHERE status = ?";
      parametros.push(status);
    }

    const contagem = await consultar<RowDataPacket>(
      `SELECT COUNT(*) AS total FROM pedidos ${filtro}`,
      parametros
    );
    const total = Number(contagem[0]?.["total"] ?? 0);

    const linhas = await consultar<LinhaPedido>(
      `SELECT ${COLUNAS} FROM pedidos ${filtro}
       ORDER BY pedidos.criado_em DESC, id DESC LIMIT ? OFFSET ?`,
      [...parametros, porPagina, offset]
    );

    const pedidos = [];
    for (const linha of linhas) {
      pedidos.push({ ...normalizarPedido(linha), itens: await itensDoPedido(Number(linha.id)) });
    }
    return respostaPaginada(pedidos, total, pagina, porPagina);
  });

  // Um pedido: GET /api/pedidos/3
  app.get<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const linhas = await consultar<LinhaPedido>(`SELECT ${COLUNAS} FROM pedidos WHERE id = ?`, [id]);
    const linha = linhas[0];
    if (linha === undefined) {
      erro(404, "Pedido não encontrado.");
    }
    return sucesso({ ...normalizarPedido(linha), itens: await itensDoPedido(id) });
  });

  // Criar pelo painel: POST /api/pedidos  { cliente_nome, cliente_telefone, cliente_email?, itens }
  app.post("/", async (requisicao, resposta) => {
    const dados = corpoJson(requisicao);
    const cliente = validarCliente(
      texto(dados["cliente_nome"]),
      texto(dados["cliente_telefone"]),
      texto(dados["cliente_email"]),
      "Nome e telefone do cliente são obrigatórios."
    );
    const { itens, total } = await validarItens(dados["itens"]);
    const pedido = await gravarPedido(cliente, itens, total);

    resposta.code(201);
    return sucesso(
      { id: pedido.id, codigo: pedido.codigo, total, status: "aguardando_pagamento" },
      "Pedido criado com sucesso."
    );
  });

  // Editar: PUT /api/pedidos/3  { status?, cliente_nome?, cliente_telefone?, cliente_email? }
  app.put<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    const existente = await consultar<LinhaPedido>(`SELECT ${COLUNAS} FROM pedidos WHERE id = ?`, [id]);
    const atual = existente[0];
    if (atual === undefined) {
      erro(404, "Pedido não encontrado.");
    }

    const dados = corpoJson(requisicao);
    const mudaStatus = "status" in dados;
    const mudaCliente =
      "cliente_nome" in dados || "cliente_telefone" in dados || "cliente_email" in dados;
    if (!mudaStatus && !mudaCliente) {
      erro(422, "Informe o que deseja alterar: status ou dados do cliente.");
    }

    const novoStatus = mudaStatus ? texto(dados["status"]) : atual.status;
    if (!STATUS_VALIDOS.includes(novoStatus)) {
      erro(422, "Status inválido.");
    }

    // Campo que não veio no pedido mantém o valor que já estava no banco.
    const cliente = validarCliente(
      "cliente_nome" in dados ? texto(dados["cliente_nome"]) : atual.cliente_nome,
      "cliente_telefone" in dados ? texto(dados["cliente_telefone"]) : atual.cliente_telefone,
      "cliente_email" in dados ? texto(dados["cliente_email"]) : (atual.cliente_email ?? ""),
      "Nome e telefone do cliente são obrigatórios."
    );

    await emTransacao(async (conexao) => {
      // Relê o status com a linha travada: se dois cliques chegarem juntos, o segundo
      // espera o primeiro e vê o status novo, então o estoque nunca é abatido duas vezes.
      const statusAtual = await travarStatus(conexao, id);
      const abater = novoStatus === "pago" && statusAtual !== "pago";
      const devolver = novoStatus !== "pago" && statusAtual === "pago";

      await conexao.query(
        "UPDATE pedidos SET status = ?, cliente_nome = ?, cliente_telefone = ?, cliente_email = ? WHERE id = ?",
        [novoStatus, cliente.nome, cliente.telefone, cliente.email === "" ? null : cliente.email, id]
      );
      if (abater || devolver) {
        await ajustarEstoque(conexao, id, abater);
      }
    });

    return sucesso({ id, status: novoStatus }, "Pedido atualizado.");
  });

  // Excluir: DELETE /api/pedidos/3
  app.delete<{ Params: { id: string } }>("/:id", async (requisicao) => {
    const id = lerId(requisicao.params.id);
    if (id === 0) {
      erro(422, "ID inválido.");
    }

    await emTransacao(async (conexao) => {
      const statusAtual = await travarStatus(conexao, id);
      if (statusAtual === "pago") {
        await ajustarEstoque(conexao, id, false); // devolve o que o pagamento tinha abatido
      }
      // Os itens saem junto (FK com ON DELETE CASCADE).
      await conexao.query("DELETE FROM pedidos WHERE id = ?", [id]);
    });

    return sucesso(null, "Pedido excluído com sucesso.");
  });
}