## ADDED Requirements

### Requirement: Executive Participation Dashboard
The system SHALL present aggregated participation metrics for the conversational survey, filtered by period and flow, with every rate showing its denominator.

#### Scenario: Showing participation totals
- **WHEN** an authorized user opens the executive dashboard for a period and flow
- **THEN** the system SHALL show contacts approached, permissions granted, permissions denied, opt-outs, answers received, completed conversations and conversations waiting for a human
- **AND** it SHALL show response rate, completion rate, average time to first reply and average number of automated turns.

#### Scenario: A rate without a denominator is not displayed
- **WHEN** the denominator of a rate is zero
- **THEN** the system SHALL display a dash instead of a percentage
- **AND** it SHALL NOT display zero percent.

#### Scenario: Comparing two periods
- **WHEN** the user selects a comparison period
- **THEN** the system SHALL show both values and the difference
- **AND** it SHALL NOT state or imply a causal relationship between the periods.

#### Scenario: Empty state before any survey runs
- **WHEN** no conversation flow has produced data yet
- **THEN** the dashboard SHALL open and report the absence of data explicitly
- **AND** it SHALL NOT raise an error.

### Requirement: Topic Reporting
The system SHALL report the topics extracted by the interpretation subetapa in aggregate, including confidence and human review counts.

#### Scenario: Listing most mentioned topics
- **WHEN** an authorized user opens the topic report for a period
- **THEN** the system SHALL list topics ordered by number of mentions with average confidence and the count reviewed by a human
- **AND** it SHALL include a separate count of unclassified insights.

#### Scenario: Emerging topics
- **WHEN** a topic has mentions in the current period and none in the previous one
- **THEN** the system SHALL mark it as emerging.

#### Scenario: Drill-down requires content permission
- **WHEN** a user without the content permission requests the insights behind a topic
- **THEN** the system SHALL refuse the request.

#### Scenario: Charts never carry personal content
- **WHEN** topic data is rendered as a chart or summary table
- **THEN** the output SHALL contain only counts, labels and rates
- **AND** it SHALL NOT contain message text, contact name or phone number.

### Requirement: Geographic Reporting Without Inference
The system SHALL aggregate by city and region only from data already registered or explicitly declared by the person, and SHALL suppress cells below the configured minimum.

#### Scenario: Using only declared or registered locality
- **WHEN** the geographic report is built
- **THEN** it SHALL read city and state from the contact record and locality declared in the person's own answer
- **AND** it SHALL NOT derive locality from phone area code or any other proxy.

#### Scenario: Suppressing a small cell
- **WHEN** an aggregated cell contains fewer records than the configured minimum
- **THEN** the system SHALL suppress the value
- **AND** it SHALL indicate that the cell was suppressed for being below the minimum.

#### Scenario: No crossing with sensitive attributes
- **WHEN** the geographic report offers filters
- **THEN** the available filters SHALL be period, flow, city and region
- **AND** no filter SHALL exist for any sensitive attribute.

### Requirement: Demand Reporting
The system SHALL report identified problems, suggested actions, desired results and urgency in aggregate, with anonymized examples.

#### Scenario: Listing demands by urgency
- **WHEN** an authorized user opens the demand report
- **THEN** the system SHALL group identified problems and suggested actions with their counts and urgency
- **AND** examples SHALL be shown without contact name, phone number or contact identifier.

#### Scenario: Low confidence queue
- **WHEN** an insight has confidence below the configured threshold or is marked as requiring review
- **THEN** it SHALL appear in a separate review queue rather than in the aggregated totals as a settled result.

### Requirement: AI Quality Reporting
The system SHALL report operational quality of the automated interpretation and generation without promoting any version automatically.

#### Scenario: Reporting suggestion outcomes
- **WHEN** an authorized user opens the AI quality report
- **THEN** the system SHALL show suggestions approved without editing, approved with editing, rejected with reason, blocked and expired
- **AND** it SHALL show handoff rate and low confidence rate.

#### Scenario: Reporting failures by provider and version
- **WHEN** runs are grouped
- **THEN** the system SHALL group by provider, model and prompt version, showing failures, average latency and estimated cost when available.

#### Scenario: Version comparison never promotes automatically
- **WHEN** two prompt versions are compared
- **THEN** the system SHALL present the numbers side by side
- **AND** it SHALL NOT change the active version as a result of the comparison.

#### Scenario: Cost is hidden without the cost permission
- **WHEN** a user without the cost permission opens the AI quality report
- **THEN** cost columns SHALL be omitted
- **AND** the remaining quality metrics SHALL still be shown.

### Requirement: Question Quality Reporting
The system SHALL report per-question performance so that questions can be improved for clarity, never for persuasion.

#### Scenario: Reporting per-question metrics
- **WHEN** an authorized user opens the question quality report
- **THEN** the system SHALL show, for each question, the number of times it was asked, the response rate, the completion rate, the average answer length and the handoff frequency.

#### Scenario: No optimization target for persuasion
- **WHEN** the report is displayed
- **THEN** it SHALL NOT rank questions by any measure of persuasive effect, declared support or vote intention.

### Requirement: Governance Report
The system SHALL present a single view of the automation configuration, active content, sensitive events and operational health.

#### Scenario: Showing the current configuration state
- **WHEN** an authorized user opens the governance report
- **THEN** the system SHALL show whether automation, interpretation, generation and knowledge retrieval are enabled, the active flows, the approved documents in force, the active prompt and model versions and the configured thresholds.

#### Scenario: Showing sensitive events and pending items
- **WHEN** the governance report is built
- **THEN** it SHALL show opt-outs, handoffs for sensitive reasons, provider failures, items awaiting human review and queue health.

#### Scenario: Detecting configuration divergence
- **WHEN** a subetapa is enabled but a dependency of it is not configured
- **THEN** the governance report SHALL list the divergence explicitly.

### Requirement: Aggregated And Detailed Exports
The system SHALL export aggregated data by default and SHALL require an elevated permission and a stated purpose for any export containing message content.

#### Scenario: Aggregated export carries no identification
- **WHEN** an aggregated export is generated
- **THEN** the file SHALL contain only counts, labels, rates and periods
- **AND** it SHALL NOT contain contact name, phone number or contact identifier.

#### Scenario: Detailed export requires elevated permission and purpose
- **WHEN** a user requests an export containing message content
- **THEN** the system SHALL require the detailed export permission and a written purpose
- **AND** it SHALL record the requesting user, filters, purpose, date and expiration.

#### Scenario: Anonymizing a detailed export
- **WHEN** a detailed export is generated
- **THEN** the contact name SHALL be removed, the phone number SHALL be masked and the contact identifier SHALL be replaced by a pseudonym derived with a salt specific to that export
- **AND** two exports of the same period SHALL NOT produce matching pseudonyms.

#### Scenario: Export files stay private and expire
- **WHEN** an export completes
- **THEN** the file SHALL be stored on a private disk outside the publicly served directory
- **AND** it SHALL have an expiration after which download is refused.

### Requirement: Spreadsheet Formula Injection Is Neutralized
The system SHALL neutralize spreadsheet formula injection in every generated CSV and XLSX file.

#### Scenario: Neutralizing a dangerous cell
- **WHEN** a cell value starts with an equals sign, a plus sign, a minus sign, an at sign, a tab or a carriage return
- **THEN** the written value SHALL be prefixed so that the spreadsheet treats it as text.

#### Scenario: Existing report exports are covered
- **WHEN** an export from the message history is generated
- **THEN** the same neutralization SHALL be applied to its cells.

### Requirement: Idempotent Metric Materialization
The system SHALL materialize daily conversation metrics in a way that can be rebuilt without duplication.

#### Scenario: Rebuilding a day twice
- **WHEN** the metric rebuild command runs twice for the same date and flow
- **THEN** the stored values SHALL be identical after both runs
- **AND** no duplicate row SHALL be created.

#### Scenario: Rebuilding after a correction
- **WHEN** data for a past day changes because of a human correction or a deletion
- **THEN** rebuilding that day SHALL replace the stored values with the recomputed ones.

### Requirement: Analytics Permissions Are Separated By Exposure Level
The system SHALL separate permission to see aggregates, to see content, to see identification, to export aggregates, to export detailed data, to administer taxonomy, to administer AI configuration, to see cost and to see governance.

#### Scenario: Query profile sees only aggregates
- **WHEN** a user holding only the aggregate permission opens the reports
- **THEN** the system SHALL show counts and rates
- **AND** it SHALL NOT show message text, contact name or phone number.

#### Scenario: Content permission does not grant identification
- **WHEN** a user holds the content permission but not the identification permission
- **THEN** message text SHALL be visible
- **AND** contact name and phone number SHALL remain hidden.

### Requirement: Retention And Data Subject Rights
The system SHALL provide commands to anonymize or delete conversational content according to the retention policy, preserving referential integrity and updating aggregates.

#### Scenario: Anonymizing content on request
- **WHEN** the anonymization command runs for a contact or period
- **THEN** message content and free-text insight fields SHALL be cleared or replaced
- **AND** the aggregate counts SHALL be recomputed for the affected days.

#### Scenario: Preserving minimum audit
- **WHEN** content is anonymized or deleted
- **THEN** the audit entry recording the action SHALL be preserved
- **AND** foreign keys SHALL remain valid.

#### Scenario: Execution is recorded
- **WHEN** a retention command runs
- **THEN** the system SHALL record who ran it, the scope, the number of affected records and the moment of execution.
