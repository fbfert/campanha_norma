## ADDED Requirements

### Requirement: Suggestion Audit Trail
Every suggestion creation, edition, approval, rejection, regeneration, automatic send decision and feedback SHALL be auditable.

#### Scenario: Auditing an approval
- **WHEN** an operator approves and sends a suggestion
- **THEN** the audit log SHALL record the action, the responsible user and the affected entity
- **AND** the originally generated text SHALL remain readable.

#### Scenario: Auditing a refused automatic send
- **WHEN** an automatic send is refused
- **THEN** the specific reason SHALL be recorded.

#### Scenario: Audit without secrets
- **WHEN** suggestion actions are audited
- **THEN** the records SHALL NOT contain credentials or full phone numbers.
