# history-compliance Specification

## Purpose

Define history, snapshots, event logs, errors, audit logging, LGPD controls, opt-out behavior, and sensitive-data handling.
## Requirements
### Requirement: Batch History
The system SHALL provide history by batch with message, date, responsible user, selected count, eligible count, sent count, failures, canceled count, status, and duration.

#### Scenario: Viewing a completed batch
- **WHEN** an administrator opens batch history
- **THEN** the system SHALL show the batch result totals and timing data.

### Requirement: Recipient History
The system SHALL provide history by recipient with contact data snapshot, sent message, position, attempts, timestamps, result, error, and status changes.

#### Scenario: Viewing recipient attempt history
- **WHEN** an administrator opens a recipient record inside a batch
- **THEN** the system SHALL show all attempts and status changes for that recipient.

### Requirement: Send Snapshots
The system SHALL store a copy of the contact data and rendered message used at send time.

#### Scenario: Contact edited after sending
- **WHEN** a contact is edited after a message attempt
- **THEN** the previous history SHALL continue showing the original `contact_name_snapshot`, `contact_phone_snapshot`, `contact_city_snapshot`, and `rendered_message`.

### Requirement: Error Codes
The system SHALL record expected error codes for validation, provider, communication, timeout, session, and unknown failures.

#### Scenario: Known failure
- **WHEN** a send attempt fails
- **THEN** the system SHALL store an error code such as `telefone_invalido`, `placeholder_sem_valor`, `contato_bloqueado`, `whatsapp_desconectado`, `sessao_expirada`, `tempo_limite_excedido`, `erro_no_servico_node`, `falha_de_comunicacao`, or `erro_desconhecido`.

### Requirement: Message Events
The system SHALL store recipient events in `message_events` or an equivalent event history.

#### Scenario: Status transition
- **WHEN** a recipient status changes
- **THEN** the system SHALL record an event containing the event type, payload, and timestamp.

### Requirement: LGPD Controls
The system SHALL provide mechanisms to record data origin, contact purpose, authorization when applicable, correction, blocking, deletion or anonymization when necessary, access control, relevant operation logs, and retention policy.

#### Scenario: Data subject block request
- **WHEN** a person asks not to be contacted again
- **THEN** the system SHALL mark the person or phone as do-not-contact
- **AND** future batches SHALL be blocked for that phone.

### Requirement: Sensitive Log Protection
Logs SHALL NOT record tokens, QR Codes, complete sessions, or unnecessary sensitive personal data.

#### Scenario: Provider error logging
- **WHEN** the Node.js service or Laravel provider logs an error
- **THEN** the log SHALL omit secret tokens, QR Codes, raw session content, and unnecessary message/session details.

### Requirement: Initial Message Only
The system SHALL automate only the initial message and SHALL NOT automate complete conversations in the first version.

#### Scenario: Recipient responds
- **WHEN** a recipient responds after the initial message
- **THEN** the continuation of the conversation SHALL be manual and human through WhatsApp.

### Requirement: Foundation Audit Events
The system SHALL audit foundation-stage security and administrative events without recording passwords, tokens, raw sessions, or secrets.

#### Scenario: Authentication audit
- **WHEN** a user logs in, logs out, or a relevant login failure occurs
- **THEN** the system SHALL record an audit event with user when available, action, IP address, user agent, and safe description.

#### Scenario: Administrative user audit
- **WHEN** a user is created, edited, blocked, unblocked, assigned roles, soft-deleted, or has a password reset by an administrator
- **THEN** the system SHALL record an audit event with safe old and new values.

#### Scenario: Password audit safety
- **WHEN** a password is changed or reset
- **THEN** the audit log SHALL record the action
- **AND** SHALL NOT store the password or password hash.

### Requirement: Contact History Events
The system SHALL store contact-specific history for creation, updates, status changes, tag changes, do-not-contact changes, imports, deletes, and restores.

#### Scenario: Contact update history
- **WHEN** a contact is changed
- **THEN** contact history SHALL record user, action, safe old values, safe new values, and timestamp.

### Requirement: Contact Audit Events
The system SHALL audit contact creation, editing, phone changes, status changes, do-not-contact changes, import, export, soft delete, restore, and bulk tag application.

#### Scenario: Import audit safety
- **WHEN** a contact import is audited
- **THEN** audit logs SHALL NOT store complete spreadsheets or imported files.

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
