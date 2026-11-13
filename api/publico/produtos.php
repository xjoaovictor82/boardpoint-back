<?php
// Endpoint PÚBLICO do catálogo: lista produtos com busca, filtro por categoria e
// paginação (só leitura, sem login). O CRUD administrativo fica em ../produtos.php.
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respostaErro('Método não suportado.', 405);
}

$pdo = getConexao();

$busca = trim($_GET['busca'] ?? '');
$categoriaSlug = trim($_GET['categoria'] ?? 'todos');
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$porPagina = min(100, max(1, (int) ($_GET['por_pagina'] ?? 50)));
$offset = ($pagina - 1) * $porPagina;

$stmt = $pdo->prepare('CALL sp_listar_produtos(?, ?, ?, ?)');
$stmt->execute([$busca, $categoriaSlug, $porPagina, $offset]);
$produtos = array_map('normalizarProduto', $stmt->fetchAll());
$stmt->closeCursor();

$stmtTotal = $pdo->prepare('CALL sp_contar_produtos(?, ?)');
$stmtTotal->execute([$busca, $categoriaSlug]);
$total = (int) $stmtTotal->fetch()['total'];
$stmtTotal->closeCursor();

respostaSucesso([
    'produtos' => $produtos,
    'total' => $total,
    'pagina' => $pagina,
    'por_pagina' => $porPagina,
]);