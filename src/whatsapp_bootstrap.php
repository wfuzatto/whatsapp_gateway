<?php
declare(strict_types=1);
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
error_reporting(E_ALL);

function loadEnvironment(): void {
    $file = dirname(__DIR__) . '/.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $m)) continue;
        $value = trim($m[2]);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) $value = substr($value, 1, -1);
        if (getenv($m[1]) === false) putenv($m[1] . '=' . $value);
    }
}
loadEnvironment();

function config(string $key, string $default = ''): string {
    $v = getenv($key);
    return $v === false ? $default : $v;
}
class HttpError extends RuntimeException {
    public function __construct(string $message, public int $httpStatus = 400) { parent::__construct($message); }
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $host = config('DB_HOST', '127.0.0.1');
    $port = config('DB_PORT', '3306');
    $dbname = config('DB_NAME');
    if ($dbname === '' || !ctype_digit($port)) throw new RuntimeException('Database configuration missing');
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        config('DB_USER'), config('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
function jsonString(mixed $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
function response(int $status, mixed $data): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo jsonString($data);
    exit;
}
function requestJson(): array {
    $max = max(1024, (int)config('MAX_BODY_BYTES', '2097152'));
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new HttpError('Body too large', 413);
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) throw new HttpError('Body too large', 413);
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new HttpError('Invalid JSON'); }
    if (!is_array($data) || array_is_list($data)) throw new HttpError('Expected JSON object');
    return $data;
}
function requireHttps(): void {
    if (config('APP_REQUIRE_HTTPS', '1') !== '1') return;
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    if (config('TRUST_PROXY', '0') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') $https = true;
    if (!$https) throw new HttpError('HTTPS required', 403);
}
function masterKey(): string {
    $raw = base64_decode(config('MASTER_KEY_BASE64'), true);
    if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES)
        throw new RuntimeException('MASTER_KEY_BASE64 must be 32 bytes encoded as base64');
    return $raw;
}
function seal(string $value): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, masterKey()));
}
function unseal(string $ciphertext): string {
    $raw = base64_decode($ciphertext, true);
    if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES)
        throw new RuntimeException('Invalid encrypted secret');
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $data = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, masterKey());
    if ($data === false) throw new RuntimeException('Secret decryption failed');
    return $data;
}
/**
 * Autenticacao com escopo de tenant. Uma chave pode ser de tenant (varios numeros)
 * ou vinculada a um numero especifico. Nunca confie em tenant_id do JSON enviado.
 */
function bearerContext(): array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\\s+([a-zA-Z0-9_-]{20,160})$/', $auth, $m))
        throw new HttpError('Invalid or missing API token',401);
    $s = db()->prepare("SELECT k.id AS key_id,k.tenant_id,k.account_id,t.slug AS tenant_slug
        FROM whatsapp_api_keys k
        JOIN whatsapp_tenants t ON t.id=k.tenant_id
        WHERE k.token_hash=? AND k.active=1 AND t.status='active'
        AND (NOT EXISTS(SELECT 1 FROM whatsapp_subscriptions s WHERE s.tenant_id=t.id)
             OR EXISTS(SELECT 1 FROM whatsapp_subscriptions s WHERE s.tenant_id=t.id AND s.status='active'))
        AND (k.account_id IS NULL OR EXISTS (
             SELECT 1 FROM whatsapp_accounts a WHERE a.tenant_id=k.tenant_id
               AND a.id=k.account_id AND a.status='active')) LIMIT 1");
    $s->execute([hash('sha256',$m[1])]);
    $context = $s->fetch();
    if (!$context) throw new HttpError('Invalid or missing API token',401);
    return $context;
}
function selectedAccount(array $context, ?string $requestedPhoneId): array {
    $tenantId = (int)$context['tenant_id'];
    if ($requestedPhoneId !== null && $requestedPhoneId !== '' && !validId($requestedPhoneId))
        throw new HttpError('Invalid X-WhatsApp-Phone-Id header',400);
    if ($context['account_id'] !== null) {
        $s = db()->prepare("SELECT * FROM whatsapp_accounts
              WHERE tenant_id=? AND id=? AND status='active' LIMIT 1");
        $s->execute([$tenantId,$context['account_id']]);
        $account = $s->fetch();
        if (!$account || ($requestedPhoneId && $requestedPhoneId !== $account['phone_number_id']))
            throw new HttpError('WhatsApp number not found for this API key',404);
        return $account;
    }
    if ($requestedPhoneId) {
        $s = db()->prepare("SELECT * FROM whatsapp_accounts
              WHERE tenant_id=? AND phone_number_id=? AND status='active' LIMIT 1");
        $s->execute([$tenantId,$requestedPhoneId]);
        $account = $s->fetch();
        if (!$account) throw new HttpError('WhatsApp number not found for this tenant',404);
        return $account;
    }
    $s = db()->prepare("SELECT * FROM whatsapp_accounts WHERE tenant_id=? AND status='active' ORDER BY id LIMIT 2");
    $s->execute([$tenantId]);
    $accounts = $s->fetchAll();
    if (count($accounts)===0) throw new HttpError('Tenant has no active WhatsApp number',404);
    if (count($accounts)>1)
        throw new HttpError('Multiple numbers: supply X-WhatsApp-Phone-Id header',400);
    return $accounts[0];
}
function bearerAccount(): array {
    return selectedAccount(bearerContext(),$_SERVER['HTTP_X_WHATSAPP_PHONE_ID']??null);
}
function tenantBySlug(string $slug): ?array {
    $s=db()->prepare('SELECT * FROM whatsapp_tenants WHERE slug=?');
    $s->execute([$slug]);
    return $s->fetch() ?: null;
}
function accountByPhone(string $phoneId): ?array {
    $s=db()->prepare("SELECT a.* FROM whatsapp_accounts a
                      JOIN whatsapp_tenants t ON t.id=a.tenant_id
                      WHERE a.phone_number_id=? AND a.status='active' AND t.status='active'");
    $s->execute([$phoneId]);
    return $s->fetch() ?: null;
}
function accountByWaba(string $wabaId): ?array {
    $s=db()->prepare("SELECT a.* FROM whatsapp_accounts a
                      JOIN whatsapp_wabas w ON w.waba_id=a.waba_id AND w.tenant_id=a.tenant_id
                      JOIN whatsapp_tenants t ON t.id=a.tenant_id
                      WHERE w.waba_id=? AND a.status='active' AND t.status='active'
                      ORDER BY a.id LIMIT 1");
    $s->execute([$wabaId]);
    return $s->fetch() ?: null;
}
function audit(?int $accountId,string $action,array $details=[]): void {
    if ($accountId===null) throw new RuntimeException('Audit requires a tenant-bound account');
    $s=db()->prepare('INSERT INTO whatsapp_audit_logs(tenant_id,account_id,action,details)
                      SELECT tenant_id,id,?,? FROM whatsapp_accounts WHERE id=?');
    $s->execute([$action,jsonString($details),$accountId]);
}
function validId(string $s): bool { return (bool)preg_match('/^[0-9]{1,40}$/D', $s); }
function validPhone(string $s): bool { return (bool)preg_match('/^[0-9]{7,20}$/D', $s); }
