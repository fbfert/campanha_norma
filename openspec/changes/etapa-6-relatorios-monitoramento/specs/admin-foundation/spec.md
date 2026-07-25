## ADDED Requirements

### Requirement: History Report Monitoring Maintenance Permissions
The system SHALL add backend-protected permissions for histories, reports, monitoring, and maintenance.

#### Scenario: Administrator permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all history, report, monitoring, and maintenance permissions.

#### Scenario: Operator permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive history view, attempt view, history export, report view/export/contact-data/operational-metrics, monitoring view, and retry-eligible maintenance permissions, but not cleanup or retention permissions by default.

#### Scenario: Consulta permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL receive only authorized summary history/report permissions and SHALL NOT receive technical details or maintenance permissions.

### Requirement: Report And Operation Navigation
The administrative menu SHALL include report and operation navigation according to permissions.

#### Scenario: Reports menu
- **WHEN** a user has report permissions
- **THEN** the menu SHALL include overview, batches, messages, errors, not-sent, attempts, rate limits, contacts, and templates report links as permitted.

#### Scenario: Operation menu
- **WHEN** a user has operation permissions
- **THEN** the menu SHALL include monitoring, report exports, and maintenance links as permitted.
