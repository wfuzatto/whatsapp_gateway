# Servidor MCP ZapApi

Guia público online: `https://zapapi.inf.br/mcp-docs`.

O endpoint MCP permite que um cliente compatível leia conversas, consulte mensagens/eventos e enfileire mensagens na mesma operação da central web. É stateless sobre HTTP; não abre conexão de eventos em tempo real.

## Conectar

1. No painel **Integração Meta → Chaves de API**, consulte o token inicial automático ou crie um token adicional para cada sistema consumidor.
2. Marque `mcp:connect` e os escopos das ferramentas que esse sistema realmente precisa. O token fica limitado a um único número e expira conforme a validade selecionada.
3. Configure o cliente MCP para chamar `https://zapapi.inf.br/mcp` com `Authorization: Bearer wg_TOKEN`.

Exemplo genérico de configuração (a sintaxe exata varia por cliente MCP):

```json
{
  "mcpServers": {
    "zapapi": {
      "url": "https://zapapi.inf.br/mcp",
      "headers": {
        "Authorization": "Bearer wg_TOKEN"
      }
    }
  }
}
```

Clientes machine-to-machine podem omitir `Origin`. Clientes executados em navegador devem enviar uma origem HTTPS permitida em `MCP_ALLOWED_ORIGINS`, por exemplo `https://zapapi.inf.br` ou uma origem explicitamente administrada. Não use curinga. CORS só permite os métodos e cabeçalhos usados pelo MCP. O servidor aceita Streamable HTTP stateless e clientes MCP `2025-11-25`; chamadas da revisão nova precisam enviar os cabeçalhos de roteamento exigidos pelo protocolo.

## Ferramentas

O servidor só anuncia ferramentas habilitadas pelos escopos do token:

| Ferramenta | Escopo | Função |
|---|---|---|
| `accounts_list` | `accounts:read` | Lista o número limitado ao token |
| `conversations_list` | `conversations:read` | Lista conversas, última mensagem e não lidas |
| `messages_list` | `messages:read` | Consulta o histórico de uma conversa |
| `messages_send` | `messages:send` | Enfileira texto, mídia ou template aprovado |
| `templates_list` | `templates:read` | Consulta templates e status na Meta |
| `events_list` | `events:read` | Busca eventos incrementais com `after_id` |
| `jobs_get` | `jobs:read` | Consulta resultado/status de envio |
| `conversation_mark_read` | `conversations:mark_read` | Confirma leitura à Meta e sincroniza o cursor dos Androids |

Todas as operações usam tenant e número derivados do token. O argumento opcional `phone_number_id` não consegue ampliar o acesso: um número diferente é rejeitado.

### Enviar

`messages_send` recebe `idempotency_key` e `message` separados. `message` usa o formato de envio da Cloud API, por exemplo:

```json
{
  "idempotency_key": "pedido-123-mensagem-1",
  "message": {
    "to": "5511999999999",
    "type": "template",
    "template": {
      "name": "hello_world",
      "language": {"code": "en_US"}
    }
  }
}
```

A ferramenta chama a fila de envio usada pela central web e devolve `job_id`. Use uma chave estável de 8 a 180 caracteres por intenção, inclusive em retries de rede. Um retorno `queued` significa somente que o job foi aceito pela fila. O job pode ser consultado com `jobs_get`; os webhooks de status aparecem depois em `events_list`.

Templates precisam estar aprovados para o idioma e WABA correspondentes. Fora da janela de atendimento só se pode enviar template aprovado; as verificações de consentimento e janela existentes continuam valendo.
Para enviar um arquivo, primeiro use `POST /v1/media` com o escopo `media:upload` e passe o ID de mídia retornado no objeto da mensagem.

### Receber

Chame `events_list` com `after_id` e persista o `next_after_id` retornado antes de continuar o polling. Os eventos incluem o payload verificado da Meta, com mensagens de entrada em `entry[].changes[].value.messages` e estados de envio em `statuses`. Também é possível buscar mensagens normalizadas com `messages_list`.

O MCP/API não chama o sistema externo quando chega um evento. Para atualizações sem polling, use um callback HTTPS configurado na conta; valide `X-Gateway-Signature-256` e deduplique `X-Gateway-Event-ID`. Não marque leitura ao apenas listar histórico: invoque `conversation_mark_read` somente depois de uma pessoa ou automação considerar a mensagem visualizada.

## Controles de segurança

- Bearer separado dos tokens Meta, com entropia aleatória de 256 bits; o hash SHA-256 é persistido; o token inicial também possui uma cópia cifrada para consulta autenticada.
- Tokens adicionais criados no painel têm CSRF, escopo explícito, número único, expiração e exibição única. O token inicial automático tem escopos padrão e é restrito ao tenant.
- `mcp:connect` é obrigatório e não é concedido a chaves legadas. Ferramentas também exigem o próprio escopo.
- Validação de `Origin` não confia no `Host` enviado pelo cliente. Configure as origens HTTPS confiáveis em `MCP_ALLOWED_ORIGINS`; clientes sem Origin são tratados como chamadas de máquina.
- TLS obrigatório pelo front controller, limites de corpo e rate limit por credencial: 120 chamadas/minuto, incluindo no máximo 30 escritas/minuto.
- A API nunca retorna o token Meta. Leituras e jobs são sempre filtrados pelo tenant e pela conta vinculada.
- Revogue tokens antigos no painel. Tokens legados não expiram e conservam acesso REST amplo para compatibilidade até serem revogados.

O MCP usa token estático Bearer e ainda não implementa o fluxo OAuth 2.1. Guarde o token em cofre de segredos do sistema consumidor e não em configuração pública ou versionada.
