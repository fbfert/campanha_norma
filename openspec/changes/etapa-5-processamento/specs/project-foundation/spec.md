## MODIFIED Requirements

### Requirement: Dashboard
The system SHALL provide a dashboard with administrative foundation metrics, real contact metrics after Etapa 2, real WhatsApp connection status after Etapa 3, real message template and prepared-batch metrics after Etapa 4, and real processing metrics after Etapa 5.

#### Scenario: Dashboard metrics
- **WHEN** an administrator opens the dashboard after Etapa 5
- **THEN** the system SHALL show total contacts, active contacts, blocked contacts, messages sent today, pending messages, failed messages, processing batches, paused batches, WhatsApp connection status, daily limit usage, next send time, and latest processing activity where the user has permission.

#### Scenario: Future dashboard metrics are reserved
- **WHEN** a dashboard card refers to inbox, chatbot, attachments, multiple accounts, official API, or advanced reports before those modules are implemented
- **THEN** it SHALL display `Modulo ainda nao implementado`
- **AND** SHALL NOT create fake operational records.
