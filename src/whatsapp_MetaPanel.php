<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_Portal.php';
require_once __DIR__.'/whatsapp_Graph.php';

/**
 * Integracao Meta para usuario proprietario de um tenant ativo.
 * Credenciais Meta NUNCA sao retornadas ao navegador depois de gravadas.
 */
final class WhatsAppMetaPanel {
    private static function currentUser(): array {
        WhatsAppPortal::csrf();
        $userId=(int)($_SESSION['whatsapp_user_id']??0);
        if($userId<1) throw new HttpError('Faça login para acessar as integrações.',401);
        $s=db()->prepare("SELECT u.id,u.name,u.tenant_id,t.name AS company,t.status AS tenant_status,
            s.status AS subscription_status,p.max_numbers
            FROM whatsapp_users u JOIN whatsapp_tenants t ON t.id=u.tenant_id
            LEFT JOIN whatsapp_subscriptions s ON s.tenant_id=t.id
            LEFT JOIN whatsapp_plans p ON p.id=s.plan_id
            WHERE u.id=? AND u.status='active' AND t.status='active' LIMIT 1");
        $s->execute([$userId]);$user=$s->fetch();
        if(!$user)throw new HttpError('Sessão inválida ou cliente suspenso.',403);
        return $user;
    }
    private static function active(array $user): void {
        if(($user['subscription_status']??'')!=='active')
            throw new HttpError('A assinatura precisa estar ativa para conectar a Meta.',403);
    }
    private static function csrf(array $data): void {
        if(!is_string($data['csrf']??null) ||
            !hash_equals(WhatsAppPortal::csrf(),$data['csrf']))
            throw new HttpError('Sessão expirada. Atualize a página.',419);
    }
    private static function field(array $post,string $name,int $max=1024): string {
        if(!is_string($post[$name]??''))throw new HttpError('Campo inválido: '.$name,422);
        $value=trim($post[$name]);
        if(strlen($value)>$max)throw new HttpError('Texto muito grande: '.$name,422);
        return $value;
    }
    private static function account(int $tenantId,int $id): array {
        if($id<=0)throw new HttpError('Selecione um número conectado.',422);
        $s=db()->prepare("SELECT id,tenant_id,name,waba_id,phone_number_id,status,token_box,
                    created_at FROM whatsapp_accounts WHERE tenant_id=? AND id=? LIMIT 1");
        $s->execute([$tenantId,$id]);$a=$s->fetch();
        if(!$a)throw new HttpError('Esta conexão não pertence à sua empresa.',404);
        return $a;
    }
    private static function listAccounts(int $tenantId): array {
        $s=db()->prepare('SELECT id,name,waba_id,phone_number_id,status,created_at
                     FROM whatsapp_accounts WHERE tenant_id=? ORDER BY id DESC');
        $s->execute([$tenantId]);return $s->fetchAll();
    }
    private static function accountId(array $post): int {
        $raw=$post['account_id']??'';
        if(!is_string($raw)||!ctype_digit($raw))throw new HttpError('Número inválido.',422);
        return (int)$raw;
    }
    private static function flash(string $msg,string $type='success'): never {
        $_SESSION['whatsapp_meta_flash']=['message'=>$msg,'type'=>$type];
        header('Location: /painel/meta',true,303);exit;
    }
    private static function metaError(MetaFailure $e): HttpError {
        $error=$e->metaBody['error']??[];
        $code=(int)($error['code']??0);
        $msg=is_string($error['message']??null)?$error['message']:'Solicitação rejeitada';
        // Evita ecoar tokens ou grande volume de resposta externa.
        $msg=substr(str_replace(["\r","\n"],' ', $msg),0,220);
        return new HttpError('Meta HTTP '.$e->upstreamStatus.($code?' (código '.$code.')':'').': '.$msg,422);
    }
    private static function verifyPhone(array $credential,string $waba,string $phone): void {
        $cursor=null;$visited=[];$matched=false;
        for($page=0;$page<8;$page++){
            $query=['fields'=>'id,display_phone_number,verified_name','limit'=>100];
            if($cursor!==null)$query['after']=$cursor;
            $r=Graph::call($credential,'GET',$waba.'/phone_numbers',null,$query);
            foreach(($r['data']??[]) as $item)if((string)($item['id']??'')===$phone)$matched=true;
            if($matched)return;
            $next=$r['paging']['cursors']['after']??null;
            if(!is_string($next)||$next===''||isset($visited[$next]))break;
            $visited[$next]=true;$cursor=$next;
        }
        throw new HttpError('O Phone Number ID não foi encontrado no WABA informado (ou faltam permissões).',422);
    }
    private static function connect(array $user,array $post): never {
        self::active($user);
        $name=self::field($post,'name',120);
        $waba=self::field($post,'waba_id',40);
        $phone=self::field($post,'phone_number_id',40);
        $token=self::field($post,'access_token',4096);
        if(strlen($name)<2 || !validId($waba) || !validId($phone) ||
            strlen($token)<30 || !preg_match('/^[A-Za-z0-9._|:-]+$/D',$token))
            throw new HttpError('Confira nome, WABA ID, Phone Number ID e token de acesso.',422);
        $cred=['token_box'=>seal($token)];
        // Teste real de permissões ANTES de persistir o token.
        self::verifyPhone($cred,$waba,$phone);
        Graph::call($cred,'GET',$waba.'/message_templates',null,['limit'=>1]);
        $pdo=db();$pdo->beginTransaction();
        try{
            $tenant=(int)$user['tenant_id'];
            $s=$pdo->prepare('SELECT id FROM whatsapp_tenants WHERE id=? FOR UPDATE');
            $s->execute([$tenant]);
            $s=$pdo->prepare("SELECT s.status,p.max_numbers FROM whatsapp_subscriptions s
                   JOIN whatsapp_plans p ON p.id=s.plan_id WHERE s.tenant_id=?");
            $s->execute([$tenant]);$sub=$s->fetch();
            if(!$sub||$sub['status']!=='active')
                throw new HttpError('Sua assinatura não está ativa.',403);
            $s=$pdo->prepare('SELECT tenant_id FROM whatsapp_wabas WHERE waba_id=? FOR UPDATE');
            $s->execute([$waba]);$wabaOwner=$s->fetch();
            if($wabaOwner && (int)$wabaOwner['tenant_id']!==$tenant)
                throw new HttpError('Este WABA já está vinculado a outro cliente.',409);
            $s=$pdo->prepare('SELECT id,tenant_id FROM whatsapp_accounts WHERE phone_number_id=? FOR UPDATE');
            $s->execute([$phone]);$existing=$s->fetch();
            if($existing && (int)$existing['tenant_id']!==$tenant)
                throw new HttpError('Este número já está vinculado a outro cliente.',409);
            if(!$existing){
                $s=$pdo->prepare('SELECT COUNT(*) FROM whatsapp_accounts WHERE tenant_id=?');
                $s->execute([$tenant]);
                if((int)$s->fetchColumn()>=(int)$sub['max_numbers'])
                    throw new HttpError('Seu plano já atingiu o limite de números.',422);
            }
            if(!$wabaOwner){
                $s=$pdo->prepare('INSERT INTO whatsapp_wabas(tenant_id,waba_id) VALUES (?,?)');
                $s->execute([$tenant,$waba]);
            }
            if($existing){
                $s=$pdo->prepare('UPDATE whatsapp_accounts
                    SET name=?,waba_id=?,token_box=?,status="active" WHERE id=? AND tenant_id=?');
                $s->execute([$name,$waba,$cred['token_box'],$existing['id'],$tenant]);
                $id=(int)$existing['id'];
            }else{
                $s=$pdo->prepare('INSERT INTO whatsapp_accounts
                   (tenant_id,name,waba_id,phone_number_id,token_box)
                   VALUES (?,?,?,?,?)');
                $s->execute([$tenant,$name,$waba,$phone,$cred['token_box']]);
                $id=(int)$pdo->lastInsertId();
            }
            $pdo->commit();
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23000')
                throw new HttpError('WABA ou número já associado a outra conta.',409);
            throw $e;
        }
        audit($id,'meta.account.connected',['waba_id'=>$waba,'phone_number_id'=>$phone]);
        self::flash('Conexão validada com a Meta e token armazenado criptografado.');
    }
    public static function templatePayload(array $post): array {
        $mode=self::field($post,'template_mode',16);
        if($mode==='advanced'){
            $raw=self::field($post,'advanced_json',18000);
            try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}
            catch(JsonException $e){throw new HttpError('JSON avançado inválido.',422);}
            if(!is_array($value)||array_is_list($value))
                throw new HttpError('O template deve ser um objeto JSON.',422);
            $unknown=array_diff(array_keys($value),
                ['name','language','category','components','parameter_format','allow_category_change']);
            if($unknown)throw new HttpError('O JSON contém campos desconhecidos.',422);
        }else{
            $name=self::field($post,'template_name',128);
            $language=self::field($post,'template_language',20);
            $category=self::field($post,'template_category',30);
            $header=self::field($post,'template_header',60);
            $body=self::field($post,'template_body',1024);
            $footer=self::field($post,'template_footer',60);
            $components=[];
            if($header!==''){
                $h=['type'=>'HEADER','format'=>'TEXT','text'=>$header];
                $sample=self::samples($header,self::field($post,'header_examples',220));
                if($sample)$h['example']=['header_text'=>$sample];
                $components[]=$h;
            }
            $b=['type'=>'BODY','text'=>$body];
            $samples=self::samples($body,self::field($post,'body_examples',1500));
            if($samples)$b['example']=['body_text'=>[$samples]];
            $components[]=$b;
            if($footer!=='')$components[]=['type'=>'FOOTER','text'=>$footer];
            $value=['name'=>$name,'language'=>$language,'category'=>$category,'components'=>$components];
        }
        if(!is_string($value['name']??null) ||
            !preg_match('/^[a-z][a-z0-9_]{2,127}$/D',$value['name']))
            throw new HttpError('Nome do template: 3 a 128 caracteres minúsculos, números ou _.',422);
        if(!is_string($value['language']??null) ||
            !preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D',$value['language']))
            throw new HttpError('Idioma inválido (ex.: pt_BR).',422);
        if(!in_array($value['category']??'', ['UTILITY','MARKETING','AUTHENTICATION'],true))
            throw new HttpError('Categoria inválida.',422);
        if(!isset($value['components'])||!is_array($value['components'])||
            count($value['components'])<1||count($value['components'])>12)
            throw new HttpError('Componentes ausentes ou inválidos.',422);
        foreach($value['components'] as $component){
            if(!is_array($component)||!in_array($component['type']??'',
                ['HEADER','BODY','FOOTER','BUTTONS'],true))
                throw new HttpError('Tipo de componente de template inválido.',422);
        }
        if($mode!=='advanced'&&$value['category']==='AUTHENTICATION')
            throw new HttpError('Templates de autenticação OTP exigem o editor JSON avançado.',422);
        return $value;
    }
    private static function samples(string $text,string $raw): array {
        preg_match_all('/\{\{([0-9]+)\}\}/',$text,$matches);
        $ids=array_unique(array_map('intval',$matches[1]));
        sort($ids);
        if(!$ids){
            if($raw!=='')throw new HttpError('Remova exemplos sem variáveis no texto.',422);
            return [];
        }
        if($ids!==range(1,count($ids))||count($ids)>20)
            throw new HttpError('Use variáveis sequenciais: {{1}}, {{2}}, etc.',422);
        $examples=array_map('trim',preg_split('/\r?\n/',$raw));
        if(count($examples)!==count($ids))
            throw new HttpError('Informe um exemplo por variável, um em cada linha.',422);
        foreach($examples as $example)if($example===''||strlen($example)>120)
            throw new HttpError('Cada exemplo deve ter de 1 a 120 caracteres.',422);
        return $examples;
    }
    private static function submitTemplate(array $user,array $post): never {
        self::active($user);
        $a=self::account((int)$user['tenant_id'],self::accountId($post));
        if($a['status']!=='active')throw new HttpError('Conexão suspensa.',403);
        $payload=self::templatePayload($post);
        $r=Graph::call($a,'POST',$a['waba_id'].'/message_templates',$payload);
        audit((int)$a['id'],'meta.template.submitted',[
            'name'=>$payload['name'],'language'=>$payload['language'],
            'category'=>$payload['category'],'meta_id'=>$r['id']??null,
            'status'=>$r['status']??'PENDING'
        ]);
        self::flash('Template '.$payload['name'].' enviado à Meta. Status: '.
            (string)($r['status']??'aguardando análise').'. Atualize a lista para consultar a aprovação.');
    }
    private static function templateList(array $account,string $after=''): array {
        $q=['fields'=>'id,name,language,category,status,rejected_reason,components,quality_score',
            'limit'=>50];
        if($after!=='')$q['after']=$after;
        return Graph::call($account,'GET',$account['waba_id'].'/message_templates',null,$q);
    }
    private static function statusCheck(array $user,array $post): never {
        $a=self::account((int)$user['tenant_id'],self::accountId($post));
        self::verifyPhone($a,$a['waba_id'],$a['phone_number_id']);
        Graph::call($a,'GET',$a['waba_id'].'/message_templates',null,['limit'=>1]);
        self::flash('Conexão com a Meta validada. WABA, número e acesso aos templates estão autorizados.');
    }
    private static function subscribe(array $user,array $post): never {
        self::active($user);
        $a=self::account((int)$user['tenant_id'],self::accountId($post));
        // Inscreve o aplicativo associado ao token no WABA quando permitido pela Meta.
        $r=Graph::call($a,'POST',$a['waba_id'].'/subscribed_apps');
        audit((int)$a['id'],'meta.webhook.subscribe',['waba_id'=>$a['waba_id']]);
        self::flash('Solicitação de inscrição no webhook enviada à Meta. Confira no painel da Meta.');
    }
    private static function createKey(array $user,array $post): never {
        self::active($user);
        $name=self::field($post,'key_name',100);
        $id=self::accountId($post);
        if(strlen($name)<3)throw new HttpError('Informe um nome para a chave (mínimo 3 caracteres).',422);
        $a=self::account((int)$user['tenant_id'],$id);
        $raw='wg_'.bin2hex(random_bytes(32));
        $s=db()->prepare('INSERT INTO whatsapp_api_keys (tenant_id,account_id,name,token_hash) VALUES (?,?,?,?)');
        $s->execute([$user['tenant_id'],$id,$name,hash('sha256',$raw)]);
        audit($id,'api.key.created',['name'=>$name,'key_id'=>(int)db()->lastInsertId()]);
        $_SESSION['whatsapp_meta_one_time_key']=$raw;
        self::flash('Chave criada. Copie-a agora; não será exibida novamente.');
    }
    private static function revokeKey(array $user,array $post): never {
        $key=self::field($post,'key_id',20);
        if(!ctype_digit($key))throw new HttpError('Chave inválida.',422);
        $s=db()->prepare('UPDATE whatsapp_api_keys SET active=0
                           WHERE tenant_id=? AND id=? AND active=1');
        $s->execute([$user['tenant_id'],$key]);
        if(!$s->rowCount())throw new HttpError('Chave não encontrada.',404);
        self::flash('Chave de API revogada.');
    }
    private static function render(array $user,array $vars=[]): void {
        $tenantId=(int)$user['tenant_id'];
        $accounts=self::listAccounts($tenantId);
        $selectedId=(int)($_GET['conta']??0);
        if(!$selectedId && $accounts)$selectedId=(int)$accounts[0]['id'];
        $current=null;
        foreach($accounts as $a)if((int)$a['id']===$selectedId)$current=$a;
        $templateData=['data'=>[]];
        $listError=null;
        if($current){
            try{
                $after=(string)($_GET['after']??'');
                if(strlen($after)>600)$after='';
                $s=self::account($tenantId,(int)$current['id']);
                $templateData=self::templateList($s,$after);
            }catch(MetaFailure $e){$listError=self::metaError($e)->getMessage();}
            catch(Throwable $e){$listError='Não foi possível consultar a Meta. Confira token e permissões.';}
        }
        $keysSt=db()->prepare('SELECT k.id,k.name,k.active,k.created_at,a.name AS account_name
            FROM whatsapp_api_keys k LEFT JOIN whatsapp_accounts a
            ON a.id=k.account_id AND a.tenant_id=k.tenant_id
            WHERE k.tenant_id=? ORDER BY k.id DESC LIMIT 50');
        $keysSt->execute([$tenantId]);$keys=$keysSt->fetchAll();
        $flash=$_SESSION['whatsapp_meta_flash']??null;
        unset($_SESSION['whatsapp_meta_flash']);
        $oneTimeKey=$_SESSION['whatsapp_meta_one_time_key']??null;
        unset($_SESSION['whatsapp_meta_one_time_key']);
        $csrf=WhatsAppPortal::csrf();
        if(!defined('WHATSAPP_META_PANEL_VIEW'))define('WHATSAPP_META_PANEL_VIEW',true);
        require dirname(__DIR__).'/public/whatsapp_meta_panel.php';
    }
    public static function route(string $path,string $method): bool {
        if($path!=='/painel/meta')return false;
        try{
            $user=self::currentUser();
            if($method==='POST'){
                self::csrf($_POST);
                $action=self::field($_POST,'action',30);
                switch($action){
                    case 'connect':self::connect($user,$_POST);
                    case 'submit':self::submitTemplate($user,$_POST);
                    case 'check':self::statusCheck($user,$_POST);
                    case 'subscribe':self::subscribe($user,$_POST);
                    case 'key_create':self::createKey($user,$_POST);
                    case 'key_revoke':self::revokeKey($user,$_POST);
                    default:throw new HttpError('Ação desconhecida.',422);
                }
            }
            if($method!=='GET')throw new HttpError('Método não permitido.',405);
            self::render($user);return true;
        }catch(HttpError $e){
            http_response_code($e->httpStatus);
            if($e->httpStatus===401){header('Location: /entrar',true,303);exit;}
            if($e->httpStatus===403 && empty($_SESSION['whatsapp_user_id'])){
                header('Location: /entrar',true,303);exit;
            }
            try{
                $user=self::currentUser();
                self::render($user,['error'=>$e->getMessage()]);
            }catch(Throwable $ignored){response($e->httpStatus,['error'=>$e->getMessage()]);}
            return true;
        }catch(MetaFailure $e){
            http_response_code(422);
            $user=self::currentUser();
            self::render($user,['error'=>self::metaError($e)->getMessage()]);
            return true;
        }catch(Throwable $e){
            error_log('Meta panel failure: '.get_class($e));
            http_response_code(503);
            try{$user=self::currentUser();self::render($user,
                ['error'=>'Não foi possível concluir a operação. Verifique a conexão e tente novamente.']);}
            catch(Throwable $ignored){response(503,['error'=>'Erro interno']);}
            return true;
        }
    }
}
