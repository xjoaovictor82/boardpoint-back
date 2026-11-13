import mysql from "mysql2/promise";
import type { ResultSetHeader, RowDataPacket } from "mysql2/promise";
import { config } from "./config.js";

/** Conexões reutilizáveis com o MariaDB (equivale ao getConexao() do PHP). */
export const pool = mysql.createPool({
  host: config.db.host,
  user: config.db.usuario,
  password: config.db.senha,
  database: config.db.nome,
  charset: "utf8mb4",
  waitForConnections: true,
  connectionLimit: 10,
});

/** SELECT: devolve a lista de linhas. */
export async function consultar<T extends RowDataPacket>(
  sql: string,
  parametros: unknown[] = []
): Promise<T[]> {
  const [linhas] = await pool.query<T[]>(sql, parametros);
  return linhas;
}

/** INSERT / UPDATE / DELETE: devolve insertId e affectedRows. */
export async function executar(
  sql: string,
  parametros: unknown[] = []
): Promise<ResultSetHeader> {
  const [resultado] = await pool.query<ResultSetHeader>(sql, parametros);
  return resultado;
}