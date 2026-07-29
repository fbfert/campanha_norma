## ADDED Requirements

### Requirement: Interpretation Configuration
Interpretation behaviour SHALL be configurable without code changes, covering global enablement, classification and extraction enablement, queue name, active prompt and schema versions, confidence thresholds, input and context limits, circuit breaker parameters, attempt limits, retention, anonymization, sensitive expression lists and the reprocessing confirmation threshold.

#### Scenario: No hardcoded operational values
- **WHEN** the interpretation pipeline runs
- **THEN** limits, thresholds, queue names, prompt versions and expression lists SHALL come from configuration or system settings
- **AND** SHALL NOT be hardcoded in services, jobs or commands.

#### Scenario: Safe defaults
- **WHEN** the settings seeder runs
- **THEN** interpretation SHALL default to disabled
- **AND** enabling it SHALL be an explicit administrative action.

### Requirement: Separated Capability Flags
Each capability SHALL have its own configuration key, so that no single key mixes the conversational flow engine, artificial intelligence analysis and future response generation.

#### Scenario: Distinct responsibilities
- **WHEN** capability flags are read
- **THEN** the conversational flow engine, the artificial intelligence master switch, the analysis switch and the response generation switch SHALL be independent keys.

#### Scenario: Master switch does not enable analysis
- **WHEN** only the artificial intelligence master switch is enabled
- **THEN** no classification or extraction SHALL run
- **AND** no provider call SHALL be made.

#### Scenario: Reserved future flags
- **WHEN** the settings seeder runs
- **THEN** the response generation and automatic sending flags SHALL be created disabled
- **AND** no code path of this subphase SHALL use them to send anything.

#### Scenario: Vendor settings live in configuration
- **WHEN** the provider is configured
- **THEN** the base URL, model and credential SHALL come from configuration and environment
- **AND** the credential SHALL never be stored in the database.

### Requirement: Isolated Interpretation Queue
Interpretation SHALL run on a dedicated queue with configured attempts, backoff, timeout and failure handling, and SHALL NOT share a queue with incoming message processing.

#### Scenario: Queue isolation
- **WHEN** interpretation jobs are queued
- **THEN** they SHALL use the interpretation queue
- **AND** a slow or failing provider SHALL NOT delay incoming message registration.

#### Scenario: Controlled retry
- **WHEN** an interpretation job fails
- **THEN** the failure SHALL be recorded
- **AND** a controlled retry SHALL be possible without duplicating persisted results.
