# Segurança, malware e operação

- Nenhum executável ou dependência de terceiros empacotada. Somente código-fonte PHP, SQL, Markdown e workflow YAML. O CI faz lint e recusa arquivos de executáveis e segredos comuns.
- O alerta Trojan no projeto antigo deve ser tratado em separado. Não há comprovação por esta reconstrução de que o alerta antigo foi verdadeiro ou falso.
- **GitHub:** trocar a árvore de arquivos da branch não elimina commits antigos, releases, forks, caches ou clones. Para eliminação efetiva, recrie repositório limpo e revise caches/forks/repositories; rode varredura de segredos e revogue tokens antigos.
- Rotacione imediatamente tokens de sistema Meta, app secret, verify token, API keys, credenciais DB e segredos de callback anteriores. Não compartilhe os valores.
- Mantenha `.env` fora do document root preferencialmente, ative TLS/HTTPS, HSTS no proxy/Apache, banco com usuário de privilégio mínimo, backups criptografados e diretórios de código não públicos.
- Não use o mesmo banco de dados antigo. Crie esquema limpo em base vazia. Audite backups em outro ambiente antes de abrir.
- Webhooks são autenticados com HMAC SHA-256 e gravados em transação **antes** do HTTP 200. Assinatura inválida retorna 403.
- As chaves REST são aleatórias, só são exibidas uma vez e ficam armazenadas com SHA-256 no banco. Segredos Meta e callback são criptografados em repouso usando libsodium. Proteja `MASTER_KEY_BASE64` em sistema de segredos fora da aplicação.
- As chamadas Graph usam TLS verificado, host fixo e caminhos compostos em código. Nunca aceite URL Meta arbitrária da requisição.
- Limite tráfego com rate-limit por IP/API key no proxy (Nginx, Apache, WAF). O PHP não implementa rate limiting distribuído.
- Backups, controle de acesso, monitoramento, retenção e exclusão LGPD são responsabilidades de produção. Revogue opt-in quando houver desistência; não grave conteúdo sensível desnecessário.
- **Idempotência:** para mensagens, uma falha na rede após a Meta receber o pedido pode criar estado `uncertain`. Nenhum reenvio automático; verifique a entrega antes do retry manual para evitar duplicatas.
- Um callback só pode ser configurado pelo CLI administrativo. Use domínios HTTPS sob seu controle; não configure hosts internos nem endpoints de terceiros.
- Faça SAST, análise de dependências do PHP hospedado e testes E2E com a Meta antes de produção.


## Isolamento de clientes
Tabelas com prefixo `whatsapp_` e coluna `tenant_id` em todas as entidades operacionais. As chaves de API foram vinculadas ao tenant e, opcionalmente, a um numero. Consultas dos endpoints filtram tenant e conta. O CLI administrativo tem acesso global e exige protecao de servidor.

**Aviso de upgrade**: a estrutura antiga nao esta pronta para migrar dados automaticamente para o esquema novo. Nunca execute o esquema novo sobre a base anterior sem backup e plano de atribuicao de contas a tenants. Um banco novo e vazio e o caminho seguro para validacao.
