# Processamento de mensagens

## Arquitetura

O processamento da Etapa 5 ocorre no Laravel, com Redis e Laravel Queue. O navegador chama apenas o Laravel; o worker chama a camada `WhatsAppProvider`, que conversa com o serviço Node.js privado.

Fluxo:

1. Um lote `ready` e iniciado manualmente.
2. O lote muda para `queued`.
3. O comando `messages:dispatch-pending` ou o job recorrente libera o próximo destinatário.
4. O worker processa um destinatário por vez na fila `whatsapp-messages`.
5. Antes de enviar, o sistema revalida contato, conexão, janela, limites e idempotência.
6. Tentativas, eventos e auditoria são registrados.

## Estados

Lotes: `draft`, `validating`, `ready`, `queued`, `processing`, `pausing`, `paused`, `stopping`, `stopped`, `completed`, `completed_with_errors`, `failed`, `cancelled`.

Destinatários: `eligible`, `pending`, `waiting_schedule`, `waiting_minute_limit`, `waiting_minimum_interval`, `waiting_hour_limit`, `waiting_day_limit`, `queued`, `processing`, `sent`, `retry_wait`, `failed_temporary`, `failed_permanent`, `cancelled`, `skipped`.

## Travas de ritmo

São quatro, e sempre vence a mais restritiva:

```text
max_per_minute            limite de mensagens por minuto
max_per_hour              limite por hora
max_per_day               limite por dia
minimum_interval_seconds  intervalo obrigatorio entre uma mensagem e a proxima
```

O intervalo mínimo se sobrepõe aos demais: com 60 segundos, o teto real e uma
mensagem por minuto, e o limite por minuto configurado nunca chega a ser
exercido.

Cada trava tem status e mensagem próprios, com o valor configurado no texto.
Enquanto intervalo e limite por minuto dividiam o status `waiting_minute_limit`,
quem via "aguardando limite por minuto" com o limite folgado procurava no campo
errado.

**Status de espera novo precisa entrar em todas as listas** — despachante,
pausar, parar, retomar, cancelar, reprocessar, painel e tela de processamento.
Fora da consulta do despachante, o destinatário nunca mais e escolhido: fica
parado para sempre, sem erro visível.

## Trava de reciprocidade

As quatro travas acima são de ritmo, e todas olham só para o nosso lado: dá para
abordar mil pessoas em ritmo impecável sem que nenhuma responda, e nada nota. Os
contadores mostram sucesso, porque entregar a mensagem é sucesso.

```text
unanswered_lock_threshold  pessoas abordadas sem resposta que param o envio
```

Quando o número de pessoas abordadas que nunca responderam alcança o teto, o
envio de lotes e campanhas para. O que destrava não é o relógio: é alguém do
outro lado responder.

A contagem é de **pessoas, não de mensagens**. Quem recebeu três e não respondeu
conta uma vez — o que se mede é quanta gente está em silêncio, e mandar mais
para a mesma pessoa não aumenta o alcance. A mesma pessoa pode aparecer em
várias campanhas; o banco só impede repetir dentro de um lote.

O destinatário fica em `waiting_reciprocity` e **o lote não é pausado**.
Reaproveitar "aguardando horário" faria a tela dizer que basta esperar o
relógio, e pausar o lote exigiria reinício à mão; segurar o destinatário deixa a
retomada automática assim que a contagem cair.

A trava não alcança as respostas da automação a quem escreveu: quem falou com a
gente não pode ficar sem retorno por causa dela. **Teto zero desliga**, que é o
comportamento de antes de ela existir.

Ao escolher o valor, olhe o número atual — a tela de configurações mostra ao
lado do campo. Um teto abaixo da contagem de hoje tranca o envio no próximo
clique, e um teto colado nela faz o lote andar no ritmo exato das respostas: uma
resposta libera um envio.

## Redis e filas

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_QUEUE=whatsapp-messages
```

Redis deve aceitar conexões somente locais. Não exponha Redis na internet.

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

## Recuperação

`messages:recover-stuck` identifica destinatários em `processing` por mais tempo que `messages.processing_timeout_minutes` e marca o resultado como `SEND_RESULT_UNKNOWN`. O sistema não reenvia automaticamente esses casos.

## Logs

Canal: `message_processing`.

Dados seguros: `batch_id`, `recipient_id`, `request_id`, evento, status, tentativa e código de erro.

Não registrar: token, QR Code, sessão, cookies, mensagem completa ou dados pessoais desnecessários.

## Teste manual controlado

1. Conectar o WhatsApp.
2. Configurar limite baixo.
3. Criar lote com poucos contatos autorizados.
4. Iniciar lote.
5. Verificar ordem.
6. Verificar intervalo.
7. Pausar.
8. Confirmar ausência de novos envios.
9. Retomar.
10. Desconectar o WhatsApp.
11. Confirmar pausa automática.
12. Reconectar.
13. Retomar.
14. Parar lote.
15. Verificar cancelamento dos pendentes.
16. Consultar histórico.
