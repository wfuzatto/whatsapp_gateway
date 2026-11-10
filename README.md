# WhatsApp Gateway — reimplementação limpa

Gateway REST para a **WhatsApp Business Platform Cloud API oficial da Meta**. Implementação própria em **PHP 8.2+ / MySQL 8+**, sem Composer, npm, SDK de terceiros, browser automation, WhatsApp Web, QR-code, executáveis, DLLs ou binários empacotados. Não é um cliente WhatsApp: exige WABA, número cadastrado, permissões e token autorizados pela Meta.

> ⚠️ Esta versão substitui integralmente os arquivos da branch main, mas **não apaga os commits anteriores** do GitHub. Para remover código suspeito do histórico, é necessária uma operação separada de remoção do histórico/recriação do repositório. O código novo não usa nada da versão antiga.

## Funcionalidades desta primeira implementação

- Envio de mensagens Cloud API de acordo com o formato oficial: texto, template, mídia, localização, contatos, botões, listas, reações, produtos, flows, etc. O payload é repassado à Meta, sem reinventar o schema.
- Recepção dos webhooks de mensagens, status, templates, alterações de conta/número e demais eventos, com validação **HMAC-SHA256** e persistência para auditoria/replay pelo integrador.
- Contatos e registro de consentimento (opt-in); controle conservador da janela de atendimento de 24h.
- Contas/números múltiplos: credenciais Meta criptografadas com libsodium; uma chave de API por conta.
- Endpoint de envio síncrono ou assíncrono com jobs MySQL, chave de idempotência, retries controlados, rastreamento por ID e worker CLI.
- Callback HTTPS configurável por conta com assinatura HMAC, entregas persistentes, novas tentativas e event log.
- Administração Meta: templates, números, perfil comercial, flows, mídia e consulta limitada de outras arestas oficiais.
- Zero credenciais no GitHub, HTTPS/TLS obrigatório em produção, parâmetros validados, token hash, limitação de tamanho e CI de segurança.
- Testes básicos locais e de sintaxe no CI.

**Limites**: A Meta altera recursos e regras. Nem todos os recursos da plataforma (Embedded Signup de Tech Provider, cobrança, catálogo completo, Flow Data Channel com criptografia, onboarding de parceiros, WABA sharing) estão implementados; veja `docs/ROADMAP.md`. Não anuncie conformidade integral antes de testes E2E com uma conta real.

## Instalação

1. Crie **uma base MySQL nova**. Não importe o banco antigo. Execute `database/schema.sql` nela.
2. Instale PHP 8.2+ com `pdo_mysql`, `curl`, `sodium`, `fileinfo` e `openssl`; Apache com rewrite e HTTPS.
3. Copie `.env.example` para `.env` e informe banco, app secret Meta, verify token e `MASTER_KEY_BASE64`. Gere uma chave de 32 bytes com `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.
4. Configure **DocumentRoot** como a pasta `public/` (preferível). Alternativamente, em XAMPP, use o `.htaccess` da raiz; mantenha todos os diretórios privados inacessíveis pela web.
5. Cadastre uma conta: `php bin/manage.php account:add "Minha empresa" WABA_ID PHONE_NUMBER_ID` e informe o token da Meta no prompt do terminal.
6. Crie a API key: `php bin/manage.php key:add PHONE_NUMBER_ID "ERP"`; armazene o valor exibido uma única vez.
7. Configure na Meta o callback público `https://seudominio.com/webhooks/meta` e o mesmo `META_VERIFY_TOKEN`. Assine o objeto WhatsApp Business Account e os campos necessários.
8. Rode `php bin/worker.php --once` por cron a cada minuto, ou `php bin/worker.php --loop` sob serviço supervisionado; em hospedagem compartilhada, use cron.
9. Valide `GET /health` e testes `php tests/smoke.php`.

Consulte `docs/API.md`, `docs/CONFIGURACAO_META.md` e `docs/SEGURANCA.md`.

## Fontes de referência

- https://developers.facebook.com/docs/whatsapp/cloud-api/overview
- https://developers.facebook.com/documentation/business-messaging/whatsapp
- https://www.postman.com/meta/whatsapp-business-platform/overview
- https://developers.facebook.com/docs/graph-api/changelog

**Importante:** um alerta de trojan não prova que o código anterior era malicioso; pode ser falso positivo, mas esta reconstrução evita distribuíveis executáveis e dependências não auditadas. Não reutilize backups, tokens nem banco do sistema antigo sem inspeção e rotação das credenciais.
