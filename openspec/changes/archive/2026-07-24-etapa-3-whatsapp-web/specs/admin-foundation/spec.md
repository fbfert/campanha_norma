## ADDED Requirements

### Requirement: WhatsApp Role Permissions
The system SHALL add backend-protected WhatsApp permissions for connection viewing, connection management, disconnection, session clearing, test-message sending, and technical event viewing.

#### Scenario: Administrator permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all WhatsApp permissions.

#### Scenario: Operator permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive WhatsApp connection view permission by default
- **AND** SHALL NOT receive disconnect, session clear, or test-message permission by default.

### Requirement: WhatsApp Navigation
The administrative menu SHALL expose WhatsApp connection navigation according to permissions.

#### Scenario: Connection menu
- **WHEN** a user has `whatsapp.connection.view`
- **THEN** the menu SHALL include an active link to the WhatsApp connection screen.
