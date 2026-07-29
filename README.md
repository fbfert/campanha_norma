# Gerenciador de Mensagens

Aplicacao Laravel para a fundacao administrativa do futuro gerenciador de contatos e mensagens iniciais pelo WhatsApp.

A automacao do projeto deve se limitar ao primeiro contato. Depois da resposta do destinatario, a conversa continua manualmente e de forma humana pelo WhatsApp.

## Requisitos

- PHP 8.3 ou superior com `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`, `xml`, `zip`, `curl` e `bcmath`.
- Composer.
- Node.js e npm.
- MySQL.
- Apache com `mod_rewrite`.
- Redis previsto para etapas futuras.
- OpenSpout `openspout/openspout` para importacao e exportacao CSV/XLSX.
- Servico Node.js privado em `whatsapp-service/` para validacao inicial do WhatsApp Web por QR Code.

## Instalacao

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Crie o banco MySQL:

```sql
CREATE DATABASE gerenciador_mensagens CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configure o `.env` com o acesso ao banco e, se desejar, os dados do administrador inicial:

```env
ADMIN_NAME="Administrador"
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD=
```

Se `ADMIN_PASSWORD` ficar vazio, o seeder gera uma senha temporaria segura e informa no terminal.

A politica local de senha exige apenas no minimo 6 caracteres e confirmacao. Nao ha exigencia de letra maiuscula, minuscula, numero ou simbolo.

Execute migrations e seeders:

```bash
php artisan migrate --seed
```

Compile os assets:

```bash
npm run build
```

Execucao local:

```bash
php artisan serve
npm run dev
```

Testes:

```bash
php artisan test
```

Formatacao:

```bash
./vendor/bin/pint
```

## Apache

Exemplo em `docs/deploy/apache-vhost.conf`.

Comandos essenciais:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

O `DocumentRoot` deve apontar para:

```text
/var/www/gerenciador-mensagens/public
```

Garanta permissoes de escrita para:

```text
storage
bootstrap/cache
```

O arquivo `.env` nunca deve ficar publico. HTTPS e obrigatorio em producao.

## Producao

Use no `.env`:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Comandos de manutencao:

```bash
php artisan optimize
php artisan migrate --force
php artisan queue:work
```

O cron sera usado em etapas futuras:

```cron
* * * * * cd /var/www/gerenciador-mensagens && php artisan schedule:run >> /dev/null 2>&1
```

## Escopo implementado — Etapa 1

- Projeto Laravel configurado em portugues do Brasil e fuso `America/Sao_Paulo`.
- Autenticacao, logout, recuperacao e redefinicao de senha.
- Bloqueio de usuarios inativos ou bloqueados.
- Troca obrigatoria de senha temporaria.
- Perfis e permissoes: Administrador, Operador e Consulta.
- Gestao administrativa de usuarios.
- Perfil do usuario e alteracao da propria senha.
- Dashboard inicial.
- Configuracoes gerais via servico.
- Auditoria basica.
- Seeders, factories, migrations e testes.
- Layout administrativo responsivo com Blade, Livewire, Alpine.js e Vite.
- Documentacao de Apache.

## Escopo implementado — Etapa 2

- Cadastro, edicao, visualizacao, status, exclusao logica e restauracao autorizada de contatos.
- Normalizacao de telefone para formato internacional numerico.
- Prevencao de duplicidade exata por telefone normalizado.
- Filtros combinados por busca, status, cidade, estado, etiqueta, presenca de telefone/e-mail e exclusao logica.
- Etiquetas com cor, situacao, exclusao logica e quantidade de contatos.
- Acoes em massa para etiquetas, status, nao contatar e exclusao logica.
- Lista de nao contatar com motivo e prioridade sobre importacoes futuras.
- Historico especifico de contatos e auditoria geral.
- Importacao CSV/XLSX com upload, armazenamento privado, leitura de cabecalhos, pre-validacao, confirmacao, processamento e relatorio por linha.
- Exportacao CSV/XLSX de contatos filtrados ou selecionados.
- Modelo de planilha para importacao.
- Dashboard com metricas reais de contatos.
- Permissoes de contatos para Administrador, Operador e Consulta.
- Testes automatizados do modulo de contatos.

Dependencia externa:

```bash
composer require openspout/openspout
```

Finalidade: leitura e escrita de arquivos `.csv` e `.xlsx` sem exigir `ext-gd`.

Configuracoes seedadas em `system_settings`:

```text
contacts.default_country = BR
contacts.default_country_code = 55
contacts.require_phone = true
contacts.prevent_duplicate_phone = true
contacts.default_records_per_page = 20
contacts.import_max_file_size = 10 MB
contacts.import_max_rows = 10000 linhas
contacts.allow_export = true
contacts.require_do_not_contact_reason = true
```

## Escopo implementado — Etapa 3

- Servico Node.js separado para WhatsApp Web em `whatsapp-service/`.
- API privada autenticada em `127.0.0.1:3100` com endpoints de health, status, conexao, QR Code, reconexao, desconexao, exclusao de sessao e mensagem individual de teste.
- QR Code transitorio exibido pelo Laravel apenas a usuarios autorizados.
- Persistencia segura da sessao fora do diretorio publico.
- Camada Laravel `WhatsAppProvider` com implementacao `WhatsAppWebProvider`.
- Cliente HTTP Laravel com timeout, token interno e tratamento de erros.
- Tabelas de conexao, eventos tecnicos e mensagens individuais de teste.
- Tela administrativa de conexao WhatsApp e eventos.
- Dashboard com status real do WhatsApp.
- Permissoes `whatsapp.*` para conexao, eventos e envio de teste.
- Envio manual de uma unica mensagem individual de teste para contato ativo, com telefone valido e sem `nao contatar`.
- O envio pelo WhatsApp Web aceita telefone com ou sem `+`; o sistema normaliza para digitos e valida o numero pelo cliente Web antes de chamar `sendMessage`.
- Quando o WhatsApp Web confirma o envio mas nao retorna identificador externo, o sistema registra sucesso com `external_message_id` vazio em vez de tratar como falha.
- Idempotencia por `request_id`.
- Exemplo de systemd e procedimento manual controlado.
- Testes automatizados Laravel com HTTP fake e testes Node.js com runtime mockado.

Dependencias externas do servico Node:

```bash
cd whatsapp-service
npm install
```

Principais pacotes:

```text
whatsapp-web.js 1.34.7
express 5.2.1
qrcode 1.5.4
zod 4.4.3
vitest 4.1.10
supertest 7.2.2
typescript 7.0.2
```

Variaveis Laravel:

```env
WHATSAPP_PROVIDER=web
WHATSAPP_SERVICE_URL=http://127.0.0.1:3100
WHATSAPP_SERVICE_TOKEN=
WHATSAPP_SERVICE_TIMEOUT=15
WHATSAPP_SERVICE_CONNECT_TIMEOUT=5
WHATSAPP_STATUS_CACHE_SECONDS=5
WHATSAPP_TEST_MESSAGE_ENABLED=true
```

Comandos do servico Node:

```bash
cd whatsapp-service
npm run build
npm test
npm run lint
```

Documentacao complementar:

- `whatsapp-service/README.md`
- `whatsapp-service/deploy/gerenciador-whatsapp.service`
- `docs/deploy/whatsapp-systemd.md`
- `docs/tests/whatsapp-manual-etapa-3.md`

## Escopo implementado — Etapa 4

- Cadastro, edicao, visualizacao, duplicacao, ativacao, inativacao, exclusao logica e restauracao de modelos de mensagens.
- Versionamento de modelos em `message_template_versions`.
- Catalogo centralizado de placeholders: `{nome}`, `{primeiro_nome}`, `{telefone}`, `{email}`, `{cidade}`, `{estado}` e `{pais}`.
- Parser de placeholders com bloqueio de desconhecidos, incompletos, aninhados ou com sintaxe invalida.
- Renderizacao textual segura, sem Blade, PHP, JavaScript, HTML ou `eval()`.
- Validacao de valores vazios nos contatos usados por placeholders.
- Pre-visualizacao personalizada de modelo por contato.
- Criacao de lotes em rascunho com selecao manual, todos os filtrados e amostra aleatoria.
- Funcao `CAMPANHA` em `/admin/campaigns/create`, permitindo escolher contatos e ate 10 modelos ativos.
- Em campanhas, a ordem de envio dos contatos e sorteada e preservada em `random_position`.
- Em campanhas, cada destinatario recebe um modelo sorteado entre os modelos escolhidos; o modelo, a versao e a mensagem renderizada ficam congelados no destinatario.
- Validacao backend de aptidao: ativo, nao bloqueado, nao marcado como nao contatar, telefone valido, placeholders preenchidos e mensagem dentro do tamanho permitido.
- Geracao e preservacao de ordem aleatoria por `random_position`.
- Snapshots de contato e mensagem renderizada em `message_batch_recipients`.
- Preparacao de lote com status `ready`, sem processamento automatico.
- Duplicacao de lote pronto para novo rascunho sem copiar destinatarios congelados.
- Cancelamento de lote com motivo, usuario e data.
- Historico de lote em `message_batch_events`.
- Exportacao de previa de destinatarios em CSV/XLSX.
- Dashboard com metricas reais de modelos e lotes.
- Permissoes de modelos e lotes para Administrador, Operador e Consulta.
- Testes automatizados do modulo de modelos, placeholders e lotes.

Configuracoes seedadas em `system_settings`:

```text
messages.maximum_length = 4096 caracteres
messages.preview_sample_size = 5 registros
messages.allow_manual_message = true
messages.require_template_name = true
messages.block_unknown_placeholders = true
messages.block_empty_placeholder_values = true
messages.default_batch_status = draft
messages.allow_random_sample = true
messages.allow_random_reorder = true
messages.maximum_batch_size = 1000 destinatarios
```

## Escopo implementado — Etapa 5

- Redis e Laravel Queue configurados para a fila `whatsapp-messages`.
- Inicio manual de lotes preparados.
- Processamento assíncrono por worker, sem envio dentro de requisicoes HTTP.
- Limites por minuto, hora e dia, intervalo minimo entre mensagens e janela de horario.
- Dias permitidos e fuso horario configuraveis.
- Pausa, retomada, parada definitiva, cancelamento de destinatario e nova tentativa manual.
- Historico de tentativas em `message_send_attempts`.
- Eventos tecnicos em `message_processing_events`.
- Tela de acompanhamento de processamento.
- Tela de configuracoes de envio.
- Comandos de operacao: `messages:dispatch-pending`, `messages:recalculate-batch`, `messages:recover-stuck` e `messages:sync-counters`.
- Supervisor e cron documentados em `docs/message-processing.md`.

Configuracoes principais:

```text
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_QUEUE=whatsapp-messages
```

## Escopo implementado — Etapa 6

- Historico consolidado de mensagens em `/admin/histories/messages`, com filtros por periodo, lote, status, contato, telefone, cidade e erro.
- Detalhe do envio com dados do lote, snapshots usados, mensagem protegida por permissao, tentativas, eventos e classificacao do erro.
- Historico de mensagens por contato em `/admin/contacts/{contact}/message-history`, preservando snapshots mesmo quando o cadastro atual muda.
- Relatorios operacionais de lotes, mensagens, erros, nao enviados, tentativas, limites, contatos e modelos.
- Formulas documentadas para taxa de sucesso, falha, cancelamento, repeticao e tempo medio.
- Exportacoes CSV/XLSX protegidas por permissao, com arquivos fora do diretorio publico e expiracao configuravel.
- Central de exportacoes em `/admin/report-exports`.
- Dashboard operacional com mensagens enviadas, falhas, lotes ativos, resultados incertos, uso do limite diario e saude de Redis, workers e Scheduler.
- Central de monitoramento para Laravel, banco, Redis, filas, workers, Scheduler, Node.js, armazenamento, mensagens presas e lotes inconsistentes.
- Heartbeats em `worker_heartbeats` e `scheduler_heartbeats`.
- Ferramentas de manutencao para sincronizar contadores, detectar inconsistencias, recuperar mensagens presas, limpar exportacoes expiradas e aplicar retencao.
- Metricas diarias em `daily_message_metrics`.
- Permissoes de historicos, relatorios, monitoramento e manutencao.
- Auditoria de visualizacoes, exportacoes, diagnosticos e acoes de manutencao.
- Documentacao operacional em `docs/reports-and-monitoring.md`.

Configuracoes principais:

```text
reports.synchronous_export_max_rows = 1000 linhas
reports.maximum_export_rows = 100000 linhas
reports.export_expiration_hours = 24 horas
reports.allowed_formats = ["csv", "xlsx"]
retention.audit_logs_days = 365 dias
retention.technical_logs_days = 90 dias
retention.connection_events_days = 180 dias
retention.processing_events_days = 365 dias
retention.export_files_hours = 24 horas
monitoring.worker_warning_minutes = 5 minutos
monitoring.worker_critical_minutes = 15 minutos
monitoring.scheduler_warning_minutes = 3 minutos
monitoring.scheduler_critical_minutes = 10 minutos
monitoring.stuck_message_minutes = 10 minutos
```

Comandos operacionais:

```bash
php artisan reports:rebuild-metrics
php artisan reports:expire-exports
php artisan monitoring:check
php artisan maintenance:sync-counters
php artisan maintenance:find-inconsistencies
php artisan maintenance:cleanup
php artisan maintenance:apply-retention
```

## Escopo implementado — Etapa 7

- Recebimento de mensagens pelo servico Node.js e encaminhamento assinado para o Laravel.
- Webhook interno `POST /internal/whatsapp/incoming` com HMAC-SHA256, timestamp, nonce, limite de corpo e Content-Type.
- Idempotencia por `provider + external_message_id` e `event_id`.
- Processamento assíncrono na fila `whatsapp-incoming`.
- Criacao de conversas e mensagens em `conversations` e `conversation_messages`.
- Identificacao de contato por telefone normalizado.
- Conversas sem contato identificado para associacao manual posterior.
- Marcacao de contatos respondidos com `has_replied`, `first_replied_at` e `last_replied_at`.
- Interrupcao de destinatarios pendentes com `CONTACT_REPLIED`.
- Caixa de entrada em `/admin/inbox`.
- Leitura interna e contador de nao lidas.
- Atribuicao de conversa, status, prioridade, arquivamento, etiquetas e notas internas.
- Resposta manual por operador pela fila `whatsapp-manual-replies`, usando `WhatsAppProvider`.
- Relatorio basico de conversas em `/admin/reports/conversations`.
- Dashboard com indicadores de atendimento.
- Comandos `inbox:*` para sincronizacao, recuperacao, reconstrução e arquivamento.
- Alteracoes no Node.js para listeners de mensagens recebidas e envio de webhook assinado.
- Testes automatizados Laravel e Node.js.

Variaveis Laravel:

```env
WHATSAPP_INCOMING_ENABLED=true
WHATSAPP_INCOMING_SECRET=
WHATSAPP_INCOMING_TIMESTAMP_TOLERANCE=300
WHATSAPP_INCOMING_MAX_BODY_SIZE=262144
WHATSAPP_INCOMING_QUEUE=whatsapp-incoming
```

Variaveis Node.js:

```env
LARAVEL_INCOMING_WEBHOOK_URL=https://mensagens.exemplo.com/internal/whatsapp/incoming
LARAVEL_INCOMING_WEBHOOK_SECRET=
LARAVEL_INCOMING_WEBHOOK_TIMEOUT_MS=10000
INCOMING_WEBHOOK_MAX_ATTEMPTS=5
INCOMING_WEBHOOK_RETRY_SECONDS=15
INCOMING_MESSAGE_ENABLED=true
INCOMING_MESSAGE_LOG_BODY=false
```

Documentacao complementar:

- `docs/inbox-and-incoming-messages.md`

## Escopo implementado — Etapa 8

- Menu administrativo `CONVERSAS`, reaproveitando integralmente o modulo existente de inbox.
- Rotas amigaveis `/admin/conversations` e `/admin/conversations/{conversation}`, mantendo `/admin/inbox`.
- Interface de atendimento com lista de conversas, linha do tempo, resposta manual e painel de detalhes.
- Badge de conversas nao lidas com escopo por permissao e cache curto.
- Sincronizacao controlada dos chats individuais disponiveis na sessao atual do WhatsApp Web.
- Endpoints privados Node.js `GET /api/conversations` e `GET /api/conversations/:chatId/messages`.
- Importacao idempotente em `conversations` e `conversation_messages` usando `provider + external_message_id`.
- Registro de execucoes em `conversation_sync_runs`.
- Fila `whatsapp-conversation-sync`, job `SyncWhatsAppConversationsJob` e comandos `conversations:*`.
- Permissao `inbox.sync`.
- Configuracoes `conversations.sync_*`.
- Documentacao operacional em `docs/conversations-and-sync.md`.

Configuracoes principais:

```text
conversations.sync_enabled = true
conversations.sync_max_chats = 100
conversations.sync_messages_per_chat = 50
conversations.sync_days_back = 30
conversations.sync_include_archived = false
conversations.sync_interval_minutes = 15
conversations.polling_interval_seconds = 10
```

## Escopo implementado — Etapa 9A

Fundacao deterministica do fluxo de pesquisa conversacional. **Sem IA, embeddings, RAG ou classificacao por similaridade nesta subetapa**: toda decisao deriva de estado persistido e de regras configuraveis.

- Administracao de fluxos conversacionais com status rascunho/ativo/pausado/arquivado, texto de apresentacao ou modelo, agradecimento final, texto de recusa, limite de perguntas principais, limite de aprofundamentos (zero nesta subetapa), validade e transparencia sobre automacao.
- Administracao de perguntas por fluxo com titulo interno, texto, categoria, peso para sorteio, ordem administrativa, status, versao e exclusao apenas logica.
- Estado persistente por conversa em `conversation_flow_states`, com os treze estagios exigidos, contadores, motivo de encerramento, pausa, revisao humana e validade.
- Historico completo de transicoes em `conversation_flow_transitions`, com estado anterior, novo, evento, mensagem, decisao e responsavel.
- Registro de uso de perguntas em `conversation_flow_question_usages`, com indice unico garantindo que a mesma pergunta nunca seja sorteada duas vezes na mesma conversa.
- Classificador deterministico de permissao (`permission_yes`, `permission_no`, `opt_out`, `ambiguous`) com normalizacao de caixa, espacos, pontuacao, acentos e emojis, prioridade absoluta para opt-out, recusa de classificacao por aproximacao em textos longos e listas de expressoes editaveis em `system_settings`.
- Sorteio ponderado de pergunta em transacao com trava por conversa, congelamento do texto e criacao de uma unica mensagem automatica pendente.
- Associacao opcional entre campanha/lote e fluxo, com snapshot preservado e sem alterar campanhas antigas.
- Ativacao do estado como `waiting_permission` quando o destinatario da campanha e enviado, respeitando elegibilidade do contato.
- Avaliacao despachada apos o commit da mensagem recebida, em filas proprias, sem atrasar o registro nem chamar servico externo dentro da transacao.
- Opt-out reaproveitando `ContactDataService` e `ReplyInterruptionService`; recusa simples nao marca `nao contatar` por padrao.
- Telas administrativas de fluxos, perguntas e estado das conversas, com pausar, retomar, encerrar e assumir manualmente.
- Mensagens automaticas identificadas na linha do tempo da conversa.
- Permissoes `conversation_automation.*`, gates, papeis e menu.
- Testes unitarios do classificador e testes de feature dos seis criterios de aceitacao, incluindo idempotencia e regressao das etapas 1 a 8.

Filas novas:

```text
conversation-automation
conversation-automation-send
```

Configuracoes principais (desligadas por padrao ate homologacao):

```text
conversation_automation.enabled = 0
conversation_automation.auto_send_enabled = 0
conversation_automation.max_automated_messages = 3
conversation_automation.default_validity_hours = 48
conversation_automation.short_answer_max_words = 6
conversation_automation.window_start = 08:00
conversation_automation.window_end = 20:00
conversation_automation.ambiguous_behavior = waiting_human
conversation_automation.no_question_behavior = waiting_human
conversation_automation.mark_do_not_contact_on_refusal = 0
```

Documentacao complementar:

- `docs/conversation-automation.md`
- `docs/tests/conversational-manual-etapa-9a.md`

## Escopo implementado — Etapa 9B

Interpretacao por IA e banco estruturado de opinioes. A IA **le, resume e categoriza**: nao conversa, nao gera texto de resposta e nao envia nada. A conversa bruta continua sendo a fonte primaria e imutavel; todo resultado de IA e derivado, versionado e reprocessavel.

- Abstracao de provedor de IA independente de fornecedor (`App\Contracts\AiProvider`), com implementacao compativel com APIs de chat no formato OpenAI, provedor inerte para ambiente sem credencial, timeout, tentativas com backoff, disjuntor simples e erros sanitizados.
- Saida JSON obrigatoriamente validada por schema no servidor, sem confiar na promessa do fornecedor: JSON parseavel, campos obrigatorios, tipos, valores enumerados, limites de tamanho e recusa de campos desconhecidos.
- Registro auditavel de cada tentativa em `ai_runs`, com finalidade, provedor, modelo, versao de prompt, versao de schema, status, hash da requisicao, resultado, tokens, latencia, custo estimado opcional, confianca, erro sanitizado, tentativa e marcos de tempo. Nunca guarda chave, cabecalho secreto ou payload desnecessario.
- Classificacao ampliada em treze categorias, com **precedencia estrutural** da regra deterministica da 9A: quando ela conclui, o caminho de codigo nao chega ao provedor.
- Extracao estruturada e pesquisavel com resumo, tema principal relacional, temas secundarios em tabela pivo, problema, acao sugerida, resultado desejado, grupo afetado, localidade declarada, regiao, urgencia, sentimento descritivo, palavras-chave, confianca e sinalizacao de revisao.
- Taxonomia administrativa de temas e subtemas com sinonimos, ordenacao, cor, ativo/inativo, tema de fallback obrigatorio e protecao contra exclusao de tema em uso. O modelo nunca cria tema.
- Pipeline assincrono em fila propria que persiste a mensagem antes de qualquer analise e nunca chama servico externo dentro da transacao de registro.
- Prompts versionados em arquivo (`resources/ai/prompts/`), com versao ativa por finalidade em `system_settings` e reprocessamento por versao.
- Thresholds de confianca configuraveis e deteccao deterministica de conteudo sensivel sobre o texto original, que roda inclusive quando a IA falha.
- Correcao humana auditada com preservacao do valor original, sem qualquer retroalimentacao automatica do modelo.
- Contexto minimo enviado ao modelo: pergunta, mensagem truncada, poucas mensagens da mesma conversa e taxonomia. Nome, telefone, etiquetas e conversas de terceiros nunca entram no prompt.
- Telas de fila de revisao, detalhe do insight, correcao, reprocessamento, historico de versoes, CRUD de temas e monitoramento, todas com permissoes proprias e telefone mascarado nas visoes analiticas.
- Comandos `ai:reprocess` (exige filtro, confirma acima do limite) e `ai:prune-runs` (retencao configuravel).
- 126 testes de feature e 15 unitarios cobrindo sucesso, matriz completa de falhas do provedor (400, 401, 403, 404, 422, 429, 500, 503, timeout, conexao indisponivel, corpo vazio, JSON invalido, schema invalido, propriedades extras, classificacao desconhecida, confianca invalida), disjuntor, idempotencia, concorrencia, isolamento de contexto, permissoes e regressao das etapas 1 a 9A.

### Revisao e estabilizacao aplicadas apos a implementacao

- Feature flags separadas por responsabilidade, com `ai.analysis_enabled` proprio e duas chaves reservadas para a 9C criadas ja desligadas.
- Desacoplamento da 9A e da 9B por evento de extensao: `ConversationFlowService` deixou de referenciar qualquer classe de IA, o que torna as duas subetapas revisaveis e reversiveis em separado.
- **Correcao de defeito da 9A**: `denuncia` estava na lista `conversation_automation.opt_out_expressions`. Quem escrevia "quero fazer uma denuncia" era marcado como nao contatar e tinha os lotes pendentes interrompidos, em vez de ser encaminhado para atendimento humano. O termo foi removido do opt-out e permanece na deteccao de conteudo sensivel da 9B.
- Listas de opt-out completadas com variacoes ausentes: "retire meu numero", "remova meu contato", "nao quero receber mais mensagens" e outras.
- Precedencia explicita `opt_out > permission_no > permission_yes > ambiguous`, com a negativa avaliada antes da positiva tambem na correspondencia exata.
- **Correcao no provedor**: erros HTTP 4xx que nao sao 408 nem 429 passaram a usar o codigo `BAD_REQUEST`, nao retentavel. Antes eram tratados como indisponibilidade e repetidos tres vezes sem chance de sucesso.
- Indices de agregacao adicionados para os recortes previsiveis da futura 9E (por tema, por fluxo e por periodo), evitando migration de indice sobre tabela cheia depois.
- Migrations da 9A e da 9B validadas em MariaDB 10.5 real, com ciclo completo de rollback e reaplicacao.

Fila nova:

```text
ai-interpretation
```

Configuracao de ambiente (chave, URL e modelo nunca vao para o banco):

```env
AI_PROVIDER=null
AI_OPENAI_URL=https://api.openai.com/v1
AI_OPENAI_KEY=
AI_OPENAI_MODEL=gpt-4o-mini
```

Feature flags separadas por responsabilidade — nenhuma chave mistura motor de fluxo, analise por IA e futura geracao de respostas:

```text
conversation_automation.enabled       0   motor deterministico da 9A
ai.enabled                            0   chave mestra da infraestrutura de IA
ai.analysis_enabled                   0   classificacao e extracao da 9B
ai.response_generation_enabled        0   RESERVADA para a 9C, nao implementada
ai.auto_send_enabled                  0   RESERVADA para a 9C, nao implementada
```

Ligar a analise exige **duas** chaves: `ai.enabled` e `ai.analysis_enabled`. A 9B nao depende de `conversation_automation.enabled`, mas exige contexto valido de pesquisa (a conversa precisa ter estado de fluxo).

A 9A publica o evento `ConversationMessageEvaluated` como ponto de extensao e nao referencia nenhuma classe da camada de IA. Sem ouvintes registrados, o comportamento da 9A e identico ao de antes.

Configuracoes principais (desligadas por padrao ate homologacao):

```text
ai.classification_enabled = 1
ai.extraction_enabled = 1
ai.min_classification_confidence = 0.70
ai.min_extraction_confidence = 0.65
ai.max_input_chars = 2000
ai.max_context_messages = 3
ai.circuit_failure_threshold = 5
ai.runs_retention_days = 90
```

Documentacao complementar:

- `docs/ai-interpretation.md`
- `docs/tests/ai-interpretation-manual-etapa-9b.md`

## Nao implementado nesta etapa — Etapa 9B

- Geracao de resposta contextual e autoenvio.
- RAG, embeddings e busca por similaridade.
- Dashboards analiticos completos.
- Inferencia de atributo sensivel, intencao de voto ou microdirecionamento individual.
- Treinamento ou ajuste automatico a partir de correcoes humanas.
- Adivinhacao de cidade, regiao ou qualquer caracteristica nao declarada pelo contato.

## Nao implementado nesta etapa — Etapa 9A

- Classificacao por IA, embeddings, RAG ou similaridade.
- Aprofundamento de perguntas.
- Conversa infinita ou sem limite de turnos.
- Analise de sentimento, sumarizacao ou geracao de texto.
- Chatbot generico fora do fluxo especificado.
- Grupos, listas de transmissao, canais e midias.
- API oficial da Meta.

## Ajustes pos-implantacao (producao)

Correcoes e melhorias aplicadas apos a entrada em producao, fora do escopo formal das etapas numeradas acima.

- Contorno de bug conhecido do `whatsapp-web.js` upstream que impedia o evento `ready` de disparar apos `authenticated`: `webVersionCache` fixado em uma versao compativel do WhatsApp Web.
- Resolucao de identificadores `@lid` (dispositivos vinculados) para telefone real via `client.getContactLidAndPhone()`, usada na sincronizacao de conversas e no encaminhamento de mensagens recebidas.
- Correcao de correspondencia de contato para o "nono digito" de celulares brasileiros: numeros com e sem o 9 adicional (`5549XXXXXXXX` e `55499XXXXXXXX`) sao tratados como o mesmo contato em `PhoneNormalizerService`, `ContactMatcherService` e `ContactDuplicateService`.
- Correcao no `ConversationResolverService`: conversas sem contato identificado passam a ser distinguidas por telefone do remetente (nao apenas pela mais recente sem contato), evitando misturar mensagens de pessoas diferentes na mesma conversa.
- Correcao no `Conversation::whatsappPhoneDigits()`: uma consulta com `orWhere` sem escopo podia exibir na lista de conversas o telefone de uma conversa completamente diferente. Escopo da consulta corrigido.
- `ConversationSyncService` nao recria mais, na proxima sincronizacao, conversas removidas intencionalmente (soft delete) que nao tinham contato, mensagem ou telefone identificavel.
- Tela de conversa (`/admin/conversations/{id}`):
  - Atualizacao automatica a cada 30 segundos (pausada quando a aba nao esta visivel), via `GET /admin/inbox/{conversation}/messages`.
  - Botao "Atualizar mensagens" para atualizacao sob demanda, com confirmacao visivel do resultado (mensagens novas encontradas, nenhuma novidade ou erro).
  - Campo de resposta manual reposicionado acima da lista de mensagens.
  - Mensagens mais recentes exibidas primeiro.
  - Atalho para cadastrar e associar um novo contato direto na tela, quando o numero ainda nao esta na base.
- Selecao de emoji (componente `<x-emoji-picker>`) na resposta manual, em modelos de mensagem e em campanhas/lotes.
- Codificacao correta de emojis na comunicacao Laravel -> servico Node (`JSON_UNESCAPED_UNICODE`) e aumento do limite de corpo da requisicao no servico Node (16kb -> 256kb) para mensagens longas com muitos emojis.
- Migalha de navegacao (breadcrumb) com links funcionais para as paginas anteriores, em todas as telas administrativas.
- Selecao de contatos em campanhas com busca dinamica, filtros adicionais e contador ao vivo (`CampaignContactPicker`, componente Livewire), substituindo a lista estatica anterior.
- Limpeza pontual de conversas vazias (sem contato, sem mensagem, sem telefone identificavel) criadas por sincronizacoes anteriores com resolucao de `@lid` malsucedida.

## Nao implementado nesta etapa — Etapa 1

- Contatos.
- WhatsApp.
- QR Code.
- Servico Node.js.
- Mensagens.
- Placeholders.
- Lotes.
- Filas de mensagens.
- Limites de envio.
- Historico de mensagens.
- Recebimento de respostas.
- API oficial da Meta.

## Nao implementado nesta etapa — Etapa 2

- Integracao com WhatsApp.
- Verificacao de existencia do numero no WhatsApp.
- QR Code.
- Servico Node.js.
- Modelos de mensagens.
- Placeholders.
- Lotes.
- Filas.
- Limites por minuto, hora ou dia.
- Horarios de envio.
- Historico de envios.
- Recebimento de respostas.
- API oficial da Meta.

## Nao implementado nesta etapa — Etapa 3

- Modelos de mensagens.
- Placeholders.
- Selecao multipla para envio.
- Ordem aleatoria.
- Lotes.
- Filas de disparo.
- Limites por minuto, hora ou dia.
- Agendamento.
- Historico de campanhas.
- Caixa de entrada.
- Respostas automaticas.
- Chatbot.
- Anexos.
- Grupos.
- Multiplas contas de WhatsApp.
- API oficial da Meta.

## Nao implementado nesta etapa — Etapa 4

- Fila de processamento.
- Workers de envio.
- Limites por minuto, hora ou dia.
- Horarios permitidos.
- Processamento automatico de lotes.
- Pausa e retomada de processamento.
- Tentativas automaticas.
- Historico de envio.
- Caixa de entrada.
- API oficial da Meta.

## Nao implementado nesta etapa — Etapa 5

- Caixa de entrada.
- Leitura de respostas.
- Chatbot.
- Respostas automaticas.
- Anexos.
- Envio para grupos.
- Multiplas contas.
- API oficial da Meta.
- Relatorios estatisticos avancados.

## Nao implementado nesta etapa — Etapa 6

- Mensagens recebidas.
- Caixa de entrada.
- Respostas automaticas.
- Chatbot.
- Anexos.
- Envio para grupos.
- Multiplas contas.
- API oficial da Meta.
- Integracao com CRM externo.
- Conversao, engajamento ou classificacao baseada em respostas.

## Nao implementado nesta etapa — Etapa 7

- Respostas automaticas.
- Chatbot.
- Inteligencia artificial.
- Fluxos por palavras-chave.
- Anexos enviados pelo sistema.
- Download ou armazenamento de midias recebidas.
- Mensagens para grupos.
- Multiplas contas.
- API oficial da Meta.
- CRM externo.

## Nao implementado nesta etapa — Etapa 8

- Chatbot.
- Inteligencia artificial.
- Respostas automaticas.
- Fluxos por palavras-chave.
- Grupos.
- Listas de transmissao.
- Canais.
- Status do WhatsApp.
- Download ou envio de midias.
- Multiplas contas.
- API oficial da Meta.

## Fonte do planejamento

- `projeto_gerenciador_whatsapp.md`: documento original do projeto.
- `docs/gerenciador-whatsapp.md`: resumo tecnico consolidado.

## Specs

As specs aprovadas ficam em `openspec/specs/` e sao a fonte de verdade para implementacao.

Antes de alterar codigo, leia `.codex/rules.md` e as specs aplicaveis.

## Validacao

Use OpenSpec para validar specs e mudancas:

```bash
openspec validate --specs
openspec validate --all --json
```
