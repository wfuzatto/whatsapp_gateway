<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_Graph.php';
require_once __DIR__.'/whatsapp_Rules.php';

final class WhatsAppMobile {
    private const PREFIX='/mobile-api/v1';
    private const TOKEN_LIFETIME=604800; // sete dias; logout revoga
    private static function tables(): void {
        // Instalacao aditiva: nao altera nenhuma tabela ja existente do gateway.
        // Permissoes CREATE sao as mesmas usadas no instalador do gateway.
        $sql=file_get_contents(dirname(__DIR__).'/database/whatsapp_mobile.sql');
        if($sql===false)throw new RuntimeException('Migration unavailable');
        preg_match_all('/CREATE TABLE IF NOT EXISTS whatsapp_[a-z_]+\s*\(.*?\)\s*ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',
            $sql,$matches);
        if(count($matches[0])!==2)throw new RuntimeException('Mobile schema mismatch');
        foreach($matches[0] as $query)db()->exec($query);
    }
    private static function bodyString(array $data,string $field,int $max=300): string {
        $value=$data[$field]??null;
        if(!is_string($value)||strlen($value)>$max)throw new HttpError('Campo inválido: '.$field,422);
        return trim($value);
    }
    private static function rateLimitLogin(string $email): void {
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $hash=hash('sha256',"mobile-login|".$ip."|".$email);
        $s=db()->prepare("INSERT INTO whatsapp_signup_attempts(key_hash,action) VALUES (?,'login')");
        $s->execute([$hash]);
        $s=db()->prepare("SELECT COUNT(*) FROM whatsapp_signup_attempts WHERE key_hash=?
             AND action='login' AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR");
        $s->execute([$hash]);
        if((int)$s->fetchColumn()>12)throw new HttpError('Muitas tentativas. Tente novamente mais tarde.',429);
    }
    private static function login(): never {
        $data=requestJson();
        $email=strtolower(self::bodyString($data,'email',190));
        $password=self::bodyString($data,'password',128);
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||$password==='')throw new HttpError('Credenciais inválidas',401);
        self::rateLimitLogin($email);
        $s=db()->prepare("SELECT u.id,u.tenant_id,u.name,u.email,u.password_hash,t.slug,
            t.name AS company,s.status AS subscription_status
            FROM whatsapp_users u JOIN whatsapp_tenants t ON t.id=u.tenant_id
            LEFT JOIN whatsapp_subscriptions s ON s.tenant_id=t.id
            WHERE u.email=? AND u.status='active' AND t.status='active' LIMIT 1");
        $s->execute([$email]);$user=$s->fetch();
        if(!$user||!password_verify($password,(string)$user['password_hash'])){
            usleep(140000);
            throw new HttpError('E-mail ou senha inválidos',401);
        }
        if(($user['subscription_status']??'')!=='active')
            throw new HttpError('A assinatura do cliente ainda não está ativa.',403);
        $token='za_'.bin2hex(random_bytes(32));
        $s=db()->prepare("INSERT INTO whatsapp_mobile_sessions(tenant_id,user_id,token_hash,expires_at)
            VALUES (?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))");
        $s->execute([$user['tenant_id'],$user['id'],hash('sha256',$token)]);
        db()->prepare("UPDATE whatsapp_users SET last_login_at=UTC_TIMESTAMP() WHERE id=?")
          ->execute([$user['id']]);
        response(200,['access_token'=>$token,'token_type'=>'Bearer',
            'expires_in'=>self::TOKEN_LIFETIME,
            'user'=>['id'=>(int)$user['id'],'name'=>$user['name'],
                     'company'=>$user['company'],'tenant_slug'=>$user['slug']]]);
    }
    private static function identity(): array {
        $header=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
        if(!preg_match('/^Bearer\s+(za_[a-f0-9]{64})$/D',$header,$match))
            throw new HttpError('Sessão expirada. Faça login novamente.',401);
        $s=db()->prepare("SELECT ms.id AS session_id,ms.tenant_id,ms.user_id,
             u.name AS user_name,t.name AS tenant_name,t.slug AS tenant_slug
             FROM whatsapp_mobile_sessions ms
             JOIN whatsapp_users u ON u.id=ms.user_id AND u.tenant_id=ms.tenant_id AND u.status='active'
             JOIN whatsapp_tenants t ON t.id=ms.tenant_id AND t.status='active'
             JOIN whatsapp_subscriptions sub ON sub.tenant_id=t.id AND sub.status='active'
             WHERE ms.token_hash=? AND ms.revoked_at IS NULL
               AND ms.expires_at>UTC_TIMESTAMP() LIMIT 1");
        $s->execute([hash('sha256',$match[1])]);
        $ctx=$s->fetch();
        if(!$ctx)throw new HttpError('Sessão expirada ou cliente desativado.',401);
        return $ctx;
    }
    private static function account(array $ctx,?int $explicit=null): array {
        $tenant=(int)$ctx['tenant_id'];
        if($explicit!==null) {
            $s=db()->prepare("SELECT * FROM whatsapp_accounts
                WHERE tenant_id=? AND id=? AND status='active' LIMIT 1");
            $s->execute([$tenant,$explicit]);
            $item=$s->fetch();
            if(!$item)throw new HttpError('Número não encontrado neste cliente.',404);
            return $item;
        }
        $s=db()->prepare("SELECT * FROM whatsapp_accounts
            WHERE tenant_id=? AND status='active' ORDER BY id LIMIT 2");
        $s->execute([$tenant]);
        $items=$s->fetchAll();
        if(count($items)===0)throw new HttpError('Nenhum número WhatsApp cadastrado.',404);
        if(count($items)>1)throw new HttpError('Selecione um número WhatsApp.',422);
        return $items[0];
    }
    private static function conversation(array $ctx,string $raw): array {
        if(!preg_match('/^([1-9][0-9]*)-([0-9]{7,20})$/D',$raw,$match))
            throw new HttpError('Conversa inválida.',404);
        $account=self::account($ctx,(int)$match[1]);
        return [$account,$match[2]];
    }
    private static function preview(array $row): string {
        $raw=$row['payload']??'';
        if(is_string($raw))$raw=json_decode($raw,true);
        if(!is_array($raw))return '[Mensagem]';
        if(($row['message_type']??'')==='text')
            return substr((string)($raw['text']['body']??''),0,1200);
        if(($row['message_type']??'')==='template')
            return '[Template] '.substr((string)($raw['template']['name']??''),0,80);
        $label=(string)($row['message_type']??'mensagem');
        $caption=(string)($raw[$label]['caption']??'');
        return '['.ucfirst($label).'] '.substr($caption,0,500);
    }
    private static function accounts(array $ctx): never {
        $s=db()->prepare("SELECT id,name,waba_id,phone_number_id,status
                         FROM whatsapp_accounts WHERE tenant_id=? AND status='active' ORDER BY id");
        $s->execute([$ctx['tenant_id']]);
        response(200,['data'=>$s->fetchAll()]);
    }
    private static function listConversations(array $ctx): never {
        $tenant=(int)$ctx['tenant_id'];
        // O filtro tenant_id esta presente tanto na consulta externa quanto na subconsulta.
        $s=db()->prepare("SELECT m.id,m.account_id,m.wa_id,m.direction,m.message_type,m.payload,
                m.created_at,c.display_name,c.last_inbound_at,a.name AS account_name
             FROM whatsapp_messages m
             JOIN whatsapp_accounts a ON a.id=m.account_id AND a.tenant_id=m.tenant_id AND a.status='active'
             LEFT JOIN whatsapp_contacts c ON c.account_id=m.account_id AND c.wa_id=m.wa_id
                     AND c.tenant_id=m.tenant_id
             WHERE m.tenant_id=?
               AND m.id=(SELECT MAX(x.id) FROM whatsapp_messages x
                    WHERE x.tenant_id=m.tenant_id AND x.account_id=m.account_id AND x.wa_id=m.wa_id)
             ORDER BY m.id DESC LIMIT 120");
        $s->execute([$tenant]);$result=[];
        foreach($s->fetchAll() as $row){
            $result[]=['id'=>$row['account_id'].'-'.$row['wa_id'],
                'name'=>$row['display_name']?:$row['wa_id'],
                'phone'=>$row['wa_id'],'account_id'=>(int)$row['account_id'],
                'account_name'=>$row['account_name'],
                'last_message'=>self::preview($row),
                'updated_at'=>$row['created_at'],
                'window_open'=>self::hasWindow($row['last_inbound_at']),
                'unread_count'=>0];
        }
        response(200,['data'=>$result]);
    }
    private static function hasWindow(?string $timestamp): bool {
        return $timestamp!==null && strtotime($timestamp.' UTC')!==false &&
               strtotime($timestamp.' UTC')>=time()-86400;
    }
    private static function messages(array $ctx,string $id): never {
        [$account,$phone]=self::conversation($ctx,$id);
        $s=db()->prepare("SELECT id,direction,status,message_type,payload,created_at
            FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND wa_id=?
            ORDER BY id DESC LIMIT 100");
        $s->execute([$ctx['tenant_id'],$account['id'],$phone]);$rows=array_reverse($s->fetchAll());
        $data=[];
        foreach($rows as $row){
            $data[]=['id'=>(string)$row['id'],'direction'=>$row['direction'],
                'text'=>self::preview($row),'created_at'=>$row['created_at'],
                'status'=>$row['status'],'message_type'=>$row['message_type']];
        }
        $s=db()->prepare("SELECT last_inbound_at,display_name FROM whatsapp_contacts
            WHERE tenant_id=? AND account_id=? AND wa_id=?");
        $s->execute([$ctx['tenant_id'],$account['id'],$phone]);$contact=$s->fetch()?:[];
        response(200,['data'=>$data,'window_open'=>self::hasWindow($contact['last_inbound_at']??null),
            'contact'=>['name'=>$contact['display_name']??$phone,'phone'=>$phone],
            'account_id'=>(int)$account['id']]);
    }
    private static function createConversation(array $ctx): never {
        $data=requestJson();
        $phone=self::bodyString($data,'phone',20);
        if(!validPhone($phone))throw new HttpError('Informe o telefone com DDI, somente números.',422);
        $explicit=isset($data['account_id'])?filter_var($data['account_id'],FILTER_VALIDATE_INT):null;
        if(isset($data['account_id']) && ($explicit===false || (int)$explicit<=0))
            throw new HttpError('Número WhatsApp inválido.',422);
        $account=self::account($ctx,$explicit===null?null:(int)$explicit);
        // Cria contato, mas sem opt-in: isso nao libera marketing nem abre janela de servico.
        $s=db()->prepare("INSERT IGNORE INTO whatsapp_contacts(tenant_id,account_id,wa_id)
            VALUES (?,?,?)");
        $s->execute([$ctx['tenant_id'],$account['id'],$phone]);
        response(200,['id'=>$account['id'].'-'.$phone,'phone'=>$phone]);
    }
    private static function sendText(array $ctx,string $id): never {
        [$account,$phone]=self::conversation($ctx,$id);
        $data=requestJson();
        $text=self::bodyString($data,'text',4096);
        if($text==='')throw new HttpError('Digite uma mensagem.',422);
        $payload=Rules::normaliseMessage(['to'=>$phone,'type'=>'text','text'=>['body'=>$text]]);
        try {
            Rules::ensureConsentAndWindow($account,$payload);
        }catch(HttpError $e) {
            if($e->httpStatus===422)throw new HttpError('A janela de atendimento de 24 horas está encerrada. Use um template aprovado.',409);
            throw $e;
        }
        // Sincronico para refletir imediatamente no chat. Nao repete em falha incerta.
        $result=Graph::call($account,'POST',$account['phone_number_id'].'/messages',$payload);
        Rules::storeOutgoing($account,$payload,$result);
        audit((int)$account['id'],'mobile.text.sent',['to'=>$phone,'user_id'=>(int)$ctx['user_id']]);
        response(200,['id'=>(string)($result['messages'][0]['id']??''),'status'=>'accepted']);
    }
    private static function listTemplates(array $ctx,?int $accountId): never {
        $account=self::account($ctx,$accountId);
        $all=Graph::call($account,'GET',$account['waba_id'].'/message_templates',null,
                    ['fields'=>'name,language,category,status,components','limit'=>100]);
        $out=[];
        foreach(($all['data']??[]) as $item){
            if(($item['status']??'')==='APPROVED'){
                $components=$item['components']??[];
                $body='';$header='';$headerFormat='';
                foreach($components as $c){
                    if(($c['type']??'')==='BODY')$body=(string)($c['text']??'');
                    if(($c['type']??'')==='HEADER'){
                        $header=(string)($c['text']??'');
                        $headerFormat=(string)($c['format']??'');
                    }
                }
                // O app suporta header TEXT e BODY. Midias e OTP exigem interface especifica.
                if($headerFormat!=='' && $headerFormat!=='TEXT')continue;
                $out[]=['name'=>$item['name']??'','language'=>$item['language']??'',
                    'category'=>$item['category']??'','header'=>$header,'body'=>$body,
                    'header_variables'=>self::countVariables($header),
                    'variables'=>self::countVariables($body),'account_id'=>(int)$account['id']];
            }
        }
        response(200,['data'=>$out]);
    }
    public static function countVariables(string $body): int {
        preg_match_all('/\{\{([0-9]+)\}\}/',$body,$match);
        $vals=array_unique(array_map('intval',$match[1]));
        return count($vals);
    }
    private static function sendTemplate(array $ctx,string $id): never {
        [$account,$phone]=self::conversation($ctx,$id);
        $data=requestJson();
        $name=self::bodyString($data,'name',128);
        $language=self::bodyString($data,'language',20);
        if(!preg_match('/^[a-z0-9_]{3,128}$/D',$name) ||
            !preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D',$language))
            throw new HttpError('Nome ou idioma do template inválido.',422);
        $values=$data['parameters']??[];
        $headerValues=$data['header_parameters']??[];
        foreach(['corpo'=>$values,'cabeçalho'=>$headerValues] as $kind=>$params){
            if(!is_array($params)||!array_is_list($params)||count($params)>20)
                throw new HttpError('Parâmetros inválidos no '.$kind.'.',422);
            foreach($params as $v)if(!is_string($v)||trim($v)===''||strlen($v)>512)
                throw new HttpError('Valor de variável inválido no '.$kind.'.',422);
        }
        $s=db()->prepare("SELECT opt_in_at FROM whatsapp_contacts
            WHERE tenant_id=? AND account_id=? AND wa_id=?");
        $s->execute([$ctx['tenant_id'],$account['id'],$phone]);$contact=$s->fetch();
        if(!$contact||!$contact['opt_in_at'])
            throw new HttpError('É necessário registrar consentimento antes de enviar um template.',422);
        $templates=Graph::call($account,'GET',$account['waba_id'].'/message_templates',null,
            ['name'=>$name,'limit'=>100,'fields'=>'name,language,status,components']);
        $selected=null;
        foreach(($templates['data']??[]) as $tpl)
            if(($tpl['name']??'')===$name && ($tpl['language']??'')===$language &&
                ($tpl['status']??'')==='APPROVED'){$selected=$tpl;break;}
        if(!$selected)throw new HttpError('Template ainda não aprovado para este idioma.',409);
        $bodyText='';$headerText='';$format='';
        foreach(($selected['components']??[]) as $c){
            if(($c['type']??'')==='BODY')$bodyText=(string)($c['text']??'');
            if(($c['type']??'')==='HEADER'){
                $headerText=(string)($c['text']??'');
                $format=(string)($c['format']??'');
            }
        }
        if($format!=='' && $format!=='TEXT')
            throw new HttpError('Esse modelo exige mídia no cabeçalho; use a integração web.',422);
        if(count($values)!==self::countVariables($bodyText) ||
            count($headerValues)!==self::countVariables($headerText))
            throw new HttpError('Confira as variáveis do cabeçalho e do corpo do template aprovado.',422);
        $template=['name'=>$name,'language'=>['code'=>$language]];
        $pieces=[];
        if($headerValues)$pieces[]=['type'=>'header','parameters'=>
            array_map(static fn($v)=>['type'=>'text','text'=>$v],$headerValues)];
        if($values)$pieces[]=['type'=>'body','parameters'=>
            array_map(static fn($v)=>['type'=>'text','text'=>$v],$values)];
        if($pieces)$template['components']=$pieces;
        $payload=Rules::normaliseMessage(['to'=>$phone,'type'=>'template','template'=>$template]);
        Rules::ensureConsentAndWindow($account,$payload);
        $result=Graph::call($account,'POST',$account['phone_number_id'].'/messages',$payload);
        Rules::storeOutgoing($account,$payload,$result);
        audit((int)$account['id'],'mobile.template.sent',['name'=>$name,'to'=>$phone]);
        response(200,['id'=>(string)($result['messages'][0]['id']??''),'status'=>'accepted']);
    }
    private static function registerDevice(array $ctx): never {
        $data=requestJson();
        if(($data['platform']??'')!=='android')throw new HttpError('Plataforma inválida.',422);
        $token=self::bodyString($data,'fcm_token',1024);
        if(strlen($token)<30||!preg_match('/^[A-Za-z0-9:_-]{30,1024}$/D',$token))
            throw new HttpError('Token de dispositivo inválido.',422);
        $box=seal($token);
        $hash=hash('sha256',$token);
        $s=db()->prepare("INSERT INTO whatsapp_mobile_devices
            (tenant_id,user_id,session_id,token_hash,token_box)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE tenant_id=VALUES(tenant_id),user_id=VALUES(user_id),
            session_id=VALUES(session_id),token_box=VALUES(token_box),updated_at=UTC_TIMESTAMP()");
        $s->execute([$ctx['tenant_id'],$ctx['user_id'],$ctx['session_id'],$hash,$box]);
        response(200,['ok'=>true]);
    }
    private static function logout(array $ctx): never {
        db()->prepare("UPDATE whatsapp_mobile_sessions SET revoked_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$ctx['session_id']]);
        db()->prepare("DELETE FROM whatsapp_mobile_devices WHERE session_id=?")
            ->execute([$ctx['session_id']]);
        response(200,['ok'=>true]);
    }
    public static function route(string $path,string $method): bool {
        if($path!==self::PREFIX && !str_starts_with($path,self::PREFIX.'/'))return false;
        header('Cache-Control: no-store, private');
        header('Content-Type: application/json; charset=utf-8');
        $route=substr($path,strlen(self::PREFIX))?:'/';
        if($route==='/health' && $method==='GET')
            response(200,['ok'=>true,'service'=>'zapapi-mobile','version'=>'1.0',
                'auth'=>'Bearer','tenant_isolation'=>true]);
        if($route==='/auth/login' && $method==='GET')
            response(200,['endpoint'=>'mobile login','method'=>'POST','service'=>'zapapi-mobile']);
        if($route==='/auth/login' && $method!=='POST')
            throw new HttpError('Método não permitido.',405);
        self::tables();
        if($route==='/auth/login')self::login();
        $ctx=self::identity();
        if($route==='/auth/logout' && $method==='POST')self::logout($ctx);
        if($route==='/me' && $method==='GET')response(200,
            ['name'=>$ctx['user_name'],'company'=>$ctx['tenant_name'],'tenant_slug'=>$ctx['tenant_slug']]);
        if($route==='/accounts' && $method==='GET')self::accounts($ctx);
        if($route==='/devices' && $method==='POST')self::registerDevice($ctx);
        if($route==='/conversations'){
            if($method==='GET')self::listConversations($ctx);
            if($method==='POST')self::createConversation($ctx);
        }
        if($route==='/templates' && $method==='GET'){
            $raw=$_GET['account_id']??null;
            $number=($raw!==null&&is_string($raw)&&ctype_digit($raw))?(int)$raw:null;
            self::listTemplates($ctx,$number);
        }
        if($route==='/contacts' && $method==='GET'){
            $s=db()->prepare("SELECT c.wa_id AS phone,c.display_name AS name,c.account_id,c.last_inbound_at
             FROM whatsapp_contacts c JOIN whatsapp_accounts a
                 ON a.id=c.account_id AND a.tenant_id=c.tenant_id AND a.status='active'
             WHERE c.tenant_id=? ORDER BY c.id DESC LIMIT 200");
            $s->execute([$ctx['tenant_id']]);response(200,['data'=>$s->fetchAll()]);
        }
        if(preg_match('~^/conversations/([0-9]+-[0-9]{7,20})/messages$~D',$route,$matches)){
            if($method==='GET')self::messages($ctx,$matches[1]);
            if($method==='POST')self::sendText($ctx,$matches[1]);
        }
        if(preg_match('~^/conversations/([0-9]+-[0-9]{7,20})/templates$~D',$route,$matches)
            && $method==='POST')self::sendTemplate($ctx,$matches[1]);
        throw new HttpError('Endpoint móvel não encontrado.',404);
    }
}
