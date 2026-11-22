import type { FastifyInstance } from "fastify";
import { executar } from "../../db.js";
import { corpoJson, texto } from "../../corpo.js";
import { erro, sucesso } from "../../respostas.js";
import { validarEmail } from "../../validacoes.js";

/**
 * O visitante envia uma mensagem de contato (só POST, sem login).
 * Ler, marcar como lida e excluir ficam no arquivo do admin. (Era publico/contato.php.)
 */
export async function rotasContatoPublico(app: FastifyInstance): Promise<void> {
  app.post("/", async (requisicao) => {
    const dados = corpoJson(requisicao);
    const nome = texto(dados["nome"]).trim();
    const email = texto(dados["email"]).trim();
    const mensagem = texto(dados["mensagem"]).trim();

    if (nome === "" || email === "" || mensagem === "") {
      erro(422, "Preencha nome, e-mail e mensagem.");
    }
    if (!validarEmail(email)) {
      erro(422, "E-mail inválido.");
    }
    if ([...nome].length > 150) {
      erro(422, "O nome pode ter no máximo 150 caracteres.");
    }
    if ([...mensagem].length > 5000) {
      erro(422, "A mensagem pode ter no máximo 5000 caracteres.");
    }

    await executar("INSERT INTO contatos (nome, email, mensagem) VALUES (?, ?, ?)", [
      nome,
      email,
      mensagem,
    ]);
    return sucesso(null, "Mensagem enviada com sucesso.");
  });
}