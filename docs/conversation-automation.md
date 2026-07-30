# Pesquisa conversacional — Etapa 9A

Fundação determinística do fluxo de pesquisa conversacional. Esta subetapa **não usa IA**, embeddings, RAG ou classificação por similaridade. Toda decisão deriva de estado persistido e de listas de expressões configuráveis.

## Pergunta central

> O que a Professora Norma pode fazer para melhorar nosso Estado como membro da Assembleia Legislativa de Santa Catarina?

O administrador cadastra variações dessa pergunta no fluxo. Após a mensagem inicial de campanha, uma resposta positiva provoca o envio de **uma** pergunta sorteada.

## Fluxo

```text
Campanha envia mensagem inicial
        -> estado waiting_permission
Contato responde
        -> ProcessIncomingMessageJob grava a mensagem
        -> apos o commit: EvaluateConversationFlowJob (fila propria)
        -> classificacao deterministica
              permission_yes  -> sorteia pergunta -> mensagem pendente -> SendAutomatedConversationReplyJob
              permission_no   -> agradece e encerra
              opt_out         -> marca nao contatar, interrompe lotes, nao envia
              ambiguous       -> nao envia, encaminha para humano
```

O navegador nunca chama o Node.js. O envio continua pelo `WhatsAppProvider`.

## Tabelas

```text
conversation_flows
conversation_flow_questions
conversation_flow_states
conversation_flow_transitions
conversation_flow_question_usages
```

Alterações em tabelas existentes:

```text
message_batches.conversation_flow_id        associacao opcional com fluxo
message_batches.conversation_flow_snapshot  snapshot da associacao
conversation_messages.origin                manual | automation | incoming | sync
```

## Estagios

```text
inactive
initial_message_sent
waiting_permission
permission_granted
permission_denied
question_selected
waiting_answer
answer_received
completed
opted_out
paused
waiting_human
failed
```

Estagios terminais: `completed`, `opted_out`, `permission_denied`, `failed`. Mensagem fora de ordem não reabre um fluxo terminal.

Toda mudança de estagio passa por `ConversationFlowStateMachine` e gera registro em `conversation_flow_transitions`.

## Serviços

```text
PermissionResponseClassifier      classificacao deterministica
ConversationQuestionSelector      sorteio ponderado transacional e travado
ConversationAutomationGuard       porta unica de verificacao
ConversationFlowStateMachine      transicoes e historico
ConversationAutomatedReplyService cria mensagem pendente e enfileira
ConversationFlowService           orquestracao
```

## Ponto de extensão

Ao final de `handleIncomingMessage`, depois de todas as decisões deterministicas, a 9A pública `App\Events\ConversationMessageEvaluated` com a mensagem, o estado do fluxo e se o motor chegou a processar.

A 9A não conhece nenhum ouvinte e não depende de nenhum: sem ouvintes registrados o disparo e um no-op. Uma falha em ouvinte e reportada e nunca invalida o processamento determinístico já concluído. A Etapa 9B usa esse ponto para acionar a interpretação por IA.

## Classificação

Precedência, da maior para a menor:

```text
opt_out > permission_no > permission_yes > ambiguous
```

Opt-out e avaliado antes de tudo, inclusive antes do limite de palavras. Por isso "sim, mas não quero receber mais mensagens" e "pode perguntar, mas depois não me mande mais mensagens" resultam em `opt_out`: um pedido inequivoco de interrupção sempre prevalece sobre um consentimento na mesma frase.

A negativa e avaliada antes da positiva também na correspondência exata, para que uma sobreposição entre as listas nunca produza consentimento presumido.

Ordem de avaliação:

1. Normalização: caixa, espaços, pontuação, acentos e emojis. O texto original e preservado.
2. **Opt-out tem prioridade absoluta.**
3. Correspondência exata com as listas configuradas.
4. Acima do limite de palavras (`short_answer_max_words`), o texto e `ambiguous` — não ha classificação por aproximação.
5. Texto curto contendo positiva e negativa e `ambiguous`, nunca positivo.
6. Correspondência por palavra ou frase inteira; `nao` nunca casa dentro de outra palavra.

As listas ficam em `system_settings`, separadas por barra vertical, editáveis sem deploy.

**Atenção ao editar `opt_out_expressions`.** Uma expressão colocada ali marca o contato como não contatar e interrompe lotes pendentes. Termos que indicam assunto sensível, e não pedido de interrupção, devem ficar em `ai.expressions.sensitive_report` (Etapa 9B), nunca aqui. A palavra `denuncia` estava indevidamente na lista de opt-out e foi movida: quem escrevia "quero fazer uma denuncia" era removido da base em vez de ser encaminhado para atendimento humano.

## Filas

```text
conversation-automation        avaliacao do fluxo
conversation-automation-send   envio de mensagens automaticas
```

Nenhuma compartilha fila com `whatsapp-incoming`. Adicionar ao worker:

```bash
php artisan queue:work --queue=whatsapp-incoming,whatsapp-messages,whatsapp-manual-replies,whatsapp-conversation-sync,whatsapp-maintenance,conversation-automation,conversation-automation-send,default
```

## Configurações

```text
conversation_automation.enabled                        0   (desligado por padrao)
conversation_automation.auto_send_enabled              0   (desligado ate homologacao)
conversation_automation.queue                          conversation-automation
conversation_automation.send_queue                     conversation-automation-send
conversation_automation.max_automated_messages         3
conversation_automation.default_validity_hours         48
conversation_automation.short_answer_max_words         6
conversation_automation.min_response_interval_seconds  0
conversation_automation.window_start                   08:00
conversation_automation.window_end                     20:00
conversation_automation.yes_expressions                lista
conversation_automation.no_expressions                 lista
conversation_automation.opt_out_expressions            lista
conversation_automation.thank_you_text                 texto
conversation_automation.permission_denied_text         texto
conversation_automation.opt_out_text                   texto
conversation_automation.transparency_mode              none | prefix | suffix
conversation_automation.transparency_text              texto
conversation_automation.ambiguous_behavior             waiting_human | keep_waiting
conversation_automation.no_question_behavior           waiting_human | completed
conversation_automation.mark_do_not_contact_on_refusal 0
```

Após alterar configurações pelo seeder, limpe o cache:

```bash
php artisan cache:clear
```

## Permissões

```text
conversation_automation.view
conversation_automation.manage_flows
conversation_automation.manage_questions
conversation_automation.control
```

- Administrador: todas.
- Operador: `view` e `control`.
- Consulta: apenas `view`.

## Rotas

```text
/admin/conversation-automation
/admin/conversation-automation/{state}
POST /admin/conversation-automation/{state}/pause
POST /admin/conversation-automation/{state}/resume
POST /admin/conversation-automation/{state}/finish
POST /admin/conversation-automation/{state}/take-over
/admin/conversation-flows
/admin/conversation-flows/{conversationFlow}/questions/...
```

## Garantias

- **Idempotência**: `last_processed_message_id` no estado e índice único em `(conversation_id, conversation_flow_question_id)`.
- **Concorrência**: `Cache::lock` por conversa no job, `DB::transaction` com `lockForUpdate` no seletor, índice único como garantia final.
- **Sem envio duplicado**: a mensagem automática e criada uma única vez; o job de envio revalida o guard antes de disparar.
- **Limites explícitos**: máximo de mensagens automáticas, validade do fluxo e contador de tentativas.
- **Pausável**: global, por fluxo e por conversa.
- **Auditável**: `conversation_flow_transitions`, `conversation_events` e `audit_logs`.

## Transparência e LGPD

- Aviso de automação configurável por fluxo (`transparency_enabled` e `transparency_text`).
- Mensagens automáticas marcadas como `Automatica` na linha do tempo.
- Opt-out imediato e prioritário, reaproveitando `ContactDataService::setDoNotContact` e `ReplyInterruptionService`.
- Recusa simples não marca `do_not_contact` por padrão.
- A automação usa apenas os textos do fluxo e o estado da própria conversa; nunca mensagens de outros contatos.
- Logs registram a classificação e identificadores, nunca segredos ou sessão.

## Não implementado nesta subetapa

- Aprofundamento de perguntas (9B em diante).
- Classificação por IA, embeddings ou RAG.
- Conversa infinita.
- Análise de sentimento ou sumarização.

## Solução de problemas

- Nada acontece ao responder: confirmar `conversation_automation.enabled`, o worker das filas novas e o estado da conversa.
- Pergunta não enviada: confirmar `conversation_automation.auto_send_enabled`, janela de horário e elegibilidade do contato.
- Resposta legítima virando ambígua: revisar as listas de expressões e `short_answer_max_words`.
- Conversa presa em `waiting_human`: usar retomar ou encerrar na tela de estado.
- Nenhuma pergunta disponível: cadastrar perguntas ativas ou ajustar `no_question_behavior`.
