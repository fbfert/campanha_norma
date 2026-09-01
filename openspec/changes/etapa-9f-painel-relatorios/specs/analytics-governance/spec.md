## ADDED Requirements

### Requirement: Locality By Topic Cross Tabulation
The system SHALL cross declared locality and region against the main topic, applying small cell suppression in the service, and SHALL count insights without a declared locality separately.

#### Scenario: Crossing declared locality against topic
- **WHEN** an authorized user opens the cross tabulation for a period and flow
- **THEN** the system SHALL present a table of declared locality by main topic with the count of each cell, the total of each row and the total of each column
- **AND** it SHALL present the same crossing by region.

#### Scenario: Suppressed cells stay in the table
- **WHEN** a cell of the crossing contains fewer records than the configured minimum cell size
- **THEN** the system SHALL suppress the value and mark the cell as suppressed
- **AND** the cell SHALL remain present in the table so the row and column totals are not silently contradicted.

#### Scenario: Zero is never suppressed
- **WHEN** a cell of the crossing contains no record at all
- **THEN** the system SHALL display zero
- **AND** it SHALL NOT mark the cell as suppressed.

#### Scenario: Insights without a declared locality are counted apart
- **WHEN** insights in the period carry no declared locality
- **THEN** the system SHALL report their count on a separate line
- **AND** it SHALL NOT distribute them across localities nor merge them into an "other" locality.

#### Scenario: Locality is never inferred
- **WHEN** the crossing is built
- **THEN** it SHALL read only the locality declared by the person and the city and state registered on the contact record
- **AND** it SHALL NOT derive locality from phone area code, from name or from any other proxy.

#### Scenario: The screen explains why so much is suppressed
- **WHEN** the cross tabulation is displayed
- **THEN** it SHALL state that crossing two axes lowers the number of records per cell and that cells below the minimum appear suppressed by rule
- **AND** it SHALL NOT present suppression as missing data.

### Requirement: Positioning Gap Report
The system SHALL report topics mentioned in the period that have no approved document, in an active knowledge base associated with the flow, pointing at that topic.

#### Scenario: A topic without an approved document is a gap
- **WHEN** a topic has mentions in the period and no document pointing at it is approved in an active base associated with the flow
- **THEN** the system SHALL list that topic as a gap
- **AND** it SHALL report its mentions, its predominant urgency, its count of approved documents and whether a response guidance has been written for it.

#### Scenario: Indexing does not approve
- **WHEN** a document pointing at the topic is indexed but not approved
- **THEN** the topic SHALL still be listed as a gap.

#### Scenario: An approved document in an inactive base does not close the gap
- **WHEN** the only approved document pointing at the topic belongs to an inactive knowledge base
- **THEN** the topic SHALL still be listed as a gap.

#### Scenario: Ordering by what appeared most
- **WHEN** the positioning report is displayed
- **THEN** the topics SHALL be ordered by number of mentions, descending.

#### Scenario: The report stays out of the retrieval layer
- **WHEN** the positioning gap service is inspected
- **THEN** it SHALL query only knowledge and insight tables directly
- **AND** it SHALL NOT use, import or reference any class of the knowledge retrieval layer.

### Requirement: Printed Analytical Output Carries Its Sample
The system SHALL provide a printable rendering of analytical reports carrying a mandatory cover, and every printed rate SHALL display the number of records behind it.

#### Scenario: The cover states what the document is
- **WHEN** an analytical report is rendered for printing
- **THEN** the first page SHALL state the title, the period, the flow, the sample size, the generation date, who generated it and that the material is demand listening and not a registered electoral poll.

#### Scenario: Every printed rate shows its denominator
- **WHEN** a rate is printed
- **THEN** the number of records behind it SHALL be printed beside it.

#### Scenario: Printing adds no external dependency
- **WHEN** the printable rendering is produced
- **THEN** it SHALL use the existing stylesheet tokens and the browser print function
- **AND** it SHALL NOT load any script, style or font from an external network location.
