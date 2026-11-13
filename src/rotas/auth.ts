import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import bcrypt from "bcryptjs";
import { consultar } from "../db.js";
import { emitirToken, exigirLogin } from "../autenticacao.js";
import { corpoJson, texto } from "../corpo.js";
import { erro, sucesso } from "../respostas.js";
import { validarEmail } from "../validacoes.js";

interface LinhaAdmin extends RowDataPacket {
  id: number;
  usuario: string;
  senha_hash: string;
}

/** Login do admin: e-mail + senha. Devolve um JWT. (Era o auth.php.) */
export async function rotasAuth(app: FastifyInstance): Promise<void> {
  app.post("/login", async (requisicao) => {
    const dados = corpoJson(requisicao);
    const email = texto(dados["email"]).trim().toLowerCase();
    const senha = texto(dados["senha"]);

    if (email === "" || senha === "") {
      erro(422, "Informe e-mail e senha.");
    }
    if (!validarEmail(email)) {
      erro(422, "E-mail inválido.");
    }

    const linhas = await consultar<LinhaAdmin>(
      "SELECT id, usuario, senha_hash FROM admins WHERE email = ?",
      [email]
    );
    const admin: LinhaAdmin | undefined = linhas[0];

    // Mesma mensagem para "e-mail não existe" e "senha errada".
    if (admin === undefined || !(await bcrypt.compare(senha, admin.senha_hash))) {
      erro(401, "E-mail ou senha inválidos.");
    }

    const token = emitirToken(Number(admin.id), "admin", { usuario: admin.usuario });
    return sucesso({ token, usuario: admin.usuario }, "Login realizado com sucesso.");
  });

  app.get("/status", async (requisicao) => {
    const payload = exigirLogin(requisicao, "admin");
    return sucesso({ autenticado: true, usuario: payload.usuario ?? "" });
  });

  app.post("/logout", async () => {
    // JWT não fica guardado no servidor: sair = o front apagar o token.
    return sucesso(null, "Sessão encerrada.");
  });
}