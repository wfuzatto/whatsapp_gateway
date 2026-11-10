# REST API — versão 1

## Autenticação
A URL deve ser HTTPS. Para todos os endpoints `/v1/*`, use `Authorization: Bearer wg_...`.
Uma chave pertence a um único número de WhatsApp Business. O `META_APP_SECRET` e o `META_VERIFY_TOKEN` **não** são chaves de API do gateway.

## Endpoints
| Método | Caminho | Função |
|---|---|---|
| GET | /health | Verifica processo |
| GET/POST | /webhooks/meta | Meta verify challenge; eventos assinados |
| GET | /v1/me | ID da conta e versão Graph |
| POST | /v1/messages | Envia mensagem (assíncrono por padrão) |
| POST | /v1/messages?sync=1 | Resposta síncrona direta da Meta |
| POST | /v1/messages/read | Marca mensagem recebida como lida |
| GET | /v1/messages | Lista mensagens armazenadas |
| GET | /v1/contacts | Contatos e consentimentos |
| POST | /v1/contacts/consent | Registrar/revogar consentimento |
| GET | /v1/events | Eventos brutos verificados |
| GET | /v1/jobs/{id} | Job e status |
| POST | /v1/jobs/{id}?action=retry | Reenvio manual: pode duplicar! |
| POST | /v1/media | Upload multipart file |
| GET/DELETE | /v1/media/{media_id} | Metadados/exclusão na Meta |
| GET/POST/DELETE | /v1/templates | Listagem, criação, exclusão ?name= |
| POST | /v1/templates/{template_id} | Atualização via Meta Graph |
| GET | /v1/phone-numbers | Números do WABA |
| GET/POST | /v1/business-profile | Perfil do número |
| GET/POST | /v1/flows | Flows Graph |
| GET/POST/DELETE | /v1/flows/{flow_id} | Consulta, alteração e exclusão |
| GET | /v1/graph?scope=waba&edge=... | Edge Graph permitida, somente leitura |

Formatos de mensagens são os objetos **originais** da Meta: template, text, image, audio, video, document, location, contacts, interactive, reaction, sticker, product, etc. O gateway não reescreve componentes: siga o schema da versão Graph configurada.

## Exemplos
```http
POST /v1/contacts/consent
Authorization: Bearer SUA_CHAVE
Content-Type: application/json

{"wa_id":"5511999999999","consent":true,"source":"formulario-site-2026-11-09"}
```

```http
POST /v1/messages
Authorization: Bearer SUA_CHAVE
Idempotency-Key: pedido-123-aviso-1
Content-Type: application/json

{"to":"5511999999999","type":"template","template":{"name":"hello_world","language":{"code":"en_US"}}}
```

Resposta assíncrona HTTP 202:
```json
{"job_id":10,"status":"queued","duplicate":false}
```
O worker conclui o envio. Consulte `GET /v1/jobs/10`. Chave de idempotência impede duplicação dentro da fila para o mesmo número; **não** garante exatamente uma entrega em falhas de rede para a Meta.

Para texto livre, somente dentro da janela de 24h contada da última mensagem recebida:
```json
{"to":"5511999999999","type":"text","text":{"body":"Olá! Em que podemos ajudar?"}}
```

Para marcar como lida:
```json
{"message_id":"wamid.HBgMNTUxMTk5OTk5OTk5ORUCABIY..."}
```

Para upload:
```bash
curl -X POST https://seudominio.com/v1/media -H 'Authorization: Bearer SUA_CHAVE' -F 'file=@foto.jpg'
```
Respeite limites e formatos de mídia permitidos pela Meta e pelo PHP (upload_max_filesize, post_max_size). **Não** exponha token Meta a aplicações clientes.

## Eventos e callbacks
Webhook Meta: `X-Hub-Signature-256: sha256=<hex>`; calculado sobre bytes originais com o App Secret.
Callbacks aos seus sistemas: `X-Gateway-Signature-256` e `X-Gateway-Event-ID`; calcule HMAC SHA256 sobre o JSON raw usando o segredo configurado. Endpoint de destino deve retornar HTTP 2xx para confirmar. Eventos são deduplicados por hash de entry + change; a entrega do callback é pelo menos uma vez.

## Paginação
`/v1/messages` e `/v1/events` aceitam `?before_id=NUMERO&limit=50`. Máximo 100. `/v1/contacts` usa limite. A resposta pode conter dados pessoais: proteja e aplique retenção.
