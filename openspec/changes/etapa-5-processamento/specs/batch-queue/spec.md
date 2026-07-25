## MODIFIED Requirements

### Requirement: Batch States
The system SHALL use the complete processing lifecycle states for message batches once queue processing is implemented.

#### Scenario: Batch status value
- **WHEN** a batch changes state after Etapa 5
- **THEN** its status SHALL be one of `draft`, `validating`, `ready`, `queued`, `processing`, `pausing`, `paused`, `stopping`, `stopped`, `completed`, `completed_with_errors`, `failed`, or `cancelled`.
- **AND** `draft` batches SHALL remain editable
- **AND** `ready` batches SHALL be startable but not directly editable
- **AND** processing states SHALL NOT be used for batches that have not been explicitly started.

### Requirement: Recipient States
The system SHALL preserve Etapa 4 eligibility and add a separate processing status for each batch recipient.

#### Scenario: Recipient processing status value
- **WHEN** a recipient enters processing after Etapa 5
- **THEN** its processing status SHALL be one of `eligible`, `pending`, `waiting_schedule`, `waiting_minute_limit`, `waiting_hour_limit`, `waiting_day_limit`, `queued`, `processing`, `sent`, `retry_wait`, `failed_temporary`, `failed_permanent`, `cancelled`, or `skipped`.
- **AND** only recipients with Etapa 4 eligibility status `eligible` SHALL be converted to processing status `pending` when a batch starts.

### Requirement: Sending Limits
The system SHALL enforce configurable maximum sends per minute, per hour, per day, allowed start and end time, allowed weekdays, timezone, maximum attempts, retry interval, and minimum interval between messages.

#### Scenario: Before each send
- **WHEN** the worker evaluates a recipient for sending
- **THEN** it SHALL require active connection, processing batch, eligible recipient, allowed time window, available minute limit, available hour limit, available day limit, and available minimum interval before sending.
- **AND** limit consumption SHALL occur immediately before calling the WhatsApp provider.

### Requirement: Waiting When Not Allowed
The system SHALL keep recipients waiting with a specific processing status and next attempt timestamp when limits or time windows prevent sending.

#### Scenario: Limit or schedule blocks sending
- **WHEN** the next recipient cannot be sent because of schedule, minute limit, hour limit, day limit, or minimum interval
- **THEN** the recipient SHALL be marked as the matching waiting status
- **AND** `retry_at` or an equivalent next attempt timestamp SHALL be recorded
- **AND** the dispatcher SHALL avoid tight retry loops.

### Requirement: Queue Controls
The system SHALL support starting, pausing, continuing, stopping, retrying eligible failures, and canceling an individual recipient through backend-authorized actions.

#### Scenario: Start ready batch
- **WHEN** an authorized user starts a `ready` batch
- **THEN** the batch SHALL move to `queued`
- **AND** eligible recipients SHALL move to `pending`
- **AND** each recipient SHALL receive a unique `request_id`
- **AND** no message SHALL be sent inside the HTTP request.

#### Scenario: Pause and continue
- **WHEN** a batch is paused
- **THEN** no new messages SHALL be released
- **AND** continuing the batch SHALL resume from the preserved queue state, attempts, snapshots, and random order.

#### Scenario: Stop batch
- **WHEN** a batch is stopped
- **THEN** recipients not yet processed SHALL be canceled definitively
- **AND** messages already sent SHALL remain recorded.

### Requirement: Retry Rules
The system SHALL retry only eligible temporary failures within the configured attempt limit, retry interval, and backoff policy.

#### Scenario: Temporary and permanent failures
- **WHEN** a recipient fails with a temporary error
- **THEN** the system SHALL schedule another attempt if attempts remain
- **AND** when a recipient fails with a permanent error such as invalid phone, missing message, blocked contact, or do-not-contact contact
- **THEN** the system SHALL NOT retry it automatically.

## ADDED Requirements

### Requirement: Redis Queue Processing
Prepared batches SHALL be processed asynchronously by Laravel Queue using Redis and a dedicated WhatsApp message queue.

#### Scenario: Worker dispatch
- **WHEN** a batch is started
- **THEN** Laravel SHALL dispatch processing work to the `whatsapp-messages` queue
- **AND** messages SHALL NOT be processed inside the starting HTTP request.

### Requirement: Sequential Processing Lock
The system SHALL process at most one message at a time per WhatsApp connection in the first queue version.

#### Scenario: Concurrent workers
- **WHEN** multiple workers attempt to process recipients for the same connection
- **THEN** a lock SHALL prevent uncontrolled parallel sends.

### Requirement: Sending Window Calculation
The system SHALL calculate whether sending is allowed using configured timezone, weekdays, start time, end time, and windows that may cross midnight.

#### Scenario: Outside window
- **WHEN** the current time is outside the allowed window
- **THEN** the system SHALL return the next allowed time and a legible reason.

### Requirement: Send Attempt History
Each provider call SHALL create a send attempt record with attempt number, request id, status, provider, timestamps, provider id when available, and safe error metadata.

#### Scenario: Successful attempt
- **WHEN** the provider confirms a sent message
- **THEN** the attempt SHALL be marked `sent`
- **AND** the recipient SHALL be marked `sent`.

### Requirement: Processing Events
The system SHALL record processing events for batch and recipient status transitions.

#### Scenario: Recipient sent event
- **WHEN** a recipient is sent, skipped, failed, retried, cancelled, or queued
- **THEN** a processing event SHALL be recorded with safe metadata.

### Requirement: Recovery Commands
The system SHALL provide Artisan commands for dispatching pending work, recalculating a batch, recovering stuck recipients, and synchronizing counters.

#### Scenario: Stuck recipient recovery
- **WHEN** a recipient remains `processing` longer than the configured timeout
- **THEN** maintenance SHALL mark the result as uncertain without automatically resending it.
