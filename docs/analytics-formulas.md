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

---

# Fórmulas da pauta — Subetapa 9F

## Pontuação de prioridade da fila

A fila de resposta é ordenada por uma pontuação, e a pontuação **ordena sem
descartar**: toda pessoa da fila é para responder, e o número só decide quem vem
antes. Nada é escondido por prioridade baixa.

```
prioridade = peso_urgencia × urgencia
           + peso_tamanho  × tamanho
           + peso_emergente × emergente
```

| Parcela | Valores | De onde vem |
|---|---|---|
| `urgencia` | alta = 2, média = 1, baixa ou ausente = 0 | `conversation_insights.urgency` |
| `tamanho` | acima de 240 caracteres = 2, acima de 80 = 1, resto = 0 | comprimento do corpo da mensagem de origem |
| `emergente` | 1 quando o tema aparece neste período e não no anterior, senão 0 | `TopicMetricsService::emerging()` |

Os pesos vêm de `system_settings`, grupo `pauta`:

| Chave | Padrão |
|---|---|
| `pauta.priority_weight_urgency` | 3 |
| `pauta.priority_weight_length` | 1 |
| `pauta.priority_weight_emerging` | 2 |

Com os padrões, a pontuação vai de 0 a 10.

**Por que faixas de tamanho, e não proporção.** Uma proporção contínua deixaria
uma única resposta muito longa dominar a fila inteira, empurrando para baixo
respostas urgentes e curtas. As faixas dizem o que importa — escreveu pouco,
escreveu bastante, escreveu muito — sem transformar comprimento em nota.

**Por que o tamanho conta.** Quem escreveu muito investiu mais na conversa, e
uma resposta longa ignorada custa mais que uma curta ignorada.

**Por que tema emergente conta.** É onde a campanha ainda não formou posição, e
responder cedo é mais barato que corrigir depois.

**Por que os pesos são configuração.** Nenhum deles foi calibrado com dado real.
Cravá-los no código transformaria três chutes em regra permanente.

O período anterior, usado para decidir o que é emergente, tem a mesma duração do
período consultado e termina onde ele começa — a comparação não depende do
calendário.

## Detecção de resposta já enviada

Um insight conta como respondido quando existe, **na mesma conversa**, mensagem
com:

- direção de saída;
- `has_media` verdadeiro;
- `sent_at` posterior ao `created_at` do insight;
- `sent_at` dentro de `pauta.answered_lookback_days` (padrão 30) contados do
  `created_at` do insight.

A marcação manual (`conversation_insights.answered_at`) também conta e **tem
precedência**: ela é a afirmação de uma pessoa; a detecção afirma que saiu um
áudio naquela conversa, o que é evidência forte e não prova. A fila mostra qual
das duas marcou, com a data.

**Condição.** Só funciona se a resposta sair do mesmo número pareado ao sistema.
Respondendo de outro número, nada é detectado e vale apenas a marcação à mão.
Isso está dito na tela da fila, e não só aqui.

**A regra não usa `conversation_messages.origin`.** A coluna tem valor padrão
`manual` e o serviço de sincronização não a preenche ao criar a mensagem: uma
mensagem vinda do WhatsApp Web fica gravada como `manual`. Filtrar por `sync`
pareceria mais preciso e não casaria com nada, em silêncio.

## Cruzamento de localidade por tema

Contagem de insights por par (localidade declarada, tema principal), mais o
total de cada linha e de cada coluna. A versão por região usa
`conversation_insights.region` no lugar da localidade.

**A localidade nunca é inferida.** Sai do que a própria pessoa declarou na
resposta. Nada é deduzido de DDD, de nome ou de qualquer outro sinal: o DDD diz
onde a linha foi habilitada, não onde alguém mora.

A leitura prefere `locality_normalized` e recorre a `locality_text` quando a
primeira está vazia. Hoje ela está vazia sempre —
`InsightExtractionService` grava `locality_normalized` como nulo mesmo quando a
pessoa declarou onde mora. Ler só a coluna normalizada deixaria a tela
permanentemente vazia, e vazio seria lido como "ninguém declarou". A reserva não
infere nada: é a mesma declaração, sem passar pela normalização que nunca
aconteceu.

Grafias diferentes da mesma localidade são dobradas por uma chave sem acento e
sem caixa, e o rótulo exibido é a grafia que mais apareceu. Sem isso, "Chapecó"
e "chapeco" apareceriam como duas cidades, possivelmente as duas abaixo do
mínimo — o que suprimiria as duas e esconderia uma cidade que tem gente
suficiente.

### Por que a supressão derruba mais aqui

Numa tabela simples, os registros do período se dividem entre as linhas de um
eixo só. No cruzamento, os mesmos registros se dividem entre linhas **e**
colunas: com 15 temas e 33 localidades são 495 células para os mesmos registros.

Com `analytics.minimum_cell_size` em 5 e algumas centenas de respostas, a
maioria das células fica abaixo do mínimo e aparece suprimida. **Isso é a regra
funcionando, não falta de dado**, e a tela diz isso na abertura.

A célula suprimida continua na tabela, marcada. Removê-la faria a soma das
colunas visíveis não bater com o total da linha, e quem lesse concluiria que
falta registro — o que é pior que ver "suprimido".

O total da linha e o da coluna não são suprimidos: são a mesma agregação simples
de um eixo só que a tela de geografia já mostra, e suprimi-los aqui esconderia
um número disponível ao lado.

Insights sem localidade declarada são contados **à parte**, nunca distribuídos
nem somados a "outros": quem não disse onde mora não mora em nenhuma linha da
tabela, e empurrá-lo para uma linha genérica inventaria uma localidade que
ninguém declarou.

## O dossiê não tem fórmula

A pauta de resposta é nominal, e **não sofre supressão nenhuma**. A supressão
protege contra identificar alguém a partir de um agregado pequeno; no dossiê
identificar é o ponto, porque alguém vai responder àquela pessoa.

É por isso que a pauta exige três permissões juntas e mora em módulo separado do
painel. As duas regras são opostas, e mantê-las no mesmo lugar é onde o
vazamento nasce.
