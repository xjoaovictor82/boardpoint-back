<?php
/**
 * BOARD POINT — Configuração da loja (PIX)
 *
 * Os valores NÃO ficam mais no código. Defina-os em:
 *   - Docker: arquivo .env (PIX_CHAVE, PIX_NOME_RECEBEDOR, PIX_CIDADE)
 *   - XAMPP:  admin/api/config_local.php (copie de config_local.example.php)
 *
 * - PIX_CHAVE: CPF (só números), e-mail, telefone (+55DDDNUMERO) ou chave aleatória.
 * - PIX_NOME_RECEBEDOR: nome que o cliente vê no app do banco (até 25 caracteres).
 * - PIX_CIDADE: cidade do titular da chave (até 15 caracteres).
 *
 * Este arquivo depende do config.php (função configValor), que deve ser carregado antes.
 */

define('PIX_CHAVE', configValor('PIX_CHAVE', ''));
define('PIX_NOME_RECEBEDOR', configValor('PIX_NOME_RECEBEDOR', 'BOARD POINT'));
define('PIX_CIDADE', configValor('PIX_CIDADE', 'CAMPO MOURAO'));
