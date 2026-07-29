# Design — Etapa 9A

## Objetivo

Fundacao deterministica do fluxo de pesquisa conversacional, sem IA, pronta para receber classificacao inteligente nas subetapas 9B a 9E sem reescrita.

## Decisoes

### 1. Estado por conversa em tabela propria

`conversation_flow_states` guarda um registro por conversa, com `conversation_id` unico. Nao reaproveitamos colunas de `conversations` para nao acoplar o modulo de atendimento humano a automacao e para permitir remover a automacao sem migrar dados do inbox.

### 2. Maquina de estados explicita

`ConversationFlowStage` cobre os treze estados exigidos. Transicoes passam obrigatoriamente por `ConversationFlowStateMachine`, que grava `conversation_flow_transitions`. Nenhum servico altera `current_stage` diretamente.

Motivo: auditabilidade e possibilidade de reconstruir a decisao tomada em qualquer ponto.

### 3. Classificacao deterministica isolada

`PermissionResponseClassifier` e um servico puro, sem dependencia de banco alem das listas de expressoes lidas de `system_settings`. Retorna `PermissionResponseClassification`.

Regras:

- Normalizacao remove acentos, pontuacao, espacos repetidos e caixa, preservando o texto original para registro.
- Opt-out tem prioridade absoluta e e avaliado antes de positivas e negativas.
- Texto longo nao vira positivo por aproximacao: acima do limite configurado de palavras, apenas correspondencia exata da lista e aceita; o restante e `ambiguous`.
- Listas de expressoes ficam em `system_settings`, editaveis sem deploy.

Motivo: em 9B a classificacao por IA entra como estrategia adicional atras da mesma interface, mantendo o determinismo como fallback e como guarda.

### 4. Selecao de pergunta transacional e travada

`ConversationQuestionSelector` executa dentro de `DB::transaction` com `Cache::lock` por conversa e `lockForUpdate` na leitura de perguntas ja usadas. A unicidade real e garantida por indice unico em `conversation_flow_question_usages (conversation_id, conversation_flow_question_id)`.

Motivo: dois workers concorrentes nao podem sortear duas perguntas. A trava evita a corrida; o indice unico e a garantia final no banco.

### 5. Sorteio por peso

Sorteio ponderado simples: soma dos pesos das perguntas ativas ainda nao usadas, sorteio de um ponto no intervalo e varredura acumulada. Sem dependencia de funcao aleatoria do MySQL, para manter o comportamento testavel com seed controlado.

### 6. Congelamento de texto

O texto da pergunta e copiado para `selected_question_snapshot` no estado e para `question_snapshot` no registro de uso. Alterar a pergunta depois nao muda o que foi enviado.

Motivo: mesma politica de snapshot ja adotada em `message_batch_recipients`.

### 7. Envio reaproveitando o caminho da resposta manual

`ConversationAutomatedReplyService` cria um `ConversationMessage` pendente com `origin = automation` e enfileira `SendAutomatedConversationReplyJob`, que usa `WhatsAppProviderManager` exatamente como `SendManualConversationReplyJob`.

O job de avaliacao nunca envia diretamente. Ele apenas cria a mensagem pendente e enfileira.

Motivo: um unico caminho de envio, ja coberto por limites, auditoria e tratamento de falha.

### 8. Marcacao de origem das mensagens

Nova coluna `origin` em `conversation_messages` com valores `manual`, `automation`, `incoming` e `sync`. Mensagens antigas recebem `manual` ou `incoming` conforme a direcao, por backfill na propria migration.

Motivo: a linha do tempo precisa distinguir claramente o que foi automatico, exigencia de transparencia.

### 9. Filas separadas

`conversation-automation` para avaliacao e `conversation-automation-send` para envio. Nenhuma das duas compartilha fila com `whatsapp-incoming`.

Motivo: uma automacao lenta ou em retry nao pode atrasar o registro de mensagens recebidas.

### 10. Integracao pos-commit no recebimento

`ProcessIncomingMessageJob` despacha `EvaluateConversationFlowJob` somente depois do commit da transacao que grava a mensagem, usando `DB::afterCommit`. A avaliacao ocorre em outro job, em outra fila.

Motivo: regra do projeto — a mensagem recebida e persistida antes de qualquer analise, e nenhum servico externo e chamado dentro da transacao.

### 11. Idempotencia

`EvaluateConversationFlowJob` recebe o `conversation_message_id`. Antes de agir, compara com `last_processed_message_id` do estado. Mensagem ja processada encerra o job sem efeito. A criacao da mensagem automatica tambem e protegida pelo indice unico de uso de pergunta.

Motivo: cenario 2 dos criterios de aceitacao — reexecucao nao pode gerar segunda pergunta.

### 12. Guarda unica de automacao

`ConversationAutomationGuard` concentra as verificacoes: automacao global habilitada, envio automatico habilitado, fluxo ativo, estado nao pausado, contato apto, `do_not_contact` ausente, limite de mensagens automaticas, validade do fluxo e janela de horario.

Motivo: uma unica porta de entrada evita divergencia entre jobs e telas.

### 13. Opt-out reaproveita o servico de contatos

Opt-out chama `ContactDataService::setDoNotContact` e `ReplyInterruptionService::interrupt`, ja existentes. Nao duplicamos regra de negocio nem historico.

`permission_no` nao marca `do_not_contact` por padrao. Existe configuracao explicita para quem quiser esse comportamento.

Motivo: recusar uma pergunta nao e o mesmo que pedir para nunca mais ser contatado.

### 14. Automacao desligada por padrao

`conversation_automation.enabled = 0` e `conversation_automation.auto_send_enabled = 0` no seeder. A homologacao liga primeiro apenas a avaliacao, observa o estado e so depois habilita o envio.

## Alternativas descartadas

- Guardar estado em `conversations.metadata`: dificultaria indices, filtros e relatorios por estado.
- Sortear com `ORDER BY RAND()`: nao reproduzivel em teste e ignora peso.
- Enviar dentro do job de avaliacao: acoplaria classificacao e entrega, e um retry de envio reprocessaria a classificacao.
- Classificar com IA nesta subetapa: fora do escopo declarado da 9A.

## Riscos

- Listas de expressoes mal configuradas podem classificar respostas legitimas como ambiguas. Mitigacao: padrao conservador, tela de estado mostrando a decisao e possibilidade de assumir manualmente.
- Campanhas antigas sem fluxo associado permanecem sem automacao. Comportamento intencional.
- Novas filas exigem atualizacao do worker em producao. Documentado no roteiro de implantacao.
