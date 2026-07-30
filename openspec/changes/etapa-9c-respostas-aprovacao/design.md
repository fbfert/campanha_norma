# Design — Subetapa 9C

## Contexto

A 9A decide, a 9B interpreta, a 9C propoe. A diferenca essencial da 9C e que ela produz texto que pode chegar a uma pessoa real. Por isso o desenho parte de uma premissa invertida em relacao as subetapas anteriores: **o caminho padrao nao envia nada**.

## Decisoes

### 1. O default e humano, e o default e estrutural

O modo efetivo nasce em `approval_required`. Nesse modo, `ConversationSuggestionService` cria a sugestao e para. Nao existe caminho de codigo que va de "modelo respondeu" a "mensagem enviada" sem passar por um controlador de aprovacao com um usuario autenticado e a permissao `reply_suggestions.approve`.

O autoenvio e um ramo separado e explicito, nao um `if` a mais no fluxo normal.

### 2. Sugestao valida unica por mensagem, garantida pelo banco

O criterio "cada mensagem recebida gera no maximo uma sugestao valida" precisa valer sob concorrencia. MySQL nao tem indice unico parcial, entao usamos a tecnica da coluna espelho anulavel:

```text
active_source_message_id = source_message_id  enquanto pending ou approved
active_source_message_id = NULL               quando sent, rejected, superseded, failed ou expired
```

Com indice unico em `active_source_message_id`. Como MySQL permite multiplos `NULL` em indice unico, o efeito e exatamente "no maximo uma sugestao viva por mensagem recebida", sem impedir historico de regeneracoes.

### 3. Obsolescencia e detectada por fato, nao por tempo

Uma sugestao fica obsoleta quando chega uma nova mensagem recebida na conversa depois da mensagem que a originou. A verificacao compara o `id` da ultima mensagem recebida com `source_message_id` no momento da aprovacao e do autoenvio, dentro da trava.

Nao usamos expiracao por tempo como mecanismo principal: uma sugestao de dez minutos atras continua valida se a pessoa nao escreveu mais nada, e uma de dez segundos atras ja e invalida se ela escreveu. Existe tambem um prazo maximo configuravel, mas ele e um teto, nao o criterio.

### 4. Texto gerado e texto final sao colunas diferentes

`generated_text` nunca e sobrescrito. A edicao do operador vai para `final_text`. O que foi enviado e sempre `final_text ?? generated_text`, e a mensagem enviada guarda os dois vinculos.

Isso permite responder depois a pergunta que importa: o modelo estava bom, ou o operador consertou?

### 5. O validador deterministico roda depois do modelo, sempre

`ReplyTextValidator` aplica regras que o prompt tambem pede, porque prompt e pedido e validador e garantia. Cobre: tamanho maximo, no maximo um ponto de interrogacao, ausencia de expressoes de promessa, pedido de voto, comparacao com adversarios, urgencia artificial, intimidade simulada e alegacao de leitura pessoal.

Texto reprovado nunca vira sugestao aprovavel: a sugestao nasce com `requires_human_review` e motivo, ou e recusada conforme configuracao. No autoenvio, reprovacao no validador e bloqueio absoluto.

As listas de expressoes ficam em `system_settings`, editaveis sem deploy, no mesmo padrao das listas da 9A e da 9B.

### 6. Sem 9D, pergunta factual nao e respondida por invencao

Nao existe base de conhecimento aprovada nesta subetapa. Perguntas factuais sobre a Professora Norma tem dois destinos configuraveis: handoff humano, ou um texto institucional fixo definido em configuracao. O modelo nunca preenche essa lacuna.

O prompt declara explicitamente que o modelo nao possui informacao factual sobre propostas e deve usar `handoff_human` nesses casos.

### 7. Debounce por agrupamento, nao por atraso cego

Mensagens consecutivas em poucos segundos sao fragmentos de um mesmo pensamento. O job de geracao e despachado com atraso configuravel e, ao executar, verifica se a mensagem que o originou ainda e a ultima recebida. Se nao for, ele encerra sem gerar: o job da mensagem mais recente fara o trabalho com o texto completo.

O efeito e agrupamento sem precisar de buffer, e a propria verificacao de obsolescencia serve de mecanismo.

### 8. Contagem de turnos idempotente

`conversation_flow_states.followups_count` e incrementado no envio confirmado, nunca na geracao. Gerar tres sugestoes e aprovar uma conta como um turno. O limite efetivo e o menor entre o do fluxo e o global, e ao atinge-lo o sistema envia o agradecimento e encerra em vez de simplesmente parar em silencio.

### 9. Modo do fluxo so restringe

Os modos sao ordenados por permissividade:

```text
disabled < draft_only < approval_required < auto_send_limited
```

O modo efetivo e o **menor** entre o global e o do fluxo. Um fluxo nunca consegue habilitar mais do que a configuracao global permite, o que torna o desligamento global um botao de parada real.

### 10. Servico de saida unificado sem regressao do manual

`ConversationReplyService` concentra o que e comum e perigoso: validacao de elegibilidade, criacao da mensagem pendente, `request_id` unico, snapshots, evento, auditoria e despacho.

`ManualReplyService` passa a delegar, preservando integralmente suas validacoes proprias, suas mensagens de erro e sua fila. O teste de regressao do envio manual e o criterio de aceite dessa refatoracao.

Alternativa descartada: reescrever o envio manual sobre o novo servico. Ganharia simetria e arriscaria um modulo estavel em producao por beneficio estetico.

### 11. Opt-out prevalece sobre qualquer sugestao pendente

Quando a 9A registra opt-out, todas as sugestoes pendentes da conversa sao invalidadas na mesma operacao. Uma sugestao aprovada mas ainda nao enviada tambem e bloqueada no job de envio, que revalida elegibilidade.

Sao duas barreiras porque o intervalo entre aprovar e enviar e real.

### 12. Metadados de IA ficam em colunas, nao em JSON

`generated_by_ai`, `ai_run_id`, `prompt_version`, `approved_by`, `approved_at`, `confidence` e `automation_state_transition_id` viram colunas em `conversation_messages`. Sao dados de auditoria que precisam ser filtraveis e agregaveis pela 9E.

### 13. Feedback e coletado e nao retroalimenta nada

O operador marca a sugestao como boa, ruim ou inadequada. O dado fica armazenado com motivo opcional e autor. Nenhum processo automatico le esse campo para ajustar prompt, modelo ou threshold. Promover aprendizado exige criar uma nova versao de prompt no repositorio, revisada em diff.

### 14. Fila propria, separada da fila de analise

`ai-response-generation` e uma fila nova. Um provedor lento na geracao nunca atrasa a interpretacao da 9B, a avaliacao da 9A ou o registro de mensagens recebidas.

## Alternativas descartadas

- **Aprovacao em massa**: explicitamente proibida no escopo. Uma tela que aprova cinquenta sugestoes com um clique transforma revisao humana em carimbo.
- **Expiracao por tempo como criterio unico de obsolescencia**: erra nos dois sentidos, como descrito na decisao 3.
- **Deixar o modelo decidir se pode enviar**: o campo `requires_human_review` da saida e tratado como sinal, nunca como autorizacao. Quem decide envio e o guard deterministico.
- **Reaproveitar a fila da 9B**: acoplaria a latencia da geracao a analise, que precisa continuar rapida.

## Riscos

- **Operador aprovando sem ler.** Mitigado por proibicao de aprovacao em massa, exibicao obrigatoria da mensagem original e bloqueio de sugestao obsoleta.
- **Texto plausivel porem impreciso.** Mitigado por validador deterministico, ausencia de base factual e handoff obrigatorio para pergunta factual.
- **Autoenvio ligado cedo demais.** Mitigado por modo padrao de aprovacao, allowlist de categorias vazia por padrao e roteiro de homologacao que so chega ao autoenvio na ultima fase.
- **Contato percebendo a automacao como pessoa.** Mitigado pelo aviso de transparencia herdado da 9A e por regra explicita contra simular intimidade ou alegar leitura pessoal.
