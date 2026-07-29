## ADDED Requirements

### Requirement: Automation Audit Trail
Every automation decision that changes state, sends a message, marks do-not-contact or is triggered by a user SHALL be auditable.

#### Scenario: Automated question sent
- **WHEN** the automation creates and sends a question
- **THEN** an audit entry and a conversation event SHALL be recorded
- **AND** the recorded data SHALL identify the flow, the question and the conversation.

#### Scenario: Manual automation control
- **WHEN** a user pauses, resumes, finishes or takes over a conversation automation
- **THEN** the acting user SHALL be recorded in the audit entry and in the transition history.

### Requirement: Automation Log Minimization
Automation logs SHALL NOT expose secrets, session data or full message bodies by default, and SHALL apply data minimization compatible with LGPD.

#### Scenario: Logged decision
- **WHEN** a classification decision is logged
- **THEN** the log SHALL record the resulting classification and identifiers
- **AND** SHALL NOT record credentials, tokens or session material.

### Requirement: No Cross-Contact Message Reuse
The automation SHALL NOT read or reuse private messages from other contacts to compose a reply to the current contact.

#### Scenario: Composing an automated reply
- **WHEN** the automation builds a message for a conversation
- **THEN** it SHALL use only the configured flow texts and that conversation's own state.
