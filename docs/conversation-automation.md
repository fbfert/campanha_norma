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

Quem escreve **primeiro**, sem nunca ter recebido lote, cai no mesmo silêncio —
e para esse caso existe o atendimento de entrada, em `docs/inbound-attendance.md`.
Ele abre o fluxo a partir da mensagem recebida, com perfil próprio, e entrega ao
motor desta etapa uma conversa em `waiting_permission`, igual à que um lote
produziria.

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

## Rede de segurança: por que ela tem teto

`PendingReplyResolver` garante que quem escreve recebe resposta. Para isso, dois
guardas ignoram saída que falhou de propósito: falha não é resposta, e um aviso
que não saiu não pode segurar a próxima tentativa. Cada um está certo isolado.

Juntos, sem teto, repetem sem fim. Em 07/08/2026 a sessão do WhatsApp caiu às
19:42 e voltou 64 horas depois. A cada cinco minutos a rede tentou mandar o mesmo
agradecimento para duas conversas e gravou **767 falhas em cada uma**: as
conversas 355 e 1414 chegaram a 771 e 781 mensagens, sendo 13 e 14 reais. Metade
da tabela de mensagens do sistema — 1535 de 3027 linhas — virou repetição de duas
frases que nunca saíram.

O teto é duplo:

1. **Sem sessão conectada não se tenta.** O envio falharia com certeza e a pessoa
   está inalcançável de qualquer jeito. Não consome tentativa: voltando a
   conexão, a execução seguinte tenta de novo. Só barra quando se **sabe** que a
   sessão caiu — sem registro de conexão não dá para afirmar nada, e presumir
   queda silenciaria a rede numa instalação nova. Só o provedor que pareia por
   sessão passa por essa condição; a API oficial não tem esse estado.
2. **`conversation_automation.unanswered_max_attempts`** (padrão 5) limita as
   tentativas para a mesma mensagem, para o caso de a falha persistir por outro
   motivo. Se a pessoa escrever de novo, a mensagem nova é outro gatilho, com
   contagem própria.

Teste: `RedeDeSegurancaNaoRepeteSemConexaoTest`.

## O aviso morria em conversa sem fluxo

`PendingReplyResolver::sendWithoutFlow` existe para a conversa que nunca entrou
em pesquisa — sem estado, o aviso sai pelo serviço de saída comum, porque
"ignorar essas deixaria justamente quem mais ficou no vácuo sem retorno".

A porta do envio desfazia isso. `canSendSafetyNet` tirava o contato do **estado
do fluxo**, e sem estado concluía que não havia contato. O aviso era criado,
enfileirado e recusado no último passo com `contato_nao_identificado` — em
conversa com contato identificado.

Em 12/08/2026 a conversa 423, contato 1020, repetiu isso a cada cinco minutos:
"Recebemos sua mensagem" gravado como falha, e a pessoa sem receber nenhum. Ao
todo eram **826 linhas** assim espalhadas por onze conversas, 815 delas numa só.

Agora o contato vem da conversa, e o job passa `$message->conversation` junto do
estado. O que protege a pessoa continua igual: não contatar, contato inativo,
sem telefone e janela de horário.

Teste: `AvisoChegaSemFluxoNaConversaTest`.

### A equipe não é atendida pelo próprio sistema

```text
conversations.internal_phones
```

O sistema não distingue quem atende de quem é atendido. A conversa de trabalho
com a candidata — almoço com o candidato a vice, estratégia de campanha — caiu
no mesmo funil de quem responde a uma pesquisa, e em 07/08/2026 ela recebeu
"Recebemos sua mensagem, muito obrigado! Nossa equipe vai ler com atenção." duas
vezes no mesmo segundo.

Nenhuma regra de conteúdo pega isso: naquele dia ela tinha escrito **"Oiii"**,
que é o que qualquer eleitor escreve. O que distingue é quem está do outro lado.

A lista vale para as duas portas automáticas — a rede de segurança e o
atendimento de entrada, inclusive no clique. Não impede resposta manual, que é o
que se quer numa conversa de trabalho. Só os dígitos comparam: o telefone chega
normalizado num lugar e digitado à mão no outro.

Editável em Perfis de atendimento. Mantenha a lista estreita — ela cala o
sistema para quem está nela.

Teste: `EquipeNaoEAtendidaPeloProprioSistemaTest`.

### A limpeza olhava só metade

`conversations:prune-failed-replies` filtrava `AUTOMATED_REPLY_FAILED`, que é a
tentativa que saiu e falhou. A recusa antes do disparo grava
`AUTOMATION_BLOCKED`, e por isso as 815 repetições de uma conversa ficaram
invisíveis: o comando respondia "nada a recolher" com metade da tabela cheia
delas. Hoje ele reconhece os dois códigos.

Para recolher repetição já gravada:

```bash
php artisan conversations:prune-failed-replies              # simula
php artisan conversations:prune-failed-replies --aplicar
```

Guarda a primeira e a última tentativa de cada bloco: elas registram que
tentamos, quando começou e quando parou, o que as cópias do meio não acrescentam.

## Mídia ilegível: a regra vale nos dois caminhos de entrada

Áudio, figurinha, imagem, vídeo e documento chegam sem texto. O motor de fluxo só
avalia `text` e a transcrição só trata áudio: os demais não caíam em lugar nenhum
e produziam silêncio absoluto. `UnreadableMediaResponder` responde dizendo que
chegou e que o caminho é escrever — não lê a mídia, só não a deixa no vácuo. O
pedido sai **uma vez por conversa**.

Duas correções que essa regra exigiu, e as duas nasceram do mesmo caso real:

**Existem dois caminhos de entrada.** A regra nasceu só no webhook. O João Pedro
mandou um áudio em 07/08 às 19:55, a sessão do WhatsApp caiu três minutos depois
e ficou 64 horas fora, a sincronização trouxe o áudio em 10/08 às 12:30 e a rede
de segurança respondeu "já te respondo" às 12:35 — ele nunca soube que não
conseguimos ouvi-lo. A decisão passou a morar em
`UnreadableMediaResponder::handles()`, que o webhook e a sincronização consultam.
É o mesmo formato do eco de saída duplicado, que também precisou existir nos dois
caminhos.

No caminho da sincronização vale só para a mídia que ficou **por último** na
conversa e é recente (`conversation_automation.media_reply_max_age_hours`, padrão
72). A sincronização varre trinta dias de histórico, e pedir texto sobre uma
figurinha de três semanas atrás, já respondida desde então, seria falar do
passado.

**O aviso sai pelo piso, não pelo caminho estrito.** Dizer "recebi, mas não
consigo ler" não é passo de pesquisa: é o mínimo devido a quem mandou alguma
coisa. Amarrá-lo às condições do fluxo o fazia morrer justamente quando mais
importa — o fluxo do João Pedro expirou sozinho em 09/08, **enquanto a sessão
estava fora do ar**, e quando enfim houve conexão o aviso foi recusado com
`fluxo_expirado`. A pessoa perdia a resposta por causa de uma queda nossa.

O piso continua respeitando o que protege a pessoa — não contatar, contato
inativo, janela de horário — e larga só as condições de estágio do fluxo. Mesmo
desenho já usado pelo agradecimento da rede de segurança (`canSendSafetyNet`).

Teste: `FigurinhaEImagemNaoFicamNoVacuoTest`.

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
