# Design — Subetapa 9C

## Contexto

A 9A decide, a 9B interpreta, a 9C propoe. A diferença essencial da 9C e que ela produz texto que pode chegar a uma pessoa real. Por isso o desenho parte de uma premissa invertida em relação as subetapas anteriores: **o caminho padrão não envia nada**.

## Decisões

### 1. O default e humano, e o default e estrutural

O modo efetivo nasce em `approval_required`. Nesse modo, `ConversationSuggestionService` cria a sugestão e para. Não existe caminho de código que va de "modelo respondeu" a "mensagem enviada" sem passar por um controlador de aprovação com um usuário autenticado e a permissão `reply_suggestions.approve`.

O autoenvio e um ramo separado e explícito, não um `if` a mais no fluxo normal.

### 2. Sugestão valida única por mensagem, garantida pelo banco

O critério "cada mensagem recebida gera no máximo uma sugestão valida" precisa valer sob concorrência. MySQL não tem índice único parcial, então usamos a técnica da coluna espelho anulável:

```text
active_source_message_id = source_message_id  enquanto pending ou approved
active_source_message_id = NULL               quando sent, rejected, superseded, failed ou expired
```

Com índice único em `active_source_message_id`. Como MySQL permite múltiplos `NULL` em índice único, o efeito e exatamente "no máximo uma sugestão viva por mensagem recebida", sem impedir histórico de regenerações.

### 3. Obsolescência e detectada por fato, não por tempo

Uma sugestão fica obsoleta quando chega uma nova mensagem recebida na conversa depois da mensagem que a originou. A verificação compara o `id` da última mensagem recebida com `source_message_id` no momento da aprovação e do autoenvio, dentro da trava.

Não usamos expiração por tempo como mecanismo principal: uma sugestão de dez minutos atrás continua valida se a pessoa não escreveu mais nada, e uma de dez segundos atrás já e invalida se ela escreveu. Existe também um prazo máximo configurável, mas ele e um teto, não o critério.

### 4. Texto gerado e texto final são colunas diferentes

`generated_text` nunca e sobrescrito. A edição do operador vai para `final_text`. O que foi enviado e sempre `final_text ?? generated_text`, e a mensagem enviada guarda os dois vínculos.

Isso permite responder depois a pergunta que importa: o modelo estava bom, ou o operador consertou?

### 5. O validador determinístico roda depois do modelo, sempre

`ReplyTextValidator` aplica regras que o prompt também pede, porque prompt e pedido e validador e garantia. Cobre: tamanho máximo, no máximo um ponto de interrogação, ausência de expressões de promessa, pedido de voto, comparação com adversários, urgência artificial, intimidade simulada e alegação de leitura pessoal.

Texto reprovado nunca vira sugestão aprovável: a sugestão nasce com `requires_human_review` e motivo, ou e recusada conforme configuração. No autoenvio, reprovação no validador e bloqueio absoluto.

As listas de expressões ficam em `system_settings`, editáveis sem deploy, no mesmo padrão das listas da 9A e da 9B.

### 6. Sem 9D, pergunta factual não e respondida por invenção

Não existe base de conhecimento aprovada nesta subetapa. Perguntas factuais sobre a Professora Norma tem dois destinos configuráveis: handoff humano, ou um texto institucional fixo definido em configuração. O modelo nunca preenche essa lacuna.

O prompt declara explicitamente que o modelo não possui informação factual sobre propostas e deve usar `handoff_human` nesses casos.

### 7. Debounce por agrupamento, não por atraso cego

Mensagens consecutivas em poucos segundos são fragmentos de um mesmo pensamento. O job de geração e despachado com atraso configurável e, ao executar, verifica se a mensagem que o originou ainda e a última recebida. Se não for, ele encerra sem gerar: o job da mensagem mais recente fará o trabalho com o texto completo.

O efeito e agrupamento sem precisar de buffer, e a própria verificação de obsolescência serve de mecanismo.

### 8. Contagem de turnos idempotente

`conversation_flow_states.followups_count` e incrementado no envio confirmado, nunca na geração. Gerar três sugestões e aprovar uma conta como um turno. O limite efetivo e o menor entre o do fluxo e o global, e ao atinge-lo o sistema envia o agradecimento e encerra em vez de simplesmente parar em silêncio.

### 9. Modo do fluxo so restringe

Os modos são ordenados por permissividade:

```text
disabled < draft_only < approval_required < auto_send_limited
```

O modo efetivo e o **menor** entre o global e o do fluxo. Um fluxo nunca consegue habilitar mais do que a configuração global permite, o que torna o desligamento global um botão de parada real.

### 10. Serviço de saída unificado sem regressão do manual

`ConversationReplyService` concentra o que e comum e perigoso: validação de elegibilidade, criação da mensagem pendente, `request_id` único, snapshots, evento, auditoria e despacho.

`ManualReplyService` passa a delegar, preservando integralmente suas validações próprias, suas mensagens de erro e sua fila. O teste de regressão do envio manual e o critério de aceite dessa refatoração.

Alternativa descartada: reescrever o envio manual sobre o novo serviço. Ganharia simetria e arriscaria um módulo estável em produção por benefício estético.

### 11. Opt-out prevalece sobre qualquer sugestão pendente

Quando a 9A registra opt-out, todas as sugestões pendentes da conversa são invalidadas na mesma operação. Uma sugestão aprovada mas ainda não enviada também e bloqueada no job de envio, que revalida elegibilidade.

São duas barreiras porque o intervalo entre aprovar e enviar e real.

### 12. Metadados de IA ficam em colunas, não em JSON

`generated_by_ai`, `ai_run_id`, `prompt_version`, `approved_by`, `approved_at`, `confidence` e `automation_state_transition_id` viram colunas em `conversation_messages`. São dados de auditoria que precisam ser filtráveis e agregáveis pela 9E.

### 13. Feedback e coletado e não retroalimenta nada

O operador marca a sugestão como boa, ruim ou inadequada. O dado fica armazenado com motivo opcional e autor. Nenhum processo automático le esse campo para ajustar prompt, modelo ou threshold. Promover aprendizado exige criar uma nova versão de prompt no repositório, revisada em diff.

### 14. Fila própria, separada da fila de análise

`ai-response-generation` e uma fila nova. Um provedor lento na geração nunca atrasa a interpretação da 9B, a avaliação da 9A ou o registro de mensagens recebidas.

## Alternativas descartadas

- **Aprovação em massa**: explicitamente proibida no escopo. Uma tela que aprova cinquenta sugestões com um clique transforma revisão humana em carimbo.
- **Expiração por tempo como critério único de obsolescência**: erra nos dois sentidos, como descrito na decisão 3.
- **Deixar o modelo decidir se pode enviar**: o campo `requires_human_review` da saída e tratado como sinal, nunca como autorização. Quem decide envio e o guard determinístico.
- **Reaproveitar a fila da 9B**: acoplaria a latência da geração a análise, que precisa continuar rapida.

## Riscos

- **Operador aprovando sem ler.** Mitigado por proibição de aprovação em massa, exibição obrigatória da mensagem original e bloqueio de sugestão obsoleta.
- **Texto plausível porém impreciso.** Mitigado por validador determinístico, ausência de base factual e handoff obrigatório para pergunta factual.
- **Autoenvio ligado cedo demais.** Mitigado por modo padrão de aprovação, allowlist de categorias vazia por padrão e roteiro de homologação que so chega ao autoenvio na última fase.
- **Contato percebendo a automação como pessoa.** Mitigado pelo aviso de transparência herdado da 9A e por regra explícita contra simular intimidade ou alegar leitura pessoal.
