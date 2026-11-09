# Configurar WhatsApp Cloud API na Meta

Este guia acompanha a conexão assistida da aplicação. A Meta controla a criação e aprovação de empresas, aplicativos, números, templates, permissões e faturamento; a plataforma não consegue aprovar esses itens por você.

## 1. Portfólio empresarial e número

1. Entre no [Meta Business Suite](https://business.facebook.com/) com uma conta administradora legítima da empresa.
2. Crie ou selecione o portfólio empresarial e complete os dados empresariais solicitados pela Meta.
3. Abra o [WhatsApp Manager](https://business.facebook.com/wa/manage/) e crie ou selecione uma conta WhatsApp Business (WABA).
4. Adicione um número que a empresa controla e conclua a validação que a Meta solicitar. Registre o WABA ID e o Phone Number ID. O Phone Number ID é um identificador da API e não é o número telefônico visível.
5. Configure o perfil da empresa, dados de suporte e faturamento exigidos para o uso previsto.

Para desenvolvimento, use primeiro um número e destinatário de teste disponibilizados no painel do aplicativo, quando elegíveis. Produção exige ativos e permissões da própria empresa ou o arranjo de parceiro aplicável.

## 2. Aplicativo e permissões

1. Em [Meta for Developers](https://developers.facebook.com/apps/), crie um aplicativo de tipo Business, associe o portfólio e adicione o produto WhatsApp.
2. Anote o App ID e o App Secret. O App Secret é usado pelo sistema para validar a assinatura dos webhooks recebidos.
3. Conceda somente as permissões adequadas ao caso de uso. O envio e a administração da WhatsApp Business Platform usam permissões como `whatsapp_business_messaging` e `whatsapp_business_management`; operações sobre o portfólio podem exigir `business_management`.
4. Para clientes independentes, cada empresa deve autorizar o acesso aos próprios ativos pelo processo aceito pela Meta. Não reutilize tokens de uma empresa para outra.
5. Gere um token autorizado para os ativos e permissões necessários. Tokens de teste podem expirar rapidamente. Em operação, use o método de token e validade apropriado à conta e monitore sua expiração. Nunca envie token ou senha pelo chat.
6. A Meta pode exigir verificação empresarial, App Review e Advanced Access antes de permitir o onboarding de clientes ou o uso público. Confira o status no painel do aplicativo.

## 3. Preencher a aplicação

Em **Instalação Meta**, salve:

- App ID;
- WABA ID;
- Phone Number ID;
- token de acesso autorizado;
- token de verificação escolhido por você;
- App Secret.

Os tokens são cifrados no servidor. Depois de salvar, os campos ficam mascarados; deixe-os vazios para preservar o segredo existente. Use **Testar conexão** para confirmar que a Graph API consegue consultar o número. O endereço base público e a versão Graph API podem ser alterados pelo formulário no fim desta tela. A instalação inicia em `v26.0`; confira o calendário de disponibilidade na [documentação oficial de versões da Graph API](https://developers.facebook.com/docs/graph-api/overview/versioning/) antes de atualizações futuras.

## 4. Webhook da Meta

1. Publique a aplicação atrás de HTTPS com certificado válido e endereço público.
2. Em **Instalação Meta**, copie a URL de callback, que termina em `/webhook`.
3. No painel Meta Developers, configure o produto Webhooks para o objeto `whatsapp_business_account`, com a mesma URL e o token de verificação salvo na aplicação.
4. Confirme a assinatura do callback. A aplicação responde ao desafio GET somente quando o token coincide.
5. Inscreva o aplicativo nos campos de webhook necessários, especialmente `messages`, e associe a WABA ao aplicativo conforme o fluxo vigente da Meta.
6. Envie uma mensagem de teste para o número e confira o recebimento no painel e os estados assíncronos.

As chamadas POST são validadas pelo cabeçalho `X-Hub-Signature-256` usando o App Secret sobre o corpo bruto. Eventos duplicados são deduplicados por empresa. A URL pública, firewall, DNS e disponibilidade do webhook são responsabilidade da implantação.

## 5. Templates e atendimento

Crie templates em **Templates** ou no WhatsApp Manager, envie para revisão e sincronize o estado. Só modelos aprovados podem iniciar conversas pela API. A aplicação bloqueia mensagens de texto livres quando a janela de atendimento de 24 horas terminou. Templates com variáveis de corpo precisam de exemplos aceitos pela Meta. A caixa de entrada e a API suportam parâmetros de texto no corpo; campanhas em lote estão limitadas a templates sem variáveis nesta versão. Cabeçalhos de mídia e botões interativos não são enviados pela interface atual. Dentro da janela de atendimento, a caixa envia imagem JPEG/PNG (até 5 MB), vídeo MP4, áudio compatível e PDF (até 16 MB por arquivo). Também baixa anexos recebidos da Meta para exibição, com limite de 32 MB por arquivo. A Meta valida formato, codificação e tamanho finais; o limite local não substitui as regras oficiais.

Obtenha e registre autorização antes de enviar mensagens proativas, descreva com clareza o tipo de conteúdo e respeite qualquer pedido de descadastramento. A aplicação bloqueia contatos marcados como descadastrados das campanhas.

## 6. Parceiros, Embedded Signup e cobrança

O cadastro incorporado Embedded Signup requer o fluxo oficial de login da Meta, IDs de configuração, permissões, elegibilidade e revisão do aplicativo conforme a modalidade de parceiro. A versão atual oferece conexão assistida por cliente; não declara aprovação como Solution Partner nem substitui as etapas de App Review/Advanced Access. Avalie o [programa de parceiros](https://whatsappbusiness.com/partners/become-a-partner/) antes de vender onboarding self-service.

A Meta cobra conforme suas regras vigentes de categoria, destino e entrega, e um parceiro pode cobrar serviços próprios. As páginas oficiais consultadas podem divergir sobre preços de mensagens de serviço. Confira os valores e faturamento no [portal oficial de preços](https://whatsappbusiness.com/products/platform-pricing/) e no WhatsApp Manager antes de estimar valores.

## Fontes oficiais

- [Visão geral da WhatsApp Business Platform e coleções oficiais da Meta](https://www.postman.com/meta/whatsapp-business-platform/overview)
- [Coleção WhatsApp Cloud API](https://www.postman.com/meta/whatsapp-business-platform/collection/wlk6lh4/whatsapp-cloud-api)
- [Versionamento da Graph API](https://developers.facebook.com/docs/graph-api/overview/versioning/)
- [Exemplo oficial Meta: upload de imagem pelo endpoint `/media`](https://www.postman.com/meta/whatsapp-business-platform/request/q3rsuse/upload-image)
- [Documentação Embedded Signup e chamadas de onboarding](https://www.postman.com/meta/whatsapp-business-platform/documentation/du6gzjv/embedded-signup)
- [Política de mensagens para empresas](https://whatsappbusiness.com/policy/)
- [Preços da plataforma](https://whatsappbusiness.com/products/platform-pricing/)
- [Perguntas frequentes sobre WhatsApp Business](https://whatsappbusiness.com/resources/faq/)
- [Programa de parceiros](https://whatsappbusiness.com/partners/become-a-partner/)
