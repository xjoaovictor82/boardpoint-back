<?php
/**
 * BOARD POINT — Configuração local (uso no XAMPP)
 *
 * Copie este arquivo para "config_local.php" (mesma pasta) e preencha.
 * O config_local.php está no .gitignore, então a chave PIX não vai para o GitHub.
 * No Docker você não precisa dele: use o arquivo .env.
 */

return [
    'PIX_CHAVE'          => '',              // ex.: '+5544999999999'
    'PIX_NOME_RECEBEDOR' => 'BOARD POINT',
    'PIX_CIDADE'         => 'CAMPO MOURAO',

    // Só descomente se o seu MySQL não for o padrão do XAMPP:
    // 'DB_HOST' => '127.0.0.1',
    // 'DB_NAME' => 'boardpoint',
    // 'DB_USER' => 'root',
    // 'DB_PASS' => '',
];
