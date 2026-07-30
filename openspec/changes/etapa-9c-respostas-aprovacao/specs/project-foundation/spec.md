## ADDED Requirements

### Requirement: Response Generation Configuration
Generation behaviour SHALL be configurable without code changes, covering the operation mode, queue name, active prompt and schema versions, confidence threshold, deepening limit, debounce interval, maximum text length, forbidden expression lists, institutional fallback text, automatic sending allowlist and suggestion validity ceiling.

#### Scenario: No hardcoded operational values
- **WHEN** generation runs
- **THEN** limits, thresholds, queue names, prompt versions, expression lists and operational texts SHALL come from configuration or system settings
- **AND** SHALL NOT be hardcoded in services, jobs or commands.

#### Scenario: Safe defaults
- **WHEN** the settings seeder runs
- **THEN** the operation mode SHALL require human approval or be more restrictive
- **AND** the automatic sending allowlist SHALL be empty.

### Requirement: Isolated Generation Queue
Reply generation SHALL run on a dedicated queue with configured attempts, backoff, timeout and failure handling, separate from incoming message processing, deterministic evaluation and interpretation.

#### Scenario: Queue isolation
- **WHEN** generation jobs are queued
- **THEN** they SHALL use the generation queue
- **AND** a slow provider SHALL NOT delay incoming message registration, deterministic evaluation or interpretation.
