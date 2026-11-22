import { randomBytes } from "node:crypto";
import type { ResultSetHeader, RowDataPacket } from "mysql2/promise";
import { consultar, emTransacao } from "./db.js";
import { erro } from "./respostas.js";
import { lerId, validarEmail } from "./validacoes.js";

/** Regras e gravação de pedidos, compartilhadas pelo checkout público e pelo painel admin. */

export interface Cliente {
  nome: string;
  telefone: string;
  email: string; // "" = sem e-mail
}

export interface ItemValidado {
  produtoId: number;
  produtoNome: string;
  precoUnitario: number;
  quantidade: number;
}

interface LinhaProdutoPedido extends RowDataPacket {
  id: number;
  nome: string;
  preco: string | number | null;
  estoque: number;
  ativo: number;
}

/** Limpa e confere os dados do cliente. Interrompe com erro 422 se algo estiver errado. */
export function validarCliente(
  nomeBruto: string,
  telefoneBruto: string,
  emailBruto: string,
  mensagemObrigatorios: string
): Cliente {
  const nome = nomeBruto.trim();
  const telefone = telefoneBruto.trim();
  const email = emailBruto.trim();

  if (nome === "" || telefone === "") {
    erro(422, mensagemObrigatorios);
  }
  if ([...nome].length > 150 || [...telefone].length > 30) {
    erro(422, "Nome ou telefone muito longos.");
  }
  if (email !== "" && !validarEmail(email)) {
    erro(422, "E-mail inválido.");
  }
  return { nome, telefone, email };
}

/**
 * Confere os itens do carrinho contra o banco: produto existe e está ativo, tem preço e
 * tem estoque. Linhas repetidas do mesmo produto são somadas antes de conferir o estoque.
 * O total é somado em centavos, para não ter erro de arredondamento.
 */
export async function validarItens(
  itensBrutos: unknown
): Promise<{ itens: ItemValidado[]; total: number }> {
  if (!Array.isArray(itensBrutos) || itensBrutos.length === 0) {
    erro(422, "Carrinho vazio.");
  }
  if (itensBrutos.length > 50) {
    erro(422, "Informe no máximo 50 itens por pedido.");
  }

  const quantidades = new Map<number, number>();
  for (const bruto of itensBrutos as unknown[]) {
    const item =
      typeof bruto === "object" && bruto !== null ? (bruto as Record<string, unknown>) : {};
    const produtoId = lerId(item["produto_id"]);
    const quantidade = lerId(item["quantidade"]);
    if (produtoId === 0 || quantidade === 0 || quantidade > 1000) {
      erro(422, "Item inválido: informe produto e uma quantidade entre 1 e 1000.");
    }
    quantidades.set(produtoId, (quantidades.get(produtoId) ?? 0) + quantidade);
  }

  const itens: ItemValidado[] = [];
  let totalCentavos = 0;

  for (const [produtoId, quantidade] of quantidades) {
    const linhas = await consultar<LinhaProdutoPedido>(
      "SELECT id, nome, preco, estoque, ativo FROM produtos WHERE id = ?",
      [produtoId]
    );
    const produto = linhas[0];

    if (produto === undefined || !produto.ativo) {
      erro(422, `O produto ${produtoId} não existe ou não está disponível.`);
    }
    if (produto.preco === null) {
      erro(422, `O produto "${produto.nome}" está sem preço e não pode ser vendido.`);
    }
    if (quantidade > Number(produto.estoque)) {
      erro(422, `Estoque insuficiente para "${produto.nome}".`);
    }

    const precoUnitario = Number(produto.preco);
    totalCentavos += Math.round(precoUnitario * 100) * quantidade;
    itens.push({
      produtoId: Number(produto.id),
      produtoNome: produto.nome,
      precoUnitario,
      quantidade,
    });
  }

  return { itens, total: totalCentavos / 100 };
}

/** Sorteia um código BP-XXXXXX que ainda não existe. */
async function gerarCodigoUnico(): Promise<string> {
  for (;;) {
    const codigo = `BP-${randomBytes(4).toString("hex").slice(0, 6).toUpperCase()}`;
    const existe = await consultar<RowDataPacket>(
      "SELECT 1 AS ok FROM pedidos WHERE codigo = ?",
      [codigo]
    );
    if (existe.length === 0) return codigo;
  }
}

/** Grava o pedido e os itens numa transação ("tudo ou nada"). Nasce aguardando pagamento. */
export async function gravarPedido(
  cliente: Cliente,
  itens: ItemValidado[],
  total: number
): Promise<{ id: number; codigo: string }> {
  const codigo = await gerarCodigoUnico();

  const id = await emTransacao(async (conexao) => {
    const [pedido] = await conexao.query<ResultSetHeader>(
      `INSERT INTO pedidos (codigo, cliente_nome, cliente_telefone, cliente_email, total, status)
       VALUES (?, ?, ?, ?, ?, 'aguardando_pagamento')`,
      [codigo, cliente.nome, cliente.telefone, cliente.email === "" ? null : cliente.email, total]
    );
    for (const item of itens) {
      await conexao.query(
        `INSERT INTO pedido_itens (pedido_id, produto_id, produto_nome, preco_unitario, quantidade)
         VALUES (?, ?, ?, ?, ?)`,
        [pedido.insertId, item.produtoId, item.produtoNome, item.precoUnitario, item.quantidade]
      );
    }
    return pedido.insertId;
  });

  return { id, codigo };
}