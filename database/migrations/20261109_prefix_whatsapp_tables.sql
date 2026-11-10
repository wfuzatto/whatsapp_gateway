-- Ponte Gateway: migração MANUAL de instalação antiga para prefixo whatsapp_
-- NÃO execute em bancos compartilhados com outros sistemas: tabelas como users,
-- contacts, messages etc. podem pertencer a outras aplicações.
-- Faça backup integral antes de qualquer alteração. Não execute na instalação nova,
-- pois o schema.sql já cria todas as 18 tabelas com prefixo.
--
-- Pré-verificação no banco dedicado do Ponte:
-- SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
--   AND table_name IN ('tenants','users','tenant_meta','media_assets','contacts',
--   'conversations','messages','templates','quick_replies','campaigns',
--   'campaign_recipients','api_keys','tenant_webhooks','webhook_outbox',
--   'webhook_events','idempotency_keys','whatsapp_gateway_audit_logs','login_attempts');
-- É necessário confirmar que TODAS as 18 tabelas acima pertencem ao Ponte
-- e que nenhum dos nomes de destino já existe.
--
-- RENAME TABLE é DDL: não pode ser desfeito com ROLLBACK. A ordem de referências
-- com FKs é mantida pelo MySQL/MariaDB durante a renomeação de tabelas InnoDB.
RENAME TABLE
  `tenants` TO `whatsapp_tenants`,
  `users` TO `whatsapp_users`,
  `tenant_meta` TO `whatsapp_tenant_meta`,
  `media_assets` TO `whatsapp_media_assets`,
  `contacts` TO `whatsapp_contacts`,
  `conversations` TO `whatsapp_conversations`,
  `messages` TO `whatsapp_messages`,
  `templates` TO `whatsapp_templates`,
  `quick_replies` TO `whatsapp_quick_replies`,
  `campaigns` TO `whatsapp_campaigns`,
  `campaign_recipients` TO `whatsapp_campaign_recipients`,
  `api_keys` TO `whatsapp_api_keys`,
  `tenant_webhooks` TO `whatsapp_tenant_webhooks`,
  `webhook_outbox` TO `whatsapp_webhook_outbox`,
  `webhook_events` TO `whatsapp_webhook_events`,
  `idempotency_keys` TO `whatsapp_idempotency_keys`,
  `whatsapp_gateway_audit_logs` TO `whatsapp_audit_logs`,
  `login_attempts` TO `whatsapp_login_attempts`;
