# Design — Subetapa 9B

## Contexto

A 9A entregou um fluxo conversacional deterministico e auditavel. A 9B adiciona interpretacao por IA sobre o que ja foi persistido, sem alterar nenhuma decisao de envio.

A separacao central e: **a conversa e fato, a interpretacao e opiniao derivada**. Fatos sao imutaveis. Opinioes derivadas sao versionadas, reprocessaveis e descartaveis.

## Decisoes

### 1. A IA nunca decide envio nesta subetapa

O pipeline de IA nao chama `ConversationAutomatedReplyService` nem qualquer job de envio. Ele apenas grava classificacao, insight e sinalizacao de revisao. O unico efeito colateral permitido sobre o fluxo 9A e marcar `needs_human_review` no estado da conversa, que ja e um efeito previsto e reversivel pela tela de controle.

Consequencia: se toda a camada de IA falhar, o comportamento observavel pelo contato e exatamente o da 9A.

### 2. A regra deterministica tem precedencia e evita chamada de IA

Quando `PermissionResponseClassifier` retorna `opt_out`, `permission_yes` ou `permission_no` com expressao correspondida, a classificacao e gravada com origem `deterministic`, confianca `1.0` e **nenhuma chamada de IA e feita**. A IA so e consultada quando a regra deterministica nao conclui, ou quando a mensagem e uma resposta aberta a pergunta da pesquisa.

Isso atende ao criterio "opt-out deterministico nunca depende da IA" de forma estrutural, nao por configuracao: o caminho de codigo do opt-out nao passa pelo provedor.

### 3. Execucoes de IA sao log append-only; idempotencia fica no resultado

`ai_runs` registra **toda** tentativa, inclusive falhas, sem indice unico. Isso permite reprocessar apos falha e preserva o rastro de auditoria.

A idempotencia exigida pelos criterios de aceitacao e garantida nas tabelas de resultado:

- `conversation_message_classifications` com unico `(conversation_message_id, purpose, prompt_version, schema_version)`.
- `conversation_insights` com unico `(source_message_id, extraction_version)`.

Assim "classificada uma unica vez por versao e finalidade" e "reexecucao idempotente nao duplica insight" sao garantias do banco, nao do codigo. Uma nova versao de prompt ou schema produz uma nova linha, que e o comportamento desejado para reprocessamento versionado.

### 4. `request_hash` serve para deduplicacao e diagnostico, nao para unicidade

O hash cobre finalidade, versao de prompt, versao de schema, modelo e o texto normalizado enviado. Ele permite detectar reenvio identico e correlacionar execucoes, mas nao e chave unica porque duas mensagens diferentes podem produzir o mesmo texto normalizado, e porque uma tentativa apos falha deve ser permitida.

### 5. Tema principal e relacional; a saida livre do modelo e preservada

`conversation_insights.insight_topic_id` e chave estrangeira para `insight_topics`. Os temas secundarios ficam na tabela pivo `conversation_insight_topics` com papel `main` ou `secondary`.

A string livre devolvida pelo modelo e preservada em `main_topic_raw` e `secondary_topics_raw` para auditoria e para reprocessar mapeamento sem nova chamada de IA. Filtro e relatorio usam as colunas relacionais, nunca o JSON.

### 6. Mapeamento de tema e deterministico e tem fallback obrigatorio

`InsightTopicMapper` normaliza a saida do modelo e compara com slug, nome e sinonimos cadastrados. Sem correspondencia, o insight recebe o tema de fallback (`is_fallback = true`), que o seeder garante existir e que nao pode ser excluido nem desativado.

O modelo nunca cria tema. A taxonomia e exclusivamente administrativa.

### 7. Prompts em arquivos versionados, versao ativa em configuracao

Os prompts vivem em `resources/ai/prompts/{purpose}/{version}.txt`, versionados junto do codigo e revisaveis em diff. A versao ativa por finalidade vem de `system_settings` (`ai.classification_prompt_version`, `ai.extraction_prompt_version`), o que permite promover ou reverter uma versao sem deploy.

Alternativa descartada: prompts em tabela. Ganharia edicao pela interface, mas perderia revisao por diff e permitiria alterar em producao um texto que define comportamento do sistema sem rastro no repositorio.

### 8. Schema JSON validado no servidor, sem confiar no provedor

`AiSchemaRegistry` define o schema por finalidade e versao. Ele e enviado ao provedor quando o provedor suporta saida estruturada, **e** aplicado novamente localmente por `AiResponseValidator` sobre a resposta recebida.

Validacao local cobre: JSON parseavel, campos obrigatorios presentes, tipos corretos, valores dentro do conjunto permitido para campos enumerados, confianca entre zero e um, limites de tamanho de string e de itens de lista, e recusa de campos desconhecidos.

Saida invalida nao altera estado: a execucao e gravada como `invalid_output`, o item vai para revisao humana e nenhum insight e criado.

### 9. Disjuntor simples baseado em cache

`AiCircuitBreaker` conta falhas consecutivas por provedor em cache. Ao atingir o limite configurado, abre por um periodo configuravel e as chamadas seguintes falham imediatamente com `CIRCUIT_OPEN`, sem rede. Um sucesso zera o contador.

Escolha deliberada: contador em cache, nao meia-abertura com amostragem probabilistica. E suficiente para proteger a fila de um provedor fora do ar e nao introduz nao determinismo em teste.

### 10. Contexto minimo enviado ao modelo

O prompt recebe apenas: a pergunta selecionada (snapshot congelado), a mensagem atual truncada em `ai.max_input_chars`, no maximo `ai.max_context_messages` mensagens imediatamente anteriores **da mesma conversa**, e a lista de temas cadastrados.

Nunca sao enviados: base de contatos, mensagens de outras conversas, nome, telefone, etiquetas, historico de campanhas ou qualquer atributo do contato. O montador de contexto nao tem acesso ao model `Contact`.

### 11. Revisao humana e deterministica, nunca decidida pela IA

Duas fontes marcam revisao:

- Confianca abaixo do threshold configurado por finalidade.
- `SensitiveContentDetector`, que aplica listas de expressoes configuraveis sobre o texto **original** para denuncia, ameaca, pedido pessoal, acusacao nominal, conteudo juridico sensivel, promessa, urgencia individual e risco.

O detector roda independentemente do resultado da IA e tambem quando a IA falha. O motivo da revisao e persistido e exibido, nunca apenas um booleano.

### 12. Correcao humana e auditada e nao retroalimenta o modelo

`conversation_insight_corrections` grava campo, valor original, valor corrigido, usuario, motivo e data. A correcao altera o insight vigente e mantem o valor anterior legivel.

Nenhuma correcao alimenta treinamento, ajuste de prompt ou exemplo few-shot automaticamente. Promover correcoes a exemplos exige criar uma nova versao de prompt no repositorio, revisada em diff.

### 13. Privacidade por separacao, nao por confianca no operador

`conversation_insights` guarda conteudo analitico e referencia o contato por chave estrangeira, mas as telas analiticas nunca exibem telefone completo sem `ai_insights.view_raw_contact_data`. `PhoneMasker` reaproveita o mascaramento ja usado nas conversas.

`ai.anonymize_reports` remove identificacao das visoes agregadas. `ai.runs_retention_days` define a retencao das execucoes, aplicada por comando. Log tecnico registra identificadores e codigos, nunca corpo de mensagem, telefone ou chave.

### 14. Reprocessamento e explicito e limitado

`ai:reprocess` aceita `--message`, `--conversation`, `--from` e `--to`, exige ao menos um filtro e pede confirmacao interativa quando o alcance excede `ai.reprocess_confirm_threshold`. Nao existe forma de reprocessar tudo sem confirmacao explicita, nem por padrao nem por flag isolada.

### 15. Fila propria, isolada da fila de mensagens recebidas

`ai-interpretation` e uma fila nova e separada. A avaliacao do fluxo 9A continua em `conversation-automation`. Um provedor de IA lento nunca atrasa o registro de mensagens recebidas nem a avaliacao deterministica.

O job usa trava por conversa, tentativas, backoff crescente e timeout maior que o timeout do provedor, para que um estouro de tempo do HTTP falhe antes do job.

## Alternativas descartadas

- **Chamar a IA dentro de `ProcessIncomingMessageJob`**: violaria a regra de nunca chamar servico externo perto do registro da mensagem e acoplaria a latencia do provedor ao recebimento.
- **Deixar a IA decidir opt-out ou permissao**: introduziria risco regulatorio e dependencia de terceiro em uma decisao que ja e correta e barata de forma deterministica.
- **Guardar temas apenas como JSON**: impediria filtro, agregacao e integridade referencial, contrariando a regra de banco do projeto.
- **Indice unico em `ai_runs` por mensagem e finalidade**: bloquearia nova tentativa apos falha e destruiria o rastro de auditoria das tentativas.
- **Biblioteca externa de validacao de JSON Schema**: dependencia adicional para um conjunto pequeno e fechado de schemas que controlamos; a validacao explicita e mais previsivel e testavel.

## Riscos

- **Qualidade da extracao depende do prompt e do modelo.** Mitigado por versao ativa configuravel, reprocessamento por versao e fila de revisao humana.
- **Custo por volume.** Mitigado por IA desligada por padrao, chamada evitada quando a regra deterministica conclui, contexto minimo, truncamento de entrada e registro de uso de tokens.
- **Provedor indisponivel.** Mitigado por disjuntor, tentativas com backoff e falha controlada que envia o item para revisao sem alterar estado.
- **Vies do modelo em tema politico.** Mitigado por instrucao explicita de nao produzir opiniao politica, nao inferir intencao de voto e nao inferir atributo sensivel, por schema fechado que nao possui campo para isso, e por revisao humana.
- **Reprocessamento em massa acidental.** Mitigado por exigencia de filtro, confirmacao acima do limite e registro em auditoria.
