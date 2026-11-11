<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/whatsapp_bootstrap.php';
require dirname(__DIR__) . '/src/whatsapp_Graph.php';
require dirname(__DIR__) . '/src/whatsapp_Rules.php';
require dirname(__DIR__) . '/src/whatsapp_Webhook.php';
require dirname(__DIR__) . '/src/whatsapp_Jobs.php';
require dirname(__DIR__) . '/src/whatsapp_Portal.php';
require dirname(__DIR__) . '/src/whatsapp_Installer.php';
require dirname(__DIR__) . '/src/whatsapp_MetaPanel.php';
require dirname(__DIR__) . '/src/whatsapp_Mobile.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function limitNumber(mixed $value, int $max = 100): int {
    $n = filter_var($value, FILTER_VALIDATE_INT);
    return $n === false ? 50 : min($max,max(1,$n));
}
function getList(string $sql, array $params, int $max = 100): array {
    $limit = limitNumber($_GET['limit'] ?? 50,$max);
    $s = db()->prepare($sql . ' LIMIT ' . $limit);
    $s->execute($params);
    return $s->fetchAll();
}
function checkUpload(): CURLFile {
    if (!isset($_FILES['file']) || !is_array($_FILES['file']) ||
        ($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) throw new HttpError('Missing media upload field: file');
    $file = $_FILES['file'];
    $max = max(1024,(int)config('MAX_UPLOAD_BYTES','104857600'));
    if ($file['size'] <= 0 || $file['size'] > $max) throw new HttpError('File exceeds configured upload size',413);
    if (!is_uploaded_file($file['tmp_name'])) throw new HttpError('Invalid upload');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!$mime || !preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~iD',$mime))
        throw new HttpError('Invalid file type');
    $name = basename((string)$file['name']);
    return new CURLFile($file['tmp_name'],$mime,$name);
}
try {
    requireHttps();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($path)) throw new HttpError('Invalid path',404);
    $base = rtrim(config('APP_BASE_PATH'),'/');
    if ($base !== '') {
        if (!str_starts_with($path, $base . '/') && $path !== $base) throw new HttpError('Path not found',404);
        $path = substr($path,strlen($base)) ?: '/';
    }
    $path = rtrim($path,'/') ?: '/';

    // Fallback em ambientes que encaminham URLs de arquivos legais ao front controller.
    // Somente arquivos da lista fixa podem ser carregados, sem entrada arbitraria.
    if ($method === 'GET' && in_array($path,['/politicas','/termos','/exclusao',
            '/politicas/index.php','/termos/index.php','/exclusao/index.php'],true)) {
        $slug=explode('/',trim($path,'/'))[0];
        $legalFile=dirname(__DIR__).'/'.$slug.'/index.php';
        if (is_file($legalFile)) { require $legalFile; exit; }
    }
    if ($method === 'GET' && $path === '/assets/legal.css') {
        $css=dirname(__DIR__).'/assets/legal.css';
        if (is_file($css)) {
            header('Content-Type: text/css; charset=utf-8');
            readfile($css); exit;
        }
    }
    if (WhatsAppMobile::route($path, $method)) exit;
    if (WhatsAppMetaPanel::route($path, $method)) exit;
    if (WhatsAppInstaller::route($path, $method)) exit;
    if (WhatsAppPortal::route($path, $method)) exit;
    if ($method === 'GET' && $path === '/health') response(200,['status'=>'ok','gateway'=>'cloud-api','version'=>'1.0']);
    if ($path === '/webhooks/meta') {
        if ($method === 'GET') {
            $challenge = Webhook::verifySubscription($_GET);
            header('Content-Type: text/plain; charset=utf-8');
            echo $challenge;
            exit;
        }
        if ($method === 'POST') {
            $max = max(1024,(int)config('MAX_BODY_BYTES','2097152'));
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) throw new HttpError('Webhook too large',413);
            $raw = file_get_contents('php://input',false,null,0,$max+1);
            if (!is_string($raw) || strlen($raw)>$max) throw new HttpError('Webhook too large',413);
            response(200,Webhook::receive($raw,(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')));
        }
        throw new HttpError('Method not allowed',405);
    }

    if (!str_starts_with($path,'/v1/')) throw new HttpError('Path not found',404);
    $context = bearerContext();
    $tenantId = (int)$context['tenant_id'];
    if ($path === '/v1/tenant' && $method === 'GET') {
        $st = db()->prepare("SELECT id,slug,name,status,created_at FROM whatsapp_tenants WHERE id=?");
        $st->execute([$tenantId]);
        response(200,$st->fetch());
    }
    if ($path === '/v1/accounts' && $method === 'GET') {
        $sql = "SELECT id,name,waba_id,phone_number_id,status,created_at
                FROM whatsapp_accounts WHERE tenant_id=?";
        $args = [$tenantId];
        if ($context['account_id'] !== null) {
            $sql .= " AND id=?";
            $args[] = $context['account_id'];
        }
        $sql .= " ORDER BY id";
        $st = db()->prepare($sql);
        $st->execute($args);
        response(200,['data'=>$st->fetchAll()]);
    }
    $account = selectedAccount($context, $_SERVER['HTTP_X_WHATSAPP_PHONE_ID'] ?? null);
    $accountId = (int)$account['id'];
    $waba = $account['waba_id'];
    $phone = $account['phone_number_id'];

    if ($path === '/v1/me' && $method === 'GET')
        response(200,['tenant_id'=>$tenantId,'tenant_slug'=>$context['tenant_slug'],'account_id'=>$accountId,'name'=>$account['name'],'waba_id'=>$waba,
                      'phone_number_id'=>$phone,'graph_version'=>config('GRAPH_VERSION','v26.0')]);

    if ($path === '/v1/messages' && $method === 'POST') {
        $data = Rules::normaliseMessage(requestJson());
        Rules::ensureConsentAndWindow($account,$data);
        if (($_GET['sync'] ?? '') === '1') {
            if (isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])) throw new HttpError('Use async mode for Idempotency-Key',422);
            $result = Graph::call($account,'POST',$phone.'/messages',$data);
            Rules::storeOutgoing($account,$data,$result);
            audit($accountId,'message.sent',['to'=>$data['to'],'type'=>$data['type']]);
            response(200,$result);
        }
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ('auto-' . bin2hex(random_bytes(16)));
        $result = Jobs::enqueueSend($account,$data,$key);
        audit($accountId,'message.queued',['job_id'=>$result['job_id'],'type'=>$data['type']]);
        response(202,$result);
    }
    if ($path === '/v1/messages/read' && $method === 'POST') {
        $data = requestJson();
        $id = $data['message_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{5,255}$/D',$id)) throw new HttpError('Invalid message_id');
        $body = ['messaging_product'=>'whatsapp','status'=>'read','message_id'=>$id];
        if (isset($data['typing_indicator'])) $body['typing_indicator'] = $data['typing_indicator'];
        response(200,Graph::call($account,'POST',$phone.'/messages',$body));
    }
    if ($path === '/v1/messages' && $method === 'GET') {
        $before = (int)($_GET['before_id'] ?? PHP_INT_MAX);
        response(200,['data'=>getList('SELECT id,wa_id,direction,whatsapp_id,message_type,status,payload,meta_response,created_at
          FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND id<? ORDER BY id DESC',[$tenantId,$accountId,$before])]);
    }
    if ($path === '/v1/contacts' && $method === 'GET')
        response(200,['data'=>getList('SELECT id,wa_id,display_name,opt_in_at,opt_in_source,last_inbound_at
          FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? ORDER BY id DESC',[$tenantId,$accountId])]);
    if ($path === '/v1/contacts/consent' && $method === 'POST') {
        $data = requestJson();
        $waId = (string)($data['wa_id'] ?? '');
        if (!validPhone($waId) || !is_bool($data['consent'] ?? null))
            throw new HttpError('wa_id numeric and consent boolean required');
        $consent = $data['consent'];
        $source = trim((string)($data['source'] ?? ''));
        if ($consent && (strlen($source)<4 || strlen($source)>255))
            throw new HttpError('Documented consent source required (4-255 chars)');
        $s = db()->prepare('INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id,opt_in_at,opt_in_source)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE opt_in_at=VALUES(opt_in_at),
             opt_in_source=VALUES(opt_in_source)');
        $s->execute([$tenantId,$accountId,$waId,$consent?gmdate('Y-m-d H:i:s'):null,$consent?$source:null]);
        audit($accountId,$consent?'contact.optin':'contact.optout',['wa_id'=>$waId,'source'=>$consent?$source:null]);
        response(200,['ok'=>true,'wa_id'=>$waId,'consent'=>$consent]);
    }
    if ($path === '/v1/events' && $method === 'GET') {
        $before = (int)($_GET['before_id'] ?? PHP_INT_MAX);
        response(200,['data'=>getList('SELECT id,event_key,event_type,payload,received_at
          FROM whatsapp_webhook_events WHERE tenant_id=? AND account_id=? AND id<? ORDER BY id DESC',[$tenantId,$accountId,$before])]);
    }
    if (preg_match('~^/v1/jobs/([0-9]+)$~D',$path,$m)) {
        if ($method === 'GET') {
            $s = db()->prepare('SELECT id,kind,idempotency_key,status,attempts,max_attempts,
                         next_attempt_at,last_error,response,created_at,updated_at FROM whatsapp_jobs WHERE id=? AND tenant_id=? AND account_id=?');
            $s->execute([$m[1],$tenantId,$accountId]);
            $job = $s->fetch();
            if (!$job) throw new HttpError('Job not found',404);
            response(200,$job);
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'retry') {
            $s = db()->prepare("UPDATE whatsapp_jobs SET status='queued',attempts=0,next_attempt_at=UTC_TIMESTAMP(),locked_at=NULL
                  WHERE id=? AND tenant_id=? AND account_id=? AND status IN ('failed','uncertain')");
            $s->execute([$m[1],$tenantId,$accountId]);
            if (!$s->rowCount()) throw new HttpError('Job not found or not retryable',409);
            audit($accountId,'job.manual_retry',['job_id'=>(int)$m[1],'duplicate_risk'=>true]);
            response(202,['queued'=>true,'duplicate_risk'=>true]);
        }
    }
    if ($path === '/v1/media' && $method === 'POST') {
        $media = checkUpload();
        response(200,Graph::call($account,'POST',$phone.'/media',null,[],
                ['messaging_product'=>'whatsapp','file'=>$media]));
    }
    if (preg_match('~^/v1/media/([0-9]+)$~D',$path,$m)) {
        if ($method === 'GET') response(200,Graph::call($account,'GET',$m[1]));
        if ($method === 'DELETE') response(200,Graph::call($account,'DELETE',$m[1]));
    }
    if ($path === '/v1/templates') {
        if ($method === 'GET') {
            $q = ['limit'=>limitNumber($_GET['limit'] ?? 50,100)];
            if (isset($_GET['name'])) $q['name'] = (string)$_GET['name'];
            response(200,Graph::call($account,'GET',$waba.'/message_templates',null,$q));
        }
        if ($method === 'POST') response(200,Graph::call($account,'POST',$waba.'/message_templates',requestJson()));
        if ($method === 'DELETE') {
            $name = (string)($_GET['name'] ?? '');
            if (!preg_match('/^[a-z0-9_]{1,512}$/D',$name)) throw new HttpError('Template name required');
            response(200,Graph::call($account,'DELETE',$waba.'/message_templates',null,['name'=>$name]));
        }
    }
    if (preg_match('~^/v1/templates/([0-9]+)$~D',$path,$m) && $method === 'POST')
        response(200,Graph::call($account,'POST',$m[1],requestJson()));
    if ($path === '/v1/phone' && $method === 'GET')
        response(200,Graph::call($account,'GET',$phone,null,
            ['fields'=>'display_phone_number,verified_name,quality_rating,status']));
    if ($path === '/v1/phone/register' && $method === 'POST') {
        $body = requestJson();
        $pin = $body['pin'] ?? null;
        if (!is_string($pin) || !preg_match('/^[0-9]{6}$/D',$pin)) throw new HttpError('6-digit registration PIN required');
        audit($accountId,'phone.register.requested',['phone_number_id'=>$phone]);
        response(200,Graph::call($account,'POST',$phone.'/register',
            ['messaging_product'=>'whatsapp','pin'=>$pin]));
    }
    if ($path === '/v1/phone/deregister' && $method === 'POST') {
        $body = requestJson();
        if (($body['confirm'] ?? '') !== 'DEREGISTER') throw new HttpError('Explicit confirm=DEREGISTER required',422);
        audit($accountId,'phone.deregister.requested',['phone_number_id'=>$phone]);
        response(200,Graph::call($account,'POST',$phone.'/deregister'));
    }
    if ($path === '/v1/webhook-subscriptions') {
        if ($method === 'GET') response(200,Graph::call($account,'GET',$waba.'/subscribed_apps'));
        if ($method === 'POST') {
            audit($accountId,'waba.subscribe.requested');
            response(200,Graph::call($account,'POST',$waba.'/subscribed_apps'));
        }
        if ($method === 'DELETE') {
            audit($accountId,'waba.unsubscribe.requested');
            response(200,Graph::call($account,'DELETE',$waba.'/subscribed_apps'));
        }
    }
    if ($path === '/v1/phone-numbers' && $method === 'GET')
        response(200,Graph::call($account,'GET',$waba.'/phone_numbers'));
    if ($path === '/v1/business-profile') {
        if ($method === 'GET') response(200,Graph::call($account,'GET',$phone.'/whatsapp_business_profile',null,
            ['fields'=>'about,address,description,email,profile_picture_url,websites,vertical']));
        if ($method === 'POST') response(200,Graph::call($account,'POST',$phone.'/whatsapp_business_profile',requestJson()));
    }
    if ($path === '/v1/flows') {
        if ($method === 'GET') response(200,Graph::call($account,'GET',$waba.'/flows'));
        if ($method === 'POST') response(200,Graph::call($account,'POST',$waba.'/flows',requestJson()));
    }
    if (preg_match('~^/v1/flows/([0-9]+)$~D',$path,$m)) {
        if ($method === 'GET') response(200,Graph::call($account,'GET',$m[1]));
        if ($method === 'POST') response(200,Graph::call($account,'POST',$m[1],requestJson()));
        if ($method === 'DELETE') response(200,Graph::call($account,'DELETE',$m[1]));
    }
    if ($path === '/v1/graph' && $method === 'GET') {
        // Lista de edges permitidas; impossivel apontar o cliente a um host arbitrario.
        $scope = $_GET['scope'] ?? 'waba';
        $edge = $_GET['edge'] ?? '';
        $allow = [
            'waba'=>['message_templates','phone_numbers','flows','subscribed_apps'],
            'phone'=>['whatsapp_business_profile','business_profile','conversational_automation']
        ];
        if (!is_string($scope) || !is_string($edge) || !in_array($edge,$allow[$scope] ?? [],true))
            throw new HttpError('Unsupported Graph scope/edge');
        $id = $scope==='waba' ? $waba : $phone;
        $q = [];
        foreach (['fields','limit','after','before'] as $name) if (isset($_GET[$name])) {
            if (!is_scalar($_GET[$name]) || strlen((string)$_GET[$name])>500)
                throw new HttpError('Invalid query parameter');
            $q[$name]=(string)$_GET[$name];
        }
        response(200,Graph::call($account,'GET',$id.'/'.$edge,null,$q));
    }
    throw new HttpError('Path not found',404);
} catch (MetaFailure $e) {
    response($e->transportUncertain?502:($e->upstreamStatus >= 400 && $e->upstreamStatus <= 599 ? $e->upstreamStatus:502),
         ['error'=>'meta_error','upstream_status'=>$e->upstreamStatus,
          'details'=>$e->metaBody,'uncertain'=>$e->transportUncertain]);
} catch (HttpError $e) {
    response($e->httpStatus,['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    error_log('gateway failure: ' . get_class($e));
    response(500,['error'=>'Internal gateway error']);
}
