# Configuração oficial da Meta

1. Crie um portfólio comercial na Meta e um app do tipo Business habilitado para WhatsApp.
2. Configure a WhatsApp Business Account (WABA) e um número de telefone. Obtenha **WABA ID** e **Phone Number ID**.
3. Conceda ao usuário de sistema as permissões necessárias, como `whatsapp_business_messaging` e `whatsapp_business_management`, conforme cada operação, e gere um token apropriado.
4. Em configurações do app, obtenha **App Secret**. Na seção Webhooks, defina uma string aleatória como **Verify Token**.
5. Informe em `.env` o `META_APP_SECRET` e `META_VERIFY_TOKEN`, sem publicar.
6. Suba o gateway atrás de HTTPS válido e registre callback `https://seu-host/webhooks/meta`. Ative os eventos relevantes do objeto WhatsApp Business Account, especialmente `messages` e eventos de templates/conta.
7. No terminal do servidor, cadastre a conta com `php bin/manage.php account:add ...` e crie uma API key específica.
8. Faça um teste com número autorizado e template aprovado. Confirme os eventos `sent`, `delivered` e `read`, quando disponíveis.
9. Configure um callback de saída opcional `php bin/manage.php callback:set PHONE_ID https://erp.exemplo.com/webhook` e informe segredo aleatório no terminal.

Se for atuar como **Tech Provider / Solution Partner**, Embedded Signup, business verification, concessão de permissões, WABA onboarding e contratos com a Meta são etapas adicionais que não se resolvem apenas instalando o gateway.

Documentação:
- https://www.postman.com/meta/whatsapp-business-platform/overview
- https://developers.facebook.com/docs/whatsapp/cloud-api/overview
- https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks
- https://developers.facebook.com/docs/whatsapp/business-management-api
- https://developers.facebook.com/docs/whatsapp/flows

## Versão da Graph API
O padrão do exemplo é `v26.0` (lançada em julho de 2026). **A versão pode ser configurada** por `GRAPH_VERSION`. Teste os endpoints contra a versão em uso, pois schemas, limites e regras podem mudar.
