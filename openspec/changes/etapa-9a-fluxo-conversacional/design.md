# Design — Etapa 9A

## Objetivo

Fundação determinística do fluxo de pesquisa conversacional, sem IA, pronta para receber classificação inteligente nas subetapas 9B a 9E sem reescrita.

## Decisões

### 1. Estado por conversa em tabela própria

`conversation_flow_states` guarda um registro por conversa, com `conversation_id` único. Não reaproveitamos colunas de `conversations` para não acoplar o módulo de atendimento humano a automação e para permitir remover a automação sem migrar dados do inbox.

### 2. Maquina de estados explícita

`ConversationFlowStage` cobre os treze estados exigidos. Transições passam obrigatoriamente por `ConversationFlowStateMachine`, que grava `conversation_flow_transitions`. Nenhum serviço altera `current_stage` diretamente.

Motivo: auditabilidade e possibilidade de reconstruir a decisão tomada em qualquer ponto.

### 3. Classificação determinística isolada

`PermissionResponseClassifier` e um serviço puro, sem dependência de banco além das listas de expressões lidas de `system_settings`. Retorna `PermissionResponseClassification`.

Regras:

- Normalização remove acentos, pontuação, espaços repetidos e caixa, preservando o texto original para registro.
- Opt-out tem prioridade absoluta e e avaliado antes de positivas e negativas.
- Texto longo não vira positivo por aproximação: acima do limite configurado de palavras, apenas correspondência exata da lista e aceita; o restante e `ambiguous`.
- Listas de expressões ficam em `system_settings`, editáveis sem deploy.

Motivo: em 9B a classificação por IA entra como estratégia adicional atrás da mesma interface, mantendo o determinismo como fallback e como guarda.

### 4. Seleção de pergunta transacional e travada

`ConversationQuestionSelector` executa dentro de `DB::transaction` com `Cache::lock` por conversa e `lockForUpdate` na leitura de perguntas já usadas. A unicidade real e garantida por índice único em `conversation_flow_question_usages (conversation_id, conversation_flow_question_id)`.

Motivo: dois workers concorrentes não podem sortear duas perguntas. A trava evita a corrida; o índice único e a garantia final no banco.

### 5. Sorteio por peso

Sorteio ponderado simples: soma dos pesos das perguntas ativas ainda não usadas, sorteio de um ponto no intervalo e varredura acumulada. Sem dependência de função aleatória do MySQL, para manter o comportamento testável com seed controlado.

### 6. Congelamento de texto

O texto da pergunta e copiado para `selected_question_snapshot` no estado e para `question_snapshot` no registro de uso. Alterar a pergunta depois não muda o que foi enviado.

Motivo: mesma política de snapshot já adotada em `message_batch_recipients`.

### 7. Envio reaproveitando o caminho da resposta manual

`ConversationAutomatedReplyService` cria um `ConversationMessage` pendente com `origin = automation` e enfileira `SendAutomatedConversationReplyJob`, que usa `WhatsAppProviderManager` exatamente como `SendManualConversationReplyJob`.

O job de avaliação nunca envia diretamente. Ele apenas cria a mensagem pendente e enfileira.

Motivo: um único caminho de envio, já coberto por limites, auditoria e tratamento de falha.

### 8. Marcação de origem das mensagens

Nova coluna `origin` em `conversation_messages` com valores `manual`, `automation`, `incoming` e `sync`. Mensagens antigas recebem `manual` ou `incoming` conforme a direção, por backfill na própria migration.

Motivo: a linha do tempo precisa distinguir claramente o que foi automático, exigência de transparência.

### 9. Filas separadas

`conversation-automation` para avaliação e `conversation-automation-send` para envio. Nenhuma das duas compartilha fila com `whatsapp-incoming`.

Motivo: uma automação lenta ou em retry não pode atrasar o registro de mensagens recebidas.

### 10. Integração pos-commit no recebimento

`ProcessIncomingMessageJob` despacha `EvaluateConversationFlowJob` somente depois do commit da transação que grava a mensagem, usando `DB::afterCommit`. A avaliação ocorre em outro job, em outra fila.

Motivo: regra do projeto — a mensagem recebida e persistida antes de qualquer análise, e nenhum serviço externo e chamado dentro da transação.

### 11. Idempotência

`EvaluateConversationFlowJob` recebe o `conversation_message_id`. Antes de agir, compara com `last_processed_message_id` do estado. Mensagem já processada encerra o job sem efeito. A criação da mensagem automática também e protegida pelo índice único de uso de pergunta.

Motivo: cenário 2 dos critérios de aceitação — reexecução não pode gerar segunda pergunta.

### 12. Guarda única de automação

`ConversationAutomationGuard` concentra as verificações: automação global habilitada, envio automático habilitado, fluxo ativo, estado não pausado, contato apto, `do_not_contact` ausente, limite de mensagens automáticas, validade do fluxo e janela de horário.

Motivo: uma única porta de entrada evita divergência entre jobs e telas.

### 13. Opt-out reaproveita o serviço de contatos

Opt-out chama `ContactDataService::setDoNotContact` e `ReplyInterruptionService::interrupt`, já existentes. Não duplicamos regra de negócio nem histórico.

`permission_no` não marca `do_not_contact` por padrão. Existe configuração explícita para quem quiser esse comportamento.

Motivo: recusar uma pergunta não e o mesmo que pedir para nunca mais ser contatado.

### 14. Automação desligada por padrão

`conversation_automation.enabled = 0` e `conversation_automation.auto_send_enabled = 0` no seeder. A homologação liga primeiro apenas a avaliação, observa o estado e so depois habilita o envio.

## Alternativas descartadas

- Guardar estado em `conversations.metadata`: dificultaria índices, filtros e relatórios por estado.
- Sortear com `ORDER BY RAND()`: não reproduzível em teste e ignora peso.
- Enviar dentro do job de avaliação: acoplaria classificação e entrega, e um retry de envio reprocessaria a classificação.
- Classificar com IA nesta subetapa: fora do escopo declarado da 9A.

## Riscos

- Listas de expressões mal configuradas podem classificar respostas legitimas como ambiguas. Mitigação: padrão conservador, tela de estado mostrando a decisão e possibilidade de assumir manualmente.
- Campanhas antigas sem fluxo associado permanecem sem automação. Comportamento intencional.
- Novas filas exigem atualização do worker em produção. Documentado no roteiro de implantação.
