import { randomUUID } from "node:crypto";
import { mkdir, unlink, writeFile } from "node:fs/promises";
import path from "node:path";
import type { FastifyRequest } from "fastify";
import { config } from "./config.js";
import { texto } from "./corpo.js";
import { erro } from "./respostas.js";

export interface ImagemEnviada {
  buffer: Buffer;
}

export interface Formulario {
  campos: Record<string, string>;
  imagem: ImagemEnviada | null;
}

/** Lê um formulário multipart: campos de texto + (opcional) o arquivo do campo "imagem". */
export async function lerFormulario(requisicao: FastifyRequest): Promise<Formulario> {
  const campos: Record<string, string> = {};
  let imagem: ImagemEnviada | null = null;

  if (!requisicao.isMultipart()) {
    return { campos, imagem };
  }

  try {
    for await (const parte of requisicao.parts()) {
      if (parte.type === "file") {
        const buffer = await parte.toBuffer();
        // Campo "imagem" vazio (nenhum arquivo escolhido) = sem imagem.
        if (parte.fieldname === "imagem" && buffer.length > 0) {
          imagem = { buffer };
        }
      } else {
        campos[parte.fieldname] = texto(parte.value);
      }
    }
  } catch (err) {
    if (typeof err === "object" && err !== null && "code" in err) {
      const codigo = (err as { code: unknown }).code;
      if (codigo === "FST_REQ_FILE_TOO_LARGE") {
        erro(422, "A imagem é grande demais (máximo 5 MB).");
      }
    }
    throw err;
  }

  return { campos, imagem };
}

/** Descobre o tipo pelo CONTEÚDO do arquivo (não pela extensão). null = formato não aceito. */
function detectarExtensao(b: Buffer): "jpg" | "png" | "webp" | null {
  if (b.length >= 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) {
    return "jpg";
  }
  if (
    b.length >= 8 &&
    b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))
  ) {
    return "png";
  }
  if (
    b.length >= 12 &&
    b.subarray(0, 4).toString("ascii") === "RIFF" &&
    b.subarray(8, 12).toString("ascii") === "WEBP"
  ) {
    return "webp";
  }
  return null;
}

/** Confere o formato da imagem ANTES de gravar qualquer coisa. Devolve a extensão. */
export function validarImagem(imagem: ImagemEnviada): "jpg" | "png" | "webp" {
  const extensao = detectarExtensao(imagem.buffer);
  if (extensao === null) {
    erro(422, "Formato de imagem não suportado. Use JPG, PNG ou WEBP.");
  }
  return extensao;
}

/** Grava a imagem na pasta de uploads. Devolve o caminho que vai para o banco. */
export async function salvarImagem(imagem: ImagemEnviada): Promise<string> {
  const extensao = validarImagem(imagem);
  await mkdir(config.pastaUploads, { recursive: true });

  const nomeArquivo = `prod_${randomUUID()}.${extensao}`;
  await writeFile(path.join(config.pastaUploads, nomeArquivo), imagem.buffer);

  return `produtos/uploads/${nomeArquivo}`;
}

/** Apaga a foto do disco, mas SÓ se ela foi enviada pelo painel (as originais do projeto ficam). */
export async function apagarImagemEnviada(caminho: string): Promise<void> {
  if (!caminho.startsWith("produtos/uploads/")) return;

  const arquivo = path.join(config.pastaUploads, path.basename(caminho));
  try {
    await unlink(arquivo);
  } catch {
    // Arquivo já não existe: tudo bem.
  }
}