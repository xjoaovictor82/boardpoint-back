import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import bcrypt from "bcryptjs";
import { consultar, erroDoBanco, executar } from "../db.js";
import { emitirToken, exigirLogin } from "../autenticacao.js";
import { corpoJson, texto } from "../corpo.js";
import { erro, sucesso } from "../respostas.js";
import { gerarSenhaSugerida } from "../senhaSugerida.js";
import { validarCPF, validarEmail, validarSenha } from "../validacoes.js";

const MENSAGEM_SENHA =
  "A senha deve ter no mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo.";

interface LinhaLogin extends RowDataPacket {
  id: number;
  nome: string;
  senha_hash: string;
}

interface LinhaPerfil extends RowDataPacket {
  id: number;
  nome: string;
  email: string;
  cpf: string;
}

/** Tira tudo que não é número do CPF. */
function soDigitos(valor: string): string {
  return valor.replace(/\D/g, "");
}

function validarNome(nome: string): void {
  const tamanho = [...nome].length;
  if (tamanho < 2 || tamanho > 150) {
    erro(422, "Informe seu nome completo.");
  }
}

/**
 * Cadastro, login e perfil dos CLIENTES da loja (tabela usuarios).
 * O login devolve um JWT do tipo "cliente"; o token de admin nunca vale aqui.
 * (Era o clientes.php.)
 */
export async function rotasClientes(app: FastifyInstance): Promise<void> {
  // Cadastro: POST /api/clientes/registrar  { nome, email, cpf, senha }
  app.post("/registrar", async (requisicao, resposta) => {
    const dados = corpoJson(requisicao);
    const nome = texto(dados["nome"]).trim();
    const email = texto(dados["email"]).trim().toLowerCase();
    const cpf = soDigitos(texto(dados["cpf"]));
    const senha = texto(dados["senha"]);

    validarNome(nome);
    if (!validarEmail(email)) {
      erro(422, "E-mail inválido.");
    }
    if (!validarCPF(cpf)) {
      erro(422, "CPF inválido.");
    }
    if (!validarSenha(senha)) {
      erro(422, MENSAGEM_SENHA);
    }

    const existente = await consultar<RowDataPacket>(
      "SELECT id FROM usuarios WHERE email = ? OR cpf = ?",
      [email, cpf]
    );
    if (existente.length > 0) {
      erro(409, "E-mail ou CPF já cadastrado.");
    }

    const senhaHash = await bcrypt.hash(senha, 10);
    try {
      await executar("INSERT INTO usuarios (nome, email, cpf, senha_hash) VALUES (?, ?, ?, ?)", [
        nome,
        email,
        cpf,
        senhaHash,
      ]);
    } catch (err) {
      // Duas requisições ao mesmo tempo com o mesmo e-mail/CPF: o banco barra a segunda.
      if (erroDoBanco(err, "ER_DUP_ENTRY")) {
        erro(409, "E-mail ou CPF já cadastrado.");
      }
      throw err;
    }

    resposta.code(201);
    return sucesso(null, "Cadastro realizado com sucesso!");
  });

  // Login: POST /api/clientes/login  { email, senha }
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

    const linhas = await consultar<LinhaLogin>(
      "SELECT id, nome, senha_hash FROM usuarios WHERE email = ?",
      [email]
    );
    const cliente: LinhaLogin | undefined = linhas[0];

    // Mesma mensagem para "e-mail não existe" e "senha errada".
    if (cliente === undefined || !(await bcrypt.compare(senha, cliente.senha_hash))) {
      erro(401, "E-mail ou senha inválidos.");
    }

    const token = emitirToken(Number(cliente.id), "cliente", { nome: cliente.nome });
    return sucesso({ token, nome: cliente.nome }, "Login realizado com sucesso.");
  });

  // Sair: POST /api/clientes/logout
  app.post("/logout", async () => {
    // JWT não fica guardado no servidor: sair = o front apagar o token.
    return sucesso(null, "Sessão encerrada.");
  });

  // Está logado?: GET /api/clientes/status
  app.get("/status", async (requisicao) => {
    const payload = exigirLogin(requisicao, "cliente");
    return sucesso({ autenticado: true, nome: payload.nome ?? "" });
  });

  // Ver o próprio perfil: GET /api/clientes/perfil
  app.get("/perfil", async (requisicao) => {
    // O id vem SEMPRE do token: ninguém consegue ver o perfil de outra pessoa.
    const payload = exigirLogin(requisicao, "cliente");

    const linhas = await consultar<LinhaPerfil>(
      "SELECT id, nome, email, cpf FROM usuarios WHERE id = ?",
      [payload.sub]
    );
    const usuario = linhas[0];
    if (usuario === undefined) {
      erro(404, "Usuário não encontrado.");
    }
    return sucesso({
      id: Number(usuario.id),
      nome: usuario.nome,
      email: usuario.email,
      cpf: usuario.cpf,
    });
  });

  // Editar o próprio perfil: PUT /api/clientes/perfil  { nome, email, cpf, senha? }
  app.put("/perfil", async (requisicao) => {
    const payload = exigirLogin(requisicao, "cliente");
    const idToken = payload.sub;

    const dados = corpoJson(requisicao);
    const nome = texto(dados["nome"]).trim();
    const email = texto(dados["email"]).trim().toLowerCase();
    const cpf = soDigitos(texto(dados["cpf"]));
    const senha = texto(dados["senha"]); // opcional: vazio = mantém a atual

    if (nome === "" || email === "" || cpf === "") {
      erro(422, "Preencha nome, e-mail e CPF.");
    }
    validarNome(nome);
    if (!validarEmail(email)) {
      erro(422, "E-mail inválido.");
    }
    if (!validarCPF(cpf)) {
      erro(422, "CPF inválido.");
    }
    if (senha !== "" && !validarSenha(senha)) {
      erro(422, MENSAGEM_SENHA);
    }

    const linhas = await consultar<LinhaPerfil>(
      "SELECT id, nome, email, cpf FROM usuarios WHERE id = ?",
      [idToken]
    );
    const atual = linhas[0];
    if (atual === undefined) {
      erro(404, "Usuário não encontrado.");
    }
    if (email !== atual.email) {
      erro(422, "O e-mail não pode ser alterado.");
    }

    const outro = await consultar<RowDataPacket>(
      "SELECT id FROM usuarios WHERE cpf = ? AND id <> ?",
      [cpf, idToken]
    );
    if (outro.length > 0) {
      erro(409, "Este CPF já está cadastrado em outra conta.");
    }

    try {
      if (senha !== "") {
        await executar("UPDATE usuarios SET nome = ?, cpf = ?, senha_hash = ? WHERE id = ?", [
          nome,
          cpf,
          await bcrypt.hash(senha, 10),
          idToken,
        ]);
      } else {
        await executar("UPDATE usuarios SET nome = ?, cpf = ? WHERE id = ?", [nome, cpf, idToken]);
      }
    } catch (err) {
      if (erroDoBanco(err, "ER_DUP_ENTRY")) {
        erro(409, "Este CPF já está cadastrado em outra conta.");
      }
      throw err;
    }

    return sucesso({ id: idToken, nome, email: atual.email, cpf }, "Perfil atualizado com sucesso.");
  });

  // Sugestão de senha forte: POST /api/clientes/sugerir-senha
  app.post("/sugerir-senha", async () => {
    return sucesso(await gerarSenhaSugerida());
  });
}