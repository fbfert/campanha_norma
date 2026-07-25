## ADDED Requirements

### Requirement: Message Template Audit Events
The system SHALL audit template creation, update, version creation, duplication, activation, inactivation, soft deletion, and restoration.

#### Scenario: Template body update
- **WHEN** a template body changes
- **THEN** audit logs SHALL record the operation safely
- **AND** a template version record SHALL preserve the body inside protected module tables.

### Requirement: Message Batch Audit Events
The system SHALL audit batch creation, update, contact selection, validation, randomization, ready marking, duplication, and cancellation.

#### Scenario: Batch ready
- **WHEN** a batch is marked ready
- **THEN** the system SHALL record a batch event and a general audit event.

### Requirement: Batch Snapshot History
The system SHALL preserve batch message and contact snapshots without relying on current contact values.

#### Scenario: Viewing old batch
- **WHEN** a batch recipient is viewed after contact changes
- **THEN** the historical snapshot SHALL remain available.
