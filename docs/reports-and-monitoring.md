# Relatórios e monitoramento

## Origem dos dados

Os históricos e relatórios usam dados persistidos em `message_batches`, `message_batch_recipients`, `message_send_attempts`, `message_processing_events`, contatos, modelos e snapshots. Mensagens antigas não são recalculadas com dados atuais do contato.

## Fórmulas

- Taxa de sucesso: mensagens enviadas / destinatários processados.
- Taxa de falha: falhas definitivas ou temporárias encerradas / destinatários processados.
- Taxa de cancelamento: cancelados / total elegível.
- Taxa de repetição: destinatários com mais de uma tentativa / total processado.
- Média de tentativas: total de tentativas / total processado.

Quando não houver denominador, a interface mostra `—` ou informa ausência de dados suficientes.

## Exportações

Exportações usam CSV ou XLSX, ficam em `storage/app/private/report-exports` e exigem permissão. A central `/admin/report-exports` controla status, expiração e download autenticado.

## Monitoramento

`/admin/monitoring` verifica Laravel, banco, Redis, filas, workers, Scheduler, Node.js, armazenamento, mensagens presas e lotes inconsistentes. Os estados são `healthy`, `warning`, `critical` ou `unknown`, sempre com explicação textual.

## Heartbeats

Workers atualizam `worker_heartbeats` quando jobs de processamento rodam. O Scheduler atualiza `scheduler_heartbeats` pelo comando `monitoring:check`.

## Manutenção

`/admin/maintenance` executa ações confirmadas e auditadas:

- sincronizar contadores;
- verificar inconsistências;
- recuperar mensagens presas sem reenviar;
- limpar exportações expiradas;
- aplicar retenção preservando histórico.

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

Não exponha tokens, QR Codes, sessões, cookies ou payload técnico completo. Conteúdo integral de mensagem e detalhes técnicos exigem permissões dedicadas.
