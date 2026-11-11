<?php
declare(strict_types=1);
/**
 * Receptor diagnóstico de callbacks emitidos pelo próprio ZapAPI.
 * NÃO é o endpoint que a Meta chama.
 * Exige WHATSAPP_CALLBACK_TEST_SECRET no .env privado.
 */
require_once dirname(__DIR__) . '/src/whatsapp_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function callbackReply(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    callbackReply(405, ['ok'=>false,'error'=>'POST required']);
}
try {
    requireHttps();
} catch (Throwable $e) {
    callbackReply(403, ['ok'=>false,'error'=>'HTTPS required']);
}
$secret = config('WHATSAPP_CALLBACK_TEST_SECRET');
if (strlen($secret) < 32) {
    callbackReply(503, ['ok'=>false,'error'=>'Callback receiver not configured']);
}
$max = 262144;
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) {
    callbackReply(413, ['ok'=>false,'error'=>'Payload too large']);
}
$raw = file_get_contents('php://input', false, null, 0, $max + 1);
if (!is_string($raw) || strlen($raw) > $max) {
    callbackReply(413, ['ok'=>false,'error'=>'Payload too large']);
}
$signature = (string)($_SERVER['HTTP_X_PONTE_SIGNATURE']
    ?? $_SERVER['HTTP_X_GATEWAY_SIGNATURE_256'] ?? '');
$expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
if (!preg_match('/^sha256=[a-f0-9]{64}$/D', $signature) ||
    !hash_equals($expected, $signature)) {
    callbackReply(401, ['ok'=>false,'error'=>'Invalid signature']);
}
try {
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    callbackReply(400, ['ok'=>false,'error'=>'Invalid JSON']);
}
if (!is_array($payload) || array_is_list($payload)) {
    callbackReply(400, ['ok'=>false,'error'=>'Expected JSON object']);
}
// Apenas metadados, sem conteúdo de mensagens, tokens, telefones ou JSON bruto.
$eventId = (string)($_SERVER['HTTP_X_GATEWAY_EVENT_ID'] ?? $_SERVER['HTTP_X_PONTE_EVENT_ID'] ?? '');
$eventType = (string)($_SERVER['HTTP_X_PONTE_EVENT'] ?? $payload['event_type'] ?? 'unknown');
$eventId = preg_replace('/[^a-zA-Z0-9._:-]/', '', $eventId) ?: 'n/a';
$eventType = preg_replace('/[^a-zA-Z0-9._:-]/', '', $eventType) ?: 'unknown';
$line = gmdate('c') . ' event=' . substr($eventType, 0, 80) .
        ' event_id=' . substr($eventId, 0, 128) . PHP_EOL;
$logFile = dirname(__DIR__, 2) . '/whatsapp_callback_test_events.log';
if (@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
    error_log('ZapAPI callback diagnostic: private log is not writable');
    callbackReply(503, ['ok'=>false,'error'=>'Private event log unavailable']);
}
@chmod($logFile, 0600);
callbackReply(200, ['ok'=>true,'received'=>true]);
