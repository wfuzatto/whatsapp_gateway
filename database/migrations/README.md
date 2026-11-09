# Correção da tabela de auditoria

O banco online já contém uma tabela `audit_logs` de outro sistema, com colunas de pneus e veículos. O Ponte agora usa a tabela exclusiva `whatsapp_gateway_audit_logs`; a tabela antiga permanece intacta.

## Publicação

1. Faça um backup do banco pelo phpMyAdmin.
2. Selecione o banco que o Ponte já usa — a captura mostrou `prodatasteleco` — e importe `20261107_create_whatsapp_gateway_audit_logs.sql`.
3. Envie o `app/bootstrap.php` atualizado para a hospedagem.
4. Recarregue o login e entre novamente. A tela de auditoria usará a nova tabela.

A migração cria somente uma tabela nova, com nome exclusivo. Ela não altera nem remove `audit_logs` ou qualquer outra tabela. Não execute `ALTER`, `UPDATE` ou `DROP` na tabela antiga. A tentativa anterior de `UPDATE` falhou por falta de `user_id` e não associou registros.

O esquema atualizado em `database/schema.sql` já cria a tabela exclusiva em novas instalações.
