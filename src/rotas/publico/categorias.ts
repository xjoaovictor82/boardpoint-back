import type { FastifyInstance } from "fastify";
import { consultar } from "../../db.js";
import { normalizarCategoria } from "../../normalizadores.js";
import type { LinhaCategoria } from "../../normalizadores.js";
import { sucesso } from "../../respostas.js";

/** Lista pública de categorias para o site. Só leitura, sem login. (Era publico/categorias.php.) */
export async function rotasCategoriasPublicas(app: FastifyInstance): Promise<void> {
  app.get("/", async () => {
    const linhas = await consultar<LinhaCategoria>(
      "SELECT id, slug, nome FROM categorias ORDER BY nome"
    );
    return sucesso(linhas.map(normalizarCategoria));
  });
}