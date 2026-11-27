<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Documentação da API e do servidor MCP da ZapApi, com autenticação por token, escopos e exemplos de envio e recebimento de mensagens WhatsApp.">
  <title>MCP e API ZapApi — documentação</title>
  <style>
    :root{color-scheme:light;--ink:#15382d;--muted:#5d766d;--green:#087c5b;--line:#d7e8df;--paper:#f4f8f5;--card:#fff;--code:#10241d}
    *{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}
    header{background:#fff;border-bottom:1px solid var(--line)}.top{max-width:1100px;margin:auto;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{font-weight:800;font-size:1.2rem;color:var(--green);text-decoration:none}.top nav{display:flex;gap:18px;flex-wrap:wrap}.top nav a,.link{color:var(--green);font-weight:650;text-decoration:none}.top nav a:hover,.link:hover{text-decoration:underline}
    main{max-width:1100px;margin:34px auto;padding:0 22px 64px}.hero{padding:30px 32px;background:linear-gradient(130deg,#e5f5ed,#fff);border:1px solid var(--line);border-radius:20px}.eyebrow{color:var(--green);font-size:.78rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}h1{font-size:clamp(2rem,5vw,3.1rem);line-height:1.1;margin:10px 0}h2{font-size:1.45rem;margin:0 0 12px}h3{font-size:1.08rem;margin:22px 0 8px}p{margin:8px 0 14px}.muted,small{color:var(--muted)}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px}.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:22px;margin-top:18px}.grid .card{margin-top:0}.endpoint{display:inline-block;background:#e3f4eb;border-radius:9px;padding:10px 14px;color:#075b42;font:600 .98rem ui-monospace,SFMono-Regular,Consolas,monospace;overflow-wrap:anywhere}
    pre{margin:10px 0 4px;padding:18px;background:var(--code);color:#e6f3eb;border-radius:12px;white-space:pre-wrap;overflow-wrap:anywhere;overflow:auto;font:13px/1.55 ui-monospace,SFMono-Regular,Consolas,monospace}code{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}.inline{background:#eef4f0;padding:2px 5px;border-radius:5px}.pill{display:inline-block;margin:3px;padding:3px 9px;border:1px solid var(--line);border-radius:999px;background:#f6faf7;font-size:.83rem}.steps{padding-left:22px}.steps li{margin:8px 0}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{text-align:left;vertical-align:top;border-bottom:1px solid var(--line);padding:10px 8px}th{font-size:.82rem;color:var(--muted)}.notice{padding:14px 16px;border-left:4px solid #d9a33b;background:#fff8e8;border-radius:8px}.btn{display:inline-block;margin-top:10px;padding:10px 15px;background:var(--green);color:#fff;border-radius:9px;text-decoration:none;font-weight:700}.btn:hover{background:#056448}footer{text-align:center;border-top:1px solid var(--line);padding:20px;color:var(--muted);font-size:.86rem}
    @media(max-width:700px){.grid{grid-template-columns:1fr}.hero{padding:24px 20px}.card{padding:18px}table{font-size:.9rem}}
  </style>
</head>
<body>
<header><div class="top"><a class="brand" href="/">ZapApi</a><nav><a href="/entrar?destino=token">Gerar token</a><a href="/painel/conversas">Central de conversas</a></nav></div></header>
<main>
  <section class="hero">
    <div class="eyebrow">Integração segura e multitenant</div>
    <h1>MCP e API ZapApi</h1>
    <p>Conecte um sistema ao WhatsApp oficial da empresa para consultar conversas, receber eventos e enviar mensagens ou templates aprovados. Cada token fica isolado em um único tenant e número autorizado.</p>
    <p><strong>Endpoint MCP</strong></p><div class="endpoint">https://zapapi.inf.br/mcp</div>
    <p><a class="btn" href="/entrar?destino=token">Gerar token</a></p>
  </section>

  <div class="grid">
    <section class="card">
      <h2>1. Acessar seu token</h2>
      <ol class="steps">
        <li>Clique em <strong>Gerar token</strong> e faça login. Você será direcionado às configurações, na seção Chaves de API.</li>
        <li>Seu token inicial é gerado automaticamente no cadastro, sem necessidade de criá-lo manualmente.</li>
        <li>O token inicial já inclui <code class="inline">mcp:connect</code>. Para integrações adicionais, selecione somente os escopos necessários.</li>
        <li>Use o botão Copiar token nas configurações. O token ZapAPI é independente do token de acesso da Meta.</li>
      </ol>
      <p>O backend deriva o tenant e o número do token. Um campo enviado pelo cliente não pode ampliar esse acesso. Tokens têm validade configurável e podem ser revogados no painel.</p>
    </section>
    <section class="card">
      <h2>2. Configurar o cliente MCP</h2>
      <p>Configure o cliente com o endpoint e o token Bearer em um cofre de segredos. Não coloque credenciais em JavaScript público, URLs, logs ou repositórios.</p>
      <pre><code>{
  "mcpServers": {
    "zapapi": {
      "url": "https://zapapi.inf.br/mcp",
      "headers": {
        "Authorization": "Bearer wg_SEU_TOKEN"
      }
    }
  }
}</code></pre>
      <p class="muted">A sintaxe pode variar entre os clientes MCP. Chamadas de máquina podem omitir o cabeçalho Origin; clientes de navegador precisam de origem HTTPS permitida pelo servidor.</p>
    </section>
  </div>

  <section class="card">
    <h2>Exemplo HTTP: consultar ferramentas</h2>
    <p>O MCP usa Streamable HTTP. Cada chamada leva o token no cabeçalho, e o cliente deve manter os IDs retornados pelos eventos para continuar a leitura incremental.</p>
    <pre><code>curl -X POST "https://zapapi.inf.br/mcp" \
  -H "Authorization: Bearer wg_SEU_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2025-11-25" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'</code></pre>
    <p>O servidor só anuncia ferramentas cujos escopos foram concedidos ao token.</p>
  </section>

  <section class="card">
    <h2>Ferramentas MCP</h2>
    <table>
      <thead><tr><th>Ferramenta</th><th>Escopo</th><th>Uso</th></tr></thead>
      <tbody>
        <tr><td><code>accounts_list</code></td><td><code>accounts:read</code></td><td>Consulta o número autorizado pelo token.</td></tr>
        <tr><td><code>conversations_list</code></td><td><code>conversations:read</code></td><td>Lista conversas, última mensagem e não lidas.</td></tr>
        <tr><td><code>messages_list</code></td><td><code>messages:read</code></td><td>Consulta o histórico de uma conversa.</td></tr>
        <tr><td><code>messages_send</code></td><td><code>messages:send</code></td><td>Enfileira texto, mídia ou template aprovado.</td></tr>
        <tr><td><code>templates_list</code></td><td><code>templates:read</code></td><td>Consulta templates e o status informado pela Meta.</td></tr>
        <tr><td><code>events_list</code></td><td><code>events:read</code></td><td>Busca eventos incrementais do webhook.</td></tr>
        <tr><td><code>jobs_get</code></td><td><code>jobs:read</code></td><td>Consulta o resultado de um envio enfileirado.</td></tr>
        <tr><td><code>conversation_mark_read</code></td><td><code>conversations:mark_read</code></td><td>Confirma leitura e sincroniza o cursor com os Androids.</td></tr>
      </tbody>
    </table>
    <p class="muted">Todos os escopos são adicionais a <code class="inline">mcp:connect</code>. O endpoint valida o tenant, a assinatura, o número vinculado e a validade do token em cada chamada.</p>
  </section>

  <div class="grid">
    <section class="card">
      <h2>Exemplo: enviar mensagem</h2>
      <p>Chame <code class="inline">messages_send</code> com uma chave de idempotência estável por intenção. O retorno <code class="inline">queued</code> significa que o gateway aceitou o job; consulte o <code class="inline">job_id</code> para acompanhar o processamento.</p>
      <pre><code>{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "messages_send",
    "arguments": {
      "idempotency_key": "reserva-123-msg-1",
      "message": {
        "to": "5511999999999",
        "type": "template",
        "template": {
          "name": "reserva_confirmada",
          "language": {"code": "pt_BR"}
        }
      }
    }
  }
}</code></pre>
      <p>Use um template aprovado para o idioma e WABA corretos fora da janela de atendimento. Texto livre depende da janela de 24 horas e do consentimento registrado. A chave deve ter de 8 a 180 caracteres.</p>
    </section>
    <section class="card">
      <h2>Exemplo: receber mensagens</h2>
      <p>Use <code class="inline">events_list</code> para buscar eventos após o cursor salvo. Persista o <code class="inline">next_after_id</code> e envie-o na consulta seguinte para não pular eventos.</p>
      <pre><code>{
  "jsonrpc": "2.0",
  "id": 3,
  "method": "tools/call",
  "params": {
    "name": "events_list",
    "arguments": {"after_id": "0", "limit": 50}
  }
}</code></pre>
      <p>O resultado contém os webhooks verificados, incluindo mensagens recebidas e estados de entrega. O MCP consulta por polling; ele não abre uma conexão espontânea de eventos. Para callbacks, use HTTPS, valide <code class="inline">X-Gateway-Signature-256</code> e deduplique <code class="inline">X-Gateway-Event-ID</code>.</p>
    </section>
  </div>

  <section class="card">
    <h2>REST API e mídia</h2>
    <p>Base REST: <code class="inline">https://zapapi.inf.br/v1</code>. As rotas exigem Bearer token e escopo correspondente. Para upload de mídia, use <code class="inline">POST /v1/media</code> com multipart/form-data e o escopo <code class="inline">media:upload</code>; depois passe o ID retornado em <code class="inline">messages_send</code>.</p>
    <pre><code>curl -X POST "https://zapapi.inf.br/v1/messages" \
  -H "Authorization: Bearer wg_SEU_TOKEN" \
  -H "Idempotency-Key: reserva-123-msg-1" \
  -H "Content-Type: application/json" \
  -d '{"to":"5511999999999","type":"template","template":{"name":"reserva_confirmada","language":{"code":"pt_BR"}}}'</code></pre>
  </section>

  <p class="notice"><strong>Entrega e aprovação:</strong> templates só podem ser enviados depois de aprovados pela Meta. O gateway respeita consentimento e janela de atendimento; um envio aceito pela fila ainda precisa ser confirmado pelo status do job/webhook.</p>
</main>
<footer>ZapApi · Integração oficial WhatsApp Cloud API · Cada token está restrito ao tenant e ao número autorizados.</footer>
</body>
</html>
