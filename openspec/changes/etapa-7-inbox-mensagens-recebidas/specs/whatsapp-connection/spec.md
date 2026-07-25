## MODIFIED Requirements

### Requirement: Private Internal API
The Node.js service SHALL expose a private authenticated internal API under `/api` for health, status, QR Code, connect, reconnect, disconnect, test-message sending, session deletion, and controlled manual message sending, and SHALL forward received WhatsApp messages to Laravel through a signed internal webhook.

#### Scenario: Laravel sends a manual conversation reply
- **WHEN** Laravel sends a manual reply through the provider abstraction
- **THEN** the provider SHALL call the Node.js service with `request_id`, `phone`, and `message`
- **AND** the service SHALL return the same `request_id`, status, and provider message id when available.

## ADDED Requirements

### Requirement: Signed Incoming Webhook
The Node.js service SHALL sign incoming-message webhooks to Laravel using HMAC-SHA256 with timestamp, nonce, and raw body.

#### Scenario: Incoming message forwarded
- **WHEN** the Node.js service receives a WhatsApp message from a person
- **THEN** it SHALL send a webhook payload to Laravel
- **AND** include `X-Webhook-Timestamp`, `X-Webhook-Nonce`, and `X-Webhook-Signature`.

### Requirement: Incoming Event Filtering
The Node.js service SHALL ignore group messages for inbox processing and SHALL classify externally sent own-number messages without creating automated behavior.

#### Scenario: Group message received
- **WHEN** a group message is detected
- **THEN** the service SHALL NOT enqueue it as an inbox conversation message.
