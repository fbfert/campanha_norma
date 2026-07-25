# batch-queue Specification

## Purpose

Define random selection, random order, batch lifecycle, recipient lifecycle, queue controls, sending limits, schedules, retries, and worker processing.
## Requirements
### Requirement: Random Selection
The system SHALL allow selecting a random subset from filtered contact results.

#### Scenario: Random sample from filtered contacts
- **WHEN** a user filters contacts and asks for a random quantity
- **THEN** the system SHALL choose only that quantity from the filtered result set.

### Requirement: Random Order Is Generated Once
The system SHALL generate recipient send order only once when the batch is created.

#### Scenario: Batch order persistence
- **WHEN** a batch is generated
- **THEN** each recipient SHALL receive a `random_position`
- **AND** workers SHALL process recipients using `ORDER BY random_position ASC`
- **AND** the order SHALL NOT be re-randomized on retry, pause, resume, or restart.

### Requirement: Batch Records
Each sending operation SHALL be recorded as a batch with identity, template snapshot, creator, timestamps, totals, scheduling data, and status.

#### Scenario: Batch creation
- **WHEN** the user confirms a sending operation
- **THEN** the system SHALL create a batch record and recipient records before any message is sent.

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

### Requirement: Sending Limits
The system SHALL enforce configurable maximum sends per minute, per hour, per day, allowed start and end time, allowed weekdays, timezone, maximum attempts, retry interval, and minimum interval between messages.

#### Scenario: Before each send
- **WHEN** the worker evaluates a recipient for sending
- **THEN** it SHALL require active connection, processing batch, eligible recipient, allowed time window, available minute limit, available hour limit, and available day limit before sending.

### Requirement: Waiting When Not Allowed
The system SHALL keep recipients pending with a reason when limits or time windows prevent sending.

#### Scenario: Daily limit reached
- **WHEN** the daily limit has been reached
- **THEN** the recipient SHALL remain pending or waiting with reason `aguardando_limite`
- **AND** the worker SHALL resume automatically in the next allowed window if the batch is not paused or canceled.

#### Scenario: Outside allowed hours
- **WHEN** the current time is outside the allowed sending window
- **THEN** the recipient SHALL remain pending or waiting with reason `aguardando_horario`.

### Requirement: Queue Controls
The system SHALL support starting, pausing, continuing, stopping, retrying eligible failures, and canceling an individual recipient.

#### Scenario: Pause and continue
- **WHEN** a batch is paused
- **THEN** no new messages SHALL be released
- **AND** continuing the batch SHALL resume from the preserved queue state.

#### Scenario: Stop batch
- **WHEN** a batch is stopped
- **THEN** recipients not yet processed SHALL be canceled definitively.

### Requirement: Retry Rules
The system SHALL retry only eligible temporary failures within the configured attempt limit and retry interval.

#### Scenario: Permanent failure
- **WHEN** a recipient fails with a permanent error such as invalid phone, missing placeholder, or blocked contact
- **THEN** the system SHALL NOT retry it automatically.

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
