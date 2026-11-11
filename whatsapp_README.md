# WhatsApp Gateway — API Cloud Oficial da Meta (multi-tenant)

Gateway REST em **PHP 8.2+ e MySQL 8.0+**, sem dependências de terceiros empacotadas, WhatsApp Web, QR-code ou arquivos executáveis. Suporta vários **clientes (tenants)** na mesma instalação; cada cliente pode ter vários WABAs e números.

> Este repositório foi reconstruído do zero. O histórico Git antigo continua acessível; mantenha tokens antigos revogados. A aplicação é uma base operacional, ainda não uma plataforma BSP comercial completa.

## Isolamento multi-tenant
- `whatsapp_tenants`: clientes independentes, status active/suspended.
- `whatsapp_wabas`: vincula cada WABA a **somente um tenant**.
- `whatsapp_accounts`: números/credenciais por tenant; cada tenant pode ter múltiplos números.
- `whatsapp_api_keys`: chave restrita a um tenant inteiro ou a **um número** do tenant.
- Contatos, mensagens, eventos recebidos, jobs e auditoria mantêm `tenant_id` e `account_id`, com FKs compostas evitando cruzamentos.
- Webhooks são verificados pelo HMAC da Meta e roteados pelo `phone_number_id` ou pelo WABA.
- Um cliente suspenso não pode autenticar e seus jobs não são despachados; contas suspensas também não são selecionáveis.

O **prefixo obrigatório** dos 9 nomes das tabelas é `whatsapp_`. Os arquivos comuns do repositório também são nomeados com `whatsapp_`. Exceções técnicas: `.gitignore` e `.htaccess` (nomes obrigatórios para Git/Apache), além do arquivo local não versionado `.env`. Os nomes de colunas (`id`, `tenant_id`, etc.) não precisam de prefixo.

## Instalação limpa
1. PHP 8.2+ com `pdo_mysql`, `curl`, `sodium`, `fileinfo`; MySQL 8.0+; servidor HTTPS.
2. **Crie um banco vazio separado.** Importe `database/whatsapp_schema.sql`. **Não rode isso por cima do banco antigo**: ainda não há migração automática nem dedução segura de qual conta antiga pertence a qual tenant.
3. Copie `whatsapp_env.example` para `.env` no diretório **acima de `/public_html/`** (fora do Git e do diretório público) e preencha variáveis. Há fallback legado para `/public_html/.env`, mas não é o local recomendado. Gere `MASTER_KEY_BASE64` com `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.
4. Configure Apache DocumentRoot em `public/`, ou use o `.htaccess` da raiz em XAMPP. O script de entrada agora é `public/whatsapp_index.php`.
5. Cadastre os clientes e números pelo CLI (tokens da Meta são pedidos via stdin, nunca argumentos):
```bash
php bin/whatsapp_manage.php tenant:add cliente-a "Cliente A"
php bin/whatsapp_manage.php tenant:add cliente-b "Cliente B"

php bin/whatsapp_manage.php account:add cliente-a "Numero principal A" 111111111111 222222222222
php bin/whatsapp_manage.php account:add cliente-a "Numero secundario A" 111111111111 333333333333
php bin/whatsapp_manage.php account:add cliente-b "Numero B" 444444444444 555555555555

php bin/whatsapp_manage.php key:add cliente-a "ERP A"
php bin/whatsapp_manage.php key:add cliente-b "ERP B" 555555555555
```
6. Insira o callback Meta `https://seu-dominio.com/webhooks/meta`, configure App Secret/Verify Token, assine os eventos WhatsApp Business Account e teste o HMAC.
7. Rode `php bin/whatsapp_worker.php --loop` com supervisor ou `--once` pelo cron.
8. Consulte `GET /health`, `GET /v1/tenant`, `GET /v1/accounts`; faça o primeiro envio de template aprovado.

## Como escolher o número
A chave específica de um número seleciona apenas esse número. Uma chave geral do cliente pode selecionar vários; se o cliente tiver mais de um número ativo, envie `X-WhatsApp-Phone-Id: PHONE_NUMBER_ID` nas operações por número. O cabeçalho **não permite** acessar números de outros tenants.

```http
POST /v1/messages
Authorization: Bearer SUA_CHAVE_DO_TENANT
X-WhatsApp-Phone-Id: 222222222222
Idempotency-Key: pedido-123-notificacao
Content-Type: application/json

{"to":"5511999999999","type":"template","template":{"name":"hello_world","language":{"code":"en_US"}}}
```

Antes de templates, registre consentimento por `POST /v1/contacts/consent`. Texto livre exige janela de atendimento de 24h. A fila evita duplicação por idempotência no próprio gateway; falhas de rede com entrega incerta exigem reconciliação manual.

## Documentação
- `docs/whatsapp_MULTITENANT.md` — arquitetura e procedimentos.
- `docs/whatsapp_API.md` — endpoints, autenticação e exemplos.
- `docs/whatsapp_CONFIGURACAO_META.md` — credenciais e webhooks.
- `docs/whatsapp_SEGURANCA.md` — limites e mitigação de risco.
- `docs/whatsapp_ROADMAP.md` — funcionalidades comerciais ainda pendentes.

Testes: `php tests/whatsapp_smoke.php`. O GitHub Actions executa os testes de integração multitenant com MySQL 8 isolado.

Fontes oficiais: https://www.postman.com/meta/whatsapp-business-platform/overview e https://developers.facebook.com/docs/whatsapp/cloud-api/overview


## Publicacao FTP em /public_html/

O workflow [WhatsApp - Publicar FTP em public_html](https://github.com/wfuzatto/whatsapp_gateway/actions/workflows/whatsapp_ftp_test.yml) transfere os arquivos da aplicacao para a pasta **/public_html/** (nao para a raiz FTP). Esta tarefa e acionada manualmente em Actions > Run workflow e quando o proprio workflow e atualizado.

- Destino: `ftp.zapapi1.hospedagemdesites.ws:21/public_html/`
- Protocolo: `ftp` sem criptografia, por solicitacao do administrador.
- Usuario e senha: `FTP_USERNAME` e `FTP_PASSWORD` guardados em GitHub Actions Secrets.
- Antes de substituir um arquivo existente, cria-se backup ZIP fora do diretorio publico em `/whatsapp_gateway_backups/`.
- Preserva outros arquivos e pastas no servidor; nao apaga nada do site.
- Envia somente os arquivos de execucao: `.htaccess`, `public/`, `src/`, scripts administrativos `bin/` e esquema `database/whatsapp_schema.sql`. Nao publica `.github`, testes, documentacao, nem arquivos `.env`.
- Confere com download e SHA256 cada arquivo enviado.
- Se houver `index.php`, `index.html` ou WordPress na raiz, nao sobrescreve um projeto alheio e interrompe o deploy.
- Importante: os arquivos do PHP ainda exigem um `.env` privado configurado no servidor e o banco `whatsapp_` preparado; publicar arquivos nao configura credenciais nem executa migracoes automaticamente.

**Alerta:** FTP comum transmite senha e arquivos sem TLS. Como a senha de FTP ja foi compartilhada em mensagens e pode ser a mesma do banco, use uma senha exclusiva e rotacione-a quanto antes.


## Página inicial de diagnóstico

A rota `GET /` apresenta uma página pública informativa em `public/whatsapp_home.php`, sem carregar credenciais ou consultar o banco. O status `GET /health` confirma apenas a disponibilidade do endpoint HTTP/PHP; **não valida** a conexão com MySQL, a API Meta ou a configuração `.env`. Essa página não é o painel de administração: login, gestão visual de tenants e inbox continuam pendentes no roadmap.

## Portal público, planos e autocadastro
- `GET /`: landing page comercial responsiva e planos; os valores são **propostas de mensalidade**, não preços oficiais finais.
- `GET /cadastro` / `POST /cadastro`: formulário com CSRF, senha protegida por hash, proteção de tentativas e transação MySQL de **tenant + owner + assinatura**.
- `GET /entrar` / `POST /entrar`: autenticação por sessão segura, com redirecionamento ao painel.
- `GET /painel`: visão isolada do tenant e status de assinatura; `POST /sair`: logout.
- Os planos iniciais de exemplo são Essencial R$ 79/mês (1 número), Profissional R$ 199/mês (3 números) e Escala R$ 399/mês (10 números). Os preços devem ser aprovados antes de comercialização; tarifas Meta não incluídas.
- A assinatura nasce em `pending_activation`, **sem cobrança ou ativação automática**; o administrador pode aprová-la com `php bin/whatsapp_manage.php subscription:activate TENANT_SLUG` após validação comercial.
- Os valores públicos vêm da tabela `whatsapp_plans`, editáveis com `php bin/whatsapp_manage.php plan:price SLUG CENTAVOS`. O preço selecionado fica registrado na assinatura como snapshot para consulta.
- Instalação nova: importe `database/whatsapp_schema.sql`. Banco existente com as nove tabelas anteriores: aplique **apenas** `database/whatsapp_migration_portal.sql` uma vez. Não importe os dois scripts no mesmo banco.
- O cadastro só é habilitado quando o banco e o catálogo de planos estão disponíveis. Não exponha dados do banco ou segredos em repositório público nem utilize FTP comum para novas senhas.

A ativação de número, pagamentos automáticos, e-mail de confirmação e caixa de entrada visual ainda não estão disponíveis. Não confunda a landing de planos com uma plataforma BSP completa.

## Assistente inicial de instalação — `/install`
Abra **https://zapapi.inf.br/install** (ou o domínio temporário da hospedagem).

O instalador mostra os parâmetros do banco remoto pré-configurados (host: `zapapi1.mysql.dbaas.com.br`, database/user: `zapapi1`); não recebe hostname arbitrário no formulário. Exige:
1. HTTPS (não use HTTP para credenciais).
2. Chave aleatória do arquivo **`/whatsapp_install_key.txt` na raiz do FTP**, fora de `/public_html/`. O GitHub Actions cria este arquivo *uma única vez*, caso a instalação ainda não tenha sido bloqueada. Acesse-o pelo FileZilla e mantenha-o secreto.
3. Senha atual do MySQL, digitada apenas pelo navegador HTTPS; nunca adicione ao GitHub.
4. Nome/e-mail e senha nova do superadministrador (mínimo 12 caracteres com letras e números).

Depois de confirmar, a instalação verifica acesso ao banco, cria as 14 tabelas com prefixo `whatsapp_`, carrega os três planos iniciais, registra o administrador, gera `MASTER_KEY_BASE64` e token de verificação, e salva o `.env` privado em `/home/zapapi1/.env`. O arquivo não é enviado por FTP nem publicado no Git. A chave temporária é removida e um marcador de instalação é gravado fora do diretório público.

Depois, acesse **`/admin`** ou **`/admin/entrar`**. No painel, veja os tenants e ative/suspenda assinaturas manualmente. Ativar assinatura não cobra o cliente.

O instalador **não sobrescreve `.env` existente, não derruba tabelas e recusa reinstalação** após a criação do primeiro superadmin. Se a hospedagem não autorizar gravar acima de `public_html`, interrompe com erro seguro em vez de expor segredos na pasta pública. Por segurança, não use a senha de FTP como senha do MySQL; troque senhas divulgadas anteriormente.


## Integração Meta pelo painel do cliente

Após ativar a assinatura, acesse `/painel/meta`. O cliente pode cadastrar e validar WABA ID, Phone Number ID e token de sistema, consultar status de templates, submeter modelos para análise via Graph API, solicitar inscrição no webhook global e criar/revogar suas próprias chaves de API. Nenhum token é revelado depois de salvo. Os dados são isolados por tenant. Consulte `docs/whatsapp_META_INTEGRACAO.md` para roteiro de configuração. A aprovação de templates é feita exclusivamente pela Meta; o software não os aprova automaticamente.


## ZapApi Messenger Android (separado da V2)

A V1 expõe uma API de aplicativo em `/mobile-api/v1/` que é **independente do login PHP do navegador e não usa credenciais Meta no Android**.

- `GET /mobile-api/v1/health` — verifica disponibilidade do contrato.
- `POST /mobile-api/v1/auth/login` — autentica usuário proprietário da tabela `whatsapp_users` com assinatura ativa; token de sessão opaco de sete dias.
- `POST /mobile-api/v1/auth/logout` — revoga a sessão e remove registro de dispositivo.
- `GET /mobile-api/v1/me`, `GET /mobile-api/v1/accounts`, `GET|POST /mobile-api/v1/conversations`.
- `GET|POST /mobile-api/v1/conversations/{account_id}-{phone}/messages` — conversa restrita ao tenant. Texto livre apenas dentro da janela de atendimento de 24 horas, verificada no servidor.
- `GET /mobile-api/v1/templates?account_id=...` — templates aprovados; `POST /mobile-api/v1/conversations/{id}/templates` — somente template aprovado e contato com consentimento registrado.
- `POST /mobile-api/v1/devices` — armazena token FCM cifrado; envio de pushes exige serviço FCM do backend, ainda separado.
- `GET /mobile-api/v1/contacts` — contatos autorizados do tenant.

As tabelas novas são `whatsapp_mobile_sessions` e `whatsapp_mobile_devices`, criadas de forma aditiva via `database/whatsapp_mobile.sql` no primeiro login. Não altera nem recria banco existente. Chaves Meta permanecem no servidor. Login do superadministrador `/admin` não é aceito no app; cada cliente usa sua conta `/entrar`. Verificar janela e consentimento antes de envio; o app nunca contorna políticas da Meta.
