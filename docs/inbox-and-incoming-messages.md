# Conversas, caixa de entrada e mensagens recebidas

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

## CONVERSAS

Rota:

```text
/admin/inbox
/admin/conversations
```

Recursos:

- filtros por status, responsavel, nao lidas e busca
- interface em lista de conversas, linha do tempo e detalhes
- conversa detalhada
- leitura interna
- atribuicao
- status e prioridade
- notas internas
- etiquetas de conversa
- associacao manual de contato
- marcacao de nao contatar
- resposta manual
- sincronizacao controlada dos chats disponiveis na sessao atual do WhatsApp Web

As rotas antigas `/admin/inbox` continuam validas por compatibilidade. A nomenclatura visivel no menu administrativo e `CONVERSAS`.

## Sincronizacao do WhatsApp Web

A sincronizacao usa a sessao atual do WhatsApp Web por meio do servico Node.js privado:

```text
Laravel -> WhatsAppProvider -> Node.js /api/conversations -> whatsapp-web.js getChats()
Laravel -> WhatsAppProvider -> Node.js /api/conversations/{chatId}/messages -> chat.fetchMessages()
```

Limites padrao:

```text
conversations.sync_enabled = true
conversations.sync_max_chats = 100
conversations.sync_messages_per_chat = 50
conversations.sync_days_back = 30
conversations.sync_include_archived = false
conversations.sync_interval_minutes = 15
conversations.polling_interval_seconds = 10
```

Limites absolutos de backend:

```text
500 chats por execucao
500 mensagens por chat
365 dias retroativos
```

A sincronizacao:

- importa apenas conversas individuais
- ignora grupos, status, canais, comunidades e listas
- importa mensagens recebidas e enviadas por outros dispositivos
- nao baixa midias
- nao promete recuperar todo o historico, apenas o que a sessao atual disponibilizar
- usa idempotencia por `provider + external_message_id`
- registra execucoes em `conversation_sync_runs`
- usa a fila `whatsapp-conversation-sync`

## Atualizacao em tempo real da conversa

A tela `/admin/conversations/{conversation}` busca mensagens novas sem recarregar a pagina:

```text
GET /admin/inbox/{conversation}/messages?after_id={ultimo_id_conhecido}
```

- Consulta automatica a cada 30 segundos, pausada quando a aba do navegador nao esta visivel (`document.hidden`).
- Botao "Atualizar mensagens" para forcar a consulta a qualquer momento, sempre com retorno visivel (quantidade de mensagens novas, "nenhuma mensagem nova" ou erro).
- Ao encontrar mensagens novas, marca as recebidas nao lidas como lidas e zera `unread_count`, igual ao comportamento de abrir a conversa.
- Mensagens sao exibidas com as mais recentes primeiro.

## Emoji

O componente `<x-emoji-picker target="id_do_campo">` (`resources/views/components/emoji-picker.blade.php`) insere emojis na posicao do cursor do campo de texto indicado. Usado na resposta manual, no editor de modelos de mensagem e na mensagem avulsa de campanhas/lotes.

Toda a cadeia ja suporta emoji (colunas `utf8mb4`, validacao multibyte). Dois pontos de atencao tratados:

- O cliente HTTP Laravel -> servico Node codifica o corpo com `JSON_UNESCAPED_UNICODE` em `WhatsAppServiceClient::send()`, evitando que emojis inflem ~3x de tamanho ao serem escapados como `\uXXXX`.
- O servico Node aceita corpos de requisicao ate 256kb (`express.json({ limit: '256kb' })`), suficiente mesmo para mensagens de 4096 caracteres compostas majoritariamente por emoji.

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
whatsapp-conversation-sync
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

```ini
[program:gerenciador-whatsapp-conversation-sync]
command=php /var/www/gerenciador-mensagens/artisan queue:work redis --queue=whatsapp-conversation-sync --sleep=3 --tries=3 --timeout=300
directory=/var/www/gerenciador-mensagens
autostart=true
autorestart=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/supervisor/gerenciador-whatsapp-conversation-sync.log
stopwaitsecs=360
```

## Comandos

```bash
php artisan inbox:process-pending
php artisan inbox:recover-stuck
php artisan inbox:sync-unread-counts
php artisan inbox:rebuild-conversation-status
php artisan inbox:archive-resolved
php artisan conversations:sync
php artisan conversations:sync --queue
php artisan conversations:sync --chat="5549999999999@c.us"
php artisan conversations:sync --days=7 --limit-chats=50 --messages-per-chat=100
php artisan conversations:rebuild-unread
php artisan conversations:recover-sync
```

## Scheduler

```text
inbox:recover-stuck      a cada cinco minutos
inbox:sync-unread-counts a cada hora
inbox:archive-resolved   diariamente
conversations:sync --queue a cada quinze minutos
conversations:recover-sync a cada cinco minutos
```

## Monitoramento

A central de monitoramento inclui:

- fila `whatsapp-incoming`
- fila `whatsapp-manual-replies`
- fila `whatsapp-conversation-sync`
- respostas manuais presas
- ultima sincronizacao de conversas
- sincronizacao presa
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
- Conversas nao sincronizam: verificar conexao WhatsApp, fila `whatsapp-conversation-sync` e ultimo registro em `conversation_sync_runs`.
- Mensagens de contatos diferentes aparecendo na mesma conversa sem contato: `ConversationResolverService::resolve()` escopa conversas sem `contact_id` pelo telefone do remetente (`sender_phone_snapshot`/`recipient_phone_snapshot`); sem telefone conhecido, so reaproveita conversas sem nenhuma mensagem ainda.
- Telefone exibido na lista de conversas nao bate com a conversa: `Conversation::whatsappPhoneDigits()` so deve olhar mensagens da propria conversa; um `orWhereNotNull` fora do escopo correto ja causou vazamento do telefone de outra conversa no passado (corrigido).
