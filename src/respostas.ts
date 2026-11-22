/** Erro que vira resposta HTTP com o status e a mensagem informados. */
export class ErroHttp extends Error {
  readonly status: number;

  constructor(status: number, mensagem: string) {
    super(mensagem);
    this.status = status;
  }
}

/** Interrompe a rota com um erro. Uso: erro(404, "Produto não encontrado.") */
export function erro(status: number, mensagem: string): never {
  throw new ErroHttp(status, mensagem);
}

export interface RespostaSucesso<T> {
  sucesso: true;
  mensagem?: string;
  dados?: T;
}

/** Monta { sucesso: true, mensagem?, dados? }, igual ao respostaSucesso() do PHP. */
export function sucesso<T = never>(dados?: T | null, mensagem = ""): RespostaSucesso<T> {
  const resposta: RespostaSucesso<T> = { sucesso: true };
  if (mensagem !== "") resposta.mensagem = mensagem;
  if (dados !== undefined && dados !== null) resposta.dados = dados;
  return resposta;
}