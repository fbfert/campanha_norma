## MODIFIED Requirements

### Requirement: Dashboard
The system SHALL provide a dashboard with administrative foundation metrics, contact metrics, WhatsApp connection status, message authoring metrics, processing metrics, operational monitoring metrics, and inbox metrics.

#### Scenario: Inbox dashboard metrics
- **WHEN** an authorized user opens the dashboard after Etapa 7
- **THEN** the system SHALL show new messages, conversations waiting for an operator, unassigned conversations, received messages today, manual replies today, manual reply failures, average time to open, and average time to first manual reply when available.

## ADDED Requirements

### Requirement: Internal Incoming Webhook
The Laravel application SHALL provide a signed internal incoming-message webhook that validates content type, body size, timestamp, nonce, and HMAC signature before queueing work.

#### Scenario: Invalid signature
- **WHEN** an incoming webhook has an invalid signature, stale timestamp, reused nonce, invalid content type, or invalid payload
- **THEN** Laravel SHALL reject the request without queueing processing.
