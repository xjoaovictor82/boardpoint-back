<?php
/**
 * Endpoint PÚBLICO de checkout: cria um pedido a partir do carrinho e devolve o PIX.
 * Só POST, sem login. Listar/alterar pedidos fica em ../pedidos.php (exige token).
 *
 * Preço e estoque são revalidados contra o banco (nunca confia no navegador) e as
 * escritas acontecem dentro de uma transação.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config_loja.php';
require_once __DIR__ . '/../GeradorPix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respostaErro('Método não suportado.', 405);
}

// Sem chave PIX o checkout não pode gerar cobrança: recusa antes de gravar o pedido.
if (PIX_CHAVE === '') {
    respostaErro('PIX indisponível: a chave PIX da loja ainda não foi configurada.', 503);
}

$pdo = getConexao();

$dados = corpoJson();
$nome = trim((string) ($dados['nome'] ?? ''));
$telefone = trim((string) ($dados['telefone'] ?? ''));
$email = trim((string) ($dados['email'] ?? ''));
$itens = $dados['itens'] ?? [];

if ($nome === '' || $telefone === '') {
    respostaErro('Informe nome e telefone para contato.', 422);
}
if (!is_array($itens) || count($itens) === 0) {
    respostaErro('Carrinho vazio.', 422);
}

$itensValidados = [];
$total = 0.0;

foreach ($itens as $item) {
    $produtoId = (int) ($item['produto_id'] ?? 0);
    $quantidade = (int) ($item['quantidade'] ?? 0);

    if ($produtoId <= 0 || $quantidade <= 0) {
        respostaErro('Item de carrinho inválido.', 422);
    }

    $stmt = $pdo->prepare('SELECT id, nome, preco, estoque, ativo FROM produtos WHERE id = ?');
    $stmt->execute([$produtoId]);
    $produto = $stmt->fetch();

    if (!$produto || !$produto['ativo']) {
        respostaErro('Um dos produtos não está mais disponível.', 422);
    }
    if ($produto['preco'] === null) {
        respostaErro('Produto "' . $produto['nome'] . '" está sob consulta e não pode ser comprado online.', 422);
    }
    if ($quantidade > (int) $produto['estoque']) {
        respostaErro('Estoque insuficiente para "' . $produto['nome'] . '".', 422);
    }

    $total += (float) $produto['preco'] * $quantidade;

    $itensValidados[] = [
        'produto_id'     => $produto['id'],
        'produto_nome'   => $produto['nome'],
        'preco_unitario' => (float) $produto['preco'],
        'quantidade'     => $quantidade,
    ];
}

$codigo = 'BP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO pedidos (codigo, cliente_nome, cliente_telefone, cliente_email, total, status)
         VALUES (?, ?, ?, ?, ?, "aguardando_pagamento")'
    );
    $stmt->execute([$codigo, $nome, $telefone, $email ?: null, $total]);
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
    respostaErro('Não foi possível registrar o pedido. Tente novamente.', 500);
}

$gerador = new GeradorPix(PIX_CHAVE, PIX_NOME_RECEBEDOR, PIX_CIDADE);
$payloadPix = $gerador->gerarPayload($total, str_replace('-', '', $codigo));

respostaSucesso([
    'pedido_id'   => $pedidoId,
    'codigo'      => $codigo,
    'total'       => $total,
    'pix_payload' => $payloadPix,
], 'Pedido criado com sucesso.');