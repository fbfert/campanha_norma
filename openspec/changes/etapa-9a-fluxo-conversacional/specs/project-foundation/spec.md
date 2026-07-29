## ADDED Requirements

### Requirement: Conversation Automation Configuration
Automation behavior SHALL be configurable without code changes, covering global enablement, automatic sending, queues, message limits, validity, expression lists, opt-out and thank-you texts, minimum response interval, sending window and transparency mode.

#### Scenario: No hardcoded operational values
- **WHEN** the automation runs
- **THEN** limits, thresholds, queue names and operational texts SHALL come from configuration or system settings
- **AND** SHALL NOT be hardcoded in services or jobs.

#### Scenario: Safe defaults
- **WHEN** the settings seeder runs
- **THEN** `conversation_automation.enabled` SHALL default to disabled
- **AND** `conversation_automation.auto_send_enabled` SHALL default to disabled.

### Requirement: Post-commit Automation Dispatch
The automation evaluation SHALL be dispatched only after the incoming message transaction commits, and SHALL NOT run inside that transaction.

#### Scenario: Incoming message registered
- **WHEN** an incoming text message is persisted
- **THEN** the evaluation job SHALL be dispatched after commit
- **AND** the message registration SHALL NOT be delayed by automation work.

#### Scenario: Non-eligible messages
- **WHEN** the incoming message is from a group, is outgoing, is a duplicate or has no text
- **THEN** no evaluation job SHALL be dispatched.
