# Caixa de entrada e mensagens recebidas

## Arquitetura

O WhatsApp Web permanece isolado no servico Node.js. Quando uma mensagem e recebida, o Node.js normaliza o evento, assina o payload e chama o webhook interno do Laravel.

Fluxo:

```text
WhatsApp Web -> Node.js -> webhook assinado -> Laravel -> fila whatsapp-incoming -> conversa
```

O navegador do usuario nunca chama o Node.js diretamente.

## Webhook interno

Endpoint:

```text
POST /internal/whatsapp/incoming
```

Cabecalhos obrigatorios:

```text
X-Webhook-Timestamp
X-Webhook-Nonce
X-Webhook-Signature
```

A assinatura usa HMAC-SHA256 sobre:

```text
timestamp + "." + nonce + "." + corpo_bruto
```

O Laravel valida:

- `Content-Type: application/json`
- tamanho maximo do corpo
- timestamp dentro da tolerancia
- nonce nao reutilizado
- assinatura com comparacao segura

## Payload

Campos principais:

```text
event_id
provider
connection_id
external_message_id
sender_phone
recipient_phone
message_type
text
sent_at
received_at
is_from_me
is_group
has_media
metadata
```

Mensagens de grupo sao ignoradas. Midias sao registradas apenas por metadados nesta etapa.

## Idempotencia

A idempotencia usa:

```text
provider + external_message_id
event_id
```

Eventos duplicados nao criam novas mensagens, conversas ou interrupcoes de fila.

## Conversas

Tabelas:

- `conversations`
- `conversation_messages`
- `conversation_events`
- `conversation_assignments`
- `conversation_tags`
- `conversation_conversation_tag`
- `conversation_notes`

Status:

```text
new
open
waiting_operator
waiting_contact
resolved
closed
archived
blocked
```

Prioridades:

```text
low
normal
high
urgent
```

## Associacao de contato

O telefone recebido e normalizado pelo mesmo servico usado no cadastro de contatos.

Resultados possiveis:

- `matched`
- `not_found`
- `multiple_matches`
- `invalid_phone`

Contato nao encontrado cria conversa sem `contact_id`. O usuario deve associar manualmente ou criar contato em fluxo controlado.

## Interrupcao dos lotes

Ao receber uma resposta de contato identificado, o sistema marca:

```text
has_replied = true
first_replied_at
last_replied_at
```

Destinatarios pendentes do contato sao marcados como:

```text
processing_status = skipped
error_code = CONTACT_REPLIED
```

Mensagens em processamento ou ja enviadas nao sao canceladas silenciosamente.

## Caixa de entrada

Rota:

```text
/admin/inbox
```

Recursos:

- filtros por status, responsavel, nao lidas e busca
- conversa detalhada
- leitura interna
- atribuicao
- status e prioridade
- notas internas
- etiquetas de conversa
- associacao manual de contato
- marcacao de nao contatar
- resposta manual

## Resposta manual

A resposta manual:

- exige usuario autenticado e permissao
- exige contato identificado
- respeita contato bloqueado ou nao contatar
- exige conversa atribuida, salvo configuracao
- gera `request_id`
- entra na fila `whatsapp-manual-replies`
- usa `WhatsAppProvider`

Nao ha resposta automatica, chatbot, IA ou gatilho por palavra.

## Filas

Filas:

```text
whatsapp-incoming
whatsapp-manual-replies
```

Supervisor sugerido:

```ini
[program:gerenciador-whatsapp-incoming]
command=php /var/www/gerenciador-mensagens/artisan queue:work redis --queue=whatsapp-incoming --sleep=3 --tries=3 --timeout=120
directory=/var/www/gerenciador-mensagens
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/gerenciador-whatsapp-incoming.log
stopwaitsecs=180
```

```ini
[program:gerenciador-whatsapp-manual-replies]
command=php /var/www/gerenciador-mensagens/artisan queue:work redis --queue=whatsapp-manual-replies --sleep=3 --tries=1 --timeout=120
directory=/var/www/gerenciador-mensagens
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/gerenciador-whatsapp-manual-replies.log
stopwaitsecs=180
```

## Comandos

```bash
php artisan inbox:process-pending
php artisan inbox:recover-stuck
php artisan inbox:sync-unread-counts
php artisan inbox:rebuild-conversation-status
php artisan inbox:archive-resolved
```

## Scheduler

```text
inbox:recover-stuck      a cada cinco minutos
inbox:sync-unread-counts a cada hora
inbox:archive-resolved   diariamente
```

## Monitoramento

A central de monitoramento inclui:

- fila `whatsapp-incoming`
- fila `whatsapp-manual-replies`
- respostas manuais presas
- filas e jobs falhos

## Privacidade

Nao registrar em logs gerais:

- segredo do webhook
- corpo completo por padrao
- sessao do WhatsApp
- QR Code
- cookies
- midias

Mensagens completas ficam nas tabelas protegidas do modulo.

## Solucao de problemas

- Assinatura invalida: conferir segredo, timestamp, nonce e corpo bruto.
- Replay detectado: confirmar se o Node.js esta reusando nonce.
- Mensagem nao aparece: verificar fila `whatsapp-incoming` e `failed_jobs`.
- Resposta manual presa: executar `php artisan inbox:recover-stuck`.
- Contador incorreto: executar `php artisan inbox:sync-unread-counts`.
