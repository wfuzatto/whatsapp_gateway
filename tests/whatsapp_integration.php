<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='test') exit("Integration test requires APP_ENV=test\n");
require dirname(__DIR__).'/src/whatsapp_bootstrap.php';
require dirname(__DIR__).'/src/whatsapp_Webhook.php';
require dirname(__DIR__).'/src/whatsapp_Jobs.php';
putenv('MASTER_KEY_BASE64='.base64_encode(str_repeat('K',32)));
putenv('META_APP_SECRET=integration-test-secret');
function ok(bool $v,string $name): void {
    if(!$v)throw new RuntimeException('Failed: '.$name);
    echo "OK: $name\n";
}
function denied(callable $fn,string $name): void {
    try{$fn();}catch(HttpError $e){ok($e->httpStatus>=400,$name);return;}
    throw new RuntimeException('Expected deny: '.$name);
}
$pdo=db();
$schema=file_get_contents(dirname(__DIR__).'/database/whatsapp_schema.sql');
preg_match_all('/CREATE TABLE whatsapp_[a-z_]+.*?ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',$schema,$matches);
ok(count($matches[0])===9,'all tables named whatsapp_*');
foreach($matches[0] as $sql)$pdo->exec($sql);
$seedAt=strpos($schema,'INSERT INTO whatsapp_plans(');
if($seedAt===false) throw new RuntimeException('Missing plan seed data');
$pdo->exec(substr($schema,$seedAt));
ok((int)$pdo->query("SELECT COUNT(*) FROM whatsapp_plans")->fetchColumn()===3,'three plans seeded');
$pdo->exec("INSERT INTO whatsapp_tenants(slug,name) VALUES ('cliente-a','Cliente A'),('cliente-b','Cliente B')");
$a=(int)tenantBySlug('cliente-a')['id'];$b=(int)tenantBySlug('cliente-b')['id'];
$pdo->prepare('INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)')->execute([$a,'111111111']);
$pdo->prepare('INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)')->execute([$b,'222222222']);
$ins=$pdo->prepare('INSERT INTO whatsapp_accounts(tenant_id,name,waba_id,phone_number_id,token_box) VALUES (?,?,?,?,?)');
$ins->execute([$a,'A1','111111111','100000001',seal('token-test-long-a')]);$a1=(int)$pdo->lastInsertId();
$ins->execute([$a,'A2','111111111','100000002',seal('token-test-long-a')]);$a2=(int)$pdo->lastInsertId();
$ins->execute([$b,'B1','222222222','200000001',seal('token-test-long-b')]);$b1=(int)$pdo->lastInsertId();
try{$pdo->prepare('INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)')->execute([$b,'111111111']);
    throw new RuntimeException('Duplicate WABA allowed');}
catch(PDOException $e){ok($e->getCode()==='23000','WABA unique across tenants');}
$keyA='wg_'.str_repeat('a',64);$keyB='wg_'.str_repeat('b',64);$keyA1='wg_'.str_repeat('c',64);
$ins=$pdo->prepare('INSERT INTO whatsapp_api_keys(tenant_id,account_id,name,token_hash) VALUES (?,?,?,?)');
$ins->execute([$a,null,'tenant-A',hash('sha256',$keyA)]);
$ins->execute([$b,null,'tenant-B',hash('sha256',$keyB)]);
$ins->execute([$a,$a1,'phone-A1',hash('sha256',$keyA1)]);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$keyA;
$ctx=bearerContext();
ok((int)$ctx['tenant_id']===$a,'tenant authentication');
denied(fn()=>selectedAccount($ctx,null),'multiple numbers require header');
ok((int)selectedAccount($ctx,'100000001')['id']===$a1,'select first own number');
ok((int)selectedAccount($ctx,'100000002')['id']===$a2,'select second own number');
denied(fn()=>selectedAccount($ctx,'200000001'),'cannot select other tenant number');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$keyA1;
$c=bearerContext();
ok((int)selectedAccount($c,null)['id']===$a1,'account-specific key');
denied(fn()=>selectedAccount($c,'100000002'),'account-specific key cannot access sibling');
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$keyB;
$c=bearerContext();
ok((int)selectedAccount($c,null)['id']===$b1,'other tenant independent');
denied(fn()=>selectedAccount($c,'100000001'),'other tenant denied');
$pdo->prepare("UPDATE whatsapp_tenants SET status='suspended' WHERE id=?")->execute([$a]);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$keyA;
denied(fn()=>bearerContext(),'suspended tenant denied');
$pdo->prepare("UPDATE whatsapp_tenants SET status='active' WHERE id=?")->execute([$a]);
$pdo->prepare("UPDATE whatsapp_accounts SET status='suspended' WHERE id=?")->execute([$a1]);
$_SERVER['HTTP_AUTHORIZATION']='Bearer '.$keyA1;
denied(fn()=>bearerContext(),'suspended number API key denied');
$pdo->prepare("UPDATE whatsapp_accounts SET status='active' WHERE id=?")->execute([$a1]);

$event=['object'=>'whatsapp_business_account','entry'=>[[
    'id'=>'111111111','changes'=>[[
      'field'=>'messages','value'=>[
        'metadata'=>['phone_number_id'=>'100000001'],
        'messages'=>[['id'=>'wamid.test1','from'=>'5511999999999',
           'timestamp'=>(string)time(),'type'=>'text','text'=>['body'=>'ola']]]
      ]
    ]]
]]];
$raw=jsonString($event);
$sig='sha256='.hash_hmac('sha256',$raw,'integration-test-secret');
ok(Webhook::receive($raw,$sig)['events_saved']===1,'signed webhook ingested');
ok(Webhook::receive($raw,$sig)['events_saved']===0,'duplicate webhook ignored');
$s=$pdo->prepare('SELECT tenant_id,account_id FROM whatsapp_messages WHERE whatsapp_id=?');
$s->execute(['wamid.test1']);$row=$s->fetch();
ok((int)$row['tenant_id']===$a&&(int)$row['account_id']===$a1,'inbound message tenant scoped');
try{$pdo->prepare('INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id) VALUES (?,?,?)')
    ->execute([$b,$a1,'5511988877766']);throw new RuntimeException('Cross-tenant write allowed');}
catch(PDOException $e){ok($e->getCode()==='23000','cross-tenant FK rejected');}
$job=Jobs::enqueueSend(accountByPhone('100000001'),
    ['to'=>'5511999999999','type'=>'text','text'=>['body'=>'Teste']],'test-tenant-job-01');
$s=$pdo->prepare('SELECT tenant_id,account_id FROM whatsapp_jobs WHERE id=?');
$s->execute([$job['job_id']]);$row=$s->fetch();
ok((int)$row['tenant_id']===$a&&(int)$row['account_id']===$a1,'job isolated');
echo "All multi-tenant integration tests passed\n";
