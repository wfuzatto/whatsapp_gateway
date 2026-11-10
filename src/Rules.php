<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

final class Rules {
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
    public static function ensureConsentAndWindow(int $accountId, array $data): void {
        $stmt = db()->prepare('SELECT opt_in_at,last_inbound_at FROM contacts WHERE account_id=? AND wa_id=?');
        $stmt->execute([$accountId, $data['to']]);
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
        $waId = $result['messages'][0]['id'] ?? null;
        if ($waId !== null && !is_string($waId)) $waId = null;
        $s = db()->prepare('INSERT IGNORE INTO messages(account_id,wa_id,direction,whatsapp_id,message_type,status,payload,meta_response)
                            VALUES (?,?,"outbound",?,?,"accepted",?,?)');
        $s->execute([$account['id'],$data['to'],$waId,$data['type'],jsonString($data),jsonString($result)]);
    }
}
