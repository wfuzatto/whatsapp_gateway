<?php
declare(strict_types=1);

$testDir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'whatsapp_meta_config_'.bin2hex(random_bytes(6));
if(!mkdir($testDir,0700,true))throw new RuntimeException('Não foi possível preparar o diretório temporário.');
putenv('APP_ENV=test');
putenv('WHATSAPP_INSTALL_TEST_DIR='.$testDir);
require dirname(__DIR__).'/src/whatsapp_Installer.php';

$envPath=$testDir.DIRECTORY_SEPARATOR.'.env';
$mutexPath=$testDir.DIRECTORY_SEPARATOR.'whatsapp_meta_env_mutex.lock';
file_put_contents($envPath,"DB_PASSWORD=preservar\nMETA_APP_ID=antigo\nMETA_APP_SECRET=antigo\nMETA_VERIFY_TOKEN=antigo\n");
$secrets=[
    'META_APP_ID'=>'1683987243242501',
    'META_APP_SECRET'=>str_repeat('a',32),
    'META_VERIFY_TOKEN'=>'zapapi2026*'
];
$method=(new ReflectionClass(WhatsAppInstaller::class))->getMethod('updatePrivateEnv');
$method->setAccessible(true);
try {
    $method->invoke(null,$secrets);
    $saved=(string)file_get_contents($envPath);
    foreach($secrets as $key=>$value)
        if(!str_contains($saved,$key.'='.$value))throw new RuntimeException('A configuração Meta não foi persistida.');
    if(!str_contains($saved,'DB_PASSWORD=preservar'))throw new RuntimeException('Outra configuração .env foi alterada.');
    if(getenv('META_VERIFY_TOKEN')!=='zapapi2026*')throw new RuntimeException('O processo não recebeu o Verify Token atualizado.');
    echo "Meta app configuration persistence passed\n";
} finally {
    foreach(glob($testDir.DIRECTORY_SEPARATOR.'*')?:[] as $file)if(is_file($file))@unlink($file);
    @unlink($envPath);
    @rmdir($testDir);
}
