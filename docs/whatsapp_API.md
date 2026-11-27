# API de integração ZapApi V2

A API serve sistemas externos autenticados por token próprio da ZapApi. O servidor guarda as credenciais da Meta e chama a WhatsApp Cloud API; o sistema cliente nunca recebe o token Meta.

## Autenticação e isolamento

1. No painel **Integração Meta → Chaves de API**, consulte o token inicial automático da empresa ou crie um token adicional para o sistema que vai integrar. Os tokens têm 256 bits e hash SHA-256; o inicial também possui cópia cifrada para consulta autenticada. Tokens adicionais são vinculados a um número e aparecem somente uma vez.
2. Selecione somente as permissões necessárias e uma validade de 30, 90, 180 ou 365 dias. Para MCP, inclua `mcp:connect`.
3. Envie o token em `Authorization: Bearer wg_...` por HTTPS. Nunca o coloque em JavaScript público, URL, logs, repositório ou conversa.
4. Para rotacionar, crie e configure o token novo no sistema cliente e depois revogue o anterior no painel. A revogação passa a valer nas próximas chamadas.

O tenant e o número autorizado vêm do token, nunca de campos enviados no JSON. O `X-WhatsApp-Phone-Id` só é necessário para chaves legadas de tenant com mais de um número; um token novo do painel já é limitado a um número. As chamadas exigem tenant, conta e assinatura ativos.

Tokens limitados permitem até **120 chamadas por minuto**, das quais no máximo **30 operações de escrita por minuto**, por credencial. Excesso retorna HTTP `429` e `Retry-After: 60`. Tokens legados continuam funcionando na REST por compatibilidade, mas não têm escopos nem expiração e não podem autenticar o MCP; substitua-os e revogue-os.

## Permissões

| Escopo | Acesso |
|---|---|
| `tenant:read` | Dados básicos do tenant |
| `accounts:read` | Números autorizados |
| `conversations:read` | Lista de conversas e estado de leitura |
| `conversations:mark_read` | Confirma leitura à Meta e sincroniza o cursor com aparelhos Android |
| `messages:read` | Histórico de mensagens |
| `messages:send` | Envio de mensagens pela fila |
| `messages:read_receipt` | Confirmação de leitura de uma mensagem Meta |
| `templates:read` | Consulta de templates e status |
| `templates:manage` | Criação, alteração e exclusão de templates |
| `contacts:read` | Lista de contatos |
| `contacts:consent` | Registrar ou revogar consentimento de contato |
| `events:read` | Leitura incremental dos webhooks recebidos |
| `jobs:read` | Situação de um envio |
| `jobs:retry` | Recolocar um envio falho/incerto na fila; pode duplicar |
| `media:upload` | Enviar mídia à Meta |
| `media:read` | Consultar metadados e baixar mídia por proxy da ZapApi |
| `media:delete` | Excluir mídia da Meta |
| `meta:manage` | Operações administrativas de número, perfil, WABA e Flows |
| `mcp:connect` | Conectar ao endpoint MCP; é adicional aos escopos das ferramentas |

Um escopo ausente retorna HTTP `403`. Para integrações MCP, as ferramentas são filtradas pelos mesmos escopos REST.

## REST

Base: `https://zapapi.inf.br`. Todas as rotas `/v1/*` exigem Bearer token.

`GET /health` é público e retorna somente o estado básico do gateway. `GET /webhooks/meta` é o handshake da Meta; `POST /webhooks/meta` aceita apenas eventos autenticados com a assinatura Meta.

| Método | Endpoint | Escopo |
|---|---|---|
| GET | `/v1/tenant` | `tenant:read` |
| GET | `/v1/accounts`, `/v1/me` | `accounts:read` |
| GET | `/v1/conversations?before_id=ID&limit=50&q=termo` | `conversations:read` |
| GET | `/v1/conversations/{wa_id}/messages?before_id=ID&limit=50` | `messages:read` |
| POST | `/v1/conversations/{wa_id}/read` | `conversations:mark_read` |
| GET | `/v1/messages?wa_id={wa_id}&before_id=ID&limit=50` | `messages:read` |
| POST | `/v1/messages` | `messages:send` |
| POST | `/v1/messages/read` | `messages:read_receipt` |
| GET | `/v1/contacts` | `contacts:read` |
| POST | `/v1/contacts/consent` | `contacts:consent` |
| GET | `/v1/events?after_id=ID&limit=50` | `events:read` |
| GET | `/v1/jobs/{id}` | `jobs:read` |
| POST | `/v1/jobs/{id}?action=retry` | `jobs:retry` |
| POST | `/v1/media` | `media:upload` |
| GET | `/v1/media/{id}` | `media:read` |
| GET | `/v1/media/{id}/content` | `media:read` |
| DELETE | `/v1/media/{id}` | `media:delete` |
| GET | `/v1/templates` | `templates:read` |
| POST/DELETE | `/v1/templates` | `templates:manage` |
| POST | `/v1/templates/{id}` | `templates:manage` |
| GET | `/v1/phone`, `/v1/phone-numbers` | `meta:manage` |
| POST | `/v1/phone/register`, `/v1/phone/deregister` | `meta:manage` |
| GET/POST/DELETE | `/v1/webhook-subscriptions` | `meta:manage` |
| GET/POST | `/v1/business-profile`, `/v1/flows` | `meta:manage` |
| GET/POST/DELETE | `/v1/flows/{id}` | `meta:manage` |
| GET | `/v1/graph` | `meta:manage` (somente edges permitidas) |

### Enviar mensagem

Envios dos tokens novos são assíncronos e exigem `Idempotency-Key` estável por intenção. O mesmo valor evita criar um segundo job em uma repetição de rede. O retorno `202` confirma que o job foi colocado na fila, não que a Meta entregou a mensagem.

```http
POST /v1/messages
Authorization: Bearer wg_TOKEN
Idempotency-Key: pedido-123-mensagem-1
Content-Type: application/json

{"to":"5511999999999","type":"template","template":{"name":"hello_world","language":{"code":"en_US"}}}
```

Consulte o `job_id` retornado com `GET /v1/jobs/{id}`. Templates precisam estar aprovados para o idioma e a conta. Mensagens livres estão sujeitas à janela de atendimento e as regras de consentimento do gateway.

### Receber mensagens e eventos

`GET /v1/events` retorna os eventos verificados e armazenados pelo webhook da Meta, em ordem crescente quando usado `after_id`. Guarde `next_after_id` e envie-o na chamada seguinte; isso permite polling idempotente sem pular eventos. Para cada evento, as mensagens recebidas ficam em `payload.entry[].changes[].value.messages`; mudanças de entrega ficam em `statuses`. Use também `/v1/conversations` e `/v1/messages?wa_id=...` para consultar o estado normalizado.

Este endpoint é **polling**: o MCP/API não abre uma conexão persistente nem envia uma notificação espontânea ao cliente. Configure uma rotina de consulta incremental; evite consultar em intervalos muito curtos para respeitar os limites.

### Marcar conversa como lida

```http
POST /v1/conversations/5511999999999/read
Authorization: Bearer wg_TOKEN
Content-Type: application/json

{"message_id":"12345"}
```

`message_id` é o ID local da última mensagem recebida que foi visualizada. A ZapApi confirma leitura à Meta e avança o cursor monotônico da empresa/número/conversa; quando avança, os aparelhos Android ativos recebem o evento FCM correspondente. Repetir um cursor antigo é idempotente.

### Mídia

`POST /v1/media` aceita `multipart/form-data` com o campo `file`. A resposta inclui o ID de mídia da Meta. Use `GET /v1/media/{id}/content` para buscar mídia recebida ou carregada por proxy autenticado: o token Meta e a URL assinada não são expostos; o endpoint de metadados remove a URL da CDN. A resposta é um anexo com `nosniff` e sem cache público.

## Respostas e erros

- `200`: consulta ou operação concluída.
- `202`: envio enfileirado; consulte o job.
- `401`: token ausente, inválido, expirado ou integração inativa.
- `403`: escopo não concedido.
- `404`: recurso fora do tenant/número ou inexistente.
- `422`: parâmetros ou regra de envio inválidos.
- `429`: limite por minuto atingido.
- `502`: falha de rede com a Meta ou resultado de envio incerto.

Respostas de erro seguem `{"error":"..."}`; falhas da Meta podem incluir `upstream_status`. Trate timeout de envio como incerto e repita com a mesma chave de idempotência.

Webhooks Meta recebidos pelo gateway são autenticados com `X-Hub-Signature-256`. Callbacks de integração já configurados usam `X-Gateway-Signature-256` e `X-Gateway-Event-ID` (HMAC SHA-256). A entrada e a saída do gateway devem usar HTTPS.
