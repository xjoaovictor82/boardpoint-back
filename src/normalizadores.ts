import type { RowDataPacket } from "mysql2/promise";

export interface LinhaProduto extends RowDataPacket {
  id: number;
  nome: string;
  preco: string | number | null;
  estoque: number;
  imagem: string;
  ativo: number;
  categoria_id: number;
  categoria_slug: string;
  categoria_nome: string;
}

export interface LinhaCategoria extends RowDataPacket {
  id: number;
  slug: string;
  nome: string;
}

export function normalizarProduto(p: LinhaProduto) {
  return {
    id: Number(p.id),
    nome: p.nome,
    preco: p.preco !== null ? Number(p.preco) : null,
    estoque: Number(p.estoque),
    imagem: p.imagem,
    ativo: Boolean(p.ativo),
    categoria_id: Number(p.categoria_id),
    categoria_slug: p.categoria_slug,
    categoria_nome: p.categoria_nome,
  };
}

export function normalizarCategoria(c: LinhaCategoria) {
  return {
    id: Number(c.id),
    slug: c.slug,
    nome: c.nome,
  };
}