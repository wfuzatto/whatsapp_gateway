<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

function usage(): never {
    echo "Usage:\n";
    echo "  php bin/manage.php account:add NAME WABA_ID PHONE_NUMBER_ID\n";
    echo "  php bin/manage.php account:list\n";
    echo "  php bin/manage.php account:token:rotate PHONE_NUMBER_ID\n";
    echo "  php bin/manage.php key:add PHONE_NUMBER_ID LABEL\n";
    echo "  php bin/manage.php key:revoke KEY_ID\n";
    echo "  php bin/manage.php callback:set PHONE_NUMBER_ID HTTPS_URL\n";
    exit(1);
}
function secretFromStdin(string $prompt): string {
    fwrite(STDOUT,$prompt . ' (stdin; do not paste into shell arguments): ');
    $s = trim((string)fgets(STDIN));
    if (strlen($s)<16) throw new RuntimeException('Secret is too short');
    return $s;
}
try {
    $cmd = $argv[1] ?? '';
    if ($cmd === 'account:add' && count($argv) === 5) {
        [$_,$cmd,$name,$waba,$phone] = $argv;
        if (!validId($waba) || !validId($phone)) throw new RuntimeException('Invalid WABA or phone ID');
        $token = secretFromStdin('Meta system user access token');
        $s = db()->prepare('INSERT INTO accounts(name,waba_id,phone_number_id,token_box) VALUES (?,?,?,?)');
        $s->execute([$name,$waba,$phone,seal($token)]);
        echo "Account added.\n";
    } elseif ($cmd === 'account:list') {
        foreach (db()->query('SELECT id,name,waba_id,phone_number_id,created_at FROM accounts') as $a)
            echo jsonString($a) . "\n";
    } elseif ($cmd === 'account:token:rotate' && count($argv) === 3) {
        $token = secretFromStdin('New Meta system user access token');
        $s = db()->prepare('UPDATE accounts SET token_box=? WHERE phone_number_id=?');
        $s->execute([seal($token),$argv[2]]);
        if (!$s->rowCount()) throw new RuntimeException('No matching account');
        echo "Token rotated.\n";
    } elseif ($cmd === 'key:add' && count($argv) === 4) {
        $a = accountByPhone($argv[2]);
        if (!$a) throw new RuntimeException('Unknown account');
        $key = 'wg_' . bin2hex(random_bytes(32));
        $s = db()->prepare('INSERT INTO api_keys(account_id,name,token_hash) VALUES (?,?,?)');
        $s->execute([$a['id'],$argv[3],hash('sha256',$key)]);
        echo "API key (shown ONCE): $key\n";
    } elseif ($cmd === 'key:revoke' && count($argv) === 3 && ctype_digit($argv[2])) {
        $s = db()->prepare('UPDATE api_keys SET active=0 WHERE id=?');
        $s->execute([$argv[2]]);
        echo "Revoked keys: " . $s->rowCount() . "\n";
    } elseif ($cmd === 'callback:set' && count($argv) === 4) {
        $url = $argv[3];
        $p = parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) ||
            filter_var($p['host'], FILTER_VALIDATE_IP) || strtolower($p['host'])==='localhost' ||
            !empty($p['user']) || !empty($p['pass'])) throw new RuntimeException('HTTPS hostname URL required');
        $secret = secretFromStdin('Callback HMAC secret');
        $s = db()->prepare('UPDATE accounts SET callback_url=?,callback_secret_box=? WHERE phone_number_id=?');
        $s->execute([$url,seal($secret),$argv[2]]);
        if (!$s->rowCount()) throw new RuntimeException('No matching account');
        echo "Callback configured.\n";
    } else usage();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
