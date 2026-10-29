
<?php
/**
 * BOARD POINT — Configuração central da API
 * Conexão PDO com o banco, autenticação por JWT, helpers de resposta.
 *
 * Cada configuração é lida nesta ordem de prioridade:
 *   1. variável de ambiente  (Docker: vem do docker-compose / .env)
 *   2. config_local.php      (XAMPP: copie config_local.example.php; fica fora do Git)
 *   3. valor padrão do XAMPP (root sem senha, via 127.0.0.1)
 */
 
// Lê uma configuração. O padrão só vale se não houver env nem config_local.php.
// (getenv() devolve false quando a variável não existe; string vazia é valor válido,
// por exemplo a senha vazia do XAMPP.)
function configValor(string $nome, string $padrao = ''): string
{
    static $local = null;
    if ($local === null) {
        $arquivo = __DIR__ . '/config_local.php';
        $local = is_file($arquivo) ? (array) require $arquivo : [];
    }
    $env = getenv($nome);
    if ($env !== false) return $env;
    if (array_key_exists($nome, $local)) return (string) $local[$nome];
    return $padrao;
}
 
// --- Configuração do banco ---------------------------------------------------
define('DB_HOST', configValor('DB_HOST', '127.0.0.1'));
define('DB_NAME', configValor('DB_NAME', 'boardpoint'));
define('DB_USER', configValor('DB_USER', 'root'));
define('DB_PASS', configValor('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');
 
// --- JWT -----------------------------------------------------------------------
require_once __DIR__ . '/jwt.php';
 
define('JWT_SECRET', configValor('JWT_SECRET', ''));
define('JWT_TTL', 60 * 60 * 8); // o token vale 8 horas
 
// --- Conexão PDO ---------------------------------------------------------------
function getConexao(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]);
        } catch (PDOException $e) {
            respostaErro('Falha na conexão com o banco de dados: ' . $e->getMessage(), 500);
        }
    }
    return $pdo;
}
 
// --- Helpers de resposta JSON ---------------------------------------------------
function respostaJson($dados, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
 
function respostaErro(string $mensagem, int $status = 400): void
{
    respostaJson(['sucesso' => false, 'erro' => $mensagem], $status);
}
 
function respostaSucesso($dados = null, string $mensagem = ''): void
{
    $payload = ['sucesso' => true];
    if ($mensagem !== '') $payload['mensagem'] = $mensagem;
    if ($dados !== null) $payload['dados'] = $dados;
    respostaJson($payload, 200);
}
 
// --- Autenticação por JWT --------------------------------------------------------
function emitirToken(int $id, string $tipo, array $extra = []): string
{
    if (JWT_SECRET === '') {
        respostaErro('Servidor sem JWT_SECRET configurado.', 500);
    }
    $agora = time();
    return gerarJWT(
        array_merge($extra, ['sub' => $id, 'tipo' => $tipo, 'iat' => $agora, 'exp' => $agora + JWT_TTL]),
        JWT_SECRET
    );
}
 
function tokenDaRequisicao(): ?string
{
    $cabecalho = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');
 
    if (preg_match('/^Bearer\s+(\S+)$/i', (string) $cabecalho, $m)) {
        return $m[1];
    }
    return null;
}
 
/**
 * Exige um token válido do tipo pedido ('admin' ou 'cliente') e devolve o payload.
 * 401 = sem token / token inválido ou expirado. 403 = token válido, mas do tipo errado.
 */
function exigirLogin(string $tipo = 'admin'): array
{
    $token = tokenDaRequisicao();
    $payload = $token ? validarJWT($token, JWT_SECRET) : null;
 
    if (JWT_SECRET === '' || $payload === null || empty($payload['sub'])) {
        respostaErro('Não autenticado. Faça login novamente.', 401);
    }
    if (($payload['tipo'] ?? '') !== $tipo) {
        respostaErro('Você não tem permissão para esta ação.', 403);
    }
    return $payload;
}
 
// --- Normalização de tipos (MySQL via PDO retorna tudo como string) -------------
function normalizarProduto(array $p): array
{
    return [
        'id'             => (int) $p['id'],
        'nome'           => $p['nome'],
        'preco'          => $p['preco'] !== null ? (float) $p['preco'] : null,
        'estoque'        => (int) $p['estoque'],
        'imagem'         => $p['imagem'],
        'ativo'          => (bool) $p['ativo'],
        'categoria_id'   => (int) $p['categoria_id'],
        'categoria_slug' => $p['categoria_slug'],
        'categoria_nome' => $p['categoria_nome'],
    ];
}
 
function normalizarCategoria(array $c): array
{
    return [
        'id'   => (int) $c['id'],
        'slug' => $c['slug'],
        'nome' => $c['nome'],
    ];
}
 
// --- Entrada JSON do corpo da requisição -----------------------------------------
function corpoJson(): array
{
    $raw = file_get_contents('php://input');
    $dados = json_decode($raw, true);
    return is_array($dados) ? $dados : [];
}
 