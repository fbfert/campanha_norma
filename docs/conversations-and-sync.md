# Conversas e sincronização do WhatsApp

## Arquitetura

O módulo `CONVERSAS` reutiliza as tabelas da Etapa 7:

- `conversations`
- `conversation_messages`
- `conversation_events`
- `conversation_assignments`
- `conversation_tags`
- `conversation_conversation_tag`
- `conversation_notes`

A Etapa 8 adiciona campos de sincronização e a tabela `conversation_sync_runs`.

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

Rotas amigáveis:

```text
/admin/conversations
/admin/conversations/{conversation}
POST /admin/conversations/sync
```

## Permissões

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

Valores configuráveis via `system_settings`:

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

## Conversas removidas e resincronização

`ConversationSyncService::syncChat()` verifica, antes de criar uma conversa, se já existe um registro removido (soft delete) com o mesmo `provider` + `external_chat_id`. Se existir, a sincronização pula aquele chat em vez de recriar a conversa — evita colidir com a restrição única da tabela e evita reviver conversas removidas intencionalmente (por exemplo, conversas vazias sem contato, sem mensagem e sem telefone identificável, originadas por falha de resolução de `@lid`).

## Falha anterior à reconexão

A tela de conversas mostra sempre a **última** execução. Quando a sessão do
WhatsApp cai, a sincronização falha a cada 15 minutos até alguém reconectar, e a
execução seguinte volta a funcionar sozinha. No intervalo entre a reconexão e a
próxima execução, a tela seguia exibindo a última falha em vermelho — "conecte o
WhatsApp antes de sincronizar" — enquanto a tela de conexão dizia "Conectado".

As duas estavam certas, e era por isso que confundia: quem lia concluía que o
sistema estava quebrado naquele momento, quando o problema já tinha passado.
Aconteceu em 10/08/2026 — sete falhas entre 10:45 e 12:15, reconexão às 12:21, e
a tela ainda mostrando o erro das 12:15.

`SyncFailureNotice` faz a conta: se a conexão subiu **depois** de a execução
terminar, a falha é anterior à reconexão e não descreve o estado de hoje. A tela
troca o alarme vermelho por uma explicação, com o horário da reconexão.

Vale só para erro de conexão (`WHATSAPP_NOT_CONNECTED`,
`WHATSAPP_SESSION_UNAVAILABLE`). Falha de outra natureza não é resolvida por
reconectar, e apresentá-la como superada esconderia um problema real.

### O horário da conexão vinha em UTC

A comparação acima depende de as duas datas estarem na mesma escala, e não
estavam. O serviço Node manda o instante com `Z` no fim; `CarbonImmutable::parse`
respeita esse fuso e devolve um objeto em UTC; o Eloquent grava a hora **no fuso
que o objeto carrega**, e a leitura de volta interpreta a coluna como hora local.
A conexão das 12:21 ficava gravada como 15:21 — três horas no futuro.

A conversão fica em `ConnectionStatus::date()`, na fronteira, para valer também
para quem consumir `connected_at` e `last_activity_at` depois. É o mesmo defeito
já corrigido nos horários de mensagem.

Teste: `FalhaDeSincronizacaoAnteriorAReconexaoTest`.

## Limitações

- O histórico recuperado pode ser parcial.
- Grupos, status, canais, comunidades e listas são ignorados.
- Midias não são baixadas, mas mídia ilegível recebida pela sincronização recebe
  pedido de resposta por escrito — ver `docs/conversation-automation.md`.
- Não ha chatbot, IA, resposta automática ou API oficial da Meta.

## Teste manual

1. Acessar como administrador.
2. Abrir `CONVERSAS`.
3. Conferir badge de não lidas.
4. Confirmar WhatsApp conectado.
5. Clicar em `Sincronizar conversas`.
6. Aguardar o worker `whatsapp-conversation-sync`.
7. Abrir uma conversa importada.
8. Ver mensagens recebidas e enviadas por outros dispositivos.
9. Responder manualmente.
10. Associar contato quando necessário.
11. Alterar responsável, status e prioridade.
12. Adicionar etiqueta e nota interna.
13. Arquivar e desarquivar.
14. Validar bloqueio para contato marcado como não contatar.
15. Testar usuário sem permissão `inbox.sync`.
