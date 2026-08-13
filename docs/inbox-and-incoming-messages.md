# Conversas, caixa de entrada e mensagens recebidas

## Arquitetura

O WhatsApp Web permanece isolado no serviço Node.js. Quando uma mensagem e recebida, o Node.js normaliza o evento, assina o payload e chama o webhook interno do Laravel.

Fluxo:

```text
WhatsApp Web -> Node.js -> webhook assinado -> Laravel -> fila whatsapp-incoming -> conversa
```

O navegador do usuário nunca chama o Node.js diretamente.

## Webhook interno

Endpoint:

```text
POST /internal/whatsapp/incoming
```

Cabeçalhos obrigatórios:

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
- tamanho máximo do corpo
- timestamp dentro da tolerância
- nonce não reutilizado
- assinatura com comparação segura

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

Mensagens de grupo são ignoradas.

Mídia entra pelo webhook apenas como metadado. O arquivo é buscado depois, quando alguém precisa dele — o operador que abre a conversa ou a visão que vai descrever a imagem. Ver `docs/midia-recebida.md`.

## Idempotência

A idempotência usa:

```text
provider + external_message_id
event_id
```

Eventos duplicados não criam novas mensagens, conversas ou interrupções de fila.

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

## Avisos de protocolo não são mensagem

`e2e_notification`, `notification_template`, `revoked`, `call_log`, `gp2`,
`protocol` e `ciphertext` são gerados pelo próprio WhatsApp — o "suas mensagens
são protegidas com criptografia de ponta a ponta", emitido quando a chave do
contato muda. Ninguém escreveu nada.

Entravam como mensagem recebida de corpo vazio, com efeito duplo: a automação
lia aquilo como resposta, não entendia e encaminhava a conversa para atendimento
humano; e conversas nasciam só disso, sem contato identificado, indo parar na
fila de "Aguardando operador". Quem abria encontrava uma tela vazia esperando
resposta para um texto que não existia.

A lista é `ConversationSyncService::PROTOCOL_TYPES`, conferida nos **dois**
caminhos de entrada — webhook e sincronização. Fechada em 03/08/2026.

Dezesseis avisos entraram antes disso, em catorze conversas, e quatro conversas
existiam só por causa deles (412, 1350, 1354, 1357 — todas `@lid`, todas sem
contato). Para limpar resíduo:

```bash
php artisan conversations:prune-protocol-notices              # simula
php artisan conversations:prune-protocol-notices --aplicar
```

Conversa que fica sem nenhuma mensagem é removida por soft delete; conversa real
perde só a linha vazia. O comando recalcula `last_message_at` e as datas de
última entrada e saída: três conversas apontavam justamente para o aviso
apagado, e sem isso ficariam ordenadas por um registro inexistente.

Teste: `AvisoDeProtocoloNaoEMensagemTest`.

## Associação de contato

O telefone recebido e normalizado pelo mesmo serviço usado no cadastro de contatos.

Resultados possíveis:

- `matched`
- `not_found`
- `multiple_matches`
- `invalid_phone`

Contato não encontrado cria conversa sem `contact_id`. O usuário deve associar manualmente ou criar contato em fluxo controlado.

O atendimento de entrada (`docs/inbound-attendance.md`) cria o contato sozinho no momento em que abre a conversa automática — e só nesse momento, para a base não crescer com todo número que mandou um "oi" e nunca mais voltou.

## Interrupção dos lotes

Ao receber uma resposta de contato identificado, o sistema marca:

```text
has_replied = true
first_replied_at
last_replied_at
```

Destinatários pendentes do contato são marcados como:

```text
processing_status = skipped
error_code = CONTACT_REPLIED
```

Mensagens em processamento ou já enviadas não são canceladas silenciosamente.

## CONVERSAS

Rota:

```text
/admin/inbox
/admin/conversations
```

Recursos:

- filtros por status, responsável, não lidas e busca
- interface em lista de conversas, linha do tempo e detalhes
- conversa detalhada
- leitura interna
- atribuição
- status e prioridade
- notas internas
- etiquetas de conversa
- associação manual de contato
- marcação de não contatar
- resposta manual
- sincronização controlada dos chats disponíveis na sessão atual do WhatsApp Web

As rotas antigas `/admin/inbox` continuam validas por compatibilidade. A nomenclatura visível no menu administrativo e `CONVERSAS`.

## Sincronização do WhatsApp Web

A sincronização usa a sessão atual do WhatsApp Web por meio do serviço Node.js privado:

```text
Laravel -> WhatsAppProvider -> Node.js /api/conversations -> whatsapp-web.js getChats()
Laravel -> WhatsAppProvider -> Node.js /api/conversations/{chatId}/messages -> chat.fetchMessages()
```

Limites padrão:

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

A sincronização:

- importa apenas conversas individuais
- ignora grupos, status, canais, comunidades e listas
- importa mensagens recebidas e enviadas por outros dispositivos
- não baixa midias
- não promete recuperar todo o histórico, apenas o que a sessão atual disponibilizar
- usa idempotência por `provider + external_message_id`
- registra execuções em `conversation_sync_runs`
- usa a fila `whatsapp-conversation-sync`

## Atualização em tempo real da conversa

A tela `/admin/conversations/{conversation}` busca mensagens novas sem recarregar a página:

```text
GET /admin/inbox/{conversation}/messages?after_id={ultimo_id_conhecido}
```

- Consulta automática a cada 30 segundos, pausada quando a aba do navegador não esta visível (`document.hidden`).
- Botão "Atualizar mensagens" para forçar a consulta a qualquer momento, sempre com retorno visível (quantidade de mensagens novas, "nenhuma mensagem nova" ou erro).
- Ao encontrar mensagens novas, marca as recebidas não lidas como lidas e zera `unread_count`, igual ao comportamento de abrir a conversa.
- Mensagens são exibidas com as mais recentes primeiro.

## Emoji

O componente `<x-emoji-picker target="id_do_campo">` (`resources/views/components/emoji-picker.blade.php`) insere emojis na posição do cursor do campo de texto indicado. Usado na resposta manual, no editor de modelos de mensagem e na mensagem avulsa de campanhas/lotes.

Toda a cadeia já suporta emoji (colunas `utf8mb4`, validação multibyte). Dois pontos de atenção tratados:

- O cliente HTTP Laravel -> serviço Node codifica o corpo com `JSON_UNESCAPED_UNICODE` em `WhatsAppServiceClient::send()`, evitando que emojis inflem ~3x de tamanho ao serem escapados como `\uXXXX`.
- O serviço Node aceita corpos de requisição até 256kb (`express.json({ limit: '256kb' })`), suficiente mesmo para mensagens de 4096 caracteres compostas majoritariamente por emoji.

## Responsável padrão

`conversations.default_assignee_id` define quem recebe toda conversa nova. Vazio
mantem o comportamento histórico: conversa nasce sem responsável.

O observador de `Conversation` aplica a atribuição na criação e grava o registro
em `conversation_assignments` sem `assigned_by`, porque não houve pessoa
decidindo. Usuário inativo ou removido não recebe: atribuir conversa a quem não
entra no sistema esconde a conversa de todo mundo.

Para as conversas que já existem:

```bash
php artisan conversations:assign-default
php artisan conversations:assign-default --user=2
php artisan conversations:assign-default --force
```

Sem `--force` o comando so mexe em conversa sem responsável.

**Atenção ao ligar isso junto com autoenvio.** `SuggestionSendGuard` recusa envio
automático em conversa atribuída, salvo com `ai.response.auto_send_when_assigned`
ligado. Definir responsável padrão sem ligar essa chave desliga o autoenvio de
respostas geradas em toda a base, sem que nada avise.

## Resposta manual

A resposta manual:

- exige usuário autenticado e permissão
- exige contato identificado
- respeita contato bloqueado ou não contatar
- exige conversa atribuída, salvo configuração
- gera `request_id`
- entra na fila `whatsapp-manual-replies`
- usa `WhatsAppProvider`

Não ha resposta automática, chatbot, IA ou gatilho por palavra.

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
- última sincronização de conversas
- sincronização presa
- filas e jobs falhos

## Privacidade

Não registrar em logs gerais:

- segredo do webhook
- corpo completo por padrão
- sessão do WhatsApp
- QR Code
- cookies
- midias

Mensagens completas ficam nas tabelas protegidas do módulo.

## Solução de problemas

- Assinatura invalida: conferir segredo, timestamp, nonce e corpo bruto.
- Replay detectado: confirmar se o Node.js esta reusando nonce.
- Mensagem não aparece: verificar fila `whatsapp-incoming` e `failed_jobs`.
- Resposta manual presa: executar `php artisan inbox:recover-stuck`.
- Contador incorreto: executar `php artisan inbox:sync-unread-counts`.
- Conversas não sincronizam: verificar conexão WhatsApp, fila `whatsapp-conversation-sync` e último registro em `conversation_sync_runs`.
- Mensagens de contatos diferentes aparecendo na mesma conversa sem contato: `ConversationResolverService::resolve()` escopa conversas sem `contact_id` pelo telefone do remetente (`sender_phone_snapshot`/`recipient_phone_snapshot`); sem telefone conhecido, so reaproveita conversas sem nenhuma mensagem ainda.
- Telefone exibido na lista de conversas não bate com a conversa: `Conversation::whatsappPhoneDigits()` so deve olhar mensagens da própria conversa; um `orWhereNotNull` fora do escopo correto já causou vazamento do telefone de outra conversa no passado (corrigido).
