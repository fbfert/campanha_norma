## ADDED Requirements

### Requirement: Generation Linked To Interpretation
A generation execution SHALL reference the classification and the insight that informed it, so that a suggestion can be traced back to the interpretation it derived from.

#### Scenario: Traceability
- **WHEN** a suggestion is created
- **THEN** it SHALL reference the source message, the interpretation execution and the insight when one exists.

#### Scenario: Generation without interpretation
- **WHEN** no insight exists for the source message
- **THEN** generation MAY still run using the conversation content
- **AND** the absence SHALL be recorded.
