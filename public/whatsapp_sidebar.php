<?php
declare(strict_types=1);
if(!defined('WHATSAPP_SIDEBAR_VIEW')){http_response_code(404);exit;}
$sidebarUser=$sidebarUser??($user??[]);
$sidebarPage=$sidebarPage??($page??($mode==='dashboard'?'dashboard':''));
$sidebarCsrf=(string)($sidebarCsrf??($csrf??''));
$sidebarCompany=(string)($sidebarUser['company']??$sidebarUser['name']??'Sua empresa');
$sidebarName=(string)($sidebarUser['name']??'Usuário');
$sidebarPlan=(string)($sidebarUser['plan_title']??'Plano da empresa');
$sidebarActive=(string)($sidebarUser['subscription_status']??'')==='active';
$sidebarOperation=in_array($sidebarPage,['dashboard','inbox','new','contacts'],true);
$sidebarEngagement=in_array($sidebarPage,['templates','campaigns','quick_replies'],true);
$sidebarConfiguration=$sidebarPage==='meta';
$sidebarE=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<aside class="whatsapp-sidebar" id="whatsapp-sidebar" aria-label="Navegação da empresa">
  <a class="whatsapp-sidebar-brand" href="/painel" aria-label="ZapApi — visão geral">
    <img src="/whatsapp_assets/whatsapp_zapapi_logo.svg" width="154" height="44" alt="ZapApi">
  </a>
  <div class="whatsapp-sidebar-workspace">
    <span class="whatsapp-sidebar-dot" aria-hidden="true"></span>
    <span class="whatsapp-sidebar-workspace-copy"><b><?=$sidebarE($sidebarCompany)?></b><small><?=$sidebarE($sidebarPlan)?></small></span>
    <span class="whatsapp-sidebar-status <?=$sidebarActive?'is-active':'is-pending'?>" title="<?=$sidebarActive?'Assinatura ativa':'Assinatura aguardando ativação'?>" aria-label="<?=$sidebarActive?'Assinatura ativa':'Assinatura aguardando ativação'?>"></span>
  </div>
  <nav class="whatsapp-sidebar-nav">
    <a class="whatsapp-nav-overview <?=$sidebarPage==='dashboard'?'is-current':''?>" href="/painel" <?=$sidebarPage==='dashboard'?'aria-current="page"':''?>>
      <span aria-hidden="true">⌂</span> Visão geral
    </a>
    <details class="whatsapp-nav-group" <?=$sidebarOperation?'open':''?>>
      <summary><span>OPERAÇÃO</span><i aria-hidden="true"></i></summary>
      <div class="whatsapp-nav-items">
        <a class="<?=$sidebarPage==='inbox'||$sidebarPage==='new'?'is-current':''?>" href="/painel/conversas" <?=$sidebarPage==='inbox'?'aria-current="page"':''?>><span aria-hidden="true">▤</span> Conversas</a>
        <a class="whatsapp-nav-subitem <?=$sidebarPage==='new'?'is-current':''?>" href="/painel/conversas/nova" <?=$sidebarPage==='new'?'aria-current="page"':''?>>＋ Iniciar conversa</a>
        <a class="<?=$sidebarPage==='contacts'?'is-current':''?>" href="/painel/contatos" <?=$sidebarPage==='contacts'?'aria-current="page"':''?>><span aria-hidden="true">♙</span> Contatos</a>
      </div>
    </details>
    <details class="whatsapp-nav-group" <?=$sidebarEngagement?'open':''?>>
      <summary><span>ENGAJAMENTO</span><i aria-hidden="true"></i></summary>
      <div class="whatsapp-nav-items">
        <a class="<?=$sidebarPage==='templates'?'is-current':''?>" href="/painel/templates" <?=$sidebarPage==='templates'?'aria-current="page"':''?>><span aria-hidden="true">▧</span> Templates <em>Meta</em></a>
        <a class="<?=$sidebarPage==='campaigns'?'is-current':''?>" href="/painel/campanhas" <?=$sidebarPage==='campaigns'?'aria-current="page"':''?>><span aria-hidden="true">◉</span> Campanhas</a>
        <a class="<?=$sidebarPage==='quick_replies'?'is-current':''?>" href="/painel/respostas-rapidas" <?=$sidebarPage==='quick_replies'?'aria-current="page"':''?>><span aria-hidden="true">✎</span> Respostas rápidas</a>
      </div>
    </details>
    <details class="whatsapp-nav-group" <?=$sidebarConfiguration?'open':''?>>
      <summary><span>CONFIGURAÇÃO</span><i aria-hidden="true"></i></summary>
      <div class="whatsapp-nav-items">
        <a class="<?=$sidebarConfiguration?'is-current':''?>" href="/painel/meta" <?=$sidebarConfiguration?'aria-current="page"':''?>><span aria-hidden="true">⌘</span> Integração Meta</a>
      </div>
    </details>
  </nav>
  <div class="whatsapp-sidebar-account">
    <span class="whatsapp-sidebar-avatar"><?=function_exists('mb_strtoupper')?htmlspecialchars(mb_strtoupper(mb_substr($sidebarName,0,1,'UTF-8'),'UTF-8'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):htmlspecialchars(strtoupper(substr($sidebarName,0,1)),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')?></span>
    <span class="whatsapp-sidebar-account-copy"><b><?=$sidebarE($sidebarName)?></b><small><?=$sidebarE($sidebarUser['email']??'Conta da empresa')?></small></span>
    <form action="/sair" method="post"><input type="hidden" name="csrf" value="<?=$sidebarE($sidebarCsrf)?>"><button type="submit" aria-label="Sair" title="Sair">↪</button></form>
  </div>
</aside>
<button class="whatsapp-sidebar-scrim" type="button" data-sidebar-close tabindex="-1" aria-label="Fechar menu"></button>
