## ADDED Requirements

### Requirement: Nominal Reporting Is Traceable To Who Produced It
Nominal response material SHALL carry the identity of who generated it and SHALL record its generation, so that a leaked copy has an origin.

#### Scenario: The printable notebook carries a watermark
- **WHEN** the printable notebook is generated
- **THEN** every page SHALL carry a discreet watermark naming who generated it and the date.

#### Scenario: Generating the notebook is audited
- **WHEN** the printable notebook is generated
- **THEN** an audit entry SHALL record the user, the applied filters and the moment.

#### Scenario: Marking an insight as answered is audited
- **WHEN** an insight is marked as answered by hand
- **THEN** an audit entry SHALL record the user, the insight and the moment.

#### Scenario: Nominal material is never offered as a bulk export
- **WHEN** the response agenda is used
- **THEN** it SHALL NOT offer an export of nominal data in a tabular file format.

### Requirement: Aggregated Reporting Stays Free Of Identifiers
The new aggregated screens SHALL remain aggregate for users holding only the aggregate permission, with no path from an aggregated cell to an identified person.

#### Scenario: The crossing carries no personal data
- **WHEN** the locality by topic crossing is rendered for a user holding only the aggregate permission
- **THEN** the response SHALL contain counts, labels and locality or topic names
- **AND** it SHALL NOT contain contact name, phone number, message text or contact identifier.

#### Scenario: The positioning report carries no personal data
- **WHEN** the positioning gap report is rendered
- **THEN** the response SHALL contain topic names, counts, urgency labels and document counts
- **AND** it SHALL NOT contain contact name, phone number, message text or contact identifier.

#### Scenario: Logs stay free of content
- **WHEN** the generation of a notebook or the reading of the agenda is logged
- **THEN** the log SHALL contain identifiers, counts and durations
- **AND** it SHALL NOT contain message content or contact phone numbers.
