## MODIFIED Requirements

### Requirement: Connection States
The system SHALL represent WhatsApp connection status using the approved Etapa 3 technical states in Laravel, Node.js, persisted records, and internal API responses.

#### Scenario: State transition visibility
- **WHEN** the service changes state
- **THEN** the application SHALL expose one of `not_initialized`, `starting`, `generating_qr`, `waiting_for_qr_scan`, `authenticating`, `connected`, `reconnecting`, `disconnecting`, `disconnected`, `session_expired`, `authentication_failed`, `browser_error`, or `service_error`.
- **AND** Portuguese text MAY be used only as UI labels for those enum values.

### Requirement: Private Internal API
The Node.js service SHALL expose a private authenticated internal API under `/api` for health, status, QR Code, connect, reconnect, disconnect, test-message sending, and session deletion.

#### Scenario: Laravel sends a message
- **WHEN** Laravel calls `POST /api/test-message`
- **THEN** the request SHALL include `request_id`, `phone`, and `message`
- **AND** the service SHALL return success status, the same `request_id`, external message id when available, and provider status.

## ADDED Requirements

### Requirement: Laravel Provider Boundary
The Laravel application SHALL communicate with WhatsApp only through a provider contract and the `WhatsAppWebProvider` implementation.

#### Scenario: Controller requests status
- **WHEN** a Laravel controller needs WhatsApp status
- **THEN** it SHALL call the provider/service abstraction
- **AND** it SHALL NOT call Node.js endpoints directly.

### Requirement: Connection Persistence
The Laravel application SHALL persist the latest single WhatsApp connection state without storing QR Codes, service tokens, browser credentials, or session content.

#### Scenario: Status sync
- **WHEN** Laravel receives a status result from the provider
- **THEN** it SHALL update the single `whatsapp_connections` record with safe state, account, activity, error, and metadata fields.

### Requirement: Technical Connection Events
The system SHALL store WhatsApp technical events for connection, QR, reconnection, disconnection, session clearing, errors, and test-message outcomes.

#### Scenario: Session cleared
- **WHEN** an administrator clears the WhatsApp session
- **THEN** the system SHALL record a technical event
- **AND** the event SHALL NOT include tokens, QR Code content, cookies, or session data.

### Requirement: Manual Test Message
The system SHALL support exactly one manual test message to one existing contact per operation.

#### Scenario: Restricted contact
- **WHEN** a contact is inactive, blocked, missing a valid phone, or marked do-not-contact
- **THEN** the test message SHALL be rejected before calling the provider.

#### Scenario: Idempotent request
- **WHEN** the same `request_id` is submitted more than once
- **THEN** the system SHALL NOT send the message more than once.

### Requirement: Node.js Runtime Management
The Node.js service SHALL be documented for Linux deployment as a persistent systemd-managed service.

#### Scenario: Service restart
- **WHEN** the service is restarted on the VPS
- **THEN** it SHALL attempt a controlled session restore
- **AND** it SHALL keep health/status endpoints available for Laravel.
