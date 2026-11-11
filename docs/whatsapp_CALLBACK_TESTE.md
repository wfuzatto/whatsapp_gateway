# Receptor de retorno para diagnóstico (ZapAPI)

Este receptor é para o **callback do tenant** e NÃO substitui o webhook principal da Meta.

## URL
Se a raiz da aplicação é `https://prodatastelecom.com.br/sites/whatsapp_gateway`, usar:
`https://prodatastelecom.com.br/sites/whatsapp_gateway/whatsapp_callback_receiver.php`

O arquivo físico deve estar em `public/whatsapp_callback_receiver.php` conforme o roteamento do projeto. Para uma instalação que não use o rewrite do projeto, ajuste o caminho público de acordo com a hospedagem.

## Ativação
No `.env` **privado**, fora do diretório público, acrescente:
```
WHATSAPP_CALLBACK_TEST_SECRET=COLOQUE_AQUI_UMA_CHAVE_ALEATORIA_DE_PELO_MENOS_32_CARACTERES
```
Gerar localmente: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
Use o mesmo valor no campo "Segredo de assinatura opcional" do tenant. Não coloque no Git, em URLs, nem envie em mensagens.

No painel do tenant:
- URL HTTPS do cliente: URL acima
- Segredo de assinatura: exatamente `WHATSAPP_CALLBACK_TEST_SECRET`
- Eventos: marque os três tipos, se desejar todos.

O receptor aceita somente POST HTTPS com JSON válido e header `X-Ponte-Signature` ou `X-Gateway-Signature-256` no formato `sha256=<hmac-hex>`, calculado sobre o corpo bruto.
Retorna 200 com `{"ok":true,"received":true}` quando verificado.
GET retorna 405; segredo não configurado retorna 503; assinatura incorreta retorna 401.

## Log privado
Registra somente horário, tipo e ID do evento em `/home/USUARIO/whatsapp_callback_test_events.log` (um nível acima de public_html na implantação esperada). O receptor falha com 503 caso o log não seja gravável. Não armazena o corpo JSON. Proteja o arquivo e remova após o diagnóstico.

## Deploy
Workflow `.github/workflows/whatsapp_ftp_test.yml` publica em `ftp.zapapi1.hospedagemdesites.ws:/public_html/`, não necessariamente no domínio `prodatastelecom.com.br/sites/whatsapp_gateway`. Confirme que essa é a mesma aplicação. Se não for, copie `public/whatsapp_callback_receiver.php` para a pasta `public/` correspondente da instalação do site e garanta que `src/whatsapp_bootstrap.php` esteja disponível.

## Observação
O webhook da Meta permanece separado (ex.: `/webhook` na instalação atual). Nenhuma alteração deve ser feita na URL cadastrada na Meta.
