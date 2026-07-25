## Decisions

- Laravel remains the only public web application. Browser clients never call the Node.js service directly.
- The Node.js service binds to `127.0.0.1` by default and all `/api/*` endpoints require a bearer token.
- The Laravel domain code depends on `WhatsAppProvider`; `WhatsAppWebProvider` is the only Etapa 3 implementation.
- QR Code content is transient and returned through Laravel only for authorized users; it is not stored in the database or logs.
- A single connection record is supported in Etapa 3 to match the one-account limit.
- Test-message sending is manual, one contact per request, idempotent by `request_id`, and blocked for inactive, blocked, or do-not-contact contacts.
- Automated tests mock the WhatsApp client and do not connect to WhatsApp or send real messages.

## Constraints

- No campaigns, batches, templates, placeholders, scheduling, queue dispatch, groups, attachments, or inbox features are introduced.
- Node.js session files are stored outside public directories and excluded from source control.
- `--no-sandbox` is off by default and documented as an environment-specific production exception.
