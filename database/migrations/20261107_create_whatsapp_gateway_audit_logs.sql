-- Creates a Ponte-owned audit table without changing any existing audit_logs table.
CREATE TABLE IF NOT EXISTS whatsapp_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    details JSON NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    KEY ix_wg_audit_tenant (tenant_id, id),
    CONSTRAINT fk_whatsapp_gateway_audit_tenant
        FOREIGN KEY (tenant_id) REFERENCES whatsapp_tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
