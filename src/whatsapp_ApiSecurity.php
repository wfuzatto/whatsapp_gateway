<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';

/** Scoped machine credentials for REST and MCP integrations. */
final class WhatsAppApiSecurity {
    public const SCOPES = [
        'tenant:read','accounts:read','conversations:read','conversations:mark_read',
        'messages:read','messages:send','messages:read_receipt','templates:read','templates:manage',
        'contacts:read','contacts:consent','events:read','jobs:read','jobs:retry',
        'media:upload','media:read','media:delete','meta:manage','mcp:connect'
    ];

    // Mesmos escopos preselecionados no formulario de integracao.
    public const DEFAULT_SCOPES = [
        'mcp:connect','accounts:read','conversations:read','conversations:mark_read',
        'messages:read','messages:send','templates:read','events:read','jobs:read'
    ];

    private static function accountNullable(): bool {
        $s=db()->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='whatsapp_api_tokens'
              AND COLUMN_NAME='account_id'");
        return $s->fetchColumn()==='YES';
    }

    public static function ensureSchema(): void {
        static $ready=false;
        if($ready)return;
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_api_tokens (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          tenant_id BIGINT UNSIGNED NOT NULL,
          account_id BIGINT UNSIGNED DEFAULT NULL,
          name VARCHAR(120) NOT NULL,
          token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
          scopes_json JSON NOT NULL,
          expires_at DATETIME NOT NULL,
          active TINYINT(1) NOT NULL DEFAULT 1,
          last_used_at DATETIME DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_api_token_tenant (tenant_id,account_id,active),
          INDEX idx_api_token_expiry (expires_at,active),
          FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
          FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // DDL somente fora de transacoes: MySQL faz commit implicito.
        // Migracao aditiva: NULL representa o tenant inteiro; FKs continuam protegendo numeros especificos.
        if(!self::accountNullable()){
            try {
                db()->exec('ALTER TABLE whatsapp_api_tokens MODIFY account_id BIGINT UNSIGNED DEFAULT NULL');
            }catch(PDOException $e){
                if(!self::accountNullable())throw $e;
            }
        }
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_auto_api_token_delivery (
          tenant_id BIGINT UNSIGNED PRIMARY KEY,
          token_id BIGINT UNSIGNED NOT NULL UNIQUE,
          pending_box TEXT DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
          FOREIGN KEY (token_id) REFERENCES whatsapp_api_tokens(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_api_rate_limits (
          credential_type ENUM('token','legacy') NOT NULL,
          credential_id BIGINT UNSIGNED NOT NULL,
          bucket VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
          window_start DATETIME NOT NULL,
          request_count INT UNSIGNED NOT NULL DEFAULT 0,
          PRIMARY KEY (credential_type,credential_id,bucket,window_start),
          INDEX idx_api_rate_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ready=true;
    }

    /** Cria a credencial inicial uma unica vez, sem alterar tokens manuais ou revogados.
     *  O chamador deve preparar o esquema antes de iniciar qualquer transacao.
     */
    public static function provisionDefaultToken(int $tenantId): bool {
        $pdo=db();
        $own=!$pdo->inTransaction();
        if($own){self::ensureSchema();$pdo->beginTransaction();}
        try {
            $lock=$pdo->prepare("SELECT id FROM whatsapp_tenants WHERE id=? AND status='active' FOR UPDATE");
            $lock->execute([$tenantId]);
            $exists=$pdo->prepare('SELECT id FROM whatsapp_api_tokens WHERE tenant_id=? LIMIT 1 FOR UPDATE');
            $exists->execute([$tenantId]);
            // Historico de tokens inclui revogacoes deliberadas: nao reemitir automaticamente.
            if(!$lock->fetchColumn() || $exists->fetchColumn()){
                if($own)$pdo->commit();
                return false;
            }
            $plain='wg_'.bin2hex(random_bytes(32));
            $expires=gmdate('Y-m-d H:i:s',time()+90*86400);
            $s=$pdo->prepare('INSERT INTO whatsapp_api_tokens
                (tenant_id,account_id,name,token_hash,scopes_json,expires_at) VALUES (?,NULL,?,?,?,?)');
            $s->execute([$tenantId,'Token inicial ZapAPI',hash('sha256',$plain),jsonString(self::DEFAULT_SCOPES),$expires]);
            $id=(int)$pdo->lastInsertId();
            $s=$pdo->prepare('INSERT INTO whatsapp_auto_api_token_delivery(tenant_id,token_id,pending_box) VALUES (?,?,?)');
            $s->execute([$tenantId,$id,seal($plain)]);
            if($own)$pdo->commit();
            return true;
        }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    /** Retira a copia cifrada uma unica vez para um tenant autenticado. */
    public static function takeDefaultToken(int $tenantId): ?string {
        self::ensureSchema();
        $pdo=db();$pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT d.pending_box,k.token_hash,k.active,
                (k.expires_at>UTC_TIMESTAMP()) AS unexpired
                FROM whatsapp_auto_api_token_delivery d
                JOIN whatsapp_api_tokens k ON k.id=d.token_id AND k.tenant_id=d.tenant_id
                WHERE d.tenant_id=? AND d.pending_box IS NOT NULL FOR UPDATE');
            $s->execute([$tenantId]);$row=$s->fetch();
            $plain=null;
            if($row){
                if($row['active']&&$row['unexpired']){
                    $plain=unseal($row['pending_box']);
                    if(!hash_equals($row['token_hash'],hash('sha256',$plain)))
                        throw new RuntimeException('Invalid token delivery');
                }
                $pdo->prepare('UPDATE whatsapp_auto_api_token_delivery SET pending_box=NULL WHERE tenant_id=?')->execute([$tenantId]);
            }
            $pdo->commit();return $plain;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function createToken(int $tenantId,int $accountId,string $name,array $scopes,int $expiresDays): array {
        self::ensureSchema();
        $scopes=array_values(array_unique($scopes));
        if(!$scopes||count($scopes)>count(self::SCOPES))throw new HttpError('Selecione ao menos uma permissão.',422);
        foreach($scopes as $scope)if(!is_string($scope)||!in_array($scope,self::SCOPES,true))
            throw new HttpError('Permissão de integração inválida.',422);
        if(!in_array($expiresDays,[30,90,180,365],true))throw new HttpError('Validade de token inválida.',422);
        $token='wg_'.bin2hex(random_bytes(32));
        $expiresAt=gmdate('Y-m-d H:i:s',time()+$expiresDays*86400);
        $pdo=db();$pdo->beginTransaction();
        try {
            $s=$pdo->prepare('INSERT INTO whatsapp_api_tokens(tenant_id,account_id,name,token_hash,scopes_json,expires_at)
                VALUES (?,?,?,?,?,?)');
            $s->execute([$tenantId,$accountId,$name,hash('sha256',$token),jsonString($scopes),$expiresAt]);
            $id=(int)$pdo->lastInsertId();
            $pdo->commit();
            return ['id'=>$id,'token'=>$token,'expires_at'=>$expiresAt,'scopes'=>$scopes];
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    public static function authenticate(): array {
        self::ensureSchema();
        $auth=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
        if(!preg_match('/^Bearer\\s+([A-Za-z0-9_-]{30,160})$/D',$auth,$m))
            throw new HttpError('Token Bearer ausente ou inválido.',401);
        $hash=hash('sha256',$m[1]);
        $s=db()->prepare("SELECT k.id AS credential_id,k.id AS key_id,k.tenant_id,k.account_id,
                k.name AS credential_name,k.scopes_json,k.expires_at,t.slug AS tenant_slug,'token' AS credential_type
            FROM whatsapp_api_tokens k
            JOIN whatsapp_tenants t ON t.id=k.tenant_id
            WHERE k.token_hash=? AND k.active=1 AND k.expires_at>UTC_TIMESTAMP() AND t.status='active'
              AND (k.account_id IS NULL OR EXISTS(SELECT 1 FROM whatsapp_accounts a
                   WHERE a.tenant_id=k.tenant_id AND a.id=k.account_id AND a.status='active'))
              AND (NOT EXISTS(SELECT 1 FROM whatsapp_subscriptions sub WHERE sub.tenant_id=t.id)
                   OR EXISTS(SELECT 1 FROM whatsapp_subscriptions sub WHERE sub.tenant_id=t.id AND sub.status='active'))
            LIMIT 1");
        $s->execute([$hash]);$context=$s->fetch();
        $scoped=(bool)$context;
        if(!$context){
            $s=db()->prepare("SELECT k.id AS credential_id,k.id AS key_id,k.tenant_id,k.account_id,
                    k.name AS credential_name,NULL AS scopes_json,NULL AS expires_at,t.slug AS tenant_slug,'legacy' AS credential_type
                FROM whatsapp_api_keys k
                JOIN whatsapp_tenants t ON t.id=k.tenant_id
                WHERE k.token_hash=? AND k.active=1 AND t.status='active'
                  AND (NOT EXISTS(SELECT 1 FROM whatsapp_subscriptions sub WHERE sub.tenant_id=t.id)
                       OR EXISTS(SELECT 1 FROM whatsapp_subscriptions sub WHERE sub.tenant_id=t.id AND sub.status='active'))
                  AND (k.account_id IS NULL OR EXISTS (SELECT 1 FROM whatsapp_accounts a
                       WHERE a.tenant_id=k.tenant_id AND a.id=k.account_id AND a.status='active'))
                LIMIT 1");
            $s->execute([$hash]);$context=$s->fetch();
        }
        if(!$context)throw new HttpError('Token inválido, expirado ou integração desativada.',401);
        $context['scoped']=$scoped;
        $context['scopes']=[];
        if($scoped){
            $decoded=json_decode((string)$context['scopes_json'],true);
            if(!is_array($decoded))throw new HttpError('Permissões do token inválidas.',401);
            $context['scopes']=array_values(array_intersect($decoded,self::SCOPES));
            db()->prepare('UPDATE whatsapp_api_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$context['credential_id']]);
        }
        self::rateLimit($context,'request',120);
        return $context;
    }

    public static function requireScope(array $context,string $scope,bool $allowLegacy=true): void {
        if(!in_array($scope,self::SCOPES,true))throw new LogicException('Unknown integration scope');
        if(empty($context['scoped'])){
            if($allowLegacy&&$scope!=='mcp:connect')return;
            throw new HttpError('Esta chave legada precisa ser substituída por um token com escopos. Permissão necessária: '.$scope,403);
        }
        if(!in_array($scope,$context['scopes']??[],true))
            throw new HttpError('O token não possui a permissão necessária: '.$scope,403);
    }

    public static function rateLimit(array $context,string $bucket,int $maximum): void {
        if(!in_array($bucket,['request','write'],true))throw new LogicException('Invalid rate bucket');
        self::ensureSchema();
        $type=(string)($context['credential_type']??'legacy');
        $id=(int)($context['credential_id']??0);
        if($id<1)throw new HttpError('Credencial inválida.',401);
        $window=gmdate('Y-m-d H:i:00');
        $s=db()->prepare('INSERT INTO whatsapp_api_rate_limits(credential_type,credential_id,bucket,window_start,request_count)
            VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1');
        $s->execute([$type,$id,$bucket,$window]);
        $s=db()->prepare('SELECT request_count FROM whatsapp_api_rate_limits
            WHERE credential_type=? AND credential_id=? AND bucket=? AND window_start=?');
        $s->execute([$type,$id,$bucket,$window]);
        if((int)$s->fetchColumn()>$maximum){
            header('Retry-After: 60');
            throw new HttpError('Limite de chamadas da integração atingido. Tente novamente em até um minuto.',429);
        }
        if(random_int(1,200)===1)
            db()->exec('DELETE FROM whatsapp_api_rate_limits WHERE window_start<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 DAY)');
    }

    public static function revoke(int $tenantId,int $id,string $type): bool {
        self::ensureSchema();
        if($type==='token')$sql='UPDATE whatsapp_api_tokens SET active=0 WHERE tenant_id=? AND id=? AND active=1';
        elseif($type==='legacy')$sql='UPDATE whatsapp_api_keys SET active=0 WHERE tenant_id=? AND id=? AND active=1';
        else throw new HttpError('Tipo de credencial inválido.',422);
        $s=db()->prepare($sql);$s->execute([$tenantId,$id]);
        return $s->rowCount()>0;
    }
}
