# Configuração do WhatsApp Cloud API pela Meta

1. Crie o portfólio comercial na Meta e um aplicativo Business com WhatsApp.
2. Cadastre os WABAs e números. Anote o `WABA_ID` e o `PHONE_NUMBER_ID`.
3. Configure um usuário de sistema autorizado com as permissões `whatsapp_business_messaging` e `whatsapp_business_management` adequadas, conforme necessidade do endpoint.
4. Em `.env` configure `META_APP_SECRET`, `META_VERIFY_TOKEN`, `GRAPH_VERSION`, banco de dados e a chave de criptografia `MASTER_KEY_BASE64`. Não inclua tokens no Git.
5. Configure HTTPS confiável e callback `https://seu-host/webhooks/meta`. Assine os campos relevantes do objeto `whatsapp_business_account`, especialmente `messages`.
6. Cadastre **um tenant para cada cliente** e vincule seus números com o CLI:

```bash
php bin/whatsapp_manage.php tenant:add empresa-a "Empresa A"
php bin/whatsapp_manage.php account:add empresa-a "Atendimento A" WABA_ID PHONE_NUMBER_ID
php bin/whatsapp_manage.php key:add empresa-a "ERP A" PHONE_NUMBER_ID
```

O comando `account:add` solicita o token Meta no stdin. O comando `key:add` exibe uma API key apenas uma vez.

7. Se necessário, use `POST /v1/phone/register` com PIN de seis dígitos e `POST /v1/webhook-subscriptions` com a chave gerada. Teste de acordo com o status de registro e as permissões do WABA na Meta.
8. Execute `php bin/whatsapp_worker.php --loop` sob serviço supervisionado ou `--once` sob cron.
9. Registre o consentimento dos destinatários e faça teste com template aprovado. Acompanhe callbacks de `sent`, `delivered` e `read` quando disponíveis.
10. Opcionalmente, configure callback específico por número: `php bin/whatsapp_manage.php callback:set PHONE_NUMBER_ID https://erp.example.com/hook`.

O App Secret é o segredo do aplicativo de integração, não um segredo específico por tenant. Cada WABA pertence a somente um tenant, com vários números possíveis. Para chave de tenant com mais de um número ativo, envie o cabeçalho `X-WhatsApp-Phone-Id`. Consulte `docs/whatsapp_MULTITENANT.md`.

A implementação é um gateway Cloud API, não uma plataforma de Tech Provider plenamente homologada: Embedded Signup, cadastro de parceiros e requisitos adicionais da Meta constam no roadmap.

Referências:
- https://www.postman.com/meta/whatsapp-business-platform/overview
- https://developers.facebook.com/docs/whatsapp/cloud-api/overview
- https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks
- https://developers.facebook.com/docs/graph-api/changelog
