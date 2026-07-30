## ADDED Requirements

### Requirement: Analytics Data Minimization
Analytical reporting SHALL operate on aggregates by default and SHALL NOT expose personal identifiers to users who hold only aggregate permissions.

#### Scenario: Aggregated reading never carries identifiers
- **WHEN** an analytical report is rendered for a user holding only the aggregate permission
- **THEN** the response SHALL contain counts, labels, rates and periods
- **AND** it SHALL NOT contain contact name, phone number, message text or contact identifier.

#### Scenario: Export requests are audited
- **WHEN** an analytical export is requested
- **THEN** an audit entry SHALL record the user, the report type, the filters, the stated purpose when required and the expiration.

#### Scenario: Logs stay free of content
- **WHEN** metric materialization or export processing is logged
- **THEN** the log SHALL contain identifiers, counts and durations
- **AND** it SHALL NOT contain message content or contact phone numbers.
