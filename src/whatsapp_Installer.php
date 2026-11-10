<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';
require_once __DIR__.'/whatsapp_Portal.php';

/**
 * Instalador do Gateway. Requer codigo aleatorio criado no FTP ROOT, fora
 * de public_html. O endereco do banco e fixado no servidor, nao em POST.
 */
final class WhatsAppInstaller {
    private const DB_HOST='zapapi1.mysql.dbaas.com.br';
    private const DB_NAME='zapapi1';
    private const DB_USER='zapapi1';
    private const DB_PORT='3306';

    private static function root(): string { return dirname(__DIR__,2); }
    private static function path(string $file): string {
        $name=match($file) {
            'key'=>'whatsapp_install_key.txt',
            'lock'=>'whatsapp_install_done.txt',
            'mutex'=>'whatsapp_install_mutex.lock',
            'env'=>'.env',
            default=>throw new RuntimeException('Caminho inválido')
        };
        if(config('APP_ENV')==='test') {
            $override=config('WHATSAPP_INSTALL_TEST_DIR');
            if($override!=='')return rtrim($override,'/').'/'.$name;
        }
        return self::root().'/'.$name;
    }
    private static function testMode(): bool { return config('APP_ENV')==='test'; }
    private static function mysqlConfig(): array {
        // Overrides apenas para CI; em producao, parametros fixos.
        if(self::testMode())return [
            config('DB_HOST','127.0.0.1'),config('DB_PORT','3306'),
            config('DB_NAME'),config('DB_USER')
        ];
        return [self::DB_HOST,self::DB_PORT,self::DB_NAME,self::DB_USER];
    }
    private static function htmlEscape(mixed $v): string {
        return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
    private static function currentAdmin(): ?array {
        WhatsAppPortal::csrf(); // abre a mesma sessao segura usada no portal
        $id=(int)($_SESSION['whatsapp_admin_id']??0);
        if($id<1)return null;
        try {
            $st=db()->prepare("SELECT id,name,email FROM whatsapp_admins
                WHERE id=? AND status='active' LIMIT 1");
            $st->execute([$id]);return $st->fetch() ?: null;
        }catch(Throwable $e){return null;}
    }
    private static function isInstalled(): bool {
        if(is_file(self::path('lock')))return true;
        if(config('DB_PASSWORD')==='')return false;
        try {
            return (int)db()->query('SELECT COUNT(*) FROM whatsapp_admins')->fetchColumn()>0;
        }catch(Throwable $e){return false;}
    }
    private static function csrfCheck(array $post): void {
        $provided=$post['csrf']??null;
        if(!is_string($provided)||!hash_equals(WhatsAppPortal::csrf(),$provided))
            throw new HttpError('A sessão expirou. Recarregue o formulário.',419);
    }
    private static function input(array $post,string $key): string {
        $value=$post[$key]??'';
        if(!is_string($value))throw new HttpError('Dados inválidos.',422);
        return trim($value);
    }
    private static function redirect(string $path): never {
        header('Location: '.$path,true,303);exit;
    }
    private static function passwordValid(string $password): bool {
        return strlen($password)>=12 && strlen($password)<=128 &&
            (bool)preg_match('/[A-Za-z]/',$password) &&
            (bool)preg_match('/[0-9]/',$password);
    }
    private static function render(string $page,array $data=[]): void {
        if(!defined('WHATSAPP_INSTALL_VIEW'))define('WHATSAPP_INSTALL_VIEW',true);
        $mode=$page;
        $values=$data;
        $csrf=WhatsAppPortal::csrf();
        $protectedKey=is_file(self::path('key')) && is_readable(self::path('key'));
        $db=self::mysqlConfig();
        require dirname(__DIR__).'/public/whatsapp_install.php';
    }
    private static function createConfig(string $password): string {
        [$host,$port,$name,$user]=self::mysqlConfig();
        // Parser de .env deliberadamente simples; impedir caracteres de controle
        // e delimitadores que mudariam a senha ao carregar.
        if($password===''||strlen($password)>250 || trim($password)!==$password ||
            preg_match('/[\x00-\x1F\x7F"\x27]/',$password))
            throw new HttpError('Senha do banco contém caracteres incompatíveis com o arquivo de configuração.',422);
        return implode("\n",[
            '# WhatsApp Gateway: configuracao privada gerada pelo instalador',
            'APP_ENV=production',
            'APP_REQUIRE_HTTPS=1',
            'TRUST_PROXY=0',
            'GRAPH_VERSION=v26.0',
            'DB_HOST='.$host,
            'DB_PORT='.$port,
            'DB_NAME='.$name,
            'DB_USER='.$user,
            'DB_PASSWORD='.$password,
            'MASTER_KEY_BASE64='.base64_encode(random_bytes(32)),
            'META_APP_SECRET=',
            'META_VERIFY_TOKEN='.bin2hex(random_bytes(24)),
            'MAX_BODY_BYTES=4194304',
            'MAX_UPLOAD_BYTES=104857600',
            ''
        ]);
    }
    private static function connection(string $password): PDO {
        [$host,$port,$dbName,$user]=self::mysqlConfig();
        if($dbName===''||!ctype_digit($port))throw new RuntimeException('Configuração de banco inválida');
        $pdo=new PDO("mysql:host=$host;port=$port;dbname=$dbName;charset=utf8mb4",
            $user,$password,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>8
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
    private static function setupSchema(PDO $pdo): void {
        $sql=file_get_contents(dirname(__DIR__).'/database/whatsapp_schema.sql');
        if(!is_string($sql))throw new RuntimeException('Esquema SQL indisponível');
        preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?whatsapp_[a-z_]+\s*\(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s',
            $sql,$statements);
        if(count($statements[0])!==14)
            throw new RuntimeException('Esquema incompatível com o instalador');
        foreach($statements[0] as $statement) {
            $statement=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$statement,1);
            $pdo->exec($statement);
        }
        // Criar/atualizar somente o catalogo inicial; nao remover dados existentes.
        $start=strpos($sql,'INSERT INTO whatsapp_plans(');
        if($start===false)throw new RuntimeException('Planos iniciais ausentes');
        $pdo->exec(substr($sql,$start));
        $existing=(int)$pdo->query('SELECT COUNT(*) FROM whatsapp_admins')->fetchColumn();
        if($existing>0)throw new HttpError('Já existe um administrador. A reinstalação foi bloqueada.',403);
    }
    private static function install(array $post): never {
        self::csrfCheck($post);
        if(self::isInstalled())throw new HttpError('Instalação já concluída.',403);
        $keyPath=self::path('key');
        if(!is_file($keyPath)||!is_readable($keyPath))
            throw new HttpError('A chave de instalação ainda não foi preparada fora do public_html.',503);
        $expected=trim((string)file_get_contents($keyPath));
        $provided=self::input($post,'install_key');
        if(strlen($expected)<36||strlen($provided)>200||!hash_equals($expected,$provided))
            throw new HttpError('Chave de instalação incorreta.',403);
        $name=self::input($post,'admin_name');
        $email=strtolower(self::input($post,'admin_email'));
        $adminPassword=self::input($post,'admin_password');
        $confirm=self::input($post,'admin_confirm');
        $dbPassword=self::input($post,'db_password');
        if(strlen($name)<3||strlen($name)>120 || strlen($email)>190 ||
            !filter_var($email,FILTER_VALIDATE_EMAIL))
            throw new HttpError('Informe nome e e-mail válidos.',422);
        if(!self::passwordValid($adminPassword)||!hash_equals($adminPassword,$confirm))
            throw new HttpError('Senha do administrador: mínimo de 12 caracteres, letras e números; confirme corretamente.',422);
        $envContent=self::createConfig($dbPassword);
        $envFile=self::path('env');
        if(is_file($envFile))throw new HttpError('Já existe um .env privado; a instalação não irá sobrescrevê-lo.',409);
        $parent=dirname($envFile);
        if(!is_dir($parent)||!is_writable($parent))
            throw new HttpError('A hospedagem não permite gravar o .env acima do public_html.',503);
        $mutex=fopen(self::path('mutex'),'c');
        if($mutex===false||!flock($mutex,LOCK_EX|LOCK_NB))
            throw new HttpError('Outra instalação está em andamento.',409);
        try {
            if(self::isInstalled()||is_file($envFile))throw new HttpError('Instalação já iniciada ou concluída.',409);
            try{$pdo=self::connection($dbPassword);}
            catch(PDOException $e) {
                throw new HttpError('Não foi possível conectar ao banco. Confirme senha e permissões do MySQL.',422);
            }
            self::setupSchema($pdo);
            $pdo->beginTransaction();
            try {
                $s=$pdo->prepare("INSERT INTO whatsapp_admins(name,email,password_hash,role)
                    VALUES (?,?,?,'superadmin')");
                $s->execute([$name,$email,password_hash($adminPassword,PASSWORD_DEFAULT)]);
                $adminId=(int)$pdo->lastInsertId();
                // 'x' impede substituir um arquivo de configuracao concorrente.
                $handle=fopen($envFile,'x');
                if($handle===false)throw new RuntimeException('Falha ao criar arquivo .env privado');
                try {
                    @chmod($envFile,0600);
                    $size=strlen($envContent);
                    $wrote=fwrite($handle,$envContent);
                    if($wrote!==$size)throw new RuntimeException('Falha na gravação do .env');
                    fflush($handle);
                } finally { fclose($handle); }
                $pdo->commit();
            }catch(Throwable $e) {
                if($pdo->inTransaction())$pdo->rollBack();
                if(is_file($envFile))@unlink($envFile);
                throw $e;
            }
            $lock=hash('sha256','installed|'.$email.'|'.microtime(true));
            if(file_put_contents(self::path('lock'),$lock."\n",LOCK_EX)===false)
                error_log('WhatsApp installer: lock file could not be written; database admin is authoritative');
            @chmod(self::path('lock'),0600);
            @unlink($keyPath);
            session_regenerate_id(true);
            $_SESSION['whatsapp_admin_id']=$adminId;
            $_SESSION['whatsapp_csrf']=bin2hex(random_bytes(32));
            self::redirect('/admin');
        }finally {
            flock($mutex,LOCK_UN);
            fclose($mutex);
        }
    }
    private static function login(array $post): never {
        self::csrfCheck($post);
        $email=strtolower(self::input($post,'email'));
        $password=self::input($post,'password');
        if(strlen($email)>190||strlen($password)>128)throw new HttpError('Credenciais inválidas.',401);
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $hash=hash('sha256','admin-login|'.$ip);
        $st=db()->prepare("INSERT INTO whatsapp_signup_attempts(key_hash,action) VALUES (?,'login')");
        $st->execute([$hash]);
        $st=db()->prepare("SELECT COUNT(*) FROM whatsapp_signup_attempts
            WHERE action='login' AND key_hash=? AND created_at>UTC_TIMESTAMP()-INTERVAL 1 HOUR");
        $st->execute([$hash]);
        if((int)$st->fetchColumn()>12)throw new HttpError('Muitas tentativas. Tente novamente mais tarde.',429);
        $s=db()->prepare("SELECT id,password_hash FROM whatsapp_admins
                WHERE email=? AND status='active' LIMIT 1");
        $s->execute([$email]);$admin=$s->fetch();
        if(!$admin||!password_verify($password,(string)$admin['password_hash'])){
            usleep(140000);
            throw new HttpError('E-mail ou senha inválidos.',401);
        }
        session_regenerate_id(true);
        $_SESSION['whatsapp_admin_id']=(int)$admin['id'];
        $_SESSION['whatsapp_csrf']=bin2hex(random_bytes(32));
        db()->prepare('UPDATE whatsapp_admins SET last_login_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$admin['id']]);
        self::redirect('/admin');
    }
    private static function action(array $post): never {
        self::csrfCheck($post);
        if(!self::currentAdmin())throw new HttpError('Acesso negado.',403);
        $action=self::input($post,'action');
        $slug=self::input($post,'tenant_slug');
        if(!in_array($action,['activate','suspend'],true) ||
           !preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/D',$slug))
            throw new HttpError('Ação ou cliente inválido.',422);
        $s=db()->prepare("UPDATE whatsapp_subscriptions s
            JOIN whatsapp_tenants t ON t.id=s.tenant_id
            SET s.status=?,s.started_at=CASE WHEN ?='active'
                 THEN COALESCE(s.started_at,UTC_TIMESTAMP()) ELSE s.started_at END
            WHERE t.slug=?");
        $state=$action==='activate'?'active':'suspended';
        $s->execute([$state,$state,$slug]);
        self::redirect('/admin');
    }
    private static function dashboard(): array {
        $s=db()->query("SELECT t.slug,t.name AS company,u.email,
            p.title AS plan,s.price_cents_snapshot,s.status,s.created_at
            FROM whatsapp_subscriptions s
            JOIN whatsapp_tenants t ON t.id=s.tenant_id
            JOIN whatsapp_plans p ON p.id=s.plan_id
            LEFT JOIN whatsapp_users u ON u.tenant_id=t.id
            ORDER BY (s.status='pending_activation') DESC,s.id DESC LIMIT 100");
        $subs=$s->fetchAll();
        $counts=db()->query("SELECT status,COUNT(*) AS amount FROM whatsapp_subscriptions GROUP BY status")->fetchAll();
        return ['subscriptions'=>$subs,'counts'=>$counts];
    }
    public static function route(string $path,string $method): bool {
        if(!in_array($path,['/install','/admin','/admin/entrar','/admin/sair','/admin/assinatura'],true))
            return false;
        WhatsAppPortal::csrf();
        try {
            $installed=self::isInstalled();
            if($path==='/install') {
                if($method==='POST') {
                    self::install($_POST);
                }
                if($method!=='GET')throw new HttpError('Método não permitido.',405);
                self::render($installed?'installed':'install');
                return true;
            }
            if(!$installed) {
                self::redirect('/install');
            }
            if($path==='/admin/entrar') {
                if($method==='POST')self::login($_POST);
                if($method!=='GET')throw new HttpError('Método não permitido.',405);
                if(self::currentAdmin())self::redirect('/admin');
                self::render('login');return true;
            }
            if($path==='/admin/sair' && $method==='POST') {
                self::csrfCheck($_POST);
                unset($_SESSION['whatsapp_admin_id']);
                session_regenerate_id(true);
                self::redirect('/admin/entrar');
            }
            if($path==='/admin/assinatura' && $method==='POST')self::action($_POST);
            if($method!=='GET')throw new HttpError('Método não permitido.',405);
            $admin=self::currentAdmin();
            if(!$admin)self::redirect('/admin/entrar');
            self::render('dashboard',['admin'=>$admin,'stats'=>self::dashboard()]);
            return true;
        } catch(HttpError $e) {
            http_response_code($e->httpStatus);
            $fallback=$path==='/install'?'install':($path==='/admin/entrar'?'login':'error');
            self::render($fallback,['error'=>$e->getMessage()]);
            return true;
        } catch(Throwable $e) {
            error_log('WhatsApp installer/admin: '.get_class($e));
            http_response_code(503);
            self::render('error',['error'=>'Não foi possível concluir a operação. Confira os requisitos do servidor.']);
            return true;
        }
    }
}
