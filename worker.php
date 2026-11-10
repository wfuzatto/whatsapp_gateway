<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/app/bootstrap.php';
if (!installed()) { fwrite(STDERR,"Instale a plataforma antes de iniciar o worker.\n"); exit(1); }
$batch=max(1,min(50,(int)($argv[1]??20))); $sent=0; $failed=0;
try {
    $campaigns=q("SELECT c.*,t.name template_name,t.language,t.category,t.body FROM whatsapp_campaigns c JOIN whatsapp_templates t ON t.id=c.template_id AND t.tenant_id=c.tenant_id WHERE c.status IN ('queued','sending') AND (c.scheduled_at IS NULL OR c.scheduled_at<=NOW()) ORDER BY c.scheduled_at,c.id LIMIT 10")->fetchAll();
    foreach($campaigns as $campaign) {
        q("UPDATE whatsapp_campaigns SET status='sending',started_at=COALESCE(started_at,NOW()) WHERE id=? AND tenant_id=?",[$campaign['id'],$campaign['tenant_id']]);
        $recipients=q("SELECT cr.id recipient_id,cr.contact_id,ct.phone,ct.name,ct.consent_status,ct.opted_out_at FROM whatsapp_campaign_recipients cr JOIN whatsapp_contacts ct ON ct.id=cr.contact_id AND ct.tenant_id=cr.tenant_id WHERE cr.campaign_id=? AND cr.tenant_id=? AND cr.status='pending' ORDER BY cr.id LIMIT ".$batch,[$campaign['id'],$campaign['tenant_id']])->fetchAll();
        foreach($recipients as $recipient) {
            $claimed=q("UPDATE whatsapp_campaign_recipients SET status='processing',updated_at=NOW() WHERE id=? AND tenant_id=? AND status='pending'",[$recipient['recipient_id'],$campaign['tenant_id']]);
            if($claimed->rowCount()!==1) continue;
            try {
                if($recipient['consent_status']!=='opted_in'||$recipient['opted_out_at']) throw new RuntimeException('Consentimento ausente ou cancelado antes do envio.');
                if(preg_match('/\{\{\s*\d+\s*\}\}/',(string)$campaign['body'])) throw new RuntimeException('Template com variáveis exige dados por destinatário.');
                $m=meta_config((int)$campaign['tenant_id']); if(empty($m['phone_number_id'])) throw new RuntimeException('Número não conectado.');
                $payload=['messaging_product'=>'whatsapp','to'=>$recipient['phone'],'type'=>'template','template'=>['name'=>$campaign['template_name'],'language'=>['code'=>$campaign['language']]]];
                $response=meta_call((int)$campaign['tenant_id'],'POST',$m['phone_number_id'].'/messages',$payload); $wamid=$response['messages'][0]['id']??null;
                $conv=q("SELECT id FROM whatsapp_conversations WHERE tenant_id=? AND contact_id=? AND phone_number_id=? ORDER BY id DESC LIMIT 1",[$campaign['tenant_id'],$recipient['contact_id'],$m['phone_number_id']])->fetch();
                if(!$conv){q("INSERT INTO whatsapp_conversations(tenant_id,contact_id,phone_number_id,status,created_at,updated_at) VALUES(?,?,?,'open',NOW(),NOW())",[$campaign['tenant_id'],$recipient['contact_id'],$m['phone_number_id']]);$conv=['id'=>(int)db()->lastInsertId()];}
                $preview='['.$campaign['template_name'].'] '.$campaign['body'];
                q("INSERT INTO whatsapp_messages(tenant_id,conversation_id,direction,type,body,wamid,status,created_at) VALUES(?,?, 'out','template',?,?, 'accepted',NOW())",[$campaign['tenant_id'],$conv['id'],$preview,$wamid]);
                q("UPDATE whatsapp_conversations SET last_message_at=NOW(),last_message_preview=?,updated_at=NOW() WHERE id=? AND tenant_id=?",[mb_substr($preview,0,240),$conv['id'],$campaign['tenant_id']]);
                queue_tenant_event((int)$campaign['tenant_id'],'message.sent',['message_id'=>$wamid,'phone'=>$recipient['phone'],'text'=>$preview,'status'=>'accepted','campaign_id'=>(int)$campaign['id']]);
                q("UPDATE whatsapp_campaign_recipients SET status='accepted',wamid=?,updated_at=NOW() WHERE id=? AND tenant_id=?",[$wamid,$recipient['recipient_id'],$campaign['tenant_id']]); $sent++;
                usleep(250000);
            } catch(Throwable $e) { q("UPDATE whatsapp_campaign_recipients SET status='failed',error=?,updated_at=NOW() WHERE id=? AND tenant_id=?",[mb_substr($e->getMessage(),0,2000),$recipient['recipient_id'],$campaign['tenant_id']]); $failed++; }
        }
        $pending=(int)q("SELECT COUNT(*) FROM whatsapp_campaign_recipients WHERE campaign_id=? AND tenant_id=? AND status IN ('pending','processing')",[$campaign['id'],$campaign['tenant_id']])->fetchColumn();
        if($pending===0) q("UPDATE whatsapp_campaigns SET status='completed',completed_at=NOW() WHERE id=? AND tenant_id=?",[$campaign['id'],$campaign['tenant_id']]);
    }
    $hooks=q("SELECT o.*,h.url,h.secret_enc FROM whatsapp_webhook_outbox o JOIN whatsapp_tenant_webhooks h ON h.tenant_id=o.tenant_id AND h.active=1 WHERE o.status='pending' AND (o.next_attempt_at IS NULL OR o.next_attempt_at<=NOW()) ORDER BY o.id LIMIT 30")->fetchAll();$hookOk=0;$hookFailed=0;
    foreach($hooks as $hook){$claim=q("UPDATE whatsapp_webhook_outbox SET status='sending',updated_at=NOW() WHERE id=? AND status='pending'",[$hook['id']]);if($claim->rowCount()!==1)continue;
        try{if(!safe_https_url($hook['url']))throw new RuntimeException('Webhook URL deixou de resolver para um destino HTTPS público.');$body=(string)$hook['payload'];$secret=decrypt_secret($hook['secret_enc']);$signature='sha256='.hash_hmac('sha256',$body,$secret);$ch=curl_init($hook['url']);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Ponte-Signature: '.$signature,'X-Ponte-Event: '.$hook['event_type'],'User-Agent: Ponte-Webhook/1.0'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false]);$response=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException($response===false?$err:'Destino respondeu HTTP '.$status);q("UPDATE whatsapp_webhook_outbox SET status='delivered',attempts=attempts+1,last_error=NULL,updated_at=NOW() WHERE id=?",[$hook['id']]);$hookOk++;}
        catch(Throwable $e){$attempt=(int)$hook['attempts']+1;$retry=min(240,5*(2**min(5,$attempt-1)));$state=$attempt>=8?'failed':'pending';q("UPDATE whatsapp_webhook_outbox SET status=?,attempts=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL ".$retry." MINUTE),last_error=?,updated_at=NOW() WHERE id=?",[$state,$attempt,mb_substr($e->getMessage(),0,2000),$hook['id']]);$hookFailed++;}
    }
    fwrite(STDOUT,date('c')." worker complete: campaign_accepted=$sent campaign_failed=$failed client_webhooks_delivered=$hookOk client_webhooks_retrying_or_failed=$hookFailed batch=$batch\n");
} catch(Throwable $e) { fwrite(STDERR,date('c').' worker error: '.$e->getMessage()."\n"); exit(1); }
