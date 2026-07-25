## MODIFIED Requirements

### Requirement: Dashboard
The system SHALL provide a dashboard with administrative foundation metrics, contact metrics, WhatsApp connection status, message authoring metrics, processing metrics, and Etapa 6 operational monitoring metrics.

#### Scenario: Operational dashboard
- **WHEN** an authorized user opens the dashboard after Etapa 6
- **THEN** the system SHALL show sent messages today and this month, failures today and this month, processing and paused batches, completed batches today, waiting messages, retrying attempts, uncertain results, daily limit usage, WhatsApp status, worker status, Redis status, and Scheduler status.
- **AND** unavailable diagnostics SHALL be shown as warning, critical, or unknown without exposing secrets.
