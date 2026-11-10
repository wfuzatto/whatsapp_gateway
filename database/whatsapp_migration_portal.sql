
-- Migration incremental. Execute UMA VEZ sobre uma base que ja possua o esquema multitenant.
-- Portal comercial e autoatendimento. Os valores abaixo SAO SUGESTOES comerciais,
-- nao tarifas oficiais da Meta e nao representam cobranca ja ativa.
CREATE TABLE IF NOT EXISTS whatsapp_plans (
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
CREATE TABLE IF NOT EXISTS whatsapp_users (
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
CREATE TABLE IF NOT EXISTS whatsapp_subscriptions (
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
CREATE TABLE IF NOT EXISTS whatsapp_signup_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action ENUM('register','login') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rate (action,key_hash,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO whatsapp_plans(slug,title,summary,price_cents,max_numbers,features,is_popular,sort_order) VALUES
 ('essencial','Essencial','Comece a integrar seu WhatsApp com sistemas e automações.',7900,1,JSON_ARRAY('1 número conectado','API REST e webhooks','Envio de templates aprovados','Histórico técnico de eventos'),0,10),
 ('profissional','Profissional','Para operações com múltiplos fluxos e integrações.',19900,3,JSON_ARRAY('Até 3 números','Tudo do Essencial','Múltiplas integrações','Filas e rastreamento de mensagens'),1,20),
 ('escala','Escala','Para empresas que precisam centralizar mais números.',39900,10,JSON_ARRAY('Até 10 números','Tudo do Profissional','Centralização de múltiplos WABAs','Suporte comercial sob contratação'),0,30)
ON DUPLICATE KEY UPDATE slug=VALUES(slug);
