# Formulas dos relatorios — Subetapa 9E

Cada taxa declara numerador, denominador e exclusoes. Percentual sem denominador
visivel esconde o tamanho da amostra: "100% de aprovacao" sobre duas sugestoes e
verdadeiro e inutil, e a diferenca so aparece quando o par esta a vista.

**Regra geral:** denominador zero nao produz zero por cento. Produz um traco.

## Participacao

Fonte: `conversation_flow_states`, filtrado por `started_at` dentro do periodo.

Os estagios terminais nao voltam atras, entao contar pelo estado atual e
equivalente a contar por evento.

| Metrica | Numerador | Denominador | Exclusoes |
| --- | --- | --- | --- |
| Contatos abordados | — | — | Contagem simples de estados iniciados no periodo |
| Taxa de permissao | Estados em `permission_granted`, `question_selected`, `waiting_answer`, `answer_received` ou `completed` | Os mesmos mais `permission_denied` mais `opted_out` | **Quem ainda nao respondeu ao pedido.** Contar silencio como recusa produziria uma taxa que so cai com o tempo |
| Taxa de resposta | Estados em `answer_received` ou `completed` | Estados que concederam permissao | Quem nunca autorizou |
| Taxa de conclusao | Estados em `completed` | Abordados | Nenhuma |
| Taxa de opt-out | Estados em `opted_out` | Abordados | Nenhuma |
| Taxa de handoff | Estados em `waiting_human` | Abordados | Nenhuma |
| Media de turnos | Soma de `automated_messages_count` | Estados com ao menos uma mensagem automatica | Conversas que nunca receberam mensagem automatica |

### Tempo ate a primeira resposta

Do `started_at` do estado ate a primeira mensagem recebida daquela conversa
depois desse instante.

- **Numerador:** soma dos segundos.
- **Denominador:** conversas que responderam ao menos uma vez.
- **Exclusao:** conversas sem nenhuma resposta. Conta-las como zero puxaria a
  media para baixo; como infinito, para cima. Ficam de fora e o numero de
  amostras aparece ao lado da media.

## Temas

Fonte: `conversation_insights` cruzado com `insight_topics`.

| Metrica | Definicao |
| --- | --- |
| Mencoes por tema | Contagem de insights com aquele `insight_topic_id` |
| Confianca media | Media de `confidence` dentro do tema |
| Revisados | Insights com `reviewed = 1` |
| Nao classificados | Insights com `insight_topic_id` nulo. Contados a parte, nunca somados a "outros" |
| Tema emergente | Tema com mencoes no periodo atual, nenhuma no anterior e ao menos `analytics.emerging_topic_min_mentions` mencoes |

## Qualidade da IA

Fonte: `conversation_reply_suggestions` e `ai_runs`.

| Metrica | Numerador | Denominador | Exclusoes |
| --- | --- | --- | --- |
| Aprovacao sem edicao | Sugestoes aprovadas ou enviadas com `final_text` nulo ou igual a `generated_text` | Sugestoes aprovadas ou enviadas | Pendentes, expiradas, bloqueadas |
| Recusa | Sugestoes recusadas | Sugestoes que alguem decidiu (aprovadas, enviadas ou recusadas) | Pendentes e expiradas: ninguem decidiu |
| Handoff | Sugestoes com `requires_human_review` | Todas as sugestoes do periodo | Nenhuma |
| Falha por execucao | `ai_runs` com `status = failed` | `ai_runs` do mesmo grupo de provedor, modelo e versao | Nenhuma |
| Correcao de classificacao | Insights revisados com `review_reason` preenchido | Insights revisados | **Insights que ninguem olhou.** Nao foram corrigidos nem confirmados |

## Qualidade das perguntas

Fonte: `conversation_flow_states` agrupado por `selected_question_id`.

| Metrica | Numerador | Denominador |
| --- | --- | --- |
| Taxa de resposta da pergunta | Estados em `answer_received` ou `completed` | Estados em que aquela pergunta foi selecionada |
| Taxa de conclusao da pergunta | Estados em `completed` | Idem |
| Frequencia de handoff | Estados em `waiting_human` | Idem |
| Tamanho medio da resposta | Media de caracteres do `summary` do insight | Insights daquela pergunta |

Nao existe metrica de efeito persuasivo, apoio declarado ou intencao de voto, e
nao ha ordenacao por nada disso. O que estas colunas levam a fazer e reescrever
a pergunta.

## Supressao de grupo pequeno

Celula com menos de `analytics.minimum_cell_size` registros aparece como
suprimida em vez de mostrar a contagem.

Vale para geografia, temas, demandas e qualidade das perguntas. Zero nunca e
suprimido: nao identifica ninguem, e esconder ausencia de dado apagaria a
informacao mais importante da tela.

A linha suprimida continua na lista, marcada. Remove-la faria a soma das linhas
visiveis nao bater com o total.

## Onde os numeros sao materializados

`conversation_daily_metrics` guarda uma linha por dia e por fluxo, mais uma
linha de total do dia. Reconstruida por `analytics:rebuild-metrics`, com chave
natural `(dia, fluxo)` — rodar duas vezes produz o mesmo estado.

Tema, geografia, demanda e qualidade ficam em consulta ao vivo, porque sao
recortados de muitas formas e mudam quando alguem corrige uma classificacao.
