# Design — Subetapa 9E

## Contexto

Volume real em produção no momento do desenho: 2.457 contatos, 110 conversas, zero fluxos conversacionais cadastrados, zero insights, zero sugestões. As subetapas 9A a 9D estão implantadas e desligadas. A 9E precisa funcionar bem com base vazia hoje e com dezenas de milhares de respostas depois.

## Decisão 1 — Materialização: tabela diária mais consulta ao vivo

**Escolhido:** tabela `conversation_daily_metrics` com uma linha por dia e fluxo, reconstruida por comando idempotente, mais consulta ao vivo para recortes que a tabela não cobre.

**Alternativas descartadas:**

- *So consulta ao vivo.* Simples e correto hoje, com 110 conversas. Vira varredura completa por página quando a pesquisa rodar de verdade, e o painel executivo e a tela que mais gente abre.
- *So tabela materializada.* Obriga a antecipar todo recorte possível. Qualquer pergunta nova exige migration.

O critério para o que vai para a tabela: **contagem que aparece no painel executivo e não muda depois do dia fechado**. Tema, geografia e drill-down ficam ao vivo, porque são filtrados de muitas formas e reprocessados quando um humano corrige uma classificação.

A reconstrução segue o padrão já adotado na Etapa 6 pelo `reports:rebuild-metrics`: `updateOrCreate` por chave natural. Rodar duas vezes produz o mesmo estado, que e o que a especificação pede por "rebuild idempotente".

## Decisão 2 — Supressão de grupo pequeno

Célula agregada com menos que o mínimo configurado (`analytics.minimum_cell_size`, padrão 5) não mostra o número: mostra que foi suprimida.

Isso vale para geografia, tema por cidade, e qualquer recorte cruzado. A razão e direta: numa cidade com três respondentes, "3 pessoas reclamaram de saúde" e uma frase sobre três pessoas identificáveis por quem conhece a cidade.

A supressão e aplicada no serviço, não na view. Uma supressão que depende de a tela lembrar de aplicar e uma supressão que um dia falha.

## Decisão 3 — Geografia nunca inferida

Duas fontes, ambas já existentes:

- `contacts.city` e `contacts.state`, do cadastro;
- `conversation_insights.locality_normalized` e `region`, quando a própria pessoa declarou na resposta.

Não ha dedução por DDD, por prefixo de telefone ou por proximidade. O DDD diz onde a linha foi habilitada, não onde a pessoa mora, e tratar um pelo outro produziria mapa errado com aparência de mapa certo.

Não existe cruzamento de geografia com atributo sensível. Isso não e uma opção desligada por padrão: não ha filtro para ligar.

## Decisão 4 — Sem biblioteca de gráficos

Barras e séries são desenhadas com HTML e CSS já existentes no projeto. Nenhuma dependência nova.

A especificação permite adicionar biblioteca com justificativa. Não ha justificativa: os gráficos pedidos são barra horizontal, série temporal simples e tabela. Uma dependência de terceiros para isso acrescentaria peso ao bundle, superfície de atualização e um ponto de falha no build, sem melhorar a leitura.

Toda tabela tem exportação, que e onde a análise seria feita quando o gráfico não bastasse.

## Decisão 5 — Anonimização na exportação

Exportação agregada e o padrão e não carrega identificação.

Exportação detalhada exige permissão elevada e **finalidade escrita**, gravada junto do pedido. A finalidade não e validada pelo sistema — e um registro de responsabilidade, não um controle técnico, e vale documentar isso para não se confundir com garantia.

Na exportação detalhada:

- nome removido;
- telefone mascarado, preservando apenas os quatro últimos digitos;
- `contact_id` substituído por pseudônimo derivado de hash com sal próprio da exportação. Sal por exportação significa que duas exportações do mesmo período não podem ser cruzadas para reidentificar. E irreversível por construção: o sal não e guardado.

## Decisão 6 — Injeção de fórmula

Célula cujo texto começa com `=`, `+`, `-`, `@`, tabulação ou retorno de carro recebe uma aspa simples antes. Vale para CSV e XLSX.

Aplicado também a `ReportExportService`, da Etapa 6, que hoje escreve conteúdo de mensagem sem tratamento. Uma mensagem recebida de um cidadão pode começar com `=` e virar fórmula ao abrir a planilha. Isso e vulnerabilidade existente, e corrigi-la e parte do escopo.

## Decisão 7 — Permissões separadas em nove

Ver agregado, ver conteúdo, ver identificação, exportar agregado, exportar detalhado, administrar taxonomia, administrar IA, ver custo e ver governança.

A separação que mais importa e entre **agregado**, **conteúdo** e **identificação**. Ver que saúde foi o tema mais citado, ler o que uma pessoa escreveu e saber quem escreveu são três níveis distintos de exposição. Um perfil de consulta recebe apenas o primeiro.

## Decisão 8 — Fórmulas com denominador visível

Cada taxa declara numerador, denominador e exclusões na documentação e no próprio rodape da tela. Sem denominador, a taxa não e exibida: aparece um traço.

"Taxa de resposta de 100%" sobre um denominador de duas conversas e um número verdadeiro e inutil, e a diferença so aparece quando o denominador esta a vista.
