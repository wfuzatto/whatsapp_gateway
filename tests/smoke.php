<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/Rules.php';

function check(bool $condition, string $label): void {
    if (!$condition) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    echo "OK: $label\n";
}
check(validPhone('5511999998888'), 'E.164 numeric WhatsApp ID');
check(!validPhone('+55 11 9999'), 'reject invalid phone');
check(validId('123456789012'), 'Graph numeric ID');
check(!validId('..'), 'reject path traversal');
$payload = Rules::normaliseMessage(['to'=>'5511999998888','type'=>'template','template'=>['name'=>'hello_world','language'=>['code'=>'en_US']]]);
check($payload['messaging_product'] === 'whatsapp', 'Meta product');
check($payload['recipient_type'] === 'individual', 'Meta recipient type');
try { Rules::normaliseMessage(['to'=>'5511999998888','type'=>'text','from'=>'wrong']); check(false,'blocked from'); }
catch (HttpError $e) { check(true,'blocked from'); }
$key = base64_encode(random_bytes(32));
putenv('MASTER_KEY_BASE64=' . $key);
$secret = 'sample-secret-' . bin2hex(random_bytes(8));
check(unseal(seal($secret)) === $secret, 'sodium encrypted secrets roundtrip');
check(hash_equals(hash_hmac('sha256', 'payload', 'abc'), hash_hmac('sha256','payload','abc')), 'HMAC primitive');
echo "All tests passed\n";
