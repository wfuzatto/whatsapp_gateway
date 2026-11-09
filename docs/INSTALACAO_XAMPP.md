# Instalação no XAMPP

## Preparar o ambiente

1. Instale XAMPP com PHP 8.1+, Apache e MySQL/MariaDB.
2. No painel do XAMPP, inicie Apache e MySQL.
3. Confirme no `php.ini` usado pelo Apache que estas extensões estão ativas: `pdo_mysql`, `curl`, `openssl`, `mbstring`, `iconv` e `fileinfo`. Para anexos, configure `file_uploads=On`, `upload_max_filesize=20M` e `post_max_size=22M`. Reinicie o Apache depois de qualquer mudança.
4. Copie a pasta do projeto para `C:\xampp\htdocs\whatsapp_api`. Se escolher outro nome de pasta, use esse caminho nas instruções e na URL.
5. No `httpd.conf`, confirme que `mod_rewrite` está ativo e que a configuração de `htdocs` permite `AllowOverride All`. O `.htaccess` do projeto protege diretórios internos e impede listagem de arquivos.
6. No phpMyAdmin (`http://localhost/phpmyadmin`), crie o banco `whatsapp_gateway`, codificação `utf8mb4` e ordenação `utf8mb4_unicode_ci`. Para XAMPP local, `root` normalmente não tem senha; em qualquer servidor acessível pela rede, crie um usuário MySQL dedicado com senha forte e apenas permissões para esse banco.

## Instalar a aplicação

1. Abra `http://localhost/whatsapp_api/`.
2. Informe nome da empresa principal, nome, e-mail e senha administrativa com pelo menos 12 caracteres.
3. Informe host, porta, nome do banco e usuário MySQL. Digite a senha real do MySQL no instalador; não a envie por chat.
4. Em endereço público, deixe vazio enquanto estiver somente no computador local. Ao publicar, informe o endereço completo da aplicação, incluindo o caminho da pasta se houver, por exemplo `https://mensagens.suaempresa.com` ou `https://dominio.com/whatsapp_api`.
5. Conclua a instalação e entre com o administrador recém-criado.
6. Acesse **Equipe e ajustes → Autenticação em duas etapas**, clique em **Configurar autenticador**, leia o QR com o Google Authenticator (ou informe a chave manualmente) e confirme com o código de 6 dígitos. A administração fica restrita até o segundo fator ser ativado.
7. Em **Clientes da plataforma**, cadastre os clientes e administradores de cada empresa.

O instalador grava `storage/config.local.php`. Não apague nem compartilhe esse arquivo após instalar. Guarde uma cópia segura. Ele contém credenciais do banco e a chave que protege tokens da Meta. Backups do banco e desse arquivo devem ser protegidos com o mesmo cuidado.

## Executar o worker de campanhas e webhooks

O worker envia até 20 destinatários por campanha em cada execução e tenta entregar eventos pendentes aos sistemas clientes. Programe uma tarefa do Windows para executar a cada minuto:

- **Programa:** `C:\xampp\php\php.exe`
- **Argumentos:** `C:\xampp\htdocs\whatsapp_api\worker.php 20`
- **Iniciar em:** `C:\xampp\htdocs\whatsapp_api`

No Agendador de Tarefas, selecione repetição a cada minuto, habilite execução mesmo sem sessão aberta e use a conta do Windows que tem acesso ao XAMPP. Para testar manualmente, rode o mesmo programa e argumentos no PowerShell. O resultado é escrito na saída do processo; consulte também as falhas de entrega na tela **API e integrações**.

Execute apenas uma instância agendada. Se um processo terminar de forma inesperada no meio de um envio, o destinatário pode ficar em estado `processing` para evitar reenvio duplicado; revise essa situação antes de qualquer reprocessamento manual.

## Publicar para receber mensagens da Meta

O webhook oficial precisa de uma URL pública HTTPS com certificado válido. O endereço `http://localhost/whatsapp_api/index.php?r=webhook` serve para a aplicação local, mas a Meta não consegue alcançá-lo. Publique em um servidor com HTTPS ou configure um túnel seguro apenas para desenvolvimento. Atualize o endereço público pelo formulário em **Instalação Meta** e copie a URL exibida nessa tela.

Para uso contínuo na internet, não exponha a configuração padrão do XAMPP sem endurecimento, atualizações, firewall, backup e uma revisão de implantação. XAMPP foi criado para desenvolvimento local.
