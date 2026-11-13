<?php
// Endpoint PÚBLICO: o visitante envia uma mensagem de contato (só POST, sem login).
// Ler, marcar como lida e excluir ficam em ../contato.php (exige token).
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respostaErro('Método não suportado.', 405);
}

$dados = corpoJson();
$nome = trim((string) ($dados['nome'] ?? ''));
$email = trim((string) ($dados['email'] ?? ''));
$mensagem = trim((string) ($dados['mensagem'] ?? ''));

if ($nome === '' || $email === '' || $mensagem === '') {
    respostaErro('Preencha nome, e-mail e mensagem.', 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respostaErro('E-mail inválido.', 422);
}
if (mb_strlen($nome) > 150 || mb_strlen($email) > 150) {
    respostaErro('Nome ou e-mail muito longos.', 422);
}

$stmt = getConexao()->prepare('INSERT INTO contatos (nome, email, mensagem) VALUES (?, ?, ?)');
$stmt->execute([$nome, $email, $mensagem]);

respostaSucesso(null, 'Mensagem enviada com sucesso.');