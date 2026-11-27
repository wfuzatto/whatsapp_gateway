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
require dirname(__DIR__) . '/src/whatsapp_Console.php';
require dirname(__DIR__) . '/src/whatsapp_ApiSecurity.php';
require dirname(__DIR__) . '/src/whatsapp_IntegrationService.php';
require dirname(__DIR__) . '/src/whatsapp_Mcp.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function limitNumber(mixed $value, int $max = 100): int {
    $n = filter_var($value, FILTER_VALIDATE_INT);
    return $n === false ? 50 : min($max,max(1,$n));
}
function apiCursor(mixed $value,int $default): int {
    if($value===null)return $default;
    if(!is_string($value)&&!is_int($value))throw new HttpError('Cursor inválido.',422);
    $raw=(string)$value;
    if(!preg_match('/^(0|[1-9][0-9]{0,18})$/D',$raw))throw new HttpError('Cursor inválido.',422);
    $parsed=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if($parsed===false)throw new HttpError('Cursor fora do limite suportado.',422);
    return (int)$parsed;
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

    if ($method === 'GET' && $path === '/mcp-docs') {
        require dirname(__DIR__) . '/public/whatsapp_mcp_docs.php';
        exit;
    }

    if (WhatsAppMcp::route($path,$method)) exit;

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
    if (WhatsAppConsole::route($path, $method)) exit;
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
        requireApiScope($context,'tenant:read');
        $st = db()->prepare("SELECT id,slug,name,status,created_at FROM whatsapp_tenants WHERE id=?");
        $st->execute([$tenantId]);
        response(200,$st->fetch());
    }
    if ($path === '/v1/accounts' && $method === 'GET') {
        requireApiScope($context,'accounts:read');
        response(200,['data'=>WhatsAppIntegrationService::accounts($context)]);
    }
    $account = selectedAccount($context, $_SERVER['HTTP_X_WHATSAPP_PHONE_ID'] ?? null);
    $accountId = (int)$account['id'];
    $waba = $account['waba_id'];
    $phone = $account['phone_number_id'];

    if ($path === '/v1/me' && $method === 'GET') {
        requireApiScope($context,'accounts:read');
        response(200,['tenant_id'=>$tenantId,'tenant_slug'=>$context['tenant_slug'],'account_id'=>$accountId,'name'=>$account['name'],'waba_id'=>$waba,
                      'phone_number_id'=>$phone,'graph_version'=>config('GRAPH_VERSION','v26.0')]);
    }

    if ($path === '/v1/conversations' && $method === 'GET') {
        requireApiScope($context,'conversations:read');
        $before=apiCursor($_GET['before_id']??null,PHP_INT_MAX);
        $search=isset($_GET['q'])&&is_string($_GET['q'])?substr(trim($_GET['q']),0,120):'';
        response(200,['data'=>WhatsAppIntegrationService::conversations($context,$account,$search,$before,
            limitNumber($_GET['limit']??50,100))]);
    }
    if (preg_match('~^/v1/conversations/([0-9]{7,20})/messages$~D',$path,$m)&&$method==='GET') {
        requireApiScope($context,'messages:read');
        $before=apiCursor($_GET['before_id']??null,PHP_INT_MAX);
        response(200,['data'=>WhatsAppIntegrationService::messages($context,$account,$m[1],$before,
            limitNumber($_GET['limit']??50,100))]);
    }
    if (preg_match('~^/v1/conversations/([0-9]{7,20})/read$~D',$path,$m)&&$method==='POST') {
        requireApiScope($context,'conversations:mark_read');
        WhatsAppApiSecurity::rateLimit($context,'write',30);
        $body=requestJson();$id=$body['message_id']??null;
        if(!is_string($id)&&!is_int($id))throw new HttpError('message_id obrigatório.',422);
        $id=apiCursor($id,0);if($id<1)throw new HttpError('message_id inválido.',422);
        $result=WhatsAppIntegrationService::markConversationRead($context,$account,$m[1],$id);
        response(200,['ok'=>true,'read_through_id'=>$result['read_through_id'],'advanced'=>$result['advanced']]);
    }

    if ($path === '/v1/messages' && $method === 'POST') {
        requireApiScope($context,'messages:send');
        WhatsAppApiSecurity::rateLimit($context,'write',30);
        $data = Rules::normaliseMessage(requestJson());
        Rules::ensureConsentAndWindow($account,$data);
        WhatsAppIntegrationService::assertApprovedTemplate($account,$data);
        if (($_GET['sync'] ?? '') === '1') {
            if (!empty($context['scoped'])) throw new HttpError('Tokens de integração usam somente envio assíncrono idempotente.',422);
            if (isset($_SERVER['HTTP_IDEMPOTENCY_KEY'])) throw new HttpError('Use async mode for Idempotency-Key',422);
            $result = Graph::call($account,'POST',$phone.'/messages',$data);
            Rules::storeOutgoing($account,$data,$result);
            audit($accountId,'message.sent',['to'=>$data['to'],'type'=>$data['type']]);
            response(200,$result);
        }
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        if($key===''&&!empty($context['scoped']))throw new HttpError('Idempotency-Key obrigatório para tokens de integração.',422);
        if($key==='')$key='auto-' . bin2hex(random_bytes(16));
        $result = Jobs::enqueueSend($account,$data,$key);
        audit($accountId,'message.queued',['job_id'=>$result['job_id'],'type'=>$data['type'],'credential_id'=>(int)$context['key_id']]);
        response(202,$result);
    }
    if ($path === '/v1/messages/read' && $method === 'POST') {
        requireApiScope($context,'messages:read_receipt');
        WhatsAppApiSecurity::rateLimit($context,'write',30);
        $data = requestJson();
        $id = $data['message_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9._:-]{5,255}$/D',$id)) throw new HttpError('Invalid message_id');
        $body = ['messaging_product'=>'whatsapp','status'=>'read','message_id'=>$id];
        if (isset($data['typing_indicator'])) $body['typing_indicator'] = $data['typing_indicator'];
        response(200,Graph::call($account,'POST',$phone.'/messages',$body));
    }
    if ($path === '/v1/messages' && $method === 'GET') {
        requireApiScope($context,'messages:read');
        $before = apiCursor($_GET['before_id']??null,PHP_INT_MAX);
        $waId=$_GET['wa_id']??null;
        if($waId!==null){
            if(!is_string($waId)||!validPhone($waId))throw new HttpError('wa_id inválido.',422);
            response(200,['data'=>WhatsAppIntegrationService::messages($context,$account,$waId,$before,limitNumber($_GET['limit']??50,100))]);
        }
        response(200,['data'=>getList('SELECT id,wa_id,direction,whatsapp_id,message_type,status,payload,meta_response,created_at
          FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND id<? ORDER BY id DESC',[$tenantId,$accountId,$before])]);
    }
    if ($path === '/v1/contacts' && $method === 'GET') {
        requireApiScope($context,'contacts:read');
        response(200,['data'=>getList('SELECT id,wa_id,display_name,opt_in_at,opt_in_source,last_inbound_at
          FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? ORDER BY id DESC',[$tenantId,$accountId])]);
    }
    if ($path === '/v1/contacts/consent' && $method === 'POST') {
        requireApiScope($context,'contacts:consent');
        WhatsAppApiSecurity::rateLimit($context,'write',30);
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
        requireApiScope($context,'events:read');
        if(isset($_GET['after_id'])||!empty($context['scoped'])){
            $after=0;
            if(isset($_GET['after_id'])){
                $after=apiCursor($_GET['after_id'],0);
            }
            response(200,WhatsAppIntegrationService::events($context,$account,$after,limitNumber($_GET['limit']??50,100)));
        }
        $before = apiCursor($_GET['before_id']??null,PHP_INT_MAX);
        response(200,['data'=>getList('SELECT id,event_key,event_type,payload,received_at
          FROM whatsapp_webhook_events WHERE tenant_id=? AND account_id=? AND id<? ORDER BY id DESC',[$tenantId,$accountId,$before])]);
    }
    if (preg_match('~^/v1/jobs/([0-9]+)$~D',$path,$m)) {
        if ($method === 'GET') {
            requireApiScope($context,'jobs:read');
            $s = db()->prepare('SELECT id,kind,idempotency_key,status,attempts,max_attempts,
                         next_attempt_at,last_error,response,created_at,updated_at FROM whatsapp_jobs WHERE id=? AND tenant_id=? AND account_id=?');
            $s->execute([$m[1],$tenantId,$accountId]);
            $job = $s->fetch();
            if (!$job) throw new HttpError('Job not found',404);
            response(200,$job);
        }
        if ($method === 'POST' && ($_GET['action'] ?? '') === 'retry') {
            requireApiScope($context,'jobs:retry');
            WhatsAppApiSecurity::rateLimit($context,'write',30);
            $s = db()->prepare("UPDATE whatsapp_jobs SET status='queued',attempts=0,next_attempt_at=UTC_TIMESTAMP(),locked_at=NULL
                  WHERE id=? AND tenant_id=? AND account_id=? AND status IN ('failed','uncertain')");
            $s->execute([$m[1],$tenantId,$accountId]);
            if (!$s->rowCount()) throw new HttpError('Job not found or not retryable',409);
            audit($accountId,'job.manual_retry',['job_id'=>(int)$m[1],'duplicate_risk'=>true]);
            response(202,['queued'=>true,'duplicate_risk'=>true]);
        }
    }
    if ($path === '/v1/media' && $method === 'POST') {
        requireApiScope($context,'media:upload');
        WhatsAppApiSecurity::rateLimit($context,'write',30);
        $media = checkUpload();
        response(200,Graph::call($account,'POST',$phone.'/media',null,[],
                ['messaging_product'=>'whatsapp','file'=>$media]));
    }
    if (preg_match('~^/v1/media/([A-Za-z0-9_-]{1,255})/content$~D',$path,$m)&&$method==='GET') {
        requireApiScope($context,'media:read');
        $media=Graph::downloadMedia($account,$m[1]);
        header('Content-Type: '.$media['mime_type']);
        header('Content-Disposition: attachment; filename="whatsapp-media"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: '.(int)$media['file_size']);
        fpassthru($media['stream']);fclose($media['stream']);exit;
    }
    if (preg_match('~^/v1/media/([0-9]+)$~D',$path,$m)) {
        if ($method === 'GET') {
            requireApiScope($context,'media:read');
            $metadata=Graph::call($account,'GET',$m[1]);
            unset($metadata['url']); // URL assinado da CDN funciona como credencial temporária.
            response(200,$metadata);
        }
        if ($method === 'DELETE') {requireApiScope($context,'media:delete');WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'DELETE',$m[1]));}
    }
    if ($path === '/v1/templates') {
        if ($method === 'GET') {
            requireApiScope($context,'templates:read');
            $name=isset($_GET['name'])&&is_string($_GET['name'])?substr($_GET['name'],0,128):null;
            $after=isset($_GET['after'])&&is_string($_GET['after'])?$_GET['after']:null;
            response(200,WhatsAppIntegrationService::templates($account,limitNumber($_GET['limit']??50,100),$name,$after));
        }
        if ($method === 'POST') {requireApiScope($context,'templates:manage');WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'POST',$waba.'/message_templates',requestJson()));}
        if ($method === 'DELETE') {
            requireApiScope($context,'templates:manage');
            WhatsAppApiSecurity::rateLimit($context,'write',30);
            $name = (string)($_GET['name'] ?? '');
            if (!preg_match('/^[a-z0-9_]{1,512}$/D',$name)) throw new HttpError('Template name required');
            response(200,Graph::call($account,'DELETE',$waba.'/message_templates',null,['name'=>$name]));
        }
    }
    if (preg_match('~^/v1/templates/([0-9]+)$~D',$path,$m) && $method === 'POST') {
        requireApiScope($context,'templates:manage');WhatsAppApiSecurity::rateLimit($context,'write',30);
        response(200,Graph::call($account,'POST',$m[1],requestJson()));
    }
    if ($path === '/v1/phone' && $method === 'GET') {
        requireApiScope($context,'meta:manage');
        response(200,Graph::call($account,'GET',$phone,null,
            ['fields'=>'display_phone_number,verified_name,quality_rating,status']));
    }
    if ($path === '/v1/phone/register' && $method === 'POST') {
        requireApiScope($context,'meta:manage');WhatsAppApiSecurity::rateLimit($context,'write',30);
        $body = requestJson();
        $pin = $body['pin'] ?? null;
        if (!is_string($pin) || !preg_match('/^[0-9]{6}$/D',$pin)) throw new HttpError('6-digit registration PIN required');
        audit($accountId,'phone.register.requested',['phone_number_id'=>$phone]);
        response(200,Graph::call($account,'POST',$phone.'/register',
            ['messaging_product'=>'whatsapp','pin'=>$pin]));
    }
    if ($path === '/v1/phone/deregister' && $method === 'POST') {
        requireApiScope($context,'meta:manage');WhatsAppApiSecurity::rateLimit($context,'write',30);
        $body = requestJson();
        if (($body['confirm'] ?? '') !== 'DEREGISTER') throw new HttpError('Explicit confirm=DEREGISTER required',422);
        audit($accountId,'phone.deregister.requested',['phone_number_id'=>$phone]);
        response(200,Graph::call($account,'POST',$phone.'/deregister'));
    }
    if ($path === '/v1/webhook-subscriptions') {
        requireApiScope($context,'meta:manage');
        if ($method === 'GET') response(200,Graph::call($account,'GET',$waba.'/subscribed_apps'));
        if ($method === 'POST') {
            WhatsAppApiSecurity::rateLimit($context,'write',30);
            audit($accountId,'waba.subscribe.requested');
            response(200,Graph::call($account,'POST',$waba.'/subscribed_apps'));
        }
        if ($method === 'DELETE') {
            WhatsAppApiSecurity::rateLimit($context,'write',30);
            audit($accountId,'waba.unsubscribe.requested');
            response(200,Graph::call($account,'DELETE',$waba.'/subscribed_apps'));
        }
    }
    if ($path === '/v1/phone-numbers' && $method === 'GET')
        {requireApiScope($context,'meta:manage');
        response(200,Graph::call($account,'GET',$waba.'/phone_numbers'));
        }
    if ($path === '/v1/business-profile') {
        requireApiScope($context,'meta:manage');
        if ($method === 'GET') response(200,Graph::call($account,'GET',$phone.'/whatsapp_business_profile',null,
            ['fields'=>'about,address,description,email,profile_picture_url,websites,vertical']));
        if ($method === 'POST') {WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'POST',$phone.'/whatsapp_business_profile',requestJson()));}
    }
    if ($path === '/v1/flows') {
        requireApiScope($context,'meta:manage');
        if ($method === 'GET') response(200,Graph::call($account,'GET',$waba.'/flows'));
        if ($method === 'POST') {WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'POST',$waba.'/flows',requestJson()));}
    }
    if (preg_match('~^/v1/flows/([0-9]+)$~D',$path,$m)) {
        requireApiScope($context,'meta:manage');
        if ($method === 'GET') response(200,Graph::call($account,'GET',$m[1]));
        if ($method === 'POST') {WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'POST',$m[1],requestJson()));}
        if ($method === 'DELETE') {WhatsAppApiSecurity::rateLimit($context,'write',30);response(200,Graph::call($account,'DELETE',$m[1]));}
    }
    if ($path === '/v1/graph' && $method === 'GET') {
        requireApiScope($context,'meta:manage');
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
