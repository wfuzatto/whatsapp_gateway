<?php
declare(strict_types=1);

/**
 * Assistente de primeira instalação do Ponte Gateway.
 * Executado apenas por index.php enquanto installed() for falso.
 * Nenhum arquivo de configuração ou banco é apagado pelo assistente.
 */
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

function wizard_requirements(): array {
    $checks = [
        ['PHP 8.1 ou superior (atual: '.PHP_VERSION.')', PHP_VERSION_ID >= 80100],
        ['Driver PDO MySQL', class_exists('PDO') && in_array('mysql', PDO::getAvailableDrivers(), true)],
        ['Extensões curl, openssl, mbstring, iconv, fileinfo e json',
            !array_filter(['curl','openssl','mbstring','iconv','fileinfo','json'], fn($ext) => !extension_loaded($ext))],
        ['Arquivo database/schema.sql disponível', is_readable(APP_ROOT.'/database/schema.sql')],
        ['Pasta storage com permissão de escrita', is_dir(APP_ROOT.'/storage') && is_writable(APP_ROOT.'/storage')],
        ['Proteção HTTP da pasta storage presente', is_file(APP_ROOT.'/storage/.htaccess')],
        ['Sem configuração de instalação anterior', !is_file(CONFIG_FILE)],
    ];
    return $checks;
}

function wizard_connect(array $credentials, bool $empty = true): PDO {
    $host = trim((string)($credentials['host'] ?? ''));
    $name = trim((string)($credentials['name'] ?? ''));
    $user = (string)($credentials['user'] ?? '');
    $port = (int)($credentials['port'] ?? 0);
    if (!preg_match('/^[a-zA-Z0-9_.:-]{1,255}$/', $host)
        || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $name)
        || $user === '' || strlen($user) > 128 || $port < 1 || $port > 65535) {
        throw new RuntimeException('Preencha servidor, porta, nome do banco e usuário válidos.');
    }
    try {
        $pdo = new PDO(
            'mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',
            $user,
            (string)($credentials['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false,
             PDO::ATTR_TIMEOUT => 8]
        );
        if ($empty && $pdo->query('SHOW TABLES')->fetchColumn() !== false) {
            throw new RuntimeException('O banco já possui tabelas. Escolha um banco novo e vazio para evitar perda ou mistura de dados.');
        }
        return $pdo;
    } catch (PDOException $e) {
        throw new RuntimeException('Falha na conexão MySQL. Verifique host, porta, banco, usuário, senha e permissões. Código: '.$e->getCode(), 0, $e);
    }
}

function wizard_ready(array $checks): bool {
    foreach ($checks as $check) if (!$check[1]) return false;
    return true;
}

$error = '';
$checks = wizard_requirements();
$wizard = $_SESSION['ponte_setup'] ?? null;
if (!is_array($wizard) || !isset($wizard['started']) || time() - (int)$wizard['started'] > 1800) {
    $wizard = ['started' => time(), 'highest' => 1];
}
$step = max(1, min(5, (int)($_GET['step'] ?? 1), (int)$wizard['highest']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['setup_action'] ?? '');
    $targetStep = ['requirements'=>1, 'database'=>2, 'administrator'=>3, 'address'=>4, 'install'=>5][$action] ?? 1;
    $step = min($targetStep, (int)$wizard['highest']);
    try {
        csrf_check();
        if (!wizard_ready($checks)) {
            throw new RuntimeException('Corrija os requisitos assinalados antes de iniciar a instalação.');
        }
        if ($targetStep > (int)$wizard['highest']) {
            throw new RuntimeException('Etapa fora de sequência. Retorne à etapa anterior.');
        }
        if ($action === 'requirements') {
            $wizard['highest'] = max(2, (int)$wizard['highest']);
            $step = 2;
        } elseif ($action === 'database') {
            $credentials = [
                'host' => trim((string)($_POST['db_host'] ?? '127.0.0.1')),
                'port' => (int)($_POST['db_port'] ?? 3306),
                'name' => trim((string)($_POST['db_name'] ?? '')),
                'user' => trim((string)($_POST['db_user'] ?? '')),
                'password' => (string)($_POST['db_password'] ?? ''),
            ];
            $pdo = wizard_connect($credentials);
            $wizard['database'] = $credentials;
            $wizard['server_version'] = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            $wizard['highest'] = max(3, (int)$wizard['highest']);
            $step = 3;
        } elseif ($action === 'administrator') {
            if (empty($wizard['database'])) throw new RuntimeException('Configure primeiro o banco MySQL.');
            $company = trim((string)($_POST['company_name'] ?? ''));
            $name = trim((string)($_POST['admin_name'] ?? ''));
            $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
            $password = (string)($_POST['admin_password'] ?? '');
            $confirmation = (string)($_POST['admin_password_confirm'] ?? '');
            if ($company === '' || mb_strlen($company) > 180 || $name === '' || mb_strlen($name) > 160
                || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
                || strlen($password) < 12 || !hash_equals($password, $confirmation)) {
                throw new RuntimeException('Informe empresa, administrador e e-mail válidos, com senha de 12 caracteres ou mais e confirmação igual.');
            }
            $wizard['administrator'] = [
                'company' => $company, 'name' => $name, 'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT)
            ];
            $wizard['highest'] = max(4, (int)$wizard['highest']);
            $step = 4;
        } elseif ($action === 'address') {
            if (empty($wizard['administrator'])) throw new RuntimeException('Defina primeiro o administrador.');
            $url = rtrim(trim((string)($_POST['app_url'] ?? '')), '/');
            $parts = $url !== '' ? parse_url($url) : [];
            if ($url !== '' && (!filter_var($url, FILTER_VALIDATE_URL)
                || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment']))) {
                throw new RuntimeException('Informe uma URL pública HTTPS válida ou deixe em branco para configurar depois.');
            }
            $wizard['app_url'] = $url;
            $wizard['highest'] = max(5, (int)$wizard['highest']);
            $step = 5;
        } elseif ($action === 'install') {
            if ((int)$wizard['highest'] < 5 || empty($wizard['database']) || empty($wizard['administrator'])
                || !array_key_exists('app_url', $wizard)) {
                throw new RuntimeException('Conclua todas as etapas antes de instalar.');
            }
            if (is_file(CONFIG_FILE)) {
                throw new RuntimeException('Já existe storage/config.local.php. Faça backup e remova-o manualmente somente se deseja uma instalação nova.');
            }
            // A segunda verificação impede instalação em um banco que recebeu dados após a etapa 2.
            $pdo = wizard_connect($wizard['database']);
            $schema = file_get_contents(APP_ROOT.'/database/schema.sql');
            if (!$schema) throw new RuntimeException('O arquivo de estrutura SQL está vazio ou indisponível.');
            $sqlStatements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schema)));
            foreach ($sqlStatements as $sql) $pdo->exec($sql);
            $admin = $wizard['administrator'];
            $pdo->beginTransaction();
            try {
                $pdo->prepare('INSERT INTO tenants(name,slug,status,created_at) VALUES(?,?,?,NOW())')
                    ->execute([$admin['company'], bin2hex(random_bytes(8)), 'active']);
                $tenantId = (int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO users(tenant_id,name,email,password_hash,role,status,created_at) VALUES(?,?,?,?,?,?,NOW())')
                    ->execute([$tenantId, $admin['name'], $admin['email'], $admin['password_hash'], 'platform_admin', 'active']);
                $pdo->prepare('INSERT INTO tenant_meta(tenant_id,updated_at) VALUES(?,NOW())')->execute([$tenantId]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            // Só marca instalado depois de criar todas as tabelas e o administrador.
            $config = [
                'installed'=>true, 'app_key'=>bin2hex(random_bytes(32)),
                'app_url'=>(string)$wizard['app_url'], 'meta_graph_version'=>'v26.0',
                'database'=>$wizard['database'],
            ];
            $tmp = tempnam(APP_ROOT.'/storage', '.setup-');
            if ($tmp === false) throw new RuntimeException('Falha ao preparar o arquivo de configuração na pasta storage.');
            $payload = "<?php\nreturn ".var_export($config, true).";\n";
            if (file_put_contents($tmp, $payload, LOCK_EX) === false) {
                @unlink($tmp);
                throw new RuntimeException('Falha ao gravar a configuração. Confira as permissões da pasta storage.');
            }
            @chmod($tmp, 0600);
            if (is_file(CONFIG_FILE) || !@rename($tmp, CONFIG_FILE)) {
                @unlink($tmp);
                throw new RuntimeException('Não foi possível finalizar a configuração; verifique a pasta storage.');
            }
            unset($_SESSION['ponte_setup']);
            flash('Instalação concluída! Entre com o administrador e ative o autenticador em duas etapas.');
            redirect('?r=login');
        } else {
            throw new RuntimeException('Ação de instalação inválida.');
        }
        $_SESSION['ponte_setup'] = $wizard;
        redirect('?step='.$step);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('[ponte-setup] Falha na etapa '.$targetStep.': '.get_class($e).' (código '.$e->getCode().')');
        $_SESSION['ponte_setup'] = $wizard;
    }
}

$step = max(1, min(5, $step, (int)$wizard['highest']));
$db = $wizard['database'] ?? ['host'=>'127.0.0.1','port'=>3306,'name'=>'whatsapp_gateway','user'=>'root'];
$admin = $wizard['administrator'] ?? [];
$labels = ['Requisitos','Banco de dados','Administrador','Endereço público','Instalar'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalação guiada · Ponte Gateway</title>
<link rel="stylesheet" href="assets/css/setup-wizard.css">
</head>
<body>
<main class="setup-wrap">
  <header class="setup-head">
    <div class="setup-brand"><span class="setup-logo">P</span><span><strong>Ponte Gateway</strong><small>WHATSAPP CLOUD API · INSTALAÇÃO</small></span></div>
    <div class="setup-count">Etapa <?= $step ?> de 5</div>
  </header>
  <ol class="setup-progress" aria-label="Etapas de instalação">
    <?php foreach ($labels as $i => $label): $number = $i + 1; ?>
    <li class="<?= $number === $step ? 'current' : ($number < $step ? 'completed' : '') ?>">
      <?php if ($number <= (int)$wizard['highest'] && $number !== $step): ?>
        <a href="?step=<?= $number ?>" aria-label="Ir para <?=e($label)?>"><span><?= $number ?></span><em><?=e($label)?></em></a>
      <?php else: ?>
        <span class="step-static"><span><?= $number ?></span><em><?=e($label)?></em></span>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ol>
  <section class="setup-panel">
    <?php if ($error): ?><div class="setup-error" role="alert"><?=e($error)?>
      <?php if ($step === 5): ?><p>Se houve falha durante a criação das tabelas, prepare outro banco vazio antes de tentar novamente. Não apague dados de outros sistemas.</p><?php endif; ?>
    </div><?php endif; ?>
    <?php if ($step === 1): ?>
      <p class="eyebrow">01 / Verificação automática</p>
      <h1>Seu servidor está pronto?</h1>
      <p class="intro">O assistente confere PHP, extensões e permissões. Nenhum banco será alterado nesta etapa.</p>
      <ul class="check-list">
        <?php foreach ($checks as [$label, $ok]): ?>
          <li><span class="check-icon <?= $ok ? 'ok' : 'fail' ?>"><?= $ok ? '✓' : '!' ?></span><span><?=e($label)?></span><strong><?= $ok ? 'OK' : 'Verificar' ?></strong></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!wizard_ready($checks)): ?><p class="setup-warning">Corrija os itens marcados e atualize esta página. Não exclua arquivos de configuração sem fazer backup.</p><?php endif; ?>
      <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="setup_action" value="requirements">
        <div class="setup-actions"><span>Instalação guiada, sem apagar dados existentes.</span><button class="primary" <?=wizard_ready($checks)?'':'disabled'?>>Continuar <b>→</b></button></div>
      </form>
    <?php elseif ($step === 2): ?>
      <p class="eyebrow">02 / MySQL</p>
      <h1>Conectar ao banco</h1>
      <p class="intro">Crie primeiro um banco <strong>vazio</strong> no phpMyAdmin. Vamos testar a conexão antes de continuar.</p>
      <form method="post" class="setup-form">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="setup_action" value="database">
        <div class="form-grid">
          <label>Servidor MySQL<input name="db_host" required value="<?=e($_POST['db_host'] ?? $db['host'])?>" placeholder="127.0.0.1"></label>
          <label>Porta<input name="db_port" required type="number" min="1" max="65535" value="<?=e($_POST['db_port'] ?? $db['port'])?>"></label>
          <label>Nome do banco<input name="db_name" required pattern="[A-Za-z0-9_]+" value="<?=e($_POST['db_name'] ?? $db['name'])?>"></label>
          <label>Usuário<input name="db_user" required value="<?=e($_POST['db_user'] ?? $db['user'])?>"></label>
          <label class="full">Senha MySQL<input name="db_password" type="password" autocomplete="new-password" placeholder="<?=!empty($wizard['database'])?'Digite a senha novamente ao alterar dados':'No XAMPP padrão pode ficar em branco'?>"></label>
        </div>
        <div class="setup-note">XAMPP padrão: host <code>127.0.0.1</code>, porta <code>3306</code>, usuário <code>root</code>, sem senha (se não foi definida). O teste exige um banco sem tabelas.</div>
        <div class="setup-actions"><a href="?step=1" class="back">← Voltar</a><button class="primary">Testar conexão e continuar <b>→</b></button></div>
      </form>
    <?php elseif ($step === 3): ?>
      <p class="eyebrow">03 / Conta principal</p>
      <h1>Criar administrador</h1>
      <p class="intro">Este será o primeiro usuário da plataforma. Não existe senha padrão.</p>
      <form method="post" class="setup-form">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="setup_action" value="administrator">
        <div class="form-grid">
          <label class="full">Nome da empresa<input name="company_name" required maxlength="180" value="<?=e($_POST['company_name'] ?? ($admin['company'] ?? ''))?>" placeholder="Ex.: Minha empresa"></label>
          <label>Nome do administrador<input name="admin_name" required maxlength="160" value="<?=e($_POST['admin_name'] ?? ($admin['name'] ?? ''))?>"></label>
          <label>E-mail do administrador<input name="admin_email" required type="email" maxlength="190" value="<?=e($_POST['admin_email'] ?? ($admin['email'] ?? ''))?>"></label>
          <label>Senha de acesso<input name="admin_password" required type="password" minlength="12" autocomplete="new-password" placeholder="Mínimo 12 caracteres"></label>
          <label>Confirmar senha<input name="admin_password_confirm" required type="password" minlength="12" autocomplete="new-password"></label>
        </div>
        <div class="setup-note">A senha não fica salva em texto puro no banco. Depois do primeiro login, será necessário ativar a autenticação em duas etapas.</div>
        <div class="setup-actions"><a href="?step=2" class="back">← Voltar</a><button class="primary">Salvar administrador <b>→</b></button></div>
      </form>
    <?php elseif ($step === 4): ?>
      <p class="eyebrow">04 / Publicação</p>
      <h1>Onde ficará o gateway?</h1>
      <p class="intro">No XAMPP, pode deixar em branco. Quando publicar na Locaweb, configure a URL HTTPS completa para receber webhooks da Meta.</p>
      <form method="post" class="setup-form">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="setup_action" value="address">
        <label>URL pública HTTPS (opcional)<input name="app_url" type="url" placeholder="https://mensagens.seudominio.com.br" value="<?=e($_POST['app_url'] ?? ($wizard['app_url'] ?? ''))?>"></label>
        <div class="setup-note">O assistente não conecta à Meta nesta etapa. Depois de entrar, use o menu <strong>Instalação Meta</strong> para configurar o aplicativo, WABA, número e webhook.</div>
        <div class="setup-actions"><a href="?step=3" class="back">← Voltar</a><button class="primary">Continuar <b>→</b></button></div>
      </form>
    <?php else: ?>
      <p class="eyebrow">05 / Confirmação</p>
      <h1>Pronto para instalar</h1>
      <p class="intro">Confira os dados antes de criar a estrutura no banco. A operação não apaga tabelas existentes.</p>
      <dl class="setup-summary">
        <div><dt>MySQL</dt><dd><?=e($wizard['database']['host'].':'.$wizard['database']['port'])?></dd></div>
        <div><dt>Banco</dt><dd><?=e($wizard['database']['name'])?></dd></div>
        <div><dt>Empresa</dt><dd><?=e($wizard['administrator']['company'])?></dd></div>
        <div><dt>Administrador</dt><dd><?=e($wizard['administrator']['name'].' · '.$wizard['administrator']['email'])?></dd></div>
        <div><dt>Endereço público</dt><dd><?=e($wizard['app_url'] ?: 'Será configurado depois')?></dd></div>
      </dl>
      <div class="setup-note">Ao concluir, o sistema cria as tabelas, o primeiro usuário e uma chave de segurança em <code>storage/config.local.php</code>. Guarde backup desse arquivo junto com o banco de dados.</div>
      <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="setup_action" value="install">
        <div class="setup-actions"><a href="?step=4" class="back">← Voltar</a><button class="primary">Instalar plataforma <b>✓</b></button></div>
      </form>
    <?php endif; ?>
  </section>
  <footer class="setup-footer">Ponte Gateway · API oficial do WhatsApp <span>Após instalar: login → autenticador → Instalação Meta</span></footer>
</main>
</body>
</html>
