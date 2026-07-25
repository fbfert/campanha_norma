# Processamento de mensagens

## Arquitetura

O processamento da Etapa 5 ocorre no Laravel, com Redis e Laravel Queue. O navegador chama apenas o Laravel; o worker chama a camada `WhatsAppProvider`, que conversa com o servico Node.js privado.

Fluxo:

1. Um lote `ready` e iniciado manualmente.
2. O lote muda para `queued`.
3. O comando `messages:dispatch-pending` ou o job recorrente libera o proximo destinatario.
4. O worker processa um destinatario por vez na fila `whatsapp-messages`.
5. Antes de enviar, o sistema revalida contato, conexao, janela, limites e idempotencia.
6. Tentativas, eventos e auditoria sao registrados.

## Estados

Lotes: `draft`, `validating`, `ready`, `queued`, `processing`, `pausing`, `paused`, `stopping`, `stopped`, `completed`, `completed_with_errors`, `failed`, `cancelled`.

Destinatarios: `eligible`, `pending`, `waiting_schedule`, `waiting_minute_limit`, `waiting_hour_limit`, `waiting_day_limit`, `queued`, `processing`, `sent`, `retry_wait`, `failed_temporary`, `failed_permanent`, `cancelled`, `skipped`.

## Redis e filas

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_QUEUE=whatsapp-messages
```

Redis deve aceitar conexoes somente locais. Nao exponha Redis na internet.

## Worker

Arquivo de exemplo: `docs/supervisor/gerenciador-whatsapp-worker.conf`.

```bash
php artisan queue:restart
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart gerenciador-whatsapp-worker:*
```

## Scheduler

```bash
* * * * * cd /var/www/gerenciador-mensagens && php artisan schedule:run >> /dev/null 2>&1
```

O scheduler executa `messages:dispatch-pending` a cada minuto com `withoutOverlapping`.

## Comandos

```bash
php artisan messages:dispatch-pending
php artisan messages:recalculate-batch {batch}
php artisan messages:recover-stuck
php artisan messages:sync-counters
```

## Recuperacao

`messages:recover-stuck` identifica destinatarios em `processing` por mais tempo que `messages.processing_timeout_minutes` e marca o resultado como `SEND_RESULT_UNKNOWN`. O sistema nao reenvia automaticamente esses casos.

## Logs

Canal: `message_processing`.

Dados seguros: `batch_id`, `recipient_id`, `request_id`, evento, status, tentativa e codigo de erro.

Nao registrar: token, QR Code, sessao, cookies, mensagem completa ou dados pessoais desnecessarios.

## Teste manual controlado

1. Conectar o WhatsApp.
2. Configurar limite baixo.
3. Criar lote com poucos contatos autorizados.
4. Iniciar lote.
5. Verificar ordem.
6. Verificar intervalo.
7. Pausar.
8. Confirmar ausencia de novos envios.
9. Retomar.
10. Desconectar o WhatsApp.
11. Confirmar pausa automatica.
12. Reconectar.
13. Retomar.
14. Parar lote.
15. Verificar cancelamento dos pendentes.
16. Consultar historico.
