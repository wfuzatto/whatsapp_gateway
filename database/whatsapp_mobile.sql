-- Complemento aditivo do ZapApi Messenger V1. Todas as tabelas prefixadas whatsapp_.
-- Instalado pelo endpoint mobile antes do primeiro login.
CREATE TABLE IF NOT EXISTS whatsapp_mobile_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_mobile_session_user (tenant_id,user_id,expires_at),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
  FOREIGN KEY (user_id) REFERENCES whatsapp_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS whatsapp_mobile_devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
  token_box TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_mobile_devices_tenant (tenant_id,user_id),
  FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id),
  FOREIGN KEY (user_id) REFERENCES whatsapp_users(id),
  FOREIGN KEY (session_id) REFERENCES whatsapp_mobile_sessions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
