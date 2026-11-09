# API e webhooks de retorno

Todos os endpoints da API usam a URL da aplicação mais `api/v1/...`. Gere uma chave em **API e integrações** e envie-a como `Authorization: Bearer SUA_CHAVE`. A chave completa só aparece uma vez. O banco guarda o hash; revogue-a se perder ou expor.

## Registrar contato e autorização

`POST /api/v1/contacts`

```json
{
  "name": "Ana Silva",
  "phone": "5511999999999",
  "consent": true,
  "consent_source": "Formulário de pedido 8472, aceito em 2026-11-02"
}
```

Quando `consent` for `true`, envie uma descrição verificável da origem do consentimento. Atualizações sem consentimento explícito não removem um bloqueio ou descadastramento já registrado.

## Enviar mensagem

`POST /api/v1/messages`

Texto livre só funciona se houver uma conversa com mensagem recebida nas últimas 24 horas. Para iniciar contato ou falar fora da janela, envie um template aprovado e um contato com consentimento confirmado.

```json
{
  "to": "5511999999999",
  "template": {
    "name": "confirmacao_pedido",
    "language": { "code": "pt_BR" }
  }
}
```

Para texto dentro da janela:

```json
{
  "to": "5511999999999",
  "text": "Olá! Posso ajudar com seu pedido?"
}
```

Use `Idempotency-Key` para reduzir o risco de duplicar solicitações repetidas. Uma resposta `202` informa que a Meta aceitou a solicitação; estados finais chegam depois pelo webhook. O retorno inclui `message_id` e `status`.

## Enviar mídia

Faça upload autenticado do arquivo para a conta Meta da empresa: `POST /api/v1/media` como `multipart/form-data`, com campo `file` e o mesmo cabeçalho `Authorization`. A aplicação aceita JPEG/PNG até 5 MB; MP4, áudio compatível e PDF até 16 MB. A resposta contém um `asset_id` local, vinculado à empresa e ao número configurado.

Use esse identificador no endpoint de mensagens, sempre respeitando a janela de atendimento aberta:

```json
{
  "to": "5511999999999",
  "media": {
    "asset_id": 42,
    "caption": "Segue o documento solicitado."
  }
}
```

`caption` é opcional e se aplica a imagens, vídeos e documentos. O sistema mantém os IDs e metadados da mídia separados por empresa. A Meta pode expirar ou rejeitar um arquivo; se isso acontecer, envie-o novamente. Para obter um anexo recebido pelo webhook, use o `asset_id` entregue no evento ou listado em `GET /api/v1/messages` e baixe-o em `GET /api/v1/media/{asset_id}` com uma chave que tenha escopo `messages:read`.

## Consultar dados

- `GET /api/v1/messages` retorna até 100 mensagens recentes da empresa.
- `GET /api/v1/templates` retorna nome, idioma, categoria e estado dos templates locais.

Erros usam JSON com `error` e um código HTTP apropriado. A API opera no escopo da empresa vinculada à chave; não aceite `tenant_id` fornecido na requisição.

## Webhook do cliente

Configure um endereço HTTPS público em **API e integrações**. O sistema enfileira os eventos e o worker faz as entregas com espera progressiva, sem seguir redirecionamentos. Após salvar, copie o segredo de assinatura que aparece uma única vez.

Eventos disponíveis:

- `message.received`
- `message.sent`
- `message.status`

O cabeçalho `X-Ponte-Event` informa o tipo. `X-Ponte-Signature` contém `sha256=` seguido do HMAC-SHA256 do corpo JSON exato, usando o segredo compartilhado. Exemplo para validar em PHP:

```php
$raw = file_get_contents('php://input');
$provided = $_SERVER['HTTP_X_PONTE_SIGNATURE'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $raw, $webhookSecret);
if (!hash_equals($expected, $provided)) {
    http_response_code(401);
    exit;
}
$event = json_decode($raw, true);
```

O objeto tem `id`, `type`, `created_at` e `data`. Responda com HTTP 2xx rapidamente. Falhas são tentadas novamente até oito vezes; depois aparecem como falhas na página de integração.

## Configuração do worker

O endpoint de envio não processa campanhas nem entregas de webhook do cliente em segundo plano. Agende `php worker.php 20` a cada minuto, conforme o guia XAMPP. Um lote processa até 20 destinatários de cada campanha e até 30 webhooks pendentes por execução. Não rode múltiplas cópias agendadas em paralelo.


O contrato OpenAPI pode ser baixado na aplicação em **API e integrações** ou consultado em [openapi.yaml](openapi.yaml).
