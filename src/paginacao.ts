import { sucesso } from "./respostas.js";

export interface Paginacao {
  pagina: number;
  porPagina: number;
  offset: number;
}

/** Lê ?pagina= e ?por_pagina= (equivale ao paginacao() do PHP). */
export function lerPaginacao(consulta: Record<string, unknown>, padrao = 10): Paginacao {
  const pagina = Math.max(1, Math.trunc(Number(consulta["pagina"])) || 1);

  const bruto =
    consulta["por_pagina"] === undefined
      ? padrao
      : Math.trunc(Number(consulta["por_pagina"])) || 0;
  const porPagina = Math.min(100, Math.max(1, bruto));

  return { pagina, porPagina, offset: (pagina - 1) * porPagina };
}

/** Resposta de listagem paginada: os itens vêm em dados.itens. */
export function respostaPaginada<T>(itens: T[], total: number, pagina: number, porPagina: number) {
  return sucesso({
    itens,
    total,
    pagina,
    por_pagina: porPagina,
    total_paginas: Math.max(1, Math.ceil(total / porPagina)),
  });
}