<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const CONFIG_FILE = APP_ROOT . '/storage/config.local.php';
if (is_file(CONFIG_FILE)) { $GLOBALS['cfg'] = require CONFIG_FILE; }
else { $GLOBALS['cfg'] = []; }
date_default_timezone_set('America/Sao_Paulo');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('wgate_session');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']),'samesite'=>'Lax','path'=>'/']);
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

function cfg(string $key, mixed $default=null): mixed { return $GLOBALS['cfg'][$key] ?? $default; }
function installed(): bool { return !empty(cfg('installed')); }
function app_base_url(): string { $configured=rtrim((string)cfg('app_url'),'/');if($configured)return $configured;$host=(string)($_SERVER['HTTP_HOST']??'');if(!$host)return '';$scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';$path=str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/')));return $scheme.'://'.$host.($path==='/'||$path==='.'?'':rtrim($path,'/')); }
function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(string $message, string $type='success'): void { $_SESSION['flash']=['message'=>$message,'type'=>$type]; }
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $d=cfg('database',[]);
    if (!$d) throw new RuntimeException('Banco ainda não configurado.');
    $pdo = new PDO('mysql:host='.$d['host'].';port='.$d['port'].';dbname='.$d['name'].';charset=utf8mb4', $d['user'], $d['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); $pdo->exec("SET time_zone = '".date('P')."'");
    return $pdo;
}
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(): void { if (!hash_equals(csrf_token(), (string)($_POST['_csrf'] ?? ''))) { http_response_code(419); exit('Sessão expirada. Atualize a página.'); } }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_admin(): void { if (!current_user() || !in_array(current_user()['role'],['platform_admin','owner','admin'],true)) { http_response_code(403); exit('Acesso restrito ao administrador.'); } }
function tenant_id(): int { return (int)(current_user()['tenant_id'] ?? 0); }
function q(string $sql, array $params=[]): PDOStatement { $s=db()->prepare($sql); $s->execute($params); return $s; }
function json_response(array $data, int $status=200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function audit(string $action, array $detail=[]): void { if (!installed() || !current_user()) return; q('INSERT INTO whatsapp_gateway_audit_logs (tenant_id,user_id,action,details,ip,created_at) VALUES (?,?,?,?,?,NOW())',[tenant_id(),current_user()['id'],$action,json_encode($detail,JSON_UNESCAPED_UNICODE),$_SERVER['REMOTE_ADDR']??'']); }
function encrypt_secret(string $value): string { $key=hash('sha256',(string)cfg('app_key'),true); $iv=random_bytes(12); $tag=''; $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag); if ($cipher===false) throw new RuntimeException('Falha ao proteger credencial.'); return base64_encode($iv.$tag.$cipher); }
function decrypt_secret(string $value): string { $raw=base64_decode($value,true); if ($raw===false || strlen($raw)<29) return ''; $key=hash('sha256',(string)cfg('app_key'),true); return (string)openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16)); }
function base32_encode(string $data): string { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$buffer=0;$bits=0;$out='';for($i=0,$n=strlen($data);$i<$n;$i++){$buffer=($buffer<<8)|ord($data[$i]);$bits+=8;while($bits>=5){$bits-=5;$out.=$alphabet[($buffer>>$bits)&31];$buffer&=(1<<$bits)-1;}}if($bits>0)$out.=$alphabet[($buffer<<(5-$bits))&31];return $out; }
function base32_decode(string $text): string { $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$text=strtoupper(preg_replace('/[^A-Z2-7]/i','',$text));$buffer=0;$bits=0;$out='';for($i=0,$n=strlen($text);$i<$n;$i++){$pos=strpos($alphabet,$text[$i]);if($pos===false)continue;$buffer=($buffer<<5)|$pos;$bits+=5;if($bits>=8){$bits-=8;$out.=chr(($buffer>>$bits)&255);$buffer&=(1<<$bits)-1;}}return $out; }
function totp_code(string $secret,?int $time=null): string { $time??=time();$counter=intdiv($time,30);$key=base32_decode($secret);$msg=pack('N2',intdiv($counter,4294967296),$counter&0xffffffff);$hash=hash_hmac('sha1',$msg,$key,true);$offset=ord($hash[19])&15;$bin=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255);return str_pad((string)($bin%1000000),6,'0',STR_PAD_LEFT); }
function totp_valid(string $secret,string $code): bool { $code=preg_replace('/\D+/','',$code);if(strlen($code)!==6)return false;for($i=-1;$i<=1;$i++)if(hash_equals(totp_code($secret,time()+($i*30)),$code))return true;return false; }
function meta_config(int $tid): array { $r=q('SELECT * FROM tenant_meta WHERE tenant_id=?',[$tid])->fetch(); if (!$r) return []; $r['access_token']=$r['access_token_enc']?decrypt_secret($r['access_token_enc']):''; $r['app_secret']=$r['app_secret_enc']?decrypt_secret($r['app_secret_enc']):''; return $r; }
function meta_call(int $tid,string $method,string $path,?array $body=null): array {
    $m=meta_config($tid); if (empty($m['access_token'])) throw new RuntimeException('Conecte um token de acesso válido na página Instalação Meta.');
    $version=(string)cfg('meta_graph_version','v26.0'); $url='https://graph.facebook.com/'.$version.'/'.ltrim($path,'/');
    $ch=curl_init($url); $headers=['Authorization: Bearer '.$m['access_token'],'Content-Type: application/json'];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    $raw=curl_exec($ch); $err=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($raw===false) throw new RuntimeException('Não foi possível acessar a Meta: '.$err);
    $data=json_decode((string)$raw,true); if (!is_array($data)) $data=['raw'=>$raw];
    if ($status>=400) throw new RuntimeException('Meta API ('.$status.'): '.($data['error']['message']??'erro não especificado'));
    return $data;
}
function meta_upload(int $tid,string $phoneNumberId,array $file): array {
    if(!extension_loaded('curl')||!class_exists('CURLFile')||!class_exists('finfo'))throw new RuntimeException('Ative curl e fileinfo no PHP do XAMPP para enviar anexos.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($file['tmp_name'])||!is_uploaded_file((string)$file['tmp_name']))throw new RuntimeException('Não foi possível receber o arquivo enviado.');
    $size=(int)($file['size']??0);if($size<1||$size>16*1024*1024)throw new RuntimeException('O anexo deve ter até 16 MB.');
    $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file((string)$file['tmp_name']);
    $limits=['image/jpeg'=>5*1024*1024,'image/png'=>5*1024*1024,'video/mp4'=>16*1024*1024,'audio/aac'=>16*1024*1024,'audio/amr'=>16*1024*1024,'audio/mpeg'=>16*1024*1024,'audio/mp4'=>16*1024*1024,'audio/ogg'=>16*1024*1024,'application/pdf'=>16*1024*1024];
    if(!isset($limits[$mime]))throw new RuntimeException('Formato não suportado. Use imagem JPEG/PNG, vídeo MP4, áudio compatível ou documento PDF.');
    if($size>$limits[$mime])throw new RuntimeException('Este tipo de anexo excede o limite permitido pela aplicação.');
    $kind=str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'video/')?'video':(str_starts_with($mime,'audio/')?'audio':'document'));
    $safeName=basename(str_replace('\\','/',(string)($file['name']??'arquivo')));$safeName=preg_replace('/[^\pL\pN._ -]+/u','_',$safeName)?:'arquivo';
    $meta=meta_config($tid);if(empty($meta['access_token']))throw new RuntimeException('Configure o token de acesso da Meta antes de enviar anexos.');
    $version=(string)cfg('meta_graph_version','v26.0');$url='https://graph.facebook.com/'.$version.'/'.rawurlencode($phoneNumberId).'/media';
    $fields=['messaging_product'=>'whatsapp','type'=>$mime,'file'=>new CURLFile((string)$file['tmp_name'],$mime,$safeName)];$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$meta['access_token']],CURLOPT_POSTFIELDS=>$fields,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60]);
    $raw=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if($raw===false)throw new RuntimeException('Não foi possível enviar o anexo à Meta: '.$error);$data=json_decode((string)$raw,true);
    if($status>=400||!is_array($data)||empty($data['id']))throw new RuntimeException('Meta API ('.$status.'): '.($data['error']['message']??'a Meta não retornou o identificador da mídia.'));
    return ['id'=>(string)$data['id'],'mime'=>$mime,'kind'=>$kind,'filename'=>$safeName,'caption_supported'=>in_array($kind,['image','video','document'],true)];
}
function store_media_asset(int $tid,string $phoneNumberId,array $media): int {
    q('INSERT INTO media_assets(tenant_id,phone_number_id,meta_media_id,mime_type,media_type,filename,created_at) VALUES(?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE phone_number_id=VALUES(phone_number_id),mime_type=VALUES(mime_type),media_type=VALUES(media_type),filename=VALUES(filename)',[$tid,$phoneNumberId,$media['id'],$media['mime'],$media['kind'],$media['filename']]);
    return (int)q('SELECT id FROM media_assets WHERE tenant_id=? AND meta_media_id=?',[$tid,$media['id']])->fetchColumn();
}
function safe_https_url(string $url): bool {
    $p=parse_url($url); if(!$p||strtolower($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])) return false;
    $host=strtolower(rtrim((string)$p['host'],'.')); if($host==='localhost'||str_ends_with($host,'.local')||str_ends_with($host,'.localhost')) return false;
    if(filter_var($host,FILTER_VALIDATE_IP)) return (bool)filter_var($host,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE);
    $records=@dns_get_record($host,DNS_A|DNS_AAAA); if(!$records) return false; $found=false;
    foreach($records as $record){$ip=$record['ip']??$record['ipv6']??null;if(!$ip)continue;$found=true;if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;}
    return $found;
}
function queue_tenant_event(int $tid,string $type,array $payload): void {
    $sub=q('SELECT id,events,active FROM tenant_webhooks WHERE tenant_id=?',[$tid])->fetch(); if(!$sub||!(int)$sub['active'])return;
    $events=array_map('trim',explode(',',(string)$sub['events'])); if(!in_array('*',$events,true)&&!in_array($type,$events,true))return;
    q('INSERT INTO webhook_outbox(tenant_id,event_key,event_type,payload,status,created_at,updated_at) VALUES(?,?,?,?,\'pending\',NOW(),NOW())',[$tid,bin2hex(random_bytes(16)),$type,json_encode(['id'=>'evt_'.bin2hex(random_bytes(12)),'type'=>$type,'created_at'=>gmdate('c'),'data'=>$payload],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
}
function persist_app_config(array $config): void { $written=file_put_contents(CONFIG_FILE,"<?php\nreturn ".var_export($config,true).";\n",LOCK_EX);if($written===false)throw new RuntimeException('Não foi possível atualizar storage/config.local.php. Verifique a permissão da pasta storage.');$GLOBALS['cfg']=$config; }
function installed_tenant(): int { return tenant_id(); }
function render_install(): void {
    $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try {
            csrf_check();
            $requiredExtensions=['pdo_mysql','openssl','curl','mbstring','iconv','fileinfo','json'];$missingExtensions=array_values(array_filter($requiredExtensions,fn($extension)=>!extension_loaded($extension)));if($missingExtensions)throw new RuntimeException('Ative estas extensões PHP no XAMPP e reinicie o Apache: '.implode(', ',$missingExtensions).'.');
            $host=trim((string)($_POST['db_host']??'127.0.0.1')); $port=(int)($_POST['db_port']??3306); $name=trim((string)($_POST['db_name']??'')); $user=trim((string)($_POST['db_user']??'root')); $pass=(string)($_POST['db_password']??'');
            if (!preg_match('/^[a-zA-Z0-9_]+$/',$name)) throw new RuntimeException('Nome do banco inválido. Use letras, números e sublinhado. Crie o banco no phpMyAdmin antes de continuar.');
            $pdo=new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->exec("SET time_zone = '".date('P')."'");
            $key=bin2hex(random_bytes(32)); $admin=trim((string)($_POST['admin_name']??'')); $email=strtolower(trim((string)($_POST['admin_email']??''))); $pwd=(string)($_POST['admin_password']??''); $org=trim((string)($_POST['company_name']??''));
            if (!$admin || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($pwd)<12 || !$org) throw new RuntimeException('Informe empresa, nome, e-mail e uma senha de administrador com ao menos 12 caracteres.');
            $config=['installed'=>true,'app_key'=>$key,'app_url'=>rtrim((string)($_POST['app_url']??''),'/'),'meta_graph_version'=>'v26.0','database'=>['host'=>$host,'port'=>$port,'name'=>$name,'user'=>$user,'password'=>$pass]];
            $configPath=APP_ROOT.'/storage/config.local.php';
            if (is_file($configPath)) throw new RuntimeException('A instalação já foi iniciada. Apague storage/config.local.php para recomeçar.');
            $export="<?php\nreturn ".var_export($config,true).";\n";
            if (file_put_contents($configPath,$export,LOCK_EX)===false) throw new RuntimeException('Não consegui gravar storage/config.local.php. Verifique permissão de escrita da pasta storage.');
            $GLOBALS['cfg']=$config;
            $schema=file_get_contents(APP_ROOT.'/database/schema.sql');
            foreach (array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema))) as $sql) $pdo->exec($sql);
            $pdo->prepare('INSERT INTO tenants(name,slug,status,created_at) VALUES(?,?,?,NOW())')->execute([$org,substr(bin2hex(random_bytes(8)),0,12),'active']); $tid=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO users(tenant_id,name,email,password_hash,role,status,created_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$tid,$admin,$email,password_hash($pwd,PASSWORD_DEFAULT),'platform_admin','active']);
            $pdo->prepare('INSERT INTO tenant_meta(tenant_id,updated_at) VALUES(?,NOW())')->execute([$tid]);
            flash('Instalação concluída. Entre com o administrador que você criou.'); redirect('?r=login');
        } catch (Throwable $e) { $error=$e->getMessage(); }
    }
    $title='Instalação inicial'; include APP_ROOT.'/app/views/install.php';
}
function handle_login(): void {
    $error='';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        csrf_check();$email=strtolower(trim((string)($_POST['email']??'')));$ip=(string)($_SERVER['REMOTE_ADDR']??'');
        $attempts=(int)q('SELECT COUNT(*) FROM login_attempts WHERE email=? AND ip=? AND created_at>=DATE_SUB(NOW(),INTERVAL 15 MINUTE)',[$email,$ip])->fetchColumn();
        if($attempts>=6){$error='Muitas tentativas. Aguarde 15 minutos e tente novamente.';}
        else{
            $row=q("SELECT u.*,t.name tenant_name FROM users u JOIN tenants t ON t.id=u.tenant_id WHERE u.email=? AND u.status='active' AND t.status='active' LIMIT 1",[$email])->fetch();
            $valid=$row&&password_verify((string)($_POST['password']??''),$row['password_hash']);
            if($valid&&(int)$row['mfa_enabled']===1)$valid=totp_valid(decrypt_secret((string)$row['mfa_secret_enc']),(string)($_POST['mfa_code']??''));
            if($valid){q('DELETE FROM login_attempts WHERE email=? AND ip=?',[$email,$ip]);session_regenerate_id(true);unset($row['password_hash'],$row['mfa_secret_enc']);$_SESSION['user']=$row;audit('login');redirect('?r=dashboard');}
            q('INSERT INTO login_attempts(email,ip,created_at) VALUES(?,?,NOW())',[$email,$ip]);$error='E-mail, senha ou código autenticador inválido.';
        }
    }
    $title='Entrar';include APP_ROOT.'/app/views/login.php';
}function handle_action(): void {
    $r=(string)($_GET['r']??'dashboard');
    if ($_SERVER['REQUEST_METHOD']==='POST') { csrf_check(); $a=(string)($_POST['action']??'');
        try {
            if ($a==='save_runtime_config') { require_admin(); $base=rtrim(trim((string)($_POST['app_url']??'')),'/');$parts=parse_url($base);$version=trim((string)($_POST['meta_graph_version']??''));if(!$parts||!in_array(strtolower($parts['scheme']??''),['http','https'],true)||empty($parts['host']))throw new RuntimeException('Informe um endereço completo começando com http:// ou https://.');if(!preg_match('/^v\d+\.\d+$/',$version))throw new RuntimeException('A versão Graph API deve estar no formato vNN.N.');$config=$GLOBALS['cfg'];$config['app_url']=$base;$config['meta_graph_version']=$version;persist_app_config($config);flash('Endereço público e versão Graph API salvos.');audit('runtime_config_updated',['app_url'=>$base,'graph_version'=>$version]);
            } elseif ($a==='save_meta') { require_admin(); $token=trim((string)($_POST['access_token']??'')); $waba=trim((string)($_POST['waba_id']??'')); $phone=trim((string)($_POST['phone_number_id']??'')); $verify=trim((string)($_POST['verify_token']??'')); $appsecret=trim((string)($_POST['app_secret']??'')); $metaApp=trim((string)($_POST['meta_app_id']??'')); $row=q('SELECT access_token_enc,app_secret_enc FROM tenant_meta WHERE tenant_id=?',[tenant_id()])->fetch();
                $enc=$token!==''?encrypt_secret($token):($row['access_token_enc']??null); $sec=$appsecret!==''?encrypt_secret($appsecret):($row['app_secret_enc']??null);
                q('UPDATE tenant_meta SET waba_id=?,phone_number_id=?,verify_token=?,access_token_enc=?,app_secret_enc=?,meta_app_id=?,updated_at=NOW() WHERE tenant_id=?',[$waba,$phone,$verify,$enc,$sec,$metaApp,tenant_id()]); audit('meta_config_updated',['waba_id'=>$waba,'phone_number_id'=>$phone]); flash('Configuração da Meta salva com proteção de credenciais.');
            } elseif ($a==='test_meta') { require_admin(); $m=meta_config(tenant_id()); if (!$m['phone_number_id']) throw new RuntimeException('Informe o Phone Number ID.'); $res=meta_call(tenant_id(),'GET',$m['phone_number_id'].'?fields=display_phone_number,verified_name,quality_rating'); flash('Conexão validada: '.($res['display_phone_number']??$res['verified_name']??'API respondeu com sucesso.')); audit('meta_connection_tested');
            } elseif ($a==='save_webhook') { require_admin(); $url=trim((string)$_POST['url']); $eventInput=$_POST['events']??[]; $eventsArr=is_array($eventInput)?$eventInput:explode(',',(string)$eventInput); if(!safe_https_url($url)) throw new RuntimeException('Informe uma URL HTTPS pública válida. Endereços locais e redes privadas não são permitidos.'); $old=q('SELECT secret_enc FROM tenant_webhooks WHERE tenant_id=?',[tenant_id()])->fetch(); $secret=trim((string)($_POST['secret']??'')); if(!$secret)$secret=$old?decrypt_secret($old['secret_enc']):'whsec_'.bin2hex(random_bytes(24)); if(strlen($secret)<24) throw new RuntimeException('A chave de assinatura deve ter pelo menos 24 caracteres.'); $allowed=['message.received','message.sent','message.status','*']; $eventsArr=array_values(array_intersect($allowed,array_map('trim',$eventsArr))); if(!$eventsArr) throw new RuntimeException('Selecione ao menos um evento válido.'); q('INSERT INTO tenant_webhooks(tenant_id,url,secret_enc,events,active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE url=VALUES(url),secret_enc=VALUES(secret_enc),events=VALUES(events),active=1,updated_at=NOW()',[tenant_id(),$url,encrypt_secret($secret),implode(',',$eventsArr)]); $_SESSION['new_hook_secret']=$secret; flash('Webhook do cliente configurado. Copie a chave de assinatura exibida acima.'); audit('client_webhook_configured',['url'=>$url,'events'=>$eventsArr]);
            } elseif ($a==='save_verify') { require_admin(); $name=trim((string)$_POST['name']); $phone=preg_replace('/\D+/','',(string)$_POST['phone']); if (strlen($phone)<8) throw new RuntimeException('Informe telefone com código do país.'); $consent=!empty($_POST['consent']); $source=trim((string)($_POST['source']??'')); if($consent&&!$source) throw new RuntimeException('Descreva a origem e a evidência do consentimento antes de marcar como autorizado.'); q('INSERT INTO contacts(tenant_id,name,phone,consent_status,consent_source,consent_at,created_at) VALUES(?,?,?,?,?,?,NOW())', [tenant_id(),$name,$phone,$consent?'opted_in':'unknown',$source,$consent?date('Y-m-d H:i:s'):null]); flash('Contato cadastrado. Confira e registre o consentimento antes de campanhas.');
            } elseif ($a==='optout_contact') { require_admin(); $contactId=(int)$_POST['contact_id']; q("UPDATE contacts SET consent_status='opted_out',opted_out_at=NOW() WHERE id=? AND tenant_id=?",[$contactId,tenant_id()]); flash('Contato bloqueado para mensagens futuras.'); audit('contact_opted_out',['contact_id'=>$contactId]);
            } elseif ($a==='save_quick_reply') { require_admin(); $name=trim((string)($_POST['name']??''));$body=trim((string)($_POST['body']??''));if(!$name||mb_strlen($name)>120||!$body||mb_strlen($body)>4000)throw new RuntimeException('Informe um título e um texto de até 4.000 caracteres.');q('INSERT INTO quick_replies(tenant_id,name,body,created_at,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE body=VALUES(body),updated_at=NOW()',[tenant_id(),$name,$body,date('Y-m-d H:i:s')]);flash('Resposta rápida salva para esta empresa.');audit('quick_reply_saved',['name'=>$name]);
            } elseif ($a==='delete_quick_reply') { require_admin();$replyId=(int)($_POST['reply_id']??0);q('DELETE FROM quick_replies WHERE id=? AND tenant_id=?',[$replyId,tenant_id()]);flash('Resposta rápida removida.');audit('quick_reply_deleted',['reply_id'=>$replyId]);
            } elseif ($a==='save_template') { require_admin(); $name=strtolower(trim((string)$_POST['name'])); $lang=trim((string)($_POST['language']??'pt_BR')); $category=strtoupper(trim((string)$_POST['category'])); $body=trim((string)$_POST['body']); if (!preg_match('/^[a-z0-9_]{1,512}$/',$name)||!$body||!in_array($category,['MARKETING','UTILITY','AUTHENTICATION'],true)) throw new RuntimeException('Confira nome, categoria e conteúdo do template.'); $m=meta_config(tenant_id()); if (!$m['waba_id']) throw new RuntimeException('Conecte a WABA antes de enviar templates.'); preg_match_all('/\{\{\s*(\d+)\s*\}\}/',$body,$matches);$indices=array_values(array_unique(array_map('intval',$matches[1])));sort($indices);$varCount=count($indices);$components=[['type'=>'BODY','text'=>$body]];if($varCount){if($indices!==range(1,$varCount))throw new RuntimeException('Use variáveis sequenciais {{1}}, {{2}} e assim por diante.');$examples=preg_split('/\r\n|\r|\n/',trim((string)($_POST['examples']??'')));if(count($examples)!==$varCount||in_array('',array_map('trim',$examples),true))throw new RuntimeException('Informe um exemplo por variável, na mesma ordem.');$components[0]['example']=['body_text'=>[array_values($examples)]];} $remote=meta_call(tenant_id(),'POST',$m['waba_id'].'/message_templates',['name'=>$name,'language'=>$lang,'category'=>$category,'components'=>$components]); q('INSERT INTO templates(tenant_id,name,language,category,body,status,meta_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE body=VALUES(body),status=VALUES(status),meta_id=VALUES(meta_id),updated_at=NOW()', [tenant_id(),$name,$lang,$category,$body,$remote['status']??'PENDING',$remote['id']??null,date('Y-m-d H:i:s')]); flash('Template enviado à Meta para análise. Status: '.($remote['status']??'PENDING')); audit('template_submitted',['name'=>$name]);
            } elseif ($a==='sync_templates') { require_admin(); $m=meta_config(tenant_id()); if (!$m['waba_id']) throw new RuntimeException('Configure a WABA primeiro.'); $remote=meta_call(tenant_id(),'GET',$m['waba_id'].'/message_templates?fields=id,name,language,category,status,components&limit=100'); foreach(($remote['data']??[]) as $rt){ $body=''; foreach(($rt['components']??[]) as $component) if(($component['type']??'')==='BODY') $body=$component['text']??''; q('INSERT INTO templates(tenant_id,name,language,category,body,status,meta_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE category=VALUES(category),body=VALUES(body),status=VALUES(status),meta_id=VALUES(meta_id),updated_at=NOW()', [tenant_id(),$rt['name'],$rt['language'],$rt['category'],$body,$rt['status'],$rt['id'],date('Y-m-d H:i:s')]); } flash('Templates sincronizados com a Meta.'); audit('templates_synced',['count'=>count($remote['data']??[])]);
            } elseif ($a==='create_api_key') { require_admin(); $plain='wg_live_'.bin2hex(random_bytes(24)); q('INSERT INTO api_keys(tenant_id,name,key_hash,key_prefix,scopes,created_at) VALUES(?,?,?,?,?,NOW())',[tenant_id(),trim((string)$_POST['name'])?:'Integração',hash('sha256',$plain),substr($plain,0,14),'messages:write,messages:read,contacts:write']); $_SESSION['new_api_key']=$plain; flash('Chave criada. Copie agora; ela não será exibida novamente.'); audit('api_key_created');
            } elseif ($a==='revoke_api_key') { require_admin(); q('UPDATE api_keys SET revoked_at=NOW() WHERE id=? AND tenant_id=?',[(int)$_POST['key_id'],tenant_id()]); flash('Chave revogada.'); audit('api_key_revoked');
            } elseif ($a==='create_tenant') { if (current_user()['role']!=='platform_admin') throw new RuntimeException('Somente a administração da plataforma pode cadastrar clientes.'); $org=trim((string)$_POST['company_name']); $name=trim((string)$_POST['admin_name']); $email=strtolower(trim((string)$_POST['admin_email'])); $pwd=(string)$_POST['admin_password']; if (!$org||!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pwd)<12) throw new RuntimeException('Preencha empresa, nome, e-mail e senha com pelo menos 12 caracteres.'); $slug=strtolower(preg_replace('/[^a-z0-9]+/i','-',iconv('UTF-8','ASCII//TRANSLIT',$org)?:$org)).'-'.substr(bin2hex(random_bytes(3)),0,6); db()->beginTransaction(); try { q('INSERT INTO tenants(name,slug,status,created_at) VALUES(?,?,\'active\',NOW())',[$org,$slug]); $newTid=(int)db()->lastInsertId(); q('INSERT INTO users(tenant_id,name,email,password_hash,role,status,created_at) VALUES(?,?,?,?,\'owner\',\'active\',NOW())',[$newTid,$name,$email,password_hash($pwd,PASSWORD_DEFAULT)]); q('INSERT INTO tenant_meta(tenant_id,updated_at) VALUES(?,NOW())',[$newTid]); db()->commit(); } catch(Throwable $e) { db()->rollBack(); throw $e; } flash('Cliente e administrador cadastrados.'); audit('tenant_created',['tenant_id'=>$newTid]);
            } elseif ($a==='set_tenant_status') { if(current_user()['role']!=='platform_admin') throw new RuntimeException('Somente a administração da plataforma pode alterar clientes.'); $target=(int)$_POST['tenant_id']; $status=(string)$_POST['status']; if($target===tenant_id()||!in_array($status,['active','suspended'],true)) throw new RuntimeException('Não é permitido suspender a própria organização da plataforma.'); q('UPDATE tenants SET status=? WHERE id=?',[$status,$target]); flash($status==='active'?'Acesso da empresa reativado.':'Acesso da empresa suspenso.'); audit('tenant_status_changed',['tenant_id'=>$target,'status'=>$status]);
            } elseif ($a==='create_user') { require_admin(); $name=trim((string)$_POST['name']); $email=strtolower(trim((string)$_POST['email'])); $pwd=(string)$_POST['password']; $role=(string)$_POST['role']; if (!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pwd)<12||!in_array($role,['admin','supervisor','agent'],true)) throw new RuntimeException('Confira os dados do usuário e use uma senha com 12 caracteres ou mais.'); q('INSERT INTO users(tenant_id,name,email,password_hash,role,status,created_at) VALUES(?,?,?,?,?,\'active\',NOW())',[tenant_id(),$name,$email,password_hash($pwd,PASSWORD_DEFAULT),$role]); flash('Usuário adicionado à equipe.'); audit('user_created',['email'=>$email,'role'=>$role]);
            } elseif ($a==='mfa_begin') { require_admin(); $_SESSION['mfa_pending']=base32_encode(random_bytes(20)); flash('Escaneie ou digite o segredo no aplicativo autenticador e confirme o código.');
            } elseif ($a==='mfa_enable') { require_admin(); $secret=(string)($_SESSION['mfa_pending']??''); if(!$secret||!totp_valid($secret,(string)($_POST['mfa_code']??''))) throw new RuntimeException('Código inválido. Confira o relógio do celular e tente novamente.'); q('UPDATE users SET mfa_secret_enc=?,mfa_enabled=1 WHERE id=? AND tenant_id=?',[encrypt_secret($secret),current_user()['id'],tenant_id()]); $_SESSION['user']['mfa_enabled']=1; unset($_SESSION['mfa_pending']); flash('Autenticação em duas etapas ativada.'); audit('mfa_enabled');
            } elseif ($a==='mfa_disable') { require_admin(); if(current_user()['role']==='platform_admin') throw new RuntimeException('A conta de administração da plataforma deve manter a autenticação em duas etapas.'); $row=q('SELECT mfa_secret_enc FROM users WHERE id=? AND tenant_id=?',[current_user()['id'],tenant_id()])->fetch(); if(!$row||!totp_valid(decrypt_secret((string)$row['mfa_secret_enc']),(string)($_POST['mfa_code']??''))) throw new RuntimeException('Código autenticador inválido.'); q('UPDATE users SET mfa_secret_enc=NULL,mfa_enabled=0 WHERE id=? AND tenant_id=?',[current_user()['id'],tenant_id()]); $_SESSION['user']['mfa_enabled']=0; flash('Autenticação em duas etapas desativada.'); audit('mfa_disabled');
            } elseif ($a==='send_message') {
                $cid=(int)$_POST['conversation_id'];$conv=q('SELECT c.*,ct.phone,ct.consent_status FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.id=? AND c.tenant_id=?',[$cid,tenant_id()])->fetch();
                if(!$conv)throw new RuntimeException('Conversa não encontrada.');if($conv['consent_status']==='opted_out')throw new RuntimeException('Este contato pediu para não receber novas mensagens.');
                if(empty($conv['last_inbound_at'])||strtotime($conv['last_inbound_at'])<time()-86400)throw new RuntimeException('A janela de 24 horas terminou. Envie um template aprovado pela API.');
                $body=trim((string)($_POST['body']??''));$upload=$_FILES['attachment']??['error'=>UPLOAD_ERR_NO_FILE];$hasFile=($upload['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
                if(!$body&&!$hasFile)throw new RuntimeException('Digite uma mensagem ou escolha um anexo.');
                $meta=meta_config(tenant_id());$media=null;
                if($hasFile){$media=meta_upload(tenant_id(),(string)$meta['phone_number_id'],$upload);$media['asset_id']=store_media_asset(tenant_id(),(string)$meta['phone_number_id'],$media);if($body&&!$media['caption_supported'])throw new RuntimeException('Este tipo de áudio não aceita legenda; remova o texto ou escolha imagem, vídeo ou PDF.');
                    $mediaPayload=['id'=>$media['id']];if($body)$mediaPayload['caption']=$body;if($media['kind']==='document')$mediaPayload['filename']=$media['filename'];
                    $payload=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$conv['phone'],'type'=>$media['kind']];$payload[$media['kind']]=$mediaPayload;
                }else{$payload=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$conv['phone'],'type'=>'text','text'=>['preview_url'=>false,'body'=>$body]];}
                $res=meta_call(tenant_id(),'POST',$meta['phone_number_id'].'/messages',$payload);$wamid=$res['messages'][0]['id']??null;$type=$media['kind']??'text';$display=$body?:($media?'Anexo: '.$media['filename']:'');
                q('INSERT INTO messages(tenant_id,conversation_id,direction,type,body,wamid,media_id,media_filename,media_mime,status,created_at) VALUES(?,?,\'out\',?,?,?,?,?,?,\'accepted\',NOW())',[tenant_id(),$cid,$type,$display,$wamid,$media['id']??null,$media['filename']??null,$media['mime']??null]);
                q('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,updated_at=NOW() WHERE id=? AND tenant_id=?',[mb_substr($display,0,240),$cid,tenant_id()]);queue_tenant_event(tenant_id(),'message.sent',['message_id'=>$wamid,'phone'=>$conv['phone'],'text'=>$display,'type'=>$type,'asset_id'=>$media['asset_id']??null,'status'=>'accepted']);audit('message_sent',['conversation_id'=>$cid,'wamid'=>$wamid,'type'=>$type]);flash('Mensagem enviada para a API da Meta.');
            } elseif ($a==='assign_conversation') { $cid=(int)$_POST['conversation_id']; q('UPDATE conversations SET assignee_id=?,updated_at=NOW() WHERE id=? AND tenant_id=?',[(int)current_user()['id'],$cid,tenant_id()]); flash('Conversa atribuída a você.'); audit('conversation_assigned',['conversation_id'=>$cid]);
            } elseif ($a==='close_conversation') { $cid=(int)$_POST['conversation_id']; q('UPDATE conversations SET status=\'closed\',updated_at=NOW() WHERE id=? AND tenant_id=?',[$cid,tenant_id()]); flash('Conversa encerrada.'); audit('conversation_closed',['conversation_id'=>$cid]);
            } elseif ($a==='send_template') {
                $cid=(int)$_POST['conversation_id'];$templateId=(int)$_POST['template_id'];
                $conv=q('SELECT c.*,ct.phone,ct.consent_status FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.id=? AND c.tenant_id=?',[$cid,tenant_id()])->fetch();
                $tpl=q("SELECT * FROM templates WHERE id=? AND tenant_id=? AND status='APPROVED'",[$templateId,tenant_id()])->fetch();
                if(!$conv||!$tpl)throw new RuntimeException('Conversa ou template aprovado não encontrado.');
                if($conv['consent_status']!=='opted_in')throw new RuntimeException('Registre o consentimento do contato antes de iniciar uma conversa com template.');
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/',$tpl['body'],$matches);$indices=array_values(array_unique(array_map('intval',$matches[1])));sort($indices);$varCount=count($indices);if($varCount&&$indices!==range(1,$varCount))throw new RuntimeException('O template possui variáveis fora de sequência; ajuste-o no WhatsApp Manager e sincronize novamente.');
                $params=preg_split('/\r\n|\r|\n/',trim((string)($_POST['template_params']??'')));
                if($varCount&&(!$params||count($params)!==$varCount||in_array('',array_map('trim',$params),true)))throw new RuntimeException('Informe um valor para cada variável do template, um por linha.');
                $meta=meta_config(tenant_id());$templatePayload=['name'=>$tpl['name'],'language'=>['code'=>$tpl['language']]];
                if($varCount)$templatePayload['components']=[['type'=>'body','parameters'=>array_map(fn($value)=>['type'=>'text','text'=>trim($value)],$params)]];
                $payload=['messaging_product'=>'whatsapp','to'=>$conv['phone'],'type'=>'template','template'=>$templatePayload];
                $displayBody=preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/',fn($match)=>trim((string)($params[(int)$match[1]-1]??$match[0])),$tpl['body']);
                $response=meta_call(tenant_id(),'POST',$meta['phone_number_id'].'/messages',$payload);$wamid=$response['messages'][0]['id']??null;
                q("INSERT INTO messages(tenant_id,conversation_id,direction,type,body,wamid,status,created_at) VALUES(?,?,'out','template',?,?,'accepted',NOW())",[tenant_id(),$cid,'['.$tpl['name'].'] '.$displayBody,$wamid]);
                q('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,updated_at=NOW() WHERE id=? AND tenant_id=?',['['.$tpl['name'].'] '.mb_substr($displayBody,0,200),$cid,tenant_id()]);
                queue_tenant_event(tenant_id(),'message.sent',['message_id'=>$wamid,'phone'=>$conv['phone'],'text'=>$displayBody,'status'=>'accepted']);audit('template_message_sent',['conversation_id'=>$cid,'template'=>$tpl['name']]);flash('Template enviado à Meta.');
} elseif ($a==='create_campaign') { require_admin(); $name=trim((string)$_POST['name']); $tpl=(int)$_POST['template_id']; $approved=q('SELECT * FROM templates WHERE id=? AND tenant_id=? AND status=\'APPROVED\'',[$tpl,tenant_id()])->fetch(); if(!$name||!$approved) throw new RuntimeException('Informe o nome e escolha um template aprovado desta empresa.'); if(preg_match('/\{\{\s*\d+\s*\}\}/',$approved['body'])) throw new RuntimeException('Para usar campanhas, escolha um template sem variáveis numéricas.'); $schedule=trim((string)($_POST['scheduled_at']??'')); $scheduled=$schedule?date('Y-m-d H:i:s',strtotime($schedule)):null; if($schedule&&strtotime($schedule)===false) throw new RuntimeException('Data de agendamento inválida.'); q('INSERT INTO campaigns(tenant_id,name,template_id,status,scheduled_at,created_at) VALUES(?,?,?,\'draft\',?,NOW())',[tenant_id(),$name,$tpl,$scheduled]); flash('Campanha salva como rascunho. Revise e coloque na fila quando estiver pronta.');
            } elseif ($a==='enqueue_campaign') { require_admin(); $campaignId=(int)$_POST['campaign_id']; $campaign=q('SELECT c.*,t.status template_status,t.name template_name,t.body,t.language,t.category FROM campaigns c JOIN templates t ON t.id=c.template_id AND t.tenant_id=c.tenant_id WHERE c.id=? AND c.tenant_id=?',[$campaignId,tenant_id()])->fetch(); if(!$campaign||$campaign['template_status']!=='APPROVED') throw new RuntimeException('A campanha precisa usar um template aprovado da empresa.'); if(preg_match('/\{\{\s*\d+\s*\}\}/',$campaign['body'])) throw new RuntimeException('Templates com variáveis precisam de uma configuração de parâmetros por destinatário.'); if(!in_array($campaign['category'],['MARKETING','UTILITY','AUTHENTICATION'],true)) throw new RuntimeException('Categoria de template não elegível.'); q('INSERT IGNORE INTO campaign_recipients(tenant_id,campaign_id,contact_id,status,created_at,updated_at) SELECT tenant_id,?,id,\'pending\',NOW(),NOW() FROM contacts WHERE tenant_id=? AND consent_status=\'opted_in\' AND opted_out_at IS NULL',[$campaignId,tenant_id()]); $count=(int)q('SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id=? AND tenant_id=?',[$campaignId,tenant_id()])->fetchColumn(); if(!$count) throw new RuntimeException('Não há contatos com consentimento confirmado para esta campanha.'); q('UPDATE campaigns SET status=\'queued\' WHERE id=? AND tenant_id=?',[$campaignId,tenant_id()]); flash($count.' destinatários autorizados colocados na fila. Configure o worker PHP para processar os envios.'); audit('campaign_queued',['campaign_id'=>$campaignId,'recipients'=>$count]);
            } else { throw new RuntimeException('Ação desconhecida.'); }
        } catch (Throwable $ex) { flash($ex->getMessage(),'error'); }
        redirect('?r='.urlencode($r));
    }
}
function handle_api(string $route): void {
    $headers=function_exists('getallheaders')?getallheaders():[];
    $auth=(string)($_SERVER['HTTP_AUTHORIZATION']??$headers['Authorization']??$headers['authorization']??'');
    $key=preg_replace('/^Bearer\s+/i','',trim($auth));
    if (!$key) json_response(['error'=>'Authorization: Bearer <api_key> obrigatório'],401);
    $rec=q('SELECT tenant_id,scopes FROM api_keys WHERE key_hash=? AND revoked_at IS NULL',[hash('sha256',$key)])->fetch();
    if (!$rec) json_response(['error'=>'Chave inválida ou revogada'],401);
    $tid=(int)$rec['tenant_id']; $method=$_SERVER['REQUEST_METHOD']; $scopes=explode(',',(string)$rec['scopes']);
    if(preg_match('#^media/(\d+)$#',$route,$mediaRoute)&&$method==='GET'){
        if(!in_array('messages:read',$scopes,true))json_response(['error'=>'Chave sem escopo messages:read'],403);
        $asset=q('SELECT * FROM media_assets WHERE id=? AND tenant_id=?',[(int)$mediaRoute[1],$tid])->fetch();if(!$asset)json_response(['error'=>'Mídia não encontrada nesta empresa'],404);stream_meta_media($tid,$asset,true);
    }
    if($route==='media'&&$method==='POST'){
        if(!in_array('messages:write',$scopes,true))json_response(['error'=>'Chave sem escopo messages:write'],403);
        $meta=meta_config($tid);$phoneNumberId=(string)($meta['phone_number_id']??'');if(!$phoneNumberId)json_response(['error'=>'Número não configurado'],409);
        try{$media=meta_upload($tid,$phoneNumberId,$_FILES['file']??['error'=>UPLOAD_ERR_NO_FILE]);$assetId=store_media_asset($tid,$phoneNumberId,$media);json_response(['asset_id'=>$assetId,'type'=>$media['kind'],'mime_type'=>$media['mime'],'filename'=>$media['filename']],201);}catch(Throwable $error){json_response(['error'=>$error->getMessage()],422);}
    }
    if($route==='contacts'&&$method==='POST'){
        if(!in_array('contacts:write',$scopes,true))json_response(['error'=>'Chave sem escopo contacts:write'],403);
        $payload=json_decode(file_get_contents('php://input'),true)??[];$phone=preg_replace('/\D+/','',(string)($payload['phone']??''));$name=trim((string)($payload['name']??''));$consent=!empty($payload['consent']);$source=trim((string)($payload['consent_source']??''));
        if(strlen($phone)<8||!$name)json_response(['error'=>'Informe name e phone com código internacional'],422);
        if($consent&&!$source)json_response(['error'=>'consent_source é obrigatório quando consent=true'],422);
        $existing=q('SELECT id FROM contacts WHERE tenant_id=? AND phone=?',[$tid,$phone])->fetch();
        if($existing){if($consent)q("UPDATE contacts SET name=?,consent_status='opted_in',consent_source=?,consent_at=NOW(),opted_out_at=NULL WHERE id=? AND tenant_id=?",[$name,$source,$existing['id'],$tid]);else q('UPDATE contacts SET name=? WHERE id=? AND tenant_id=?',[$name,$existing['id'],$tid]);$contactId=(int)$existing['id'];}
        else{q("INSERT INTO contacts(tenant_id,name,phone,consent_status,consent_source,consent_at,created_at) VALUES(?,?,?,?,?,?,NOW())",[$tid,$name,$phone,$consent?'opted_in':'unknown',$source?:null,$consent?date('Y-m-d H:i:s'):null]);$contactId=(int)db()->lastInsertId();}
        json_response(['id'=>$contactId,'phone'=>$phone,'consent_status'=>$consent?'opted_in':(string)q('SELECT consent_status FROM contacts WHERE id=? AND tenant_id=?',[$contactId,$tid])->fetchColumn()],201);
    }
    if ($route==='messages' && $method==='POST') {
        if (!in_array('messages:write',$scopes,true)) json_response(['error'=>'Chave sem escopo messages:write'],403);
        $payload=json_decode(file_get_contents('php://input'),true)??[];
        $to=preg_replace('/\D+/','',(string)($payload['to']??'')); $text=trim((string)($payload['text']??'')); $template=$payload['template']??null;$mediaInput=$payload['media']??null;
        $optionCount=($text!==''?1:0)+(is_array($template)?1:0)+(is_array($mediaInput)?1:0);
        if (strlen($to)<8 || $optionCount!==1) json_response(['error'=>'Informe to e uma única opção: text, template ou media'],422);
        $contact=q('SELECT * FROM contacts WHERE tenant_id=? AND phone=?',[$tid,$to])->fetch();
        if (!$contact) json_response(['error'=>'Cadastre o contato e registre a autorização antes do envio'],422);
        if ($contact['consent_status']==='opted_out') json_response(['error'=>'O contato pediu para não receber novas mensagens'],422);
        $msg=null; $conv=null;$mediaAsset=null;
        if (is_array($template)) {
            $templateName=(string)($template['name']??''); $language=(string)($template['language']['code']??'');
            $approved=q('SELECT * FROM templates WHERE tenant_id=? AND name=? AND language=? AND status=\'APPROVED\'',[$tid,$templateName,$language])->fetch();
            if (!$approved) json_response(['error'=>'O template não está aprovado ou não pertence a esta empresa'],422);
            if ($contact['consent_status']!=='opted_in') json_response(['error'=>'O contato não possui autorização registrada'],422);
            $conv=q('SELECT * FROM conversations WHERE tenant_id=? AND contact_id=? ORDER BY id DESC LIMIT 1',[$tid,$contact['id']])->fetch();
            $msg=['messaging_product'=>'whatsapp','to'=>$to,'type'=>'template','template'=>['name'=>$approved['name'],'language'=>['code'=>$approved['language']]]];
            if (!empty($template['components'])) $msg['template']['components']=$template['components'];
        } elseif(is_array($mediaInput)) {
            $assetId=(int)($mediaInput['asset_id']??0);$mediaAsset=q('SELECT a.* FROM media_assets a JOIN tenant_meta tm ON tm.tenant_id=a.tenant_id AND tm.phone_number_id=a.phone_number_id WHERE a.id=? AND a.tenant_id=?',[$assetId,$tid])->fetch();
            if(!$mediaAsset)json_response(['error'=>'asset_id não encontrado nesta empresa; envie primeiro o arquivo para /api/v1/media'],422);
            $caption=trim((string)($mediaInput['caption']??''));if($caption&&!in_array($mediaAsset['media_type'],['image','video','document'],true))json_response(['error'=>'Áudio não aceita legenda nesta integração'],422);
            $conv=q('SELECT * FROM conversations WHERE tenant_id=? AND contact_id=? ORDER BY id DESC LIMIT 1',[$tid,$contact['id']])->fetch();
            if(!$conv||empty($conv['last_inbound_at'])||strtotime($conv['last_inbound_at'])<time()-86400)json_response(['error'=>'Mídia livre requer janela de atendimento aberta; use um template aprovado'],422);
            $mediaPayload=['id'=>$mediaAsset['meta_media_id']];if($caption)$mediaPayload['caption']=$caption;if($mediaAsset['media_type']==='document')$mediaPayload['filename']=$mediaAsset['filename'];
            $msg=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>$mediaAsset['media_type']];$msg[$mediaAsset['media_type']]=$mediaPayload;
        } else {
            $conv=q('SELECT * FROM conversations WHERE tenant_id=? AND contact_id=? ORDER BY id DESC LIMIT 1',[$tid,$contact['id']])->fetch();
            if (!$conv || empty($conv['last_inbound_at']) || strtotime($conv['last_inbound_at'])<time()-86400) json_response(['error'=>'Mensagem livre requer janela de atendimento aberta; use template aprovado'],422);
            $msg=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'text','text'=>['preview_url'=>false,'body'=>$text]];
        }
        $idem=trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??''));
        if ($idem) { $old=q('SELECT response_json FROM idempotency_keys WHERE tenant_id=? AND idem_key=?',[$tid,$idem])->fetch(); if ($old && $old['response_json']) json_response(json_decode($old['response_json'],true)??['status'=>'already_processed'],200); if ($old) json_response(['error'=>'Requisição com esta chave ainda está sendo processada'],409); }
        $m=meta_config($tid); if (!$m['phone_number_id']) json_response(['error'=>'Número não configurado'],409);
        try {
            if ($idem) q('INSERT INTO idempotency_keys(tenant_id,idem_key,response_json,created_at) VALUES(?,?,?,NOW())',[$tid,$idem,'']);
            $res=meta_call($tid,'POST',$m['phone_number_id'].'/messages',$msg); $wamid=$res['messages'][0]['id']??null;
            if (!$conv) { q('INSERT INTO conversations(tenant_id,contact_id,phone_number_id,status,created_at,updated_at) VALUES(?,?,?,\'open\',NOW(),NOW())',[$tid,$contact['id'],$m['phone_number_id']]); $conv=['id'=>(int)db()->lastInsertId()]; }
            $messageBody=is_array($template)?'['.$approved['name'].'] '.$approved['body']:(is_array($mediaInput)?(trim((string)($mediaInput['caption']??''))?:'Anexo: '.$mediaAsset['filename']):$text);$messageType=$mediaAsset['media_type']??$msg['type'];
            q('INSERT INTO messages(tenant_id,conversation_id,direction,type,body,wamid,media_id,media_filename,media_mime,status,created_at) VALUES(?,?,\'out\',?,?,?,?,?,?,\'accepted\',NOW())',[$tid,$conv['id'],$messageType,$messageBody,$wamid,$mediaAsset['meta_media_id']??null,$mediaAsset['filename']??null,$mediaAsset['mime_type']??null]);
            q('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,updated_at=NOW() WHERE id=? AND tenant_id=?',[mb_substr($messageBody,0,240),$conv['id'],$tid]); queue_tenant_event($tid,'message.sent',['message_id'=>$wamid,'phone'=>$to,'text'=>$messageBody,'type'=>$messageType,'asset_id'=>$mediaAsset['id']??null,'status'=>'accepted']);
            $out=['message_id'=>$wamid,'status'=>'accepted']; if($idem) q('UPDATE idempotency_keys SET response_json=? WHERE tenant_id=? AND idem_key=?',[json_encode($out),$tid,$idem]);
            json_response($out,202);
        } catch(Throwable $e) { if ($idem) q('DELETE FROM idempotency_keys WHERE tenant_id=? AND idem_key=? AND response_json=\'\'',[$tid,$idem]); json_response(['error'=>$e->getMessage()],502); }
    }
    if ($route==='templates' && $method==='GET') { if(!in_array('messages:read',$scopes,true)) json_response(['error'=>'Chave sem escopo messages:read'],403); json_response(['data'=>q('SELECT name,language,category,status,meta_id FROM templates WHERE tenant_id=? ORDER BY name',[$tid])->fetchAll()]); }
    if ($route==='messages' && $method==='GET') { if(!in_array('messages:read',$scopes,true)) json_response(['error'=>'Chave sem escopo messages:read'],403); json_response(['data'=>q('SELECT m.id,m.conversation_id,m.direction,m.type,m.body,m.status,m.wamid,m.media_filename,m.media_mime,(SELECT a.id FROM media_assets a WHERE a.tenant_id=m.tenant_id AND a.meta_media_id=m.media_id LIMIT 1) asset_id,m.created_at FROM messages m WHERE m.tenant_id=? ORDER BY m.id DESC LIMIT 100',[$tid])->fetchAll()]); }
    json_response(['error'=>'Rota não encontrada'],404);
}function handle_meta_webhook(): void {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        $mode=$_GET['hub_mode']??$_GET['hub.mode']??''; $token=$_GET['hub_verify_token']??$_GET['hub.verify_token']??''; $challenge=$_GET['hub_challenge']??$_GET['hub.challenge']??'';
        $match=q('SELECT tenant_id FROM tenant_meta WHERE verify_token=? LIMIT 1',[(string)$token])->fetch();
        if($mode==='subscribe'&&$match){header('Content-Type: text/plain');echo $challenge;return;}
        http_response_code(403);echo 'Verificação recusada';return;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);return;}
    $raw=file_get_contents('php://input');$sig=(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??'');$event=json_decode($raw,true);
    if(!is_array($event)){http_response_code(400);return;}
    $accepted=false;
    foreach(($event['entry']??[]) as $entry){
        foreach(($entry['changes']??[]) as $change){
            $value=$change['value']??[];$number=(string)($value['metadata']['phone_number_id']??'');if(!$number)continue;
            $meta=q("SELECT tm.*,t.id tid FROM tenant_meta tm JOIN tenants t ON t.id=tm.tenant_id WHERE tm.phone_number_id=? AND t.status='active' LIMIT 1",[$number])->fetch();if(!$meta)continue;
            $appSecret=$meta['app_secret_enc']?decrypt_secret($meta['app_secret_enc']):'';$expected=$appSecret?'sha256='.hash_hmac('sha256',$raw,$appSecret):'';
            if(!$expected||!hash_equals($expected,$sig)){http_response_code(401);echo 'Assinatura inválida';return;}
            $eventHash=hash('sha256',$raw.'|'.$number.'|'.(string)($change['field']??''). '|'.json_encode($change));
            try{q('INSERT INTO webhook_events(tenant_id,event_hash,payload,created_at) VALUES(?,?,?,NOW())',[(int)$meta['tid'],$eventHash,json_encode($change,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);}catch(Throwable $e){$accepted=true;continue;}
            $accepted=true;
            foreach(($value['messages']??[]) as $in){
                $from=preg_replace('/\D+/','',(string)($in['from']??''));if(!$from)continue;
                $contact=q('SELECT id FROM contacts WHERE tenant_id=? AND phone=?',[(int)$meta['tid'],$from])->fetch();
                if(!$contact){q("INSERT INTO contacts(tenant_id,name,phone,consent_status,created_at) VALUES(?,?,?,'unknown',NOW())",[(int)$meta['tid'],$value['contacts'][0]['profile']['name']??$from,$from]);$contact=['id'=>(int)db()->lastInsertId()];}
                $conv=q("SELECT id FROM conversations WHERE tenant_id=? AND contact_id=? AND phone_number_id=? AND status<>'closed' ORDER BY id DESC LIMIT 1",[(int)$meta['tid'],$contact['id'],$number])->fetch();
                if(!$conv){q("INSERT INTO conversations(tenant_id,contact_id,phone_number_id,status,unread_count,created_at,updated_at) VALUES(?,?,?,'open',1,NOW(),NOW())",[(int)$meta['tid'],$contact['id'],$number]);$cid=(int)db()->lastInsertId();}else{$cid=(int)$conv['id'];q("UPDATE conversations SET unread_count=unread_count+1,status='open' WHERE id=? AND tenant_id=?",[$cid,(int)$meta['tid']]);}
                $type=(string)($in['type']??'unknown');$mediaObject=is_array($in[$type]??null)?$in[$type]:[];$mediaId=(string)($mediaObject['id']??'');$mediaMime=(string)($mediaObject['mime_type']??'');$mediaFilename=(string)($mediaObject['filename']??'');$text=$in['text']['body']??($mediaObject['caption']??($mediaId?'Anexo recebido: '.($mediaFilename?:$type):'[Mídia '.$type.' recebida]'));$mid=(string)($in['id']??'');$normalized=mb_strtolower(trim((string)$text));if($type==='text'&&in_array($normalized,['parar','cancelar','sair','stop','unsubscribe','remover','não quero receber'],true))q("UPDATE contacts SET consent_status='opted_out',opted_out_at=NOW() WHERE id=? AND tenant_id=?",[$contact['id'],(int)$meta['tid']]);
                try{q("INSERT INTO messages(tenant_id,conversation_id,direction,type,body,wamid,media_id,media_filename,media_mime,status,created_at) VALUES(?,?, 'in',?,?,?,?,?,?, 'received',NOW())",[(int)$meta['tid'],$cid,$type,$text,$mid,$mediaId?:null,$mediaFilename?:null,$mediaMime?:null]);}catch(Throwable $e){continue;}
                $assetId=null;if($mediaId&&$mediaMime&&in_array($type,['image','video','audio','document'],true))$assetId=store_media_asset((int)$meta['tid'],$number,['id'=>$mediaId,'mime'=>$mediaMime,'kind'=>$type,'filename'=>$mediaFilename?:'inbound-'.$type]);
                q('UPDATE conversations SET last_inbound_at=NOW(),last_message_at=NOW(),last_message_preview=?,updated_at=NOW() WHERE id=? AND tenant_id=?',[mb_substr((string)$text,0,240),$cid,(int)$meta['tid']]);queue_tenant_event((int)$meta['tid'],'message.received',['message_id'=>$mid,'phone'=>$from,'type'=>$type,'text'=>$text,'asset_id'=>$assetId,'filename'=>$mediaFilename?:null,'mime_type'=>$mediaMime?:null,'timestamp'=>$in['timestamp']??null]);
            }
            foreach(($value['statuses']??[]) as $status){$mid=(string)($status['id']??'');if(!$mid)continue;$state=(string)($status['status']??'unknown');q('UPDATE messages SET status=? WHERE tenant_id=? AND wamid=?',[$state,(int)$meta['tid'],$mid]);q('UPDATE campaign_recipients SET status=?,updated_at=NOW() WHERE tenant_id=? AND wamid=? AND status<>\'failed\'',[$state,(int)$meta['tid'],$mid]);queue_tenant_event((int)$meta['tid'],'message.status',['message_id'=>$mid,'status'=>$state,'timestamp'=>$status['timestamp']??null,'recipient_id'=>$status['recipient_id']??null]);}
        }
    }
    http_response_code(200);echo $accepted?'EVENT_RECEIVED':'ignored';
}function stream_meta_media(int $tid,array $asset,bool $forceDownload=false): never {
    if(empty($asset['meta_media_id'])||!ctype_digit((string)$asset['meta_media_id'])){http_response_code(404);exit('Anexo não encontrado.');}
    $temp=tempnam(sys_get_temp_dir(),'wgm_');if($temp===false){http_response_code(500);exit('Não foi possível abrir o anexo.');}$stream=fopen($temp,'wb');if(!$stream){@unlink($temp);http_response_code(500);exit('Não foi possível abrir o anexo.');}
    try{
        $meta=meta_call($tid,'GET',rawurlencode((string)$asset['meta_media_id']).'?fields=url,mime_type,file_size');$mediaUrl=(string)($meta['url']??'');$parsed=parse_url($mediaUrl);$host=strtolower((string)($parsed['host']??''));$trustedHost=$host==='lookaside.fbsbx.com'||str_ends_with($host,'.fbcdn.net');
        if(strtolower((string)($parsed['scheme']??''))!=='https'||!$trustedHost||!empty($parsed['user'])||!empty($parsed['pass'])||(int)($parsed['port']??443)!==443)throw new RuntimeException('A Meta retornou um endereço de mídia inválido.');
        $maxBytes=32*1024*1024;if((int)($meta['file_size']??0)>$maxBytes)throw new RuntimeException('O anexo excede o limite de visualização.');$config=meta_config($tid);$ch=curl_init($mediaUrl);$written=0;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$config['access_token']],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_WRITEFUNCTION=>function($curl,$chunk)use($stream,&$written,$maxBytes){$length=strlen($chunk);if($written+$length>$maxBytes)return 0;$saved=fwrite($stream,$chunk);if($saved===false)return 0;$written+=$saved;return $saved;}]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);fclose($stream);$stream=null;if($ok===false||$status>=400||$written===0)throw new RuntimeException('Não foi possível baixar a mídia da Meta.');
        $mime=(string)($meta['mime_type']??$asset['mime_type']??'application/octet-stream');if(!preg_match('#^[a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+$#',$mime))$mime='application/octet-stream';$filename=basename(str_replace('\\','/',(string)($asset['filename']?:'anexo')));$filename=preg_replace('/[^\x20-\x7E]/','_',$filename)?:'anexo';$filename=str_replace(['"',"\r","\n"],'',$filename);$inline=!$forceDownload&&(str_starts_with($mime,'image/')||str_starts_with($mime,'audio/')||str_starts_with($mime,'video/'));
        header('Content-Type: '.$mime);header('Content-Length: '.$written);header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$filename.'"');header('Cache-Control: private, max-age=3600');header('X-Content-Type-Options: nosniff');readfile($temp);@unlink($temp);exit;
    }catch(Throwable $error){if(is_resource($stream))fclose($stream);@unlink($temp);http_response_code(502);header('Content-Type: text/plain; charset=utf-8');exit('Anexo temporariamente indisponível. Verifique a conexão da conta Meta e tente novamente.');}
}
function handle_media(): void {
    $message=q('SELECT media_id meta_media_id,media_filename filename,media_mime mime_type FROM messages WHERE id=? AND tenant_id=?',[(int)($_GET['id']??0),tenant_id()])->fetch();
    if(!$message){http_response_code(404);exit('Anexo não encontrado.');}stream_meta_media(tenant_id(),$message);
}function render_app(): void {
    $r=(string)($_GET['r']??'dashboard'); $title=match($r){'inbox'=>'Central de conversas','contacts'=>'Contatos','templates'=>'Templates','quick-replies'=>'Respostas rápidas','campaigns'=>'Campanhas','api'=>'Integrações API','setup-meta'=>'Instalação Meta','settings'=>'Configurações','audit'=>'Auditoria','tenants'=>'Clientes da plataforma',default=>'Visão geral'};
    $user=current_user(); $tenant=q('SELECT name FROM tenants WHERE id=?',[tenant_id()])->fetchColumn(); $flash=$_SESSION['flash']??null; unset($_SESSION['flash']); $newKey=$_SESSION['new_api_key']??null; unset($_SESSION['new_api_key']); $newHookSecret=$_SESSION['new_hook_secret']??null; unset($_SESSION['new_hook_secret']);
    $data=[];
    if ($r==='dashboard') { $data['contacts']=(int)q('SELECT COUNT(*) FROM contacts WHERE tenant_id=?',[tenant_id()])->fetchColumn(); $data['conversations']=(int)q('SELECT COUNT(*) FROM conversations WHERE tenant_id=? AND status<>\'closed\'',[tenant_id()])->fetchColumn(); $data['messages']=(int)q('SELECT COUNT(*) FROM messages WHERE tenant_id=? AND created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)',[tenant_id()])->fetchColumn(); $data['templates']=(int)q('SELECT COUNT(*) FROM templates WHERE tenant_id=? AND status=\'APPROVED\'',[tenant_id()])->fetchColumn(); $data['recent']=q('SELECT c.id,c.status,c.last_message_preview,c.last_message_at,ct.name,ct.phone FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.tenant_id=? ORDER BY c.last_message_at DESC LIMIT 7',[tenant_id()])->fetchAll(); $data['meta']=meta_config(tenant_id()); }
    if ($r==='setup-meta') $data['meta']=meta_config(tenant_id());
    if ($r==='inbox') { $data['conversations']=q('SELECT c.*,ct.name contact_name,ct.phone,ct.consent_status FROM conversations c JOIN contacts ct ON ct.id=c.contact_id WHERE c.tenant_id=? ORDER BY COALESCE(c.last_message_at,c.created_at) DESC LIMIT 100',[tenant_id()])->fetchAll(); $data['selected']=isset($_GET['id'])?(int)$_GET['id']:(int)($data['conversations'][0]['id']??0); $data['messages']=$data['selected']?q('SELECT * FROM messages WHERE tenant_id=? AND conversation_id=? ORDER BY id', [tenant_id(),$data['selected']])->fetchAll():[]; $data['approved_templates']=q('SELECT id,name,language FROM templates WHERE tenant_id=? AND status=\'APPROVED\' ORDER BY name',[tenant_id()])->fetchAll();$data['quick_replies']=q('SELECT id,name,body FROM quick_replies WHERE tenant_id=? ORDER BY name',[tenant_id()])->fetchAll(); if ($data['selected']) q('UPDATE conversations SET unread_count=0 WHERE id=? AND tenant_id=?',[$data['selected'],tenant_id()]); }
    if ($r==='quick-replies') $data['quick_replies']=q('SELECT * FROM quick_replies WHERE tenant_id=? ORDER BY name',[tenant_id()])->fetchAll();
    if ($r==='contacts') $data['contacts']=q('SELECT * FROM contacts WHERE tenant_id=? ORDER BY id DESC LIMIT 500',[tenant_id()])->fetchAll();
    if ($r==='templates') $data['templates']=q('SELECT * FROM templates WHERE tenant_id=? ORDER BY updated_at DESC',[tenant_id()])->fetchAll();
    if ($r==='campaigns') $data['campaigns']=q('SELECT c.*,t.name template_name,(SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id=c.id AND cr.status=\'pending\') pending_count,(SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id=c.id AND cr.status IN (\'accepted\',\'sent\',\'delivered\',\'read\')) sent_count,(SELECT COUNT(*) FROM campaign_recipients cr WHERE cr.campaign_id=c.id AND cr.status=\'failed\') failed_count FROM campaigns c LEFT JOIN templates t ON t.id=c.template_id WHERE c.tenant_id=? ORDER BY c.id DESC',[tenant_id()])->fetchAll(); $data['approved_templates']=q('SELECT id,name,language,category FROM templates WHERE tenant_id=? AND status=\'APPROVED\'',[tenant_id()])->fetchAll();
    if ($r==='api') { $data['keys']=q('SELECT id,name,key_prefix,scopes,created_at,revoked_at FROM api_keys WHERE tenant_id=? ORDER BY id DESC',[tenant_id()])->fetchAll(); $data['webhook']=q('SELECT url,events,active,updated_at FROM tenant_webhooks WHERE tenant_id=?',[tenant_id()])->fetch()?:[]; $data['hook_failures']=q("SELECT event_type,status,attempts,last_error,created_at FROM webhook_outbox WHERE tenant_id=? AND status='failed' ORDER BY id DESC LIMIT 10",[tenant_id()])->fetchAll(); }
    if ($r==='settings') { $data['users']=q('SELECT id,name,email,role,status,created_at,mfa_enabled FROM users WHERE tenant_id=? ORDER BY id',[tenant_id()])->fetchAll(); $data['mfa_enabled']=(int)(current_user()['mfa_enabled']??0)===1; $data['mfa_pending']=(string)($_SESSION['mfa_pending']??''); $data['mfa_uri']=$data['mfa_pending']?'otpauth://totp/'.rawurlencode('Ponte:'.current_user()['email']).'?secret='.$data['mfa_pending'].'&issuer=Ponte&digits=6&period=30':''; }
    if ($r==='tenants' && current_user()['role']==='platform_admin') $data['tenants']=q('SELECT t.*,COUNT(DISTINCT u.id) users_count,COUNT(DISTINCT m.phone_number_id) numbers_count FROM tenants t LEFT JOIN users u ON u.tenant_id=t.id LEFT JOIN tenant_meta m ON m.tenant_id=t.id GROUP BY t.id ORDER BY t.id DESC')->fetchAll();
    if ($r==='audit') $data['logs']=q('SELECT * FROM whatsapp_gateway_audit_logs WHERE tenant_id=? ORDER BY id DESC LIMIT 200',[tenant_id()])->fetchAll();
    $view=in_array($r,['dashboard','inbox','contacts','templates','quick-replies','campaigns','api','setup-meta','settings','audit'],true)?$r:($r==='tenants'&&current_user()['role']==='platform_admin'?'tenants':'dashboard'); include APP_ROOT.'/app/views/layout.php';
}
