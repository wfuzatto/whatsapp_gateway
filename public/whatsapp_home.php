<?php
declare(strict_types=1);
// Pagina publica apenas informativa. Nao consulta banco e nao revela segredos.
header('Content-Type: text/html; charset=utf-8');
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; base-uri \'none\'; form-action \'none\'; frame-ancestors \'none\'');
header('X-Robots-Tag: noindex, nofollow');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>WhatsApp Gateway — API Oficial</title>
  <style>
    :root { color-scheme: light; font-family: system-ui, -apple-system, Segoe UI, sans-serif; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #f4f7f8; color: #14262b; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
    main { width: min(760px,100%); background: white; border: 1px solid #dae4e4; box-shadow: 0 14px 48px rgba(12,36,39,.08); border-radius: 20px; padding: clamp(24px,5vw,48px); }
    .eyebrow { font-size: .79rem; font-weight: 750; letter-spacing: .08em; color: #29746d; text-transform: uppercase; }
    h1 { font-size: clamp(2rem, 5vw, 2.8rem); margin: 12px 0 16px; letter-spacing: -.045em; line-height: 1.12; }
    p { line-height: 1.65; color: #4c6168; margin: 0 0 24px; }
    .pill { display: inline-block; padding: 7px 12px; font-size: .84rem; font-weight: 650; border-radius: 100px; background: #e9f5f1; color: #1b6e59; }
    section { border-top: 1px solid #e3eaea; margin-top: 26px; padding-top: 24px; }
    h2 { font-size: 1.05rem; margin: 0 0 12px; }
    .links { display: flex; flex-wrap: wrap; gap: 10px; }
    a { display: inline-block; padding: 10px 15px; border-radius: 9px; text-decoration: none; color: #155349; background: #e9f5f1; font-weight: 650; }
    a:hover, a:focus { background: #d4e9e1; }
    .notice { background: #fff9ec; border-left: 4px solid #e2af55; padding: 14px 18px; border-radius: 8px; font-size: .91rem; line-height: 1.55; }
    footer { font-size: .75rem; color: #70868b; margin-top: 28px; }
  </style>
</head>
<body>
<main>
  <div class="eyebrow">WhatsApp Business Platform</div>
  <h1>WhatsApp Gateway</h1>
  <p>Gateway multitenant para integração com a Cloud API oficial da Meta. A aplicação disponibiliza endpoints HTTP para mensagens, contatos, webhooks e administração de números.</p>
  <span class="pill">Servidor HTTP respondeu</span>
  <section>
    <h2>Verificações e documentação</h2>
    <div class="links">
      <a href="/health">Verificar endpoint /health</a>
      <a href="https://github.com/wfuzatto/whatsapp_gateway/blob/main/docs/whatsapp_API.md" rel="noreferrer">Documentação da API</a>
    </div>
  </section>
  <section>
    <h2>Configuração do sistema</h2>
    <div class="notice">Esta página não é um painel administrativo e não confirma conexão com o banco ou com a Meta. O banco MySQL, o arquivo privado de configuração e as credenciais da Meta precisam ser configurados e testados separadamente. A interface de login e gestão visual ainda não foi implementada.</div>
  </section>
  <footer>Versão inicial do gateway — acesso aos dados protegido por chave de API.</footer>
</main>
</body>
</html>
