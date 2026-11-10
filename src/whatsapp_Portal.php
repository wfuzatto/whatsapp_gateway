<?php
declare(strict_types=1);
require_once __DIR__.'/whatsapp_bootstrap.php';

/**
 * Portal de autocadastro. Um cadastro cria exatamente um tenant, um owner e
 * uma assinatura pendente. O pagamento NAO e processado neste projeto.
 */
final class WhatsAppPortal {
    private static function session(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        session_name('whatsapp_portal_session');
        $secure = ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || (config('TRUST_PROXY','0') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,
            'httponly'=>true,'samesite'=>'Lax']);
        session_start();
    }
    public static function csrf(): string {
        self::session();
        if (!isset($_SESSION['whatsapp_csrf'])) $_SESSION['whatsapp_csrf']=bin2hex(random_bytes(32));
        return (string)$_SESSION['whatsapp_csrf'];
    }
    private static function verifyCsrf(array $post): void {
        $token=$post['csrf'] ?? null;
        if (!is_string($token)||!hash_equals(self::csrf(),$token))
            throw new HttpError('Sessão expirada. Atualize a página e tente novamente.',419);
    }
    public static function money(int $cents): string {
        return 'R$ '.number_format($cents/100,2,',','.');
    }
    public static function fallbackPlans(): array {
        // Propostas comerciais de referencia — nao vinculantes nem precos oficiais.
        return [
            ['slug'=>'essencial','title'=>'Essencial','summary'=>'Sua primeira integração oficial.',
                'price_cents'=>7900,'max_numbers'=>1,'is_popular'=>0,
                'features'=>['1 número de WhatsApp','API REST e webhooks','Templates oficiais','Histórico técnico']],
            ['slug'=>'profissional','title'=>'Profissional','summary'=>'Mais números, uma única operação.',
                'price_cents'=>19900,'max_numbers'=>3,'is_popular'=>1,
                'features'=>['Até 3 números','API e webhooks','Filas e rastreamento','Múltiplos WABAs']],
            ['slug'=>'escala','title'=>'Escala','summary'=>'Estrutura para múltiplas empresas e marcas.',
                'price_cents'=>39900,'max_numbers'=>10,'is_popular'=>0,
                'features'=>['Até 10 números','Múltiplos WABAs','Logs e eventos centralizados','Suporte de implantação sob contratação']]
        ];
    }
    public static function plans(): array {
        try {
            $rows=db()->query('SELECT slug,title,summary,price_cents,max_numbers,features,is_popular
                FROM whatsapp_plans WHERE is_public=1 ORDER BY sort_order,id')->fetchAll();
            if ($rows) {
                foreach($rows as &$row) {
                    $features=json_decode((string)$row['features'],true);
                    $row['features']=is_array($features) ? $features : [];
                    $row['price_cents']=(int)$row['price_cents'];
                    $row['max_numbers']=(int)$row['max_numbers'];
                }
                unset($row);
                return $rows;
            }
        } catch(Throwable $e) {
            error_log('WhatsApp portal plans unavailable: '.get_class($e));
        }
        return self::fallbackPlans();
    }
    public static function ready(): bool {
        try {
            return (int)db()->query("SELECT COUNT(*) FROM whatsapp_plans WHERE is_public=1")->fetchColumn()>0;
        } catch(Throwable $e) {
            return false;
        }
    }
    private static function field(array $post,string $key): string {
        $value=$post[$key] ?? '';
        if (!is_string($value)) throw new HttpError('Dados do formulário inválidos.');
        return trim($value);
    }
    private static function rateLimit(string $action,int $max): void {
        $address=(string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        // REMOTE_ADDR, nao cabecalhos X-Forwarded-For arbitrarios.
        $hash=hash('sha256',$action.'|'.$address);
        $pdo=db();
        $s=$pdo->prepare('INSERT INTO whatsapp_signup_attempts(key_hash,action) VALUES (?,?)');
        $s->execute([$hash,$action]);
        $s=$pdo->prepare('SELECT COUNT(*) FROM whatsapp_signup_attempts
            WHERE action=? AND key_hash=? AND created_at>=UTC_TIMESTAMP()-INTERVAL 1 HOUR');
        $s->execute([$action,$hash]);
        if ((int)$s->fetchColumn()>$max)
            throw new HttpError('Limite de tentativas atingido. Tente novamente mais tarde.',429);
    }
    private static function registration(array $post): never {
        self::verifyCsrf($post);
        self::rateLimit('register',5);
        if (self::field($post,'website')!=='') throw new HttpError('Cadastro indisponível.',422);
        if (self::field($post,'accept_terms')!=='yes')
            throw new HttpError('É necessário aceitar as condições de cadastro.',422);
        $name=self::field($post,'name');
        $company=self::field($post,'company');
        $email=strtolower(self::field($post,'email'));
        $password=self::field($post,'password');
        $confirm=self::field($post,'confirm_password');
        $plan=self::field($post,'plan');
        if (strlen($name)<3 || strlen($name)>120 || strlen($company)<3 || strlen($company)>160)
            throw new HttpError('Informe seu nome e o nome da empresa (3 a 160 caracteres).',422);
        if (strlen($email)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL))
            throw new HttpError('Informe um e-mail válido.',422);
        if (strlen($password)<12 || strlen($password)>128 ||
            !preg_match('/[A-Za-z]/',$password) || !preg_match('/[0-9]/',$password))
            throw new HttpError('A senha precisa de 12 a 128 caracteres, com letras e números.',422);
        if (!hash_equals($password,$confirm))
            throw new HttpError('A confirmação da senha está diferente.',422);
        if (!preg_match('/^[a-z][a-z0-9-]{2,39}$/D',$plan))
            throw new HttpError('Selecione um plano válido.',422);
        $pdo=db();
        $pdo->beginTransaction();
        try {
            $st=$pdo->prepare('SELECT id,price_cents FROM whatsapp_plans WHERE slug=? AND is_public=1 FOR UPDATE');
            $st->execute([$plan]);$selected=$st->fetch();
            if (!$selected) throw new HttpError('Plano indisponível. Escolha outro plano.',422);
            $slug='cliente-'.bin2hex(random_bytes(8));
            $s=$pdo->prepare('INSERT INTO whatsapp_tenants(slug,name,status) VALUES (?,?,"active")');
            $s->execute([$slug,$company]);
            $tenantId=(int)$pdo->lastInsertId();
            $hash=password_hash($password, PASSWORD_DEFAULT);
            $s=$pdo->prepare('INSERT INTO whatsapp_users(tenant_id,name,email,password_hash)
                VALUES (?,?,?,?)');
            $s->execute([$tenantId,$name,$email,$hash]);
            $userId=(int)$pdo->lastInsertId();
            $s=$pdo->prepare('INSERT INTO whatsapp_subscriptions
                (tenant_id,plan_id,price_cents_snapshot,status)
                VALUES (?,?,?,"pending_activation")');
            $s->execute([$tenantId,$selected['id'],$selected['price_cents']]);
            $pdo->commit();
        } catch(Throwable $e) {
            if($pdo->inTransaction())$pdo->rollBack();
            if($e instanceof PDOException && $e->getCode()==='23000')
                throw new HttpError('Este e-mail já possui cadastro. Entre na sua conta.',409);
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['whatsapp_user_id']=$userId;
        $_SESSION['whatsapp_csrf']=bin2hex(random_bytes(32));
        self::redirect('/painel');
    }
    private static function login(array $post): never {
        self::verifyCsrf($post);
        self::rateLimit('login',15);
        $email=strtolower(self::field($post,'email'));
        $password=self::field($post,'password');
        if (strlen($email)>190 || strlen($password)>128)
            throw new HttpError('Credenciais inválidas.',401);
        $s=db()->prepare("SELECT u.id,u.password_hash FROM whatsapp_users u
            JOIN whatsapp_tenants t ON t.id=u.tenant_id
            WHERE u.email=? AND u.status='active' AND t.status='active'");
        $s->execute([$email]);$user=$s->fetch();
        if(!$user || !password_verify($password,(string)$user['password_hash'])) {
            usleep(140000);
            throw new HttpError('E-mail ou senha inválidos.',401);
        }
        session_regenerate_id(true);
        $_SESSION['whatsapp_user_id']=(int)$user['id'];
        $_SESSION['whatsapp_csrf']=bin2hex(random_bytes(32));
        db()->prepare('UPDATE whatsapp_users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$user['id']]);
        self::redirect('/painel');
    }
    private static function user(): ?array {
        $id=(int)($_SESSION['whatsapp_user_id'] ?? 0);
        if($id<=0)return null;
        $s=db()->prepare("SELECT u.id,u.name,u.email,u.tenant_id,t.name AS company,t.slug,
            s.status AS subscription_status,s.price_cents_snapshot,
            p.slug AS plan_slug,p.title AS plan_title,p.max_numbers
            FROM whatsapp_users u
            JOIN whatsapp_tenants t ON t.id=u.tenant_id
            LEFT JOIN whatsapp_subscriptions s ON s.tenant_id=t.id
            LEFT JOIN whatsapp_plans p ON p.id=s.plan_id
            WHERE u.id=? AND u.status='active' AND t.status='active' LIMIT 1");
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }
    private static function dashboard(): array {
        $user=self::user();
        if(!$user){unset($_SESSION['whatsapp_user_id']);self::redirect('/entrar');}
        $s=db()->prepare('SELECT COUNT(*) FROM whatsapp_accounts WHERE tenant_id=?');
        $s->execute([$user['tenant_id']]);$user['number_count']=(int)$s->fetchColumn();
        $s=db()->prepare('SELECT COUNT(*) FROM whatsapp_messages WHERE tenant_id=?');
        $s->execute([$user['tenant_id']]);$user['message_count']=(int)$s->fetchColumn();
        return $user;
    }
    private static function redirect(string $url): never {
        header('Location: '.$url,true,303);exit;
    }
    public static function show(string $view,array $vars=[]): void {
        self::session();
        $data=$vars;
        $data['plans']=self::plans();
        $data['ready']=self::ready();
        $data['csrf']=self::csrf();
        $mode=$view;
        if(!defined('WHATSAPP_PORTAL_VIEW'))define('WHATSAPP_PORTAL_VIEW',true);
        require dirname(__DIR__).'/public/whatsapp_portal.php';
    }
    public static function route(string $path,string $method): bool {
        if(!in_array($path,['/','/planos','/cadastro','/entrar','/painel','/sair'],true))return false;
        self::session();
        if($method==='GET' && $path==='/planos')self::redirect('/#planos');
        if($method==='POST' && $path==='/sair') {
            self::verifyCsrf($_POST);
            $_SESSION=[];session_regenerate_id(true);
            self::redirect('/');
        }
        if($path==='/sair')throw new HttpError('Método não permitido.',405);
        if($method==='POST' && in_array($path,['/cadastro','/entrar'],true)) {
            try {
                if($path==='/cadastro')self::registration($_POST);
                self::login($_POST);
            }catch(HttpError $e) {
                http_response_code($e->httpStatus);
                self::show($path==='/cadastro'?'register':'login',['error'=>$e->getMessage(),
                    'old'=>['name'=>self::field($_POST,'name'),'company'=>self::field($_POST,'company'),
                       'email'=>self::field($_POST,'email'),'plan'=>self::field($_POST,'plan')]]);
                return true;
            }catch(Throwable $e) {
                error_log('WhatsApp portal registration/login error: '.get_class($e));
                http_response_code(503);
                self::show($path==='/cadastro'?'register':'login',
                    ['error'=>'Cadastro temporariamente indisponível. Tente novamente mais tarde.']);
                return true;
            }
        }
        if($method!=='GET')throw new HttpError('Método não permitido.',405);
        if($path==='/painel') {
            try { $user=self::dashboard(); }
            catch(Throwable $e) {
                if($e instanceof HttpError) throw $e;
                error_log('WhatsApp dashboard error: '.get_class($e));
                http_response_code(503);
                self::show('unavailable');return true;
            }
            self::show('dashboard',['user'=>$user]);return true;
        }
        if($path==='/entrar') {
            if(!empty($_SESSION['whatsapp_user_id']))self::redirect('/painel');
            self::show('login');return true;
        }
        if($path==='/cadastro') {
            if(!empty($_SESSION['whatsapp_user_id']))self::redirect('/painel');
            $selected=(string)($_GET['plano']??'');
            self::show('register',['selected'=>$selected]);return true;
        }
        self::show('landing');return true;
    }
}
