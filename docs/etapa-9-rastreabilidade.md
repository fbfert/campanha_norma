# Etapa 9 — Matriz de rastreabilidade e checklist de implantacao

Cobre as subetapas 9A a 9E. Liga cada requisito ao teste que o protege e a tela
onde ele aparece.

---

## Matriz de rastreabilidade

### 9A — Fluxo conversacional deterministico

| Requisito | Teste | Tela |
| --- | --- | --- |
| Mensagem recebida persistida antes de qualquer analise | `ConversationAutomationTest` | Conversas |
| Permissao explicita antes de perguntar | `ConversationAutomationTest` | Pesquisa conversacional |
| Opt-out imediato e marcacao de nao contatar | `ConversationAutomationTest` | Pesquisa conversacional, Contatos |
| Limite de turnos, tempo e tentativas | `ConversationAutomationTest` | Fluxos conversacionais |
| Sorteio ponderado sem repeticao | `ConversationAutomationTest` | Fluxos conversacionais |
| Aviso de automacao no texto | `ConversationAutomationTest` | Fluxos conversacionais |
| Pausa e retomada por conversa | `ConversationAutomationTest` | Pesquisa conversacional |
| Contato inativo ou nao contatar nunca recebe | `ConversationAutomationTest` | — |

### 9B — Interpretacao por IA

| Requisito | Teste | Tela |
| --- | --- | --- |
| IA nunca chamada dentro da transacao da mensagem | `AiInterpretationTest` | — |
| Classificacao com confianca minima | `AiInterpretationTest` | Interpretacao por IA |
| Extracao de tema, problema, acao e resultado | `AiInterpretationTest` | Interpretacao por IA |
| Baixa confianca vai para revisao humana | `AiInterpretationTest` | Interpretacao por IA |
| Falha de provedor nao interrompe o recebimento | `AiInterpretationTest` | Monitoramento de IA |
| Disjuntor apos falhas repetidas | `AiInterpretationTest` | Monitoramento de IA |
| Nenhuma chamada real em teste | `AiInterpretationTest` (HTTP fake) | — |

### 9C — Geracao de resposta com aprovacao humana

| Requisito | Teste | Tela |
| --- | --- | --- |
| Nenhuma resposta enviada sem ato humano no modo padrao | `ConversationResponseGenerationTest` | Sugestoes de resposta |
| Ciclo pendente, aprovada, editada, recusada, bloqueada, enviada | `ConversationResponseGenerationTest` | Sugestoes de resposta |
| Expressoes proibidas bloqueiam a sugestao | `ConversationResponseGenerationTest` | Sugestoes de resposta |
| Pergunta factual sem base vira handoff | `ConversationResponseGenerationTest` | Sugestoes de resposta |
| Limites de turno respeitados na geracao | `ConversationResponseGenerationTest` | Sugestoes de resposta |
| Conversa de terceiros nunca entra no contexto | `KnowledgeGroundedGenerationTest` | — |

### 9D — Base de conhecimento com RAG

| Requisito | Teste | Tela |
| --- | --- | --- |
| Somente documento aprovado e recuperavel | `KnowledgeRetrievalTest` | Base de conhecimento |
| Documento obsoleto deixa de ser recuperado sem apagar historico | `KnowledgeRetrievalTest` | Base de conhecimento |
| Resposta factual sem evidencia nao e enviada | `KnowledgeGroundedGenerationTest` | Sugestoes de resposta |
| Citacao invalida bloqueia a sugestao | `KnowledgeGroundedGenerationTest` | Sugestoes de resposta |
| Numero, data e compromisso exigem suporte explicito | `GroundingValidatorTest` | Teste de busca na base |
| Injecao de prompt em documento neutralizada | `KnowledgeIngestionTest` | Base de conhecimento |
| Arquivo fora do publico, nome normalizado, sem path traversal | `KnowledgeIngestionTest` | Base de conhecimento |
| Antivirus exigido quando disponivel | `KnowledgeIngestionTest` | — |
| Opiniao da populacao nunca e fonte | `KnowledgeRetrievalTest` (teste estrutural sobre o codigo) | — |
| Falha no RAG nao interrompe o recebimento | `KnowledgeGroundedGenerationTest` | — |
| Possivel desabilitar o RAG e seguir com 9A a 9C | `KnowledgeGroundedGenerationTest` | Configuracoes |
| Limite da coluna de vetores medido | `KnowledgeVectorLimitsTest` | — |

### 9E — Relatorios, exportacao e governanca

| Requisito | Teste | Tela |
| --- | --- | --- |
| Painel bate com consultas de validacao | `AnalyticsReportsTest` | Painel da pesquisa |
| Taxa sem denominador nao vira zero por cento | `AnalyticsReportsTest` | Painel da pesquisa |
| Denominador da permissao exclui quem nao respondeu | `AnalyticsReportsTest` | Painel da pesquisa |
| Conversa sem resposta fica fora da media de tempo | `AnalyticsReportsTest` | Painel da pesquisa |
| Grupo abaixo do minimo e suprimido | `AnalyticsProtectionsTest`, `AnalyticsReportsTest` | Geografia, Temas, Demandas |
| Zero nunca e suprimido | `AnalyticsProtectionsTest` | Todas |
| Perfil de consulta nao ve texto de cidadao | `AnalyticsReportsTest` | Demandas |
| Custo oculto sem permissao de custo | `AnalyticsReportsTest` | Qualidade da IA |
| Exportacao agregada sem identificacao | `AnalyticsExportTest` | Temas |
| Exportacao detalhada exige permissao e finalidade | `AnalyticsExportTest` | Demandas |
| Pseudonimo irreversivel e diferente por exportacao | `AnalyticsProtectionsTest`, `AnalyticsExportTest` | — |
| Injecao de formula neutralizada em CSV e XLSX | `AnalyticsProtectionsTest`, `AnalyticsExportTest` | — |
| Metricas reconstruiveis sem duplicacao | `AnalyticsReportsTest` | — |
| Anonimizacao atualiza relatorios e preserva auditoria | `AnalyticsExportTest` | — |
| Telas abrem com tudo desligado | `AnalyticsReportsTest` | Todas |
| Divergencias de configuracao detectadas | `AnalyticsReportsTest` | Governanca |

---

## Checklist de implantacao em producao

### Antes

- [ ] `git pull` na revisao aprovada.
- [ ] Backup completo do banco (`mysqldump --skip-lock-tables --no-tablespaces`).
- [ ] Conferir espaco em disco para `storage/app/private`.
- [ ] Anotar o estado atual dos interruptores, para poder voltar.

### Migrations

- [ ] `php84 artisan migrate --force`

Da Etapa 9 inteira:

| Migration | Subetapa |
| --- | --- |
| `2026_07_29_000100_create_conversation_automation_tables` | 9A |
| `2026_07_29_000200_create_ai_interpretation_tables` | 9B |
| `2026_07_29_000300_create_reply_suggestion_tables` | 9C |
| `2026_07_29_000400_create_knowledge_base_tables` | 9D |
| `2026_07_29_000500_add_grounding_to_conversation_reply_suggestions` | 9D |
| `2026_07_30_000100_create_conversation_daily_metrics_table` | 9E |
| `2026_07_30_000200_add_analytics_fields_to_report_exports_table` | 9E |

### Seeders

- [ ] `php84 artisan db:seed --class=RolePermissionSeeder --force`
- [ ] `php84 artisan db:seed --class=SystemSettingSeeder --force`
- [ ] `php84 artisan db:seed --class=InsightTopicSeeder --force`

> **Atencao.** O `SystemSettingSeeder` usa `updateOrCreate` e sobrescreve o valor
> de qualquer chave existente. Antes de rodar, guarde uma copia:
> `CREATE TABLE zz_backup_system_settings_AAAAMMDD AS SELECT * FROM system_settings;`
> Depois, compare e restaure o que a operacao tinha ajustado a mao.

### Cache e build

- [ ] `php84 artisan cache:clear`
- [ ] `php84 artisan config:clear`
- [ ] `php84 artisan view:clear`
- [ ] `npm run build`
- [ ] Conferir que `storage/` e `bootstrap/cache/` seguem pertencendo ao usuario da aplicacao

### Filas

- [ ] Acrescentar ao worker: `conversation-automation`, `conversation-automation-send`, `ai-interpretation`, `ai-response-generation`, `ai-response-send`, `knowledge-indexing`, `analytics-exports`
- [ ] `systemctl daemon-reload && systemctl restart <worker>`
- [ ] **Reiniciar o worker sempre que trocar provedor ou modelo de IA.** Processo longo mantem a configuracao carregada em memoria e continuaria usando a anterior sem erro nenhum.

### Ambiente

- [ ] `poppler-utils` instalado (`pdftotext`), se for indexar PDF
- [ ] ClamAV instalado **e com assinaturas atualizadas** (`systemctl enable --now clamav-freshclam`)
- [ ] Provedor de IA configurado em `/admin/ai-provider` e testado pelo botao de conexao

### Verificacao

- [ ] `php84 artisan migrate:status` sem pendencias
- [ ] `php84 artisan knowledge:diagnose`
- [ ] `php84 artisan analytics:rebuild-metrics --days=30`
- [ ] Abrir `/admin/analytics/governanca` e conferir que nao ha divergencia inesperada
- [ ] Abrir as seis telas agregadas e confirmar que respondem
- [ ] Conferir `storage/logs/laravel.log` sem `production.ERROR`

### Ativacao, em ordem de risco

Cada passo e reversivel por uma unica chave. Nao ligue dois de uma vez: quando
algo sair errado, voce precisa saber qual foi.

- [ ] 1. `ai.enabled = 1` — interpreta o que chega e **nao responde nada**
- [ ] 2. Carregar e aprovar o primeiro documento da base
- [ ] 3. `knowledge.enabled = 1` — a busca passa a existir; a geracao continua desligada
- [ ] 4. Cadastrar e ativar o fluxo conversacional
- [ ] 5. `conversation_automation.enabled = 1` — avalia e **nao envia**
- [ ] 6. `conversation_automation.auto_send_enabled = 1` — passa a enviar a pergunta
- [ ] 7. `ai.response.mode` em modo sugestao — gera texto que so sai com aprovacao humana

### Rollback

| Situacao | Acao |
| --- | --- |
| Geracao respondendo mal | `ai.response.mode = disabled` |
| Base devolvendo trecho errado | `knowledge.enabled = 0` |
| Fluxo enviando quando nao devia | `conversation_automation.auto_send_enabled = 0` |
| Interpretacao gastando demais | `ai.enabled = 0` |
| Provedor com problema | Trocar em `/admin/ai-provider` e reiniciar o worker |

Nenhum dos itens acima exige deploy, migration ou reinicio da aplicacao.
