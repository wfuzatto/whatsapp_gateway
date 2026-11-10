# Migrações e padrão de tabelas do Ponte Gateway

A partir desta versão, **todas as 18 tabelas do Ponte começam por `whatsapp_`**.
Exemplos: `whatsapp_tenants`, `whatsapp_users`, `whatsapp_contacts`,
`whatsapp_messages`, `whatsapp_campaigns` e `whatsapp_audit_logs`.

O nome do **banco** pode continuar sendo `whatsapp_gateway`. O prefixo é apenas
para os nomes das **tabelas**.

## Instalação nova (XAMPP ou Locaweb)

Crie um banco de dados vazio; abra o walkthrough de instalação em
`http://localhost/whatsapp_api/`. O instalador aplica
`database/schema.sql` e cria todas as tabelas com prefixo.
**Não execute manualmente nenhuma migração em uma instalação nova.**

## Instalação antiga em um banco DEDICADO exclusivamente ao Ponte

Se você precisa **preservar os dados antigos**, faça backup completo, confirme
que as 18 tabelas legadas pertencem exclusivamente ao Ponte e confira os nomes.
Depois de colocar todos os arquivos PHP novos em produção, execute
`20261109_prefix_whatsapp_tables.sql` no banco do Ponte (em janela de manutenção).
Esse script renomeia as tabelas antigas, **não apaga os registros**, e deve ser
executado uma única vez. Ele exige que todas as tabelas antigas existam e os
nomes novos ainda estejam livres. Se a instalação antiga estiver incompleta,
avalie-a antes de executar a migração.

**Não execute o script em um banco compartilhado**: tabelas genéricas como
`users`, `contacts`, `messages` e `templates` podem pertencer a outros
sistemas. A renomeação afetaria aplicações que não são o Ponte. Nesse cenário,
faça uma análise e migração específica, com identificação da titularidade de
cada tabela, em vez de executar o script diretamente.

## Histórico da tabela de auditoria

`20261107_create_whatsapp_audit_logs.sql` cria somente
`whatsapp_audit_logs`, com chave estrangeira para `whatsapp_tenants`,
quando **a tabela de auditoria é a única ausente** de uma instalação que já
utiliza os nomes com prefixo. Não modifica qualquer tabela `audit_logs`
preexistente de outro sistema.

Nunca remova ou renomeie tabelas de outros sistemas para resolver conflitos
de nomes. Se você excluiu o banco anterior e quer recomeçar, siga o
[guia de instalação guiada](../../docs/GUIA_INSTALACAO_GUIADA.md).
