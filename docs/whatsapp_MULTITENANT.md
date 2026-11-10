# Arquitetura multi-tenant

## Entidades
```
whatsapp_tenants (cliente)
    |
    +-- whatsapp_wabas (WABA exclusivo do cliente)
           |
           +-- whatsapp_accounts (um número/credencial por registro)
                  |
                  +-- whatsapp_contacts
                  +-- whatsapp_messages
                  +-- whatsapp_webhook_events
                  +-- whatsapp_jobs
                  +-- whatsapp_audit_logs
    |
    +-- whatsapp_api_keys (escopo cliente, opcionalmente número)
```

Cada conta possui `tenant_id`, `waba_id`, `phone_number_id`, `token_box` e status. Todos os registros operacionais pertencem ao tenant e ao número. As FKs compostas `(tenant_id,account_id)` impedem gravar uma mensagem ou job do tenant A usando a conta do tenant B. `whatsapp_wabas.waba_id` é globalmente único: um WABA não pode ser associado a dois clientes. Um mesmo WABA pode ter vários números do mesmo cliente.

## Provisionamento do cliente

```bash
php bin/whatsapp_manage.php tenant:add empresa-x "Empresa X"
php bin/whatsapp_manage.php account:add empresa-x "Atendimento" WABA_ID PHONE_NUMBER_ID
php bin/whatsapp_manage.php key:add empresa-x "Integracao geral"
php bin/whatsapp_manage.php key:add empresa-x "Integracao numero" PHONE_NUMBER_ID
php bin/whatsapp_manage.php account:list empresa-x
php bin/whatsapp_manage.php tenant:list
```

Os tokens Meta e segredos de callbacks são cifrados no banco com a chave de instalação (`MASTER_KEY_BASE64`). Não coloque senhas em argumentos shell ou no Git. A chave emitida para API é exibida uma única vez e o hash fica em `whatsapp_api_keys`.

## Regras de API
- Toda chamada `/v1/*` exige Bearer token; o tenant é inferido da chave, **nunca** de um `tenant_id` fornecido pelo cliente.
- `GET /v1/tenant` retorna o tenant autenticado. `GET /v1/accounts` retorna somente seus números (ou apenas o número permitido por chave específica).
- Endpoint por número usa `X-WhatsApp-Phone-Id` quando uma chave de tenant encontra vários números. Uma chave vinculada a conta não pode apontar para outro número.
- O gateway rejeita tanto outro tenant quanto um número do mesmo tenant fora do escopo da chave.
- Consultas de mensagens, contatos, eventos e jobs filtram simultaneamente `tenant_id` e `account_id`.
- Ingresso de webhook é roteado pelo Phone Number ID; eventos WABA sem número usam o WABA exclusivo e o primeiro número ativo associado. Eventos desconhecidos não entram no banco.
- O worker somente retira jobs se tenant **e** número estiverem ativos.
- Ao suspender um tenant, acesso de API e envio de seus jobs ficam interrompidos.

## Administração
```bash
php bin/whatsapp_manage.php tenant:status empresa-x suspended
php bin/whatsapp_manage.php tenant:status empresa-x active
php bin/whatsapp_manage.php account:status PHONE_NUMBER_ID suspended
php bin/whatsapp_manage.php account:token:rotate PHONE_NUMBER_ID
php bin/whatsapp_manage.php key:revoke KEY_ID
php bin/whatsapp_manage.php callback:set PHONE_NUMBER_ID https://erp.seudominio.com/webhook
```

O CLI equivale a superadministrador de toda a instalação; não exponha pela web. Permissões para operadores por cliente e painel de administração são funcionalidades futuras.

## Instalação existente e migração
A versão anterior possui tabelas sem `whatsapp_` e contas sem `tenant_id`. O novo esquema **não é uma migração automática**. Faça backup, decida a qual tenant cada conta antiga pertence e use um processo de migração validado e testado em cópia dos dados antes de promover a versão. Não execute scripts destrutivos na base existente.

## Limitações atuais
O isolamento por IDs e FKs é de aplicação/banco compartilhado, não bancos isolados por cliente; o usuário administrativo do banco e o superadministrador CLI têm acesso global. Para clientes regulados, avalie banco ou instância separada, registros de acesso, limite de recursos por tenant, retenção e auditoria. A política global Meta App Secret é para o aplicativo de integração, não um segredo independente de cada cliente.
