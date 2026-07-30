# Relatorios, exportacao e governanca — Subetapa 9E

Leitura agregada do que as subetapas 9A a 9D coletaram. Somente leitura: nenhuma
tela desta subetapa envia mensagem, altera conversa ou liga automacao.

O objetivo declarado e ouvir demanda, nao perfilar pessoa. Essa distincao decide
o desenho inteiro — e a razao de existirem supressao de grupo pequeno,
anonimizacao na exportacao e ausencia deliberada de filtro por atributo
sensivel.

## Telas

| Tela | Caminho | Permissao |
| --- | --- | --- |
| Painel da pesquisa | `/admin/analytics` | `analytics.view_aggregates` |
| Temas | `/admin/analytics/temas` | `analytics.view_aggregates` |
| Geografia | `/admin/analytics/geografia` | `analytics.view_aggregates` |
| Demandas | `/admin/analytics/demandas` | `analytics.view_aggregates`; texto exige `analytics.view_content` |
| Qualidade da IA | `/admin/analytics/qualidade-ia` | `analytics.view_aggregates`; custo exige `analytics.view_costs` |
| Qualidade das perguntas | `/admin/analytics/perguntas` | `analytics.view_aggregates` |
| Governanca | `/admin/analytics/governanca` | `analytics.view_governance` |

Filtros de periodo e fluxo ficam na URL: a tela toda e compartilhavel por link.

## Permissoes

Nove niveis, dos quais sete sao novos e dois reaproveitam permissoes que ja
existiam.

| Capacidade | Permissao |
| --- | --- |
| Ver agregados | `analytics.view_aggregates` |
| Ver conteudo | `analytics.view_content` |
| Ver identificacao | `analytics.view_identification` |
| Exportar agregados | `analytics.export_aggregates` |
| Exportar detalhado | `analytics.export_detailed` |
| Ver custos | `analytics.view_costs` |
| Ver governanca | `analytics.view_governance` |
| Administrar taxonomia | `ai_insights.manage_taxonomy` (ja existia) |
| Administrar IA | `ai.provider.manage` (ja existia) |

Atribuicao padrao: **consulta** recebe apenas agregados. **Operador** recebe
agregados, conteudo e exportacao agregada. **Administrador** recebe tudo.

A separacao que mais importa e entre agregado, conteudo e identificacao. Saber
que saude foi o tema mais citado, ler o que uma pessoa escreveu e saber quem
escreveu sao tres niveis distintos de exposicao.

**Texto de demanda conta como conteudo.** Agrupar a frase de alguem e contar
quantas vezes ela aparece nao a transforma em numero: o rotulo continua sendo a
frase. Por isso as listas de problema, acao e resultado exigem permissao de
conteudo, e nao so os exemplos.

## Configuracoes

Grupo `analytics` em configuracoes do sistema.

| Chave | Padrao | O que faz |
| --- | --- | --- |
| `analytics.minimum_cell_size` | `5` | Minimo de registros para exibir uma celula agregada |
| `analytics.low_confidence_threshold` | `0.70` | Confianca abaixo da qual o insight vai para a fila de revisao |
| `analytics.default_period_days` | `30` | Periodo padrao das telas |
| `analytics.emerging_topic_min_mentions` | `3` | Mencoes minimas para um tema novo contar como emergente |
| `analytics.maximum_export_rows` | `50000` | Teto de linhas por exportacao |
| `analytics.synchronous_export_max_rows` | `1000` | Acima disso a exportacao vai para a fila |
| `analytics.export_expiration_hours` | `24` | Horas ate o arquivo expirar |
| `analytics.content_retention_days` | `0` | Dias de retencao de conteudo. Zero desliga a anonimizacao automatica |
| `analytics.queue` | `analytics-exports` | Fila das exportacoes |

## Exportacao

**Agregada** e o padrao: contagem, rotulo, taxa e periodo. Nao carrega nome,
telefone, identificador nem texto.

**Detalhada** carrega o texto das respostas e exige permissao elevada mais
finalidade escrita. Na geracao do arquivo:

- nome removido;
- telefone reduzido aos quatro ultimos digitos;
- `contact_id` substituido por pseudonimo derivado com **sal proprio daquela
  exportacao**.

O sal por exportacao e o ponto que sustenta o resto. Com sal fixo, duas
exportacoes de periodos diferentes teriam o mesmo pseudonimo para a mesma
pessoa, e cruzar as duas reconstruiria o historico dela. Com sal por exportacao,
cada arquivo e um universo fechado.

A finalidade **nao e validada pelo sistema**. E registro de responsabilidade,
nao controle tecnico. Vale dizer isso em voz alta para que ninguem a confunda
com garantia.

Arquivos ficam em `storage/app/private/report-exports`, fora do diretorio
publico, com expiracao e download autenticado pela central
`/admin/report-exports`.

## Injecao de formula em planilha

Celula que comeca com `=`, `+`, `-`, `@`, tabulacao ou retorno de carro recebe
uma aspa simples antes. Vale para CSV e XLSX.

Aplicado tambem a exportacao de historico da Etapa 6, que escrevia conteudo de
mensagem sem tratamento. Uma mensagem recebida de um cidadao podia comecar com
`=` e virar formula ao abrir a planilha — quem escrevia a mensagem decidia o que
a planilha executava.

## Comandos

```bash
# Reconstroi as metricas diarias. Repetir produz o mesmo resultado.
php artisan analytics:rebuild-metrics --date=2026-07-30
php artisan analytics:rebuild-metrics --days=30
php artisan analytics:rebuild-metrics --from=2026-07-01 --to=2026-07-31

# Anonimiza conteudo. Sem escopo, nao faz nada.
php artisan analytics:anonymize --contact=123
php artisan analytics:anonymize --before=2026-01-01
php artisan analytics:anonymize --retention
php artisan analytics:anonymize --contact=123 --dry-run
```

A anonimizacao esvazia texto de mensagem, instantaneo de nome e telefone e os
campos livres do insight. **Nao apaga a linha.** Apagar quebraria a integridade
referencial e faria agregados historicos mudarem de valor sem explicacao;
esvaziar preserva a estatistica e elimina o que identifica. Os dias afetados sao
reprocessados na sequencia, e a execucao fica na auditoria.

## Governanca

A parte mais util da tela nao sao os totais: sao as **divergencias**. Um sistema
com quatro interruptores independentes tem estados que parecem funcionando e nao
estao, e cada um deles produz silencio em vez de erro.

Detectadas hoje:

- interpretacao ligada sem provedor de IA configurado;
- geracao ligada com interpretacao desligada;
- base de conhecimento ligada sem documento aprovado;
- automacao ligada sem fluxo ativo;
- envio automatico ligado com automacao desligada;
- estrategia de busca vetorial sem provedor de embeddings.

## Documentos relacionados

- `docs/analytics-formulas.md` — numerador, denominador e exclusoes de cada taxa.
- `docs/reports-and-monitoring.md` — relatorios operacionais da Etapa 6.
- `docs/tests/analytics-manual-etapa-9e.md` — roteiro de homologacao.
