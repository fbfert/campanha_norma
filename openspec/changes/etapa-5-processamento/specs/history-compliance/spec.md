## ADDED Requirements

### Requirement: Message Processing Audit Events
The system SHALL audit batch processing starts, pause requests, pauses, resumes, stop requests, stops, completions, recipient cancellations, retry requests, settings updates, and maintenance execution.

#### Scenario: Processing control audit
- **WHEN** an authorized user starts, pauses, resumes, stops, retries, or cancels processing
- **THEN** a general audit log SHALL record the safe action, user, entity, and non-sensitive metadata.

### Requirement: Processing Log Safety
The system SHALL log processing diagnostics without recording tokens, QR Codes, cookies, sessions, complete messages, or unnecessary personal data.

#### Scenario: Provider failure during processing
- **WHEN** a provider call fails while sending a batch recipient
- **THEN** logs SHALL include safe identifiers such as batch id, recipient id, request id, event, status, attempt, error code, and duration
- **AND** SHALL NOT include the complete message body or service token.
