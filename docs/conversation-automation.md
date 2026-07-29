# Pesquisa conversacional — Etapa 9A

Fundacao deterministica do fluxo de pesquisa conversacional. Esta subetapa **nao usa IA**, embeddings, RAG ou classificacao por similaridade. Toda decisao deriva de estado persistido e de listas de expressoes configuraveis.

## Pergunta central

> O que a Professora Norma pode fazer para melhorar nosso Estado como membro da Assembleia Legislativa de Santa Catarina?

O administrador cadastra variacoes dessa pergunta no fluxo. Apos a mensagem inicial de campanha, uma resposta positiva provoca o envio de **uma** pergunta sorteada.

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

Alteracoes em tabelas existentes:

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

Estagios terminais: `completed`, `opted_out`, `permission_denied`, `failed`. Mensagem fora de ordem nao reabre um fluxo terminal.

Toda mudanca de estagio passa por `ConversationFlowStateMachine` e gera registro em `conversation_flow_transitions`.

## Servicos

```text
PermissionResponseClassifier      classificacao deterministica
ConversationQuestionSelector      sorteio ponderado transacional e travado
ConversationAutomationGuard       porta unica de verificacao
ConversationFlowStateMachine      transicoes e historico
ConversationAutomatedReplyService cria mensagem pendente e enfileira
ConversationFlowService           orquestracao
```

## Ponto de extensao

Ao final de `handleIncomingMessage`, depois de todas as decisoes deterministicas, a 9A publica `App\Events\ConversationMessageEvaluated` com a mensagem, o estado do fluxo e se o motor chegou a processar.

A 9A nao conhece nenhum ouvinte e nao depende de nenhum: sem ouvintes registrados o disparo e um no-op. Uma falha em ouvinte e reportada e nunca invalida o processamento deterministico ja concluido. A Etapa 9B usa esse ponto para acionar a interpretacao por IA.

## Classificacao

Precedencia, da maior para a menor:

```text
opt_out > permission_no > permission_yes > ambiguous
```

Opt-out e avaliado antes de tudo, inclusive antes do limite de palavras. Por isso "sim, mas nao quero receber mais mensagens" e "pode perguntar, mas depois nao me mande mais mensagens" resultam em `opt_out`: um pedido inequivoco de interrupcao sempre prevalece sobre um consentimento na mesma frase.

A negativa e avaliada antes da positiva tambem na correspondencia exata, para que uma sobreposicao entre as listas nunca produza consentimento presumido.

Ordem de avaliacao:

1. Normalizacao: caixa, espacos, pontuacao, acentos e emojis. O texto original e preservado.
2. **Opt-out tem prioridade absoluta.**
3. Correspondencia exata com as listas configuradas.
4. Acima do limite de palavras (`short_answer_max_words`), o texto e `ambiguous` — nao ha classificacao por aproximacao.
5. Texto curto contendo positiva e negativa e `ambiguous`, nunca positivo.
6. Correspondencia por palavra ou frase inteira; `nao` nunca casa dentro de outra palavra.

As listas ficam em `system_settings`, separadas por barra vertical, editaveis sem deploy.

**Atencao ao editar `opt_out_expressions`.** Uma expressao colocada ali marca o contato como nao contatar e interrompe lotes pendentes. Termos que indicam assunto sensivel, e nao pedido de interrupcao, devem ficar em `ai.expressions.sensitive_report` (Etapa 9B), nunca aqui. A palavra `denuncia` estava indevidamente na lista de opt-out e foi movida: quem escrevia "quero fazer uma denuncia" era removido da base em vez de ser encaminhado para atendimento humano.

## Filas

```text
conversation-automation        avaliacao do fluxo
conversation-automation-send   envio de mensagens automaticas
```

Nenhuma compartilha fila com `whatsapp-incoming`. Adicionar ao worker:

```bash
php artisan queue:work --queue=whatsapp-incoming,whatsapp-messages,whatsapp-manual-replies,whatsapp-conversation-sync,whatsapp-maintenance,conversation-automation,conversation-automation-send,default
```

## Configuracoes

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

Apos alterar configuracoes pelo seeder, limpe o cache:

```bash
php artisan cache:clear
```

## Permissoes

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

- **Idempotencia**: `last_processed_message_id` no estado e indice unico em `(conversation_id, conversation_flow_question_id)`.
- **Concorrencia**: `Cache::lock` por conversa no job, `DB::transaction` com `lockForUpdate` no seletor, indice unico como garantia final.
- **Sem envio duplicado**: a mensagem automatica e criada uma unica vez; o job de envio revalida o guard antes de disparar.
- **Limites explicitos**: maximo de mensagens automaticas, validade do fluxo e contador de tentativas.
- **Pausavel**: global, por fluxo e por conversa.
- **Auditavel**: `conversation_flow_transitions`, `conversation_events` e `audit_logs`.

## Transparencia e LGPD

- Aviso de automacao configuravel por fluxo (`transparency_enabled` e `transparency_text`).
- Mensagens automaticas marcadas como `Automatica` na linha do tempo.
- Opt-out imediato e prioritario, reaproveitando `ContactDataService::setDoNotContact` e `ReplyInterruptionService`.
- Recusa simples nao marca `do_not_contact` por padrao.
- A automacao usa apenas os textos do fluxo e o estado da propria conversa; nunca mensagens de outros contatos.
- Logs registram a classificacao e identificadores, nunca segredos ou sessao.

## Nao implementado nesta subetapa

- Aprofundamento de perguntas (9B em diante).
- Classificacao por IA, embeddings ou RAG.
- Conversa infinita.
- Analise de sentimento ou sumarizacao.

## Solucao de problemas

- Nada acontece ao responder: confirmar `conversation_automation.enabled`, o worker das filas novas e o estado da conversa.
- Pergunta nao enviada: confirmar `conversation_automation.auto_send_enabled`, janela de horario e elegibilidade do contato.
- Resposta legitima virando ambigua: revisar as listas de expressoes e `short_answer_max_words`.
- Conversa presa em `waiting_human`: usar retomar ou encerrar na tela de estado.
- Nenhuma pergunta disponivel: cadastrar perguntas ativas ou ajustar `no_question_behavior`.
