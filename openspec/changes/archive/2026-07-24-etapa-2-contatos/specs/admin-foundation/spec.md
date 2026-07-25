## ADDED Requirements

### Requirement: Contact Role Permissions
The system SHALL add contact permissions to existing roles.

#### Scenario: Administrator contact permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all contact permissions.

#### Scenario: Operator contact permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive view, create, update, import, export, manage tags, mark do-not-contact, and sensitive-data contact permissions, but not restore.

#### Scenario: Consulta contact permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL receive view contact permission and export only when authorized by configuration.

### Requirement: Contact Navigation
The administrative menu SHALL include contact navigation according to permissions.

#### Scenario: Contact menu
- **WHEN** a user has `contacts.view`
- **THEN** the menu SHALL include links for all contacts, new contact, import, import history, and tags as permitted.
