<?php
declare(strict_types=1);

// Static check: this test does not connect to MySQL or execute the gateway.
$root = dirname(__DIR__);
$expected = [
    'whatsapp_tenants',
    'whatsapp_users',
    'whatsapp_tenant_meta',
    'whatsapp_media_assets',
    'whatsapp_contacts',
    'whatsapp_conversations',
    'whatsapp_messages',
    'whatsapp_templates',
    'whatsapp_quick_replies',
    'whatsapp_campaigns',
    'whatsapp_campaign_recipients',
    'whatsapp_api_keys',
    'whatsapp_tenant_webhooks',
    'whatsapp_webhook_outbox',
    'whatsapp_webhook_events',
    'whatsapp_idempotency_keys',
    'whatsapp_audit_logs',
    'whatsapp_login_attempts',
];
sort($expected);
$schema = file_get_contents($root . '/database/schema.sql');
if ($schema === false) {
    fwrite(STDERR, "schema.sql não encontrado.\n");
    exit(1);
}
preg_match_all('/\bCREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-z][a-z0-9_]*)/i', $schema, $created);
$created = $created[1];
sort($created);
if ($created !== $expected) {
    fwrite(STDERR, "ERRO: schema.sql não contém exatamente as 18 tabelas whatsapp_ esperadas.\n");
    fwrite(STDERR, "Schema: " . implode(', ', $created) . "\n");
    exit(1);
}
preg_match_all('/\bREFERENCES\s+([a-z][a-z0-9_]*)\s*\(/i', $schema, $foreignKeys);
foreach ($foreignKeys[1] as $target) {
    if (!in_array($target, $expected, true)) {
        fwrite(STDERR, "ERRO: FK aponta para tabela não declarada: $target\n");
        exit(1);
    }
}
$legacy = array_map(
    static fn(string $name): string => $name === 'whatsapp_audit_logs'
        ? 'whatsapp_gateway_audit_logs' : substr($name, strlen('whatsapp_')),
    $expected
);
$legacyPattern = '/\b(?:FROM|JOIN|INTO|UPDATE|REFERENCES)\s+('
    . implode('|', array_map('preg_quote', $legacy))
    . ')\b/i';
$sourceFiles = [
    '/app/bootstrap.php',
    '/app/setup_wizard.php',
    '/worker.php',
    '/database/schema.sql',
    '/database/migrations/20261107_create_whatsapp_audit_logs.sql',
];
$referenced = [];
foreach ($sourceFiles as $file) {
    $source = file_get_contents($root . $file);
    if ($source === false) {
        fwrite(STDERR, "ERRO: arquivo não encontrado: $file\n");
        exit(1);
    }
    if (preg_match($legacyPattern, $source, $match)) {
        fwrite(STDERR, "ERRO: referência SQL antiga '$match[0]' em $file\n");
        exit(1);
    }
    preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|REFERENCES)\s+(whatsapp_[a-z0-9_]+)\b/i', $source, $refs);
    foreach ($refs[1] as $table) {
        if (!in_array($table, $expected, true)) {
            fwrite(STDERR, "ERRO: tabela referenciada ausente do schema: $table ($file)\n");
            exit(1);
        }
        $referenced[$table] = true;
    }
}
foreach ($expected as $table) {
    if (!isset($referenced[$table])) {
        fwrite(STDERR, "ERRO: tabela não referenciada no código: $table\n");
        exit(1);
    }
}
echo "OK: 18 tabelas whatsapp_, FKs e consultas consistentes.\n";
