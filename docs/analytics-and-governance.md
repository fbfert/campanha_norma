# Relatórios, exportação e governança — Subetapa 9E

Leitura agregada do que as subetapas 9A a 9D coletaram. Somente leitura: nenhuma
tela desta subetapa envia mensagem, altera conversa ou liga automação.

O objetivo declarado e ouvir demanda, não perfilar pessoa. Essa distinção decide
o desenho inteiro — e a razão de existirem supressão de grupo pequeno,
anonimização na exportação e ausência deliberada de filtro por atributo
sensível.

## Telas

| Tela | Caminho | Permissão |
| --- | --- | --- |
| Painel da pesquisa | `/admin/analytics` | `analytics.view_aggregates` |
| Temas | `/admin/analytics/temas` | `analytics.view_aggregates` |
| Geografia | `/admin/analytics/geografia` | `analytics.view_aggregates` |
| Demandas | `/admin/analytics/demandas` | `analytics.view_aggregates`; texto exige `analytics.view_content` |
| Qualidade da IA | `/admin/analytics/qualidade-ia` | `analytics.view_aggregates`; custo exige `analytics.view_costs` |
| Qualidade das perguntas | `/admin/analytics/perguntas` | `analytics.view_aggregates` |
| Governança | `/admin/analytics/governanca` | `analytics.view_governance` |

Filtros de período e fluxo ficam na URL: a tela toda e compartilhável por link.

## Permissões

Nove níveis, dos quais sete são novos e dois reaproveitam permissões que já
existiam.

| Capacidade | Permissão |
| --- | --- |
| Ver agregados | `analytics.view_aggregates` |
| Ver conteúdo | `analytics.view_content` |
| Ver identificação | `analytics.view_identification` |
| Exportar agregados | `analytics.export_aggregates` |
| Exportar detalhado | `analytics.export_detailed` |
| Ver custos | `analytics.view_costs` |
| Ver governança | `analytics.view_governance` |
| Administrar taxonomia | `ai_insights.manage_taxonomy` (já existia) |
| Administrar IA | `ai.provider.manage` (já existia) |

Atribuição padrão: **consulta** recebe apenas agregados. **Operador** recebe
agregados, conteúdo e exportação agregada. **Administrador** recebe tudo.

A separação que mais importa e entre agregado, conteúdo e identificação. Saber
que saúde foi o tema mais citado, ler o que uma pessoa escreveu e saber quem
escreveu são três níveis distintos de exposição.

**Texto de demanda conta como conteúdo.** Agrupar a frase de alguém e contar
quantas vezes ela aparece não a transforma em número: o rótulo continua sendo a
frase. Por isso as listas de problema, ação e resultado exigem permissão de
conteúdo, e não so os exemplos.

## Configurações

Grupo `analytics` em configurações do sistema.

| Chave | Padrão | O que faz |
| --- | --- | --- |
| `analytics.minimum_cell_size` | `5` | Mínimo de registros para exibir uma célula agregada |
| `analytics.low_confidence_threshold` | `0.70` | Confiança abaixo da qual o insight vai para a fila de revisão |
| `analytics.default_period_days` | `30` | Período padrão das telas |
| `analytics.emerging_topic_min_mentions` | `3` | Menções mínimas para um tema novo contar como emergente |
| `analytics.maximum_export_rows` | `50000` | Teto de linhas por exportação |
| `analytics.synchronous_export_max_rows` | `1000` | Acima disso a exportação vai para a fila |
| `analytics.export_expiration_hours` | `24` | Horas até o arquivo expirar |
| `analytics.content_retention_days` | `0` | Dias de retenção de conteúdo. Zero desliga a anonimização automática |
| `analytics.queue` | `analytics-exports` | Fila das exportações |

## Exportação

**Agregada** e o padrão: contagem, rótulo, taxa e período. Não carrega nome,
telefone, identificador nem texto.

**Detalhada** carrega o texto das respostas e exige permissão elevada mais
finalidade escrita. Na geração do arquivo:

- nome removido;
- telefone reduzido aos quatro últimos digitos;
- `contact_id` substituído por pseudônimo derivado com **sal próprio daquela
  exportação**.

O sal por exportação e o ponto que sustenta o resto. Com sal fixo, duas
exportações de períodos diferentes teriam o mesmo pseudônimo para a mesma
pessoa, e cruzar as duas reconstruiria o histórico dela. Com sal por exportação,
cada arquivo e um universo fechado.

A finalidade **não e validada pelo sistema**. E registro de responsabilidade,
não controle técnico. Vale dizer isso em voz alta para que ninguém a confunda
com garantia.

Arquivos ficam em `storage/app/private/report-exports`, fora do diretório
público, com expiração e download autenticado pela central
`/admin/report-exports`.

## Injeção de fórmula em planilha

Célula que começa com `=`, `+`, `-`, `@`, tabulação ou retorno de carro recebe
uma aspa simples antes. Vale para CSV e XLSX.

Aplicado também a exportação de histórico da Etapa 6, que escrevia conteúdo de
mensagem sem tratamento. Uma mensagem recebida de um cidadão podia começar com
`=` e virar fórmula ao abrir a planilha — quem escrevia a mensagem decidia o que
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

A anonimização esvazia texto de mensagem, instantaneo de nome e telefone e os
campos livres do insight. **Não apaga a linha.** Apagar quebraria a integridade
referencial e faria agregados históricos mudarem de valor sem explicação;
esvaziar preserva a estatística e elimina o que identifica. Os dias afetados são
reprocessados na sequência, e a execução fica na auditoria.

## Governança

A parte mais útil da tela não são os totais: são as **divergências**. Um sistema
com quatro interruptores independentes tem estados que parecem funcionando e não
estão, e cada um deles produz silêncio em vez de erro.

Detectadas hoje:

- interpretação ligada sem provedor de IA configurado;
- geração ligada com interpretação desligada;
- base de conhecimento ligada sem documento aprovado;
- automação ligada sem fluxo ativo;
- envio automático ligado com automação desligada;
- estratégia de busca vetorial sem provedor de embeddings.

## Documentos relacionados

- `docs/analytics-formulas.md` — numerador, denominador e exclusões de cada taxa.
- `docs/reports-and-monitoring.md` — relatórios operacionais da Etapa 6.
- `docs/tests/analytics-manual-etapa-9e.md` — roteiro de homologação.
