<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='test')exit(1);
require dirname(__DIR__).'/src/whatsapp_Mobile.php';
function ensureMobile(bool $condition,string $name): void {
    if(!$condition)throw new RuntimeException('Mobile test failed: '.$name);
    echo "OK: $name\n";
}
// Criacao idempotente e sem alteracao nas tabelas legadas.
$schema=file_get_contents(dirname(__DIR__).'/database/whatsapp_mobile.sql');
preg_match_all('/CREATE TABLE IF NOT EXISTS whatsapp_[a-z_]+\s*\(.*?\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',$schema,$m);
ensureMobile(count($m[0])===2,'mobile schema includes two prefixed tables');
foreach($m[0] as $ddl)db()->exec($ddl);
foreach($m[0] as $ddl)db()->exec($ddl);
ensureMobile((int)db()->query("SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema=DATABASE() AND table_name LIKE 'whatsapp_mobile_%'")->fetchColumn()===2,
    'mobile migrations idempotent');
ensureMobile(WhatsAppMobile::countVariables('Olá {{1}} {{2}} {{1}}')===2,'template variables unique');
ensureMobile(WhatsAppMobile::countVariables('Sem variáveis')===0,'template without variables');
echo "All mobile smoke tests passed\n";
