## ADDED Requirements

### Requirement: Response Agenda Configuration Is Not Hardcoded
The priority weights of the response queue and the window used to detect an answer already sent SHALL be configurable and SHALL NOT be embedded in code.

#### Scenario: Priority weights are configurable
- **WHEN** a priority weight is changed in system settings
- **THEN** the ordering of the queue SHALL follow the new value without a code change.

#### Scenario: The detection window is configurable
- **WHEN** the lookback window used to detect an answer is changed in system settings
- **THEN** the detection SHALL follow the new value without a code change.

#### Scenario: The agenda works with interpretation disabled
- **WHEN** interpretation, generation and knowledge retrieval are disabled
- **THEN** the new screens SHALL still open and report the absence of data.

### Requirement: The Subetapa Introduces No External Dependency
This subetapa SHALL NOT add any package, charting library or externally hosted asset.

#### Scenario: No new package is added
- **WHEN** the dependency manifests are compared before and after the subetapa
- **THEN** they SHALL be unchanged.

#### Scenario: The messaging provider contract is untouched
- **WHEN** the WhatsApp provider contract is compared before and after the subetapa
- **THEN** it SHALL be unchanged
- **AND** the Node messaging service SHALL be unchanged.
