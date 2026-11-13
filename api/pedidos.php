<?php
/**
 * CRUD ADMINISTRATIVO de pedidos. TODOS os métodos exigem token de admin.
 * (O cliente da loja cria pedidos pelo checkout público: publico/checkout.php.)
 *
 *   GET    ?pagina=1&por_pagina=10[&status=pago]  lista paginada (com itens)
 *   GET    ?id=X                                  um pedido (404 se não existir)
 *   POST   {cliente_nome, cliente_telefone, cliente_email?, itens:[{produto_id, quantidade}]}
 *                                                 cria um pedido pelo painel (201). Preço e estoque
 *                                                 vêm do banco; nasce como "aguardando_pagamento".
 *   PUT    ?id=X {status?, cliente_nome?, cliente_telefone?, cliente_email?}
 *                                                 edita (404 se não existir). Ao virar "pago"
 *                                                 abate o estoque; ao sair de "pago" devolve.
 *   DELETE ?id=X                                  exclui (404 se não existir). Se estava pago,
 *                                                 devolve o estoque.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validacoes.php';

exigirLogin('admin');

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = getConexao();
$statusValidos = ['aguardando_pagamento', 'pago', 'cancelado'];

function normalizarPedido(array $p): array
{
    return [
        'id'               => (int) $p['id'],
        'codigo'           => $p['codigo'],
        'cliente_nome'     => $p['cliente_nome'],
        'cliente_telefone' => $p['cliente_telefone'],
        'cliente_email'    => $p['cliente_email'],
        'total'            => (float) $p['total'],
        'status'           => $p['status'],
        'criado_em'        => $p['criado_em'],
    ];
}

function itensDoPedido(PDO $pdo, int $pedidoId): array
{
    $stmt = $pdo->prepare('SELECT produto_id, produto_nome, preco_unitario, quantidade FROM pedido_itens WHERE pedido_id = ?');
    $stmt->execute([$pedidoId]);
    return array_map(function ($it) {
        return [
            'produto_id'     => (int) $it['produto_id'],
            'produto_nome'   => $it['produto_nome'],
            'preco_unitario' => (float) $it['preco_unitario'],
            'quantidade'     => (int) $it['quantidade'],
        ];
    }, $stmt->fetchAll());
}

// Abate (true) ou devolve (false) o estoque de todos os itens do pedido.
function ajustarEstoque(PDO $pdo, int $pedidoId, bool $abater): void
{
    $stmtItens = $pdo->prepare('SELECT produto_id, quantidade FROM pedido_itens WHERE pedido_id = ?');
    $stmtItens->execute([$pedidoId]);
    $itens = $stmtItens->fetchAll();

    $sql = $abater
        ? 'UPDATE produtos SET estoque = GREATEST(0, estoque - ?) WHERE id = ?'
        : 'UPDATE produtos SET estoque = estoque + ? WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    foreach ($itens as $it) {
        $stmt->execute([$it['quantidade'], $it['produto_id']]);
    }
}

switch ($metodo) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                respostaErro('ID inválido.', 422);
            }
            $stmt = $pdo->prepare('SELECT * FROM pedidos WHERE id = ?');
            $stmt->execute([$id]);
            $linha = $stmt->fetch();
            if (!$linha) {
                respostaErro('Pedido não encontrado.', 404);
            }
            $pedido = normalizarPedido($linha);
            $pedido['itens'] = itensDoPedido($pdo, $id);
            respostaSucesso($pedido);
        }

        [$pagina, $porPagina, $offset] = paginacao();

        $where = '';
        $params = [];
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $status = (string) $_GET['status'];
            if (!in_array($status, $statusValidos, true)) {
                respostaErro('Status inválido.', 422);
            }
            $where = 'WHERE status = ?';
            $params[] = $status;
        }

        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM pedidos $where");
        $stmtTotal->execute($params);
        $total = (int) $stmtTotal->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM pedidos $where ORDER BY criado_em DESC, id DESC LIMIT ? OFFSET ?");
        foreach ($params as $i => $valor) {
            $stmt->bindValue($i + 1, $valor);
        }
        $stmt->bindValue(count($params) + 1, $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $resultado = [];
        foreach ($stmt->fetchAll() as $linha) {
            $pedido = normalizarPedido($linha);
            $pedido['itens'] = itensDoPedido($pdo, (int) $linha['id']);
            $resultado[] = $pedido;
        }

        respostaPaginada($resultado, $total, $pagina, $porPagina);
        break;

    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('SELECT id, status, cliente_nome, cliente_telefone, cliente_email FROM pedidos WHERE id = ?');
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            respostaErro('Pedido não encontrado.', 404);
        }

        $dados = corpoJson();
        $mudaStatus = array_key_exists('status', $dados);
        $mudaCliente = array_key_exists('cliente_nome', $dados)
            || array_key_exists('cliente_telefone', $dados)
            || array_key_exists('cliente_email', $dados);
        if (!$mudaStatus && !$mudaCliente) {
            respostaErro('Informe o que deseja alterar: status ou dados do cliente.', 422);
        }

        $novoStatus = $mudaStatus ? (string) $dados['status'] : $pedido['status'];
        if (!in_array($novoStatus, $statusValidos, true)) {
            respostaErro('Status inválido.', 422);
        }

        $nome = array_key_exists('cliente_nome', $dados) ? trim((string) $dados['cliente_nome']) : $pedido['cliente_nome'];
        $telefone = array_key_exists('cliente_telefone', $dados) ? trim((string) $dados['cliente_telefone']) : $pedido['cliente_telefone'];
        $email = array_key_exists('cliente_email', $dados) ? trim((string) $dados['cliente_email']) : (string) ($pedido['cliente_email'] ?? '');

        if ($nome === '' || $telefone === '') {
            respostaErro('Nome e telefone do cliente são obrigatórios.', 422);
        }
        if (mb_strlen($nome) > 150 || mb_strlen($telefone) > 30) {
            respostaErro('Nome ou telefone muito longos.', 422);
        }
        if ($email !== '' && !validarEmail($email)) {
            respostaErro('E-mail inválido.', 422);
        }

        // Só mexe no estoque quando o status realmente muda (evita abater/devolver 2 vezes).
        $abater = ($novoStatus === 'pago' && $pedido['status'] !== 'pago');
        $devolver = ($novoStatus !== 'pago' && $pedido['status'] === 'pago');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'UPDATE pedidos SET status = ?, cliente_nome = ?, cliente_telefone = ?, cliente_email = ? WHERE id = ?'
            );
            $stmt->execute([$novoStatus, $nome, $telefone, $email !== '' ? $email : null, $id]);

            if ($abater || $devolver) {
                ajustarEstoque($pdo, $id, $abater);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('pedidos.php PUT: ' . $e->getMessage());
            respostaErro('Não foi possível atualizar o pedido.', 500);
        }

        respostaSucesso(['id' => $id, 'status' => $novoStatus], 'Pedido atualizado.');
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('SELECT status FROM pedidos WHERE id = ?');
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();
        if (!$pedido) {
            respostaErro('Pedido não encontrado.', 404);
        }

        $pdo->beginTransaction();
        try {
            if ($pedido['status'] === 'pago') {
                ajustarEstoque($pdo, $id, false); // devolve o estoque que o pagamento tinha abatido
            }
            // Os itens saem junto (FK com ON DELETE CASCADE).
            $stmt = $pdo->prepare('DELETE FROM pedidos WHERE id = ?');
            $stmt->execute([$id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('pedidos.php DELETE: ' . $e->getMessage());
            respostaErro('Não foi possível excluir o pedido.', 500);
        }

        respostaSucesso(null, 'Pedido excluído com sucesso.');
        break;

    case 'POST':
        $dados = corpoJson();
        $nome = trim((string) ($dados['cliente_nome'] ?? ''));
        $telefone = trim((string) ($dados['cliente_telefone'] ?? ''));
        $email = trim((string) ($dados['cliente_email'] ?? ''));
        $itens = $dados['itens'] ?? [];

        if ($nome === '' || $telefone === '') {
            respostaErro('Nome e telefone do cliente são obrigatórios.', 422);
        }
        if (mb_strlen($nome) > 150 || mb_strlen($telefone) > 30) {
            respostaErro('Nome ou telefone muito longos.', 422);
        }
        if ($email !== '' && !validarEmail($email)) {
            respostaErro('E-mail inválido.', 422);
        }
        if (!is_array($itens) || count($itens) === 0 || count($itens) > 50) {
            respostaErro('Informe de 1 a 50 itens para o pedido.', 422);
        }

        // Soma as quantidades do mesmo produto (evita burlar o estoque repetindo a linha).
        $quantidades = [];
        foreach ($itens as $item) {
            $produtoId = (int) ($item['produto_id'] ?? 0);
            $quantidade = (int) ($item['quantidade'] ?? 0);
            if ($produtoId <= 0 || $quantidade <= 0 || $quantidade > 1000) {
                respostaErro('Item inválido: informe produto e uma quantidade entre 1 e 1000.', 422);
            }
            $quantidades[$produtoId] = ($quantidades[$produtoId] ?? 0) + $quantidade;
        }

        $itensValidados = [];
        $totalPedido = 0.0;
        $stmtProduto = $pdo->prepare('SELECT id, nome, preco, estoque, ativo FROM produtos WHERE id = ?');
        foreach ($quantidades as $produtoId => $quantidade) {
            $stmtProduto->execute([$produtoId]);
            $produto = $stmtProduto->fetch();

            if (!$produto || !$produto['ativo']) {
                respostaErro('O produto ' . $produtoId . ' não existe ou não está disponível.', 422);
            }
            if ($produto['preco'] === null) {
                respostaErro('O produto "' . $produto['nome'] . '" está sem preço e não pode ser vendido.', 422);
            }
            if ($quantidade > (int) $produto['estoque']) {
                respostaErro('Estoque insuficiente para "' . $produto['nome'] . '".', 422);
            }

            $totalPedido += (float) $produto['preco'] * $quantidade;
            $itensValidados[] = [
                'produto_id'     => (int) $produto['id'],
                'produto_nome'   => $produto['nome'],
                'preco_unitario' => (float) $produto['preco'],
                'quantidade'     => $quantidade,
            ];
        }

        // Código único do pedido (BP-XXXXXX).
        $stmtCodigo = $pdo->prepare('SELECT 1 FROM pedidos WHERE codigo = ?');
        do {
            $codigo = 'BP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            $stmtCodigo->execute([$codigo]);
        } while ($stmtCodigo->fetchColumn());

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO pedidos (codigo, cliente_nome, cliente_telefone, cliente_email, total, status)
                 VALUES (?, ?, ?, ?, ?, \'aguardando_pagamento\')'
            );
            $stmt->execute([$codigo, $nome, $telefone, $email !== '' ? $email : null, $totalPedido]);
            $pedidoId = (int) $pdo->lastInsertId();

            $stmtItem = $pdo->prepare(
                'INSERT INTO pedido_itens (pedido_id, produto_id, produto_nome, preco_unitario, quantidade)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($itensValidados as $it) {
                $stmtItem->execute([
                    $pedidoId, $it['produto_id'], $it['produto_nome'], $it['preco_unitario'], $it['quantidade'],
                ]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('pedidos.php POST: ' . $e->getMessage());
            respostaErro('Não foi possível criar o pedido.', 500);
        }

        respostaJson([
            'sucesso'  => true,
            'mensagem' => 'Pedido criado com sucesso.',
            'dados'    => ['id' => $pedidoId, 'codigo' => $codigo, 'total' => $totalPedido, 'status' => 'aguardando_pagamento'],
        ], 201);
        break;

    default:
        respostaErro('Método não suportado.', 405);
}