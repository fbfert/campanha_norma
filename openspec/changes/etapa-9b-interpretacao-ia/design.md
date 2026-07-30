# Design — Subetapa 9B

## Contexto

A 9A entregou um fluxo conversacional determinístico e auditável. A 9B adiciona interpretação por IA sobre o que já foi persistido, sem alterar nenhuma decisão de envio.

A separação central e: **a conversa e fato, a interpretação e opinião derivada**. Fatos são imutáveis. Opiniões derivadas são versionadas, reprocessáveis e descartáveis.

## Decisões

### 1. A IA nunca decide envio nesta subetapa

O pipeline de IA não chama `ConversationAutomatedReplyService` nem qualquer job de envio. Ele apenas grava classificação, insight e sinalização de revisão. O único efeito colateral permitido sobre o fluxo 9A e marcar `needs_human_review` no estado da conversa, que já e um efeito previsto e reversível pela tela de controle.

Consequência: se toda a camada de IA falhar, o comportamento observável pelo contato e exatamente o da 9A.

### 2. A regra determinística tem precedência e evita chamada de IA

Quando `PermissionResponseClassifier` retorna `opt_out`, `permission_yes` ou `permission_no` com expressão correspondida, a classificação e gravada com origem `deterministic`, confiança `1.0` e **nenhuma chamada de IA e feita**. A IA so e consultada quando a regra determinística não conclui, ou quando a mensagem e uma resposta aberta a pergunta da pesquisa.

Isso atende ao critério "opt-out determinístico nunca depende da IA" de forma estrutural, não por configuração: o caminho de código do opt-out não passa pelo provedor.

### 3. Execuções de IA são log append-only; idempotência fica no resultado

`ai_runs` registra **toda** tentativa, inclusive falhas, sem índice único. Isso permite reprocessar após falha e preserva o rastro de auditoria.

A idempotência exigida pelos critérios de aceitação e garantida nas tabelas de resultado:

- `conversation_message_classifications` com único `(conversation_message_id, purpose, prompt_version, schema_version)`.
- `conversation_insights` com único `(source_message_id, extraction_version)`.

Assim "classificada uma única vez por versão e finalidade" e "reexecução idempotente não duplica insight" são garantias do banco, não do código. Uma nova versão de prompt ou schema produz uma nova linha, que e o comportamento desejado para reprocessamento versionado.

### 4. `request_hash` serve para deduplicação e diagnostico, não para unicidade

O hash cobre finalidade, versão de prompt, versão de schema, modelo e o texto normalizado enviado. Ele permite detectar reenvio identico e correlacionar execuções, mas não e chave única porque duas mensagens diferentes podem produzir o mesmo texto normalizado, e porque uma tentativa após falha deve ser permitida.

### 5. Tema principal e relacional; a saída livre do modelo e preservada

`conversation_insights.insight_topic_id` e chave estrangeira para `insight_topics`. Os temas secundários ficam na tabela pivô `conversation_insight_topics` com papel `main` ou `secondary`.

A string livre devolvida pelo modelo e preservada em `main_topic_raw` e `secondary_topics_raw` para auditoria e para reprocessar mapeamento sem nova chamada de IA. Filtro e relatório usam as colunas relacionais, nunca o JSON.

### 6. Mapeamento de tema e determinístico e tem fallback obrigatório

`InsightTopicMapper` normaliza a saída do modelo e compara com slug, nome e sinônimos cadastrados. Sem correspondência, o insight recebe o tema de fallback (`is_fallback = true`), que o seeder garante existir e que não pode ser excluído nem desativado.

O modelo nunca cria tema. A taxonomia e exclusivamente administrativa.

### 7. Prompts em arquivos versionados, versão ativa em configuração

Os prompts vivem em `resources/ai/prompts/{purpose}/{version}.txt`, versionados junto do código e revisáveis em diff. A versão ativa por finalidade vem de `system_settings` (`ai.classification_prompt_version`, `ai.extraction_prompt_version`), o que permite promover ou reverter uma versão sem deploy.

Alternativa descartada: prompts em tabela. Ganharia edição pela interface, mas perderia revisão por diff e permitiria alterar em produção um texto que define comportamento do sistema sem rastro no repositório.

### 8. Schema JSON validado no servidor, sem confiar no provedor

`AiSchemaRegistry` define o schema por finalidade e versão. Ele e enviado ao provedor quando o provedor suporta saída estruturada, **e** aplicado novamente localmente por `AiResponseValidator` sobre a resposta recebida.

Validação local cobre: JSON parseável, campos obrigatórios presentes, tipos corretos, valores dentro do conjunto permitido para campos enumerados, confiança entre zero e um, limites de tamanho de string e de itens de lista, e recusa de campos desconhecidos.

Saída invalida não altera estado: a execução e gravada como `invalid_output`, o item vai para revisão humana e nenhum insight e criado.

### 9. Disjuntor simples baseado em cache

`AiCircuitBreaker` conta falhas consecutivas por provedor em cache. Ao atingir o limite configurado, abre por um período configurável e as chamadas seguintes falham imediatamente com `CIRCUIT_OPEN`, sem rede. Um sucesso zera o contador.

Escolha deliberada: contador em cache, não meia-abertura com amostragem probabilistica. E suficiente para proteger a fila de um provedor fora do ar e não introduz não determinismo em teste.

### 10. Contexto mínimo enviado ao modelo

O prompt recebe apenas: a pergunta selecionada (snapshot congelado), a mensagem atual truncada em `ai.max_input_chars`, no máximo `ai.max_context_messages` mensagens imediatamente anteriores **da mesma conversa**, e a lista de temas cadastrados.

Nunca são enviados: base de contatos, mensagens de outras conversas, nome, telefone, etiquetas, histórico de campanhas ou qualquer atributo do contato. O montador de contexto não tem acesso ao model `Contact`.

### 11. Revisão humana e determinística, nunca decidida pela IA

Duas fontes marcam revisão:

- Confiança abaixo do threshold configurado por finalidade.
- `SensitiveContentDetector`, que aplica listas de expressões configuráveis sobre o texto **original** para denuncia, ameaca, pedido pessoal, acusação nominal, conteúdo juridico sensível, promessa, urgência individual e risco.

O detector roda independentemente do resultado da IA e também quando a IA falha. O motivo da revisão e persistido e exibido, nunca apenas um booleano.

### 12. Correção humana e auditada e não retroalimenta o modelo

`conversation_insight_corrections` grava campo, valor original, valor corrigido, usuário, motivo e data. A correção altera o insight vigente e mantem o valor anterior legível.

Nenhuma correção alimenta treinamento, ajuste de prompt ou exemplo few-shot automaticamente. Promover correções a exemplos exige criar uma nova versão de prompt no repositório, revisada em diff.

### 13. Privacidade por separação, não por confiança no operador

`conversation_insights` guarda conteúdo analítico e referência o contato por chave estrangeira, mas as telas analíticas nunca exibem telefone completo sem `ai_insights.view_raw_contact_data`. `PhoneMasker` reaproveita o mascaramento já usado nas conversas.

`ai.anonymize_reports` remove identificação das visões agregadas. `ai.runs_retention_days` define a retenção das execuções, aplicada por comando. Log técnico registra identificadores e códigos, nunca corpo de mensagem, telefone ou chave.

### 14. Reprocessamento e explícito e limitado

`ai:reprocess` aceita `--message`, `--conversation`, `--from` e `--to`, exige ao menos um filtro e pede confirmação interativa quando o alcance excede `ai.reprocess_confirm_threshold`. Não existe forma de reprocessar tudo sem confirmação explícita, nem por padrão nem por flag isolada.

### 15. Fila própria, isolada da fila de mensagens recebidas

`ai-interpretation` e uma fila nova e separada. A avaliação do fluxo 9A continua em `conversation-automation`. Um provedor de IA lento nunca atrasa o registro de mensagens recebidas nem a avaliação determinística.

O job usa trava por conversa, tentativas, backoff crescente e timeout maior que o timeout do provedor, para que um estouro de tempo do HTTP falhe antes do job.

## Alternativas descartadas

- **Chamar a IA dentro de `ProcessIncomingMessageJob`**: violaria a regra de nunca chamar serviço externo perto do registro da mensagem e acoplaria a latência do provedor ao recebimento.
- **Deixar a IA decidir opt-out ou permissão**: introduziria risco regulatório e dependência de terceiro em uma decisão que já e correta e barata de forma determinística.
- **Guardar temas apenas como JSON**: impediria filtro, agregação e integridade referencial, contrariando a regra de banco do projeto.
- **Índice único em `ai_runs` por mensagem e finalidade**: bloquearia nova tentativa após falha e destruiria o rastro de auditoria das tentativas.
- **Biblioteca externa de validação de JSON Schema**: dependência adicional para um conjunto pequeno e fechado de schemas que controlamos; a validação explícita e mais previsível e testável.

## Riscos

- **Qualidade da extração depende do prompt e do modelo.** Mitigado por versão ativa configurável, reprocessamento por versão e fila de revisão humana.
- **Custo por volume.** Mitigado por IA desligada por padrão, chamada evitada quando a regra determinística conclui, contexto mínimo, truncamento de entrada e registro de uso de tokens.
- **Provedor indisponível.** Mitigado por disjuntor, tentativas com backoff e falha controlada que envia o item para revisão sem alterar estado.
- **Vies do modelo em tema político.** Mitigado por instrução explícita de não produzir opinião política, não inferir intenção de voto e não inferir atributo sensível, por schema fechado que não possui campo para isso, e por revisão humana.
- **Reprocessamento em massa acidental.** Mitigado por exigência de filtro, confirmação acima do limite e registro em auditoria.
