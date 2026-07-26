# Conversas e sincronizacao do WhatsApp

## Arquitetura

O modulo `CONVERSAS` reutiliza as tabelas da Etapa 7:

- `conversations`
- `conversation_messages`
- `conversation_events`
- `conversation_assignments`
- `conversation_tags`
- `conversation_conversation_tag`
- `conversation_notes`

A Etapa 8 adiciona campos de sincronizacao e a tabela `conversation_sync_runs`.

Fluxo:

```text
Administrador -> CONVERSAS -> Laravel -> fila whatsapp-conversation-sync -> WhatsAppProvider -> Node.js -> whatsapp-web.js
```

O navegador nunca acessa o Node.js diretamente.

## Rotas

Rotas preservadas:

```text
/admin/inbox
/admin/inbox/{conversation}
```

Rotas amigaveis:

```text
/admin/conversations
/admin/conversations/{conversation}
POST /admin/conversations/sync
```

## Permissoes

- `inbox.view`
- `inbox.view_all`
- `inbox.view_message_content`
- `inbox.reply`
- `inbox.assign`
- `inbox.change_status`
- `inbox.change_priority`
- `inbox.manage_tags`
- `inbox.add_notes`
- `inbox.archive`
- `inbox.mark_do_not_contact`
- `inbox.associate_contact`
- `inbox.sync`

## Node.js

Endpoints privados:

```text
GET /api/conversations
GET /api/conversations/:chatId/messages
```

Ambos exigem o token interno `Authorization: Bearer`.

## Limites

Valores configuraveis via `system_settings`:

```text
conversations.sync_max_chats = 100
conversations.sync_messages_per_chat = 50
conversations.sync_days_back = 30
```

Limites absolutos:

```text
500 chats
500 mensagens por chat
365 dias
```

## Limitações

- O historico recuperado pode ser parcial.
- Grupos, status, canais, comunidades e listas sao ignorados.
- Midias nao sao baixadas.
- Nao ha chatbot, IA, resposta automatica ou API oficial da Meta.

## Teste manual

1. Acessar como administrador.
2. Abrir `CONVERSAS`.
3. Conferir badge de nao lidas.
4. Confirmar WhatsApp conectado.
5. Clicar em `Sincronizar conversas`.
6. Aguardar o worker `whatsapp-conversation-sync`.
7. Abrir uma conversa importada.
8. Ver mensagens recebidas e enviadas por outros dispositivos.
9. Responder manualmente.
10. Associar contato quando necessario.
11. Alterar responsavel, status e prioridade.
12. Adicionar etiqueta e nota interna.
13. Arquivar e desarquivar.
14. Validar bloqueio para contato marcado como nao contatar.
15. Testar usuario sem permissao `inbox.sync`.
