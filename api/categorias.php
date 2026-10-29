<?php
// CRUD de categorias. O DELETE depende da FK "fk_produtos_categoria"
// (ON DELETE RESTRICT): se a categoria ainda tem produtos, o MySQL
// recusa a exclusão (erro 23000) e devolvemos uma mensagem clara.
require_once __DIR__ . '/config.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = getConexao();

// Leitura é pública (o site também consome), escrita exige login
if (in_array($metodo, ['POST', 'PUT', 'DELETE'])) {
    exigirLogin();
}

switch ($metodo) {

    case 'GET':
        $stmt = $pdo->query('SELECT id, slug, nome FROM categorias ORDER BY nome');
        $categorias = array_map('normalizarCategoria', $stmt->fetchAll());
        respostaSucesso($categorias);
        break;

    case 'POST':
        $dados = corpoJson();
        $nome = trim($dados['nome'] ?? '');
        $slug = trim($dados['slug'] ?? '');

        if ($nome === '' || $slug === '') {
            respostaErro('Nome e slug são obrigatórios.', 422);
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO categorias (slug, nome) VALUES (?, ?)');
            $stmt->execute([$slug, $nome]);
            $id = (int) $pdo->lastInsertId();
            respostaSucesso(['id' => $id, 'slug' => $slug, 'nome' => $nome], 'Categoria criada com sucesso.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Já existe uma categoria com esse slug.', 409);
            }
            respostaErro('Erro ao criar categoria: ' . $e->getMessage(), 500);
        }
        break;

    case 'PUT':
        parse_str(file_get_contents('php://input'), $legacy); // fallback
        $dados = corpoJson();
        $id = (int) ($_GET['id'] ?? 0);
        $nome = trim($dados['nome'] ?? '');
        $slug = trim($dados['slug'] ?? '');

        if ($id <= 0 || $nome === '' || $slug === '') {
            respostaErro('Dados inválidos para atualização.', 422);
        }

        try {
            $stmt = $pdo->prepare('UPDATE categorias SET nome = ?, slug = ? WHERE id = ?');
            $stmt->execute([$nome, $slug, $id]);
            respostaSucesso(['id' => $id, 'slug' => $slug, 'nome' => $nome], 'Categoria atualizada com sucesso.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Já existe uma categoria com esse slug.', 409);
            }
            respostaErro('Erro ao atualizar categoria: ' . $e->getMessage(), 500);
        }
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM categorias WHERE id = ?');
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                respostaErro('Categoria não encontrada.', 404);
            }
            respostaSucesso(null, 'Categoria excluída com sucesso.');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Não é possível excluir: existem produtos vinculados a essa categoria.', 409);
            }
            respostaErro('Erro ao excluir categoria: ' . $e->getMessage(), 500);
        }
        break;

    default:
        respostaErro('Método não suportado.', 405);
}
