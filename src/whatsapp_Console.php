<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_Graph.php';
require_once __DIR__.'/whatsapp_Rules.php';
require_once __DIR__.'/whatsapp_Jobs.php';
require_once __DIR__.'/whatsapp_Portal.php';
require_once __DIR__.'/whatsapp_Campaigns.php';

final class WhatsAppConsole {
    private static function redirect(string $path): never { header('Location: '.$path,true,303);exit; }
    private static function csrf(): string { return WhatsAppPortal::csrf(); }
    private static function user(): array {
        self::csrf();
        $id=(int)($_SESSION['whatsapp_user_id']??0);
        if($id<1)self::redirect('/entrar');
        $s=db()->prepare('SELECT u.id,u.name,u.email,u.tenant_id,t.name AS company,t.slug,
            sub.status AS subscription_status,p.title AS plan_title,p.max_numbers
            FROM whatsapp_users u JOIN whatsapp_tenants t ON t.id=u.tenant_id
            LEFT JOIN whatsapp_subscriptions sub ON sub.tenant_id=t.id
            LEFT JOIN whatsapp_plans p ON p.id=sub.plan_id
            WHERE u.id=? AND u.status="active" AND t.status="active" LIMIT 1');
        $s->execute([$id]);$user=$s->fetch();
        if(!$user){unset($_SESSION['whatsapp_user_id']);self::redirect('/entrar');}
        return $user;
    }
    private static function active(array $user): void {
        if(($user['subscription_status']??'')!=='active')
            throw new HttpError('A assinatura ainda não está ativa. Fale com o administrador da ZapApi.',403);
    }
    private static function csrfCheck(array $post): void {
        $token=$post['csrf']??null;
        if(!is_string($token)||!hash_equals(self::csrf(),$token))
            throw new HttpError('Sessão expirada. Atualize a página e tente novamente.',419);
    }
    private static function field(array $data,string $name,int $max=2048): string {
        $value=$data[$name]??'';
        if(!is_string($value))throw new HttpError('Dados do formulário inválidos.',422);
        $value=trim($value);
        if(strlen($value)>$max)throw new HttpError('Um dos campos excedeu o limite permitido.',422);
        return $value;
    }
    private static function accounts(int $tenantId,bool $activeOnly=false): array {
        $sql='SELECT id,name,waba_id,phone_number_id,status FROM whatsapp_accounts WHERE tenant_id=?';
        if($activeOnly)$sql.=' AND status="active"';
        $sql.=' ORDER BY id DESC';
        $s=db()->prepare($sql);$s->execute([$tenantId]);return $s->fetchAll();
    }
    private static function account(int $tenantId,int $id): array {
        if($id<1)throw new HttpError('Selecione uma conexão Meta.',422);
        $s=db()->prepare('SELECT * FROM whatsapp_accounts WHERE tenant_id=? AND id=? LIMIT 1');
        $s->execute([$tenantId,$id]);$account=$s->fetch();
        if(!$account)throw new HttpError('Conexão não encontrada nesta empresa.',404);
        if($account['status']!=='active')throw new HttpError('Esta conexão Meta está suspensa.',403);
        return $account;
    }
    private static function metaTemplates(array $account): array {
        $query=['fields'=>'id,name,language,category,status,rejected_reason,components,quality_score','limit'=>100];
        $templates=[];$seen=[];
        for($page=0;$page<10;$page++) {
            $result=Graph::call($account,'GET',$account['waba_id'].'/message_templates',null,$query);
            $items=$result['data']??[];
            if(!is_array($items))break;
            foreach($items as $item)if(is_array($item))$templates[]=$item;
            $cursor=$result['paging']['cursors']['after']??null;
            if(!is_string($cursor)||$cursor===''||isset($seen[$cursor])||count($items)<100)break;
            $seen[$cursor]=true;$query['after']=$cursor;
        }
        return $templates;
    }
    private static function variableCount(string $text): int {
        preg_match_all('/\{\{([0-9]+)\}\}/',$text,$matches);
        $ids=array_values(array_unique(array_map('intval',$matches[1]??[])));
        sort($ids);
        if(!$ids)return 0;
        if($ids!==range(1,count($ids))||count($ids)>20)
            throw new HttpError('O template contém variáveis não sequenciais e não pode ser enviado por esta tela.',422);
        return count($ids);
    }
    public static function templateInfo(array $template): array {
        $headerText='';$headerFormat='';$bodyText='';$headerVars=0;$bodyVars=0;$otherVars=false;
        foreach(($template['components']??[]) as $component) {
            if(!is_array($component))continue;
            $type=strtoupper((string)($component['type']??''));
            if($type==='HEADER') {
                $headerFormat=strtoupper((string)($component['format']??''));
                $headerText=(string)($component['text']??'');
                if($headerFormat==='TEXT')$headerVars=self::variableCount($headerText);
            } elseif($type==='BODY') {
                $bodyText=(string)($component['text']??'');
                $bodyVars=self::variableCount($bodyText);
            } elseif($type==='BUTTONS') {
                foreach(($component['buttons']??[]) as $button) {
                    if(!is_array($button))continue;
                    foreach(['text','url','phone_number'] as $field) {
                        $v=(string)($button[$field]??'');
                        if(str_contains($v,'{{'))$otherVars=true;
                    }
                }
            }
        }
        $supported=($headerFormat===''||$headerFormat==='TEXT')&&!$otherVars;
        return ['header_text'=>$headerText,'header_format'=>$headerFormat,'body_text'=>$bodyText,
            'header_vars'=>$headerVars,'body_vars'=>$bodyVars,'supported'=>$supported];
    }
    private static function templatesForPage(array $account,string $page): array {
        $items=self::metaTemplates($account);
        foreach($items as &$item)$item+=self::templateInfo($item);
        unset($item);
        if($page==='campaigns')$items=array_values(array_filter($items,static fn($t)=>
            ($t['status']??'')==='APPROVED'&&$t['supported']&&($t['header_vars']??0)===0&&($t['body_vars']??0)===0));
        if($page==='new')$items=array_values(array_filter($items,static fn($t)=>(($t['status']??'')==='APPROVED')&&$t['supported']));
        return $items;
    }
    private static function approvedTemplate(array $account,string $name,string $language): array {
        if(!preg_match('/^[a-z][a-z0-9_]{2,127}$/D',$name)||!preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D',$language))
            throw new HttpError('Template ou idioma inválido.',422);
        $items=self::metaTemplates($account);
        foreach($items as $item)if(($item['name']??'')===$name&&($item['language']??'')===$language&&($item['status']??'')==='APPROVED') {
            $item+=self::templateInfo($item);
            return $item;
        }
        throw new HttpError('A Meta não informa este template como aprovado para a conexão selecionada.',422);
    }
    private static function templatePayload(array $template,array $post,string $to): array {
        $info=self::templateInfo($template);
        if(!$info['supported'])throw new HttpError('Este template usa cabeçalho de mídia ou botão variável não suportado nesta ação.',422);
        $components=[];
        foreach([['header_vars','header_params','header'],['body_vars','body_params','body']] as [$countKey,$postKey,$type]) {
            $count=(int)$info[$countKey];
            if($count===0)continue;
            $raw=$post[$postKey]??[];
            if(!is_array($raw)||count($raw)!==$count)throw new HttpError('Preencha todos os campos variáveis do template.',422);
            $params=[];
            for($i=0;$i<$count;$i++) {
                $value=$raw[$i]??null;
                if(!is_string($value)||trim($value)===''||strlen($value)>1024)
                    throw new HttpError('Cada variável deve ter de 1 a 1.024 caracteres.',422);
                $params[]=['type'=>'text','text'=>trim($value)];
            }
            $components[]=['type'=>$type,'parameters'=>$params];
        }
        $data=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'template',
            'template'=>['name'=>$template['name'],'language'=>['code'=>$template['language']]]];
        if($components)$data['template']['components']=$components;
        return $data;
    }
    private static function flash(string $message,string $type='success'): void {
        $_SESSION['whatsapp_console_flash']=['message'=>$message,'type'=>$type];
    }
    private static function listConversations(int $tenantId,int $accountId,string $search,string $filter): array {
        $sql='SELECT m.account_id,m.wa_id,a.name AS account_name,ct.display_name,ct.opt_in_at,ct.last_inbound_at,
            m.id AS message_id,m.direction,m.message_type,m.status AS message_status,m.payload,m.created_at,
            cv.status AS conversation_status,cv.closed_at
            FROM whatsapp_messages m
            JOIN (SELECT account_id,wa_id,MAX(id) AS last_id FROM whatsapp_messages WHERE tenant_id=? GROUP BY account_id,wa_id) latest
                ON latest.last_id=m.id
            JOIN whatsapp_accounts a ON a.id=m.account_id AND a.tenant_id=m.tenant_id AND a.status="active"
            LEFT JOIN whatsapp_contacts ct ON ct.tenant_id=m.tenant_id AND ct.account_id=m.account_id AND ct.wa_id=m.wa_id
            LEFT JOIN whatsapp_conversations cv ON cv.tenant_id=m.tenant_id AND cv.account_id=m.account_id AND cv.wa_id=m.wa_id
            WHERE m.tenant_id=?';
        $args=[$tenantId,$tenantId];
        if($accountId>0){$sql.=' AND m.account_id=?';$args[]=$accountId;}
        if($search!==''){$sql.=' AND (m.wa_id LIKE ? OR ct.display_name LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        $sql.=' ORDER BY m.id DESC LIMIT 150';
        $s=db()->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
        $rows=array_values(array_filter($rows,static function($row) use($filter){
            $closed=($row['conversation_status']??'')==='closed';
            if($closed&&!empty($row['last_inbound_at'])&&!empty($row['closed_at']))
                $closed=strtotime($row['last_inbound_at'].' UTC')<=strtotime($row['closed_at'].' UTC');
            if($filter==='closed')return $closed;
            if($filter==='open')return !$closed;
            return true;
        }));
        foreach($rows as &$row)$row['preview']=self::messagePreview($row['payload'],$row['message_type']);
        unset($row);
        return $rows;
    }
    private static function messagePreview(string $raw,string $type): string {
        $payload=json_decode($raw,true);if(!is_array($payload))return ucfirst($type);
        $typed=is_array($payload[$type]??null)?$payload[$type]:[];
        $text=$payload['text']['body']??$typed['caption']??$payload['template']['name']??$payload['button']['text']??
            $payload['interactive']['button_reply']['title']??$payload['interactive']['list_reply']['title']??null;
        if(is_string($text)&&$text!=='')return substr($text,0,180);
        return match($type){'image'=>'Imagem','video'=>'Vídeo','audio'=>'Áudio','document'=>'Documento','sticker'=>'Figurinha',default=>'Mensagem '.ucfirst($type)};
    }
    private static function messages(int $tenantId,int $accountId,string $waId): array {
        $s=db()->prepare('SELECT id,direction,message_type,status,payload,created_at FROM whatsapp_messages
            WHERE tenant_id=? AND account_id=? AND wa_id=? ORDER BY id DESC LIMIT 100');
        $s->execute([$tenantId,$accountId,$waId]);$rows=array_reverse($s->fetchAll());
        foreach($rows as &$row) {
            $payload=json_decode((string)$row['payload'],true);if(!is_array($payload))$payload=[];
            $row['text']=self::messagePreview((string)$row['payload'],(string)$row['message_type']);
            $row['media_id']=null;$type=(string)$row['message_type'];
            if(in_array($type,['image','video','audio','document'],true)) {
                $id=$payload[$type]['id']??null;if(is_string($id)&&validId($id))$row['media_id']=$id;
            }
            $row['caption']=$payload[$type]['caption']??'';
            $row['payload_data']=$payload;
        }
        unset($row);return $rows;
    }
    private static function conversationsState(int $tenantId,int $accountId,string $waId): array {
        $s=db()->prepare('SELECT status,closed_at FROM whatsapp_conversations WHERE tenant_id=? AND account_id=? AND wa_id=? LIMIT 1');
        $s->execute([$tenantId,$accountId,$waId]);$state=$s->fetch()?:['status'=>'open','closed_at'=>null];
        if($state['status']==='closed') {
            $s=db()->prepare('SELECT last_inbound_at FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? AND wa_id=?');
            $s->execute([$tenantId,$accountId,$waId]);$lastInbound=$s->fetchColumn();
            if($lastInbound&&$state['closed_at']&&strtotime($lastInbound.' UTC')>strtotime($state['closed_at'].' UTC'))
                $state=['status'=>'open','closed_at'=>null];
        }
        return $state;
    }
    private static function render(array $user,string $page,array $vars=[]): void {
        WhatsAppCampaigns::ensureSchema();
        $tenantId=(int)$user['tenant_id'];$data=$vars;
        $data['csrf']=self::csrf();$data['accounts']=self::accounts($tenantId);
        $selected=(int)($vars['account_id']??($vars['old']['account_id']??($_GET['account_id']??0)));
        if(!$selected&&$data['accounts'])$selected=(int)$data['accounts'][0]['id'];
        $data['selected_account_id']=$selected;
        $data['selected_account']=null;
        foreach($data['accounts'] as $account)if((int)$account['id']===$selected)$data['selected_account']=$account;
        if($selected&&!$data['selected_account'])throw new HttpError('Conexão não encontrada nesta empresa.',404);
        $data['templates']=[];$data['template_error']=null;
        if($data['selected_account']&&in_array($page,['new','templates','campaigns'],true)) {
            try {$data['templates']=self::templatesForPage(self::account($tenantId,$selected),$page);}
            catch(MetaFailure $e){$data['template_error']='A Meta não respondeu. Verifique a conexão e tente novamente.';}
            catch(Throwable $e){$data['template_error']='Não foi possível consultar os templates desta conexão.';}
        }
        if($page==='inbox') {
            $data['search']=self::field($_GET,'q',100);$data['filter']=(string)($_GET['status']??'all');
            if(!in_array($data['filter'],['all','open','closed'],true))$data['filter']='all';
            $data['conversations']=self::listConversations($tenantId,$selected,$data['search'],$data['filter']);
            $waId=self::field($_GET,'wa_id',32);
            if($waId!==''&&!validPhone($waId))throw new HttpError('Número de conversa inválido.',422);
            $data['selected_wa_id']=$waId;$data['contact']=null;$data['messages']=[];
            $data['conversation_state']=['status'=>'open','closed_at'=>null];
            if($waId!==''&&$selected) {
                $s=db()->prepare('SELECT wa_id,display_name,opt_in_at,opt_in_source,last_inbound_at
                    FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? AND wa_id=? LIMIT 1');
                $s->execute([$tenantId,$selected,$waId]);$data['contact']=$s->fetch();
                if(!$data['contact'])throw new HttpError('Conversa não encontrada nesta empresa.',404);
                $data['messages']=self::messages($tenantId,$selected,$waId);
                $data['conversation_state']=self::conversationsState($tenantId,$selected,$waId);
            }
            $s=db()->prepare('SELECT id,name,body FROM whatsapp_quick_replies WHERE tenant_id=? ORDER BY name LIMIT 100');
            $s->execute([$tenantId]);$data['quick_replies']=$s->fetchAll();
        } elseif($page==='contacts') {
            $search=self::field($_GET,'q',100);$data['search']=$search;
            $sql='SELECT id,account_id,wa_id,display_name,opt_in_at,opt_in_source,last_inbound_at FROM whatsapp_contacts WHERE tenant_id=?';$args=[$tenantId];
            if($selected){$sql.=' AND account_id=?';$args[]=$selected;}
            if($search!==''){$sql.=' AND (wa_id LIKE ? OR display_name LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
            $sql.=' ORDER BY COALESCE(last_inbound_at,created_at) DESC LIMIT 500';
            $s=db()->prepare($sql);$s->execute($args);$data['contacts']=$s->fetchAll();
        } elseif($page==='campaigns') {
            $data['eligible_contacts']=[];
            if($selected) {
                $s=db()->prepare('SELECT id,wa_id,display_name,opt_in_source FROM whatsapp_contacts
                    WHERE tenant_id=? AND account_id=? AND opt_in_at IS NOT NULL ORDER BY display_name,wa_id LIMIT 1000');
                $s->execute([$tenantId,$selected]);$data['eligible_contacts']=$s->fetchAll();
            }
            $s=db()->prepare('SELECT c.*,
                SUM(r.status IN ("pending","queued")) AS pending_count,
                SUM(r.status="accepted") AS accepted_count,
                SUM(r.status="failed") AS failed_count,
                SUM(r.status="uncertain") AS uncertain_count,
                SUM(r.status="excluded") AS excluded_count
                FROM whatsapp_campaigns c LEFT JOIN whatsapp_campaign_recipients r
                    ON r.tenant_id=c.tenant_id AND r.campaign_id=c.id
                WHERE c.tenant_id=? GROUP BY c.id ORDER BY c.id DESC LIMIT 100');
            $s->execute([$tenantId]);$data['campaigns']=$s->fetchAll();
        } elseif($page==='quick_replies') {
            $s=db()->prepare('SELECT id,name,body,created_at FROM whatsapp_quick_replies WHERE tenant_id=? ORDER BY name LIMIT 200');
            $s->execute([$tenantId]);$data['quick_replies']=$s->fetchAll();
        }
        $data['flash']=$_SESSION['whatsapp_console_flash']??null;unset($_SESSION['whatsapp_console_flash']);
        $user['tenant_id']=$tenantId;
        $mode=$page;
        if(!defined('WHATSAPP_CONSOLE_VIEW'))define('WHATSAPP_CONSOLE_VIEW',true);
        require dirname(__DIR__).'/public/whatsapp_console.php';
    }

    private static function saveContact(array $user): never {
        self::active($user);
        $tenantId=(int)$user['tenant_id'];$accountId=(int)self::field($_POST,'account_id',20);
        $account=self::account($tenantId,$accountId);
        $waId=self::field($_POST,'wa_id',32);$name=self::field($_POST,'display_name',255);
        if(!validPhone($waId))throw new HttpError('Informe o WhatsApp com DDI e somente números.',422);
        $consent=($_POST['consent']??'')==='1';$source=self::field($_POST,'opt_in_source',255);
        if($consent&&strlen($source)<4)throw new HttpError('Registre a origem do consentimento (mínimo 4 caracteres).',422);
        $at=$consent?gmdate('Y-m-d H:i:s'):null;
        $s=db()->prepare('INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id,display_name,opt_in_at,opt_in_source)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
            display_name=COALESCE(NULLIF(VALUES(display_name),""),display_name),
            opt_in_at=IF(VALUES(opt_in_at) IS NULL,opt_in_at,VALUES(opt_in_at)),
            opt_in_source=IF(VALUES(opt_in_at) IS NULL,opt_in_source,VALUES(opt_in_source))');
        $s->execute([$tenantId,$accountId,$waId,$name?:null,$at,$consent?$source:null]);
        audit($accountId,$consent?'contact.optin':'contact.saved',['wa_id'=>$waId,'source'=>$consent?$source:null]);
        self::flash($consent?'Contato salvo com consentimento registrado.':'Contato salvo. Sem consentimento, a plataforma não enviará templates ou campanhas.');
        self::redirect('/painel/contatos?account_id='.$accountId);
    }

    private static function optOut(array $user): never {
        $tenantId=(int)$user['tenant_id'];
        $account=self::account($tenantId,(int)self::field($_POST,'account_id',20));
        $waId=self::field($_POST,'wa_id',32);if(!validPhone($waId))throw new HttpError('Contato inválido.',422);
        $s=db()->prepare('UPDATE whatsapp_contacts SET opt_in_at=NULL,opt_in_source=NULL WHERE tenant_id=? AND account_id=? AND wa_id=?');
        $s->execute([$tenantId,$account['id'],$waId]);
        audit((int)$account['id'],'contact.optout',['wa_id'=>$waId]);
        self::flash('Consentimento removido. O contato não poderá receber templates ou campanhas.');
        self::redirect('/painel/contatos?account_id='.(int)$account['id']);
    }

    private static function startConversation(array $user): never {
        self::active($user);$tenantId=(int)$user['tenant_id'];
        $account=self::account($tenantId,(int)self::field($_POST,'account_id',20));
        $waId=self::field($_POST,'wa_id',32);$name=self::field($_POST,'display_name',255);
        if(!validPhone($waId))throw new HttpError('Informe o WhatsApp com DDI e somente números.',422);
        if(($_POST['consent']??'')!=='1')throw new HttpError('Confirme que o contato autorizou o recebimento de mensagens.',422);
        $source=self::field($_POST,'opt_in_source',255);
        if(strlen($source)<4)throw new HttpError('Registre a origem do consentimento (mínimo 4 caracteres).',422);
        $template=self::approvedTemplate($account,self::field($_POST,'template_name',128),self::field($_POST,'template_language',20));
        $data=self::templatePayload($template,$_POST,$waId);
        $s=db()->prepare('INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id,display_name,opt_in_at,opt_in_source)
            VALUES (?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE
            display_name=COALESCE(NULLIF(VALUES(display_name),""),display_name),
            opt_in_at=VALUES(opt_in_at),opt_in_source=VALUES(opt_in_source)');
        $s->execute([$tenantId,$account['id'],$waId,$name?:null,$source]);
        Rules::ensureConsentAndWindow($account,$data);
        $result=Graph::call($account,'POST',$account['phone_number_id'].'/messages',$data);
        Rules::storeOutgoing($account,$data,$result);
        db()->prepare('INSERT INTO whatsapp_conversations(tenant_id,account_id,wa_id,status)
            VALUES (?,?,?,"open") ON DUPLICATE KEY UPDATE status="open",closed_at=NULL,updated_at=UTC_TIMESTAMP()')
            ->execute([$tenantId,$account['id'],$waId]);
        audit((int)$account['id'],'message.template.sent',['to'=>$waId,'template'=>$template['name'],'source'=>$source]);
        self::flash('Conversa iniciada com o template aprovado “'.$template['name'].'”.');
        self::redirect('/painel/conversas?account_id='.(int)$account['id'].'&wa_id='.rawurlencode($waId));
    }

    private static function sendMessage(array $user): never {
        self::active($user);$tenantId=(int)$user['tenant_id'];
        $account=self::account($tenantId,(int)self::field($_POST,'account_id',20));
        $waId=self::field($_POST,'wa_id',32);if(!validPhone($waId))throw new HttpError('Conversa inválida.',422);
        $state=self::conversationsState($tenantId,(int)$account['id'],$waId);
        if($state['status']==='closed')throw new HttpError('Reabra a conversa antes de responder.',409);
        $file=$_FILES['file']??null;$body=self::field($_POST,'body',4096);
        $data=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$waId];
        if(is_array($file)&&($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
            if(($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file((string)($file['tmp_name']??'')))
                throw new HttpError('Não foi possível receber o anexo.',422);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $map=['image/jpeg'=>'image','image/png'=>'image','video/mp4'=>'video','video/3gpp'=>'video',
                'audio/aac'=>'audio','audio/mp4'=>'audio','audio/mpeg'=>'audio','audio/amr'=>'audio','audio/ogg'=>'audio',
                'application/pdf'=>'document'];
            if(!is_string($mime)||!isset($map[$mime]))throw new HttpError('Tipo de anexo não suportado.',422);
            $max=$map[$mime]==='image'?5*1024*1024:16*1024*1024;
            if((int)$file['size']<1||(int)$file['size']>$max)throw new HttpError('O anexo excede o limite para esse tipo de arquivo.',413);
            $name=basename((string)($file['name']??'arquivo'));
            $upload=Graph::call($account,'POST',$account['phone_number_id'].'/media',null,[],[
                'messaging_product'=>'whatsapp','file'=>new CURLFile($file['tmp_name'],$mime,$name)]);
            $mediaId=$upload['id']??null;if(!is_string($mediaId)||!validId($mediaId))throw new HttpError('A Meta não retornou um identificador para o anexo.',502);
            $type=$map[$mime];$data['type']=$type;$data[$type]=['id'=>$mediaId];
            if($body!==''&&$type!=='audio')$data[$type]['caption']=$body;
        } else {
            if($body==='')throw new HttpError('Digite uma mensagem ou selecione um anexo.',422);
            $data['type']='text';$data['text']=['preview_url'=>false,'body'=>$body];
        }
        $data=Rules::normaliseMessage($data);Rules::ensureConsentAndWindow($account,$data);
        $result=Graph::call($account,'POST',$account['phone_number_id'].'/messages',$data);
        Rules::storeOutgoing($account,$data,$result);
        db()->prepare('INSERT INTO whatsapp_conversations(tenant_id,account_id,wa_id,status)
            VALUES (?,?,?,"open") ON DUPLICATE KEY UPDATE status="open",closed_at=NULL,updated_at=UTC_TIMESTAMP()')
            ->execute([$tenantId,$account['id'],$waId]);
        audit((int)$account['id'],'message.sent',['to'=>$waId,'type'=>$data['type']]);
        self::flash('Mensagem enviada à Meta.');
        self::redirect('/painel/conversas?account_id='.(int)$account['id'].'&wa_id='.rawurlencode($waId));
    }

    private static function setConversationStatus(array $user): never {
        $tenantId=(int)$user['tenant_id'];$account=self::account($tenantId,(int)self::field($_POST,'account_id',20));
        $waId=self::field($_POST,'wa_id',32);$status=self::field($_POST,'status',10);
        if(!validPhone($waId)||!in_array($status,['open','closed'],true))throw new HttpError('Conversa inválida.',422);
        db()->prepare('INSERT INTO whatsapp_conversations(tenant_id,account_id,wa_id,status,closed_at)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),closed_at=VALUES(closed_at),updated_at=UTC_TIMESTAMP()')
            ->execute([$tenantId,$account['id'],$waId,$status,$status==='closed'?gmdate('Y-m-d H:i:s'):null]);
        audit((int)$account['id'],$status==='closed'?'conversation.closed':'conversation.reopened',['wa_id'=>$waId]);
        self::flash($status==='closed'?'Conversa encerrada.':'Conversa reaberta.');
        self::redirect('/painel/conversas?account_id='.(int)$account['id'].'&wa_id='.rawurlencode($waId));
    }

    private static function createCampaign(array $user): never {
        self::active($user);$tenantId=(int)$user['tenant_id'];
        $account=self::account($tenantId,(int)self::field($_POST,'account_id',20));
        $name=self::field($_POST,'name',120);if(strlen($name)<3)throw new HttpError('O nome da campanha deve ter ao menos 3 caracteres.',422);
        $template=self::approvedTemplate($account,self::field($_POST,'template_name',128),self::field($_POST,'template_language',20));
        $info=self::templateInfo($template);
        if(!$info['supported']||$info['header_vars']||$info['body_vars'])
            throw new HttpError('Campanhas aceitam templates aprovados sem variáveis e sem cabeçalho de mídia.',422);
        $ids=$_POST['contact_ids']??[];
        if(!is_array($ids)||count($ids)<1||count($ids)>1000)throw new HttpError('Selecione de 1 a 1.000 contatos autorizados.',422);
        $ids=array_values(array_unique(array_filter($ids,static fn($id)=>is_string($id)&&ctype_digit($id)&&strlen($id)<19)));
        if(!$ids)throw new HttpError('Selecione contatos válidos.',422);
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $s=db()->prepare('SELECT wa_id FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? AND opt_in_at IS NOT NULL AND id IN ('.$marks.')');
        $s->execute(array_merge([$tenantId,$account['id']],$ids));$recipients=$s->fetchAll(PDO::FETCH_COLUMN);
        if(count($recipients)!==count($ids))throw new HttpError('A lista foi alterada. Atualize e selecione somente contatos autorizados desta conexão.',409);
        $scheduled=null;$raw=self::field($_POST,'scheduled_at',32);
        if($raw!=='') {
            $tz=new DateTimeZone(config('APP_TIMEZONE','America/Sao_Paulo'));
            $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$raw,$tz);
            $errors=DateTimeImmutable::getLastErrors();
            if(!$date||($errors&&($errors['warning_count']||$errors['error_count'])))throw new HttpError('Horário agendado inválido.',422);
            $scheduled=$date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $campaignId=WhatsAppCampaigns::createDraft($tenantId,(int)$account['id'],$name,$template,$recipients,$scheduled);
        audit((int)$account['id'],'campaign.created',['campaign_id'=>$campaignId,'recipient_count'=>count($recipients)]);
        self::flash('Rascunho criado com '.count($recipients).' contato(s) autorizados. Revise e coloque na fila quando estiver pronto.');
        self::redirect('/painel/campanhas?account_id='.(int)$account['id']);
    }

    private static function enqueueCampaign(array $user): never {
        self::active($user);$tenantId=(int)$user['tenant_id'];
        $id=self::field($_POST,'campaign_id',20);if(!ctype_digit($id)||strlen($id)>18)throw new HttpError('Campanha inválida.',422);
        $s=db()->prepare('SELECT account_id,template_name,template_language FROM whatsapp_campaigns WHERE tenant_id=? AND id=?');
        $s->execute([$tenantId,$id]);$campaign=$s->fetch();if(!$campaign)throw new HttpError('Campanha não encontrada nesta empresa.',404);
        $account=self::account($tenantId,(int)$campaign['account_id']);
        self::approvedTemplate($account,(string)$campaign['template_name'],(string)$campaign['template_language']);
        $status=WhatsAppCampaigns::launch($tenantId,(int)$id);
        if($status==='scheduled')self::flash('Campanha agendada. O worker a colocará na fila no horário indicado.');
        else self::flash('Campanha colocada na fila. O worker enviará os templates aos contatos autorizados.');
        self::redirect('/painel/campanhas?account_id='.(int)$account['id']);
    }

    private static function saveQuickReply(array $user): never {
        self::active($user);$tenantId=(int)$user['tenant_id'];$name=self::field($_POST,'name',80);$body=self::field($_POST,'body',4096);
        if(strlen($name)<2||$body==='')throw new HttpError('Informe nome e conteúdo da resposta rápida.',422);
        try {
            db()->prepare('INSERT INTO whatsapp_quick_replies(tenant_id,name,body) VALUES (?,?,?)')
                ->execute([$tenantId,$name,$body]);
        } catch(PDOException $e) {
            if($e->getCode()==='23000')throw new HttpError('Já existe uma resposta rápida com esse nome.',409);
            throw $e;
        }
        self::flash('Resposta rápida salva.');self::redirect('/painel/respostas-rapidas');
    }
    private static function deleteQuickReply(array $user): never {
        self::active($user);$id=self::field($_POST,'reply_id',20);
        if(!ctype_digit($id))throw new HttpError('Resposta rápida inválida.',422);
        db()->prepare('DELETE FROM whatsapp_quick_replies WHERE tenant_id=? AND id=?')->execute([$user['tenant_id'],$id]);
        self::flash('Resposta rápida removida.');self::redirect('/painel/respostas-rapidas');
    }

    private static function downloadMedia(array $user): never {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new HttpError('Método não permitido.',405);
        $tenantId=(int)$user['tenant_id'];$account=self::account($tenantId,(int)self::field($_GET,'account_id',20));
        $waId=self::field($_GET,'wa_id',32);$mediaId=self::field($_GET,'id',255);
        if(!validPhone($waId)||!validId($mediaId))throw new HttpError('Mídia inválida.',422);
        $s=db()->prepare("SELECT 1 FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND wa_id=? AND
            (JSON_UNQUOTE(JSON_EXTRACT(payload,'$.image.id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(payload,'$.video.id'))=? OR
             JSON_UNQUOTE(JSON_EXTRACT(payload,'$.audio.id'))=? OR JSON_UNQUOTE(JSON_EXTRACT(payload,'$.document.id'))=?) LIMIT 1");
        $s->execute([$tenantId,$account['id'],$waId,$mediaId,$mediaId,$mediaId,$mediaId]);
        if(!$s->fetchColumn())throw new HttpError('Mídia não encontrada nesta conversa.',404);
        $meta=Graph::call($account,'GET',$mediaId);$url=(string)($meta['url']??'');$parts=parse_url($url);
        $host=strtolower((string)($parts['host']??''));
        if(!$parts||($parts['scheme']??'')!=='https'||!$host||
            !($host==='lookaside.fbsbx.com'||str_ends_with($host,'.fbcdn.net')||str_ends_with($host,'.fbsbx.com')))
            throw new HttpError('A Meta retornou uma URL de mídia inválida.',502);
        $ch=curl_init($url);if($ch===false)throw new HttpError('Mídia indisponível.',502);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.unseal($account['token_box']),'Accept: */*'],
            CURLOPT_MAXFILESIZE=>32*1024*1024]);
        $bytes=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if(!is_string($bytes)||$status<200||$status>=300||strlen($bytes)>32*1024*1024)
            throw new HttpError('Não foi possível baixar a mídia da Meta.',502);
        $mime=strtolower(trim(explode(';',(string)($meta['mime_type']??'application/octet-stream'))[0]));
        $allowed=['image/jpeg','image/png','video/mp4','video/3gpp','audio/aac','audio/mp4','audio/mpeg','audio/amr','audio/ogg','application/pdf'];
        if(!in_array($mime,$allowed,true))$mime='application/octet-stream';
        header('Content-Type: '.$mime);header('Content-Length: '.strlen($bytes));header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');header('Content-Disposition: inline; filename="whatsapp-media"');
        echo $bytes;exit;
    }

    public static function route(string $path,string $method): bool {
        $pages=['/painel/conversas'=>'inbox','/painel/conversas/nova'=>'new','/painel/contatos'=>'contacts',
            '/painel/templates'=>'templates','/painel/campanhas'=>'campaigns','/painel/respostas-rapidas'=>'quick_replies'];
        if($path==='/painel/midia') {
            try {self::downloadMedia(self::user());}
            catch(HttpError $e){http_response_code($e->httpStatus);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
            catch(Throwable $e){error_log('WhatsApp media download: '.get_class($e));http_response_code(502);echo 'Mídia indisponível.';}
            return true;
        }
        if(!isset($pages[$path]))return false;
        $user=null;$page=$pages[$path];
        try {
            $user=self::user();
            WhatsAppCampaigns::ensureSchema();
            if($method==='POST') {
                self::csrfCheck($_POST);$action=self::field($_POST,'action',40);
                switch($action) {
                    case 'save_contact':self::saveContact($user);
                    case 'optout_contact':self::optOut($user);
                    case 'start_conversation':self::startConversation($user);
                    case 'send_message':self::sendMessage($user);
                    case 'set_conversation_status':self::setConversationStatus($user);
                    case 'create_campaign':self::createCampaign($user);
                    case 'enqueue_campaign':self::enqueueCampaign($user);
                    case 'save_quick_reply':self::saveQuickReply($user);
                    case 'delete_quick_reply':self::deleteQuickReply($user);
                    default:throw new HttpError('Ação desconhecida.',422);
                }
            }
            if($method!=='GET')throw new HttpError('Método não permitido.',405);
            self::render($user,$page);return true;
        } catch(HttpError $e) {
            if($e->httpStatus===401){self::redirect('/entrar');}
            http_response_code($e->httpStatus);
            try {self::render($user??self::user(),$page,['error'=>$e->getMessage(),'old'=>$_POST]);}
            catch(Throwable $ignored){header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
            return true;
        } catch(MetaFailure $e) {
            http_response_code(502);
            try {self::render($user??self::user(),$page,['error'=>'A Meta não respondeu. Nenhuma confirmação de envio foi recebida.','old'=>$_POST]);}
            catch(Throwable $ignored){header('Content-Type: text/plain; charset=utf-8');echo 'A Meta não respondeu.';}
            return true;
        } catch(Throwable $e) {
            error_log('WhatsApp console error: '.get_class($e));http_response_code(503);
            try {self::render($user??self::user(),$page,['error'=>'A operação está indisponível agora. Atualize a página e tente novamente.','old'=>$_POST]);}
            catch(Throwable $ignored){header('Content-Type: text/plain; charset=utf-8');echo 'Serviço indisponível.';}
            return true;
        }
    }
}
