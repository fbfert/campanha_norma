## ADDED Requirements

### Requirement: Immutable Source Of Truth For Interpretation
The raw conversation SHALL remain the primary and immutable source, and interpretation results SHALL be derived, versioned, reprocessable records that never replace the original message.

#### Scenario: Original message preserved
- **WHEN** an interpretation result is created, corrected or reprocessed
- **THEN** the original message body, timestamps and snapshots SHALL remain unchanged.

#### Scenario: Derived data can be discarded
- **WHEN** interpretation results are removed
- **THEN** the conversation history SHALL remain complete and readable.

### Requirement: Interpretation Audit Trail
Every interpretation execution, correction, reprocessing request and taxonomy change SHALL be auditable.

#### Scenario: Auditing a correction
- **WHEN** an operator corrects an interpretation result
- **THEN** the audit log SHALL record the action, the responsible user and the affected entity
- **AND** the previous value SHALL remain readable.

#### Scenario: Audit without secrets
- **WHEN** interpretation actions are audited
- **THEN** the audit records SHALL NOT contain credentials or full phone numbers.
