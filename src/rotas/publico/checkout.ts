import type { FastifyInstance } from "fastify";
import { config } from "../../config.js";
import { corpoJson, texto } from "../../corpo.js";
import { gravarPedido, validarCliente, validarItens } from "../../pedidos.js";
import { gerarPayloadPix } from "../../pix.js";
import { erro, sucesso } from "../../respostas.js";

/**
 * Cria um pedido a partir do carrinho e devolve o Pix. Só POST, sem login.
 * As regras de validação e a gravação ficam em pedidos.ts (o painel admin usa as mesmas).
 * (Era publico/checkout.php.)
 */
export async function rotasCheckout(app: FastifyInstance): Promise<void> {
  app.post("/", async (requisicao) => {
    // Sem chave Pix não dá para cobrar: recusa antes de gravar qualquer coisa.
    if (config.pix.chave === "") {
      erro(503, "PIX indisponível: a chave PIX da loja ainda não foi configurada.");
    }

    const dados = corpoJson(requisicao);
    const cliente = validarCliente(
      texto(dados["nome"]),
      texto(dados["telefone"]),
      texto(dados["email"]),
      "Informe nome e telefone para contato."
    );
    const { itens, total } = await validarItens(dados["itens"]);
    const pedido = await gravarPedido(cliente, itens, total);

    const pixPayload = gerarPayloadPix(config.pix, total, pedido.codigo.replace("-", ""));
    return sucesso(
      { pedido_id: pedido.id, codigo: pedido.codigo, total, pix_payload: pixPayload },
      "Pedido criado com sucesso."
    );
  });
}