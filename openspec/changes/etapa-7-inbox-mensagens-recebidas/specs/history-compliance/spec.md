## ADDED Requirements

### Requirement: Manual Conversation Continuation
The system SHALL receive replies and continue conversations only through manual human operator actions.

#### Scenario: Contact replies after initial message
- **WHEN** a recipient replies to an initial message
- **THEN** the system SHALL register the incoming message
- **AND** SHALL show it in the administrative inbox
- **AND** SHALL NOT send an automatic reply, chatbot response, AI response, keyword-triggered response, or follow-up sequence.

### Requirement: Conversation History
The system SHALL store conversation messages, internal notes, and conversation events with protected access and preserved snapshots.

#### Scenario: Viewing a conversation
- **WHEN** an authorized user opens a conversation
- **THEN** the system SHALL show incoming messages, manual outgoing messages, internal notes, and system events with direction, status, timestamps, and safe metadata.

### Requirement: Incoming Message Idempotency
Incoming messages SHALL be idempotent by provider and external message id, and also by event id.

#### Scenario: Duplicate incoming event
- **WHEN** the same incoming event is delivered more than once
- **THEN** the system SHALL NOT create duplicate conversation messages
- **AND** SHALL NOT duplicate conversation creation or pending-send interruption.

### Requirement: Conversation Audit Safety
The system SHALL audit inbox views, conversation reads, assignments, status changes, priority changes, notes, archiving, blocking, contact association, incoming-message processing, and manual reply results without storing secrets or complete message bodies in general audit logs.

#### Scenario: Manual reply audit
- **WHEN** a manual reply is requested, sent, or fails
- **THEN** the audit log SHALL record the safe operation, user, conversation, and non-sensitive metadata.

### Requirement: Conversation Privacy Controls
The system SHALL protect message content, technical details, and full phone numbers with dedicated permissions.

#### Scenario: Restricted user opens inbox
- **WHEN** a user lacks message-content permission
- **THEN** the full message body SHALL be hidden or summarized.
