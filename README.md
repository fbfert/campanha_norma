# Gerenciador de Mensagens

Aplicação Laravel para a fundação administrativa do futuro gerenciador de contatos e mensagens iniciais pelo WhatsApp.

A automação do projeto deve se limitar ao primeiro contato. Depois da resposta do destinatário, a conversa continua manualmente e de forma humana pelo WhatsApp.

## Requisitos

- PHP 8.3 ou superior com `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `intl`, `xml`, `zip`, `curl` e `bcmath`.
- Composer.
- Node.js e npm.
- MySQL.
- Apache com `mod_rewrite`.
- Redis previsto para etapas futuras.
- OpenSpout `openspout/openspout` para importação e exportação CSV/XLSX.
- Serviço Node.js privado em `whatsapp-service/` para validação inicial do WhatsApp Web por QR Code.

## Instalação

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

Se `ADMIN_PASSWORD` ficar vazio, o seeder gera uma senha temporária segura e informa no terminal.

A política local de senha exige apenas no mínimo 6 caracteres e confirmação. Não ha exigência de letra maiúscula, minúscula, número ou símbolo.

Execute migrations e seeders:

```bash
php artisan migrate --seed
```

Compile os assets:

```bash
npm run build
```

Execução local:

```bash
php artisan serve
npm run dev
```

Testes:

```bash
php artisan test
```

Formatação:

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

Garanta permissões de escrita para:

```text
storage
bootstrap/cache
```

O arquivo `.env` nunca deve ficar público. HTTPS e obrigatório em produção.

## Produção

Use no `.env`:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
```

Comandos de manutenção:

```bash
php artisan optimize
php artisan migrate --force
php artisan queue:work
```

O cron será usado em etapas futuras:

```cron
* * * * * cd /var/www/gerenciador-mensagens && php artisan schedule:run >> /dev/null 2>&1
```

## Escopo implementado — Etapa 1

- Projeto Laravel configurado em português do Brasil e fuso `America/Sao_Paulo`.
- Autenticação, logout, recuperação e redefinição de senha.
- Bloqueio de usuários inativos ou bloqueados.
- Troca obrigatória de senha temporária.
- Perfis e permissões: Administrador, Operador e Consulta.
- Gestão administrativa de usuários.
- Perfil do usuário e alteração da própria senha.
- Dashboard inicial.
- Configurações gerais via serviço.
- Auditoria basica.
- Seeders, factories, migrations e testes.
- Layout administrativo responsivo com Blade, Livewire, Alpine.js e Vite.
- Documentação de Apache.

## Escopo implementado — Etapa 2

- Cadastro, edição, visualização, status, exclusão lógica e restauração autorizada de contatos.
- Normalização de telefone para formato internacional numerico.
- Prevenção de duplicidade exata por telefone normalizado.
- Filtros combinados por busca, status, cidade, estado, etiqueta, presença de telefone/e-mail e exclusão lógica.
- Etiquetas com cor, situação, exclusão lógica e quantidade de contatos.
- Ações em massa para etiquetas, status, não contatar e exclusão lógica.
- Lista de não contatar com motivo e prioridade sobre importações futuras.
- Histórico específico de contatos e auditoria geral.
- Importação CSV/XLSX com upload, armazenamento privado, leitura de cabeçalhos, pre-validação, confirmação, processamento e relatório por linha.
- Exportação CSV/XLSX de contatos filtrados ou selecionados.
- Modelo de planilha para importação.
- Dashboard com métricas reais de contatos.
- Permissões de contatos para Administrador, Operador e Consulta.
- Testes automatizados do módulo de contatos.

Dependência externa:

```bash
composer require openspout/openspout
```

Finalidade: leitura e escrita de arquivos `.csv` e `.xlsx` sem exigir `ext-gd`.

Configurações seedadas em `system_settings`:

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

- Serviço Node.js separado para WhatsApp Web em `whatsapp-service/`.
- API privada autenticada em `127.0.0.1:3100` com endpoints de health, status, conexão, QR Code, reconexão, desconexão, exclusão de sessão e mensagem individual de teste.
- QR Code transitorio exibido pelo Laravel apenas a usuários autorizados.
- Persistência segura da sessão fora do diretório público.
- Camada Laravel `WhatsAppProvider` com implementação `WhatsAppWebProvider`.
- Cliente HTTP Laravel com timeout, token interno e tratamento de erros.
- Tabelas de conexão, eventos técnicos e mensagens individuais de teste.
- Tela administrativa de conexão WhatsApp e eventos.
- Dashboard com status real do WhatsApp.
- Permissões `whatsapp.*` para conexão, eventos e envio de teste.
- Envio manual de uma única mensagem individual de teste para contato ativo, com telefone valido e sem `nao contatar`.
- O envio pelo WhatsApp Web aceita telefone com ou sem `+`; o sistema normaliza para digitos e valida o número pelo cliente Web antes de chamar `sendMessage`.
- Quando o WhatsApp Web confirma o envio mas não retorna identificador externo, o sistema registra sucesso com `external_message_id` vazio em vez de tratar como falha.
- Idempotência por `request_id`.
- Exemplo de systemd e procedimento manual controlado.
- Testes automatizados Laravel com HTTP fake e testes Node.js com runtime mockado.

Dependências externas do serviço Node:

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

Variáveis Laravel:

```env
WHATSAPP_PROVIDER=web
WHATSAPP_SERVICE_URL=http://127.0.0.1:3100
WHATSAPP_SERVICE_TOKEN=
WHATSAPP_SERVICE_TIMEOUT=15
WHATSAPP_SERVICE_CONNECT_TIMEOUT=5
WHATSAPP_STATUS_CACHE_SECONDS=5
WHATSAPP_TEST_MESSAGE_ENABLED=true
```

Comandos do serviço Node:

```bash
cd whatsapp-service
npm run build
npm test
npm run lint
```

Documentação complementar:

- `whatsapp-service/README.md`
- `whatsapp-service/deploy/gerenciador-whatsapp.service`
- `docs/deploy/whatsapp-systemd.md`
- `docs/tests/whatsapp-manual-etapa-3.md`

## Escopo implementado — Etapa 4

- Cadastro, edição, visualização, duplicação, ativação, inativação, exclusão lógica e restauração de modelos de mensagens.
- Versionamento de modelos em `message_template_versions`.
- Catalogo centralizado de placeholders: `{nome}`, `{primeiro_nome}`, `{telefone}`, `{email}`, `{cidade}`, `{estado}` e `{pais}`.
- Parser de placeholders com bloqueio de desconhecidos, incompletos, aninhados ou com sintaxe invalida.
- Renderização textual segura, sem Blade, PHP, JavaScript, HTML ou `eval()`.
- Validação de valores vazios nos contatos usados por placeholders.
- Pre-visualização personalizada de modelo por contato.
- Criação de lotes em rascunho com seleção manual, todos os filtrados e amostra aleatória.
- Função `CAMPANHA` em `/admin/campaigns/create`, permitindo escolher contatos e até 10 modelos ativos.
- Em campanhas, a ordem de envio dos contatos e sorteada e preservada em `random_position`.
- Em campanhas, cada destinatário recebe um modelo sorteado entre os modelos escolhidos; o modelo, a versão e a mensagem renderizada ficam congelados no destinatário.
- Validação backend de aptidão: ativo, não bloqueado, não marcado como não contatar, telefone valido, placeholders preenchidos e mensagem dentro do tamanho permitido.
- Geração e preservação de ordem aleatória por `random_position`.
- Snapshots de contato e mensagem renderizada em `message_batch_recipients`.
- Preparação de lote com status `ready`, sem processamento automático.
- Duplicação de lote pronto para novo rascunho sem copiar destinatários congelados.
- Cancelamento de lote com motivo, usuário e data.
- Histórico de lote em `message_batch_events`.
- Exportação de previa de destinatários em CSV/XLSX.
- Dashboard com métricas reais de modelos e lotes.
- Permissões de modelos e lotes para Administrador, Operador e Consulta.
- Testes automatizados do módulo de modelos, placeholders e lotes.

Configurações seedadas em `system_settings`:

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
- Início manual de lotes preparados.
- Processamento assíncrono por worker, sem envio dentro de requisições HTTP.
- Limites por minuto, hora e dia, intervalo mínimo entre mensagens e janela de horário.
- Dias permitidos e fuso horário configuráveis.
- Pausa, retomada, parada definitiva, cancelamento de destinatário e nova tentativa manual.
- Histórico de tentativas em `message_send_attempts`.
- Eventos técnicos em `message_processing_events`.
- Tela de acompanhamento de processamento.
- Tela de configurações de envio.
- Comandos de operação: `messages:dispatch-pending`, `messages:recalculate-batch`, `messages:recover-stuck` e `messages:sync-counters`.
- Supervisor e cron documentados em `docs/message-processing.md`.

Configurações principais:

```text
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_QUEUE=whatsapp-messages
```

## Escopo implementado — Etapa 6

- Histórico consolidado de mensagens em `/admin/histories/messages`, com filtros por período, lote, status, contato, telefone, cidade e erro.
- Detalhe do envio com dados do lote, snapshots usados, mensagem protegida por permissão, tentativas, eventos e classificação do erro.
- Histórico de mensagens por contato em `/admin/contacts/{contact}/message-history`, preservando snapshots mesmo quando o cadastro atual muda.
- Relatórios operacionais de lotes, mensagens, erros, não enviados, tentativas, limites, contatos e modelos.
- Fórmulas documentadas para taxa de sucesso, falha, cancelamento, repetição e tempo médio.
- Exportações CSV/XLSX protegidas por permissão, com arquivos fora do diretório público e expiração configurável.
- Central de exportações em `/admin/report-exports`.
- Dashboard operacional com mensagens enviadas, falhas, lotes ativos, resultados incertos, uso do limite diário e saúde de Redis, workers e Scheduler.
- Central de monitoramento para Laravel, banco, Redis, filas, workers, Scheduler, Node.js, armazenamento, mensagens presas e lotes inconsistentes.
- Heartbeats em `worker_heartbeats` e `scheduler_heartbeats`.
- Ferramentas de manutenção para sincronizar contadores, detectar inconsistências, recuperar mensagens presas, limpar exportações expiradas e aplicar retenção.
- Métricas diárias em `daily_message_metrics`.
- Permissões de históricos, relatórios, monitoramento e manutenção.
- Auditoria de visualizações, exportações, diagnosticos e ações de manutenção.
- Documentação operacional em `docs/reports-and-monitoring.md`.

Configurações principais:

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

- Recebimento de mensagens pelo serviço Node.js e encaminhamento assinado para o Laravel.
- Webhook interno `POST /internal/whatsapp/incoming` com HMAC-SHA256, timestamp, nonce, limite de corpo e Content-Type.
- Idempotência por `provider + external_message_id` e `event_id`.
- Processamento assíncrono na fila `whatsapp-incoming`.
- Criação de conversas e mensagens em `conversations` e `conversation_messages`.
- Identificação de contato por telefone normalizado.
- Conversas sem contato identificado para associação manual posterior.
- Marcação de contatos respondidos com `has_replied`, `first_replied_at` e `last_replied_at`.
- Interrupção de destinatários pendentes com `CONTACT_REPLIED`.
- Caixa de entrada em `/admin/inbox`.
- Leitura interna e contador de não lidas.
- Atribuição de conversa, status, prioridade, arquivamento, etiquetas e notas internas.
- Resposta manual por operador pela fila `whatsapp-manual-replies`, usando `WhatsAppProvider`.
- Relatório básico de conversas em `/admin/reports/conversations`.
- Dashboard com indicadores de atendimento.
- Comandos `inbox:*` para sincronização, recuperação, reconstrução e arquivamento.
- Alterações no Node.js para listeners de mensagens recebidas e envio de webhook assinado.
- Testes automatizados Laravel e Node.js.

Variáveis Laravel:

```env
WHATSAPP_INCOMING_ENABLED=true
WHATSAPP_INCOMING_SECRET=
WHATSAPP_INCOMING_TIMESTAMP_TOLERANCE=300
WHATSAPP_INCOMING_MAX_BODY_SIZE=262144
WHATSAPP_INCOMING_QUEUE=whatsapp-incoming
```

Variáveis Node.js:

```env
LARAVEL_INCOMING_WEBHOOK_URL=https://mensagens.exemplo.com/internal/whatsapp/incoming
LARAVEL_INCOMING_WEBHOOK_SECRET=
LARAVEL_INCOMING_WEBHOOK_TIMEOUT_MS=10000
INCOMING_WEBHOOK_MAX_ATTEMPTS=5
INCOMING_WEBHOOK_RETRY_SECONDS=15
INCOMING_MESSAGE_ENABLED=true
INCOMING_MESSAGE_LOG_BODY=false
```

Documentação complementar:

- `docs/inbox-and-incoming-messages.md`

## Escopo implementado — Etapa 8

- Menu administrativo `CONVERSAS`, reaproveitando integralmente o módulo existente de inbox.
- Rotas amigáveis `/admin/conversations` e `/admin/conversations/{conversation}`, mantendo `/admin/inbox`.
- Interface de atendimento com lista de conversas, linha do tempo, resposta manual e painel de detalhes.
- Badge de conversas não lidas com escopo por permissão e cache curto.
- Sincronização controlada dos chats individuais disponíveis na sessão atual do WhatsApp Web.
- Endpoints privados Node.js `GET /api/conversations` e `GET /api/conversations/:chatId/messages`.
- Importação idempotente em `conversations` e `conversation_messages` usando `provider + external_message_id`.
- Registro de execuções em `conversation_sync_runs`.
- Fila `whatsapp-conversation-sync`, job `SyncWhatsAppConversationsJob` e comandos `conversations:*`.
- Permissão `inbox.sync`.
- Configurações `conversations.sync_*`.
- Documentação operacional em `docs/conversations-and-sync.md`.

Configurações principais:

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

Fundação determinística do fluxo de pesquisa conversacional. **Sem IA, embeddings, RAG ou classificação por similaridade nesta subetapa**: toda decisão deriva de estado persistido e de regras configuráveis.

- Administração de fluxos conversacionais com status rascunho/ativo/pausado/arquivado, texto de apresentação ou modelo, agradecimento final, texto de recusa, limite de perguntas principais, limite de aprofundamentos (zero nesta subetapa), validade e transparência sobre automação.
- Administração de perguntas por fluxo com título interno, texto, categoria, peso para sorteio, ordem administrativa, status, versão e exclusão apenas lógica.
- Estado persistente por conversa em `conversation_flow_states`, com os treze estagios exigidos, contadores, motivo de encerramento, pausa, revisão humana e validade.
- Histórico completo de transições em `conversation_flow_transitions`, com estado anterior, novo, evento, mensagem, decisão e responsável.
- Registro de uso de perguntas em `conversation_flow_question_usages`, com índice único garantindo que a mesma pergunta nunca seja sorteada duas vezes na mesma conversa.
- Classificador determinístico de permissão (`permission_yes`, `permission_no`, `opt_out`, `ambiguous`) com normalização de caixa, espaços, pontuação, acentos e emojis, prioridade absoluta para opt-out, recusa de classificação por aproximação em textos longos e listas de expressões editáveis em `system_settings`.
- Sorteio ponderado de pergunta em transação com trava por conversa, congelamento do texto e criação de uma única mensagem automática pendente.
- Associação opcional entre campanha/lote e fluxo, com snapshot preservado e sem alterar campanhas antigas.
- Ativação do estado como `waiting_permission` quando o destinatário da campanha e enviado, respeitando elegibilidade do contato.
- Avaliação despachada após o commit da mensagem recebida, em filas próprias, sem atrasar o registro nem chamar serviço externo dentro da transação.
- Opt-out reaproveitando `ContactDataService` e `ReplyInterruptionService`; recusa simples não marca `nao contatar` por padrão.
- Telas administrativas de fluxos, perguntas e estado das conversas, com pausar, retomar, encerrar e assumir manualmente.
- Mensagens automáticas identificadas na linha do tempo da conversa.
- Permissões `conversation_automation.*`, gates, papeis e menu.
- Testes unitários do classificador e testes de feature dos seis critérios de aceitação, incluindo idempotência e regressão das etapas 1 a 8.

Filas novas:

```text
conversation-automation
conversation-automation-send
```

Configurações principais (desligadas por padrão até homologação):

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

Documentação complementar:

- `docs/conversation-automation.md`
- `docs/tests/conversational-manual-etapa-9a.md`

## Escopo implementado — Etapa 9B

Interpretação por IA e banco estruturado de opiniões. A IA **le, resume e categoriza**: não conversa, não gera texto de resposta e não envia nada. A conversa bruta continua sendo a fonte primária e imutável; todo resultado de IA e derivado, versionado e reprocessável.

- Abstração de provedor de IA independente de fornecedor (`App\Contracts\AiProvider`), com implementação compatível com APIs de chat no formato OpenAI, provedor inerte para ambiente sem credencial, timeout, tentativas com backoff, disjuntor simples e erros sanitizados.
- Saída JSON obrigatoriamente validada por schema no servidor, sem confiar na promessa do fornecedor: JSON parseável, campos obrigatórios, tipos, valores enumerados, limites de tamanho e recusa de campos desconhecidos.
- Registro auditável de cada tentativa em `ai_runs`, com finalidade, provedor, modelo, versão de prompt, versão de schema, status, hash da requisição, resultado, tokens, latência, custo estimado opcional, confiança, erro sanitizado, tentativa e marcos de tempo. Nunca guarda chave, cabeçalho secreto ou payload desnecessário.
- Classificação ampliada em treze categorias, com **precedência estrutural** da regra determinística da 9A: quando ela conclui, o caminho de código não chega ao provedor.
- Extração estruturada e pesquisável com resumo, tema principal relacional, temas secundários em tabela pivô, problema, ação sugerida, resultado desejado, grupo afetado, localidade declarada, região, urgência, sentimento descritivo, palavras-chave, confiança e sinalização de revisão.
- Taxonomia administrativa de temas e subtemas com sinônimos, ordenação, cor, ativo/inativo, tema de fallback obrigatório e proteção contra exclusão de tema em uso. O modelo nunca cria tema.
- Pipeline assíncrono em fila própria que persiste a mensagem antes de qualquer análise e nunca chama serviço externo dentro da transação de registro.
- Prompts versionados em arquivo (`resources/ai/prompts/`), com versão ativa por finalidade em `system_settings` e reprocessamento por versão.
- Thresholds de confiança configuráveis e detecção determinística de conteúdo sensível sobre o texto original, que roda inclusive quando a IA falha.
- Correção humana auditada com preservação do valor original, sem qualquer retroalimentação automática do modelo.
- Contexto mínimo enviado ao modelo: pergunta, mensagem truncada, poucas mensagens da mesma conversa e taxonomia. Nome, telefone, etiquetas e conversas de terceiros nunca entram no prompt.
- Telas de fila de revisão, detalhe do insight, correção, reprocessamento, histórico de versões, CRUD de temas e monitoramento, todas com permissões próprias e telefone mascarado nas visões analíticas.
- Comandos `ai:reprocess` (exige filtro, confirma acima do limite) e `ai:prune-runs` (retenção configurável).
- 126 testes de feature e 15 unitários cobrindo sucesso, matriz completa de falhas do provedor (400, 401, 403, 404, 422, 429, 500, 503, timeout, conexão indisponível, corpo vazio, JSON inválido, schema inválido, propriedades extras, classificação desconhecida, confiança invalida), disjuntor, idempotência, concorrência, isolamento de contexto, permissões e regressão das etapas 1 a 9A.

### Revisão e estabilização aplicadas após a implementação

- Feature flags separadas por responsabilidade, com `ai.analysis_enabled` próprio e duas chaves reservadas para a 9C criadas já desligadas.
- Desacoplamento da 9A e da 9B por evento de extensão: `ConversationFlowService` deixou de referenciar qualquer classe de IA, o que torna as duas subetapas revisáveis e reversíveis em separado.
- **Correção de defeito da 9A**: `denuncia` estava na lista `conversation_automation.opt_out_expressions`. Quem escrevia "quero fazer uma denuncia" era marcado como não contatar e tinha os lotes pendentes interrompidos, em vez de ser encaminhado para atendimento humano. O termo foi removido do opt-out e permanece na detecção de conteúdo sensível da 9B.
- Listas de opt-out completadas com variações ausentes: "retire meu número", "remova meu contato", "não quero receber mais mensagens" e outras.
- Precedência explícita `opt_out > permission_no > permission_yes > ambiguous`, com a negativa avaliada antes da positiva também na correspondência exata.
- **Correção no provedor**: erros HTTP 4xx que não são 408 nem 429 passaram a usar o código `BAD_REQUEST`, não retentável. Antes eram tratados como indisponibilidade e repetidos três vezes sem chance de sucesso.
- Índices de agregação adicionados para os recortes previsíveis da futura 9E (por tema, por fluxo e por período), evitando migration de índice sobre tabela cheia depois.
- Migrations da 9A e da 9B validadas em MariaDB 10.5 real, com ciclo completo de rollback e reaplicação.

Fila nova:

```text
ai-interpretation
```

Configuração de ambiente (chave, URL e modelo nunca vao para o banco):

```env
AI_PROVIDER=null
AI_OPENAI_URL=https://api.openai.com/v1
AI_OPENAI_KEY=
AI_OPENAI_MODEL=gpt-4o-mini
```

Feature flags separadas por responsabilidade — nenhuma chave mistura motor de fluxo, análise por IA e futura geração de respostas:

```text
conversation_automation.enabled       0   motor deterministico da 9A
ai.enabled                            0   chave mestra da infraestrutura de IA
ai.analysis_enabled                   0   classificacao e extracao da 9B
ai.response_generation_enabled        0   RESERVADA para a 9C, nao implementada
ai.auto_send_enabled                  0   RESERVADA para a 9C, nao implementada
```

Ligar a análise exige **duas** chaves: `ai.enabled` e `ai.analysis_enabled`. A 9B não depende de `conversation_automation.enabled`, mas exige contexto valido de pesquisa (a conversa precisa ter estado de fluxo).

A 9A pública o evento `ConversationMessageEvaluated` como ponto de extensão e não referência nenhuma classe da camada de IA. Sem ouvintes registrados, o comportamento da 9A e identico ao de antes.

Configurações principais (desligadas por padrão até homologação):

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

Documentação complementar:

- `docs/ai-interpretation.md`
- `docs/tests/ai-interpretation-manual-etapa-9b.md`

## Escopo implementado — Etapa 9C

Geração de respostas contextualizadas, aprovação humana e handoff. O objetivo e **aprofundar a opinião da própria pessoa**, com no máximo duas perguntas, sempre a partir do que ela mesma escreveu. O modo padrão e sugerir para aprovação humana: nenhum texto gerado chega ao contato sem um operador aprovar.

- Gerador de resposta por contrato independente de fornecedor, com saída JSON validada por schema, prompt versionado e execução ligada a classificação e ao insight da 9B.
- Contrato com seis ações: `suggest_reply`, `thank_and_complete`, `request_clarification`, `handoff_human`, `no_reply` e `opt_out`.
- Validador determinístico do texto aplicado **depois** do modelo, cobrindo tamanho, quantidade de perguntas, promessa, pedido de voto, comparação com adversários, urgência artificial, intimidade simulada, alegação de leitura pessoal e coleta de dado pessoal. Texto reprovado nunca e enviado, nem por aprovação.
- Quatro modos de operação (`disabled`, `draft_only`, `approval_required`, `auto_send_limited`), com o modo do fluxo podendo apenas restringir o global.
- Caixa de aprovação com edição antes do envio, aprovação individual, rejeição, regeneração com justificativa obrigatória e assunção manual. **Sem aprovação em massa**, por decisão de projeto.
- Proteção contra sugestão obsoleta: chegou mensagem nova, a sugestão anterior não pode mais ser enviada. Garantia de no máximo uma sugestão viva por mensagem recebida, imposta por índice único no banco.
- Texto gerado e texto final armazenados separadamente: o original nunca e sobrescrito pela edição do operador.
- Autoenvio limitado, desligado por padrão e com allowlist de categorias vazia, condicionado a treze guards com registro do motivo de cada recusa.
- Handoff humano com quatorze motivos, pausando a automação, elevando prioridade quando cabe e sem nenhum texto improvisado.
- Limite de aprofundamentos com contagem idempotente, agradecimento e encerramento ao atingir o limite, e agrupamento de mensagens consecutivas por debounce.
- Serviço de saída unificado compartilhado por envio manual, automático e aprovado, sem regressão do envio manual.
- Metadados de autoria de IA nas mensagens, com selo na linha do tempo indicando quem aprovou.
- Feedback operacional por sugestão, sem qualquer efeito automático sobre prompt, modelo ou thresholds.
- 51 testes de feature cobrindo os critérios de aceitação, sugestão obsoleta, aprovação concorrente, autoenvio duplicado, limite de turnos, handoff, opt-out e desativação entre geração e envio, textos proibidos, falha de provedor, os quatro modos e regressão da resposta manual.

Filas novas:

```text
ai-response-generation
ai-response-send
```

Configurações principais (desligadas por padrão):

```text
ai.response.mode = disabled
ai.response.auto_send_classifications = (vazia)
ai.response.auto_send_min_confidence = 0.90
ai.response.max_followups = 2
ai.response.debounce_seconds = 20
ai.response.factual_behavior = handoff
```

Sem base de conhecimento aprovada nesta subetapa, pergunta factual sobre a Professora Norma e encaminhada para atendimento humano ou respondida com texto institucional fixo. O modelo nunca inventa conteúdo factual.

Documentação complementar:

- `docs/ai-response-generation.md`
- `docs/tests/ai-response-manual-etapa-9c.md`

## Escopo implementado — Etapa 9D

Base de conhecimento oficial e aprovada, com recuperação e validação de fundamentação. O objetivo e permitir resposta a pergunta factual **somente** com apoio em documento aprovado, com rastreabilidade até o trecho usado. Sem evidência, a resposta não sai: vira encaminhamento para atendimento humano.

- Quatro contratos próprios (`KnowledgeBaseProvider`, `EmbeddingProvider`, `KnowledgeRetriever`, `AnswerGroundingValidator`), resolvidos por configuração, com provedor inerte e provedor local relacional.
- **Aprovação humana como condição de existência na busca**: sete situações de documento, e somente `approved` dentro de base `active` associada ao fluxo e recuperável. Indexar nunca aprova; reprocessar revoga a aprovação anterior.
- Ingestão com disco privado fora de `public/`, nome em disco gerado por UUID (path traversal encerrado na origem), MIME conferido pelo conteúdo real, deduplicação por hash e por base, antivirus configurável com comportamento explícito na ausência do scanner.
- Extratores nativos de texto plano, Markdown, HTML e DOCX; PDF por binário configurável, com falha limpa quando ausente. Nenhum texto e adivinhado.
- Três estratégias de recuperação — `lexical` (padrão, sem dependência externa), `vector` e `hybrid` — com teto explícito de candidatos, recusa registrada e queda para a estratégia léxica em vez de degradação silenciosa.
- Defesa contra injeção de prompt em duas camadas: neutralização de instruções na ingestão, com o achado visível antes da aprovação, e bloco delimitado no prompt declarado como dado, com a instrução explícita de ignorar ordens internas. O prompt de sistema prevalece sempre.
- Prompt e schema fundamentados em versão própria (`v2`/`2`), selecionados apenas quando ha base ativa associada ao fluxo. Sem base, a 9C segue com `v1`/`1` sem nenhuma alteração.
- Validação de fundamentação determinística **depois** do modelo, com nove vereditos. O campo `grounded` devolvido pelo modelo e sinal, nunca autorização. Reprovação nunca produz texto alternativo: produz bloqueio e handoff.
- Rastreabilidade por snapshot: log de recuperação e citações guardam copia do conteúdo, do título e da versão. Excluir ou substituir um documento não apaga a explicação de nenhuma resposta já enviada.
- Isolamento estrutural: o recuperador consulta apenas as tabelas `knowledge_*`, e um teste le o código-fonte e falha se `Conversation`, `Contact` ou `ConversationInsight` aparecerem. A opinião da população nunca e fonte de resposta individual.
- Telas de bases, documentos, previa de texto extraido e de trechos, teste de busca e de fundamentação sem envio, e exibição das fontes na sugestão. Oito permissões próprias; aprovar e baixar o original ficam com administrador.
- 116 testes novos (465 no total) cobrindo os critérios de aceitação, injeção em documento, citação inventada, documento obsoleto, exclusão, troca de versão, limite de vetores, isolamento estrutural, comandos de operação e regressão das Etapas 1 a 9C.
- Migrations validadas em MariaDB 10.5 real, com ciclo completo de rollback e reaplicação. As colunas de fundamentação ficam em migration própria, separada da criação das tabelas de conhecimento, porque as duas mudanças tem perfis de reversão diferentes.

Fila nova:

```text
knowledge-indexing
```

Configurações principais (desligadas por padrão):

```text
knowledge.enabled                  = 0
knowledge.retrieval_strategy       = lexical
knowledge.top_k                    = 5
knowledge.score_threshold          = 0.25
knowledge.antivirus_required       = 1
knowledge.show_citations_to_contact = 0
```

Ligar a recuperação exige **quatro** condições simultaneas: `knowledge.enabled`, base `active`, base associada ao fluxo e documento `approved`. Desligar qualquer uma interrompe a busca sem apagar nada. O rollback e `knowledge.enabled = 0` e não exige deploy nem migration.

Pendência de ambiente: `pdftotext` não esta instalado neste servidor. Até `dnf install poppler-utils`, upload de PDF falha de forma limpa e o documento fica em `failed`. ClamAV esta presente e exigido.

Documentação complementar:

- `docs/knowledge-base-rag.md`
- `docs/tests/knowledge-base-manual-etapa-9d.md`
- `docs/adr/0001-armazenamento-vetorial-e-provedor-de-conhecimento.md`

## Escopo implementado — Etapa 9E

Relatórios analíticos, exportação, governança e retenção. Somente leitura: nenhuma tela desta subetapa envia mensagem, altera conversa ou liga automação.

- Painel executivo de participação com abordados, permissões, opt-outs, respostas, conclusões, aguardando humano, taxas, tempo até a primeira resposta e média de turnos, por período e por fluxo.
- Relatório de temas com mais mencionados, emergentes, não classificados contados a parte, confiança média e revisão humana.
- Relatório de geografia usando apenas cidade do cadastro e localidade declarada pela própria pessoa. Nada e deduzido de DDD.
- Relatório de demandas com problemas, ações, resultados, urgência, exemplos anonimizados e fila de baixa confiança.
- Relatório de qualidade da IA com aprovação sem edição, aprovação com edição, recusas com motivo, handoff, falhas por provedor, modelo e versão de prompt, latência e custo.
- Relatório de qualidade das perguntas com taxa de resposta, conclusão, handoff e tamanho médio da resposta por pergunta.
- Relatório de governança com interruptores, fluxos, documentos vigentes, versões, limiares, eventos sensíveis, pendências, falhas e **divergências de configuração**.
- Supressão de célula agregada abaixo do mínimo configurado, aplicada no serviço e não na tela.
- Exportação agregada sem identificação e exportação detalhada com permissão elevada, finalidade escrita, nome removido, telefone mascarado e pseudônimo com sal próprio por exportação.
- Neutralização de injeção de fórmula em CSV e XLSX, aplicada também a exportação existente da Etapa 6.
- Materialização diária idempotente em `conversation_daily_metrics`, reconstruível por comando sem duplicação.
- Comando de anonimização por contato ou período, preservando linha, integridade referencial e auditoria, com reprocessamento dos dias afetados.
- Sete permissões novas separando agregado, conteúdo, identificação, exportação agregada, exportação detalhada, custo e governança.
- Documentação de fórmulas com numerador, denominador e exclusões de cada taxa.

## Escopo implementado — Subetapa 9F

Painel de relatórios e pauta de resposta. A 9E resolveu o dado e parou na tela; a 9F acrescenta a camada de documento, o recorte que faltava e o caminho de volta para quem respondeu. **Somente leitura: nenhuma tela desta subetapa envia mensagem, agenda envio, grava áudio ou liga automação.** O contrato `WhatsAppProvider` não foi tocado e o serviço Node não foi tocado.

- **Cruzamento de localidade declarada e região por tema**, com supressão de célula pequena aplicada no serviço. Célula suprimida continua na tabela, marcada — removê-la faria a soma das colunas visíveis não bater com o total da linha, e quem lesse concluiria que falta registro. Zero nunca é suprimido, e a tela explica na abertura por que cruzar dois eixos derruba tanta célula: é a regra da 9E funcionando, não falta de dado.
- **Insights sem localidade declarada contados à parte**, nunca distribuídos nem somados a "outros": quem não disse onde mora não mora em nenhuma linha da tabela.
- **Pauta de posicionamento**, que responde sobre o que a campanha ainda não escreveu: tema citado no período sem nenhum documento aprovado, em base ativa associada ao fluxo. Indexar não aprova, e documento aprovado em base desligada não responde a ninguém. Ordenada pelo que mais apareceu — o buraco mais caro primeiro.
- **Caderno de resposta**, um dossiê nominal por pessoa, com a frase literal da mensagem, os campos que a interpretação já extraiu, a orientação escrita do tema e a linha vermelha do tema. Existe também como comando (`relatorios:caderno`), em HTML autocontido, que veio antes de qualquer tela.
- **Roteiro montado por composição determinística, sem nenhuma chamada de IA.** A 9B já extrai o que um briefing precisaria, e isso não é matéria-prima: já é o briefing. Citação literal é mais forte que paráfrase, e um modelo que parafraseia introduz afirmação que ninguém escreveu dentro de um documento que será lido como o que a pessoa disse.
- **Linha vermelha por tema, em destaque forte, e dita mesmo quando falta.** Promessa na voz da própria candidata não tem retratação possível. Seção ausente em silêncio seria lida como "não há nada a evitar aqui", que é o contrário do que a ausência significa.
- **Fila ordenada por relevância, e não por escassez.** Duzentas respostas cabem em atendimento individual: a pontuação ordena e nunca descarta — toda pessoa da fila é para responder, e o peso só decide quem vem antes. Os três pesos são configuração porque nenhum foi calibrado com dado real.
- **Marcação de resposta já enviada por detecção**, sobre o que a sincronização já grava: saída com mídia, na mesma conversa, posterior ao insight e dentro da janela configurada. Quem responde do número pareado não precisa apertar nada — e disciplina é o que não sobrevive à terceira semana de campanha. A condição está na tela, não só na documentação.
- **Marcação manual como reserva**, com precedência sobre a detecção. A fila mostra qual das duas marcou, com a data: origem diferente é confiança diferente.
- **Dois módulos separados, com permissões separadas.** O painel suprime grupo pequeno; o dossiê expõe uma pessoa. As regras são opostas, e mantê-las no mesmo código é onde o vazamento nasce. A pauta exige três permissões juntas — a dela, a de identificação e a de conteúdo — porque expõe nome, cidade e o texto da pessoa.
- **Duas colunas de texto em `insight_topics`** para orientação e linha vermelha, no cadastro de temas que já existe. Nenhuma tabela nova, nenhum CRUD novo, e some junto com o tema desativado.
- **Coluna opcional em `knowledge_documents`** ligando documento aprovado ao tema que responde, usada só pela pauta de posicionamento. Teste barra o nome dela no recuperador da 9D e no objeto de consulta: usá-la para escolher o que recuperar faria a opinião coletada decidir a resposta oficial.
- **Impressão e PDF pelo próprio navegador**, com layout de impressão, regras `@media print` a partir dos tokens do `:root` e capa obrigatória. O aviso de que o material é escuta de demanda e não pesquisa eleitoral registrada vai na capa, não em rodapé: rodapé de página impressa não é lido.
- **Marca-d'água de origem em cada página do caderno** e registro da geração na auditoria. Documento nominal que vaza precisa ter origem, pelo mesmo motivo que a exportação detalhada da 9E carrega sal próprio.
- **Teste que afirma que ler a pauta não cria nenhuma execução de modelo**, e teste que varre os controllers do módulo e falha se o contrato de envio, um despacho de job ou a fila aparecerem. Restrição declarada em prosa é convenção, e convenção não impede nada.
- **Teste que afirma que o dossiê não sofre supressão nenhuma**, para ninguém "consertar" depois uma ausência que é deliberada.
- Correção de dois defeitos que só apareciam contra o banco de verdade: o enum de urgência convertido na consulta agregada, e o agrupamento por expressão repetida recusado pelo `ONLY_FULL_GROUP_BY` do MySQL.

Documentação complementar:

- `docs/painel-de-relatorios.md`
- `docs/analytics-formulas.md`, seção da 9F
- `docs/tests/painel-manual-etapa-9f.md`

## Não implementado nesta etapa — Subetapa 9F

- **Envio de áudio ou de qualquer mídia.** O contrato do provedor continua com texto, e a 9F não o alterou. Quem responde grava pelo WhatsApp dela, à mão.
- **Gravação de áudio no sistema.** Nada de captura pelo navegador, nada de arquivo de áudio guardado. Um sistema que grava a voz da candidata passa a ter um acervo que precisa ser protegido, retido e apagado, e nada disso foi desenhado.
- **Geração de texto por IA para o roteiro.** Decidido, e não adiado: promessa dita na própria voz da candidata não tem "foi o sistema". Se o roteiro parecer seco depois de usado, existe caminho pela 9C, que já tem validador determinístico barrando promessa, pedido de voto, urgência artificial e intimidade simulada.
- **Disparo em massa a partir da pauta.** A fila é para atendimento individual. Um botão de responder todo mundo transformaria escuta em campanha de envio, que é exatamente a barreira de finalidade que a Etapa 10 levantou.
- **Agendamento de resposta.** Responder é ato de uma pessoa, e resposta que sai sozinha é resposta que ninguém estava olhando quando saiu.
- **Mapa geográfico.** Desenhar mapa com célula suprimida ou mostra o que a supressão esconde ou mente sobre a cobertura. Tabela com "suprimido" escrito é honesta; mancha em mapa não é.
- **Biblioteca de gráficos.** Pela mesma razão da 9E: o que estas telas pedem é tabela, e uma dependência de terceiros acrescentaria peso, superfície de atualização e um ponto de falha no build sem melhorar a leitura.
- **Correção da normalização de localidade.** `InsightExtractionService` grava `locality_normalized` como nulo mesmo quando a pessoa declarou onde mora — defeito antigo, que afeta também a tela de geografia da 9E. A 9F contorna lendo a declaração crua, e corrigir a extração com o histórico preenchido é trabalho de outra subetapa.
## Escopo implementado — Etapa 10

Captação por palavra-chave: quem escreve a palavra divulgada vira um inscrito, com prova de origem, lista conferível, congelamento e sorteio auditável. A Etapa 9 fechou o caso de quem escreve por conta própria; esta fecha o de quem escreve **porque foi convidado a escrever**.

- Campanha por palavra-chave com vigência, limite de participantes, alarme de rajada por hora e textos próprios de confirmação, de já inscrito e de fora de vigência.
- Gatilho avaliado **dentro** de `EvaluateConversationFlowJob`, antes do roteamento e sob a trava por conversa que o job já segura. Registra inscrição também para quem está no meio de uma pesquisa, sem tocar no estágio do fluxo.
- Casamento determinístico por palavra inteira sobre texto normalizado, sem IA e sem tolerância a erro de digitação, reaproveitando a regra que já existia no roteamento de entrada.
- Uma mensagem por pessoa: quando a campanha responde, a abertura do atendimento de entrada é suprimida para aquela mensagem.
- Contato criado a partir da palavra-chave com origem `gatilho` e consentimento **concedido com finalidade registrada** — participar da campanha, e não receber disparo.
- Barreira de finalidade em `ContactSelectionService`, que antes não filtrava por origem nenhuma: contato de campanha fica fora do lote, e a seleção manual é recusada com o motivo à vista.
- Limitador global de confirmação com **incremento atômico**, teto por minuto e intervalo mínimo. O excedente é adiado, nunca descartado, e a confirmação não obedece à janela de horário da automação.
- Elegibilidade de aluno **marcada por importação**, nunca verificada na entrada; fila de conferência humana com marcação em lote.
- Congelamento condicionado à fila de conferência vazia, com hash estável do conteúdo da lista.
- Sorteio reproduzível sobre lista congelada, com semente registrada em claro e verificação refeita na tela.
- **Todos os sorteados são ganhadores**, e a tela passa a dizer isso. `CouponService::atribuirAosGanhadores()` sempre entregou um cupom a cada sorteado, e o sorteio sempre recusou executar sem cupom para todos; o rótulo que chamava o primeiro de ganhador e o resto de suplente era a única parte do sistema que discordava — e era a parte que a pessoa lia. A ordem continua registrada porque é ela que alguém de fora refaz com a semente e a lista, não porque classifique alguém.
- Correção da derivação de semente do `RandomSelectionService`, que reduzia a semente a 32 bits, sem alterar o comportamento do sorteio de lote.
- Cupons importados por CSV, atribuição transacional e código fora de log, de exportação e do histórico em claro.
- **Cupom cadastrado à mão**, um código por linha, para o prêmio que veio em um e-mail e não em planilha. Mesmo caminho da importação, e por isso mesma idempotência pela chave única do banco e mesma auditoria sem código em claro; o registro passa a guardar a origem, `arquivo` ou `manual`.
- **Mensagem do cupom configurável por campanha**, com `{codigo}` obrigatório e `{nome}` opcional. Campo nulo manda o texto que saía fixo antes. Duas travas antes de qualquer job sair: mensagem sem o código é recusada, e `{nome}` com ganhador sem nome cadastrado também — descobrir isso no meio da fila deixaria a escolha entre mandar "Parabéns, !" e não mandar nada. O que fica gravado é o molde, nunca o código.
- **Lista de cupons na tela de sorteio**, com as três situações contadas no topo e cada cupom identificado por código (para quem administra) ou referência. Usados primeiro, e `atribuído` separado de `entregue` por cor: o primeiro é o prêmio que ainda pode falhar no envio.
- **"Marcar todos" na fila de conferência**, que alterna e vale só para a página na tela — a fila é paginada, e marcar o que não se vê seria conferir às cegas quem ninguém leu.
- **Conferir a fila inteira**, opção à parte que aparece quando a fila passa de uma página e diz no rótulo quantas vai alcançar. Marcar caixas alcança o que está à vista; a fila inteira alcança também quem ninguém leu, e quem decide isso precisa ver o tamanho da decisão antes de tomá-la — por isso é uma segunda escolha, e não um efeito colateral do botão. Os identificadores saem do banco, filtrados por esta campanha, e a tela recusa quando não há seleção nem a opção marcada.
- Nome do remetente preenchido no serviço Node, que mandava `sender_name: null` cravado.
- Comandos `campanhas:reprocessar`, `campanhas:diagnosticar` e `campanhas:quase-casamentos`, nenhum deles agendado.
- **Pesquisa a partir da inscrição**, opcional por campanha: a campanha aponta para um fluxo conversacional, e a confirmação sai emendada ao pedido de permissão numa mensagem só. Do "sim" em diante quem conduz é o motor da 9A, com interpretação da 9B e continuação da 9C — nenhum código novo, o mesmo caminho que o lote usa. O fluxo só é aberto depois de a confirmação ter saído de verdade, e quem já está em outra pesquisa se inscreve sem ser convidado para uma segunda.
- Correção: a campanha criava o contato de um número desconhecido e **não o ligava à conversa**, então a confirmação nunca era criada — o caminho principal da etapa ficava inscrito e sem resposta.

Documentação complementar:

- `docs/gatilhos-de-palavra-chave.md`

## Não implementado nesta etapa — Etapa 10

- **Tolerância a erro de digitação.** Distância de edição aproxima palavra errada de palavra certa, mas também aproxima duas palavras legítimas e diferentes, e calibrar o limiar sem dado real é chute. `campanhas:quase-casamentos` existe para transformar esse chute em número depois da primeira campanha.
- **Casamento sobre áudio transcrito.** Inscrição é ato com consequência, e transcrição automática erra: uma inscrição criada por engano é indistinguível, no banco, de uma de verdade, e quem não se inscreveu não tem como saber que está na lista. É a primeira coisa a reconsiderar se a divulgação for por rádio.
- **Coleta de CPF** e qualquer identificação além do telefone e do nome de perfil.
- **Pergunta de nome em dois turnos.** Dobra as mensagens da campanha e perde quem não responde a segunda; participação sem nome é válida.
- **Integração com a API do portal.** A elegibilidade e os cupons entram por CSV exportado à mão.
- **Sorteio agendado.** Sortear é ato deliberado de uma pessoa: um sorteio que acontece sozinho é um sorteio que ninguém estava olhando quando aconteceu.
- **Gatilhos para outras ações além de campanha.** A palavra-chave inscreve; não abre chamado, não agenda nem encaminha.
- **Enquadramento jurídico automatizado.** O código permite tanto sorteio quanto concurso de mérito, porque quem decide o ganhador é configuração. Qual dos dois vale é decisão de fora do sistema.

## Escopo implementado — Atendimento de entrada e leitura de mídia

Quem escreve primeiro passa a ser atendido, e a mídia recebida passa a ser vista, ouvida e lida. Até aqui todo fluxo nascia de um lote: quem escrevia por conta própria caía num motor sem estado, que saía calado.

- Perfis de atendimento de entrada — o equivalente do lote para quem escreve primeiro: fluxo, texto de abertura, janela, teto diário e homologação. A seleção não é nossa, é de quem escreve.
- Roteamento por conteúdo da mensagem, com **perfil de fallback obrigatório**: ninguém escreve pensando na nossa lista de expressões, e quem escreve fora dela é quem mais precisa de resposta.
- Fila de mensagens aguardando resposta, com contador no topo de toda tela e no painel, seleção individual ou por página, e o motivo de cada conversa parada à vista.
- Contato criado no momento em que a conversa automática começa, com origem `recebido` e consentimento **não presumido**: escrever para nós autoriza responder, não autoriza entrar em campanha.
- Travas: chave geral própria, teto diário por perfil e global, janela de horário, homologação por clique nas primeiras conversas, sessão conectada, idade máxima da mensagem, expressões de exclusão para robô e operadora, e lista de números da própria equipe.
- Mídia recebida guardada em disco privado, sob demanda e com retenção de 90 dias; imagem, figurinha, áudio e vídeo aparecem na conversa por rota autenticada.
- Descrição de imagem por visão e transcrição de áudio, ambas gravadas como texto extraído por máquina e distinguíveis do que a pessoa escreveu.
- Download de mídia por caminho próprio no serviço Node, contornando o passo de resolução que o whatsapp-web.js perdeu contra a build atual do WhatsApp Web.
- Reação lida nos dois provedores, com o alvo gravado: reagir 👍 na mensagem que perguntou autoriza a pesquisa e inscreve na campanha; reagir 👎 inscreve do mesmo jeito e recusa apenas a pesquisa, que não é aberta. Não existe opt-out por reação &mdash; descadastro não pode nascer de um toque errado no teclado de emoji.
- Consentimento gravado quando a pessoa autoriza a pesquisa, escrevendo ou reagindo, com a frase exata que o pediu dentro de `consent_text`. "Sim" ouvido pela máquina autoriza a pergunta mas não consente: consentimento criado por engano de transcrição é indistinguível, no banco, de um de verdade.
- Inscrição e pesquisa como consentimentos independentes: recusar a pesquisa nunca tira ninguém do sorteio.

Documentação complementar:

- `docs/inbound-attendance.md`
- `docs/midia-recebida.md`
- `docs/reacoes-na-conversa.md`

## Não implementado — Atendimento de entrada e leitura de mídia

- Opt-out por reação. Descadastro é irreversível para quem o sofre, e um toque errado no teclado de emoji não pode produzi-lo. Quem quer sair escreve "sair", que continua tendo prioridade absoluta.
- Reação como resposta a pergunta aberta. A pergunta pede texto, e gravar um emoji como opinião produziria dado indistinguível de dado real.
- Remoção de reação. O evento chega, e é descartado: a pergunta seguinte já saiu e a inscrição já existe, então retirar o emoji não desfaria nada.
- Reação trazida pela sincronização. Ela lê mensagens, e reação não é mensagem para o whatsapp-web.js; só o evento ao vivo entra.
- Consentimento a partir de áudio transcrito. A transcrição autoriza a pergunta, mas não grava `consent_status`: é a mesma linha que a inscrição por palavra-chave traça contra a suposição da máquina.
- Leitura de vídeo e de documento. O provedor de visão recebe imagem, e um quadro solto descreveria o quadro, não o vídeo; PDF exige extração de texto, que é outro caminho. Os dois seguem recebendo o pedido por escrito.
- Envio de mídia. Continua fora de escopo: o sistema lê o que chega e responde por texto.
- Estabilidade do download contra mudanças do WhatsApp Web. O contorno depende de módulos internos não documentados e **vai quebrar de novo** quando eles forem renomeados. O sintoma será mídia parando de descer; o endpoint de diagnóstico responde em segundos qual peça caiu.

## Não implementado nesta etapa — Etapa 9E

- Biblioteca de gráficos. Os gráficos pedidos são barra, série simples e tabela; uma dependência de terceiros acrescentaria bundle, superfície de atualização e ponto de falha no build sem melhorar a leitura.
- Mapas geograficos. Sem biblioteca aprovada, os dados aparecem em tabela.
- Backup dedicado de metadados analíticos. O backup do banco cobre as tabelas.
- Painel de custo por indexação e apuração financeira consolidada.
- Promoção automática de versão de prompt a partir das métricas. Mudar o que responde a cidadão continua sendo decisão humana.
- Filtros por atributo sensível. Não estão desligados: não foram construidos.

## Não implementado nesta etapa — Etapa 9D

- Provedor gerenciado de vetores ou banco vetorial dedicado. Ver ADR 0001: a decisão foi documentada com limites medidos e procedimento de troca, e o corpus previsto não a justifica.
- Reranking por modelo, expansao de consulta e busca semântica multilingue.
- Ingestão automática a partir de site, feed ou repositório externo.
- Resposta livre fora do fluxo da pesquisa.
- Citação visível ao contato por padrão.
- Relatórios analíticos finais (9E).

## Não implementado nesta etapa — Etapa 9C

- Relatórios analíticos finais (9E).
- Conversa aberta fora do fluxo da pesquisa.
- Aprendizado automático a partir de feedback ou correções.
- Envio de qualquer texto gerado sem aprovação humana no modo padrão.

## Não implementado nesta etapa — Etapa 9B

- Geração de resposta contextual e autoenvio.
- RAG, embeddings e busca por similaridade.
- Dashboards analíticos completos.
- Inferência de atributo sensível, intenção de voto ou microdirecionamento individual.
- Treinamento ou ajuste automático a partir de correções humanas.
- Adivinhação de cidade, região ou qualquer caracteristica não declarada pelo contato.

## Não implementado nesta etapa — Etapa 9A

- Classificação por IA, embeddings, RAG ou similaridade.
- Aprofundamento de perguntas.
- Conversa infinita ou sem limite de turnos.
- Análise de sentimento, sumarização ou geração de texto.
- Chatbot genérico fora do fluxo especificado.
- Grupos, listas de transmissao, canais e midias.
- API oficial da Meta.

## Ajustes pos-implantação (produção)

Correções e melhorias aplicadas após a entrada em produção, fora do escopo formal das etapas numeradas acima.

- Contorno de bug conhecido do `whatsapp-web.js` upstream que impedia o evento `ready` de disparar após `authenticated`: `webVersionCache` fixado em uma versão compatível do WhatsApp Web.
- Resolução de identificadores `@lid` (dispositivos vinculados) para telefone real via `client.getContactLidAndPhone()`, usada na sincronização de conversas e no encaminhamento de mensagens recebidas.
- Correção de correspondência de contato para o "nono digito" de celulares brasileiros: números com e sem o 9 adicional (`5549XXXXXXXX` e `55499XXXXXXXX`) são tratados como o mesmo contato em `PhoneNormalizerService`, `ContactMatcherService` e `ContactDuplicateService`.
- Correção no `ConversationResolverService`: conversas sem contato identificado passam a ser distinguidas por telefone do remetente (não apenas pela mais recente sem contato), evitando misturar mensagens de pessoas diferentes na mesma conversa.
- Correção no `Conversation::whatsappPhoneDigits()`: uma consulta com `orWhere` sem escopo podia exibir na lista de conversas o telefone de uma conversa completamente diferente. Escopo da consulta corrigido.
- `ConversationSyncService` não recria mais, na próxima sincronização, conversas removidas intencionalmente (soft delete) que não tinham contato, mensagem ou telefone identificável.
- Tela de conversa (`/admin/conversations/{id}`):
  - Atualização automática a cada 30 segundos (pausada quando a aba não esta visível), via `GET /admin/inbox/{conversation}/messages`.
  - Botão "Atualizar mensagens" para atualização sob demanda, com confirmação visível do resultado (mensagens novas encontradas, nenhuma novidade ou erro).
  - Campo de resposta manual reposicionado acima da lista de mensagens.
  - Mensagens mais recentes exibidas primeiro.
  - Atalho para cadastrar e associar um novo contato direto na tela, quando o número ainda não esta na base.
- Seleção de emoji (componente `<x-emoji-picker>`) na resposta manual, em modelos de mensagem e em campanhas/lotes.
- Codificação correta de emojis na comunicação Laravel -> serviço Node (`JSON_UNESCAPED_UNICODE`) e aumento do limite de corpo da requisição no serviço Node (16kb -> 256kb) para mensagens longas com muitos emojis.
- Migalha de navegação (breadcrumb) com links funcionais para as páginas anteriores, em todas as telas administrativas.
- Seleção de contatos em campanhas com busca dinamica, filtros adicionais e contador ao vivo (`CampaignContactPicker`, componente Livewire), substituindo a lista estatica anterior.
- Limpeza pontual de conversas vazias (sem contato, sem mensagem, sem telefone identificável) criadas por sincronizações anteriores com resolução de `@lid` malsucedida.
- **Administração responsiva.** Abaixo de 860px a barra de navegação virava uma pilha de oito grupos acima do conteúdo, e toda página começava com um rolar longo. Passou a ser uma gaveta, feita com caixa de seleção e sem JavaScript — menu que depende de script é menu que às vezes não abre.
- Correção de rolagem horizontal em **toda** a área administrativa, em qualquer largura: filho de grid não encolhe abaixo do próprio conteúdo (`min-width` padrão é `auto`), e um card com tabela larga empurrava a página inteira. Eram 1342px de rolagem numa janela de 1280.
- Topo da tela reorganizado em duas fileiras no celular: título espremido virava reticências e a trilha quebrava em cinco linhas, ocupando 174px antes de a tela começar.
- Tela de conversa: a coluna da esquerda saiu. Ela repetia o nome do contato, que encabeça o chat, e a data da última mensagem, que está no card de detalhes — 250px para dizer duas vezes a mesma coisa. O "Voltar para conversas" foi para o topo da coluna de detalhes.
- Aviso de transcrição de áudio corrigido: ele conferia se a **conversa** já tivera algum áudio, e saía colado a uma resposta a texto dizendo "Recebi seu áudio" dias depois. Passou a acompanhar a resposta à mensagem que de fato veio em áudio.
- Prazo próprio para o download de mídia (`WHATSAPP_SERVICE_MEDIA_TIMEOUT`, 30s). O serviço Node dá 20s ao download e o cliente geral desistia em 15: qualquer mídia entre os dois prazos era gravada como indisponível com "o serviço não respondeu a tempo" — descrevendo o Laravel, não o WhatsApp.
- Substituto para a cidade ausente: `{cidade}` sem valor vira "sua cidade" em vez de bloquear o envio. Vale para lote e para pesquisa — contato que antes ficava de fora do disparo passa a receber. A recusa continua para nome, telefone, e-mail, estado e país, onde não existe palavra genérica que sirva.

## Não implementado nesta etapa — Etapa 1

- Contatos.
- WhatsApp.
- QR Code.
- Serviço Node.js.
- Mensagens.
- Placeholders.
- Lotes.
- Filas de mensagens.
- Limites de envio.
- Histórico de mensagens.
- Recebimento de respostas.
- API oficial da Meta.

## Não implementado nesta etapa — Etapa 2

- Integração com WhatsApp.
- Verificação de existência do número no WhatsApp.
- QR Code.
- Serviço Node.js.
- Modelos de mensagens.
- Placeholders.
- Lotes.
- Filas.
- Limites por minuto, hora ou dia.
- Horários de envio.
- Histórico de envios.
- Recebimento de respostas.
- API oficial da Meta.

## Não implementado nesta etapa — Etapa 3

- Modelos de mensagens.
- Placeholders.
- Seleção multipla para envio.
- Ordem aleatória.
- Lotes.
- Filas de disparo.
- Limites por minuto, hora ou dia.
- Agendamento.
- Histórico de campanhas.
- Caixa de entrada.
- Respostas automáticas.
- Chatbot.
- Anexos.
- Grupos.
- Múltiplas contas de WhatsApp.
- API oficial da Meta.

## Não implementado nesta etapa — Etapa 4

- Fila de processamento.
- Workers de envio.
- Limites por minuto, hora ou dia.
- Horários permitidos.
- Processamento automático de lotes.
- Pausa e retomada de processamento.
- Tentativas automáticas.
- Histórico de envio.
- Caixa de entrada.
- API oficial da Meta.

## Não implementado nesta etapa — Etapa 5

- Caixa de entrada.
- Leitura de respostas.
- Chatbot.
- Respostas automáticas.
- Anexos.
- Envio para grupos.
- Múltiplas contas.
- API oficial da Meta.
- Relatórios estatisticos avancados.

## Não implementado nesta etapa — Etapa 6

- Mensagens recebidas.
- Caixa de entrada.
- Respostas automáticas.
- Chatbot.
- Anexos.
- Envio para grupos.
- Múltiplas contas.
- API oficial da Meta.
- Integração com CRM externo.
- Conversão, engajamento ou classificação baseada em respostas.

## Não implementado nesta etapa — Etapa 7

- Respostas automáticas.
- Chatbot.
- Inteligência artificial.
- Fluxos por palavras-chave.
- Anexos enviados pelo sistema.
- Download ou armazenamento de midias recebidas.
- Mensagens para grupos.
- Múltiplas contas.
- API oficial da Meta.
- CRM externo.

## Não implementado nesta etapa — Etapa 8

- Chatbot.
- Inteligência artificial.
- Respostas automáticas.
- Fluxos por palavras-chave.
- Grupos.
- Listas de transmissao.
- Canais.
- Status do WhatsApp.
- Download ou envio de midias.
- Múltiplas contas.
- API oficial da Meta.

## Fonte do planejamento

- `projeto_gerenciador_whatsapp.md`: documento original do projeto.
- `docs/gerenciador-whatsapp.md`: resumo técnico consolidado.

## Specs

As specs aprovadas ficam em `openspec/specs/` e são a fonte de verdade para implementação.

Antes de alterar código, leia `.codex/rules.md` e as specs aplicáveis.

## Validação

Use OpenSpec para validar specs e mudanças:

```bash
openspec validate --specs
openspec validate --all --json
```
