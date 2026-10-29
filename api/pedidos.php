<?php
/**
 * BOARD POINT — API de pedidos (carrinho / checkout)
 *
 * POST   ?acao=criar    (público)     — cria um pedido a partir do carrinho, retorna o PIX
 * GET    (autenticado)                — lista pedidos (admin)
 * PUT    ?id=X&acao=status (autenticado) — muda status (pago / cancelado), abate estoque se pago
 *
 * Todo preço/estoque é revalidado contra o banco no POST (nunca confia no
 * valor que o navegador mandou) e as escritas em pedidos + pedido_itens +
 * ajuste de estoque acontecem dentro de uma transação (beginTransaction/
 * commit/rollBack), então nunca ficam pela metade.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_loja.php';
require_once __DIR__ . '/GeradorPix.php';

$metodo = $_SERVER['REQUEST_METHOD'];
$pdo = getConexao();

// Sem chave PIX configurada o checkout não pode gerar cobrança. Recusa antes
// de gravar o pedido, para não deixar pedido órfão no banco.
if ($metodo === 'POST' && PIX_CHAVE === '') {
    respostaErro('PIX indisponível: a chave PIX da loja ainda não foi configurada.', 503);
}

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

function gerarCodigoPedido(): string
{
    return 'BP-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

switch ($metodo) {

    // -------------------------------------------------------------------
    case 'POST':
        $acao = $_GET['acao'] ?? '';
        if ($acao !== 'criar') {
            respostaErro('Ação inválida.', 404);
        }

        $dados = corpoJson();
        $nome = trim($dados['nome'] ?? '');
        $telefone = trim($dados['telefone'] ?? '');
        $email = trim($dados['email'] ?? '');
        $itens = $dados['itens'] ?? [];

        if ($nome === '' || $telefone === '') {
            respostaErro('Informe nome e telefone para contato.', 422);
        }
        if (!is_array($itens) || count($itens) === 0) {
            respostaErro('Carrinho vazio.', 422);
        }

        // Revalida cada item contra o banco: preço e estoque reais,
        // nunca confia no que o navegador mandou.
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

            $subtotal = (float) $produto['preco'] * $quantidade;
            $total += $subtotal;

            $itensValidados[] = [
                'produto_id'     => $produto['id'],
                'produto_nome'   => $produto['nome'],
                'preco_unitario' => (float) $produto['preco'],
                'quantidade'     => $quantidade,
            ];
        }

        $codigo = gerarCodigoPedido();

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
            'pedido_id'    => $pedidoId,
            'codigo'       => $codigo,
            'total'        => $total,
            'pix_payload'  => $payloadPix,
        ], 'Pedido criado com sucesso.');
        break;

    // -------------------------------------------------------------------
    case 'GET':
        exigirLogin();

        $stmt = $pdo->query('SELECT * FROM pedidos ORDER BY criado_em DESC');
        $pedidos = $stmt->fetchAll();

        $stmtItens = $pdo->prepare('SELECT * FROM pedido_itens WHERE pedido_id = ?');

        $resultado = [];
        foreach ($pedidos as $p) {
            $stmtItens->execute([$p['id']]);
            $itens = $stmtItens->fetchAll();

            $pedidoNormalizado = normalizarPedido($p);
            $pedidoNormalizado['itens'] = array_map(function ($it) {
                return [
                    'produto_nome'   => $it['produto_nome'],
                    'preco_unitario' => (float) $it['preco_unitario'],
                    'quantidade'     => (int) $it['quantidade'],
                ];
            }, $itens);

            $resultado[] = $pedidoNormalizado;
        }

        respostaSucesso($resultado);
        break;

    // -------------------------------------------------------------------
    case 'PUT':
        exigirLogin();

        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $dados = corpoJson();
        $novoStatus = $dados['status'] ?? '';

        if (!in_array($novoStatus, ['pago', 'cancelado', 'aguardando_pagamento'], true)) {
            respostaErro('Status inválido.', 422);
        }

        $stmt = $pdo->prepare('SELECT status FROM pedidos WHERE id = ?');
        $stmt->execute([$id]);
        $pedido = $stmt->fetch();

        if (!$pedido) {
            respostaErro('Pedido não encontrado.', 404);
        }

        // Abate estoque ao confirmar pagamento (transição para "pago" vinda
        // de um status diferente) e devolve estoque se um pedido que já
        // estava pago for cancelado ou voltar para aguardando — evita
        // abater/devolver duas vezes se clicarem 2x no mesmo botão.
        $vaiAbaterEstoque = ($novoStatus === 'pago' && $pedido['status'] !== 'pago');
        $vaiDevolverEstoque = ($novoStatus !== 'pago' && $pedido['status'] === 'pago');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE pedidos SET status = ? WHERE id = ?');
            $stmt->execute([$novoStatus, $id]);

            if ($vaiAbaterEstoque || $vaiDevolverEstoque) {
                $stmtItens = $pdo->prepare('SELECT produto_id, quantidade FROM pedido_itens WHERE pedido_id = ?');
                $stmtItens->execute([$id]);
                $itens = $stmtItens->fetchAll();

                if ($vaiAbaterEstoque) {
                    $stmtEstoque = $pdo->prepare(
                        'UPDATE produtos SET estoque = GREATEST(0, estoque - ?) WHERE id = ?'
                    );
                } else {
                    $stmtEstoque = $pdo->prepare(
                        'UPDATE produtos SET estoque = estoque + ? WHERE id = ?'
                    );
                }
                foreach ($itens as $it) {
                    $stmtEstoque->execute([$it['quantidade'], $it['produto_id']]);
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            respostaErro('Não foi possível atualizar o pedido.', 500);
        }

        respostaSucesso(null, 'Status atualizado.');
        break;

    default:
        respostaErro('Método não suportado.', 405);
}
