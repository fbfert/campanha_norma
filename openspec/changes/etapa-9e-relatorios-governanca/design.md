# Design — Subetapa 9E

## Contexto

Volume real em producao no momento do desenho: 2.457 contatos, 110 conversas, zero fluxos conversacionais cadastrados, zero insights, zero sugestoes. As subetapas 9A a 9D estao implantadas e desligadas. A 9E precisa funcionar bem com base vazia hoje e com dezenas de milhares de respostas depois.

## Decisao 1 — Materializacao: tabela diaria mais consulta ao vivo

**Escolhido:** tabela `conversation_daily_metrics` com uma linha por dia e fluxo, reconstruida por comando idempotente, mais consulta ao vivo para recortes que a tabela nao cobre.

**Alternativas descartadas:**

- *So consulta ao vivo.* Simples e correto hoje, com 110 conversas. Vira varredura completa por pagina quando a pesquisa rodar de verdade, e o painel executivo e a tela que mais gente abre.
- *So tabela materializada.* Obriga a antecipar todo recorte possivel. Qualquer pergunta nova exige migration.

O criterio para o que vai para a tabela: **contagem que aparece no painel executivo e nao muda depois do dia fechado**. Tema, geografia e drill-down ficam ao vivo, porque sao filtrados de muitas formas e reprocessados quando um humano corrige uma classificacao.

A reconstrucao segue o padrao ja adotado na Etapa 6 pelo `reports:rebuild-metrics`: `updateOrCreate` por chave natural. Rodar duas vezes produz o mesmo estado, que e o que a especificacao pede por "rebuild idempotente".

## Decisao 2 — Supressao de grupo pequeno

Celula agregada com menos que o minimo configurado (`analytics.minimum_cell_size`, padrao 5) nao mostra o numero: mostra que foi suprimida.

Isso vale para geografia, tema por cidade, e qualquer recorte cruzado. A razao e direta: numa cidade com tres respondentes, "3 pessoas reclamaram de saude" e uma frase sobre tres pessoas identificaveis por quem conhece a cidade.

A supressao e aplicada no servico, nao na view. Uma supressao que depende de a tela lembrar de aplicar e uma supressao que um dia falha.

## Decisao 3 — Geografia nunca inferida

Duas fontes, ambas ja existentes:

- `contacts.city` e `contacts.state`, do cadastro;
- `conversation_insights.locality_normalized` e `region`, quando a propria pessoa declarou na resposta.

Nao ha deducao por DDD, por prefixo de telefone ou por proximidade. O DDD diz onde a linha foi habilitada, nao onde a pessoa mora, e tratar um pelo outro produziria mapa errado com aparencia de mapa certo.

Nao existe cruzamento de geografia com atributo sensivel. Isso nao e uma opcao desligada por padrao: nao ha filtro para ligar.

## Decisao 4 — Sem biblioteca de graficos

Barras e series sao desenhadas com HTML e CSS ja existentes no projeto. Nenhuma dependencia nova.

A especificacao permite adicionar biblioteca com justificativa. Nao ha justificativa: os graficos pedidos sao barra horizontal, serie temporal simples e tabela. Uma dependencia de terceiros para isso acrescentaria peso ao bundle, superficie de atualizacao e um ponto de falha no build, sem melhorar a leitura.

Toda tabela tem exportacao, que e onde a analise seria feita quando o grafico nao bastasse.

## Decisao 5 — Anonimizacao na exportacao

Exportacao agregada e o padrao e nao carrega identificacao.

Exportacao detalhada exige permissao elevada e **finalidade escrita**, gravada junto do pedido. A finalidade nao e validada pelo sistema — e um registro de responsabilidade, nao um controle tecnico, e vale documentar isso para nao se confundir com garantia.

Na exportacao detalhada:

- nome removido;
- telefone mascarado, preservando apenas os quatro ultimos digitos;
- `contact_id` substituido por pseudonimo derivado de hash com sal proprio da exportacao. Sal por exportacao significa que duas exportacoes do mesmo periodo nao podem ser cruzadas para reidentificar. E irreversivel por construcao: o sal nao e guardado.

## Decisao 6 — Injecao de formula

Celula cujo texto comeca com `=`, `+`, `-`, `@`, tabulacao ou retorno de carro recebe uma aspa simples antes. Vale para CSV e XLSX.

Aplicado tambem a `ReportExportService`, da Etapa 6, que hoje escreve conteudo de mensagem sem tratamento. Uma mensagem recebida de um cidadao pode comecar com `=` e virar formula ao abrir a planilha. Isso e vulnerabilidade existente, e corrigi-la e parte do escopo.

## Decisao 7 — Permissoes separadas em nove

Ver agregado, ver conteudo, ver identificacao, exportar agregado, exportar detalhado, administrar taxonomia, administrar IA, ver custo e ver governanca.

A separacao que mais importa e entre **agregado**, **conteudo** e **identificacao**. Ver que saude foi o tema mais citado, ler o que uma pessoa escreveu e saber quem escreveu sao tres niveis distintos de exposicao. Um perfil de consulta recebe apenas o primeiro.

## Decisao 8 — Formulas com denominador visivel

Cada taxa declara numerador, denominador e exclusoes na documentacao e no proprio rodape da tela. Sem denominador, a taxa nao e exibida: aparece um traco.

"Taxa de resposta de 100%" sobre um denominador de duas conversas e um numero verdadeiro e inutil, e a diferenca so aparece quando o denominador esta a vista.
