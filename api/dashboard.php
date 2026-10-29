<?php
// Rota mantida para demonstrar o consumo de stored procedure/view via CALL.
// O dashboard admin hoje usa produtos.php + dashboard.ts (cálculo em TS),
// mas esta rota continua funcional e pode ser chamada diretamente.
require_once __DIR__ . '/config.php';

exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respostaErro('Método não suportado.', 405);
}

$pdo = getConexao();

$stmt = $pdo->query('CALL sp_estatisticas_dashboard()');
$stats = $stmt->fetch();
$stmt->closeCursor();

$stmtCategorias = $pdo->query('SELECT id, slug, nome, total_produtos FROM vw_dashboard_categorias');
$categorias = $stmtCategorias->fetchAll();
$stmtCategorias->closeCursor();

respostaSucesso([
    'total_produtos'      => (int) $stats['total_produtos'],
    'total_categorias'    => (int) $stats['total_categorias'],
    'produtos_com_preco'  => (int) $stats['produtos_com_preco'],
    'produtos_sem_estoque'=> (int) $stats['produtos_sem_estoque'],
    'estoque_total'       => (int) $stats['estoque_total'],
    'preco_medio'         => (float) $stats['preco_medio'],
    'por_categoria'       => $categorias,
]);
