<?php
// CRUD ADMINISTRATIVO das mensagens de contato. TODOS os métodos exigem token de admin.
// (O envio pelo site fica em publico/contato.php.)
//
//   GET    ?pagina=1&por_pagina=10[&lida=0|1]   lista paginada
//   GET    ?id=X                                uma mensagem (404 se não existir)
//   POST   {nome, email, mensagem}              cadastra uma mensagem pelo painel (201)
//   PUT    ?id=X {nome?, email?, mensagem?, lida?}  edita (404 se não existir)
//   DELETE ?id=X                                remove (404 se não existir)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validacoes.php';

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

// Valida nome, e-mail e mensagem. Devolve [nome, email, mensagem] já tratados.
function validarDadosContato(string $nome, string $email, string $mensagem): array
{
    $nome = trim($nome);
    $email = strtolower(trim($email));
    $mensagem = trim($mensagem);

    if ($nome === '' || $email === '' || $mensagem === '') {
        respostaErro('Preencha nome, e-mail e mensagem.', 422);
    }
    if (mb_strlen($nome) > 150) {
        respostaErro('O nome pode ter no máximo 150 caracteres.', 422);
    }
    if (!validarEmail($email)) {
        respostaErro('E-mail inválido.', 422);
    }
    if (mb_strlen($mensagem) > 5000) {
        respostaErro('A mensagem pode ter no máximo 5000 caracteres.', 422);
    }
    return [$nome, $email, $mensagem];
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

        $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ?');
        $stmt->execute([$id]);
        $atual = $stmt->fetch();
        if (!$atual) {
            respostaErro('Mensagem não encontrada.', 404);
        }

        $dados = corpoJson();
        $camposEditaveis = ['nome', 'email', 'mensagem', 'lida'];
        if (count(array_intersect($camposEditaveis, array_keys($dados))) === 0) {
            respostaErro('Informe o que deseja alterar: nome, email, mensagem ou lida.', 422);
        }

        [$nome, $email, $mensagem] = validarDadosContato(
            array_key_exists('nome', $dados) ? (string) $dados['nome'] : $atual['nome'],
            array_key_exists('email', $dados) ? (string) $dados['email'] : $atual['email'],
            array_key_exists('mensagem', $dados) ? (string) $dados['mensagem'] : $atual['mensagem']
        );
        $lida = array_key_exists('lida', $dados)
            ? (filter_var($dados['lida'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
            : (int) $atual['lida'];

        $stmt = $pdo->prepare('UPDATE contatos SET nome = ?, email = ?, mensagem = ?, lida = ? WHERE id = ?');
        $stmt->execute([$nome, $email, $mensagem, $lida, $id]);

        respostaSucesso(
            ['id' => $id, 'nome' => $nome, 'email' => $email, 'mensagem' => $mensagem, 'lida' => (bool) $lida],
            'Mensagem atualizada.'
        );
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
        $dados = corpoJson();
        [$nome, $email, $mensagem] = validarDadosContato(
            (string) ($dados['nome'] ?? ''),
            (string) ($dados['email'] ?? ''),
            (string) ($dados['mensagem'] ?? '')
        );

        $stmt = $pdo->prepare('INSERT INTO contatos (nome, email, mensagem) VALUES (?, ?, ?)');
        $stmt->execute([$nome, $email, $mensagem]);
        $id = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare('SELECT * FROM contatos WHERE id = ?');
        $stmt->execute([$id]);

        respostaJson([
            'sucesso'  => true,
            'mensagem' => 'Mensagem cadastrada com sucesso.',
            'dados'    => normalizarContato($stmt->fetch()),
        ], 201);
        break;

    default:
        respostaErro('Método não suportado.', 405);
}