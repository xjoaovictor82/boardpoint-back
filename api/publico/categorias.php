<?php
// Endpoint PÚBLICO do catálogo: lista as categorias (só leitura, sem login).
// O CRUD administrativo fica em ../categorias.php (exige token).
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respostaErro('Método não suportado.', 405);
}

$stmt = getConexao()->query('SELECT id, slug, nome FROM categorias ORDER BY nome');
respostaSucesso(array_map('normalizarCategoria', $stmt->fetchAll()));