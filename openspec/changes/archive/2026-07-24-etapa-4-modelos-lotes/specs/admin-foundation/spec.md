## ADDED Requirements

### Requirement: Message Authoring Role Permissions
The system SHALL add backend-protected permissions for message templates and message batches.

#### Scenario: Administrator message permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all message template and batch permissions.

#### Scenario: Operator message permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL be able to view/create/update templates, create/update/cancel batches, view recipients, and export previews.

#### Scenario: Consulta message permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL be able to view templates and batches only.

### Requirement: Message Navigation
The administrative menu SHALL include message template and batch navigation according to permissions.

#### Scenario: Message menu
- **WHEN** a user has message authoring permissions
- **THEN** the menu SHALL include links to models, new batch, and batches as permitted.
