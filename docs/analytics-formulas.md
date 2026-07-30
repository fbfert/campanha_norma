# Fórmulas dos relatórios — Subetapa 9E

Cada taxa declara numerador, denominador e exclusões. Percentual sem denominador
visível esconde o tamanho da amostra: "100% de aprovação" sobre duas sugestões e
verdadeiro e inutil, e a diferença so aparece quando o par esta a vista.

**Regra geral:** denominador zero não produz zero por cento. Produz um traço.

## Participação

Fonte: `conversation_flow_states`, filtrado por `started_at` dentro do período.

Os estagios terminais não voltam atrás, então contar pelo estado atual e
equivalente a contar por evento.

| Métrica | Numerador | Denominador | Exclusões |
| --- | --- | --- | --- |
| Contatos abordados | — | — | Contagem simples de estados iniciados no período |
| Taxa de permissão | Estados em `permission_granted`, `question_selected`, `waiting_answer`, `answer_received` ou `completed` | Os mesmos mais `permission_denied` mais `opted_out` | **Quem ainda não respondeu ao pedido.** Contar silêncio como recusa produziria uma taxa que so cai com o tempo |
| Taxa de resposta | Estados em `answer_received` ou `completed` | Estados que concederam permissão | Quem nunca autorizou |
| Taxa de conclusão | Estados em `completed` | Abordados | Nenhuma |
| Taxa de opt-out | Estados em `opted_out` | Abordados | Nenhuma |
| Taxa de handoff | Estados em `waiting_human` | Abordados | Nenhuma |
| Média de turnos | Soma de `automated_messages_count` | Estados com ao menos uma mensagem automática | Conversas que nunca receberam mensagem automática |

### Tempo até a primeira resposta

Do `started_at` do estado até a primeira mensagem recebida daquela conversa
depois desse instante.

- **Numerador:** soma dos segundos.
- **Denominador:** conversas que responderam ao menos uma vez.
- **Exclusão:** conversas sem nenhuma resposta. Conta-las como zero puxaria a
  média para baixo; como infinito, para cima. Ficam de fora e o número de
  amostras aparece ao lado da média.

## Temas

Fonte: `conversation_insights` cruzado com `insight_topics`.

| Métrica | Definição |
| --- | --- |
| Menções por tema | Contagem de insights com aquele `insight_topic_id` |
| Confiança média | Média de `confidence` dentro do tema |
| Revisados | Insights com `reviewed = 1` |
| Não classificados | Insights com `insight_topic_id` nulo. Contados a parte, nunca somados a "outros" |
| Tema emergente | Tema com menções no período atual, nenhuma no anterior e ao menos `analytics.emerging_topic_min_mentions` menções |

## Qualidade da IA

Fonte: `conversation_reply_suggestions` e `ai_runs`.

| Métrica | Numerador | Denominador | Exclusões |
| --- | --- | --- | --- |
| Aprovação sem edição | Sugestões aprovadas ou enviadas com `final_text` nulo ou igual a `generated_text` | Sugestões aprovadas ou enviadas | Pendentes, expiradas, bloqueadas |
| Recusa | Sugestões recusadas | Sugestões que alguém decidiu (aprovadas, enviadas ou recusadas) | Pendentes e expiradas: ninguém decidiu |
| Handoff | Sugestões com `requires_human_review` | Todas as sugestões do período | Nenhuma |
| Falha por execução | `ai_runs` com `status = failed` | `ai_runs` do mesmo grupo de provedor, modelo e versão | Nenhuma |
| Correção de classificação | Insights revisados com `review_reason` preenchido | Insights revisados | **Insights que ninguém olhou.** Não foram corrigidos nem confirmados |

## Qualidade das perguntas

Fonte: `conversation_flow_states` agrupado por `selected_question_id`.

| Métrica | Numerador | Denominador |
| --- | --- | --- |
| Taxa de resposta da pergunta | Estados em `answer_received` ou `completed` | Estados em que aquela pergunta foi selecionada |
| Taxa de conclusão da pergunta | Estados em `completed` | Idem |
| Frequência de handoff | Estados em `waiting_human` | Idem |
| Tamanho médio da resposta | Média de caracteres do `summary` do insight | Insights daquela pergunta |

Não existe métrica de efeito persuasivo, apoio declarado ou intenção de voto, e
não ha ordenação por nada disso. O que estas colunas levam a fazer e reescrever
a pergunta.

## Supressão de grupo pequeno

Célula com menos de `analytics.minimum_cell_size` registros aparece como
suprimida em vez de mostrar a contagem.

Vale para geografia, temas, demandas e qualidade das perguntas. Zero nunca e
suprimido: não identifica ninguém, e esconder ausência de dado apagaria a
informação mais importante da tela.

A linha suprimida continua na lista, marcada. Remove-la faria a soma das linhas
visíveis não bater com o total.

## Onde os números são materializados

`conversation_daily_metrics` guarda uma linha por dia e por fluxo, mais uma
linha de total do dia. Reconstruida por `analytics:rebuild-metrics`, com chave
natural `(dia, fluxo)` — rodar duas vezes produz o mesmo estado.

Tema, geografia, demanda e qualidade ficam em consulta ao vivo, porque são
recortados de muitas formas e mudam quando alguém corrige uma classificação.
