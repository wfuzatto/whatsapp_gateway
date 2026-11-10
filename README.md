# Ponte · Gateway WhatsApp

Plataforma web multicliente em PHP e MySQL para uso com WhatsApp Cloud API oficial. Foi preparada para instalação local no XAMPP e inclui painel de clientes, atendimento, templates, campanhas em fila, API REST, webhooks de entrada e retorno, auditoria e guia de ativação Meta.

## Assistente visual de instalação

Na primeira execução, abra http://localhost/whatsapp_api/. O **walkthrough de cinco etapas** orienta requisitos do PHP, conexão MySQL, administrador, endereço público e criação da plataforma. Para reinstalar após apagar um banco, faça backup e retire a configuração local antiga. Leia [GUIA_INSTALACAO_GUIADA.md](docs/GUIA_INSTALACAO_GUIADA.md).

## Instalação rápida no XAMPP

1. Instale e inicie Apache e MySQL no XAMPP.
2. Copie esta pasta para `C:\xampp\htdocs\whatsapp_api`.
3. No phpMyAdmin, crie o banco `whatsapp_gateway` com `utf8mb4_unicode_ci`.
4. Abra `http://localhost/whatsapp_api/` e conclua o instalador. Ele cria as tabelas e o primeiro usuário da plataforma.
5. Entre, ative a autenticação por aplicativo e abra **Instalação Meta** para configurar a primeira conta.
6. Siga os guias em [`docs/INSTALACAO_XAMPP.md`](docs/INSTALACAO_XAMPP.md) e [`docs/CONFIGURACAO_META.md`](docs/CONFIGURACAO_META.md).

Não existe usuário ou senha padrão da plataforma. O primeiro administrador e a senha são criados no instalador. A configuração local, inclusive senha do banco e chave de cifragem, fica em `storage/config.local.php`, bloqueado para acesso HTTP pelo `.htaccess`. Faça backup seguro desse arquivo junto com o banco; sem a chave de cifragem, tokens já salvos não podem ser recuperados.

## O que está incluído

- Empresas clientes e usuários separados por `tenant_id`, com perfis de plataforma, administrador, supervisor e atendente.
- Autenticação TOTP obrigatória para administradores e administração da plataforma.
- Conexão manual assistida à Cloud API, credenciais cifradas, teste do número e configuração do webhook Meta.
- Central de conversas com texto e anexos recebidos/enviados (imagem JPEG/PNG, vídeo MP4, áudio compatível e PDF), status da Meta, atribuição, encerramento e limite de 24 horas.
- Gestão e sincronização de templates aprovados.
- Respostas rápidas privadas por empresa e disponíveis para a equipe na caixa de entrada.
- API autenticada para contatos, upload/download de mídias, envio e consulta de mensagens e templates, idempotência e chaves revogáveis.
- Webhooks de retorno por cliente, assinatura HMAC, fila durável, novas tentativas e verificação de URL HTTPS pública.
- Contatos com registro de consentimento e bloqueio de descadastramento.
- Campanhas para contatos autorizados, modelos sem variáveis numéricas e processamento pelo worker PHP.
- Painel de instalação, auditoria e instruções de operação.

## Padrão das tabelas

As **18 tabelas do sistema** usam o prefixo obrigatório `whatsapp_` (ex.: `whatsapp_tenants`, `whatsapp_contacts` e `whatsapp_messages`). O nome do banco continua podendo ser `whatsapp_gateway`. Para uma instalação nova, crie um banco **vazio**: o walkthrough cria as tabelas já com o prefixo correto. Se deseja preservar dados de uma instalação anterior, consulte [migrações](database/migrations/README.md) e nunca renomeie tabelas genéricas em um banco compartilhado com outros sistemas.

## Requisitos

- PHP 8.1 ou superior com `pdo_mysql`, `curl`, `openssl`, `mbstring`, `fileinfo`, `json` e `iconv`.
- MySQL 8 ou MariaDB 10.5 ou superior.
- Apache com `mod_rewrite` e `AllowOverride All` para as regras `.htaccess`.
- A pasta `storage` precisa permitir escrita pelo usuário do Apache.
- Para receber webhooks Meta, domínio público com HTTPS válido. `localhost` sozinho não pode receber eventos da Meta.
- Para campanhas e webhooks dos clientes, agende `worker.php` no Windows Task Scheduler ou no cron do servidor.

## Operação

Consulte [`docs/API.md`](docs/API.md) e [`docs/openapi.yaml`](docs/openapi.yaml) para autenticação, requisições e assinatura dos webhooks de retorno. Use a página **Clientes da plataforma** para cadastrar cada empresa e seu administrador. O administrador de cada cliente entra com seu próprio usuário, conecta os ativos Meta da própria empresa e cria as chaves e integrações dela.

## Limites funcionais atuais

- Cada empresa configura um número WhatsApp nesta versão. A conexão Meta entregue é assistida por IDs e token autorizados. Embedded Signup ainda depende de elegibilidade e App Review e não está ativado nesta versão.
- A central envia mídia dentro da janela de atendimento e busca anexos recebidos diretamente da Meta; a aplicação limita uploads a 5 MB para imagens e 16 MB para vídeo, áudio e PDF, e a visualização de anexos recebidos a 32 MB. Cabeçalhos de mídia em templates, botões interativos completos, filas de atribuição avançadas e histórico anterior do WhatsApp não estão implementados.
- Campanhas aceitam templates sem variáveis e são processadas em lotes ao executar o worker. O operador precisa revisar consentimento e preparar a campanha. Não há cálculo de fatura Meta.
- Não foi executada validação com credenciais reais da Meta. Essa etapa exige o aplicativo, o portfólio, o número, as permissões e o webhook público do operador.
- A documentação de preços da Meta apresenta informações divergentes sobre mensagens de serviço. Confirme os preços atuais diretamente com a Meta antes de estimar custos ou vender planos.

## Segurança e produção

XAMPP é adequado para desenvolvimento e validação local. Antes de expor a aplicação à internet, use HTTPS, uma conta MySQL dedicada sem privilégios de administrador, firewall, backups, monitoramento, atualização do PHP/Apache e revisão de segurança. Defina política de retenção, aviso de privacidade, base legal e atendimento a titulares conforme o uso e a LGPD.
