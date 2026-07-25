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
