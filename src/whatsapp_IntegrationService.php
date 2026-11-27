<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_Graph.php';
require_once __DIR__.'/whatsapp_Rules.php';
require_once __DIR__.'/whatsapp_Jobs.php';
require_once __DIR__.'/whatsapp_Fcm.php';

/** Shared REST/MCP use cases. Tenant and number scope always come from the credential. */
final class WhatsAppIntegrationService {
    public static function accounts(array $context): array {
        $sql='SELECT id,name,waba_id,phone_number_id,status,created_at FROM whatsapp_accounts WHERE tenant_id=?';
        $args=[(int)$context['tenant_id']];
        if(($context['account_id']??null)!==null){$sql.=' AND id=?';$args[]=(int)$context['account_id'];}
        $sql.=' ORDER BY id';$s=db()->prepare($sql);$s->execute($args);return $s->fetchAll();
    }

    public static function conversations(array $context,array $account,string $search='',int $beforeId=PHP_INT_MAX,int $limit=50): array {
        $limit=max(1,min(100,$limit));
        $tenantId=(int)$context['tenant_id'];$accountId=(int)$account['id'];
        WhatsAppFcm::ensureReadCursorSchema();
        $sql='SELECT m.id,m.account_id,m.wa_id,ct.display_name,m.direction,m.message_type,m.status,
                m.payload,m.created_at,COALESCE(cv.status,"open") AS conversation_status,
                COALESCE(rc.last_read_message_id,0) AS read_through_id,
                (SELECT COUNT(*) FROM whatsapp_messages unread WHERE unread.tenant_id=m.tenant_id
                    AND unread.account_id=m.account_id AND unread.wa_id=m.wa_id AND unread.direction="inbound"
                    AND unread.id>COALESCE(rc.last_read_message_id,0)) AS unread_count
            FROM whatsapp_messages m
            JOIN (SELECT wa_id,MAX(id) AS last_id FROM whatsapp_messages
                  WHERE tenant_id=? AND account_id=? GROUP BY wa_id) latest ON latest.last_id=m.id
            LEFT JOIN whatsapp_contacts ct ON ct.tenant_id=m.tenant_id AND ct.account_id=m.account_id AND ct.wa_id=m.wa_id
            LEFT JOIN whatsapp_conversations cv ON cv.tenant_id=m.tenant_id AND cv.account_id=m.account_id AND cv.wa_id=m.wa_id
            LEFT JOIN whatsapp_conversation_read_cursors rc ON rc.tenant_id=m.tenant_id AND rc.account_id=m.account_id AND rc.wa_id=m.wa_id
            WHERE m.tenant_id=? AND m.account_id=? AND m.id<?';
        $args=[$tenantId,$accountId,$tenantId,$accountId,$beforeId];
        if($search!==''){$sql.=' AND (m.wa_id LIKE ? OR ct.display_name LIKE ?)';$args[]='%'.$search.'%';$args[]='%'.$search.'%';}
        $sql.=' ORDER BY m.id DESC LIMIT '.$limit;
        $s=db()->prepare($sql);$s->execute($args);$rows=$s->fetchAll();
        foreach($rows as &$row){
            $row['id']=(string)$row['id'];$row['read_through_id']=(string)$row['read_through_id'];
            $row['unread_count']=(int)$row['unread_count'];
            $row['preview']=self::preview((string)$row['payload'],(string)$row['message_type']);
            unset($row['payload']);
        }
        unset($row);return $rows;
    }

    public static function messages(array $context,array $account,string $waId,int $beforeId=PHP_INT_MAX,int $limit=50): array {
        if(!validPhone($waId))throw new HttpError('wa_id inválido.',422);
        $limit=max(1,min(100,$limit));
        $s=db()->prepare('SELECT id,wa_id,direction,whatsapp_id,message_type,status,payload,created_at
            FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND wa_id=? AND id<? ORDER BY id DESC LIMIT '.$limit);
        $s->execute([(int)$context['tenant_id'],(int)$account['id'],$waId,$beforeId]);
        $rows=$s->fetchAll();
        foreach($rows as &$row){
            $payload=json_decode((string)$row['payload'],true);if(!is_array($payload))$payload=[];
            $row['id']=(string)$row['id'];$row['payload']=$payload;
        }
        unset($row);return $rows;
    }

    public static function events(array $context,array $account,int $afterId=0,int $limit=50): array {
        $limit=max(1,min(100,$limit));
        $tenantId=(int)$context['tenant_id'];$accountId=(int)$account['id'];
        $s=db()->prepare('SELECT id,event_key,event_type,received_at FROM whatsapp_webhook_events
            WHERE tenant_id=? AND account_id=? AND id>? ORDER BY id ASC LIMIT '.($limit+1));
        $s->execute([$tenantId,$accountId,$afterId]);$ids=$s->fetchAll();
        $more=count($ids)>$limit;if($more)array_pop($ids);
        $maxBytes=max(65536,min(8388608,(int)config('MAX_API_EVENT_PAGE_BYTES','2097152')));
        $data=[];$bytes=0;$cursor=(string)$afterId;
        $payloadQuery=db()->prepare('SELECT payload FROM whatsapp_webhook_events WHERE tenant_id=? AND account_id=? AND id=?');
        foreach($ids as $row){
            $payloadQuery->execute([$tenantId,$accountId,$row['id']]);
            $decoded=json_decode((string)$payloadQuery->fetchColumn(),true);$payload=is_array($decoded)?$decoded:[];
            $encoded=jsonString($payload);$itemBytes=strlen($encoded);
            if($data&&$bytes+$itemBytes>$maxBytes){$more=true;break;}
            $row['id']=(string)$row['id'];$row['payload']=$payload;$data[]=$row;$bytes+=$itemBytes;$cursor=$row['id'];
        }
        return ['data'=>$data,'next_after_id'=>$cursor,'has_more'=>$more];
    }

    public static function templates(array $account,int $limit=50,?string $name=null,?string $after=null): array {
        $query=['limit'=>max(1,min(100,$limit))];
        if($name!==null&&$name!=='')$query['name']=$name;
        if($after!==null&&$after!==''){
            if(strlen($after)>600||preg_match('/[\x00-\x1F\x7F]/',$after))throw new HttpError('Cursor de templates inválido.',422);
            $query['after']=$after;
        }
        return Graph::call($account,'GET',$account['waba_id'].'/message_templates',null,$query);
    }

    public static function assertApprovedTemplate(array $account,array $message): void {
        if(($message['type']??'')!=='template')return;
        $template=$message['template']??null;
        if(!is_array($template)||!is_array($template['language']??null))throw new HttpError('Template e idioma obrigatórios.',422);
        $name=$template['name']??'';$language=$template['language']['code']??'';
        if(!is_string($name)||!preg_match('/^[a-z0-9_]{1,128}$/D',$name)||!is_string($language)||strlen($language)>20)
            throw new HttpError('Nome e idioma do template inválidos.',422);
        $result=self::templates($account,100,$name);$items=$result['data']??[];
        foreach($items as $item)if(is_array($item)&&($item['name']??'')===$name&&
            ($item['language']??'')===$language&&($item['status']??'')==='APPROVED')return;
        throw new HttpError('O template não está aprovado para este número e idioma.',422);
    }

    public static function enqueueMessage(array $context,array $account,array $message,string $key): array {
        WhatsAppApiSecurity::rateLimit($context,'write',30);
        $message=Rules::normaliseMessage($message);
        Rules::ensureConsentAndWindow($account,$message);
        self::assertApprovedTemplate($account,$message);
        if(!preg_match('/^[A-Za-z0-9._:-]{8,180}$/D',$key))throw new HttpError('Idempotency-Key inválida ou ausente.',422);
        $result=Jobs::enqueueSend($account,$message,$key);
        audit((int)$account['id'],'integration.message.queued',[
            'job_id'=>$result['job_id'],'type'=>$message['type'],'to'=>$message['to'],
            'credential_type'=>$context['credential_type']??'legacy','credential_id'=>(int)($context['credential_id']??0)
        ]);
        return $result;
    }

    public static function job(array $context,array $account,int $jobId): array {
        if($jobId<1)throw new HttpError('job_id inválido.',422);
        $s=db()->prepare('SELECT id,kind,status,attempts,max_attempts,next_attempt_at,last_error,response,created_at,updated_at
            FROM whatsapp_jobs WHERE id=? AND tenant_id=? AND account_id=?');
        $s->execute([$jobId,(int)$context['tenant_id'],(int)$account['id']]);$job=$s->fetch();
        if(!$job)throw new HttpError('Envio não encontrado.',404);
        $job['id']=(string)$job['id'];$response=json_decode((string)($job['response']??''),true);
        $job['response']=is_array($response)?$response:null;return $job;
    }

    public static function markConversationRead(array $context,array $account,string $waId,int $messageId): array {
        if(!validPhone($waId)||$messageId<1)throw new HttpError('Conversa ou mensagem inválida.',422);
        $tenantId=(int)$context['tenant_id'];$accountId=(int)$account['id'];
        $s=db()->prepare('SELECT whatsapp_id FROM whatsapp_messages WHERE tenant_id=? AND account_id=? AND wa_id=?
            AND id=? AND direction="inbound" LIMIT 1');
        $s->execute([$tenantId,$accountId,$waId,$messageId]);$whatsappId=$s->fetchColumn();
        if(!is_string($whatsappId)||$whatsappId==='')throw new HttpError('Mensagem recebida não pertence a esta conversa.',404);
        WhatsAppFcm::ensureReadCursorSchema();
        $cursorQuery=db()->prepare('SELECT last_read_message_id FROM whatsapp_conversation_read_cursors
            WHERE tenant_id=? AND account_id=? AND wa_id=?');
        $cursorQuery->execute([$tenantId,$accountId,$waId]);$current=(int)($cursorQuery->fetchColumn()?:0);
        if($current>=$messageId)return ['read_through_id'=>(string)$current,'advanced'=>false,'push_job_id'=>0];
        // Send the Meta receipt first. If it fails, do not clear the APK notification cursor.
        Graph::call($account,'POST',$account['phone_number_id'].'/messages',[
            'messaging_product'=>'whatsapp','status'=>'read','message_id'=>$whatsappId
        ]);
        $through=WhatsAppFcm::markConversationRead($tenantId,$accountId,$waId,$messageId);
        $result=['read_through_id'=>(string)$through,'advanced'=>$through>$current,'push_job_id'=>0];
        audit($accountId,'integration.conversation.read',[
            'wa_id'=>$waId,'read_through_id'=>$result['read_through_id'],
            'credential_type'=>$context['credential_type']??'legacy','credential_id'=>(int)($context['credential_id']??0)
        ]);
        return $result;
    }

    public static function preview(string $raw,string $type): string {
        $payload=json_decode($raw,true);if(!is_array($payload))return ucfirst($type);
        $typed=is_array($payload[$type]??null)?$payload[$type]:[];
        $text=$payload['text']['body']??$typed['caption']??$payload['template']['name']??
            $payload['button']['text']??$payload['interactive']['button_reply']['title']??
            $payload['interactive']['list_reply']['title']??null;
        if(is_string($text)&&$text!=='')return substr($text,0,180);
        return match($type){'image'=>'Imagem','video'=>'Vídeo','audio'=>'Áudio','document'=>'Documento','sticker'=>'Figurinha',default=>'Mensagem '.ucfirst($type)};
    }
}
