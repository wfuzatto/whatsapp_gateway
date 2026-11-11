<?php
declare(strict_types=1);
if(!defined('WHATSAPP_META_PANEL_VIEW')){http_response_code(404);exit;}
function metaE(mixed $v): string {
    return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex,nofollow');
$active=($user['subscription_status']??'')==='active';
$selected=$current??null;
$flash=$flash??null;
$problem=$vars['error']??null;
$list=$templateData['data']??[];
$draft=$vars['draft']??[];
$next=$templateData['paging']['cursors']['after']??null;
$next=is_string($next)&&strlen($next)<600?$next:null;
$advancedExample=json_encode([
 'name'=>'codigo_de_acesso','language'=>'pt_BR','category'=>'AUTHENTICATION',
 'components'=>[['type'=>'BODY','add_security_recommendation'=>true],
 ['type'=>'FOOTER','code_expiration_minutes'=>10],
 ['type'=>'BUTTONS','buttons'=>[['type'=>'OTP','otp_type'=>'COPY_CODE','text'=>'Copiar código']]]]
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><link rel="icon" type="image/svg+xml" href="/whatsapp_assets/whatsapp_zapapi_favicon.svg">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Integração Meta | ZapApi</title>
<style>
:root{font-family:Inter,ui-sans-serif,-apple-system,Segoe UI,Arial,sans-serif;color:#14362e;background:#f3f8f5}
*{box-sizing:border-box}body{margin:0}h1,h2,h3,p{margin-top:0}h1,h2,h3{letter-spacing:-.036em}
p,li{line-height:1.55}a{color:inherit;text-decoration:none}
input,select,textarea,button{font:inherit}button{cursor:pointer}
header{background:#062f28;color:white}.nav{max-width:1180px;margin:auto;display:flex;justify-content:space-between;align-items:center;gap:14px;padding:20px 24px}
.logo{font-weight:800;font-size:1.08rem}.logo span{color:#59deb1}
.navlinks{display:flex;gap:19px;font-size:.84rem;font-weight:650;color:#dcf4ec;align-items:center}.navlinks a:hover{text-decoration:underline}
main{max-width:1180px;margin:0 auto;padding:39px 24px 85px}
.intro{display:flex;justify-content:space-between;gap:18px;align-items:start;margin-bottom:28px}
.kicker{font-weight:850;text-transform:uppercase;letter-spacing:.13em;color:#168966;font-size:.72rem;margin-bottom:10px}
.intro h1{font-size:clamp(1.9rem,4vw,2.7rem);margin:0 0 9px}.intro p{font-size:.95rem;color:#6e8279;margin:0}
.tag{border-radius:30px;padding:8px 12px;font-weight:750;background:#dcf5e8;color:#116e4b;font-size:.78rem;white-space:nowrap}
.tag.wait{background:#ffefd1;color:#8a601c}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}.span2{grid-column:span 2}
.box{background:white;border:1px solid #dbe8e1;border-radius:17px;padding:25px;box-shadow:0 14px 40px rgba(18,62,46,.035);min-width:0}
.box h2{font-size:1.23rem;margin-bottom:8px}
.box h3{font-size:1.02rem;margin-bottom:10px}.help{color:#667e73;font-size:.85rem;line-height:1.62;margin:0 0 18px}
.notice{border-radius:11px;padding:13px 16px;font-size:.85rem;line-height:1.55;margin-bottom:18px}
.notice.ok{background:#e5f9ee;border:1px solid #b9e6cc;color:#125e40}.notice.bad{background:#fff0ec;border:1px solid #ecc5b8;color:#9d382d}
.notice.warn{background:#fff7e6;border:1px solid #eedab2;color:#7b5923}
.form{display:grid;gap:14px}.form label{display:block;font-size:.82rem;color:#34584b;font-weight:700}
input,select,textarea{width:100%;border:1px solid #cfddd4;border-radius:9px;background:#fff;color:#163b30;padding:11px 13px;margin-top:6px;outline:0}
textarea{min-height:96px;resize:vertical;font-family:inherit;line-height:1.5}
textarea.code{min-height:170px;font-family:ui-monospace,Consolas,monospace;font-size:.8rem}
input:focus,select:focus,textarea:focus{border-color:#139e71;box-shadow:0 0 0 3px #14a77920}
label small{display:block;font-weight:450;color:#799086;font-size:.76rem;margin-top:5px}
.twocol{display:grid;grid-template-columns:1fr 1fr;gap:13px}
.btn{border:0;border-radius:10px;padding:12px 17px;background:#0b6f52;color:white;font-weight:800;display:inline-flex;gap:8px;justify-content:center;align-items:center;text-align:center}
.btn:hover{background:#078361}.btn.secondary{background:#e8f6ef;color:#087250}.btn.light{background:#f0f5f1;color:#315d4d}
.btn:disabled{background:#adbfb3;cursor:not-allowed}
.btn.tiny{padding:8px 12px;font-size:.79rem}
.buttonrow{display:flex;flex-wrap:wrap;gap:9px;align-items:center}
.summaryrow{border-top:1px solid #edf3ee;padding:12px 0;display:flex;flex-wrap:wrap;gap:15px;align-items:center;justify-content:space-between}
.summaryrow:first-of-type{border-top:0}.summaryrow strong{font-size:.91rem}
.summaryrow small{color:#74897e;font-size:.77rem;display:block;margin-top:3px}
.codeinline{font-family:ui-monospace,Consolas,monospace;background:#eff6f1;padding:4px 7px;border-radius:6px;font-size:.78rem;overflow-wrap:anywhere}
.number{color:#0c674d;font-weight:650;font-family:ui-monospace,Consolas,monospace;font-size:.8rem}
.tablewrap{overflow-x:auto}.table{width:100%;border-collapse:collapse;min-width:550px}.table th,.table td{text-align:left;border-bottom:1px solid #ebf1ed;padding:11px 9px;font-size:.81rem;vertical-align:top}.table th{color:#6b8377}
.status{font-size:.72rem;font-weight:850;border-radius:18px;padding:5px 9px;background:#fff0d6;color:#8a5c08}
.status.APPROVED,.status.active{background:#d9f5e5;color:#09734b}
.status.REJECTED,.status.PAUSED{background:#ffebeb;color:#a23c3c}
.status.PENDING,.status.IN_REVIEW{background:#fff0d6;color:#8a5c08}
.details{border:1px solid #e2ece6;border-radius:12px;padding:15px;background:#fafdfb;margin-top:16px}
summary{font-size:.9rem;font-weight:800;cursor:pointer}
details[open] summary{margin-bottom:15px}
.steplist{margin:0;padding-left:20px;color:#4d675c;font-size:.87rem;line-height:1.85}
hr{border:0;border-top:1px solid #e5eee8;margin:19px 0}
a.link{color:#087855;text-decoration:underline}
.aside{background:#ebf7f0;border:1px solid #d4eadf;border-radius:12px;padding:16px}
.mono{font-family:ui-monospace,Consolas,monospace;font-size:.8rem;overflow-wrap:anywhere}
footer{max-width:1180px;margin:0 auto 25px;padding:0 24px;color:#72887d;font-size:.78rem}
@media(max-width:850px){.grid{grid-template-columns:1fr}.span2{grid-column:auto}}
@media(max-width:600px){main{padding:25px 15px 55px}.box{padding:19px}.intro{display:block}.tag{display:inline-block;margin-top:16px}.twocol{grid-template-columns:1fr}.nav{padding:15px}.navlinks{gap:8px}.navlinks a{font-size:.76rem}}
 .zapapi-brand{display:block;width:188px;height:auto;max-height:58px;object-fit:contain}@media(max-width:600px){.zapapi-brand{width:138px}}
</style></head><body>
<header><div class="nav"><a class="logo" href="/painel"><img class="zapapi-brand" src="/whatsapp_assets/whatsapp_zapapi_logo.svg" width="188" height="53" alt="ZapApi"></a>
<div class="navlinks"><a href="/painel">Painel</a><a href="/painel/meta">Integração Meta</a><a href="https://developers.facebook.com/apps/" rel="noreferrer">Meta Developers</a></div>
</div></header>
<main>
<div class="intro"><div>
<div class="kicker">Configurações do cliente · <?=metaE($user['company'])?></div>
<h1>Integração com a Meta</h1>
<p>Cadastre números oficiais, envie templates para aprovação e acompanhe o resultado.</p>
</div><span class="tag <?=$active?'':'wait'?>"><?=$active?'Assinatura ativa':'Aguardando ativação'?></span></div>
<?php if($flash): ?><div class="notice <?=($flash['type']??'')==='success'?'ok':'bad'?>"><?=metaE($flash['message']??'')?></div><?php endif; ?>
<?php if($problem): ?><div class="notice bad" role="alert"><?=metaE($problem)?></div><?php endif; ?>
<?php if(!$active): ?><div class="notice warn">Seu plano ainda não está ativo. O administrador precisa ativar a assinatura antes de conectar números ou enviar templates.</div><?php endif; ?>
<?php if($oneTimeKey): ?><section class="box" style="margin-bottom:20px;border-color:#8bd7b3"><h2>Chave de API recém-criada — copie agora</h2>
<p class="help">Por segurança, ela aparece somente nesta tela. Guarde em um gerenciador de senhas; não a envie por e-mail ou a inclua em arquivos públicos.</p>
<label>Chave secreta <input type="text" readonly value="<?=metaE($oneTimeKey)?>" autocomplete="off"></label>
</section><?php endif; ?>
<div class="grid">
<section class="box" id="conectar">
<h2>1. Conectar número e WABA</h2>
<p class="help">Em <a class="link" href="https://developers.facebook.com/apps/" rel="noreferrer">Meta for Developers</a>, abra seu aplicativo WhatsApp e localize WABA ID, Phone Number ID e o token de acesso do usuário de sistema. A plataforma testa as permissões antes de salvar.</p>
<form class="form" method="post" action="/painel/meta">
<input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="connect">
<label>Nome da conexão <input name="name" required maxlength="120" placeholder="Ex.: Atendimento principal"></label>
<div class="twocol">
<label>WhatsApp Business Account ID (WABA) <input name="waba_id" required inputmode="numeric" pattern="[0-9]+" maxlength="40" placeholder="Ex.: 123456789012345"></label>
<label>Phone Number ID <input name="phone_number_id" required inputmode="numeric" pattern="[0-9]+" maxlength="40" placeholder="Ex.: 987654321098765"></label>
</div>
<label>Token de acesso da Meta <input type="password" name="access_token" required minlength="30" maxlength="4096" autocomplete="off" placeholder="Cole o token de usuário de sistema"><small>Permissões necessárias: whatsapp_business_management e whatsapp_business_messaging. Nunca exibiremos o token após salvar. Para rotacioná-lo, use os mesmos IDs e informe o token novo.</small></label>
<button type="submit" class="btn" <?=$active?'':'disabled'?>>Validar e salvar integração</button>
</form>
<div class="notice warn" style="margin-top:17px;margin-bottom:0">O WABA é exclusivo do seu tenant. O plano permite até <?=metaE($user['max_numbers']??0)?> número(s). Não coloque o App Secret na caixa do token.</div>
</section>
<section class="box" id="conexoes"><h2>2. Números conectados</h2><p class="help">Somente as conexões do seu ambiente são listadas. Tokens permanecem criptografados no banco.</p>
<?php if(!$accounts): ?><div class="aside"><b>Nenhum número cadastrado</b><p class="help" style="margin:7px 0 0">Preencha o formulário ao lado. Após validar sua conta na Meta, ela aparecerá aqui.</p></div>
<?php else: ?>
<?php foreach($accounts as $a): ?>
<div class="summaryrow"><div>
<strong><?=metaE($a['name'])?></strong> <span class="status <?=$a['status']==='active'?'active':''?>"><?=metaE($a['status'])?></span>
<small>WABA <span class="number"><?=metaE($a['waba_id'])?></span></small>
<small>Número ID <span class="number"><?=metaE($a['phone_number_id'])?></span></small>
</div><div class="buttonrow">
<a class="btn tiny secondary" href="/painel/meta?conta=<?=rawurlencode((string)$a['id'])?>#templates">Templates</a>
<form method="post" action="/painel/meta"><input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="check"><input type="hidden" name="account_id" value="<?=metaE($a['id'])?>"><button class="btn tiny light" type="submit">Testar</button></form>
</div></div>
<?php endforeach; ?>
<?php endif; ?>
<div class="aside" style="margin-top:20px"><strong>Checklist Meta</strong><ol class="steplist">
<li>WABA e número criados no Business Manager</li>
<li>Usuário de sistema com acesso e permissões corretas</li>
<li>Token permanente (não usar temporário em produção)</li>
<li>Perfil empresarial e número habilitados na plataforma</li>
<li>Webhook inscrito para receber eventos da Meta</li></ol></div>
</section>
<section class="box span2" id="templates">
<h2>3. Criar e enviar template para aprovação</h2>
<p class="help">O envio utiliza <span class="codeinline">POST /{WABA_ID}/message_templates</span> da Graph API oficial. O retorno geralmente é <strong>PENDING</strong>; somente a Meta decide a aprovação. Templates aprovados poderão ser usados depois no envio de mensagens.</p>
<?php if(!$accounts): ?><div class="notice warn">Conecte primeiro seu WABA e número para habilitar o editor de templates.</div><?php else: ?>
<form class="form" action="/painel/meta" method="post">
<input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="submit">
<div class="twocol">
<label>Conexão Meta
<select name="account_id" required><?php foreach($accounts as $a): ?><option value="<?=metaE($a['id'])?>" <?=$selected && $a['id']===$selected['id']?'selected':''?>><?=metaE($a['name'].' · '.$a['phone_number_id'])?></option><?php endforeach;?></select></label>
<label>Modo de criação
<select name="template_mode"><option value="simple" <?=($draft["template_mode"]??"simple")==="simple"?"selected":""?>>Editor de texto</option><option value="advanced" <?=($draft["template_mode"]??"")==="advanced"?"selected":""?>>JSON avançado (usa campo abaixo)</option></select><small>Para autenticação OTP, mídia e botões avançados, selecione JSON avançado.</small></label>
</div>
<div class="twocol">
<label>Nome interno <input name="template_name" value="<?=metaE($draft["template_name"]??"")?>" pattern="[a-z][a-z0-9_]{2,127}" maxlength="128" placeholder="confirmacao_reserva"></label>
<label>Idioma <select name="template_language"><option value="pt_BR" <?=($draft["template_language"]??"pt_BR")==="pt_BR"?"selected":""?>>Português (Brasil) — pt_BR</option><option value="en_US" <?=($draft["template_language"]??"pt_BR")==="en_US"?"selected":""?>>English — en_US</option><option value="es_ES" <?=($draft["template_language"]??"pt_BR")==="es_ES"?"selected":""?>>Español — es_ES</option></select></label>
</div>
<label>Categoria <select name="template_category">
<option value="UTILITY" <?=($draft["template_category"]??"UTILITY")==="UTILITY"?"selected":""?>>UTILITY — atualizações transacionais</option><option value="MARKETING" <?=($draft["template_category"]??"UTILITY")==="MARKETING"?"selected":""?>>MARKETING — campanhas/promocional</option></select>
<small>A Meta pode reclassificar o template; não marque promoção como UTILITY.</small></label>
<label>Cabeçalho opcional <input name="template_header" value="<?=metaE($draft["template_header"]??"")?>" maxlength="60" placeholder="Confirmação de reserva {{1}}"></label>
<label>Exemplo para variável do cabeçalho <input name="header_examples" value="<?=metaE($draft["header_examples"]??"")?>" maxlength="220" placeholder="ABC123"><small>Preencha somente quando houver {{1}} no cabeçalho.</small></label>
<label>Mensagem principal (BODY)
<textarea name="template_body" maxlength="1024" placeholder="Olá {{1}}, sua reserva para {{2}} foi confirmada." rows="4"><?=metaE($draft["template_body"]??"")?></textarea><small>Variáveis devem ser sequenciais: {{1}}, {{2}} etc.</small></label>
<label>Exemplos de cada variável do BODY (um por linha)
<textarea name="body_examples" maxlength="1500" rows="3" placeholder="João&#10;20/11/2026"><?=metaE($draft["body_examples"]??"")?></textarea><small>Se houver {{1}} e {{2}}, informe dois exemplos em linhas separadas. Exigido pela Meta.</small></label>
<label>Rodapé opcional <input name="template_footer" value="<?=metaE($draft["template_footer"]??"")?>" maxlength="60" placeholder="Equipe de atendimento"></label>
<details class="details"><summary>Editor JSON avançado — AUTHENTICATION, mídia e botões</summary>
<p class="help">Selecione o modo JSON avançado acima; informe um objeto com <span class="codeinline">name</span>, <span class="codeinline">language</span>, <span class="codeinline">category</span> e <span class="codeinline">components</span>. Exemplo ilustrativo de OTP (ajuste ao esquema da Meta):</p>
<textarea name="advanced_json" class="code" maxlength="18000" rows="12"><?=metaE($draft["advanced_json"]??$advancedExample)?></textarea>
<p class="help">Para cabeçalho de imagem/vídeo/documento, obtenha antes o <span class="codeinline">header_handle</span> pelo processo de upload de exemplos da Meta. O editor não faz esse upload ainda.</p></details>
<button class="btn" type="submit" <?=$active?'':'disabled'?>>Enviar template para análise da Meta →</button>
</form>
<?php endif; ?>
</section>
<section class="box span2" id="aprovacoes">
<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
<div><h2>4. Templates enviados e situação da aprovação</h2><p class="help">A lista é consultada diretamente na Meta e mostra PENDING, APPROVED, REJECTED ou PAUSED quando disponível.</p></div>
<a class="btn secondary" href="/painel/meta<?= $selected?'?conta='.rawurlencode((string)$selected['id']).'#aprovacoes':'#aprovacoes' ?>">Atualizar status ↻</a></div>
<?php if($listError): ?><div class="notice bad"><?=metaE($listError)?></div><?php endif; ?>
<?php if(!$selected): ?><p class="help">Conecte um WABA para consultar os templates.</p>
<?php elseif(!$list): ?><p class="help">Nenhum template retornado para esta conta.</p>
<?php else: ?>
<div class="tablewrap"><table class="table"><thead><tr><th>Nome</th><th>Idioma</th><th>Categoria</th><th>Status</th><th>ID Meta / motivo</th></tr></thead><tbody>
<?php foreach($list as $item): ?>
<tr><td><b><?=metaE($item['name']??'')?></b></td><td><?=metaE($item['language']??'')?></td>
<td><?=metaE($item['category']??'')?></td>
<td><span class="status <?=metaE($item['status']??'')?>"><?=metaE($item['status']??'—')?></span></td>
<td><span class="mono"><?=metaE($item['id']??'')?></span>
<?php if(!empty($item['rejected_reason'])): ?><div style="font-size:.77rem;color:#b24a34;margin-top:5px"><?=metaE($item['rejected_reason'])?></div><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif;?>
<?php if($next && $selected): ?><a class="btn secondary" style="margin-top:16px" href="/painel/meta?conta=<?=rawurlencode((string)$selected['id'])?>&after=<?=rawurlencode($next)?>#aprovacoes">Carregar próxima página</a><?php endif; ?>
</section>
<section class="box" id="webhooks"><h2>5. Webhooks da Meta</h2>
<p class="help">Configure os webhooks para que mensagens recebidas, entregas e mudanças de status dos templates cheguem ao gateway.</p>
<label style="font-size:.82rem;font-weight:750">URL pública de callback
<input readonly value="https://zapapi.inf.br/webhooks/meta" onclick="this.select()"></label>
<p class="help" style="margin-top:10px">Na Meta, configure e valide essa URL no <strong>app proprietário da integração</strong>. O Verify Token e o App Secret ficam com o operador da plataforma, pois a rota atual utiliza um único aplicativo compartilhado.</p>
<div class="aside"><b>Eventos recomendados</b><p class="mono" style="margin:10px 0">messages<br>message_template_status_update<br>message_template_quality_update<br>template_category_update</p><span style="font-size:.78rem;color:#567065">A disponibilidade exata depende das permissões do seu aplicativo e dos campos atuais da Meta.</span></div>
<?php if($accounts): ?><form method="post" action="/painel/meta" style="margin-top:16px" class="form">
<input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="subscribe">
<label>WABA para inscrição <select name="account_id"><?php foreach($accounts as $a): ?><option value="<?=metaE($a['id'])?>"><?=metaE($a['name'].' · '.$a['waba_id'])?></option><?php endforeach; ?></select></label>
<button class="btn secondary" type="submit" <?=$active?'':'disabled'?>>Solicitar inscrição do app no WABA</button></form><?php endif; ?>
<div class="notice warn" style="margin-top:16px;margin-bottom:0">Se você possui aplicativo Meta próprio, será necessária a configuração adicional de App Secret e verificação individual. O callback compartilhado não aceita automaticamente a assinatura de aplicativos externos.</div>
</section>
<section class="box" id="api"><h2>6. Chaves de API do seu tenant</h2>
<p class="help">Use uma chave exclusiva em cada integração de ERP, sistema de reservas ou automação. Ela será mostrada apenas uma vez.</p>
<?php if($accounts): ?><form class="form" action="/painel/meta" method="post">
<input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="key_create">
<label>Nome da integração <input name="key_name" maxlength="100" placeholder="ERP principal" required></label>
<label>Número autorizado <select name="account_id"><?php foreach($accounts as $a): ?><option value="<?=metaE($a['id'])?>"><?=metaE($a['name'].' · '.$a['phone_number_id'])?></option><?php endforeach; ?></select></label>
<button class="btn" type="submit" <?=$active?'':'disabled'?>>Gerar chave de API</button>
</form><?php else: ?><div class="notice warn">Conecte um número para criar uma chave específica.</div><?php endif; ?>
<?php if($keys): ?><hr><h3>Chaves existentes</h3>
<?php foreach($keys as $k): ?><div class="summaryrow"><div><strong><?=metaE($k['name'])?></strong>
<small><?=metaE($k['account_name']??'Todo tenant')?> · <?=metaE($k['created_at'])?> · <?=($k['active']?'Ativa':'Revogada')?></small></div>
<?php if($k['active']): ?><form method="post" action="/painel/meta">
<input type="hidden" name="csrf" value="<?=metaE($csrf)?>"><input type="hidden" name="action" value="key_revoke"><input type="hidden" name="key_id" value="<?=metaE($k['id'])?>">
<button class="btn tiny light" type="submit">Revogar</button></form><?php endif; ?></div><?php endforeach; ?>
<?php endif; ?>
<p class="help" style="margin-top:19px">Exemplo de autenticação: <span class="codeinline">Authorization: Bearer wg_...</span>. O token Meta e a chave do gateway são diferentes.</p>
</section>
</div>
</main>
<footer>ZapApi · Integração Cloud API oficial da Meta · Os templates ficam sujeitos às políticas e revisão da Meta.</footer>
</body></html>
