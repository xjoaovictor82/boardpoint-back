import type { FastifyInstance } from "fastify";
import type { RowDataPacket } from "mysql2/promise";
import { chamarProcedimento, consultar } from "../db.js";
import { exigirLogin } from "../autenticacao.js";
import { erro, sucesso } from "../respostas.js";

interface LinhaEstatisticas extends RowDataPacket {
  total_produtos: number;
  total_categorias: number;
  produtos_com_preco: number;
  produtos_sem_estoque: number;
  estoque_total: number;
  preco_medio: string | number | null;
}

interface LinhaDashboardCategoria extends RowDataPacket {
  id: number;
  slug: string;
  nome: string;
  total_produtos: number;
}

/** Estatísticas do painel admin. Exige login de admin. (Era o dashboard.php.) */
export async function rotasDashboard(app: FastifyInstance): Promise<void> {
  app.addHook("preHandler", async (requisicao) => {
    exigirLogin(requisicao, "admin");
  });

  // GET /api/dashboard
  app.get("/", async () => {
    const estatisticas = await chamarProcedimento<LinhaEstatisticas>(
      "CALL sp_estatisticas_dashboard()"
    );
    const stats = estatisticas[0];
    if (stats === undefined) {
      erro(500, "Erro interno do servidor.");
    }

    const categorias = await consultar<LinhaDashboardCategoria>(
      "SELECT id, slug, nome, total_produtos FROM vw_dashboard_categorias"
    );

    return sucesso({
      total_produtos: Number(stats.total_produtos),
      total_categorias: Number(stats.total_categorias),
      produtos_com_preco: Number(stats.produtos_com_preco),
      produtos_sem_estoque: Number(stats.produtos_sem_estoque),
      estoque_total: Number(stats.estoque_total),
      preco_medio: Number(stats.preco_medio ?? 0),
      por_categoria: categorias.map((c) => ({
        id: Number(c.id),
        slug: c.slug,
        nome: c.nome,
        total_produtos: Number(c.total_produtos),
      })),
    });
  });
}