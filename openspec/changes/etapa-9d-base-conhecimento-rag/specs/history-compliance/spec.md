## ADDED Requirements

### Requirement: Knowledge Base Auditing And Data Protection
Knowledge base operations SHALL be auditable, files SHALL be stored privately, retrieved content SHALL be minimized and no secret or personal identifier SHALL be written to logs.

#### Scenario: Auditing lifecycle actions
- **WHEN** a base or document is created, updated, approved, rejected, reprocessed, obsoleted, downloaded or deleted
- **THEN** an audit entry SHALL record the action, the responsible user and the affected entity.

#### Scenario: Files are never publicly reachable
- **WHEN** a document file is stored
- **THEN** it SHALL reside on a private disk outside the publicly served directory
- **AND** access SHALL only be possible through an authorized application route.

#### Scenario: Path traversal is prevented
- **WHEN** an uploaded filename contains directory traversal sequences or unsafe characters
- **THEN** the stored name SHALL be normalized
- **AND** the resolved storage path SHALL remain inside the configured directory.

#### Scenario: Logs stay free of secrets and content
- **WHEN** ingestion, retrieval or generation is logged
- **THEN** the log SHALL contain identifiers, codes, counts and durations
- **AND** it SHALL NOT contain credentials, full document contents or contact phone numbers.

#### Scenario: No private personal data in the base
- **WHEN** a document is reviewed for approval
- **THEN** the interface SHALL state that private personal data, confidential strategy and third party conversations are not admissible
- **AND** approving such content SHALL be a documented human responsibility, not an automated one.

#### Scenario: Retention of retrieval logs
- **WHEN** the configured retention period for retrieval logs elapses
- **THEN** old retrieval logs SHALL be prunable by an operational command
- **AND** citations attached to conversation suggestions SHALL be preserved.
