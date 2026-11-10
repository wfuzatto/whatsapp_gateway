# WhatsApp Gateway REST API (multi-tenant)

A API oficial da Meta é chamada apenas pelo servidor. Todas as rotas `/v1/*` precisam de `Authorization: Bearer wg_...`. Cada chave pertence a um tenant e, opcionalmente, a uma conta (número).

Em operações por número, informe `X-WhatsApp-Phone-Id: PHONE_NUMBER_ID` quando tiver mais de um número no tenant. O ID deve corresponder ao tenant autenticado e ao escopo da chave.

| Método | Endpoint | Uso |
|---|---|---|
| GET | /health | Saúde |
| GET/POST | /webhooks/meta | Verificação e evento Meta com HMAC |
| GET | /v1/tenant | Tenant autenticado |
| GET | /v1/accounts | Números do tenant, sem credenciais |
| GET | /v1/me | Número selecionado, tenant e versão Graph |
| POST | /v1/messages | Envio via fila, chave `Idempotency-Key` opcional |
| POST | /v1/messages?sync=1 | Envio síncrono |
| GET | /v1/messages | Histórico apenas do número |
| POST | /v1/messages/read | Marca lida |
| GET | /v1/contacts | Contatos e consentimento |
| POST | /v1/contacts/consent | Registrar/revogar consentimento |
| GET | /v1/events | Webhooks brutos verificados |
| GET | /v1/jobs/{id} | Situação da fila |
| POST | /v1/jobs/{id}?action=retry | Retry manual (pode duplicar) |
| POST | /v1/media | Upload de arquivo multipart |
| GET/DELETE | /v1/media/{id} | Metadados/excluir mídia |
| GET/POST/DELETE | /v1/templates | Templates |
| POST | /v1/templates/{id} | Alterar template |
| GET | /v1/phone-numbers | Números do WABA |
| GET | /v1/phone | Informações do número |
| POST | /v1/phone/register | Registro com PIN |
| POST | /v1/phone/deregister | Remoção com confirmação |
| GET/POST/DELETE | /v1/webhook-subscriptions | Subscrições no WABA |
| GET/POST | /v1/business-profile | Perfil comercial |
| GET/POST | /v1/flows | Flows |
| GET/POST/DELETE | /v1/flows/{id} | Flow individual |
| GET | /v1/graph | Areias Graph de leitura explicitamente permitidas |

Exemplo com tenant de dois números:
```http
POST /v1/contacts/consent
Authorization: Bearer CHAVE_TENANT_A
X-WhatsApp-Phone-Id: 222222222222
Content-Type: application/json

{"wa_id":"5511999999999","consent":true,"source":"formulario-site-2026"}
```

```http
POST /v1/messages
Authorization: Bearer CHAVE_TENANT_A
X-WhatsApp-Phone-Id: 222222222222
Idempotency-Key: pedido-123
Content-Type: application/json

{"to":"5511999999999","type":"template","template":{"name":"hello_world","language":{"code":"en_US"}}}
```

Retorno do envio assíncrono: `{"job_id":10,"status":"queued","duplicate":false}`. Verifique `GET /v1/jobs/10` com a mesma chave/número. Mensagens em texto livre só estão permitidas enquanto a janela de atendimento estiver aberta. Conteúdos e tipos seguem os objetos originais da Meta.

Webhooks Meta usam `X-Hub-Signature-256`. Callbacks enviados pelo gateway usam `X-Gateway-Signature-256` e `X-Gateway-Event-ID`. O corpo bruto é autenticado com HMAC SHA-256. Eventos são deduplicados e gravados em transação. Todas as respostas precisam ser tratadas por códigos HTTP; 202 não significa que a Meta já aceitou a mensagem.

Paginação do histórico: `?before_id=123&limit=50`. APIs Graph usam versão configurável em `GRAPH_VERSION`; recursos comerciais adicionais da Meta exigem permissões e testes próprios.
