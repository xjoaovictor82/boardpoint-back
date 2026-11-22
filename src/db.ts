import mysql from "mysql2/promise";
import type { PoolConnection, ResultSetHeader, RowDataPacket } from "mysql2/promise";
import { config } from "./config.js";


/** Conexões reutilizáveis com o MariaDB (equivale ao getConexao() do PHP). */
export const pool = mysql.createPool({
  host: config.db.host,
  port: config.db.porta,
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
  parametros: unknown[] = [],
): Promise<T[]> {
  const [linhas] = await pool.query<T[]>(sql, parametros);
  return linhas;
}

/** INSERT / UPDATE / DELETE: devolve insertId e affectedRows. */
export async function executar(
  sql: string,
  parametros: unknown[] = [],
): Promise<ResultSetHeader> {
  const [resultado] = await pool.query<ResultSetHeader>(sql, parametros);
  return resultado;
}


/** true se o erro do MariaDB tem o código informado (ex.: "ER_DUP_ENTRY"). */
export function erroDoBanco(err: unknown, codigo: string): boolean {
  return (
    typeof err === "object" &&
    err !== null &&
    "code" in err &&
    (err as { code: unknown }).code === codigo
  );
}
/** CALL de procedure que devolve linhas: devolve só a lista de linhas do resultado. */
export async function chamarProcedimento<T extends RowDataPacket>(
  sql: string,
  parametros: unknown[] = [],
): Promise<T[]> {
  const [resultado] = await pool.query<RowDataPacket[][]>(sql, parametros);
  return (resultado[0] ?? []) as T[];
}

/**
 * Roda várias gravações como "tudo ou nada": se qualquer uma falhar, nenhuma vale.
 * Use a `conexao` recebida (e não o `executar`) dentro do trabalho.
 */
export async function emTransacao<T>(
  trabalho: (conexao: PoolConnection) => Promise<T>,
): Promise<T> {
  const conexao = await pool.getConnection();
  try {
    await conexao.beginTransaction();
    const resultado = await trabalho(conexao);
    await conexao.commit();
    return resultado;
  } catch (err) {
    await conexao.rollback();
    throw err;
  } finally {
    conexao.release();
  }
}