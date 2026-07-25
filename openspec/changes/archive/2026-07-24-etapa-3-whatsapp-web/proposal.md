## Why

The project needs the initial WhatsApp Web validation integration after the administrative foundation and contact module. Etapa 3 adds a private Node.js service, QR Code authentication, session state monitoring, controlled session actions, and a single manual test message path without introducing campaigns or bulk sending.

## What Changes

- Add a private Node.js WhatsApp Web service with authenticated internal API, QR Code generation, session persistence, limited reconnection, disconnect, session deletion, health/status endpoints, and idempotent test-message sending.
- Add Laravel provider abstraction, HTTP client, connection state storage, technical events, test-message records, permissions, routes, screens, dashboard status, and audit integration.
- Update the approved WhatsApp connection state contract from the earlier Portuguese UI states to the Etapa 3 technical enum values used by Laravel and Node.js.
- Document VPS installation, Apache boundary, service environment, systemd, logs, security constraints, and manual test procedure.
- Preserve Etapa 3 boundaries: no message templates, placeholders, batch/campaign sending, queues for dispatch, limits, schedules, inbox, chatbot, attachments, groups, multiple accounts, or official Meta API.

## Capabilities

### Modified Capabilities
- `whatsapp-connection`: Implements the initial WhatsApp Web provider integration and technical API behavior.
- `admin-foundation`: Adds WhatsApp permissions and menu navigation.
- `project-foundation`: Updates dashboard behavior so WhatsApp status is real in Etapa 3 while other message/batch cards remain reserved.
- `history-compliance`: Adds WhatsApp connection events and safe audit logging.

## Impact

- Adds Laravel migrations, models, enums, contracts, data objects, services, controllers, requests, views, tests, configuration, and documentation.
- Adds a `whatsapp-service/` TypeScript Node.js service with tests and deployment documentation.
- Requires a strong shared service token in both Laravel and Node.js environments.
