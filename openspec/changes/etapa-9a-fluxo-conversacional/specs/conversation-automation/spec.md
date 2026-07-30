## ADDED Requirements

### Requirement: Conversation Flow Administration
The system SHALL provide administration of conversational flows with name, description, status of `draft`, `active`, `paused` or `archived`, presentation text or message template reference, closing thank-you text, maximum main questions, maximum follow-ups, maximum validity, automation transparency configuration, timestamps and audit.

#### Scenario: Creating a flow
- **WHEN** an authorized user creates a conversational flow
- **THEN** the flow SHALL be stored with status `draft`
- **AND** the creation SHALL be audited.

#### Scenario: Follow-up limit in this subphase
- **WHEN** a flow is created in subphase 9A
- **THEN** the maximum follow-up count SHALL default to zero
- **AND** no deepening question SHALL be sent.

### Requirement: Conversation Flow Questions
The system SHALL provide administration of questions bound to a flow with internal title, text, optional category, draw weight, administrative order, active/inactive status, version, timestamps and authorship.

#### Scenario: Deleting a used question
- **WHEN** a user deletes a question already used in a conversation
- **THEN** the question SHALL be removed logically only
- **AND** the historical snapshot SHALL remain readable.

#### Scenario: Inactive question
- **WHEN** a question is inactive
- **THEN** it SHALL NOT be eligible for drawing.

### Requirement: Conversation Flow State
The system SHALL persist one automation state per conversation containing conversation, flow, current stage, selected question, selected question snapshot, automated message count, attempt count, last processed incoming message, last automated message, end reason, pause and human review flags, start, last transition, completion and expiration timestamps, and metadata reserved for non-searchable auxiliary data.

#### Scenario: Supported stages
- **WHEN** the automation state changes
- **THEN** `current_stage` SHALL be one of `inactive`, `initial_message_sent`, `waiting_permission`, `permission_granted`, `permission_denied`, `question_selected`, `waiting_answer`, `answer_received`, `completed`, `opted_out`, `paused`, `waiting_human` or `failed`.

### Requirement: Conversation Flow Transition History
The system SHALL record every stage transition with previous stage, new stage, triggering event, related message, decision taken, responsible user or system, metadata and timestamp.

#### Scenario: Recording a transition
- **WHEN** the state machine changes the stage
- **THEN** a transition record SHALL be created
- **AND** it SHALL identify whether the actor was a user or the system.

### Requirement: Question Usage Registry
The system SHALL record question usage per conversation with question, snapshot, selection date, send date, sent message and result, and SHALL guarantee that the same question is never drawn twice for the same conversation.

#### Scenario: Repeated draw attempt
- **WHEN** the selector runs for a conversation that already used a question
- **THEN** that question SHALL be excluded from the candidate set
- **AND** a database uniqueness constraint SHALL prevent duplicate usage rows.

### Requirement: Deterministic Permission Classification
The system SHALL classify short permission replies deterministically as `permission_yes`, `permission_no`, `opt_out` or `ambiguous`, without AI, embeddings or similarity ranking in this subphase.

#### Scenario: Normalization
- **WHEN** a reply is classified
- **THEN** case, spacing, punctuation and accents SHALL be normalized for matching
- **AND** the original text SHALL be preserved for storage.

#### Scenario: Opt-out precedence
- **WHEN** a reply matches both a positive expression and an opt-out expression
- **THEN** the classification SHALL be `opt_out`.

#### Scenario: Long or doubtful text
- **WHEN** a reply exceeds the configured short-answer word limit and is not an exact configured expression
- **THEN** the classification SHALL be `ambiguous`
- **AND** it SHALL NOT be treated as positive by approximation.

#### Scenario: Editable expression lists
- **WHEN** an administrator edits the positive, negative or opt-out expression lists
- **THEN** classification SHALL use the new lists without code deployment.

### Requirement: Question Selection And Automated Send
The system SHALL select exactly one active, unused question of the flow honoring weight, inside a transaction with locking, freeze the selected text, create a single pending outgoing message marked as automation origin, and enqueue it on a dedicated queue.

#### Scenario: Granted permission
- **GIVEN** an eligible contact whose campaign is bound to a flow and whose state is `waiting_permission`
- **WHEN** the reply `Sim, pode perguntar` arrives
- **THEN** exactly one not-yet-used question SHALL be selected
- **AND** a single pending message SHALL be created
- **AND** the question and state snapshots SHALL be recorded
- **AND** the stage SHALL become `waiting_answer`.

#### Scenario: Concurrent workers
- **WHEN** two workers evaluate the same conversation simultaneously
- **THEN** only one question SHALL be selected
- **AND** only one outgoing message SHALL be created.

#### Scenario: Evaluation job never sends directly
- **WHEN** the evaluation job decides to ask a question
- **THEN** it SHALL create the pending message and enqueue the send job
- **AND** it SHALL NOT call the WhatsApp provider itself.

### Requirement: Automation Idempotency
Repeated execution of the evaluation job for the same incoming message SHALL NOT produce a second question or a second outgoing message.

#### Scenario: Duplicated job execution
- **WHEN** the same evaluation job runs twice for the same message
- **THEN** the second execution SHALL detect the already processed message
- **AND** SHALL finish without side effects.

### Requirement: Opt-out Precedence And Effects
An opt-out reply SHALL mark the contact as do-not-contact through the existing contact service, interrupt pending campaign recipients and automations, record audit, and send nothing.

#### Scenario: Opt-out received
- **WHEN** the reply `nao quero receber mensagens` arrives
- **THEN** the contact SHALL be marked as do-not-contact
- **AND** pending batch recipients SHALL be interrupted
- **AND** no automated message SHALL be created
- **AND** the stage SHALL become `opted_out`.

### Requirement: Refusal Without Automatic Blocking
A `permission_no` reply SHALL close the automation politely using configurable text and SHALL NOT mark the contact as do-not-contact unless an explicit setting enables it.

#### Scenario: Polite refusal
- **WHEN** the contact refuses to answer
- **THEN** the configured thank-you text MAY be sent
- **AND** the stage SHALL become `permission_denied`
- **AND** `do_not_contact` SHALL remain unchanged by default.

### Requirement: Ambiguous Reply Handling
An ambiguous reply SHALL NOT trigger an automatic question and SHALL move the conversation to human review or keep it waiting, according to configuration.

#### Scenario: Ambiguous text
- **WHEN** an ambiguous reply arrives
- **THEN** no question SHALL be sent
- **AND** the conversation SHALL be flagged for human attention according to the configured behavior.

### Requirement: Automation Guard
The system SHALL block automated replies when global automation is disabled, automatic sending is disabled, the flow is not active, the flow or conversation is paused, the contact is inactive or marked do-not-contact, the automated message limit is reached, the flow validity expired, or the send is outside the configured window.

#### Scenario: Paused automation
- **WHEN** global automation, the flow or the conversation is paused
- **THEN** no automated reply SHALL be created.

#### Scenario: Disabled by default
- **WHEN** the system is installed or upgraded
- **THEN** global automation and automatic sending SHALL be disabled by default until homologation.

### Requirement: No Available Question
When no active unused question remains, the conversation SHALL move to `waiting_human` or `completed` according to configuration, with event and audit records.

#### Scenario: Question pool exhausted
- **WHEN** the selector finds no eligible question
- **THEN** no message SHALL be created
- **AND** the configured terminal stage SHALL be applied
- **AND** an event and an audit entry SHALL be recorded.

### Requirement: Bounded Automation
The automation SHALL have explicit limits of automatic turns, time and attempts, and SHALL NOT sustain an open-ended conversation.

#### Scenario: Limit reached
- **WHEN** the automated message limit or the flow validity is reached
- **THEN** the automation SHALL stop
- **AND** the end reason SHALL be recorded.

### Requirement: Out Of Order Messages
Messages arriving out of order SHALL NOT restart a flow that already advanced or ended.

#### Scenario: Late reply after completion
- **WHEN** a reply arrives for a conversation already `completed`, `opted_out` or `permission_denied`
- **THEN** the stage SHALL NOT return to `waiting_permission`
- **AND** no automated message SHALL be created.

### Requirement: Automation Transparency
The system SHALL support configurable disclosure that the interaction is automated and SHALL NOT present automated messages as written by a human in real time.

#### Scenario: Transparency enabled
- **WHEN** transparency mode is enabled for a flow
- **THEN** the configured disclosure text SHALL be applied to the automated message.

#### Scenario: Timeline marking
- **WHEN** an operator views the conversation timeline
- **THEN** automated messages SHALL be visually identified as automatic.

### Requirement: Automation Administration Screens
The system SHALL provide an administrative área for conversational research with flow and question CRUD, conversation state screen, pause, resume, finish and human takeover actions, filters by stage and flow, and specific permissions.

#### Scenario: Human takeover
- **WHEN** an authorized user takes a conversation manually
- **THEN** the automation SHALL stop for that conversation
- **AND** the action SHALL be audited.

#### Scenario: Unauthorized access
- **WHEN** a user without the automation permissions opens the administration área
- **THEN** access SHALL be denied.
