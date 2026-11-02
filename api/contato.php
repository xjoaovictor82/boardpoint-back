<?php
// CRUD ADMINISTRATIVO das mensagens de contato. TODOS os métodos exigem token de admin.
// (O envio pelo site fica em publico/contato.php.)
//
//   GET    ?pagina=1&por_pagina=10[&lida=0|1]   lista paginada
//   GET    ?id=X                                uma mensagem (404 se não existir)
//   PUT    ?id=X {lida: true|false}             marca como lida/não lida (404 se não existir)
//   DELETE ?id=X                                remove (404 se não existir)
require_once __DIR__ . '/config.php';

exigirLogin('admin');

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = getConexao();

function normalizarContato(array $c): array
{
    return [
        'id'        => (int) $c['id'],
        'nome'      => $c['nome'],
        'email'     => $c['email'],
        'mensagem'  => $c['mensagem'],
        'lida'      => (bool) $c['lida'],
        'criado_em' => $c['criado_em'],
    ];
}

switch ($metodo) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                respostaErro('ID inválido.', 422);
            }
            $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ?');
            $stmt->execute([$id]);
            $linha = $stmt->fetch();
            if (!$linha) {
                respostaErro('Mensagem não encontrada.', 404);
            }
            respostaSucesso(normalizarContato($linha));
        }

        [$pagina, $porPagina, $offset] = paginacao();

        $where = '';
        $params = [];
        if (isset($_GET['lida']) && in_array((string) $_GET['lida'], ['0', '1'], true)) {
            $where = 'WHERE lida = ?';
            $params[] = (int) $_GET['lida'];
        }

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM contatos $where");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM contatos $where ORDER BY criado_em DESC, id DESC LIMIT ? OFFSET ?");
        foreach ($params as $i => $valor) {
            $stmt->bindValue($i + 1, $valor, PDO::PARAM_INT);
        }
        $stmt->bindValue(count($params) + 1, $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        respostaPaginada(array_map('normalizarContato', $stmt->fetchAll()), $total, $pagina, $porPagina);
        break;

    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }
        if (!registroExiste($pdo, 'contatos', $id)) {
            respostaErro('Mensagem não encontrada.', 404);
        }

        $dados = corpoJson();
        if (!array_key_exists('lida', $dados)) {
            respostaErro('Informe o campo "lida" (true ou false).', 422);
        }
        $lida = filter_var($dados['lida'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

        $stmt = $pdo->prepare('UPDATE contatos SET lida = ? WHERE id = ?');
        $stmt->execute([$lida, $id]);

        respostaSucesso(['id' => $id, 'lida' => (bool) $lida], 'Atualizado.');
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('DELETE FROM contatos WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            respostaErro('Mensagem não encontrada.', 404);
        }

        respostaSucesso(null, 'Mensagem removida.');
        break;

    case 'POST':
        respostaErro('Para enviar uma mensagem use publico/contato.php.', 405);
        break;

    default:
        respostaErro('Método não suportado.', 405);
}