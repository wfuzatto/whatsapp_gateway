<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Graph.php';
require_once __DIR__ . '/Rules.php';

final class Jobs {
    public static function enqueueSend(array $account, array $payload, string $key): array {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,180}$/D', $key)) throw new HttpError('Invalid Idempotency-Key');
        $s = db()->prepare('INSERT IGNORE INTO jobs(account_id,kind,idempotency_key,payload)
                             VALUES (?,"send",?,?)');
        $s->execute([$account['id'],$key,jsonString($payload)]);
        $added = $s->rowCount() === 1;
        $s = db()->prepare('SELECT id,status,payload,created_at FROM jobs WHERE account_id=? AND kind="send" AND idempotency_key=?');
        $s->execute([$account['id'],$key]);
        $job = $s->fetch();
        if (!$job) throw new RuntimeException('Unable to load queued job');
        if (!$added && json_decode($job['payload'],true) != $payload)
            throw new HttpError('Idempotency-Key already used for a different message',409);
        return ['job_id'=>(int)$job['id'],'status'=>$job['status'],'duplicate'=>!$added];
    }
    public static function claim(): ?array {
        $pdo = db();
        // Nao reenviar jobs "processing" cujo worker caiu: entrega pode ja ter acontecido.
        $pdo->exec("UPDATE jobs SET status='uncertain',last_error='Worker stopped after claiming send'
                    WHERE kind='send' AND status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $pdo->exec("UPDATE jobs SET status='retry',locked_at=NULL
                    WHERE kind='callback' AND status='processing' AND locked_at < UTC_TIMESTAMP() - INTERVAL 10 MINUTE");
        $pdo->beginTransaction();
        try {
            $s = $pdo->query("SELECT * FROM jobs WHERE status IN ('queued','retry')
                              AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY id
                              LIMIT 1 FOR UPDATE SKIP LOCKED");
            $job = $s->fetch();
            if (!$job) { $pdo->commit(); return null; }
            $pdo->prepare("UPDATE jobs SET status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP()
                            WHERE id=?")->execute([$job['id']]);
            $pdo->commit();
            $job['attempts'] = (int)$job['attempts']+1;
            return $job;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    public static function process(array $job): void {
        $s = db()->prepare('SELECT * FROM accounts WHERE id=?');
        $s->execute([$job['account_id']]);
        $account = $s->fetch();
        if (!$account) { self::finish($job,'failed',[], 'Account no longer exists'); return; }
        $payload = json_decode($job['payload'],true);
        if (!is_array($payload)) { self::finish($job,'failed',[],'Malformed job'); return; }
        try {
            if ($job['kind'] === 'send') {
                // Consentimento/janela conferidos novamente na hora real do envio.
                Rules::ensureConsentAndWindow((int)$account['id'], $payload);
                $result = Graph::call($account,'POST',$account['phone_number_id'] . '/messages',$payload);
                Rules::storeOutgoing($account,$payload,$result);
            } else {
                $result = self::sendCallback($account,$payload,$job['id']);
            }
            self::finish($job,'done',$result);
        } catch (MetaFailure $e) {
            if ($e->transportUncertain || $e->upstreamStatus >= 500) {
                // Sem garantia Meta de exactly-once, NAO reenviar automaticamente.
                self::finish($job,'uncertain',$e->metaBody,'Delivery uncertain; inspect status before manual retry');
            } elseif ($e->upstreamStatus === 429) {
                self::retry($job, 'Meta rate limit 429');
            } else self::finish($job,'failed',$e->metaBody,'Meta rejected request: HTTP ' . $e->upstreamStatus);
        } catch (HttpError $e) {
            self::finish($job,'failed',[],$e->getMessage());
        } catch (Throwable $e) {
            if ($job['kind'] === 'callback') self::retry($job,'Callback delivery failed');
            else self::finish($job,'uncertain',[],'Unexpected send error; manual reconciliation required');
        }
    }
    private static function sendCallback(array $account,array $payload,int $id): array {
        $url = (string)($account['callback_url'] ?? '');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
            !empty($parts['user']) || !empty($parts['pass']) ||
            strtolower($parts['host']) === 'localhost' || filter_var($parts['host'],FILTER_VALIDATE_IP))
            throw new RuntimeException('Callback URL must be a trusted HTTPS hostname');
        $body = jsonString($payload);
        $signature = 'sha256=' . hash_hmac('sha256',$body,unseal((string)$account['callback_secret_box']));
        $ch = curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Gateway-Signature-256: '.$signature,
                                 'X-Gateway-Event-ID: '.$payload['event_id']],
            CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) throw new RuntimeException('Callback failed');
        return ['http_status'=>$status,'job_id'=>$id];
    }
    private static function finish(array $job,string $state,array $result=[],?string $error=null): void {
        $s = db()->prepare('UPDATE jobs SET status=?,response=?,last_error=?,locked_at=NULL WHERE id=?');
        $s->execute([$state,jsonString($result),$error,$job['id']]);
    }
    private static function retry(array $job,string $error): void {
        if ((int)$job['attempts'] >= (int)$job['max_attempts']) {
            self::finish($job,'failed',[],$error);
            return;
        }
        $delay = min(3600, (int)pow(2, min((int)$job['attempts'],10))*10 + random_int(0,10));
        $s = db()->prepare("UPDATE jobs SET status='retry',last_error=?,locked_at=NULL,
                            next_attempt_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND) WHERE id=?");
        $s->execute([$error,$delay,$job['id']]);
    }
}
