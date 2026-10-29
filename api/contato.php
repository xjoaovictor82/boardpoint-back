<?php
/**
 * BOARD POINT — API de mensagens de contato
 *
 * POST   (público)     — envia uma nova mensagem de contato
 * GET    (autenticado) — lista as mensagens (admin)
 * PUT    (autenticado) — marca uma mensagem como lida/não lida
 * DELETE (autenticado) — remove uma mensagem
 */

require_once __DIR__ . '/config.php';

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

    case 'POST':
        $dados = corpoJson();

        $nome = trim($dados['nome'] ?? '');
        $email = trim($dados['email'] ?? '');
        $mensagem = trim($dados['mensagem'] ?? '');

        if ($nome === '' || $email === '' || $mensagem === '') {
            respostaErro('Preencha nome, e-mail e mensagem.', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respostaErro('E-mail inválido.', 422);
        }
        if (mb_strlen($nome) > 150 || mb_strlen($email) > 150) {
            respostaErro('Nome ou e-mail muito longos.', 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO contatos (nome, email, mensagem) VALUES (?, ?, ?)'
        );
        $stmt->execute([$nome, $email, $mensagem]);

        respostaSucesso(null, 'Mensagem enviada com sucesso.');
        break;

    case 'GET':
        exigirLogin();

        $stmt = $pdo->query('SELECT * FROM contatos ORDER BY criado_em DESC');
        $linhas = $stmt->fetchAll();

        respostaSucesso(array_map('normalizarContato', $linhas));
        break;

    case 'PUT':
        exigirLogin();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $dados = corpoJson();
        $lida = !empty($dados['lida']) ? 1 : 0;

        $stmt = $pdo->prepare('UPDATE contatos SET lida = ? WHERE id = ?');
        $stmt->execute([$lida, $id]);

        respostaSucesso(null, 'Atualizado.');
        break;

    case 'DELETE':
        exigirLogin();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('DELETE FROM contatos WHERE id = ?');
        $stmt->execute([$id]);

        respostaSucesso(null, 'Mensagem removida.');
        break;

    default:
        respostaErro('Método não suportado.', 405);
}
