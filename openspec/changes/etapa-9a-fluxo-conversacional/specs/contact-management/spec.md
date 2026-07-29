## ADDED Requirements

### Requirement: Automation Opt-out Reuses Contact Service
Opt-out detected by the conversation automation SHALL mark the contact as do-not-contact through the existing contact data service, preserving contact history and audit, and SHALL NOT duplicate the business rule.

#### Scenario: Opt-out through automation
- **WHEN** the automation classifies a reply as opt-out
- **THEN** the existing contact service SHALL perform the do-not-contact marking
- **AND** the contact history SHALL record the change
- **AND** pending batch recipients SHALL be interrupted by the existing interruption service.

### Requirement: Refusal Does Not Block By Default
Refusing to answer a research question SHALL NOT mark the contact as do-not-contact unless an explicit configuration enables that behavior.

#### Scenario: Default refusal
- **WHEN** a contact refuses the research question
- **THEN** `do_not_contact` SHALL remain unchanged
- **AND** the contact SHALL remain eligible for future manual contact.
