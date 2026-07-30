## ADDED Requirements

### Requirement: Knowledge Indexing Queue And Configuration
Knowledge ingestion SHALL run in its own queue, and every provider, endpoint, credential, model, limit, threshold and operational text SHALL come from configuration rather than from application code.

#### Scenario: Dedicated queue
- **WHEN** a document is queued for indexing
- **THEN** it SHALL use a queue separate from incoming messages, deterministic automation, interpretation and reply generation
- **AND** a slow or large document SHALL NOT delay those queues.

#### Scenario: Credentials never in the database
- **WHEN** provider credentials, endpoints and model names are resolved
- **THEN** they SHALL come from environment configuration
- **AND** they SHALL NOT be stored in the settings table.

#### Scenario: Operational values in settings
- **WHEN** chunk size, overlap, top count, threshold, context limit, candidate limit, retention, accepted MIME types, maximum file size, injection patterns and institutional texts are resolved
- **THEN** they SHALL come from the settings table and SHALL be editable without a deploy.

#### Scenario: Disabled by default
- **WHEN** the settings seeder runs
- **THEN** knowledge retrieval SHALL be disabled
- **AND** the knowledge provider SHALL default to the inert implementation.

#### Scenario: Safe rollback
- **WHEN** the subphase migration is rolled back
- **THEN** the knowledge tables and the columns added to the suggestion table SHALL be removed
- **AND** the previous subphases SHALL continue to operate.
