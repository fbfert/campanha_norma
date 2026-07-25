## MODIFIED Requirements

### Requirement: Dashboard
The system SHALL provide an initial dashboard with administrative foundation metrics in Etapa 1 and operational WhatsApp/message metrics only when those future modules exist.

#### Scenario: Dashboard metrics
- **WHEN** an administrator opens the dashboard
- **THEN** the system SHALL show total contacts, active contacts, blocked contacts, messages sent today, remaining daily limit, pending messages, failed messages, active batches, WhatsApp connection status, allowed sending window, and latest service activity.

#### Scenario: Etapa 1 dashboard metrics
- **WHEN** an administrator opens the dashboard during Etapa 1
- **THEN** the system SHALL show active users, blocked users, administrator count, current user's latest access, general system situation, environment, Laravel version, and PHP version.

#### Scenario: Future dashboard metrics are reserved
- **WHEN** a dashboard card refers to contacts, messages sent today, active batches, send failures, or WhatsApp status before those modules are implemented
- **THEN** it SHALL display `Modulo ainda nao implementado`
- **AND** SHALL NOT create fake module tables or fake operational records.
