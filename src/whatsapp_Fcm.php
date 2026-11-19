<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';

/** Enfileira e envia notificações de nova mensagem pela FCM HTTP v1. */
final class WhatsAppFcm {
    private const PROJECT_ID='zapapi-ffc8b';
    private const TOKEN_URL='https://oauth2.googleapis.com/token';
    private static bool $schemaReady=false;
    private static ?string $accessToken=null;
    private static int $accessTokenExpiresAt=0;

    public static function ensureSchema(): void {
        if(self::$schemaReady)return;
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_mobile_push_jobs (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          account_id BIGINT UNSIGNED NOT NULL,
          message_id BIGINT UNSIGNED NOT NULL,
          wa_id VARCHAR(32) NOT NULL,
          status ENUM('queued','processing','retry','done','failed') NOT NULL DEFAULT 'queued',
          attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
          next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          last_error VARCHAR(500) DEFAULT NULL,
          locked_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_mobile_push_message (tenant_id,message_id),
          INDEX idx_mobile_push_claim (status,next_attempt_at,id),
          FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        self::$schemaReady=true;
    }

    public static function enqueue(PDO $pdo,int $tenantId,int $accountId,int $messageId,string $waId): int {
        if($messageId<1||!validPhone($waId))return 0;
        $s=$pdo->prepare("INSERT IGNORE INTO whatsapp_mobile_push_jobs
            (tenant_id,account_id,message_id,wa_id) VALUES (?,?,?,?)");
        $s->execute([$tenantId,$accountId,$messageId,$waId]);
        return $s->rowCount()===1?(int)$pdo->lastInsertId():0;
    }

    private static function serviceAccountFile(): string {
        $configured=config('FCM_SERVICE_ACCOUNT_FILE');
        return $configured!==''?$configured:dirname(__DIR__,2).'/whatsapp_firebase_service_account.json';
    }

    private static function credentialsAvailable(): bool {
        $file=self::serviceAccountFile();
        return is_file($file)&&!is_link($file)&&is_readable($file);
    }

    private static function serviceAccount(): array {
        $file=self::serviceAccountFile();
        $real=realpath($file);
        if($real===false||!is_file($real)||is_link($file)||!is_readable($real))
            throw new RuntimeException('FCM service account file is unavailable');
        $documentRoot=(string)($_SERVER['DOCUMENT_ROOT']??'');
        $publicRoot=$documentRoot!==''?realpath($documentRoot):false;
        if($publicRoot!==false&&str_starts_with($real,rtrim($publicRoot,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))
            throw new RuntimeException('FCM service account must be outside the public web root');
        $raw=file_get_contents($real);
        try{$data=is_string($raw)?json_decode($raw,true,32,JSON_THROW_ON_ERROR):null;}
        catch(Throwable $e){throw new RuntimeException('FCM service account JSON is invalid');}
        $expected=config('FCM_PROJECT_ID',self::PROJECT_ID);
        if(!is_array($data)||($data['type']??'')!=='service_account'||
            ($data['project_id']??'')!==$expected||$expected!==self::PROJECT_ID||
            !filter_var($data['client_email']??null,FILTER_VALIDATE_EMAIL)||
            !is_string($data['private_key']??null)||!str_contains($data['private_key'],'PRIVATE KEY'))
            throw new RuntimeException('FCM service account does not match the configured Firebase project');
        return $data;
    }

    private static function base64Url(string $value): string {
        return rtrim(strtr(base64_encode($value),'+/','-_'),'=');
    }

    private static function accessToken(array $account): string {
        if(self::$accessToken!==null&&self::$accessTokenExpiresAt>time()+60)return self::$accessToken;
        $issued=time();
        $header=self::base64Url(jsonString(['alg'=>'RS256','typ'=>'JWT']));
        $claims=self::base64Url(jsonString([
            'iss'=>$account['client_email'],
            'scope'=>'https://www.googleapis.com/auth/firebase.messaging',
            'aud'=>self::TOKEN_URL,'iat'=>$issued,'exp'=>$issued+3600
        ]));
        $unsigned=$header.'.'.$claims;
        $privateKey=openssl_pkey_get_private($account['private_key']);
        if($privateKey===false)throw new RuntimeException('FCM service account private key is invalid');
        $signature='';
        try{$signed=openssl_sign($unsigned,$signature,$privateKey,OPENSSL_ALGO_SHA256);}
        finally{openssl_free_key($privateKey);}
        if(!$signed)throw new RuntimeException('FCM OAuth assertion could not be signed');
        $assertion=$unsigned.'.'.self::base64Url($signature);
        $ch=curl_init(self::TOKEN_URL);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query([
                'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$assertion
            ]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        $result=is_string($raw)?json_decode($raw,true):null;
        if($status<200||$status>=300||!is_array($result)||!is_string($result['access_token']??null))
            throw new RuntimeException('FCM OAuth token request failed with HTTP '.$status);
        self::$accessToken=$result['access_token'];
        self::$accessTokenExpiresAt=$issued+max(60,(int)($result['expires_in']??3600));
        return self::$accessToken;
    }

    private static function send(string $accessToken,string $deviceToken,array $job): array {
        $body=['message'=>[
            'token'=>$deviceToken,
            'data'=>[
                'type'=>'new_message',
                'conversation_id'=>$job['account_id'].'-'.$job['wa_id'],
                'message_id'=>(string)$job['message_id']
            ],
            'notification'=>[
                'title'=>'ZapApi • Nova mensagem',
                'body'=>'Há uma nova mensagem para sua equipe.'
            ],
            'android'=>[
                'priority'=>'HIGH','ttl'=>'3600s',
                'notification'=>['channel_id'=>'messages']
            ]
        ]];
        $url='https://fcm.googleapis.com/v1/projects/'.self::PROJECT_ID.'/messages:send';
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>jsonString($body),CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$accessToken,'Content-Type: application/json'],
            CURLOPT_TIMEOUT=>12,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
        if($status>=200&&$status<300)return ['ok'=>true,'status'=>$status];
        $decoded=is_string($raw)?json_decode($raw,true):null;
        $details=is_array($decoded)?($decoded['error']['details']??[]):[];
        $errorCode='';
        foreach($details as $detail)if(is_array($detail)&&is_string($detail['errorCode']??null)){
            $errorCode=$detail['errorCode'];break;
        }
        return ['ok'=>false,'status'=>$status,'error_code'=>$errorCode];
    }

    public static function claim(?int $pushJobId=null): ?array {
        self::ensureSchema();
        if(!self::credentialsAvailable())return null;
        $pdo=db();
        $pdo->exec("UPDATE whatsapp_mobile_push_jobs SET status='retry',locked_at=NULL,
            next_attempt_at=UTC_TIMESTAMP() WHERE status='processing'
            AND locked_at<UTC_TIMESTAMP()-INTERVAL 2 MINUTE");
        $pdo->beginTransaction();
        try{
            $sql="SELECT j.* FROM whatsapp_mobile_push_jobs j
                JOIN whatsapp_tenants t ON t.id=j.tenant_id AND t.status='active'
                JOIN whatsapp_accounts a ON a.id=j.account_id AND a.tenant_id=j.tenant_id AND a.status='active'
                LEFT JOIN whatsapp_subscriptions sub ON sub.tenant_id=j.tenant_id
                WHERE (sub.id IS NULL OR sub.status='active') AND j.status IN ('queued','retry')
                AND j.next_attempt_at<=UTC_TIMESTAMP() AND j.created_at>UTC_TIMESTAMP()-INTERVAL 1 DAY";
            if($pushJobId!==null)$sql.=' AND j.id=?';
            $sql.=" ORDER BY j.id LIMIT 1 FOR UPDATE SKIP LOCKED";
            $s=$pdo->prepare($sql);
            if($pushJobId!==null)$s->execute([$pushJobId]);else $s->execute();
            $job=$s->fetch();
            if(!$job){$pdo->commit();return null;}
            $pdo->prepare("UPDATE whatsapp_mobile_push_jobs SET status='processing',attempts=attempts+1,locked_at=UTC_TIMESTAMP()
                WHERE id=?")->execute([$job['id']]);
            $pdo->commit();
            $job['attempts']=(int)$job['attempts']+1;
            return $job;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private static function finish(array $job,string $status,?string $error=null): void {
        db()->prepare('UPDATE whatsapp_mobile_push_jobs SET status=?,last_error=?,locked_at=NULL WHERE id=?')
            ->execute([$status,$error?substr($error,0,500):null,$job['id']]);
    }

    private static function retry(array $job,string $error): void {
        if((int)$job['attempts']>=(int)$job['max_attempts']){self::finish($job,'failed',$error);return;}
        $delay=min(300,(int)pow(2,min((int)$job['attempts'],8))*2+random_int(0,5));
        db()->prepare("UPDATE whatsapp_mobile_push_jobs SET status='retry',last_error=?,locked_at=NULL,
            next_attempt_at=TIMESTAMPADD(SECOND,?,UTC_TIMESTAMP()) WHERE id=?")
            ->execute([substr($error,0,500),$delay,$job['id']]);
    }

    public static function process(array $job): void {
        try{$account=self::serviceAccount();$accessToken=self::accessToken($account);}
        catch(Throwable $e){
            $safeError=$e->getMessage();
            error_log('WhatsApp FCM setup/send failed: '.get_class($e).' '.$safeError);
            if(preg_match('/FCM OAuth token request failed with HTTP ([0-9]+)/',$safeError,$match)){
                $status=(int)$match[1];
                if($status===0||$status===429||$status>=500){self::retry($job,'FCM OAuth temporarily unavailable (HTTP '.$status.')');return;}
            }
            self::finish($job,'failed',$safeError);
            return;
        }
        $s=db()->prepare("SELECT d.id,d.token_hash,d.token_box FROM whatsapp_mobile_devices d
            JOIN whatsapp_mobile_sessions ms ON ms.id=d.session_id AND ms.tenant_id=d.tenant_id AND ms.user_id=d.user_id
            WHERE d.tenant_id=? AND ms.revoked_at IS NULL AND ms.expires_at>UTC_TIMESTAMP()");
        $s->execute([$job['tenant_id']]);$devices=$s->fetchAll();
        if(!$devices){self::finish($job,'done');return;}
        $transientError=null;
        foreach($devices as $device){
            try{$token=unseal((string)$device['token_box']);}
            catch(Throwable $e){db()->prepare('DELETE FROM whatsapp_mobile_devices WHERE id=?')->execute([$device['id']]);continue;}
            $result=self::send($accessToken,$token,$job);
            if($result['ok'])continue;
            if(($result['status']??0)===404||($result['error_code']??'')==='UNREGISTERED'){
                db()->prepare('DELETE FROM whatsapp_mobile_devices WHERE id=?')->execute([$device['id']]);
                continue;
            }
            $status=(int)($result['status']??0);
            if($status===429||$status>=500||$status===0){$transientError='FCM temporarily unavailable (HTTP '.$status.')';break;}
            $transientError='FCM rejected the device message (HTTP '.$status.')';
            if($status>=400&&$status<500){self::finish($job,'failed',$transientError);return;}
        }
        if($transientError!==null){self::retry($job,$transientError);return;}
        self::finish($job,'done');
    }
}
