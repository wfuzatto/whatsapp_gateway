<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/whatsapp_bootstrap.php';

function usage(): never {
    echo "Uso (administrador local, somente CLI):\n";
    echo "  php bin/whatsapp_manage.php tenant:add SLUG NOME\n";
    echo "  php bin/whatsapp_manage.php tenant:list\n";
    echo "  php bin/whatsapp_manage.php tenant:status SLUG active|suspended\n";
    echo "  php bin/whatsapp_manage.php account:add TENANT_SLUG NOME WABA_ID PHONE_NUMBER_ID\n";
    echo "  php bin/whatsapp_manage.php account:list TENANT_SLUG\n";
    echo "  php bin/whatsapp_manage.php account:status PHONE_NUMBER_ID active|suspended\n";
    echo "  php bin/whatsapp_manage.php account:token:rotate PHONE_NUMBER_ID\n";
    echo "  php bin/whatsapp_manage.php key:add TENANT_SLUG LABEL [PHONE_NUMBER_ID]\n";
    echo "  php bin/whatsapp_manage.php key:revoke KEY_ID\n";
    echo "  php bin/whatsapp_manage.php callback:set PHONE_NUMBER_ID HTTPS_URL\n";
    exit(1);
}
function secretFromStdin(string $prompt): string {
    fwrite(STDOUT,$prompt . ' (stdin, nao use argumentos do shell): ');
    $s=trim((string)fgets(STDIN));
    if (strlen($s)<16) throw new RuntimeException('Segredo demasiado curto');
    return $s;
}
function requiredTenant(string $slug): array {
    $a=tenantBySlug($slug);
    if (!$a) throw new RuntimeException('Cliente inexistente: '.$slug);
    return $a;
}
function requiredAccount(string $phone): array {
    $a=accountByPhone($phone);
    if (!$a) {
        // Inclui contas suspensas para tarefas administrativas.
        $st=db()->prepare('SELECT * FROM whatsapp_accounts WHERE phone_number_id=?');
        $st->execute([$phone]);
        $a=$st->fetch();
    }
    if (!$a) throw new RuntimeException('Numero nao cadastrado');
    return $a;
}
try {
    $cmd=$argv[1]??'';
    if ($cmd==='tenant:add' && count($argv)===4) {
        $slug=$argv[2]; $name=trim($argv[3]);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/D',$slug) || strlen($name)<2 || strlen($name)>160)
            throw new RuntimeException('Slug ou nome invalido');
        $st=db()->prepare('INSERT INTO whatsapp_tenants(slug,name) VALUES (?,?)');
        $st->execute([$slug,$name]);
        echo "Cliente criado id=" . db()->lastInsertId() . "\n";
    } elseif ($cmd==='tenant:list' && count($argv)===2) {
        foreach(db()->query('SELECT id,slug,name,status,created_at FROM whatsapp_tenants ORDER BY id') as $row)
            echo jsonString($row) . "\n";
    } elseif ($cmd==='tenant:status' && count($argv)===4) {
        if (!in_array($argv[3],['active','suspended'],true)) throw new RuntimeException('Status invalido');
        $st=db()->prepare('UPDATE whatsapp_tenants SET status=? WHERE slug=?');
        $st->execute([$argv[3],$argv[2]]);
        if (!$st->rowCount()) throw new RuntimeException('Nao encontrado ou ja estava com este status');
        echo "Status de cliente alterado.\n";
    } elseif ($cmd==='account:add' && count($argv)===6) {
        $t=requiredTenant($argv[2]);
        $name=trim($argv[3]); $waba=$argv[4]; $phone=$argv[5];
        if (!validId($waba)||!validId($phone)||strlen($name)<2||strlen($name)>120)
            throw new RuntimeException('Nome, WABA ou numero invalido');
        $token=secretFromStdin('Token Meta do usuario de sistema');
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT tenant_id FROM whatsapp_wabas WHERE waba_id=? FOR UPDATE');
            $s->execute([$waba]);
            $owner=$s->fetch();
            if ($owner && (int)$owner['tenant_id']!==(int)$t['id'])
                throw new RuntimeException('Este WABA ja pertence a outro cliente');
            if (!$owner) {
                $s=$pdo->prepare('INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)');
                $s->execute([$t['id'],$waba]);
            }
            $s=$pdo->prepare('INSERT INTO whatsapp_accounts(tenant_id,name,waba_id,phone_number_id,token_box)
                              VALUES (?,?,?,?,?)');
            $s->execute([$t['id'],$name,$waba,$phone,seal($token)]);
            $pdo->commit();
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
        echo "Numero vinculado ao cliente ".$t['slug'].".\n";
    } elseif ($cmd==='account:list' && count($argv)===3) {
        $t=requiredTenant($argv[2]);
        $st=db()->prepare('SELECT id,tenant_id,name,waba_id,phone_number_id,status,created_at
                           FROM whatsapp_accounts WHERE tenant_id=? ORDER BY id');
        $st->execute([$t['id']]);
        foreach($st as $row) echo jsonString($row)."\n";
    } elseif ($cmd==='account:status' && count($argv)===4) {
        if (!in_array($argv[3],['active','suspended'],true)) throw new RuntimeException('Status invalido');
        $st=db()->prepare('UPDATE whatsapp_accounts SET status=? WHERE phone_number_id=?');
        $st->execute([$argv[3],$argv[2]]);
        if(!$st->rowCount())throw new RuntimeException('Numero nao encontrado ou sem mudanca');
        echo "Status do numero alterado.\n";
    } elseif ($cmd==='account:token:rotate' && count($argv)===3) {
        requiredAccount($argv[2]);
        $token=secretFromStdin('Novo token Meta');
        $st=db()->prepare('UPDATE whatsapp_accounts SET token_box=? WHERE phone_number_id=?');
        $st->execute([seal($token),$argv[2]]);
        echo "Token trocado.\n";
    } elseif ($cmd==='key:add' && (count($argv)===4 || count($argv)===5)) {
        $t=requiredTenant($argv[2]);
        $accountId=null;
        if(count($argv)===5) {
            $a=requiredAccount($argv[4]);
            if((int)$a['tenant_id']!==(int)$t['id'])throw new RuntimeException('Numero pertence a outro cliente');
            $accountId=(int)$a['id'];
        }
        $label=trim($argv[3]);
        if(strlen($label)<2||strlen($label)>120)throw new RuntimeException('Label invalido');
        $key='wg_'.bin2hex(random_bytes(32));
        $st=db()->prepare('INSERT INTO whatsapp_api_keys(tenant_id,account_id,name,token_hash) VALUES (?,?,?,?)');
        $st->execute([$t['id'],$accountId,$label,hash('sha256',$key)]);
        echo "API key (mostrada UMA VEZ): ".$key."\n";
    } elseif ($cmd==='key:revoke' && count($argv)===3 && ctype_digit($argv[2])) {
        $st=db()->prepare('UPDATE whatsapp_api_keys SET active=0 WHERE id=?');
        $st->execute([$argv[2]]);
        echo "Revogadas: ".$st->rowCount()."\n";
    } elseif ($cmd==='callback:set' && count($argv)===4) {
        $a=requiredAccount($argv[2]);
        $url=$argv[3];$p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||!empty($p['user'])||!empty($p['pass'])||
           filter_var($p['host'],FILTER_VALIDATE_IP)||strtolower($p['host'])==='localhost')
            throw new RuntimeException('E exigido HTTPS com hostname publico');
        $secret=secretFromStdin('Segredo HMAC do callback');
        $st=db()->prepare('UPDATE whatsapp_accounts SET callback_url=?,callback_secret_box=?
                           WHERE id=? AND tenant_id=?');
        $st->execute([$url,seal($secret),$a['id'],$a['tenant_id']]);
        echo "Callback configurado.\n";
    } else usage();
} catch(Throwable $e) {
    fwrite(STDERR,'ERRO: '.$e->getMessage()."\n");
    exit(1);
}
