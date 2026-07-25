## MODIFIED Requirements

### Requirement: Batch States
The system SHALL use Etapa 4 batch states for non-processing batches and SHALL defer processing lifecycle states to the future queue stage.

#### Scenario: Batch status value
- **WHEN** a batch changes state during Etapa 4
- **THEN** its status SHALL be one of `draft`, `validating`, `ready`, or `cancelled`.
- **AND** no automatic processing state SHALL be used before the queue stage.

### Requirement: Recipient States
The system SHALL use Etapa 4 recipient eligibility states for prepared batch recipients and SHALL defer sending lifecycle states to the future queue stage.

#### Scenario: Recipient status value
- **WHEN** a recipient is evaluated during Etapa 4
- **THEN** its eligibility status SHALL be one of `eligible`, `ineligible`, or `excluded`.

## ADDED Requirements

### Requirement: Prepared Batch Snapshots
The system SHALL store contact snapshots and rendered message snapshots for every batch recipient at validation/preparation time.

#### Scenario: Contact edited after preparation
- **WHEN** a contact is edited after a batch is prepared
- **THEN** the batch recipient SHALL continue showing the original snapshot values and rendered message.

### Requirement: Contact Selection Types
The system SHALL support manual contact selection, all filtered results, and random sample selection using backend filtering and eligibility checks.

#### Scenario: Random sample from filtered contacts
- **WHEN** a user requests a random quantity from filtered contacts
- **THEN** the system SHALL sample only contacts matching the filters and SHALL NOT include contacts outside the filter.

### Requirement: Batch Random Order
The system SHALL generate and persist a random recipient order for draft/prepared batches without using automatic sending.

#### Scenario: Order persistence
- **WHEN** a batch order is generated
- **THEN** each recipient SHALL have a saved `random_position`
- **AND** the order SHALL remain stable after page reloads.

### Requirement: Ready Batch Is Frozen
Ready batches SHALL NOT allow direct changes to message body, recipients, or random order.

#### Scenario: Editing ready batch
- **WHEN** a user attempts to edit a ready batch
- **THEN** the system SHALL reject the edit and require duplication to create a new draft.

### Requirement: Batch Cancellation
Draft or ready batches SHALL be cancellable with permission, confirmation, reason, user, and timestamp, without deleting history.

#### Scenario: Cancel ready batch
- **WHEN** an authorized user cancels a ready batch with a reason
- **THEN** the batch SHALL move to `cancelled`
- **AND** its history SHALL be preserved.
