<?php
declare(strict_types=1);
if (!defined('WHATSAPP_PORTAL_VIEW')) { http_response_code(404); exit; }
function whatsappE(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
$mode=$mode??'landing';
$data=$data??[];
$plans=$data['plans']??[];
$ready=(bool)($data['ready']??false);
$user=$data['user']??null;
$errors=$data['error']??null;
$csrf=(string)($data['csrf']??'');
$old=$data['old']??[];
$selected=(string)($old['plan']??$data['selected']??'profissional');
if (!in_array($selected,array_column($plans,'slug'),true)) $selected=$plans[0]['slug']??'';
$pageTitle=match($mode) {
    'register'=>'Criar conta',
    'login'=>'Entrar',
    'dashboard'=>'Painel do cliente',
    'unavailable'=>'Serviço indisponível',
    default=>'API oficial de WhatsApp para sua empresa'
};
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: '.($mode==='landing'?'index, follow':'noindex, nofollow'));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=whatsappE($pageTitle)?> | WhatsApp Gateway</title>
<meta name="description" content="Conecte sua empresa à API oficial do WhatsApp, com planos por número, webhooks e integrações.">
<style>
:root{--ink:#102c29;--muted:#647875;--accent:#0f8b69;--deep:#082e29;--aqua:#b1f5d7;--line:#dce9e4;--cream:#f6faf8;--white:#fff;--shadow:0 24px 60px rgba(4,41,35,.10);font-family:Inter,ui-sans-serif,-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;color:var(--ink);font-synthesis:none}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--white)}a{color:inherit;text-decoration:none}button,input,select{font:inherit}button{cursor:pointer}h1,h2,h3,p{margin-top:0}h1,h2,h3{letter-spacing:-.045em}p{line-height:1.68}main{min-height:65vh}.container{width:min(1170px,calc(100% - 42px));margin-inline:auto}.navbar{border-bottom:1px solid rgba(255,255,255,.09);background:#062b26;color:white}.navline{height:76px;display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{display:flex;align-items:center;gap:11px;font-size:1.1rem;font-weight:800;letter-spacing:-.04em;white-space:nowrap}.brandicon{height:36px;width:36px;border-radius:12px;background:#30cc9f;display:grid;place-items:center;color:#083d31;font-size:20px}.navlinks{display:flex;align-items:center;gap:29px;color:#d1e9e0;font-size:.92rem}.navlinks a:hover{color:white}.btn{display:inline-flex;align-items:center;justify-content:center;gap:9px;padding:14px 21px;border:1px solid transparent;border-radius:12px;font-weight:750;transition:transform .2s,background .2s;white-space:nowrap;text-align:center}.btn:hover{transform:translateY(-2px)}.btn-primary{background:#26d39c;color:#083b2d}.btn-primary:hover{background:#63eabf}.btn-dark{background:var(--deep);color:white}.btn-outline{border-color:#94b4aa;background:transparent;color:var(--ink)}.btn-white{background:white;color:#173e34}.btn-sm{padding:10px 16px;font-size:.86rem}.hero{background:radial-gradient(ellipse 750px 580px at 80% 0%,#1b6656 0%,#0e4137 43%,transparent 79%),linear-gradient(120deg,#082f2a,#0a3c33);color:white;position:relative;overflow:hidden}.hero-inner{padding:94px 0 106px;display:grid;grid-template-columns:1.05fr .95fr;gap:65px;align-items:center}.kicker{font-size:.76rem;letter-spacing:.16em;font-weight:850;text-transform:uppercase;color:#66e0b6;margin-bottom:20px}.hero h1{font-size:clamp(2.6rem,5vw,4.65rem);line-height:1.075;max-width:640px;margin:0 0 27px}.hero h1 span{color:#8df1c5}.hero p{font-size:1.13rem;max-width:560px;color:#cbe3db;line-height:1.65}.hero-buttons{display:flex;flex-wrap:wrap;gap:12px;margin:30px 0 19px}.hero-note{font-size:.79rem;color:#a2c8bc}.showcase{padding:14px;border:1px solid #4a8a76;border-radius:24px;background:linear-gradient(140deg,#163f37,#13322c);box-shadow:0 30px 80px rgba(0,0,0,.28);transform:rotate(1deg)}.panel{background:#f7fbf9;color:#15342e;border-radius:15px;padding:22px;box-shadow:inset 0 1px white}.panel-top{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e4eee9;padding-bottom:17px}.panel-top b{font-size:.95rem}.online{font-size:.74rem;color:#13875f;background:#dff7ec;padding:6px 10px;border-radius:100px}.statrow{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:20px 0}.stat{background:white;border:1px solid #e5eee9;border-radius:12px;padding:13px 10px}.stat strong{display:block;font-size:1.3rem;letter-spacing:-.04em}.stat small{font-size:.66rem;color:#738881}.activity{background:white;border:1px solid #e1ebe5;border-radius:13px;padding:13px}.activity-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;font-size:.84rem}.activity-item{display:flex;gap:9px;align-items:center;padding:10px 2px;border-top:1px solid #eff3f0}.dot{width:9px;height:9px;flex-shrink:0;background:#1bc390;border-radius:50%}.dot-blue{background:#8aafe9}.activity-item span{font-size:.78rem;flex:1}.activity-item small{font-size:.67rem;color:#71847b}.disclaimer-mini{font-size:.7rem;color:#71847b;margin:10px 0 0;text-align:center}.trustline{background:#edf7f2;border-bottom:1px solid #d5e9df;color:#245f4e}.trust-inner{padding:18px 0;display:flex;justify-content:center;flex-wrap:wrap;gap:16px 48px;font-weight:700;font-size:.84rem}.check{color:#089369;margin-right:8px}.section{padding:100px 0}.section-alt{background:var(--cream)}.section-hdr{text-align:center;margin:0 auto 43px;max-width:720px}.eyebrow{color:#07855f;font-weight:850;text-transform:uppercase;letter-spacing:.12em;font-size:.75rem;margin:0 0 14px}.section h2{font-size:clamp(2rem,4.5vw,3.15rem);line-height:1.14;margin:0 0 15px}.section-hdr p{color:var(--muted);font-size:1.02rem}.cards3{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}.feature-card,.plan-card{background:white;border:1px solid var(--line);border-radius:19px;padding:28px;box-shadow:0 10px 30px rgba(21,53,43,.035)}.feature-icon{background:#e7f8f0;color:#096f50;border-radius:12px;width:46px;height:46px;display:grid;place-items:center;font-size:1.25rem;margin-bottom:22px}.feature-card h3{font-size:1.12rem;margin-bottom:10px}.feature-card p{color:var(--muted);font-size:.91rem;margin-bottom:0}.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:35px}.step-num{height:45px;width:45px;display:grid;place-items:center;border-radius:13px;background:#dff8ed;color:#087352;font-weight:850;font-size:1.15rem;margin-bottom:20px}.steps h3{font-size:1.15rem}.steps p{color:var(--muted);font-size:.93rem}.pricing-intro{text-align:center;background:#fff8e8;color:#795c25;border:1px solid #eedcba;padding:13px 18px;border-radius:13px;font-size:.86rem;margin:0 0 28px}.plan-card{display:flex;flex-direction:column;position:relative;min-height:480px}.plan-card.featured{border:2px solid #0d966f;box-shadow:0 22px 55px rgba(8,110,76,.12)}.popular{position:absolute;right:20px;top:-16px;background:#0e966f;color:white;padding:7px 13px;border-radius:50px;font-size:.72rem;font-weight:800}.plan-title{font-size:1.4rem;margin-bottom:8px}.plan-summary{color:var(--muted);font-size:.91rem;min-height:52px;margin-bottom:14px}.price{font-size:2.35rem;font-weight:850;letter-spacing:-.06em;color:#133e32;line-height:1.2}.price small{font-size:.92rem;font-weight:500;letter-spacing:0;color:#7b8982}.monthly{font-size:.78rem;color:#73827f;margin:10px 0 19px}.sep{height:1px;background:#e1ebe7;margin:7px 0 20px}.features{list-style:none;padding:0;margin:0 0 27px;display:grid;gap:13px;flex:1}.features li{font-size:.89rem;color:#35564e;display:flex;gap:10px}.features li:before{content:'✓';font-weight:900;color:#09976c}.plan-card .btn{width:100%}.fine-print{max-width:870px;text-align:center;margin:24px auto 0;font-size:.81rem;color:#71837d}.faq{max-width:870px;margin:auto}.faq details{border:1px solid var(--line);border-radius:13px;padding:21px 24px;background:white;margin:12px 0}.faq summary{font-weight:750;cursor:pointer}.faq p{font-size:.9rem;color:var(--muted);margin:13px 0 0}.closing{background:#073a31;color:white;padding:66px 0}.closing-wrap{display:flex;gap:30px;align-items:center;justify-content:space-between}.closing h2{font-size:2.4rem;margin:0 0 8px}.closing p{color:#b5d4c8;margin:0}.footer{background:#062922;color:#b5d1c8;padding:32px 0;font-size:.84rem}.footinner{display:flex;gap:20px;justify-content:space-between;flex-wrap:wrap}.footinner a{color:#c8e9de}.auth-shell{background:radial-gradient(circle at 92% 10%,#e4faf0,transparent 34%),#f6faf8;padding:67px 0 100px}.auth-layout{max-width:1010px;margin:auto;display:grid;grid-template-columns:1fr .8fr;gap:38px;align-items:start}.auth-box{background:white;padding:36px;border-radius:19px;border:1px solid #dfebe5;box-shadow:var(--shadow)}.auth-box h1{font-size:2rem;margin:0 0 9px}.auth-box>p{color:var(--muted);font-size:.9rem}.form{display:grid;gap:15px;margin-top:24px}.form label{font-weight:700;color:#29483d;font-size:.85rem}.form input,.form select{width:100%;display:block;margin-top:7px;border:1px solid #cfded7;border-radius:10px;padding:13px 14px;background:#fff;color:#193c32;outline:none;transition:border .2s}.form input:focus,.form select:focus{border-color:#0b926c;box-shadow:0 0 0 3px #13ae8425}.form .btn{width:100%;margin-top:8px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.checkboxline{display:flex;gap:10px;align-items:flex-start;font-size:.82rem;color:#51665e}.checkboxline input{width:auto;margin:3px 0 0}.checkboxline a{color:#07876a;text-decoration:underline}.note{padding:13px 15px;border-radius:12px;font-size:.83rem;line-height:1.6}.note-warning{background:#fff6e8;color:#795a24;border:1px solid #f1d7a3}.note-error{background:#fff0ef;color:#a22a28;border:1px solid #f0c5bf}.note-success{background:#eaf8f0;color:#1a644b;border:1px solid #c6e9d7}.auth-side{padding:32px 16px}.auth-side h2{font-size:1.55rem}.auth-side p,.auth-side li{color:#52766b;font-size:.93rem}.auth-side ul{padding-left:20px;line-height:2}.small-link{font-size:.86rem;color:#0a8968;text-decoration:underline}.auth-bottom{margin-top:20px;font-size:.84rem;color:var(--muted)}.hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}.dashboard{background:#f4f8f5;padding:58px 0 110px}.dash-top{display:flex;justify-content:space-between;align-items:start;gap:20px;margin-bottom:29px}.dash-top h1{font-size:2.3rem;margin:0 0 5px}.dash-top p{color:var(--muted)}.dash-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin:23px 0}.metric{border-radius:16px;background:white;padding:23px;border:1px solid var(--line)}.metric strong{font-size:1.7rem;display:block;letter-spacing:-.04em}.metric small{font-size:.8rem;color:var(--muted)}.dash-panel{border-radius:18px;background:white;border:1px solid var(--line);padding:30px;margin-top:20px}.dash-panel h2{font-size:1.3rem;margin-bottom:15px}.steps-list{margin:0;padding-left:25px;color:#405b52;line-height:2.1;font-size:.93rem}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:.89rem;overflow-wrap:anywhere}.badge{display:inline-block;border-radius:50px;padding:7px 12px;font-size:.75rem;font-weight:800;background:#e3f5eb;color:#176247}.badge-pending{background:#fff4d9;color:#835d0b}.logout{border:0;background:transparent;color:#c8e7d9;font-weight:700}.logout:hover{text-decoration:underline}
@media(max-width:900px){.hero-inner{grid-template-columns:1fr;gap:28px;padding:67px 0}.showcase{max-width:560px;transform:none}.cards3,.steps{grid-template-columns:1fr 1fr}.auth-layout{grid-template-columns:1fr}.auth-side{padding:0 5px}.closing-wrap{flex-direction:column;align-items:start}.dash-grid{grid-template-columns:1fr 1fr}}
@media(max-width:650px){.container{width:min(100% - 30px,1170px)}.navline{height:66px}.navlinks{gap:12px}.navlinks .hide-mobile{display:none}.navlinks .btn-sm{padding:9px 12px;font-size:.76rem}.brand{font-size:.94rem}.brandicon{height:30px;width:30px}.hero h1{font-size:2.6rem}.section{padding:70px 0}.cards3,.steps,.dash-grid{grid-template-columns:1fr}.auth-shell{padding:25px 0 65px}.auth-box{padding:24px}.form-grid{grid-template-columns:1fr}.dash-top{flex-direction:column}.showcase{padding:8px}.statrow{gap:5px}.stat{padding:9px 6px}.stat strong{font-size:.98rem}}
</style>
</head>
<body>
<header class="navbar">
  <div class="container navline">
    <a href="/" class="brand" aria-label="WhatsApp Gateway - página inicial"><span class="brandicon">✳</span> Gateway <span style="color:#75e4bb">WhatsApp</span></a>
    <nav class="navlinks" aria-label="Menu principal">
      <a href="/#recursos" class="hide-mobile">Recursos</a>
      <a href="/#como-funciona" class="hide-mobile">Como funciona</a>
      <a href="/#planos">Planos</a>
      <?php if($mode==='dashboard'): ?>
      <form action="/sair" method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=whatsappE($csrf)?>"><button class="logout" type="submit">Sair</button></form>
      <?php else: ?>
      <a href="/entrar" class="hide-mobile">Entrar</a>
      <a href="/cadastro" class="btn btn-primary btn-sm">Criar conta</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main>
<?php if ($mode==='landing'): ?>
<section class="hero">
 <div class="container hero-inner">
  <div>
   <div class="kicker">Integrações, mensagens e automações</div>
   <h1>Seu WhatsApp conectado. <span>Seu negócio em movimento.</span></h1>
   <p>Integre sua empresa à API oficial do WhatsApp com uma plataforma multitenant pensada para quem precisa de mensagens, templates, eventos e webhooks em um único lugar.</p>
   <div class="hero-buttons">
    <a href="/#planos" class="btn btn-primary">Ver planos e preços <span aria-hidden="true">→</span></a>
    <a href="/cadastro" class="btn btn-white">Criar minha conta</a>
   </div>
   <div class="hero-note">Sem WhatsApp Web ou QR code. Requer aprovação e credenciais da Meta.</div>
  </div>
  <div class="showcase" aria-label="Ilustração conceitual de integrações">
   <div class="panel">
    <div class="panel-top"><b>Visão das integrações</b><span class="online">● API oficial</span></div>
    <div class="statrow">
     <div class="stat"><strong>REST</strong><small>API de mensagens</small></div>
     <div class="stat"><strong>HMAC</strong><small>Webhooks assinados</small></div>
     <div class="stat"><strong>24/7</strong><small>Operação por filas</small></div>
    </div>
    <div class="activity">
     <div class="activity-top"><strong>Exemplos de eventos</strong><span style="color:#7a8e87">Cloud API</span></div>
     <div class="activity-item"><i class="dot"></i><span>Mensagem recebida</span><small>Webhook</small></div>
     <div class="activity-item"><i class="dot dot-blue"></i><span>Template aprovado</span><small>Meta</small></div>
     <div class="activity-item"><i class="dot"></i><span>Status de entrega atualizado</span><small>Evento</small></div>
    </div>
    <div class="disclaimer-mini">Representação ilustrativa, não são métricas reais.</div>
   </div>
  </div>
 </div>
</section>
<div class="trustline"><div class="container trust-inner">
 <span><span class="check">✓</span>API oficial da Meta</span>
 <span><span class="check">✓</span>Isolamento por empresa</span>
 <span><span class="check">✓</span>Webhooks e integrações</span>
 <span><span class="check">✓</span>Credenciais por cliente</span>
</div></div>
<section class="section" id="recursos"><div class="container">
 <div class="section-hdr"><div class="eyebrow">O que sua empresa pode integrar</div><h2>Uma base confiável para operar mensagens.</h2><p>O gateway organiza as conexões com a Meta para que seus sistemas possam enviar, receber e acompanhar mensagens com segurança.</p></div>
 <div class="cards3">
  <article class="feature-card"><div class="feature-icon">↗</div><h3>Envio pela Cloud API</h3><p>Envie mensagens e templates aprovados, mídia e tipos interativos compatíveis com sua versão da API.</p></article>
  <article class="feature-card"><div class="feature-icon">◎</div><h3>Webhooks e status</h3><p>Receba mensagens, confirmação de entrega e eventos validados por assinatura, com registro de auditoria.</p></article>
  <article class="feature-card"><div class="feature-icon">▦</div><h3>Mais de um número</h3><p>Cada empresa tem seu próprio ambiente. Associe números e WABAs conforme o limite do plano contratado.</p></article>
  <article class="feature-card"><div class="feature-icon">◈</div><h3>API REST para sistemas</h3><p>Integre ERP, sites, portais, reservas, notificações e suas automações utilizando endpoints padronizados.</p></article>
  <article class="feature-card"><div class="feature-icon">⏱</div><h3>Filas e rastreamento</h3><p>Organize os envios e consulte jobs, eventos e respostas da Meta sem perder a rastreabilidade.</p></article>
  <article class="feature-card"><div class="feature-icon">🔒</div><h3>Isolamento multitenant</h3><p>Dados separados por cliente, senhas protegidas e credenciais da Meta criptografadas no armazenamento.</p></article>
 </div>
</div></section>
<section class="section section-alt" id="como-funciona"><div class="container">
 <div class="section-hdr"><div class="eyebrow">Comece em três etapas</div><h2>Do cadastro à primeira integração.</h2></div>
 <div class="steps">
  <div><div class="step-num">01</div><h3>Escolha um plano</h3><p>Compare os valores propostos e selecione a capacidade adequada para seus números de WhatsApp.</p></div>
  <div><div class="step-num">02</div><h3>Crie sua empresa</h3><p>Faça o autocadastro. Um ambiente exclusivo (tenant) será criado para sua organização automaticamente.</p></div>
  <div><div class="step-num">03</div><h3>Ative e conecte</h3><p>Após a ativação comercial e configuração das credenciais Meta, conecte seu número e comece a utilizar a API.</p></div>
 </div>
</div></section>
<section class="section" id="planos"><div class="container">
 <div class="section-hdr"><div class="eyebrow">Preços transparentes</div><h2>Um plano para cada etapa.</h2><p>Compare a mensalidade do software e escolha a melhor opção. O custo de mensagens da Meta é calculado separadamente.</p></div>
 <div class="pricing-intro"><strong>Valores em proposta comercial:</strong> estes preços são sugestões configuráveis, não constituem cobrança nem oferta final até aprovação. Não há pagamento automático nesta versão.</div>
 <div class="cards3">
 <?php foreach($plans as $plan): ?>
 <article class="plan-card <?=!empty($plan['is_popular'])?'featured':''?>">
  <?php if(!empty($plan['is_popular'])): ?><span class="popular">Mais escolhido</span><?php endif; ?>
  <h3 class="plan-title"><?=whatsappE($plan['title'])?></h3>
  <p class="plan-summary"><?=whatsappE($plan['summary'])?></p>
  <div class="price"><?=whatsappE(WhatsAppPortal::money((int)$plan['price_cents']))?> <small>/mês</small></div>
  <div class="monthly">Mensalidade proposta do gateway</div>
  <div class="sep"></div>
  <ul class="features"><?php foreach((array)$plan['features'] as $feature): ?><li><?=whatsappE($feature)?></li><?php endforeach; ?></ul>
  <a class="btn <?=!empty($plan['is_popular'])?'btn-dark':'btn-outline'?>" href="/cadastro?plano=<?=rawurlencode((string)$plan['slug'])?>">Selecionar <?=whatsappE($plan['title'])?></a>
 </article>
 <?php endforeach; ?>
 </div>
 <p class="fine-print">Os valores não incluem eventuais tarifas da Meta por mensagens entregues, impostos, configuração de número, ativação comercial ou serviços adicionais. Consulte a <a href="https://developers.facebook.com/docs/whatsapp/pricing" rel="noreferrer" style="text-decoration:underline;color:#0a8563">documentação oficial de preços da Meta</a>. Esta página não representa a Meta nem seus parceiros.</p>
</div></section>
<section class="section section-alt" id="duvidas"><div class="container">
 <div class="section-hdr"><div class="eyebrow">Dúvidas frequentes</div><h2>Antes de começar.</h2></div>
 <div class="faq">
 <details><summary>O valor do plano já inclui mensagens do WhatsApp?</summary><p>Não. O preço apresentado é uma proposta de mensalidade do software. A Meta cobra separadamente conforme suas categorias, países, políticas e tabela vigente.</p></details>
 <details><summary>Posso cadastrar mais de uma empresa?</summary><p>Sim. Cada novo cadastro de proprietário cria um tenant independente com isolamento próprio. O número de WhatsApps que cada tenant pode conectar depende do plano selecionado.</p></details>
 <details><summary>Preciso usar QR Code ou WhatsApp Web?</summary><p>Não. O gateway trabalha com a API oficial da Meta e exige WABA, número elegível e token com permissões adequadas.</p></details>
 <details><summary>Minha assinatura começa a ser cobrada ao cadastrar?</summary><p>Não. A conta é criada com ativação comercial pendente. Não há cobrança automática nem processamento de cartão nesta versão.</p></details>
 <details><summary>Esta plataforma já possui CRM e atendimento multiagente?</summary><p>A implementação atual é um gateway de APIs, webhooks e registros. CRM, caixa de entrada, atendimento multiagente e automações visuais são etapas futuras.</p></details>
 </div>
</div></section>
<section class="closing"><div class="container closing-wrap"><div><h2>Pronto para integrar seu WhatsApp?</h2><p>Escolha um plano e crie o ambiente exclusivo da sua empresa.</p></div><a href="/cadastro" class="btn btn-primary">Criar meu cadastro →</a></div></section>
<?php elseif($mode==='register' || $mode==='login'): ?>
<section class="auth-shell"><div class="container auth-layout">
 <div class="auth-box">
  <div class="eyebrow"><?= $mode==='register'?'AUTOCADASTRO':'ACESSO DO CLIENTE' ?></div>
  <h1><?= $mode==='register'?'Crie sua conta':'Bem-vindo de volta' ?></h1>
  <p><?= $mode==='register'?'Seu cadastro cria automaticamente um tenant separado para a sua empresa.':'Acesse o ambiente da sua empresa com seu e-mail e senha.' ?></p>
  <?php if($errors): ?><div class="note note-error" role="alert"><?=whatsappE($errors)?></div><?php endif; ?>
  <?php if(!$ready): ?><div class="note note-warning" style="margin-top:15px">O sistema ainda aguarda a instalação do banco e dos planos. A página pode ser visualizada, mas cadastros e logins estarão indisponíveis até a configuração do servidor.</div><?php endif; ?>
  <?php if($mode==='register'): ?>
   <form action="/cadastro" method="post" class="form" autocomplete="on">
    <input type="hidden" name="csrf" value="<?=whatsappE($csrf)?>">
    <div class="hp" aria-hidden="true"><label>Site <input name="website" type="text" autocomplete="off" tabindex="-1"></label></div>
    <div class="form-grid">
     <label>Seu nome completo <input type="text" name="name" maxlength="120" minlength="3" autocomplete="name" required value="<?=whatsappE($old['name']??'')?>"></label>
     <label>Nome da empresa <input type="text" name="company" maxlength="160" minlength="3" autocomplete="organization" required value="<?=whatsappE($old['company']??'')?>"></label>
    </div>
    <label>E-mail para entrar <input type="email" name="email" maxlength="190" autocomplete="email" required value="<?=whatsappE($old['email']??'')?>"></label>
    <label>Plano desejado
    <select name="plan" required>
     <?php foreach($plans as $plan): ?><option value="<?=whatsappE($plan['slug'])?>" <?=$selected===$plan['slug']?'selected':''?>><?=whatsappE($plan['title'].' — '.WhatsAppPortal::money((int)$plan['price_cents']).'/mês (proposta)')?></option><?php endforeach; ?>
    </select></label>
    <div class="form-grid">
     <label>Senha <input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" required placeholder="12+ caracteres, letras e números"></label>
     <label>Confirme a senha <input type="password" name="confirm_password" minlength="12" maxlength="128" autocomplete="new-password" required></label>
    </div>
    <label class="checkboxline"><input type="checkbox" name="accept_terms" value="yes" required><span>Entendo que estes valores são propostas, que a conta fica pendente de ativação e que tarifas da Meta serão adicionais. Concordo com o armazenamento dos dados fornecidos para cadastro e contato comercial.</span></label>
    <button class="btn btn-dark" type="submit" <?=$ready?'':'disabled'?> style="<?=$ready?'':'opacity:.55;cursor:not-allowed'?>">Criar meu tenant →</button>
   </form>
   <p class="auth-bottom">Já possui conta? <a class="small-link" href="/entrar">Entrar</a></p>
  <?php else: ?>
   <form action="/entrar" method="post" class="form">
    <input type="hidden" name="csrf" value="<?=whatsappE($csrf)?>">
    <label>Seu e-mail <input type="email" name="email" autocomplete="username" required value="<?=whatsappE($old['email']??'')?>"></label>
    <label>Senha <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn btn-dark" type="submit" <?=$ready?'':'disabled'?> style="<?=$ready?'':'opacity:.55;cursor:not-allowed'?>">Entrar no painel →</button>
   </form>
   <p class="auth-bottom">Ainda não tem cadastro? <a class="small-link" href="/cadastro">Criar conta</a></p>
  <?php endif; ?>
 </div>
 <aside class="auth-side">
  <div class="eyebrow">Ambiente exclusivo</div><h2>Uma empresa. Um tenant. Seus dados separados.</h2>
  <p>Seu cadastro gera os registros da organização, do usuário responsável e da assinatura escolhida em uma única operação.</p>
  <ul><li>Chaves e números isolados por cliente</li><li>Credenciais da Meta armazenadas com criptografia</li><li>Acesso ao painel com senha protegida</li><li>Ativação comercial antes da operação</li></ul>
  <div class="note note-warning">Ainda não existe cobrança automática, verificação de e-mail ou configuração totalmente automática dos números. A ativação é feita após a validação comercial.</div>
 </aside>
</div></section>
<?php elseif($mode==='dashboard' && $user): ?>
<section class="dashboard"><div class="container">
 <div class="dash-top"><div><div class="eyebrow">PAINEL DO CLIENTE</div><h1>Olá, <?=whatsappE($user['name'])?>.</h1><p><?=whatsappE($user['company'])?> · <?=whatsappE($user['email'])?></p></div>
 <span class="badge <?=($user['subscription_status']??'')==='active'?'':'badge-pending'?>"><?=($user['subscription_status']??'')==='active'?'Assinatura ativa':'Aguardando ativação'?></span></div>
 <div class="dash-grid">
  <article class="metric"><small>Plano selecionado</small><strong><?=whatsappE($user['plan_title']??'Não configurado')?></strong><small><?=whatsappE(WhatsAppPortal::money((int)($user['price_cents_snapshot']??0)))?> /mês · proposta</small></article>
  <article class="metric"><small>Números cadastrados</small><strong><?=whatsappE($user['number_count'])?> <span style="font-size:.9rem;color:#6c8176">/ <?=whatsappE($user['max_numbers']??0)?></span></strong><small>Contas no tenant</small></article>
  <article class="metric"><small>Mensagens registradas</small><strong><?=whatsappE($user['message_count'])?></strong><small>Eventos técnicos disponíveis após integração</small></article>
 </div>
 <article class="dash-panel">
  <h2>Seu ambiente foi criado</h2>
  <p style="color:#5b7167">Este ambiente é exclusivo da sua organização. Seus dados não são compartilhados com outros clientes.</p>
  <p class="mono"><b>Tenant:</b> <?=whatsappE($user['slug'])?> &nbsp; · &nbsp; <b>ID:</b> <?=whatsappE($user['tenant_id'])?></p>
  <?php if(($user['subscription_status']??'')!=='active'): ?>
  <div class="note note-warning">Assinatura aguardando validação comercial. O cadastro foi realizado, porém não houve cobrança. A liberação da operação e as credenciais da Meta dependem da próxima etapa de configuração.</div>
  <?php else: ?>
  <div class="note note-success">Assinatura ativada. Configure os números e credenciais da Meta com o administrador do gateway.</div>
  <?php endif; ?>
 </article>
 <article class="dash-panel"><h2>Próximas etapas</h2>
  <ol class="steps-list"><li>Confirme o plano e a ativação comercial.</li><li>Prepare o WABA e o número de telefone na Meta.</li><li>Associe suas credenciais oficiais ao tenant.</li><li>Configure webhooks e integre seu sistema à API.</li></ol>
  <p style="font-size:.85rem;color:#678176;margin-top:19px">A integração de pagamento e a administração visual de números ainda estão em desenvolvimento; o painel inicial não permite pagamentos nem disparos.</p>
 </article>
</div></section>
<?php else: ?>
<section class="auth-shell"><div class="container" style="max-width:750px"><div class="auth-box"><h1>Serviço temporariamente indisponível</h1><p>O banco ou o ambiente de execução não está pronto. Os arquivos do portal foram publicados; configure o banco e tente novamente.</p><a href="/" class="btn btn-dark">Voltar ao início</a></div></div></section>
<?php endif; ?>
</main>
<footer class="footer"><div class="container footinner">
 <div><strong>Gateway WhatsApp</strong> · Integrações oficiais, organizadas por empresa.</div>
 <div><a href="/#planos">Planos</a> &nbsp;·&nbsp; <a href="/entrar">Acesso do cliente</a> &nbsp;·&nbsp; <a href="/health">Status da API</a></div>
 <div>Software independente, não afiliado à Meta.</div>
</div></footer>
</body>
</html>