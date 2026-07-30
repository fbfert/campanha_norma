## ADDED Requirements

### Requirement: Analytics Administration Screens
The administrative interface SHALL provide screens for the executive dashboard, topics, geography, demands, AI quality, question quality and governance, each respecting the separated analytics permissions.

#### Scenario: Menu entries follow permission
- **WHEN** the administrative menu is rendered
- **THEN** each analytics entry SHALL appear only for users holding the corresponding permission.

#### Scenario: Filters are preserved in the address
- **WHEN** a user applies period and flow filters to a report
- **THEN** the filters SHALL be reflected in the address so the view can be shared or reloaded.

#### Scenario: Empty and error states are explicit
- **WHEN** a report has no data or a dependency is unavailable
- **THEN** the screen SHALL state the situation in plain language
- **AND** it SHALL NOT present an empty chart as though it were a result.
