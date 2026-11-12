<?php
// CRUD ADMINISTRATIVO de categorias. TODOS os métodos exigem token de admin.
// (A leitura pública para o site fica em publico/categorias.php.)
//
//   GET    ?pagina=1&por_pagina=10   lista paginada
//   GET    ?id=X                     uma categoria (404 se não existir)
//   POST   {nome, slug}              cria
//   PUT    ?id=X {nome, slug}        edita (404 se não existir)
//   DELETE ?id=X                     exclui (404 se não existir; 409 se ainda tem produtos)
require_once __DIR__ . '/config.php';

exigirLogin('admin');

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = getConexao();

function validarDadosCategoria(array $dados): array
{
    $nome = trim((string) ($dados['nome'] ?? ''));
    $slug = strtolower(trim((string) ($dados['slug'] ?? '')));

    if ($nome === '' || $slug === '') {
        respostaErro('Nome e slug são obrigatórios.', 422);
    }
    if (mb_strlen($nome) > 100) {
        respostaErro('O nome pode ter no máximo 100 caracteres.', 422);
    }
    if (strlen($slug) > 60 || !preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug)) {
        respostaErro('Slug inválido: use só letras minúsculas, números e hífen (máx. 60).', 422);
    }
    return [$nome, $slug];
}

switch ($metodo) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                respostaErro('ID inválido.', 422);
            }
            $stmt = $pdo->prepare('SELECT id, slug, nome FROM categorias WHERE id = ?');
            $stmt->execute([$id]);
            $linha = $stmt->fetch();
            if (!$linha) {
                respostaErro('Categoria não encontrada.', 404);
            }
            respostaSucesso(normalizarCategoria($linha));
        }

        [$pagina, $porPagina, $offset] = paginacao();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM categorias')->fetchColumn();

        $stmt = $pdo->prepare('SELECT id, slug, nome FROM categorias ORDER BY nome LIMIT ? OFFSET ?');
        $stmt->bindValue(1, $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        respostaPaginada(array_map('normalizarCategoria', $stmt->fetchAll()), $total, $pagina, $porPagina);
        break;

    case 'POST':
        [$nome, $slug] = validarDadosCategoria(corpoJson());

        try {
            $stmt = $pdo->prepare('INSERT INTO categorias (slug, nome) VALUES (?, ?)');
            $stmt->execute([$slug, $nome]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Já existe uma categoria com esse slug.', 409);
            }
            error_log('categorias.php POST: ' . $e->getMessage());
            respostaErro('Erro interno do servidor.', 500);
        }

        respostaJson([
            'sucesso'  => true,
            'mensagem' => 'Categoria criada com sucesso.',
            'dados'    => ['id' => (int) $pdo->lastInsertId(), 'slug' => $slug, 'nome' => $nome],
        ], 201);
        break;

    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }
        if (!registroExiste($pdo, 'categorias', $id)) {
            respostaErro('Categoria não encontrada.', 404);
        }
        [$nome, $slug] = validarDadosCategoria(corpoJson());

        try {
            $stmt = $pdo->prepare('UPDATE categorias SET nome = ?, slug = ? WHERE id = ?');
            $stmt->execute([$nome, $slug, $id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Já existe uma categoria com esse slug.', 409);
            }
            error_log('categorias.php PUT: ' . $e->getMessage());
            respostaErro('Erro interno do servidor.', 500);
        }

        respostaSucesso(['id' => $id, 'slug' => $slug, 'nome' => $nome], 'Categoria atualizada com sucesso.');
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM categorias WHERE id = ?');
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Não é possível excluir: existem produtos vinculados a essa categoria.', 409);
            }
            error_log('categorias.php DELETE: ' . $e->getMessage());
            respostaErro('Erro interno do servidor.', 500);
        }
        if ($stmt->rowCount() === 0) {
            respostaErro('Categoria não encontrada.', 404);
        }

        respostaSucesso(null, 'Categoria excluída com sucesso.');
        break;

    default:
        respostaErro('Método não suportado.', 405);
}