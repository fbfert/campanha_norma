## ADDED Requirements

### Requirement: Inbox Permissions
The system SHALL add backend-protected permissions for inbox viewing, message content, replies, assignments, status, priority, tags, notes, archiving, blocking, do-not-contact, contact association, and metrics.

#### Scenario: Administrator inbox permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all inbox permissions.

#### Scenario: Operator inbox permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive default permissions to view permitted conversations, reply manually, assign, change status, add notes, manage tags, archive, and associate contacts according to configuration.

#### Scenario: Consulta inbox permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL receive only authorized view permissions and SHALL NOT be able to reply, assign, archive, block, or change do-not-contact.

### Requirement: Inbox Navigation
The administrative menu SHALL expose Atendimento and inbox navigation according to permissions.

#### Scenario: Inbox menu
- **WHEN** a user has `inbox.view`
- **THEN** the menu SHALL include Caixa de entrada and related conversation filters.
