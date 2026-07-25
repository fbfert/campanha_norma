## ADDED Requirements

### Requirement: Consolidated Message History
The system SHALL provide a protected consolidated history of message batch recipients using preserved snapshots.

#### Scenario: Viewing message history
- **WHEN** an authorized user opens `/admin/histories/messages`
- **THEN** the system SHALL show batch, recipient, snapshot contact data, rendered message when permitted, processing status, attempts, timestamps, provider, external id, and error information.

#### Scenario: Snapshot separation
- **WHEN** current contact data differs from send snapshots
- **THEN** the system SHALL show snapshot data separately from current contact data
- **AND** SHALL NOT recalculate old messages.

### Requirement: Contact Message History
The system SHALL provide message history by contact.

#### Scenario: Contact history
- **WHEN** an authorized user opens a contact message history
- **THEN** the system SHALL show message attempts, batches, statuses, errors, and snapshots for that contact even when current contact data changed.

### Requirement: History Privacy Controls
The system SHALL protect full message content and technical details with dedicated permissions.

#### Scenario: Restricted content
- **WHEN** a user lacks `histories.view_message_content`
- **THEN** the full rendered message SHALL be hidden or summarized.

### Requirement: Report And Maintenance Audit Events
The system SHALL audit history views, history exports, report views, export requests/downloads, monitoring diagnostics, and maintenance actions without storing complete message content.

#### Scenario: Export audit
- **WHEN** an authorized user exports a report or history
- **THEN** a general audit event SHALL record report type, format, filters, columns, and user without tokens, QR Codes, sessions, or complete message bodies.

### Requirement: Retention Preservation
Retention and cleanup SHALL preserve required message history, contact snapshots, audit records, do-not-contact records, consent evidence, attempts, and relevant events.

#### Scenario: Applying retention
- **WHEN** retention is applied
- **THEN** the system SHALL clean only eligible transient data such as expired exports, temporary files, old technical logs, caches, or transitional data.
