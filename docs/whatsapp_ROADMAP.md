# Escopo, compatibilidade e próximos marcos

Este software é um **gateway inicial funcional**, não substitui todos os módulos comerciais da Meta ou uma plataforma BSP completa. A API oficial evolui frequentemente. Só declare um recurso pronto após testar com conta WABA, número e permissões reais.

## Implementado (código)
- Cloud API envio de payloads oficiais de mensagens, síncrono/assíncrono.
- Verificação e ingestão de webhooks, persistência e entrega de callbacks HMAC.
- Mídia upload/metadados/delete; gerenciamento básico de templates, phones, business profile e flows via Graph.
- Logs de eventos, contatos, janela de atendimento e consentimento.
- Multinúmero com tokens criptografados, API keys e worker.
- CI PHP lint, testes de utilidades, proteção de arquivos.

## Pendente / implementar antes de uso empresarial amplo
- Suite E2E com Graph v26, validação de limites e políticas por tipo de mensagem e mídia.
- Painel web administrativo com login, RBAC, caixa de entrada multiagente, respostas rápidas, métricas, filtros e campanhas com revisão/consentimento.
- Flow Data Channel: endpoint criptografado, troca de chaves, tratamento de dados e assinatura, testes de fluxos.
- Embedded Signup / Tech Provider: OAuth, troca de code, Business Login, sessão, token, WABA subscription e onboarding automatizado, conforme política e aprovação da Meta.
- Catálogos e produtos: integração Catalog API, Commerce Manager, product sets, pedidos.
- Templates avançados: revisão, categoria, qualidade, autenticação OTP, componentes, localizações, paginação Graph completa e status.
- Cobrança/conversas: insights, analytics, preço por categoria/mercado e relatório financeiro reconciliado.
- Mensagens: validação detalhada por tipo, WhatsApp Payments (quando elegível), localização, reações, stickers, templates interativos, Flows/Carousels e mudanças no schema.
- Rate limit distribuído, backpressure, filas escaláveis, métricas Prometheus/OpenTelemetry, dashboards de observabilidade.
- API pública com OAuth2 ou API keys com escopos e rotação, permissões de times, bloqueio de abuso.
- Retenção configurável e exclusão LGPD de contatos, mensagens e eventos, políticas de backup e DPA.
- Recuperação de status fora de ordem e gestão de webhooks de campos especiais sem phone_number_id.
- Segurança de callbacks: DNS egress allowlisting, mitigação de DNS rebinding, circuit breakers e política de repetição configurável.
- Documentação OpenAPI gerada, coleção Postman, testes de carga, segurança e compatibilidade Windows/Linux.

**Sem WhatsApp Web, QR, scraping, Selenium, atalhos para disparos não autorizados ou bypass de políticas.** O tráfego depende exclusivamente da Cloud API aprovada pela Meta.

Fontes:
- https://www.postman.com/meta/whatsapp-business-platform/overview
- https://developers.facebook.com/documentation/business-messaging/whatsapp
- https://developers.facebook.com/docs/graph-api/changelog


## Atualizacao multi-tenant
Implementados: `whatsapp_tenants`, `whatsapp_wabas`, isolamento de numeros, chaves de API por tenant/numero, FKs `(tenant_id,account_id)`, suspensao de cliente e testes integrados de isolamento MySQL. Pendentes: painel com administradores de tenant, cotas/limites individuais, cobranca por tenant, migraçao assistida do banco anterior e testes reais com diversos WABAs.
