<?php
// Login do admin: e-mail + senha (password_verify). Devolve um JWT.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validacoes.php';
 
$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';
 
switch ("$metodo:$acao") {
 
    case 'POST:login':
        $dados = corpoJson();
        $email = strtolower(trim((string) ($dados['email'] ?? '')));
        $senha = (string) ($dados['senha'] ?? '');
 
        if ($email === '' || $senha === '') {
            respostaErro('Informe e-mail e senha.', 422);
        }
        if (!validarEmail($email)) {
            respostaErro('E-mail inválido.', 422);
        }
 
        $stmt = getConexao()->prepare('SELECT id, usuario, senha_hash FROM admins WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
 
        if (!$admin || !password_verify($senha, $admin['senha_hash'])) {
            respostaErro('E-mail ou senha inválidos.', 401);
        }
 
        $token = emitirToken((int) $admin['id'], 'admin', ['usuario' => $admin['usuario']]);
        respostaSucesso(['token' => $token, 'usuario' => $admin['usuario']], 'Login realizado com sucesso.');
        break;
 
    case 'GET:status':
        $payload = exigirLogin('admin');
        respostaSucesso(['autenticado' => true, 'usuario' => $payload['usuario'] ?? '']);
        break;
 
    case 'POST:logout':
        // JWT não fica guardado no servidor: sair = o front apagar o token.
        respostaSucesso(null, 'Sessão encerrada.');
        break;
 
    default:
        respostaErro('Ação inválida.', 404);
}
 