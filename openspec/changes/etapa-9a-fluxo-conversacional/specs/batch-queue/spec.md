## ADDED Requirements

### Requirement: Campaign Flow Association
A message batch or campaign MAY be associated with one conversational flow, and the association SHALL be stored as a snapshot so later flow edits do not rewrite past campaigns.

#### Scenario: Associating a flow
- **WHEN** an authorized user associates a flow with a batch
- **THEN** the flow reference and a snapshot of its relevant configuration SHALL be persisted on the batch.

#### Scenario: Existing campaigns untouched
- **WHEN** the migration runs on a database with existing batches
- **THEN** those batches SHALL remain without any flow association
- **AND** their behavior SHALL be unchanged.

### Requirement: Automation Start On Campaign Send
When a recipient of a batch bound to a flow is successfully sent, the system SHALL create or update the conversation automation state to `waiting_permission`.

#### Scenario: Recipient sent
- **WHEN** the initial campaign message is confirmed as sent for an eligible contact
- **THEN** an automation state SHALL exist for the corresponding conversation
- **AND** its stage SHALL be `waiting_permission`.

#### Scenario: Ineligible contact
- **WHEN** the contact is inactive or marked do-not-contact
- **THEN** the automation SHALL NOT be activated for that conversation.

#### Scenario: Batch without flow
- **WHEN** the batch has no associated flow
- **THEN** no automation state SHALL be created.

### Requirement: Dedicated Automation Queues
Conversation automation SHALL use queues separate from incoming message processing and from campaign sending.

#### Scenario: Queue isolation
- **WHEN** automation jobs are enqueued
- **THEN** they SHALL NOT be placed on the `whatsapp-incoming` queue
- **AND** a slow or retrying automation SHALL NOT delay incoming message registration.
