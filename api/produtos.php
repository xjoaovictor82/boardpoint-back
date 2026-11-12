<?php
// CRUD ADMINISTRATIVO de produtos. TODOS os métodos exigem token de admin.
// (A leitura pública para o site fica em publico/produtos.php.)
//
//   GET    ?pagina=1&por_pagina=10[&busca=..&categoria=slug]  lista paginada
//   GET    ?id=X                                              um produto (404 se não existir)
//   POST   multipart: categoria_id, nome, preco, estoque, imagem   cria
//   PUT    ?id=X (multipart; envie POST com ?_method=PUT)          edita (404 se não existir)
//   DELETE ?id=X                                              exclui (404; 409 se está em pedidos)
require_once __DIR__ . '/config.php';

$metodo = $_SERVER['REQUEST_METHOD'];

// Method override: PHP não popula $_FILES em requisições PUT nativas, então o painel
// envia POST com ?_method=PUT quando precisa editar com upload de imagem.
if ($metodo === 'POST' && ($_GET['_method'] ?? '') === 'PUT') {
    $metodo = 'PUT';
}

exigirLogin('admin');

$pdo = getConexao();
$pastaUploads = dirname(__DIR__, 2) . '/produtos/uploads';

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

// Lê e valida os campos do formulário. Devolve [categoriaId, nome, preco, estoque].
function lerDadosProduto(PDO $pdo): array
{
    $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $precoRaw = trim((string) ($_POST['preco'] ?? ''));
    $estoqueRaw = trim((string) ($_POST['estoque'] ?? '0'));

    if ($categoriaId <= 0 || $nome === '') {
        respostaErro('Categoria e nome são obrigatórios.', 422);
    }
    if (mb_strlen($nome) > 150) {
        respostaErro('O nome pode ter no máximo 150 caracteres.', 422);
    }

    if ($precoRaw === '' || $precoRaw === 'null') {
        $preco = null; // sem preço = "Consulte o valor" no site
    } else {
        $precoRaw = str_replace(',', '.', $precoRaw);
        if (!is_numeric($precoRaw) || (float) $precoRaw < 0 || (float) $precoRaw > 99999999.99) {
            respostaErro('Preço inválido.', 422);
        }
        $preco = round((float) $precoRaw, 2);
    }

    if ($estoqueRaw === '') {
        $estoqueRaw = '0';
    }
    if (!ctype_digit($estoqueRaw) || (int) $estoqueRaw > 1000000) {
        respostaErro('Estoque inválido: use um número inteiro maior ou igual a zero.', 422);
    }

    if (!registroExiste($pdo, 'categorias', $categoriaId)) {
        respostaErro('Categoria inexistente.', 422);
    }

    return [$categoriaId, $nome, $preco, (int) $estoqueRaw];
}

switch ($metodo) {

    case 'GET':
        if (isset($_GET['id'])) {
            $id = (int) $_GET['id'];
            if ($id <= 0) {
                respostaErro('ID inválido.', 422);
            }
            $stmt = $pdo->prepare(
                'SELECT id, nome, preco, estoque, imagem, ativo, categoria_id, categoria_slug, categoria_nome
                 FROM vw_produtos_completo WHERE id = ?'
            );
            $stmt->execute([$id]);
            $linha = $stmt->fetch();
            if (!$linha) {
                respostaErro('Produto não encontrado.', 404);
            }
            respostaSucesso(normalizarProduto($linha));
        }

        $busca = trim((string) ($_GET['busca'] ?? ''));
        $categoriaSlug = trim((string) ($_GET['categoria'] ?? 'todos'));
        [$pagina, $porPagina, $offset] = paginacao();

        $stmt = $pdo->prepare('CALL sp_listar_produtos(?, ?, ?, ?)');
        $stmt->execute([$busca, $categoriaSlug, $porPagina, $offset]);
        $produtos = array_map('normalizarProduto', $stmt->fetchAll());
        $stmt->closeCursor();

        $stmtTotal = $pdo->prepare('CALL sp_contar_produtos(?, ?)');
        $stmtTotal->execute([$busca, $categoriaSlug]);
        $total = (int) $stmtTotal->fetch()['total'];
        $stmtTotal->closeCursor();

        respostaPaginada($produtos, $total, $pagina, $porPagina);
        break;

    case 'POST':
        [$categoriaId, $nome, $preco, $estoque] = lerDadosProduto($pdo);

        $caminhoImagem = salvarImagemEnviada($pastaUploads);
        if ($caminhoImagem === null) {
            respostaErro('A imagem do produto é obrigatória.', 422);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO produtos (categoria_id, nome, preco, estoque, imagem) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$categoriaId, $nome, $preco, $estoque, $caminhoImagem]);

        respostaJson([
            'sucesso'  => true,
            'mensagem' => 'Produto criado com sucesso.',
            'dados'    => ['id' => (int) $pdo->lastInsertId(), 'imagem' => $caminhoImagem],
        ], 201);
        break;

    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }
        // 404 ANTES de validar e antes de gravar qualquer imagem (não deixa arquivo órfão).
        if (!registroExiste($pdo, 'produtos', $id)) {
            respostaErro('Produto não encontrado.', 404);
        }

        [$categoriaId, $nome, $preco, $estoque] = lerDadosProduto($pdo);
        $novaImagem = salvarImagemEnviada($pastaUploads);

        if ($novaImagem !== null) {
            $stmt = $pdo->prepare(
                'UPDATE produtos SET categoria_id = ?, nome = ?, preco = ?, estoque = ?, imagem = ? WHERE id = ?'
            );
            $stmt->execute([$categoriaId, $nome, $preco, $estoque, $novaImagem, $id]);
        } else {
            $stmt = $pdo->prepare(
                'UPDATE produtos SET categoria_id = ?, nome = ?, preco = ?, estoque = ? WHERE id = ?'
            );
            $stmt->execute([$categoriaId, $nome, $preco, $estoque, $id]);
        }

        respostaSucesso(['id' => $id], 'Produto atualizado com sucesso.');
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            respostaErro('ID inválido.', 422);
        }

        $stmt = $pdo->prepare('SELECT imagem FROM produtos WHERE id = ?');
        $stmt->execute([$id]);
        $produto = $stmt->fetch();
        if (!$produto) {
            respostaErro('Produto não encontrado.', 404);
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM produtos WHERE id = ?');
            $stmt->execute([$id]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                respostaErro('Não é possível excluir: este produto aparece em pedidos.', 409);
            }
            error_log('produtos.php DELETE: ' . $e->getMessage());
            respostaErro('Erro interno do servidor.', 500);
        }

        // Só apaga o arquivo se foi enviado pelo painel (as fotos originais do projeto ficam).
        if (strpos((string) $produto['imagem'], 'produtos/uploads/') === 0) {
            $arquivo = $pastaUploads . '/' . basename($produto['imagem']);
            if (is_file($arquivo)) {
                @unlink($arquivo);
            }
        }

        respostaSucesso(null, 'Produto excluído com sucesso.');
        break;

    default:
        respostaErro('Método não suportado.', 405);
}