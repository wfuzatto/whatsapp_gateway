-- Migracao aditiva da central web. Segura para banco ja instalado.
-- Nao altera tabelas existentes nem apaga dados.
CREATE TABLE IF NOT EXISTS whatsapp_conversations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  wa_id VARCHAR(32) NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_at DATETIME DEFAULT NULL,
  last_read_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversation (account_id,wa_id),
  UNIQUE KEY uq_tenant_conversation (tenant_id,id),
  INDEX idx_conversation_tenant_status (tenant_id,status,updated_at),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_quick_replies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  body VARCHAR(4096) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_quick_reply (tenant_id,name),
  INDEX idx_quick_reply_tenant (tenant_id,id),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_campaigns (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  template_name VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  template_language VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  template_category VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  template_body TEXT NOT NULL,
  scheduled_at DATETIME DEFAULT NULL,
  status ENUM('draft','scheduled','queued','sending','completed','needs_review','cancelled') NOT NULL DEFAULT 'draft',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME DEFAULT NULL,
  completed_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_tenant_campaign (tenant_id,id),
  INDEX idx_campaign_due (status,scheduled_at,id),
  INDEX idx_campaign_tenant (tenant_id,account_id,created_at),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS whatsapp_campaign_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  campaign_id BIGINT UNSIGNED NOT NULL,
  wa_id VARCHAR(32) NOT NULL,
  status ENUM('pending','queued','accepted','failed','uncertain','excluded') NOT NULL DEFAULT 'pending',
  job_id BIGINT UNSIGNED DEFAULT NULL,
  error VARCHAR(1000) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_recipient (campaign_id,wa_id),
  INDEX idx_campaign_recipient_tenant (tenant_id,campaign_id,status),
  INDEX idx_campaign_recipient_job (job_id),
  FOREIGN KEY (tenant_id,account_id) REFERENCES whatsapp_accounts(tenant_id,id),
  FOREIGN KEY (tenant_id,campaign_id) REFERENCES whatsapp_campaigns(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
