# Interpretacao por IA — Etapa 9B

Camada de interpretacao das respostas da pesquisa conversacional. A IA **le, resume e categoriza**. Ela nao conversa, nao gera texto de resposta e nao envia nada.

## Principio central

A conversa bruta e a fonte primaria e imutavel. Todo resultado de IA e derivado, versionado, reprocessavel e descartavel. Se a camada inteira falhar, o comportamento observavel pelo contato e exatamente o da Etapa 9A.

## Feature flags

As chaves sao separadas por responsabilidade. Nenhuma chave mistura motor de fluxo, analise por IA e futura geracao de respostas.

| Chave | Responsabilidade | Padrao |
|---|---|---|
| `conversation_automation.enabled` | Motor deterministico da 9A | `0` |
| `conversation_automation.auto_send_enabled` | Envio das mensagens da 9A | `0` |
| `ai.enabled` | Chave mestra da infraestrutura de IA. Sozinha nao habilita nada | `0` |
| `ai.analysis_enabled` | Classificacao e extracao da 9B | `0` |
| `ai.classification_enabled` | Sub-chave da analise | `1` |
| `ai.extraction_enabled` | Sub-chave da analise | `1` |
| `ai.response_generation_enabled` | **Reservada para a 9C, nao implementada** | `0` |
| `ai.auto_send_enabled` | **Reservada para a 9C, nao implementada** | `0` |

Para ligar a analise sao necessarias **duas** chaves: `ai.enabled` **e** `ai.analysis_enabled`.

As duas chaves reservadas existem para que a separacao ja esteja explicita e auditavel. Nenhum caminho de codigo desta subetapa as consulta para decidir alguma coisa, e nenhum le-las como verdadeiro produz envio.

A 9B **nao** depende de `conversation_automation.enabled`. O que ela exige e contexto valido de pesquisa: a conversa precisa ter um `conversation_flow_state`. Uma conversa avulsa nunca e interpretada.

## Arquitetura e acoplamento

A 9A publica um evento de extensao e nao conhece a 9B:

```text
ConversationFlowService (9A)
        -> ConversationMessageEvaluated (evento, 9A)
                -> DispatchConversationInterpretation (ouvinte, 9B)
                        -> InterpretConversationMessageJob (9B)
```

`ConversationFlowService` nao referencia nenhuma classe de `App\Services\Ai` — ha teste que verifica isso lendo o proprio arquivo. Sem ouvintes registrados, o evento e um no-op e a 9A se comporta exatamente como antes.

O evento e publicado **depois** que todas as decisoes deterministicas foram tomadas, inclusive quando o motor da 9A esta desligado ou bloqueou a mensagem. Uma falha em ouvinte e reportada e nunca invalida o processamento ja concluido.

## Fluxo

```text
Mensagem recebida
        -> ProcessIncomingMessageJob grava a mensagem (Etapa 7)
        -> apos o commit: EvaluateConversationFlowJob (Etapa 9A, fila propria)
        -> decisoes deterministicas da 9A (permissao, pergunta, opt-out)
        -> evento ConversationMessageEvaluated
        -> InterpretConversationMessageJob (Etapa 9B, fila propria)
              guarda de interpretacao
              classificador deterministico
                  concluiu  -> grava e PARA (nenhuma chamada de IA)
                  nao concluiu -> classificacao por IA
              validacao do JSON contra o schema
              se question_answer ou resposta a pergunta -> extracao estruturada
              persistencia do insight e mapeamento de temas
              sinalizacao de revisao humana
```

Nenhuma resposta gerada e criada ou enviada nesta subetapa.

## Precedencia deterministica

Quando `PermissionResponseClassifier` (9A) corresponde a uma expressao de opt-out, positiva ou negativa, a classificacao e gravada com origem `deterministic` e confianca `1.0`, e **o caminho de codigo nao chega ao provedor**. Isso torna a garantia estrutural, nao configuravel.

Mensagens com midia ou sem texto viram `media_or_unsupported` sem chamada externa.

## Tabelas

```text
ai_runs                                log append-only de cada tentativa
conversation_message_classifications   classificacao por mensagem, unica por versao
conversation_insights                  insight derivado, unico por mensagem e versao
insight_topics                         taxonomia administrativa
conversation_insight_topics            vinculo relacional de temas (main | secondary)
conversation_insight_corrections       historico imutavel de correcoes humanas
```

Idempotencia por indice unico:

```text
cmc_message_purpose_version_uniq  (conversation_message_id, purpose, prompt_version, schema_version)
ci_message_version_uniq           (source_message_id, extraction_version)
```

`ai_runs` **nao** tem indice unico: ele registra toda tentativa, inclusive falhas, para permitir nova tentativa sem destruir o rastro.

## Categorias

```text
permission_yes          permission_no           opt_out
question_answer         asks_for_clarification  asks_about_norma
off_topic               human_requested         complaint
sensitive_report        insult_or_abuse         media_or_unsupported
ambiguous
```

## Provedor

```text
App\Contracts\AiProvider                          contrato independente de fornecedor
App\Services\Ai\Providers\OpenAiCompatibleProvider APIs de chat no formato OpenAI
App\Services\Ai\Providers\NullAiProvider           inerte, sem chamada de rede
App\Services\Ai\AiProviderManager                  resolve pelo config
App\Services\Ai\AiClient                           disjuntor, tentativas, validacao e registro
```

Codigos de erro: `TIMEOUT`, `RATE_LIMITED`, `UNAUTHORIZED`, `SERVICE_UNAVAILABLE`, `INVALID_RESPONSE`, `CIRCUIT_OPEN`, `NOT_CONFIGURED`.

Sao repetidos com backoff apenas `TIMEOUT`, `RATE_LIMITED` e `SERVICE_UNAVAILABLE`. `INVALID_RESPONSE` e problema de conteudo e nao conta para o disjuntor.

## Configuracao de ambiente

Chave, URL e modelo ficam apenas em `.env`, nunca no banco.

```env
AI_PROVIDER=null            # `openai` para ativar; `null` mantem a camada inerte
AI_OPENAI_URL=https://api.openai.com/v1
AI_OPENAI_KEY=
AI_OPENAI_MODEL=gpt-4o-mini
AI_OPENAI_ORGANIZATION=
AI_TIMEOUT=30
AI_CONNECT_TIMEOUT=5
AI_MAX_OUTPUT_TOKENS=900
AI_TEMPERATURE=0
AI_COST_INPUT_PER_1K=
AI_COST_OUTPUT_PER_1K=
```

`AI_PROVIDER=openai` funciona com qualquer servico compativel: OpenAI, Azure OpenAI, OpenRouter, Groq, Ollama ou vLLM local. Basta trocar `AI_OPENAI_URL` e `AI_OPENAI_MODEL`.

## Configuracoes operacionais

Ficam em `system_settings` e sao editaveis sem deploy. As chaves de habilitacao estao descritas na secao de feature flags.

```text
ai.queue                          ai-interpretation
ai.classification_prompt_version  v1
ai.extraction_prompt_version      v1
ai.classification_schema_version  1
ai.extraction_schema_version      1
ai.min_classification_confidence  0.70
ai.min_extraction_confidence      0.65
ai.max_input_chars                2000
ai.max_context_messages           3
ai.max_attempts                   3
ai.retry_backoff_ms               500
ai.circuit_failure_threshold      5
ai.circuit_open_seconds           300
ai.stuck_run_minutes              15
ai.runs_retention_days            90
ai.anonymize_reports              1
ai.reprocess_confirm_threshold    50
ai.expressions.*                  listas de deteccao de conteudo sensivel
```

Apos alterar pelo seeder, limpe o cache:

```bash
php artisan cache:clear
```

## Fila

```text
ai-interpretation
```

Isolada da fila de mensagens recebidas e da fila da 9A. Adicionar ao worker:

```bash
php artisan queue:work --queue=whatsapp-incoming,whatsapp-messages,whatsapp-manual-replies,whatsapp-conversation-sync,whatsapp-maintenance,conversation-automation,conversation-automation-send,ai-interpretation,default
```

O job usa trava por conversa, tres tentativas, backoff `30/120/300` e timeout de 180 segundos, maior que o timeout do provedor.

## Prompts versionados

```text
resources/ai/prompts/classification/v1.txt
resources/ai/prompts/extraction/v1.txt
```

Ficam no repositorio para serem revisaveis em diff. A versao ativa vem de `system_settings`, o que permite promover ou reverter sem deploy. Para criar a `v2`, adicione o arquivo e altere a configuracao correspondente.

Os prompts instruem explicitamente o modelo a nao produzir opiniao politica, nao persuadir, nao inferir intencao de voto e nao inferir atributo sensivel.

## Schemas

`AiSchemaRegistry` define o schema por finalidade e versao. Ele e enviado ao provedor **e** aplicado de novo localmente por `AiResponseValidator`, que verifica JSON parseavel, campos obrigatorios, tipos, valores enumerados, limites de tamanho e recusa campos desconhecidos.

Nao existe campo para renda, religiao, raca, saude, orientacao politica ou intencao de voto. A ausencia do campo e a garantia estrutural.

## Taxonomia

Temas e subtemas administrados em `/admin/insight-topics`, com sinonimos separados por barra vertical, ordenacao, cor de interface e ativo/inativo.

- O modelo nunca cria tema.
- Saida nao reconhecida cai no tema de fallback (`outros`), que nao pode ser excluido nem desativado.
- Tema ja utilizado por um insight nao pode ser excluido, apenas desativado.
- A string livre do modelo fica preservada em `main_topic_raw` e `secondary_topics_raw`.

## Revisao humana

Duas fontes, ambas deterministicas:

1. Confianca abaixo de `ai.min_classification_confidence` ou `ai.min_extraction_confidence`.
2. `SensitiveContentDetector`, aplicado sobre o texto **original**, para risco, ameaca, denuncia, acusacao nominal, conteudo juridico sensivel, pedido de promessa, pedido pessoal, urgencia individual, pedido de atendimento humano, ofensa e reclamacao.

O detector roda tambem quando a IA falha ou devolve saida invalida. O motivo fica persistido e visivel.

O unico efeito da interpretacao sobre o fluxo 9A e marcar `needs_human_review` no estado da conversa. O estagio continua sendo decidido apenas pelas regras deterministicas.

## Correcao humana

Em `/admin/ai-insights/{id}` o operador corrige resumo, problema, acao, resultado, grupo afetado, localidade, regiao, urgencia, sentimento, tema e classificacao.

Cada campo alterado gera uma linha em `conversation_insight_corrections` com valor original, valor corrigido, usuario, motivo e data. Nenhuma correcao alimenta treinamento ou prompt automaticamente: promover uma correcao exige uma nova versao de prompt no repositorio.

## Privacidade e LGPD

- Contexto minimo: apenas a pergunta, a mensagem truncada, poucas mensagens da **mesma** conversa e a taxonomia.
- `AiContextBuilder` nao acessa o model `Contact`: nome, telefone, etiquetas e historico nunca entram no prompt.
- Telas analiticas mascaram o telefone sem `ai_insights.view_contact_data`.
- `ai.anonymize_reports` remove identificacao das visoes agregadas.
- `ai.runs_retention_days` define a retencao das execucoes, aplicada por comando.
- Log tecnico registra identificadores, codigos e latencia; nunca chave, telefone ou corpo de mensagem.
- Localidade so e registrada quando declarada pelo contato. Nunca inferida.

## Permissoes

```text
ai_insights.view
ai_insights.view_contact_data
ai_insights.correct
ai_insights.reprocess
ai_insights.manage_taxonomy
ai_insights.view_monitoring
```

- Administrador: todas.
- Operador: `view` e `correct`.
- Consulta: apenas `view`.

## Rotas

```text
/admin/ai-insights
/admin/ai-insights/{insight}
PUT  /admin/ai-insights/{insight}
POST /admin/ai-insights/{insight}/approve
POST /admin/ai-insights/{insight}/reprocess
/admin/ai-monitoring
/admin/insight-topics
```

## Monitoramento

`/admin/ai-monitoring` mostra volume, sucesso, falha, saida invalida, latencia media e maxima, tokens, custo estimado, baixa confianca, itens aguardando revisao, falhas por codigo e por provedor, execucoes presas e estado do disjuntor.

## Comandos

```bash
# Reprocessamento seguro: exige ao menos um filtro.
php artisan ai:reprocess --message=123
php artisan ai:reprocess --conversation=45
php artisan ai:reprocess --from=2026-07-01 --to=2026-07-15 --dry-run

# Retencao das execucoes.
php artisan ai:prune-runs
php artisan ai:prune-runs --days=30 --dry-run
```

`ai:reprocess` sem filtro **falha**. Acima de `ai.reprocess_confirm_threshold` pede confirmacao interativa. Nao existe forma de reprocessar tudo sem confirmacao explicita.

## Onde exatamente esta a garantia de idempotencia

Sao tres mecanismos em camadas. O ultimo e o que vale sob concorrencia real.

| Camada | Mecanismo | O que garante |
|---|---|---|
| 1. Fila | `Cache::lock("ai-interpretation:{conversation_id}")` no job, 180 s | Dois workers nao interpretam a mesma conversa ao mesmo tempo |
| 2. Servico | Consulta previa em `MessageClassificationService::existing()` e em `InsightExtractionService::extract()` | Reexecucao nao chama o provedor de novo, economizando tokens |
| 3. Banco | `cmc_message_purpose_version_uniq` e `ci_message_version_uniq` | Garantia final: dois workers nunca criam dois resultados correntes |

A camada 3 e a unica que continua valendo se o cache cair ou se houver corrida entre a consulta e a escrita. Os servicos capturam `UniqueConstraintViolationException` e devolvem a linha existente, e `InsightExtractionService` grava dentro de `DB::transaction` junto com os vinculos de tema.

`ai_runs` **nao** participa da idempotencia de proposito: e log append-only. Um retry do provedor cria uma nova linha com `attempt` incrementado, e a tentativa anterior permanece intacta com seu `error_code`. Reprocessar com uma versao nova de prompt ou schema cria um novo resultado corrente para aquela versao, e o resultado da versao anterior continua legivel como historico.

Chave logica do resultado corrente:

```text
classificacao: (conversation_message_id, purpose, prompt_version, schema_version)
insight:       (source_message_id, extraction_version)
```

## Garantias

- **Idempotencia**: ver a tabela acima.
- **Concorrencia**: trava por conversa no job e indice unico como garantia final.
- **JSON invalido nao altera estado**: execucao marcada como `invalid_output`, nenhum insight criado, item para revisao.
- **Opt-out nunca depende da IA**: o caminho de codigo nao passa pelo provedor.
- **Nenhum envio**: a camada nao cria mensagem de saida em nenhum caminho.
- **Auditavel**: `ai_runs`, `conversation_insight_corrections`, `conversation_events` e `audit_logs`.

## Nao implementado nesta subetapa

- Geracao de resposta contextual e autoenvio.
- RAG, embeddings e busca por similaridade.
- Dashboards analiticos completos.
- Inferencia de atributo sensivel e microdirecionamento individual.
- Treinamento ou ajuste automatico a partir de correcoes.

## Banco de dados e rollback

Validado em MariaDB 10.5 real (banco `staging`), com ciclo completo de aplicacao, rollback e reaplicacao das migrations da 9A e da 9B.

```text
maior nome de indice   57 de 64 permitidos
maior nome de FK       60 de 64 permitidos
charset                utf8mb4 em todas as tabelas
engine                 InnoDB em todas as tabelas
```

MariaDB nao possui tipo JSON nativo: as colunas `json` viram `longtext` com restricao de validacao. O cast `array` do Eloquent funciona normalmente.

Rollback das duas subetapas, na ordem inversa:

```bash
# Remove apenas a 9B.
php artisan migrate:rollback --step=1 --force

# Remove tambem a 9A.
php artisan migrate:rollback --step=2 --force
```

O `down()` da 9B derruba as seis tabelas na ordem correta das dependencias. O `down()` da 9A remove a coluna `origin` de `conversation_messages` e a associacao de fluxo em `message_batches` pelo nome explicito da chave estrangeira. Insights e classificacoes sao dados derivados: descarta-los nao afeta o historico de conversas.

## Solucao de problemas

- **Nada acontece**: confirmar **as duas** chaves `ai.enabled` e `ai.analysis_enabled`, o worker da fila `ai-interpretation` e a existencia de estado de fluxo na conversa.
- **Evento `ai_interpretation_blocked` com motivo `sem_contexto_de_pesquisa`**: a conversa nao tem `conversation_flow_state`; a 9B so interpreta respostas de pesquisa.
- **Evento com motivo `analise_desabilitada`**: `ai.enabled` esta ligada mas `ai.analysis_enabled` nao.
- **`NOT_CONFIGURED` nas execucoes**: `AI_PROVIDER` esta em `null` ou `AI_OPENAI_KEY` esta vazia.
- **`CIRCUIT_OPEN`**: o provedor falhou consecutivamente; aguarde `ai.circuit_open_seconds` ou corrija a causa.
- **Muita saida invalida**: revisar a versao do prompt e o modelo configurado; modelos pequenos falham mais em saida estruturada.
- **Tudo caindo em revisao**: thresholds altos demais, ou listas de expressoes sensiveis amplas demais.
- **Tema sempre `outros`**: cadastrar sinonimos que correspondam ao vocabulario devolvido pelo modelo.
