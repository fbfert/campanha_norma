# Interpretação por IA — Etapa 9B

Camada de interpretação das respostas da pesquisa conversacional. A IA **le, resume e categoriza**. Ela não conversa, não gera texto de resposta e não envia nada.

## Princípio central

A conversa bruta e a fonte primária e imutável. Todo resultado de IA e derivado, versionado, reprocessável e descartável. Se a camada inteira falhar, o comportamento observável pelo contato e exatamente o da Etapa 9A.

## Feature flags

As chaves são separadas por responsabilidade. Nenhuma chave mistura motor de fluxo, análise por IA e futura geração de respostas.

| Chave | Responsabilidade | Padrão |
|---|---|---|
| `conversation_automation.enabled` | Motor determinístico da 9A | `0` |
| `conversation_automation.auto_send_enabled` | Envio das mensagens da 9A | `0` |
| `ai.enabled` | Chave mestra da infraestrutura de IA. Sozinha não habilita nada | `0` |
| `ai.analysis_enabled` | Classificação e extração da 9B | `0` |
| `ai.classification_enabled` | Sub-chave da análise | `1` |
| `ai.extraction_enabled` | Sub-chave da análise | `1` |
| `ai.response_generation_enabled` | **Reservada para a 9C, não implementada** | `0` |
| `ai.auto_send_enabled` | **Reservada para a 9C, não implementada** | `0` |

Para ligar a análise são necessárias **duas** chaves: `ai.enabled` **e** `ai.analysis_enabled`.

As duas chaves reservadas existem para que a separação já esteja explícita e auditável. Nenhum caminho de código desta subetapa as consulta para decidir alguma coisa, e nenhum le-las como verdadeiro produz envio.

A 9B **não** depende de `conversation_automation.enabled`. O que ela exige e contexto valido de pesquisa: a conversa precisa ter um `conversation_flow_state`. Uma conversa avulsa nunca e interpretada.

## Arquitetura e acoplamento

A 9A pública um evento de extensão e não conhece a 9B:

```text
ConversationFlowService (9A)
        -> ConversationMessageEvaluated (evento, 9A)
                -> DispatchConversationInterpretation (ouvinte, 9B)
                        -> InterpretConversationMessageJob (9B)
```

`ConversationFlowService` não referência nenhuma classe de `App\Services\Ai` — ha teste que verifica isso lendo o próprio arquivo. Sem ouvintes registrados, o evento e um no-op e a 9A se comporta exatamente como antes.

O evento e publicado **depois** que todas as decisões deterministicas foram tomadas, inclusive quando o motor da 9A esta desligado ou bloqueou a mensagem. Uma falha em ouvinte e reportada e nunca invalida o processamento já concluído.

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

## Precedência determinística

Quando `PermissionResponseClassifier` (9A) corresponde a uma expressão de opt-out, positiva ou negativa, a classificação e gravada com origem `deterministic` e confiança `1.0`, e **o caminho de código não chega ao provedor**. Isso torna a garantia estrutural, não configurável.

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

Idempotência por índice único:

```text
cmc_message_purpose_version_uniq  (conversation_message_id, purpose, prompt_version, schema_version)
ci_message_version_uniq           (source_message_id, extraction_version)
```

`ai_runs` **não** tem índice único: ele registra toda tentativa, inclusive falhas, para permitir nova tentativa sem destruir o rastro.

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

Códigos de erro: `TIMEOUT`, `RATE_LIMITED`, `UNAUTHORIZED`, `SERVICE_UNAVAILABLE`, `INVALID_RESPONSE`, `CIRCUIT_OPEN`, `NOT_CONFIGURED`.

São repetidos com backoff apenas `TIMEOUT`, `RATE_LIMITED` e `SERVICE_UNAVAILABLE`. `INVALID_RESPONSE` e problema de conteúdo e não conta para o disjuntor.

## Configuração de ambiente

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

`AI_PROVIDER=openai` funciona com qualquer serviço compatível: OpenAI, Azure OpenAI, OpenRouter, Groq, Ollama ou vLLM local. Basta trocar `AI_OPENAI_URL` e `AI_OPENAI_MODEL`.

## Configurações operacionais

Ficam em `system_settings` e são editáveis sem deploy. As chaves de habilitação estão descritas na seção de feature flags.

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

Após alterar pelo seeder, limpe o cache:

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

O job usa trava por conversa, três tentativas, backoff `30/120/300` e timeout de 180 segundos, maior que o timeout do provedor.

## Prompts versionados

```text
resources/ai/prompts/classification/v1.txt
resources/ai/prompts/extraction/v1.txt
```

Ficam no repositório para serem revisáveis em diff. A versão ativa vem de `system_settings`, o que permite promover ou reverter sem deploy. Para criar a `v2`, adicione o arquivo e altere a configuração correspondente.

Os prompts instruem explicitamente o modelo a não produzir opinião política, não persuadir, não inferir intenção de voto e não inferir atributo sensível.

## Schemas

`AiSchemaRegistry` define o schema por finalidade e versão. Ele e enviado ao provedor **e** aplicado de novo localmente por `AiResponseValidator`, que verifica JSON parseável, campos obrigatórios, tipos, valores enumerados, limites de tamanho e recusa campos desconhecidos.

Não existe campo para renda, religião, raca, saúde, orientação política ou intenção de voto. A ausência do campo e a garantia estrutural.

## Taxonomia

Temas e subtemas administrados em `/admin/insight-topics`, com sinônimos separados por barra vertical, ordenação, cor de interface e ativo/inativo.

- O modelo nunca cria tema.
- Saída não reconhecida cai no tema de fallback (`outros`), que não pode ser excluído nem desativado.
- Tema já utilizado por um insight não pode ser excluído, apenas desativado.
- A string livre do modelo fica preservada em `main_topic_raw` e `secondary_topics_raw`.

## Revisão humana

Duas fontes, ambas deterministicas:

1. Confiança abaixo de `ai.min_classification_confidence` ou `ai.min_extraction_confidence`.
2. `SensitiveContentDetector`, aplicado sobre o texto **original**, para risco, ameaca, denuncia, acusação nominal, conteúdo juridico sensível, pedido de promessa, pedido pessoal, urgência individual, pedido de atendimento humano, ofensa e reclamação.

O detector roda também quando a IA falha ou devolve saída invalida. O motivo fica persistido e visível.

O único efeito da interpretação sobre o fluxo 9A e marcar `needs_human_review` no estado da conversa. O estagio continua sendo decidido apenas pelas regras deterministicas.

## Correção humana

Em `/admin/ai-insights/{id}` o operador corrige resumo, problema, ação, resultado, grupo afetado, localidade, região, urgência, sentimento, tema e classificação.

Cada campo alterado gera uma linha em `conversation_insight_corrections` com valor original, valor corrigido, usuário, motivo e data. Nenhuma correção alimenta treinamento ou prompt automaticamente: promover uma correção exige uma nova versão de prompt no repositório.

## Privacidade e LGPD

- Contexto mínimo: apenas a pergunta, a mensagem truncada, poucas mensagens da **mesma** conversa e a taxonomia.
- `AiContextBuilder` não acessa o model `Contact`: nome, telefone, etiquetas e histórico nunca entram no prompt.
- Telas analíticas mascaram o telefone sem `ai_insights.view_contact_data`.
- `ai.anonymize_reports` remove identificação das visões agregadas.
- `ai.runs_retention_days` define a retenção das execuções, aplicada por comando.
- Log técnico registra identificadores, códigos e latência; nunca chave, telefone ou corpo de mensagem.
- Localidade so e registrada quando declarada pelo contato. Nunca inferida.

## Permissões

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

`/admin/ai-monitoring` mostra volume, sucesso, falha, saída invalida, latência média e máxima, tokens, custo estimado, baixa confiança, itens aguardando revisão, falhas por código e por provedor, execuções presas e estado do disjuntor.

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

`ai:reprocess` sem filtro **falha**. Acima de `ai.reprocess_confirm_threshold` pede confirmação interativa. Não existe forma de reprocessar tudo sem confirmação explícita.

## Onde exatamente esta a garantia de idempotência

São três mecanismos em camadas. O último e o que vale sob concorrência real.

| Camada | Mecanismo | O que garante |
|---|---|---|
| 1. Fila | `Cache::lock("ai-interpretation:{conversation_id}")` no job, 180 s | Dois workers não interpretam a mesma conversa ao mesmo tempo |
| 2. Serviço | Consulta previa em `MessageClassificationService::existing()` e em `InsightExtractionService::extract()` | Reexecução não chama o provedor de novo, economizando tokens |
| 3. Banco | `cmc_message_purpose_version_uniq` e `ci_message_version_uniq` | Garantia final: dois workers nunca criam dois resultados correntes |

A camada 3 e a única que continua valendo se o cache cair ou se houver corrida entre a consulta e a escrita. Os serviços capturam `UniqueConstraintViolationException` e devolvem a linha existente, e `InsightExtractionService` grava dentro de `DB::transaction` junto com os vínculos de tema.

`ai_runs` **não** participa da idempotência de propósito: e log append-only. Um retry do provedor cria uma nova linha com `attempt` incrementado, e a tentativa anterior permanece intacta com seu `error_code`. Reprocessar com uma versão nova de prompt ou schema cria um novo resultado corrente para aquela versão, e o resultado da versão anterior continua legível como histórico.

Chave lógica do resultado corrente:

```text
classificacao: (conversation_message_id, purpose, prompt_version, schema_version)
insight:       (source_message_id, extraction_version)
```

## Garantias

- **Idempotência**: ver a tabela acima.
- **Concorrência**: trava por conversa no job e índice único como garantia final.
- **JSON inválido não altera estado**: execução marcada como `invalid_output`, nenhum insight criado, item para revisão.
- **Opt-out nunca depende da IA**: o caminho de código não passa pelo provedor.
- **Nenhum envio**: a camada não cria mensagem de saída em nenhum caminho.
- **Auditável**: `ai_runs`, `conversation_insight_corrections`, `conversation_events` e `audit_logs`.

## Não implementado nesta subetapa

- Geração de resposta contextual e autoenvio.
- RAG, embeddings e busca por similaridade.
- Dashboards analíticos completos.
- Inferência de atributo sensível e microdirecionamento individual.
- Treinamento ou ajuste automático a partir de correções.

## Banco de dados e rollback

Validado em MariaDB 10.5 real (banco `staging`), com ciclo completo de aplicação, rollback e reaplicação das migrations da 9A e da 9B.

```text
maior nome de indice   57 de 64 permitidos
maior nome de FK       60 de 64 permitidos
charset                utf8mb4 em todas as tabelas
engine                 InnoDB em todas as tabelas
```

MariaDB não possui tipo JSON nativo: as colunas `json` viram `longtext` com restrição de validação. O cast `array` do Eloquent funciona normalmente.

Rollback das duas subetapas, na ordem inversa:

```bash
# Remove apenas a 9B.
php artisan migrate:rollback --step=1 --force

# Remove tambem a 9A.
php artisan migrate:rollback --step=2 --force
```

O `down()` da 9B derruba as seis tabelas na ordem correta das dependências. O `down()` da 9A remove a coluna `origin` de `conversation_messages` e a associação de fluxo em `message_batches` pelo nome explícito da chave estrangeira. Insights e classificações são dados derivados: descarta-los não afeta o histórico de conversas.

## Solução de problemas

- **Nada acontece**: confirmar **as duas** chaves `ai.enabled` e `ai.analysis_enabled`, o worker da fila `ai-interpretation` e a existência de estado de fluxo na conversa.
- **Evento `ai_interpretation_blocked` com motivo `sem_contexto_de_pesquisa`**: a conversa não tem `conversation_flow_state`; a 9B so interpreta respostas de pesquisa.
- **Evento com motivo `analise_desabilitada`**: `ai.enabled` esta ligada mas `ai.analysis_enabled` não.
- **`NOT_CONFIGURED` nas execuções**: `AI_PROVIDER` esta em `null` ou `AI_OPENAI_KEY` esta vazia.
- **`CIRCUIT_OPEN`**: o provedor falhou consecutivamente; aguarde `ai.circuit_open_seconds` ou corrija a causa.
- **Muita saída invalida**: revisar a versão do prompt e o modelo configurado; modelos pequenos falham mais em saída estruturada.
- **Tudo caindo em revisão**: thresholds altos demais, ou listas de expressões sensíveis amplas demais.
- **Tema sempre `outros`**: cadastrar sinônimos que correspondam ao vocabulário devolvido pelo modelo.
