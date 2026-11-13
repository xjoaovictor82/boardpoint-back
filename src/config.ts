import path from "node:path";

/** Lê uma variável de ambiente. Se não existir, usa o padrão. (String vazia é um valor válido.) */
function valor(nome: string, padrao = ""): string {
  const v = process.env[nome];
  return v !== undefined ? v : padrao;
}

export const config = {
  porta: Number(valor("PORTA", "3000")),

  db: {
    host: valor("DB_HOST", "127.0.0.1"),
    nome: valor("DB_NAME", "boardpoint"),
    usuario: valor("DB_USER", "root"),
    senha: valor("DB_PASS", ""),
  },

  jwt: {
    segredo: valor("JWT_SECRET"),
    validadeSegundos: 60 * 60 * 8, // o token vale 8 horas
  },

  pix: {
    chave: valor("PIX_CHAVE"),
    nomeRecebedor: valor("PIX_NOME_RECEBEDOR", "BOARD POINT"),
    cidade: valor("PIX_CIDADE", "CAMPO MOURAO"),
  },

  geminiApiKey: valor("GEMINI_API_KEY"),

  /** Onde as fotos enviadas pelo admin são gravadas. */
  pastaUploads: valor(
    "PASTA_UPLOADS",
    path.resolve(process.cwd(), "../front/produtos/uploads")
  ),
} as const;