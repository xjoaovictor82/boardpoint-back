<?php

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

function gerarJWT(array $payload, string $secret): string {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payloadJson = json_encode($payload);

    $base64UrlHeader = base64UrlEncode($header);
    $base64UrlPayload = base64UrlEncode($payloadJson);

    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = base64UrlEncode($signature);

    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

function validarJWT(string $jwt, string $secret): ?array {
    $partes = explode('.', $jwt);
    if (count($partes) !== 3) {
        return null;
    }

    [$header, $payload, $signature] = $partes;

    $validSignature = base64UrlEncode(
        hash_hmac('sha256', $header . "." . $payload, $secret, true)
    );

    if (!hash_equals($validSignature, $signature)) {
        return null;
    }

    $dadosPayload = json_decode(base64UrlDecode($payload), true);
    if (isset($dadosPayload['exp']) && $dadosPayload['exp'] < time()) {
        return null;
    }

    return $dadosPayload;
}