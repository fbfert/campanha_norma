## ADDED Requirements

### Requirement: Analytics Configuration Is Not Hardcoded
Analytical thresholds, minimum cell size, retention windows and export limits SHALL be configurable and SHALL NOT be embedded in code.

#### Scenario: Minimum cell size is configurable
- **WHEN** the minimum cell size for suppression is changed in system settings
- **THEN** the suppression behaviour SHALL follow the new value without a code change.

#### Scenario: Reports work with every subetapa disabled
- **WHEN** interpretation, generation and knowledge retrieval are disabled
- **THEN** the analytical screens SHALL still open and report the absence of data.
