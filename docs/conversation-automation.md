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

## Vínculo entre lote e fluxo

O fluxo só entra em ação se o lote estiver vinculado a ele. O campo fica no
formulário do lote, em "3. Resposta automática", e grava
`message_batches.conversation_flow_id` mais o snapshot da associação.

Só fluxo com status `active` pode ser vinculado. Um fluxo já vinculado que for
pausado depois continua aceito na edição do lote — pausar o fluxo interrompe a
automação (o guard nega `fluxo_inativo`), mas não trava a edição.

A cada envio bem-sucedido, `RecipientProcessingService::activateConversationFlow`
resolve a conversa do destinatário e chama `ConversationFlowService::activateForConversation`,
que cria o estado em `waiting_permission`. Sem contato identificado no
destinatário, nada e criado.

Lote sem fluxo apenas envia: quem responder cai em atendimento humano, porque
`handleIncomingMessage` sai calado quando não existe estado para a conversa.

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

## Perguntas por conversa e ordem

`max_main_questions` define quantas perguntas da pesquisa cada conversa recebe. Com 1, a conversa encerra na primeira resposta; acima disso, cada resposta traz a próxima pergunta, sem repetir, até o teto ou até acabarem as perguntas ativas.

`question_order` decide como a próxima e escolhida:

```text
sorteio     sorteio ponderado pelo peso (padrao)
sequencia   ordem cadastrada em display_order; o peso e ignorado
```

Sorteio cobre mais temas com menos perguntas por pessoa. Sequência faz todo mundo responder as mesmas perguntas na mesma ordem, que e o que um questionário precisa para as respostas se compararem.

Quando o fluxo tem `max_followups` maior que zero, as perguntas cadastradas vem primeiro e so depois a 9C assume o aprofundamento. A pergunta cadastrada e igual para todo mundo e produz resposta comparável; a gerada varia a cada conversa.

## Retomar uma conversa

Ao entrar em espera — encaminhamento automático ou pausa manual — o estado guarda `stage_before_hold`. Retomar devolve a conversa para la, e não para o pedido de permissão.

Sem esse registro, retomar uma conversa que já tinha autorização a fazia voltar a pedir autorização, e a próxima frase da pessoa, que seria a opinião dela, era lida como sim ou não. Conversa antiga, sem o registro, continua voltando para `waiting_permission`, que e o destino seguro.

## Placeholders na pergunta

O texto da pergunta aceita os mesmos placeholders da mensagem de lote, e o formulário recusa chave inexistente. Contato sem o campo preenchido não recebe a pergunta: fica registrado `automation_placeholder_missing` e nada e enviado.

`{nome}`, `{primeiro_nome}` e `{telefone}` existem em todo contato apto. Cidade, estado, país e e-mail podem faltar.

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

A tela `/admin/conversation-automation/settings` edita todas essas chaves, menos
`queue` e `send_queue`. Ela grava preservando grupo, tipo e visibilidade, limpa
o cache sozinha e registra `conversation_automation.settings_updated` na
auditoria. Exige `conversation_automation.manage_settings`.

Fila continua fora da tela de propósito: nome de fila que nenhum worker consome
não produz erro, apenas emudece a automação. Trocar fila exige deploy, junto com
o worker que passa a consumi-la.

A mesma tela edita os **limiares de confiança da IA**, que também viviam só no
banco:

```text
ai.response.min_confidence            abaixo disso a resposta nasce sinalizada e nunca e autoenviada
ai.response.auto_send_min_confidence  a partir disso pode sair sem revisao humana
ai.min_classification_confidence      abaixo disso a classificacao pede revisao
ai.min_extraction_confidence          abaixo disso o insight pede revisao
analytics.low_confidence_threshold    so marca o dado como fragil nos relatorios
```

A tela recusa autoenvio abaixo do limiar de revisão obrigatória, que seria
enviar sozinho um texto que o próprio sistema considera duvidoso. Alterações
registram `ai.thresholds_updated` na auditoria.

Confiança e o modelo avaliando a si mesmo, e ele erra para cima: na prática
quase toda geração volta com 0,90 ou mais. O limiar filtra o descarado, não o
plausível e errado.

Duas combinações a tela recusa, por prometerem o que não podem cumprir: envio
automático ligado com a automação desligada, e aviso de automação escolhido sem
texto de aviso.

As listas de expressões são editadas uma por linha e guardadas separadas por
barra vertical, sem vazio e sem repetida.

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
conversation_automation.manage_settings
```

- Administrador: todas.
- Operador: `view` e `control`.
- Consulta: apenas `view`.

`control` pausa e retoma **uma** conversa. `manage_settings` liga e desliga o
motor para toda a base e define o texto que sai sem revisão humana: por isso são
permissões separadas, e o operador tem a primeira e não a segunda.

## Rotas

```text
/admin/conversation-automation
/admin/conversation-automation/settings
PUT /admin/conversation-automation/settings
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

- Nada acontece ao responder: confirmar o vínculo do lote com o fluxo, `conversation_automation.enabled`, o worker das filas novas e o estado da conversa.
- Pergunta não enviada: confirmar `conversation_automation.auto_send_enabled`, janela de horário e elegibilidade do contato.
- Resposta legítima virando ambígua: revisar as listas de expressões e `short_answer_max_words`.
- Conversa presa em `waiting_human`: usar retomar ou encerrar na tela de estado.
- Nenhuma pergunta disponível: cadastrar perguntas ativas ou ajustar `no_question_behavior`.
