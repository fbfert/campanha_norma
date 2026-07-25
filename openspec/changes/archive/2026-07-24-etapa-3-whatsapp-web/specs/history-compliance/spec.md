## ADDED Requirements

### Requirement: WhatsApp Audit Events
The system SHALL audit WhatsApp connection viewing, QR requests, connect requests, connection changes, reconnect requests, disconnect requests, session clear requests, test-message requests, test-message successes, and test-message failures.

#### Scenario: QR audit safety
- **WHEN** a QR Code is requested
- **THEN** audit logs SHALL record the administrative action
- **AND** SHALL NOT store QR Code content.

### Requirement: WhatsApp Test Message Records
The system SHALL store manual WhatsApp test-message records with contact, user, unique request id, phone snapshot, message, status, provider id when available, timing, and safe error information.

#### Scenario: Failed test message
- **WHEN** a manual test message fails
- **THEN** the system SHALL store the failure status, code, and legible error message
- **AND** SHALL NOT log service tokens or session contents.
