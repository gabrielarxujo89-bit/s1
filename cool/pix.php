<?php

ini_set('display_errors', '0');
header('Content-Type: application/json');

$API_TOKEN = 'sk_86ae326674ae10697cded887dfb2f1c9a0d32fa4cba8ea334fe2efacdd3b4739';
$PRODUCT_HASH = 'prod_16b101094666d7f8';
$PRODUCT_TITLE = 'jose do doce';
$VALORES_PERMITIDOS = [5, 10, 15, 25, 50, 100];
$API_BASE = 'https://multi.paradisepags.com/api/v1';

function responder($codigo, $dados) {
    http_response_code($codigo);
    echo json_encode($dados);
    exit;
}

function chamarApi($url, $token, $payload = null) {
    $ch = curl_init($url);
    $headers = ['Accept: application/json', 'X-API-Key: ' . $token];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    $resposta = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    if ($erro) responder(502, ['error' => 'Falha ao contatar o gateway.']);
    return [$http, json_decode($resposta, true)];
}

if (($_GET['action'] ?? '') === 'check_status') {
    $hash = $_GET['hash'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $hash)) responder(400, ['error' => 'Hash inválido.']);
    [, $dados] = chamarApi($API_BASE . '/check_status.php?hash=' . urlencode($hash), $API_TOKEN);
    responder(200, ['payment_status' => $dados['payment_status'] ?? 'pending']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['error' => 'Método não permitido.']);

$entrada = json_decode(file_get_contents('php://input'), true) ?? [];
$valor = (int)($entrada['valor'] ?? 0);
$cliente = $entrada['customer'] ?? [];

if (!in_array($valor, $VALORES_PERMITIDOS, true)) responder(400, ['error' => 'Valor inválido.']);

$nome = trim((string)($cliente['name'] ?? ''));
$email = trim((string)($cliente['email'] ?? ''));
$cpf = preg_replace('/\D/', '', (string)($cliente['document'] ?? ''));
$telefone = preg_replace('/\D/', '', (string)($cliente['phone'] ?? ''));

if (mb_strlen($nome) < 3) responder(400, ['error' => 'Informe seu nome completo.']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) responder(400, ['error' => 'Informe um e-mail válido.']);
if (strlen($cpf) !== 11) responder(400, ['error' => 'Informe um CPF válido.']);
if (strlen($telefone) < 10 || strlen($telefone) > 11) responder(400, ['error' => 'Informe um telefone válido com DDD.']);

$referencia = 'DOA-' . uniqid();
[$http, $dados] = chamarApi($API_BASE . '/transaction.php', $API_TOKEN, [
    'amount' => $valor * 100,
    'description' => $PRODUCT_TITLE,
    'reference' => $referencia,
    'productHash' => $PRODUCT_HASH,
    'customer' => [
        'name' => $nome,
        'email' => $email,
        'document' => $cpf,
        'phone' => $telefone,
    ],
]);

$transacao = $dados['transaction'] ?? $dados;
if ($http < 200 || $http >= 300 || empty($transacao['qr_code'])) {
    error_log('Paradise erro HTTP ' . $http . ': ' . json_encode($dados));
    $motivo = $dados['message'] ?? $dados['error'] ?? 'Resposta sem código Pix';
    responder($http >= 400 ? $http : 502, ['error' => 'Gateway: ' . (is_string($motivo) ? $motivo : json_encode($motivo)) . ' (HTTP ' . $http . ')']);
}

responder(200, [
    'hash' => $transacao['id'] ?? $referencia,
    'codigo' => $transacao['qr_code'],
    'expira_em' => $transacao['expires_at'] ?? null,
]);
