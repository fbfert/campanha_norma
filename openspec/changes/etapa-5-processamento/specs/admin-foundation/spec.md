## MODIFIED Requirements

### Requirement: Laravel Foundation Configuration
The system SHALL be a Laravel administrative application configured with Brazilian Portuguese locale, `America/Sao_Paulo` timezone, MySQL defaults, Redis cache, Redis Laravel Queue, database-backed sessions, Blade, Livewire, Alpine.js, Vite, and Apache deployment support.

#### Scenario: Application defaults
- **WHEN** the application boots
- **THEN** locale SHALL be `pt_BR`
- **AND** fallback locale SHALL be `pt_BR`
- **AND** timezone SHALL be `America/Sao_Paulo`.

## ADDED Requirements

### Requirement: Message Processing Permissions
The system SHALL add backend-protected permissions for message processing viewing, start, pause, resume, stop, recipient cancellation, retry, attempt viewing, settings management, and maintenance.

#### Scenario: Administrator processing permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all processing permissions.

#### Scenario: Operator processing permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive processing view, start, pause, resume, stop, cancel-recipient, retry, and attempt-view permissions, but not global settings or maintenance permissions by default.

#### Scenario: Consulta processing permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL receive only processing view permission.

### Requirement: Message Processing Navigation
The administrative menu SHALL include message processing and sending settings navigation according to permissions.

#### Scenario: Processing menu
- **WHEN** a user has message processing permissions
- **THEN** the message menu SHALL include processing, batch, and sending settings links as permitted.
