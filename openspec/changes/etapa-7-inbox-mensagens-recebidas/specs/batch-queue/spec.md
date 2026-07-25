## ADDED Requirements

### Requirement: Stop Pending Sends After Reply
When a contact replies, the system SHALL interrupt pending automated sends for that contact according to inbox configuration.

#### Scenario: Reply interrupts pending recipients
- **WHEN** a contact receives an incoming reply
- **THEN** pending recipients in eligible, pending, waiting, queued, retry-wait, or temporary-failure states for that contact or phone SHALL be marked `skipped`
- **AND** the error code SHALL be `CONTACT_REPLIED`
- **AND** already sent or processing recipients SHALL NOT be canceled silently.

### Requirement: Incoming And Manual Reply Queues
The system SHALL process incoming messages and manual replies asynchronously using dedicated queues.

#### Scenario: Webhook accepted
- **WHEN** the incoming webhook signature and payload are valid
- **THEN** Laravel SHALL enqueue processing on `whatsapp-incoming`
- **AND** SHALL NOT execute full conversation resolution inside the HTTP request.
