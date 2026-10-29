<?php
// CRUD de produtos: GET lista (via stored procedure, com busca/filtro/
// paginação), POST cria, PUT edita, DELETE remove. Escrita exige login.
require_once __DIR__ . '/config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

// Method override: PHP não popula $_FILES em requisições PUT nativas,
// então o admin envia POST com ?_method=PUT quando precisa editar com upload.
if ($metodo === 'POST' && ($_GET['_method'] ?? '') === 'PUT') {
    $metodo = 'PUT';
}

$pdo = getConexao();
$pastaUploads = dirname(__DIR__, 2) . '/produtos/uploads';

if (in_array($metodo, ['POST', 'PUT', 'DELETE'])) {
    exigirLogin();
}

function salvarImagemEnviada(string $pastaUploads): ?string
{
    if (empty($_FILES['imagem']) || $_FILES['imagem']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES['imagem']['error'] !== UPLOAD_ERR_OK) {
        respostaErro('Erro no upload da imagem.', 422);
    }

    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $tipo = mime_content_type($_FILES['imagem']['tmp_name']);

    if (!isset($permitidos[$tipo])) {
        respostaErro('Formato de imagem não suportado. Use JPG, PNG ou WEBP.', 422);
    }

    if (!is_dir($pastaUploads)) {
        mkdir($pastaUploads, 0755, true);
    }

    $nomeArquivo = uniqid('prod_', true) . '.' . $permitidos[$tipo];
    $destino = $pastaUploads . '/' . $nomeArquivo;

    if (!move_uploaded_file($_FILES['imagem']['tmp_name'], $destino)) {
        respostaErro('Falha ao salvar a imagem.', 500);
    }

    return 'produtos/uploads/' . $nomeArquivo;
}

switch ($metodo) {

    case 'GET':
        $busca = trim($_GET['busca'] ?? '');
        $categoriaSlug = trim($_GET['categoria'] ?? 'todos');
        $pagina = max(1, (int) ($_GET['pagina'] ?? 1));
        $porPagina = min(100, max(1, (int) ($_GET['por_pagina'] ?? 50)));
        $offset = ($pagina - 1) * $porPagina;

        $stmt = $pdo->prepare('CALL sp_listar_produtos(?, ?, ?, ?)');
        $stmt->execute([$busca, $categoriaSlug, $porPagina, $offset]);
        $produtos = array_map('normalizarProduto', $stmt->fetchAll());
        $stmt->closeCursor();

        $stmtTotal = $pdo->prepare('CALL sp_contar_produtos(?, ?)');
        $stmtTotal->execute([$busca, $categoriaSlug]);
        $total = (int) $stmtTotal->fetch()['total'];
        $stmtTotal->closeCursor();

        respostaSucesso([
            'produtos' => $produtos,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
        ]);
        break;

    case 'POST':
        $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $precoRaw = $_POST['preco'] ?? '';
        $estoque = (int) ($_POST['estoque'] ?? 0);

        if ($categoriaId <= 0 || $nome === '') {
            respostaErro('Categoria e nome são obrigatórios.', 422);
        }

        $caminhoImagem = salvarImagemEnviada($pastaUploads);
        if ($caminhoImagem === null) {
            respostaErro('A imagem do produto é obrigatória.', 422);
        }

        $preco = ($precoRaw === '' || $precoRaw === 'null') ? null : (float) $precoRaw;

        $stmt = $pdo->prepare(
            'INSERT INTO produtos (categoria_id, nome, preco, estoque, imagem) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$categoriaId, $nome, $preco, $estoque, $caminhoImagem]);
        $id = (int) $pdo->lastInsertId();

        respostaSucesso(['id' => $id, 'imagem' => $caminhoImagem], 'Produto criado com sucesso.');
        break;

    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
        $nome = trim($_POST['nome'] ?? '');
        $precoRaw = $_POST['preco'] ?? '';
        $estoque = (int) ($_POST['estoque'] ?? 0);

        if ($id <= 0 || $categoriaId <= 0 || $nome === '') {
            respostaErro('Dados inválidos para atualização.', 422);
        }

        $preco = ($precoRaw === '' || $precoRaw === 'null') ? null : (float) $precoRaw;
        $novaImagem = salvarImagemEnviada($pastaUploads);

        if ($novaImagem !== null) {
            $stmt = $pdo->prepare(
                'UPDATE produtos SET categoria_id=?, nome=?, preco=?, estoque=?, imagem=? WHERE id=?'
            );
            $stmt->execute([$categoriaId, $nome, $preco, $estoque, $novaImagem, $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE produtos SET categoria_id=?, nome=?, preco=?, estoque=? WHERE id=?'
            );
            $stmt->execute([$categoriaId, $nome, $preco, $estoque, $id]);
        }

        if ($stmt->rowCount() === 0) {
            // pode ser que os dados enviados sejam iguais aos já salvos — não é erro real
        }

        respostaSucesso(['id' => $id], 'Produto atualizado com sucesso.');
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('DELETE FROM produtos WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            respostaErro('Produto não encontrado.', 404);
        }

        respostaSucesso(null, 'Produto excluído com sucesso.');
        break;

    default:
        respostaErro('Método não suportado.', 405);
}
