# Etapa 9 — Matriz de rastreabilidade e checklist de implantação

Cobre as subetapas 9A a 9E. Liga cada requisito ao teste que o protege e a tela
onde ele aparece.

---

## Matriz de rastreabilidade

### 9A — Fluxo conversacional determinístico

| Requisito | Teste | Tela |
| --- | --- | --- |
| Mensagem recebida persistida antes de qualquer análise | `ConversationAutomationTest` | Conversas |
| Permissão explícita antes de perguntar | `ConversationAutomationTest` | Pesquisa conversacional |
| Opt-out imediato e marcação de não contatar | `ConversationAutomationTest` | Pesquisa conversacional, Contatos |
| Limite de turnos, tempo e tentativas | `ConversationAutomationTest` | Fluxos conversacionais |
| Sorteio ponderado sem repetição | `ConversationAutomationTest` | Fluxos conversacionais |
| Aviso de automação no texto | `ConversationAutomationTest` | Fluxos conversacionais |
| Pausa e retomada por conversa | `ConversationAutomationTest` | Pesquisa conversacional |
| Contato inativo ou não contatar nunca recebe | `ConversationAutomationTest` | — |

### 9B — Interpretação por IA

| Requisito | Teste | Tela |
| --- | --- | --- |
| IA nunca chamada dentro da transação da mensagem | `AiInterpretationTest` | — |
| Classificação com confiança mínima | `AiInterpretationTest` | Interpretação por IA |
| Extração de tema, problema, ação e resultado | `AiInterpretationTest` | Interpretação por IA |
| Baixa confiança vai para revisão humana | `AiInterpretationTest` | Interpretação por IA |
| Falha de provedor não interrompe o recebimento | `AiInterpretationTest` | Monitoramento de IA |
| Disjuntor após falhas repetidas | `AiInterpretationTest` | Monitoramento de IA |
| Nenhuma chamada real em teste | `AiInterpretationTest` (HTTP fake) | — |

### 9C — Geração de resposta com aprovação humana

| Requisito | Teste | Tela |
| --- | --- | --- |
| Nenhuma resposta enviada sem ato humano no modo padrão | `ConversationResponseGenerationTest` | Sugestões de resposta |
| Ciclo pendente, aprovada, editada, recusada, bloqueada, enviada | `ConversationResponseGenerationTest` | Sugestões de resposta |
| Expressões proibidas bloqueiam a sugestão | `ConversationResponseGenerationTest` | Sugestões de resposta |
| Pergunta factual sem base vira handoff | `ConversationResponseGenerationTest` | Sugestões de resposta |
| Limites de turno respeitados na geração | `ConversationResponseGenerationTest` | Sugestões de resposta |
| Conversa de terceiros nunca entra no contexto | `KnowledgeGroundedGenerationTest` | — |

### 9D — Base de conhecimento com RAG

| Requisito | Teste | Tela |
| --- | --- | --- |
| Somente documento aprovado e recuperável | `KnowledgeRetrievalTest` | Base de conhecimento |
| Documento obsoleto deixa de ser recuperado sem apagar histórico | `KnowledgeRetrievalTest` | Base de conhecimento |
| Resposta factual sem evidência não e enviada | `KnowledgeGroundedGenerationTest` | Sugestões de resposta |
| Citação invalida bloqueia a sugestão | `KnowledgeGroundedGenerationTest` | Sugestões de resposta |
| Número, data e compromisso exigem suporte explícito | `GroundingValidatorTest` | Teste de busca na base |
| Injeção de prompt em documento neutralizada | `KnowledgeIngestionTest` | Base de conhecimento |
| Arquivo fora do público, nome normalizado, sem path traversal | `KnowledgeIngestionTest` | Base de conhecimento |
| Antivirus exigido quando disponível | `KnowledgeIngestionTest` | — |
| Opinião da população nunca e fonte | `KnowledgeRetrievalTest` (teste estrutural sobre o código) | — |
| Falha no RAG não interrompe o recebimento | `KnowledgeGroundedGenerationTest` | — |
| Possível desabilitar o RAG e seguir com 9A a 9C | `KnowledgeGroundedGenerationTest` | Configurações |
| Limite da coluna de vetores medido | `KnowledgeVectorLimitsTest` | — |

### 9E — Relatórios, exportação e governança

| Requisito | Teste | Tela |
| --- | --- | --- |
| Painel bate com consultas de validação | `AnalyticsReportsTest` | Painel da pesquisa |
| Taxa sem denominador não vira zero por cento | `AnalyticsReportsTest` | Painel da pesquisa |
| Denominador da permissão exclui quem não respondeu | `AnalyticsReportsTest` | Painel da pesquisa |
| Conversa sem resposta fica fora da média de tempo | `AnalyticsReportsTest` | Painel da pesquisa |
| Grupo abaixo do mínimo e suprimido | `AnalyticsProtectionsTest`, `AnalyticsReportsTest` | Geografia, Temas, Demandas |
| Zero nunca e suprimido | `AnalyticsProtectionsTest` | Todas |
| Perfil de consulta não ve texto de cidadão | `AnalyticsReportsTest` | Demandas |
| Custo oculto sem permissão de custo | `AnalyticsReportsTest` | Qualidade da IA |
| Exportação agregada sem identificação | `AnalyticsExportTest` | Temas |
| Exportação detalhada exige permissão e finalidade | `AnalyticsExportTest` | Demandas |
| Pseudônimo irreversível e diferente por exportação | `AnalyticsProtectionsTest`, `AnalyticsExportTest` | — |
| Injeção de fórmula neutralizada em CSV e XLSX | `AnalyticsProtectionsTest`, `AnalyticsExportTest` | — |
| Métricas reconstruíveis sem duplicação | `AnalyticsReportsTest` | — |
| Anonimização atualiza relatórios e preserva auditoria | `AnalyticsExportTest` | — |
| Telas abrem com tudo desligado | `AnalyticsReportsTest` | Todas |
| Divergências de configuração detectadas | `AnalyticsReportsTest` | Governança |

---

## Checklist de implantação em produção

### Antes

- [ ] `git pull` na revisão aprovada.
- [ ] Backup completo do banco (`mysqldump --skip-lock-tables --no-tablespaces`).
- [ ] Conferir espaço em disco para `storage/app/private`.
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

> **Atenção.** O `SystemSettingSeeder` usa `updateOrCreate` e sobrescreve o valor
> de qualquer chave existente. Antes de rodar, guarde uma copia:
> `CREATE TABLE zz_backup_system_settings_AAAAMMDD AS SELECT * FROM system_settings;`
> Depois, compare e restaure o que a operação tinha ajustado a mão.

### Cache e build

- [ ] `php84 artisan cache:clear`
- [ ] `php84 artisan config:clear`
- [ ] `php84 artisan view:clear`
- [ ] `npm run build`
- [ ] Conferir que `storage/` e `bootstrap/cache/` seguem pertencendo ao usuário da aplicação

### Filas

- [ ] Acrescentar ao worker: `conversation-automation`, `conversation-automation-send`, `ai-interpretation`, `ai-response-generation`, `ai-response-send`, `knowledge-indexing`, `analytics-exports`
- [ ] `systemctl daemon-reload && systemctl restart <worker>`
- [ ] **Reiniciar o worker sempre que trocar provedor ou modelo de IA.** Processo longo mantem a configuração carregada em memória e continuaria usando a anterior sem erro nenhum.

### Ambiente

- [ ] `poppler-utils` instalado (`pdftotext`), se for indexar PDF
- [ ] ClamAV instalado **e com assinaturas atualizadas** (`systemctl enable --now clamav-freshclam`)
- [ ] Provedor de IA configurado em `/admin/ai-provider` e testado pelo botão de conexão

### Verificação

- [ ] `php84 artisan migrate:status` sem pendências
- [ ] `php84 artisan knowledge:diagnose`
- [ ] `php84 artisan analytics:rebuild-metrics --days=30`
- [ ] Abrir `/admin/analytics/governanca` e conferir que não ha divergência inesperada
- [ ] Abrir as seis telas agregadas e confirmar que respondem
- [ ] Conferir `storage/logs/laravel.log` sem `production.ERROR`

### Ativação, em ordem de risco

Cada passo e reversível por uma única chave. Não ligue dois de uma vez: quando
algo sair errado, você precisa saber qual foi.

- [ ] 1. `ai.enabled = 1` — interpreta o que chega e **não responde nada**
- [ ] 2. Carregar e aprovar o primeiro documento da base
- [ ] 3. `knowledge.enabled = 1` — a busca passa a existir; a geração continua desligada
- [ ] 4. Cadastrar e ativar o fluxo conversacional
- [ ] 5. `conversation_automation.enabled = 1` — avalia e **não envia**
- [ ] 6. `conversation_automation.auto_send_enabled = 1` — passa a enviar a pergunta
- [ ] 7. `ai.response.mode` em modo sugestão — gera texto que so sai com aprovação humana

### Rollback

| Situação | Ação |
| --- | --- |
| Geração respondendo mal | `ai.response.mode = disabled` |
| Base devolvendo trecho errado | `knowledge.enabled = 0` |
| Fluxo enviando quando não devia | `conversation_automation.auto_send_enabled = 0` |
| Interpretação gastando demais | `ai.enabled = 0` |
| Provedor com problema | Trocar em `/admin/ai-provider` e reiniciar o worker |

Nenhum dos itens acima exige deploy, migration ou reinício da aplicação.
