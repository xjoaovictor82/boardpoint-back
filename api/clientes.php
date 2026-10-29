<?php
// Cadastro e login dos CLIENTES da loja (tabela `usuarios`).
// O login devolve um JWT com tipo 'cliente'. O token de admin (tipo 'admin') é
// emitido só por auth.php e nunca vale aqui.
//
//   POST ?acao=registrar  {nome, email, cpf, senha}
//   POST ?acao=login      {email, senha}
//   POST ?acao=logout
//   GET  ?acao=status
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validacoes.php';
 
$metodo = $_SERVER['REQUEST_METHOD'];
$acao = $_GET['acao'] ?? '';
 
switch ("$metodo:$acao") {
 
    case 'POST:registrar':
        $dados = corpoJson();
        $nome  = trim((string) ($dados['nome'] ?? ''));
        $email = strtolower(trim((string) ($dados['email'] ?? '')));
        $cpf   = preg_replace('/\D/', '', (string) ($dados['cpf'] ?? ''));
        $senha = (string) ($dados['senha'] ?? '');
 
        if (mb_strlen($nome) < 2 || mb_strlen($nome) > 150) {
            respostaErro('Informe seu nome completo.', 422);
        }
        if (!validarEmail($email)) {
            respostaErro('E-mail inválido.', 422);
        }
        if (!validarCPF($cpf)) {
            respostaErro('CPF inválido.', 422);
        }
        if (!validarSenha($senha)) {
            respostaErro('A senha deve ter no mínimo 8 caracteres, com maiúscula, minúscula, número e símbolo.', 422);
        }
 
        $pdo = getConexao();
 
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? OR cpf = ?');
        $stmt->execute([$email, $cpf]);
        if ($stmt->fetch()) {
            respostaErro('E-mail ou CPF já cadastrado.', 409);
        }
 
        try {
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, cpf, senha_hash) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nome, $email, $cpf, password_hash($senha, PASSWORD_BCRYPT)]);
        } catch (PDOException $e) {
            // 23000 = violação de UNIQUE (duas requisições ao mesmo tempo com o mesmo e-mail/CPF)
            if ($e->getCode() === '23000') {
                respostaErro('E-mail ou CPF já cadastrado.', 409);
            }
            error_log('clientes.php registrar: ' . $e->getMessage());
            respostaErro('Erro interno do servidor.', 500);
        }
 
        respostaJson(['sucesso' => true, 'mensagem' => 'Cadastro realizado com sucesso!'], 201);
        break;
 
    case 'POST:login':
        $dados = corpoJson();
        $email = strtolower(trim((string) ($dados['email'] ?? '')));
        $senha = (string) ($dados['senha'] ?? '');
 
        if ($email === '' || $senha === '') {
            respostaErro('Informe e-mail e senha.', 422);
        }
 
        $pdo = getConexao();
        $stmt = $pdo->prepare('SELECT id, nome, senha_hash FROM usuarios WHERE email = ?');
        $stmt->execute([$email]);
        $cliente = $stmt->fetch();
 
        // Mesma mensagem para "e-mail não existe" e "senha errada".
        if (!$cliente || !password_verify($senha, $cliente['senha_hash'])) {
            respostaErro('E-mail ou senha inválidos.', 401);
        }
 
        $token = emitirToken((int) $cliente['id'], 'cliente', ['nome' => $cliente['nome']]);
        respostaSucesso(['token' => $token, 'nome' => $cliente['nome']], 'Login realizado com sucesso.');
        break;
 
    case 'POST:logout':
        // JWT não fica guardado no servidor: sair = o front apagar o token.
        respostaSucesso(null, 'Sessão encerrada.');
        break;
 
    case 'GET:status':
        $payload = exigirLogin('cliente');
        respostaSucesso(['autenticado' => true, 'nome' => $payload['nome'] ?? '']);
        break;
 
    default:
        respostaErro('Ação inválida.', 404);
}