<?php
declare(strict_types=1);
if(!defined('WHATSAPP_INSTALL_VIEW')){http_response_code(404);exit;}
function instE(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
header('X-Robots-Tag: noindex, nofollow');
$error=$values['error']??null;
$admin=$values['admin']??null;
$stats=$values['stats']??[];
$db=$db??[];
$ready=$protectedKey??false;
?>
<!doctype html><html lang="pt-BR"><head>
<meta charset="utf-8"><link rel="icon" type="image/svg+xml" href="/whatsapp_assets/whatsapp_zapapi_favicon.svg"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=instE(match($mode) {
 'install'=>'Instalação inicial', 'installed'=>'Sistema instalado',
 'login'=>'Acesso do administrador', 'dashboard'=>'Administração',
 default=>'WhatsApp Gateway'
})?> · ZapApi</title>
<style>
:root{font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#163731;color-scheme:light}
*{box-sizing:border-box}body{margin:0;background:#f2f7f5;min-height:100vh}a{color:inherit;text-decoration:none}button,input{font:inherit}
.top{background:#0a3029;color:white;padding:18px 0}.wrap{width:min(1040px,calc(100% - 36px));margin:auto}.topinner{display:flex;justify-content:space-between;align-items:center;gap:14px}.brand{font-size:1.12rem;font-weight:800;letter-spacing:-.035em}.brand i{font-style:normal;color:#4be5b2}.top a.link{font-size:.86rem;color:#cce5dc}.main{padding:62px 0 100px}.intro{text-align:center;max-width:710px;margin:0 auto 37px}.eyebrow{font-size:.76rem;letter-spacing:.14em;color:#138768;font-weight:850;text-transform:uppercase;margin-bottom:12px}.intro h1{font-size:clamp(2rem,4.5vw,3rem);line-height:1.15;letter-spacing:-.05em;margin:0 0 15px}.intro p{color:#647972;line-height:1.65;margin:0}.grid{display:grid;grid-template-columns:1.25fr .75fr;gap:23px;align-items:start}.box{border:1px solid #d9e7e0;background:#fff;border-radius:17px;padding:30px;box-shadow:0 15px 42px rgba(13,66,47,.055)}.box h2{font-size:1.35rem;letter-spacing:-.035em;margin:0 0 8px}.box p{line-height:1.55;color:#61766e;font-size:.89rem}.form{display:grid;gap:16px;margin-top:24px}.form label{display:block;font-size:.86rem;font-weight:750;color:#355248}.form input{display:block;width:100%;margin-top:7px;padding:13px 14px;border:1px solid #c9dad0;border-radius:10px;outline:none;background:#fff;color:#183f31}.form input:focus{border-color:#18a979;box-shadow:0 0 0 3px #18a97920}.two{display:grid;grid-template-columns:1fr 1fr;gap:13px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 19px;font-weight:800;border-radius:10px;border:0;background:#0a654e;color:white;cursor:pointer}.btn:hover{background:#087b5d}.btn:disabled{opacity:.45;cursor:not-allowed}.btn-outline{background:#e5f6ed;color:#07583e}.notice{border-radius:11px;padding:13px 16px;line-height:1.6;font-size:.85rem;margin-top:17px}.warn{background:#fff7e6;border:1px solid #f0d7a5;color:#795b23}.error{background:#fff0ef;border:1px solid #edc6be;color:#9c2c25}.success{background:#e9f9ef;border:1px solid #c7ead4;color:#116a49}.steps{display:grid;gap:21px;margin-top:23px}.step{display:flex;gap:13px}.num{flex:none;width:32px;height:32px;border-radius:10px;background:#dbf8e9;color:#087755;display:grid;place-items:center;font-weight:850}.step b{font-size:.9rem}.step p{margin:4px 0 0;font-size:.83rem}.mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;background:#eff5f2;padding:3px 6px;border-radius:5px;overflow-wrap:anywhere}.footer{padding:28px 0 36px;font-size:.8rem;text-align:center;color:#7a8c85}.tablewrap{overflow-x:auto}.table{border-collapse:collapse;width:100%;min-width:660px;text-align:left}.table th,.table td{padding:15px 9px;border-bottom:1px solid #e4eee8;font-size:.82rem}.table th{color:#61756d}.badge{font-size:.75rem;font-weight:750;background:#fff4d8;color:#835b12;border-radius:22px;padding:5px 10px}.badge.active{background:#dcf6e8;color:#116744}.act{border:1px solid #b7ddd0;background:#eff9f4;padding:8px 12px;border-radius:8px;font-size:.78rem;font-weight:800;color:#096749;cursor:pointer}.act.danger{color:#9b5130;border-color:#efceb9;background:#fff6eb}.headerline{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:22px}.headerline h2{margin:0}.stats{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:20px}.stat{padding:15px 19px;background:#eff7f1;border-radius:13px;min-width:130px}.stat strong{font-size:1.5rem}.stat small{display:block;color:#678076}.row-actions{display:flex;gap:7px;flex-wrap:wrap}.logout{background:none;border:0;color:#d7f2e8;font-weight:700;cursor:pointer}@media(max-width:740px){.main{padding:35px 0 70px}.grid{grid-template-columns:1fr}.box{padding:22px}.two{grid-template-columns:1fr}.topinner{flex-wrap:wrap}}
 .zapapi-brand{display:block;width:188px;height:auto;max-height:58px;object-fit:contain}@media(max-width:740px){.zapapi-brand{width:146px}}
</style></head><body>
<header class="top"><div class="wrap topinner">
<a class="brand" href="/"><img class="zapapi-brand" src="/whatsapp_assets/whatsapp_zapapi_logo.svg" width="188" height="53" alt="ZapApi"></a>
<div><a class="link" href="/">Página inicial</a> &nbsp; · &nbsp; <a class="link" href="/admin">Administração</a>
<?php if($mode==='dashboard'): ?>
<form style="display:inline" action="/admin/sair" method="post"><input type="hidden" name="csrf" value="<?=instE($csrf)?>"><button class="logout" type="submit">Sair</button></form>
<?php endif;?></div>
</div></header>
<main class="main"><div class="wrap">
<?php if($mode==='install'): ?>
<div class="intro"><div class="eyebrow">Etapa inicial · Instalação protegida</div><h1>Configure o WhatsApp Gateway.</h1><p>Conecte o banco de dados, crie as tabelas e registre o administrador principal. O assistente é bloqueado após a primeira instalação.</p></div>
<div class="grid"><section class="box">
<h2>1. Banco de dados e superadministrador</h2><p>Os parâmetros de hospedagem já estão pré-configurados. Informe apenas a senha atual do MySQL e os dados do administrador.</p>
<?php if($error): ?><div class="notice error" role="alert"><?=instE($error)?></div><?php endif; ?>
<?php if(!$ready): ?><div class="notice warn"><b>Proteção inicial não preparada.</b> No FTP, na pasta raiz <span class="mono">/</span> (fora de <span class="mono">public_html</span>), deve existir o arquivo <span class="mono">whatsapp_install_key.txt</span>. Execute novamente a publicação pelo GitHub Actions para prepará-lo.</div><?php endif;?>
<form class="form" action="/install" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?=instE($csrf)?>">
<label>Servidor MySQL<input type="text" value="<?=instE($db[0]??'')?>" readonly aria-readonly="true"></label>
<div class="two">
<label>Nome do banco<input type="text" value="<?=instE($db[2]??'')?>" readonly aria-readonly="true"></label>
<label>Usuário MySQL<input type="text" value="<?=instE($db[3]??'')?>" readonly aria-readonly="true"></label>
</div>
<label>Senha atual do MySQL<input type="password" name="db_password" autocomplete="new-password" required placeholder="Digite a senha do banco"></label>
<label>Chave de instalação (arquivo FTP)<input type="password" name="install_key" required autocomplete="one-time-code" placeholder="Conteúdo de whatsapp_install_key.txt"></label>
<hr style="width:100%;border:0;border-top:1px solid #e4eee8;margin:2px 0">
<div class="two">
<label>Nome do superadministrador<input type="text" name="admin_name" required minlength="3" maxlength="120" autocomplete="name"></label>
<label>E-mail administrativo<input type="email" name="admin_email" required maxlength="190" autocomplete="email"></label>
</div>
<div class="two">
<label>Senha administrativa<input type="password" name="admin_password" required minlength="12" maxlength="128" autocomplete="new-password" placeholder="12+ caracteres, letras e números"></label>
<label>Confirmar senha<input type="password" name="admin_confirm" required minlength="12" maxlength="128" autocomplete="new-password"></label>
</div>
<button class="btn" type="submit" <?=$ready?'':'disabled'?>>Instalar sistema e criar administrador →</button>
</form>
<div class="notice warn">Esta operação cria um arquivo <span class="mono">.env</span> privado <b>fora de public_html</b>, gera chaves aleatórias e instala o banco sem apagar registros existentes. Não envie a senha por mensagens ou coloque-a no GitHub.</div>
</section><aside class="box">
<h2>2. Onde encontrar a chave?</h2><div class="steps">
<div class="step"><span class="num">1</span><div><b>Abra o FileZilla</b><p>Conecte ao FTP e volte para o endereço remoto <span class="mono">/</span>.</p></div></div>
<div class="step"><span class="num">2</span><div><b>Encontre o arquivo de segurança</b><p>O arquivo <span class="mono">whatsapp_install_key.txt</span> fica na raiz, no mesmo nível de <span class="mono">public_html</span>.</p></div></div>
<div class="step"><span class="num">3</span><div><b>Copie o conteúdo</b><p>Abra o arquivo e cole a chave no formulário, sem compartilhá-la. Ela só pode ser utilizada na primeira instalação.</p></div></div>
<div class="step"><span class="num">4</span><div><b>Finalize</b><p>Após salvar as tabelas e o administrador, o sistema remove a chave e desativa o instalador.</p></div></div>
</div><div class="notice warn">Use este formulário apenas por HTTPS. O arquivo FTP é confidencial: não deve ser movido para public_html.</div></aside>
</div>
<?php elseif($mode==='installed'): ?>
<div class="intro"><div class="eyebrow">Instalação protegida</div><h1>O sistema já foi instalado.</h1><p>Uma nova instalação está bloqueada para proteger o banco e as credenciais existentes.</p></div>
<div class="box" style="max-width:680px;margin:auto;text-align:center"><div class="notice success">O assistente não pode ser executado novamente por esta URL.</div><p style="margin-top:25px">Acesse o painel com o superadministrador cadastrado durante a instalação.</p><a class="btn" href="/admin/entrar">Entrar como administrador</a></div>
<?php elseif($mode==='login'): ?>
<div class="intro"><div class="eyebrow">Área restrita</div><h1>Administração da plataforma.</h1><p>Gerencie clientes e assinaturas com a conta criada pelo instalador.</p></div>
<div class="box" style="max-width:500px;margin:auto">
<h2>Entrar no painel administrativo</h2>
<?php if($error): ?><div class="notice error"><?=instE($error)?></div><?php endif;?>
<form class="form" method="post" action="/admin/entrar">
<input type="hidden" name="csrf" value="<?=instE($csrf)?>">
<label>E-mail<input type="email" name="email" autocomplete="username" maxlength="190" required></label>
<label>Senha<input type="password" name="password" autocomplete="current-password" required></label>
<button class="btn" type="submit">Entrar</button>
</form></div>
<?php elseif($mode==='dashboard' && $admin): ?>
<div class="headerline"><div><div class="eyebrow">Superadministração</div><h1 style="margin:0;letter-spacing:-.04em">Olá, <?=instE($admin['name'])?></h1><p style="color:#678078">Aprovação de assinaturas e gerenciamento inicial.</p></div><a class="btn btn-outline" href="/">Ver site público</a></div>
<section class="box"><div class="headerline"><h2>Assinaturas</h2><span class="badge">Ativação comercial manual</span></div>
<div class="stats">
<?php foreach(($stats['counts']??[]) as $st): ?><div class="stat"><strong><?=instE($st['amount'])?></strong><small><?=instE($st['status'])?></small></div><?php endforeach; ?>
</div>
<div class="tablewrap"><table class="table"><thead><tr><th>Empresa</th><th>Plano</th><th>Valor proposto</th><th>Situação</th><th>Ação</th></tr></thead><tbody>
<?php foreach(($stats['subscriptions']??[]) as $sub): ?>
<tr><td><strong><?=instE($sub['company'])?></strong><br><small><?=instE($sub['email']??'')?> · <?=instE($sub['slug'])?></small></td>
<td><?=instE($sub['plan'])?></td><td><?=instE(WhatsAppPortal::money((int)$sub['price_cents_snapshot']))?></td>
<td><span class="badge <?=$sub['status']==='active'?'active':''?>"><?=instE($sub['status'])?></span></td>
<td><div class="row-actions">
<?php if($sub['status']!=='active'): ?><form method="post" action="/admin/assinatura"><input type="hidden" name="csrf" value="<?=instE($csrf)?>"><input type="hidden" name="tenant_slug" value="<?=instE($sub['slug'])?>"><input type="hidden" name="action" value="activate"><button class="act" type="submit">Ativar</button></form><?php endif;?>
<?php if($sub['status']==='active'): ?><form method="post" action="/admin/assinatura"><input type="hidden" name="csrf" value="<?=instE($csrf)?>"><input type="hidden" name="tenant_slug" value="<?=instE($sub['slug'])?>"><input type="hidden" name="action" value="suspend"><button class="act danger" type="submit">Suspender</button></form><?php endif;?>
</div></td></tr>
<?php endforeach; ?>
<?php if(empty($stats['subscriptions'])): ?><tr><td colspan="5">Nenhum cadastro realizado até o momento.</td></tr><?php endif;?>
</tbody></table></div>
<p>Os clientes são criados pelo autocadastro. A aprovação aqui não realiza cobrança: confirme o pagamento comercialmente antes de liberar a assinatura.</p>
</section>
<?php else: ?>
<div class="intro"><h1>Não foi possível concluir.</h1><p><?=instE($error??'Confira os requisitos e tente novamente.')?></p></div>
<div class="box" style="max-width:600px;margin:auto;text-align:center"><a class="btn" href="/install">Voltar ao instalador</a></div>
<?php endif;?>
</div></main>
<footer class="footer"><div class="wrap">ZapApi · Área de configuração protegida · Não afiliado à Meta</div></footer>
</body></html>