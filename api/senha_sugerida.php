<?php
// Sugestão de senha forte com IA generativa (API da Anthropic).
// A IA NUNCA recebe dados da pessoa: o pedido é só para gerar uma senha genérica.
// Toda senha que volta da IA passa de novo por validarSenha() antes de sair.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/validacoes.php';

function gerarSenhaSugerida(): array
{
    $senha = senhaPelaIA();
    if ($senha !== null && validarSenha($senha)) {
        return ['senha' => $senha, 'origem' => 'ia'];
    }
    // Sem chave, sem resposta, ou resposta fora das regras: gera localmente.
    return ['senha' => senhaAleatoriaLocal(), 'origem' => 'local'];
}

function senhaPelaIA(): ?string
{
    $chave = configValor('GEMINI_API_KEY', '');
    if ($chave === '') {
        return null;
    }

    $modelo = 'gemini-3.5-flash-lite';
    $corpo = json_encode([
        'contents' => [[
            'parts' => [[
                'text' => 'Gere UMA senha forte de 14 a 18 caracteres com letra maiúscula, letra minúscula, número e um símbolo entre !@#$%&*. Responda somente com a senha, sem aspas e sem explicações.',
            ]],
        ]],
        'generationConfig' => [
            'maxOutputTokens' => 1024,
            'temperature' => 1.0,
        ],
    ]);

    $contexto = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nx-goog-api-key: $chave\r\n",
        'content' => $corpo,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);

    $url = "https://generativelanguage.googleapis.com/v1beta/models/$modelo:generateContent";
    $resposta = @file_get_contents($url, false, $contexto);
    if ($resposta === false) {
        error_log('senha_sugerida: falha de rede ao chamar o Gemini');
        return null;
    }

    $dados = json_decode($resposta, true);
    $senha = trim((string) ($dados['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($senha === '' || mb_strlen($senha) > 64 || preg_match('/\s/', $senha)) {
        error_log('senha_sugerida: resposta do Gemini fora do esperado');
        return null;
    }
    return $senha;
}

function senhaAleatoriaLocal(): string
{
    // Um caractere de cada tipo exigido, e o resto sorteado de todos os grupos.
    $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*'];
    $senha = [];
    foreach ($grupos as $grupo) {
        $senha[] = $grupo[random_int(0, strlen($grupo) - 1)];
    }
    $todos = implode('', $grupos);
    while (count($senha) < 16) {
        $senha[] = $todos[random_int(0, strlen($todos) - 1)];
    }
    // Embaralha (Fisher-Yates com random_int, que é criptograficamente seguro).
    for ($i = count($senha) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$senha[$i], $senha[$j]] = [$senha[$j], $senha[$i]];
    }
    return implode('', $senha);
}
