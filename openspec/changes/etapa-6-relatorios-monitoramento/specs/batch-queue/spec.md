## ADDED Requirements

### Requirement: Operational Reports
The system SHALL calculate operational reports from persisted batches, recipients, attempts, events, snapshots, and sending settings.

#### Scenario: Batch indicators
- **WHEN** a report calculates success, failure, cancellation, retry, or average duration rates
- **THEN** it SHALL use documented formulas
- **AND** SHALL show `—` or `Sem dados suficientes` instead of misleading zero percentages when the denominator is zero.

### Requirement: Report Exports
The system SHALL export protected report data as CSV or XLSX and record export lifecycle state.

#### Scenario: Large export
- **WHEN** an export exceeds the synchronous row threshold
- **THEN** the export SHALL be stored as `pending` or `processing` for queued processing
- **AND** the generated file SHALL be outside the public directory.

### Requirement: Operational Monitoring
The system SHALL diagnose application, database, Redis, queues, workers, Scheduler, Node.js service, storage, stuck messages, failed jobs, and inconsistent batches.

#### Scenario: Health statuses
- **WHEN** diagnostics are shown
- **THEN** each item SHALL use one of `healthy`, `warning`, `critical`, or `unknown`
- **AND** SHALL include a textual explanation, not only color.

### Requirement: Operational Heartbeats
The system SHALL persist worker and Scheduler heartbeats for diagnostics.

#### Scenario: Stale worker
- **WHEN** a worker heartbeat is older than the configured threshold
- **THEN** monitoring SHALL report warning or critical according to configuration.
