# Gerenciador WhatsApp Service

Servico Node.js privado para validacao inicial de conexao com WhatsApp Web por QR Code.

## Requisitos

- Node.js LTS compativel com o ambiente da VPS.
- Chromium ou navegador compativel para `whatsapp-web.js`.
- Porta local `127.0.0.1:3100` disponivel.
- Token forte compartilhado com o Laravel em `WHATSAPP_SERVICE_TOKEN`.

## Dependencias principais

- `whatsapp-web.js` 1.34.7: cliente WhatsApp Web.
- `express` 5.2.1: API HTTP privada.
- `qrcode` 1.5.4: Data URI do QR Code.
- `zod` 4.4.3: validacao de entrada.
- `vitest` 4.1.10 e `supertest` 7.2.2: testes automatizados.

## Instalacao

```bash
npm install
cp .env.example .env
npm run build
```

Configure `.env` sem commitar tokens reais.

## Execucao

```bash
npm run dev
npm run build
npm start
```

Em producao, use `HOST=127.0.0.1` e gerencie o processo por systemd.

## Variaveis

Veja `.env.example`. A sessao deve ficar fora do diretorio publico, por exemplo:

```bash
sudo mkdir -p /var/lib/gerenciador-whatsapp/session
sudo chown -R gerenciador-whatsapp:gerenciador-whatsapp /var/lib/gerenciador-whatsapp
sudo chmod -R 700 /var/lib/gerenciador-whatsapp
```

## Chromium

Instale as dependencias do Chromium conforme a distribuicao Linux. `BROWSER_NO_SANDBOX=false` e o padrao. Use `true` apenas quando tecnicamente indispensavel no ambiente e documente a justificativa operacional.

## Endpoints

Todos os endpoints ficam sob `/api` e exigem:

```http
Authorization: Bearer TOKEN_INTERNO
Content-Type: application/json
```

- `GET /api/health`
- `GET /api/status`
- `POST /api/connect`
- `GET /api/qrcode`
- `POST /api/reconnect`
- `POST /api/disconnect`
- `DELETE /api/session`
- `POST /api/test-message`

## Envio individual de teste

O telefone pode chegar com ou sem `+`; a API remove caracteres nao numericos antes do envio. Antes de chamar `sendMessage`, o servico valida o destino com `getNumberId()` do WhatsApp Web. Se o numero nao for reconhecido, retorna `INVALID_PHONE`.

Algumas versoes/estados do WhatsApp Web podem concluir o envio sem devolver um objeto de mensagem com identificador externo. Nesses casos, desde que `sendMessage` nao lance excecao, o servico retorna `sent` com `external_message_id = null`.

## Mensagens recebidas

Quando `INCOMING_MESSAGE_ENABLED=true`, o servico escuta eventos do WhatsApp Web e encaminha mensagens recebidas ao Laravel:

```text
LARAVEL_INCOMING_WEBHOOK_URL=https://mensagens.exemplo.com/internal/whatsapp/incoming
LARAVEL_INCOMING_WEBHOOK_SECRET=
```

O payload e assinado com HMAC-SHA256 nos cabecalhos:

```text
X-Webhook-Timestamp
X-Webhook-Nonce
X-Webhook-Signature
```

Mensagens de grupo sao ignoradas. Midias sao registradas apenas como metadados nesta etapa. O servico nao envia respostas automaticas.

## Codigos de erro

`SERVICE_UNAVAILABLE`, `UNAUTHORIZED_SERVICE_REQUEST`, `INVALID_REQUEST`, `CLIENT_NOT_INITIALIZED`, `CLIENT_ALREADY_STARTING`, `QR_NOT_AVAILABLE`, `QR_EXPIRED`, `AUTHENTICATION_FAILED`, `SESSION_EXPIRED`, `WHATSAPP_NOT_CONNECTED`, `INVALID_PHONE`, `EMPTY_MESSAGE`, `MESSAGE_TOO_LONG`, `DUPLICATE_REQUEST`, `SEND_FAILED`, `BROWSER_START_FAILED`, `BROWSER_DISCONNECTED`, `SESSION_DELETE_FAILED`, `INTERNAL_ERROR`.

## Logs e seguranca

Os logs sao estruturados e nao devem registrar QR Code, token, cookies, sessao, credenciais ou corpo completo de mensagens. O servico nao deve ser exposto publicamente.

## Testes

```bash
npm test
npm run lint
```

Os testes usam mocks e nao conectam no WhatsApp real.
