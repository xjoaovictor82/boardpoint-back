import { randomInt } from "node:crypto";
import { config } from "./config.js";
import { validarSenha } from "./validacoes.js";

export interface SenhaSugerida {
  senha: string;
  origem: "ia" | "local";
}

/** Tira o texto da resposta do Gemini, sem confiar no formato. Falhou = "". */
function textoDaResposta(dados: unknown): string {
  const candidatos = (
    dados as { candidates?: { content?: { parts?: { text?: unknown }[] } }[] } | null
  )?.candidates;
  const texto = candidatos?.[0]?.content?.parts?.[0]?.text;
  return typeof texto === "string" ? texto.trim() : "";
}

/** Pede uma senha à IA. Devolve null se não houver chave, se falhar ou se vier algo estranho. */
async function senhaPelaIA(): Promise<string | null> {
  const chave = config.geminiApiKey;
  if (chave === "") return null;

  const modelo = "gemini-3.5-flash-lite";
  try {
    const resposta = await fetch(
      `https://generativelanguage.googleapis.com/v1beta/models/${modelo}:generateContent`,
      {
        method: "POST",
        headers: { "Content-Type": "application/json", "x-goog-api-key": chave },
        body: JSON.stringify({
          contents: [
            {
              parts: [
                {
                  text: "Gere UMA senha forte de 14 a 18 caracteres com letra maiúscula, letra minúscula, número e um símbolo entre !@#$%&*. Responda somente com a senha, sem aspas e sem explicações.",
                },
              ],
            },
          ],
          generationConfig: { maxOutputTokens: 1024, temperature: 1.0 },
        }),
        signal: AbortSignal.timeout(8000), // não deixa o cadastro esperando a IA para sempre
      }
    );

    const dados: unknown = await resposta.json();
    const senha = textoDaResposta(dados);
    if (senha === "" || senha.length > 64 || /\s/.test(senha)) {
      console.error("senhaSugerida: resposta do Gemini fora do esperado");
      return null;
    }
    return senha;
  } catch (err) {
    console.error("senhaSugerida: falha ao chamar o Gemini", err);
    return null;
  }
}

/** Sorteia um caractere de um grupo (usa o gerador seguro do Node, não o Math.random). */
function sortear(grupo: string): string {
  return grupo[randomInt(grupo.length)] ?? "";
}

/** Gera uma senha forte localmente: um caractere de cada tipo exigido e o resto sorteado. */
function senhaAleatoriaLocal(): string {
  const grupos = ["ABCDEFGHJKLMNPQRSTUVWXYZ", "abcdefghijkmnopqrstuvwxyz", "23456789", "!@#$%&*"];
  const senha = grupos.map(sortear);
  const todos = grupos.join("");
  while (senha.length < 16) {
    senha.push(sortear(todos));
  }

  // Embaralha (Fisher-Yates), para os 4 primeiros não ficarem sempre na mesma ordem.
  for (let i = senha.length - 1; i > 0; i--) {
    const j = randomInt(i + 1);
    const a = senha[i];
    const b = senha[j];
    if (a !== undefined && b !== undefined) {
      senha[i] = b;
      senha[j] = a;
    }
  }
  return senha.join("");
}

/** Sugere uma senha forte: tenta a IA e, se não der (ou não cumprir as regras), gera local. */
export async function gerarSenhaSugerida(): Promise<SenhaSugerida> {
  const daIA = await senhaPelaIA();
  if (daIA !== null && validarSenha(daIA)) {
    return { senha: daIA, origem: "ia" };
  }
  return { senha: senhaAleatoriaLocal(), origem: "local" };
}