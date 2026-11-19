<?php
declare(strict_types=1);
require_once __DIR__ . '/whatsapp_bootstrap.php';

final class Rules {
    public static function ensureStatusEventSchema(?PDO $pdo=null): void {
        static $ready=false;
        if($ready)return;
        $pdo??=db();
        if(!empty($_SESSION['whatsapp_status_event_schema_ready'])){$ready=true;return;}
        $s=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $s->execute(['whatsapp_message_status_events']);
        if(!$s->fetchColumn()) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_message_status_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tenant_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                whatsapp_id VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(30) NOT NULL,
                event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                event_timestamp BIGINT UNSIGNED NOT NULL DEFAULT 0,
                received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_message_status_event (tenant_id,account_id,event_key),
                INDEX idx_message_status_lookup (tenant_id,account_id,whatsapp_id,event_timestamp),
                FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ready=true;
            self::backfillRecentStatusEvents($pdo);
        } else $ready=true;
        if(isset($_SESSION))$_SESSION['whatsapp_status_event_schema_ready']=true;
    }
    private static function backfillRecentStatusEvents(PDO $pdo): void {
        $s=$pdo->query('SELECT tenant_id,account_id,event_key,payload FROM whatsapp_webhook_events
            WHERE event_type="messages" AND received_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)
            ORDER BY id DESC LIMIT 20000');
        while($row=$s->fetch()) {
            $event=json_decode((string)$row['payload'],true);
            foreach(($event['change']['value']['statuses']??[]) as $status)
                if(is_array($status))self::recordStatusEvent($pdo,(int)$row['tenant_id'],(int)$row['account_id'],$status,(string)$row['event_key'],false,false);
        }
    }
    public static function recordStatusEvent(PDO $pdo,int $tenantId,int $accountId,array $statusEvent,string $sourceEventKey,bool $ensureSchema=true,bool $apply=true): void {
        if($ensureSchema)self::ensureStatusEventSchema($pdo);
        $whatsappId=(string)($statusEvent['id']??'');
        $status=(string)($statusEvent['status']??'');
        if($whatsappId===''||!in_array($status,['sent','delivered','read','failed'],true))return;
        $timestamp=max(0,(int)($statusEvent['timestamp']??0));
        $eventKey=hash('sha256',$sourceEventKey."\n".$whatsappId."\n".$status."\n".$timestamp);
        $s=$pdo->prepare('INSERT IGNORE INTO whatsapp_message_status_events(tenant_id,account_id,whatsapp_id,status,event_key,event_timestamp)
            VALUES (?,?,?,?,?,?)');
        $s->execute([$tenantId,$accountId,$whatsappId,$status,$eventKey,$timestamp]);
        if($apply)self::applyMessageStatus($pdo,$tenantId,$accountId,$whatsappId,$status);
    }
    public static function applyMessageStatus(PDO $pdo,int $tenantId,int $accountId,string $whatsappId,string $status): void {
        if($whatsappId===''||!in_array($status,['sent','delivered','read','failed'],true))return;
        $s=$pdo->prepare("UPDATE whatsapp_messages SET status=CASE
                WHEN status='read' THEN 'read'
                WHEN status='delivered' AND ? IN ('accepted','sent','delivered','failed') THEN 'delivered'
                WHEN status='failed' AND ? IN ('accepted','sent') THEN 'failed'
                WHEN status='uncertain' AND ? IN ('accepted','sent') THEN 'uncertain'
                ELSE ? END
            WHERE tenant_id=? AND account_id=? AND direction='outbound' AND whatsapp_id=?");
        $s->execute([$status,$status,$status,$status,$tenantId,$accountId,$whatsappId]);
    }
    public static function reconcileMessageStatuses(PDO $pdo,int $tenantId,int $accountId,string $waId): void {
        self::ensureStatusEventSchema($pdo);
        $s=$pdo->prepare("SELECT e.whatsapp_id,e.status FROM whatsapp_message_status_events e
            JOIN whatsapp_messages m ON m.tenant_id=e.tenant_id AND m.account_id=e.account_id AND m.whatsapp_id=e.whatsapp_id
            WHERE m.tenant_id=? AND m.account_id=? AND m.wa_id=? AND m.direction='outbound'
              AND ((m.status='accepted' AND e.status IN ('sent','delivered','read','failed'))
                OR (m.status='sent' AND e.status IN ('delivered','read','failed'))
                OR (m.status='delivered' AND e.status='read')
                OR (m.status IN ('failed','uncertain') AND e.status IN ('delivered','read')))
            ORDER BY e.event_timestamp ASC,e.id ASC");
        $s->execute([$tenantId,$accountId,$waId]);
        while($row=$s->fetch())self::applyMessageStatus($pdo,$tenantId,$accountId,(string)$row['whatsapp_id'],(string)$row['status']);
    }
    public static function normaliseMessage(array $data): array {
        if (!isset($data['to']) || !is_string($data['to']) || !validPhone($data['to']))
            throw new HttpError('to must contain 7-20 digits');
        if (!isset($data['type']) || !is_string($data['type']) ||
            !preg_match('/^[a-z_]{2,40}$/D', $data['type'])) throw new HttpError('Invalid message type');
        if (isset($data['from']) || isset($data['access_token'])) throw new HttpError('Forbidden Meta fields');
        $data['messaging_product'] = 'whatsapp';
        $data['recipient_type'] = 'individual';
        return $data;
    }
    public static function ensureConsentAndWindow(array $account, array $data): void {
        $stmt = db()->prepare('SELECT opt_in_at,last_inbound_at FROM whatsapp_contacts WHERE tenant_id=? AND account_id=? AND wa_id=?');
        $stmt->execute([$account['tenant_id'], $account['id'], $data['to']]);
        $contact = $stmt->fetch() ?: [];
        if ($data['type'] === 'template') {
            if (empty($contact['opt_in_at'])) throw new HttpError('No recorded opt-in for this recipient', 422);
            return;
        }
        if (empty($contact['last_inbound_at']) ||
            strtotime($contact['last_inbound_at'] . ' UTC') < time() - 86400)
            throw new HttpError('Customer service window expired; use an approved template with consent', 422);
    }
    public static function storeOutgoing(array $account, array $data, array $result): void {
        self::ensureStatusEventSchema();
        $waId = $result['messages'][0]['id'] ?? null;
        if ($waId !== null && !is_string($waId)) $waId = null;
        $s = db()->prepare('INSERT IGNORE INTO whatsapp_messages(tenant_id,account_id,wa_id,direction,whatsapp_id,message_type,status,payload,meta_response)
                            VALUES (?,?,?,"outbound",?,?,"accepted",?,?)');
        $s->execute([$account['tenant_id'],$account['id'],$data['to'],$waId,$data['type'],jsonString($data),jsonString($result)]);
        if(is_string($waId)&&$waId!=='')self::reconcileMessageStatuses(db(),(int)$account['tenant_id'],(int)$account['id'],(string)$data['to']);
    }
}
