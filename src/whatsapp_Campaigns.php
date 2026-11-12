<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_Rules.php';
require_once __DIR__.'/whatsapp_Graph.php';

final class WhatsAppCampaigns {
    public static function ensureSchema(): void {
        static $ready=false;
        if($ready)return;
        $file=dirname(__DIR__).'/database/whatsapp_migration_console.sql';
        $sql=file_get_contents($file);
        if(!is_string($sql))throw new RuntimeException('Migracao da central indisponivel');
        preg_match_all('/CREATE TABLE IF NOT EXISTS whatsapp_[a-z_]+\s*\(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',$sql,$matches);
        if(count($matches[0])!==4)throw new RuntimeException('Migracao da central incompatível');
        foreach($matches[0] as $statement)db()->exec($statement);
        $ready=true;
    }

    public static function createDraft(int $tenantId,int $accountId,string $name,array $template,array $recipients,?string $scheduledAt): int {
        self::ensureSchema();
        if(!$recipients)throw new HttpError('Selecione ao menos um contato com consentimento registrado.',422);
        if(count($recipients)>1000)throw new HttpError('Cada campanha pode incluir até 1.000 contatos.',422);
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('INSERT INTO whatsapp_campaigns
                (tenant_id,account_id,name,template_name,template_language,template_category,template_body,scheduled_at,status)
                VALUES (?,?,?,?,?,?,?,? ,"draft")');
            $s->execute([$tenantId,$accountId,$name,$template['name'],$template['language'],
                $template['category'],self::templateBody($template),$scheduledAt]);
            $campaignId=(int)$pdo->lastInsertId();
            $insert=$pdo->prepare('INSERT IGNORE INTO whatsapp_campaign_recipients
                (tenant_id,account_id,campaign_id,wa_id,status) VALUES (?,?,?, ?,"pending")');
            foreach(array_unique($recipients) as $waId)$insert->execute([$tenantId,$accountId,$campaignId,$waId]);
            if($insert->rowCount()===0)throw new HttpError('Nenhum contato elegível foi salvo na campanha.',422);
            $pdo->commit();
            audit($accountId,'campaign.created',['campaign_id'=>$campaignId,'recipients'=>count(array_unique($recipients)),
                'template'=>$template['name']]);
            return $campaignId;
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    public static function launch(int $tenantId,int $campaignId,?string $scheduledAt=null): string {
        self::ensureSchema();
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT c.*,a.status account_status,a.phone_number_id,a.waba_id,a.token_box,t.status tenant_status,
                (SELECT status FROM whatsapp_subscriptions WHERE tenant_id=c.tenant_id LIMIT 1) subscription_status
                FROM whatsapp_campaigns c
                JOIN whatsapp_accounts a ON a.id=c.account_id AND a.tenant_id=c.tenant_id
                JOIN whatsapp_tenants t ON t.id=c.tenant_id
                WHERE c.id=? AND c.tenant_id=? FOR UPDATE');
            $s->execute([$campaignId,$tenantId]);$campaign=$s->fetch();
            if(!$campaign)throw new HttpError('Campanha não encontrada nesta empresa.',404);
            if($campaign['status']!=='draft')throw new HttpError('Esta campanha já foi enfileirada ou encerrada.',409);
            if($campaign['account_status']!=='active'||$campaign['tenant_status']!=='active'||
                ($campaign['subscription_status']!==null&&$campaign['subscription_status']!=='active'))
                throw new HttpError('Ative a assinatura e a conexão Meta antes de enfileirar.',403);

            $excluded=$pdo->prepare('UPDATE whatsapp_campaign_recipients r
                LEFT JOIN whatsapp_contacts c ON c.tenant_id=r.tenant_id AND c.account_id=r.account_id AND c.wa_id=r.wa_id
                SET r.status="excluded",r.error="Consentimento não está mais ativo"
                WHERE r.tenant_id=? AND r.campaign_id=? AND r.status="pending" AND c.opt_in_at IS NULL');
            $excluded->execute([$tenantId,$campaignId]);
            $s=$pdo->prepare('SELECT r.wa_id FROM whatsapp_campaign_recipients r
                JOIN whatsapp_contacts c ON c.tenant_id=r.tenant_id AND c.account_id=r.account_id AND c.wa_id=r.wa_id
                WHERE r.tenant_id=? AND r.campaign_id=? AND r.status="pending" AND c.opt_in_at IS NOT NULL
                ORDER BY r.id FOR UPDATE');
            $s->execute([$tenantId,$campaignId]);$recipients=$s->fetchAll(PDO::FETCH_COLUMN);
            if(!$recipients)throw new HttpError('Não há contatos com consentimento ativo nesta campanha.',422);

            $scheduledAt=$scheduledAt??($campaign['scheduled_at']!==null?(string)$campaign['scheduled_at']:null);
            $due=null;
            if($scheduledAt!==null&&$scheduledAt!=='') {
                $dt=new DateTimeImmutable($scheduledAt,new DateTimeZone('UTC'));
                if($dt->getTimestamp()>time()+60)$due=$dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
            if($due!==null) {
                $pdo->prepare('UPDATE whatsapp_campaigns SET status="scheduled",scheduled_at=? WHERE id=? AND tenant_id=?')
                    ->execute([$due,$campaignId,$tenantId]);
                $pdo->commit();
                audit((int)$campaign['account_id'],'campaign.scheduled',['campaign_id'=>$campaignId,'scheduled_at'=>$due,
                    'recipients'=>count($recipients)]);
                return 'scheduled';
            }
            self::queueRecipients($campaign,$recipients);
            $pdo->commit();
            audit((int)$campaign['account_id'],'campaign.queued',['campaign_id'=>$campaignId,'recipients'=>count($recipients)]);
            return 'queued';
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }

    private static function queueRecipients(array $campaign,array $recipients): void {
        $account=['id'=>(int)$campaign['account_id'],'tenant_id'=>(int)$campaign['tenant_id'],
            'phone_number_id'=>(string)$campaign['phone_number_id'],'waba_id'=>(string)$campaign['waba_id'],
            'token_box'=>(string)$campaign['token_box']];
        $update=db()->prepare('UPDATE whatsapp_campaign_recipients SET status="queued",job_id=?,error=NULL
            WHERE tenant_id=? AND campaign_id=? AND wa_id=? AND status="pending"');
        foreach($recipients as $waId) {
            $payload=['messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$waId,'type'=>'template',
                'template'=>['name'=>$campaign['template_name'],'language'=>['code'=>$campaign['template_language']]]];
            $key='campaign-'.$campaign['id'].'-'.hash('sha256',$waId);
            $job=Jobs::enqueueSend($account,$payload,$key);
            $update->execute([$job['job_id'],$campaign['tenant_id'],$campaign['id'],$waId]);
        }
        db()->prepare('UPDATE whatsapp_campaigns SET status="queued",started_at=COALESCE(started_at,UTC_TIMESTAMP()),
            scheduled_at=NULL WHERE id=? AND tenant_id=?')->execute([$campaign['id'],$campaign['tenant_id']]);
    }

    private static function templateBody(array $template): string {
        foreach(($template['components']??[]) as $component)
            if(is_array($component)&&strtoupper((string)($component['type']??''))==='BODY')
                return substr((string)($component['text']??''),0,4000);
        return '';
    }

    public static function dispatchDue(): void {
        try {
            self::ensureSchema();
            self::reconcileUncertain();
            $ids=db()->query("SELECT id,tenant_id FROM whatsapp_campaigns
                WHERE status='scheduled' AND scheduled_at<=UTC_TIMESTAMP() ORDER BY scheduled_at,id LIMIT 10")->fetchAll();
            foreach($ids as $row)self::dispatchScheduled((int)$row['tenant_id'],(int)$row['id']);
        } catch(Throwable $e) {
            error_log('WhatsApp campaign scheduler: '.get_class($e));
        }
    }

    private static function dispatchScheduled(int $tenantId,int $campaignId): void {
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT c.*,a.status account_status,a.phone_number_id,a.waba_id,a.token_box,t.status tenant_status,
                (SELECT status FROM whatsapp_subscriptions WHERE tenant_id=c.tenant_id LIMIT 1) subscription_status
                FROM whatsapp_campaigns c
                JOIN whatsapp_accounts a ON a.id=c.account_id AND a.tenant_id=c.tenant_id
                JOIN whatsapp_tenants t ON t.id=c.tenant_id
                WHERE c.id=? AND c.tenant_id=? AND c.status="scheduled" AND c.scheduled_at<=UTC_TIMESTAMP() FOR UPDATE');
            $s->execute([$campaignId,$tenantId]);$campaign=$s->fetch();
            if(!$campaign||$campaign['account_status']!=='active'||$campaign['tenant_status']!=='active'||
                ($campaign['subscription_status']!==null&&$campaign['subscription_status']!=='active')) {
                $pdo->commit();return;
            }
            $s=$pdo->prepare('SELECT r.wa_id FROM whatsapp_campaign_recipients r
                JOIN whatsapp_contacts c ON c.tenant_id=r.tenant_id AND c.account_id=r.account_id AND c.wa_id=r.wa_id
                WHERE r.tenant_id=? AND r.campaign_id=? AND r.status="pending" AND c.opt_in_at IS NOT NULL
                ORDER BY r.id FOR UPDATE');
            $s->execute([$tenantId,$campaignId]);$recipients=$s->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('UPDATE whatsapp_campaign_recipients r LEFT JOIN whatsapp_contacts c
                ON c.tenant_id=r.tenant_id AND c.account_id=r.account_id AND c.wa_id=r.wa_id
                SET r.status="excluded",r.error="Consentimento não está mais ativo"
                WHERE r.tenant_id=? AND r.campaign_id=? AND r.status="pending" AND c.opt_in_at IS NULL')
                ->execute([$tenantId,$campaignId]);
            if(!$recipients) {
                $pdo->prepare('UPDATE whatsapp_campaigns SET status="completed",completed_at=UTC_TIMESTAMP()
                    WHERE id=? AND tenant_id=?')->execute([$campaignId,$tenantId]);
                $pdo->commit();return;
            }
            // O worker envia apenas templates sem variáveis e confere novamente o consentimento em cada envio.
            self::queueRecipients($campaign,$recipients);
            $pdo->commit();
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            error_log('WhatsApp scheduled campaign '.get_class($e));
        }
    }

    public static function jobClaimed(array $job): void {
        if(($job['kind']??'')!=='send')return;
        $s=db()->prepare('SELECT campaign_id FROM whatsapp_campaign_recipients WHERE tenant_id=? AND job_id=? LIMIT 1');
        $s->execute([$job['tenant_id'],$job['id']]);$campaignId=$s->fetchColumn();
        if($campaignId)db()->prepare('UPDATE whatsapp_campaigns SET status="sending" WHERE tenant_id=? AND id=? AND status IN ("queued","sending")')
            ->execute([$job['tenant_id'],$campaignId]);
    }

    public static function jobFinished(array $job,string $state,?string $error=null): void {
        if(($job['kind']??'')!=='send')return;
        $mapped=match($state){'done'=>'accepted','failed'=>'failed','uncertain'=>'uncertain',default=>null};
        if($mapped===null)return;
        $s=db()->prepare('SELECT campaign_id FROM whatsapp_campaign_recipients WHERE tenant_id=? AND job_id=? LIMIT 1');
        $s->execute([$job['tenant_id'],$job['id']]);$campaignId=$s->fetchColumn();
        if(!$campaignId)return;
        db()->prepare('UPDATE whatsapp_campaign_recipients SET status=?,error=? WHERE tenant_id=? AND campaign_id=? AND job_id=?')
            ->execute([$mapped,$error?substr($error,0,1000):null,$job['tenant_id'],$campaignId,$job['id']]);
        self::refresh((int)$job['tenant_id'],(int)$campaignId);
    }

    public static function reconcileUncertain(): void {
        self::ensureSchema();
        $s=db()->query('SELECT DISTINCT r.tenant_id,r.campaign_id,r.job_id FROM whatsapp_campaign_recipients r
            JOIN whatsapp_jobs j ON j.id=r.job_id AND j.tenant_id=r.tenant_id
            WHERE j.status="uncertain" AND r.status="queued"');
        $rows=$s->fetchAll();
        foreach($rows as $row) {
            db()->prepare('UPDATE whatsapp_campaign_recipients SET status="uncertain",error="Entrega incerta; reconcilie antes de reenviar"
                WHERE tenant_id=? AND campaign_id=? AND job_id=? AND status="queued"')
                ->execute([$row['tenant_id'],$row['campaign_id'],$row['job_id']]);
            self::refresh((int)$row['tenant_id'],(int)$row['campaign_id']);
        }
    }

    private static function refresh(int $tenantId,int $campaignId): void {
        $s=db()->prepare('SELECT
            SUM(status IN ("pending","queued")) AS remaining,
            SUM(status="uncertain") AS uncertain
            FROM whatsapp_campaign_recipients WHERE tenant_id=? AND campaign_id=?');
        $s->execute([$tenantId,$campaignId]);$counts=$s->fetch()?:[];
        if((int)($counts['remaining']??0)>0)return;
        $status=(int)($counts['uncertain']??0)>0?'needs_review':'completed';
        db()->prepare('UPDATE whatsapp_campaigns SET status=?,completed_at=UTC_TIMESTAMP()
            WHERE tenant_id=? AND id=? AND status IN ("queued","sending")')
            ->execute([$status,$tenantId,$campaignId]);
    }
}
