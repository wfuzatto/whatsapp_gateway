
# Integração com a Meta — envio de templates para aprovação

**Tela do cliente:** `https://zapapi.inf.br/painel/meta`
**Painel do cliente:** `https://zapapi.inf.br/painel`

## Requisitos na Meta

1. Criar ou acessar um **Portfólio empresarial** e uma conta do WhatsApp Business (WABA).
2. Cadastrar um número no produto WhatsApp do aplicativo Meta com permissões adequadas.
3. Em [Meta for Developers](https://developers.facebook.com/apps/), localizar o **WABA ID** e o **Phone Number ID**.
4. Gerar **token de usuário de sistema** com acesso ao WABA e com permissões `whatsapp_business_management` e `whatsapp_business_messaging`. Um token temporário de desenvolvimento pode expirar.
5. O administrador comercial precisa ativar a assinatura do cliente (isso não cobra nem valida pagamento automaticamente).
6. No painel do cliente, abrir **Integração Meta**, inserir nome interno, WABA ID, Phone Number ID e token.
7. O gateway faz `GET /{WABA_ID}/phone_numbers` para verificar se o número pertence ao WABA e `GET /{WABA_ID}/message_templates` para validar acesso antes de armazenar o token criptografado com libsodium.
8. Para enviar um template: escolha o número, nome do modelo (minúsculas/sublinhado), idioma, categoria (UTILITY ou MARKETING), HEADER/BODY/FOOTER e exemplos correspondentes às variáveis `{{1}}`, `{{2}}`. Clique **Enviar template para análise da Meta**.
9. Modelos **AUTHENTICATION**, mídia ou botões específicos da Meta precisam ser enviados pelo **editor JSON avançado**, conforme a documentação da versão atual da API. O editor não realiza upload de amostras de mídia.
10. O sistema utiliza `POST /{WABA_ID}/message_templates` e consulta a lista diretamente por `GET /{WABA_ID}/message_templates`. O retorno PENDING indica solicitação recebida, não template aprovado. Após aprovação, use o endpoint de mensagens para envio.

## Webhook
A URL publicada é `https://zapapi.inf.br/webhooks/meta`.

**Importante:** no estágio atual o receptor de webhooks usa um **App Secret e Verify Token globais** no arquivo privado `.env`. Eles são controlados pelo operador da plataforma, não fornecidos a clientes individualmente. Se o cliente utilizar um aplicativo Meta de propriedade própria, o webhook exige desenvolvimento adicional de segredo e validação específicos por app; não basta preencher a URL.

No app Meta proprietário da integração, configurar/validar o callback e assinar `messages`, `message_template_status_update`, `message_template_quality_update` e `template_category_update` (quando disponíveis). O painel pode solicitar `POST /{WABA_ID}/subscribed_apps`; isto **não garante** que um aplicativo não autorizado será inscrito automaticamente.

## Chaves de API
Uma chave do gateway é diferente do token da Meta. No painel, selecione o número, informe um nome e crie a chave `wg_...`. Ela é armazenada somente como hash, exibida **uma vez**, e pode ser revogada pelo próprio tenant. Use-a como `Authorization: Bearer wg_...`.

## Segurança e limites
- Conexões, números, templates e chaves são selecionados pela sessão autenticada, filtrados por `tenant_id`; uma conta não pode ser conectada a dois tenants.
- Credenciais Meta nunca são reexibidas em páginas após armazenamento; são cifradas no banco.
- A janela do navegador é protegida por CSRF, sessão HTTPOnly e HTTPS obrigatório em produção.
- A conexão é testada antes de salvar. Um token válido não garante revisão ou aprovação de template.
- As categorias, requisitos de exemplos e regras da Meta podem mudar; a resposta HTTP e o erro da Meta serão exibidos para correção.
- Sem Embedded Signup nesta versão. O onboarding na Meta, app review, autorização comercial, pagamentos e catálogo não são automatizados.

Referências oficiais:
- https://developers.facebook.com/docs/whatsapp/cloud-api/overview
- https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates/
- https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/
- https://www.postman.com/meta/whatsapp-business-platform/overview
