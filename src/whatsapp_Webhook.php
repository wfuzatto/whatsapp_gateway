<?php
declare(strict_types=1);
require_once __DIR__ . '/whatsapp_bootstrap.php';

final class Webhook {
    public static function verifySubscription(array $query): string {
        $mode = $query['hub_mode'] ?? $query['hub.mode'] ?? '';
        $token = $query['hub_verify_token'] ?? $query['hub.verify_token'] ?? '';
        $challenge = $query['hub_challenge'] ?? $query['hub.challenge'] ?? '';
        $expected = config('META_VERIFY_TOKEN');
        if ($mode !== 'subscribe' || $expected === '' || !is_string($token) ||
            !hash_equals($expected, $token) || !is_string($challenge))
            throw new HttpError('Webhook verification failed', 403);
        return $challenge;
    }
    public static function validSignature(string $raw, string $signature): bool {
        $secret = config('META_APP_SECRET');
        if ($secret === '' || !preg_match('/^sha256=[0-9a-f]{64}$/D', $signature)) return false;
        return hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $signature);
    }
    public static function receive(string $raw, string $signature): array {
        if (!self::validSignature($raw, $signature)) throw new HttpError('Invalid webhook signature', 403);
        try { $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new HttpError('Invalid webhook JSON'); }
        if (!is_array($body) || ($body['object'] ?? '') !== 'whatsapp_business_account' ||
            !isset($body['entry']) || !is_array($body['entry'])) throw new HttpError('Unexpected webhook object');
        $pdo = db();
        $saved = 0;
        $pdo->beginTransaction();
        try {
            foreach ($body['entry'] as $entry) {
                if (!is_array($entry)) continue;
                $entryId = (string)($entry['id'] ?? '');
                foreach (($entry['changes'] ?? []) as $change) {
                    if (!is_array($change)) continue;
                    $value = $change['value'] ?? [];
                    if (!is_array($value)) $value = [];
                    $phoneId = (string)($value['metadata']['phone_number_id'] ?? '');
                    $account = $phoneId !== '' ? accountByPhone($phoneId) : accountByWaba($entryId);
                    // Evento sem vinculacao verificavel a tenant nunca entra no banco.
                    if (!$account || ($entryId!=='' && $account['waba_id']!==$entryId)) continue;
                    $tenantId = (int)$account['tenant_id'];
                    $accountId = (int)$account['id'];
                    $eventKey = hash('sha256', $entryId . "\n" . jsonString($change));
                    $field = substr((string)($change['field'] ?? 'unknown'), 0, 80);
                    $stmt = $pdo->prepare('INSERT IGNORE INTO whatsapp_webhook_events(tenant_id,account_id,event_key,event_type,payload) VALUES (?,?,?,?,?)');
                    $stmt->execute([$tenantId, $accountId, $eventKey, $field, jsonString(['entry_id'=>$entryId,'change'=>$change])]);
                    if ($stmt->rowCount() === 0) continue; // Meta pode enviar novamente o mesmo evento
                    $saved++;
                    if ($accountId !== null) {
                        self::updateIncoming($pdo, $tenantId, $accountId, $value);
                        if (!empty($account['callback_url']) && !empty($account['callback_secret_box'])) {
                            $stmt = $pdo->prepare('INSERT IGNORE INTO whatsapp_jobs(tenant_id,account_id,kind,idempotency_key,payload)
                                                   VALUES (?,?,"callback",?,?)');
                            $stmt->execute([$tenantId,$accountId,'event-' . $eventKey,jsonString([
                                'event_id'=>$eventKey,'event_type'=>$field,'entry_id'=>$entryId,
                                'change'=>$change
                            ])]);
                        }
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e; // A Meta deve repetir: jamais confirmar evento não gravado
        }
        return ['accepted'=>true,'events_saved'=>$saved];
    }
    private static function updateIncoming(PDO $pdo, int $tenantId, int $accountId, array $value): void {
        $names = [];
        foreach (($value['contacts'] ?? []) as $contact) {
            $waId = (string)($contact['wa_id'] ?? '');
            if (validPhone($waId)) $names[$waId] = (string)($contact['profile']['name'] ?? '');
        }
        foreach (($value['messages'] ?? []) as $message) {
            if (!is_array($message)) continue;
            $from = (string)($message['from'] ?? '');
            $waMessageId = (string)($message['id'] ?? '');
            if (!validPhone($from) || $waMessageId === '') continue;
            $ts = (int)($message['timestamp'] ?? time());
            $ts = $ts > 0 && $ts <= time() ? $ts : time();
            $receivedAt = gmdate('Y-m-d H:i:s', $ts);
            $name = $names[$from] ?? null;
            $s = $pdo->prepare('INSERT INTO whatsapp_contacts(tenant_id,account_id,wa_id,display_name,last_inbound_at)
                VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE
                display_name=COALESCE(VALUES(display_name),display_name),
                last_inbound_at=GREATEST(COALESCE(last_inbound_at,"1970-01-01 00:00:00"),VALUES(last_inbound_at))');
            $s->execute([$tenantId,$accountId,$from,$name ?: null,$receivedAt]);
            $s = $pdo->prepare('INSERT IGNORE INTO whatsapp_messages(tenant_id,account_id,wa_id,direction,whatsapp_id,message_type,status,payload)
                VALUES (?,?,?,"inbound",?,?,"received",?)');
            $s->execute([$tenantId,$accountId,$from,$waMessageId,substr((string)($message['type'] ?? 'unknown'),0,40),jsonString($message)]);
        }
        foreach (($value['statuses'] ?? []) as $st) {
            if (!is_array($st) || empty($st['id']) || empty($st['status'])) continue;
            $status = substr((string)$st['status'], 0, 30);
            $s = $pdo->prepare('UPDATE whatsapp_messages SET status=? WHERE tenant_id=? AND account_id=? AND whatsapp_id=?');
            $s->execute([$status,$tenantId,$accountId,(string)$st['id']]);
        }
    }
}
