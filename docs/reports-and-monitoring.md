# Relatorios e monitoramento

## Origem dos dados

Os historicos e relatorios usam dados persistidos em `message_batches`, `message_batch_recipients`, `message_send_attempts`, `message_processing_events`, contatos, modelos e snapshots. Mensagens antigas nao sao recalculadas com dados atuais do contato.

## Formulas

- Taxa de sucesso: mensagens enviadas / destinatarios processados.
- Taxa de falha: falhas definitivas ou temporarias encerradas / destinatarios processados.
- Taxa de cancelamento: cancelados / total elegivel.
- Taxa de repeticao: destinatarios com mais de uma tentativa / total processado.
- Media de tentativas: total de tentativas / total processado.

Quando nao houver denominador, a interface mostra `—` ou informa ausencia de dados suficientes.

## Exportacoes

Exportacoes usam CSV ou XLSX, ficam em `storage/app/private/report-exports` e exigem permissao. A central `/admin/report-exports` controla status, expiracao e download autenticado.

## Monitoramento

`/admin/monitoring` verifica Laravel, banco, Redis, filas, workers, Scheduler, Node.js, armazenamento, mensagens presas e lotes inconsistentes. Os estados sao `healthy`, `warning`, `critical` ou `unknown`, sempre com explicacao textual.

## Heartbeats

Workers atualizam `worker_heartbeats` quando jobs de processamento rodam. O Scheduler atualiza `scheduler_heartbeats` pelo comando `monitoring:check`.

## Manutencao

`/admin/maintenance` executa acoes confirmadas e auditadas:

- sincronizar contadores;
- verificar inconsistencias;
- recuperar mensagens presas sem reenviar;
- limpar exportacoes expiradas;
- aplicar retencao preservando historico.

## Comandos

```bash
php artisan reports:rebuild-metrics
php artisan reports:expire-exports
php artisan monitoring:check
php artisan maintenance:sync-counters
php artisan maintenance:find-inconsistencies
php artisan maintenance:cleanup
php artisan maintenance:apply-retention
```

## Privacidade

Nao exponha tokens, QR Codes, sessoes, cookies ou payload tecnico completo. Conteudo integral de mensagem e detalhes tecnicos exigem permissoes dedicadas.
