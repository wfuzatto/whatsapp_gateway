-- WhatsApp Gateway 2 / esquema multi-tenant: MySQL 8.0+.
-- Execute em banco NOVO e vazio; nenhum DROP ou alteracao destrutiva.
-- Todas as tabelas possuem o prefixo whatsapp_.
CREATE TABLE whatsapp_tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_wabas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  waba_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_waba (tenant_id,waba_id),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  waba_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  phone_number_id VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  token_box TEXT NOT NULL,
  callback_url VARCHAR(2048) DEFAULT NULL,
  callback_secret_box TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_account (tenant_id,id),
  INDEX idx_waba (tenant_id,waba_id),
  FOREIGN KEY (tenant_id,waba_id) REFERENCES whatsapp_wabas(tenant_id,waba_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_api_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED DEFAULT NULL,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_api_tenant (tenant_id,active),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_contacts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  wa_id VARCHAR(32) NOT NULL,
  display_name VARCHAR(255) DEFAULT NULL,
  opt_in_at DATETIME DEFAULT NULL,
  opt_in_source VARCHAR(255) DEFAULT NULL,
  last_inbound_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_contact (account_id,wa_id),
  INDEX idx_contact_tenant (tenant_id,account_id),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  wa_id VARCHAR(32) NOT NULL,
  direction ENUM('inbound','outbound') NOT NULL,
  whatsapp_id VARCHAR(255) DEFAULT NULL,
  message_type VARCHAR(40) NOT NULL,
  status VARCHAR(30) NOT NULL,
  payload JSON NOT NULL,
  meta_response JSON DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wa_message (account_id,whatsapp_id),
  INDEX idx_conversation (tenant_id,account_id,wa_id,created_at),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_webhook_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  event_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  event_type VARCHAR(80) NOT NULL,
  payload JSON NOT NULL,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_event_lookup (tenant_id,account_id,received_at),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('send','callback') NOT NULL,
  idempotency_key VARCHAR(180) NOT NULL,
  payload JSON NOT NULL,
  status ENUM('queued','processing','retry','done','failed','uncertain') NOT NULL DEFAULT 'queued',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_error VARCHAR(1000) DEFAULT NULL,
  response JSON DEFAULT NULL,
  locked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_job (account_id,kind,idempotency_key),
  INDEX idx_claim (status,next_attempt_at),
  INDEX idx_jobs_tenant (tenant_id,account_id,created_at),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  details JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit (tenant_id,account_id,created_at),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Portal comercial e autoatendimento. Os valores abaixo SAO SUGESTOES comerciais,
-- nao tarifas oficiais da Meta e nao representam cobranca ja ativa.
CREATE TABLE whatsapp_plans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  title VARCHAR(80) NOT NULL,
  summary VARCHAR(255) NOT NULL,
  price_cents INT UNSIGNED NOT NULL,
  max_numbers SMALLINT UNSIGNED NOT NULL,
  features JSON NOT NULL,
  is_popular TINYINT(1) NOT NULL DEFAULT 0,
  is_public TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner') NOT NULL DEFAULT 'owner',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL DEFAULT NULL,
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
  plan_id BIGINT UNSIGNED NOT NULL,
  price_cents_snapshot INT UNSIGNED NOT NULL,
  status ENUM('pending_activation','active','suspended','cancelled') NOT NULL DEFAULT 'pending_activation',
  started_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
  FOREIGN KEY (plan_id) REFERENCES whatsapp_plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_signup_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action ENUM('register','login') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate (action,key_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE whatsapp_admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('superadmin') NOT NULL DEFAULT 'superadmin',
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO whatsapp_plans(slug,title,summary,price_cents,max_numbers,features,is_popular,sort_order) VALUES
 ('essencial','Essencial','Comece a integrar seu WhatsApp com sistemas e automações.',7900,1,JSON_ARRAY('1 número conectado','API REST e webhooks','Envio de templates aprovados','Histórico técnico de eventos'),0,10),
 ('profissional','Profissional','Para operações com múltiplos fluxos e integrações.',19900,3,JSON_ARRAY('Até 3 números','Tudo do Essencial','Múltiplas integrações','Filas e rastreamento de mensagens'),1,20),
 ('escala','Escala','Para empresas que precisam centralizar mais números.',39900,10,JSON_ARRAY('Até 10 números','Tudo do Profissional','Centralização de múltiplos WABAs','Suporte comercial sob contratação'),0,30)
ON DUPLICATE KEY UPDATE slug=VALUES(slug);
