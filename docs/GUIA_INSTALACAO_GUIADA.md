# Walkthrough de instalação · Ponte Gateway

O projeto possui um assistente visual de cinco etapas, disponível automaticamente enquanto a plataforma não estiver instalada.

## Preparar o XAMPP

1. Coloque o projeto em C:\xampp\htdocs\whatsapp_api\, preservando os arquivos .htaccess.
2. Inicie Apache e MySQL no XAMPP.
3. Abra o phpMyAdmin em http://localhost/phpmyadmin.
4. Crie o banco vazio chamado whatsapp_gateway, com collation utf8mb4_unicode_ci. Não importe schema.sql manualmente.
5. Abra http://localhost/whatsapp_api/. Caso a pasta tenha outro nome, ajuste a URL.

## Cinco etapas

1. **Requisitos:** checa PHP, extensões, PDO MySQL, schema.sql, proteção HTTP e escrita em storage.
2. **Banco de dados:** recebe host, porta, nome, usuário e senha. Testa a conexão; não aceita banco que já tenha tabelas.
3. **Administrador:** registra empresa, nome, e-mail e senha de no mínimo 12 caracteres, com confirmação.
4. **Endereço público:** no XAMPP deixe vazio. Na hospedagem, informe uma URL HTTPS válida, incluindo a subpasta se houver.
5. **Instalar:** confirma os dados, cria tabelas e o primeiro administrador e só depois grava storage/config.local.php.

Depois, entre com a nova conta e ative a autenticação em duas etapas. Use o menu **Instalação Meta** para configurar a Cloud API oficial.

## Instalação anterior / banco apagado

O sistema considera instalado quando storage/config.local.php informa installed = true. **Apagar o banco não reinicia o walkthrough.**

Se pretende instalar do zero:

1. Faça backup seguro de storage/config.local.php; ele guarda a chave de criptografia dos tokens da Meta.
2. Guarde backup do banco anterior, se ainda existir.
3. Mova o arquivo storage/config.local.php para **fora de htdocs**, sem publicá-lo no GitHub. Não deixe cópias .php0, .txt ou .bak acessíveis na web.
4. Crie um novo banco MySQL vazio.
5. Abra a URL local da aplicação. O walkthrough aparecerá.

Uma nova instalação não restaura clientes, mensagens ou tokens da instalação antiga. O assistente não remove banco ou tabela por conta própria.

## Erros comuns

- **Abre login em vez do assistente:** config.local.php antigo ainda está instalado. Faça backup e siga o procedimento acima.
- **Extensão ausente:** habilite no php.ini do Apache e reinicie Apache.
- **Pasta storage não permite escrita:** corrija as permissões e preserve storage/.htaccess.
- **Falha na conexão:** verifique se o banco existe e confira host, porta, usuário e senha.
- **Banco já possui tabelas:** use outro banco novo e vazio, sem apagar dados existentes.
- **Falha na etapa final:** parte das tabelas pode ter sido criada; use um novo banco vazio e consulte os logs do PHP/Apache.

## Publicação na Locaweb

Confira requisitos de PHP 8.1+, extensões, MySQL e permissões. Use HTTPS e confirme a proteção de storage, app e database pelas regras do Apache.

O instalador não executa o worker continuamente. Configure um agendamento no cron para worker.php, conforme o plano da hospedagem.

Para a integração com a Meta veja [CONFIGURACAO_META.md](CONFIGURACAO_META.md). Consulte também [API.md](API.md).
