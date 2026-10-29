<?php

function validarCPF(string $cpf): bool {
    $cpf = preg_replace('/\D/', '', $cpf);

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($t = 9; $t < 11; $t++) {
        for ($d = 0, $c = 0; $c < $t; $c++) {
            $d += $cpf[$c] * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ($cpf[$c] != $d) {
            return false;
        }
    }

    return true;
}

function validarEmail(string $email): bool {
    return strlen($email) <= 150 && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/', $email) === 1;
}

function validarSenha(string $senha): bool {
    if (strlen($senha) < 8) return false;
    if (!preg_match('/[A-Z]/', $senha)) return false;
    if (!preg_match('/[a-z]/', $senha)) return false;
    if (!preg_match('/[0-9]/', $senha)) return false;
    if (!preg_match('/[\W_]/', $senha)) return false;

    return true;
}