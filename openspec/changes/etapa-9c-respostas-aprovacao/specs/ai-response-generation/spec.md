## ADDED Requirements

### Requirement: Response Generator Abstraction
The system SHALL generate contextual replies through a vendor independent contract, with the provider, model and credential resolved from configuration, structured JSON output validated server side and versioned prompts.

#### Scenario: Generating a suggestion
- **WHEN** a reply is generated for an incoming message
- **THEN** the generator SHALL be resolved from configuration
- **AND** the execution SHALL be recorded and linked to the classification and insight of the interpretation subphase.

#### Scenario: Structured contract
- **WHEN** the provider answers
- **THEN** the response SHALL contain an action, reply text, follow-up type, topic, confidence, human review flag and handoff reason
- **AND** the action SHALL be one of `suggest_reply`, `thank_and_complete`, `request_clarification`, `handoff_human`, `no_reply` or `opt_out`.

#### Scenario: Invalid structured output
- **WHEN** the response is not valid JSON, violates the schema or reports a confidence outside the range from zero to one
- **THEN** no suggestion SHALL be created
- **AND** the conversation SHALL be routed to human handling with a recorded reason.

### Requirement: Conversational Purpose And Neutrality
Generated replies SHALL briefly acknowledge the point raised, stay neutral, ask at most one deepening question and derive exclusively from what the person themselves wrote.

#### Scenario: Acknowledge and deepen
- **WHEN** a person describes a problem
- **THEN** the suggested reply SHALL acknowledge the point and MAY ask one clarifying question about that same point.

#### Scenario: No persuasion
- **WHEN** any reply is generated
- **THEN** it SHALL NOT attempt to change a vote or an opinion
- **AND** it SHALL NOT request a vote, use slogans, compare with opponents, apply pressure or create artificial urgency.

#### Scenario: No promises
- **WHEN** any reply is generated
- **THEN** it SHALL NOT promise actions, benefits, positions, favours or results
- **AND** it SHALL NOT assert a proposal that does not exist.

#### Scenario: No simulated humanity
- **WHEN** any reply is generated
- **THEN** it SHALL NOT claim that a specific person read the message or will answer personally
- **AND** it SHALL NOT simulate a familiarity that does not exist.

#### Scenario: The person can always stop
- **WHEN** a person asks to stop at any moment
- **THEN** that request SHALL prevail over any pending suggestion.

### Requirement: Deterministic Text Validation
Generated text SHALL be validated by deterministic rules after the model answers, independently of what the prompt requested.

#### Scenario: Question limit
- **WHEN** the generated text contains more than one question mark
- **THEN** the text SHALL be rejected by the validator.

#### Scenario: Forbidden content
- **WHEN** the generated text matches configured expressions for promise, vote request, opponent comparison, artificial urgency, simulated intimacy or personal reading claim
- **THEN** the text SHALL be rejected by the validator
- **AND** the reason SHALL be recorded.

#### Scenario: Length limit
- **WHEN** the generated text exceeds the configured maximum length
- **THEN** the text SHALL be rejected by the validator.

#### Scenario: Rejected text is never sent automatically
- **WHEN** the validator rejects a text
- **THEN** automatic sending SHALL be refused unconditionally.

### Requirement: Restricted Generation Context
The generation prompt SHALL contain only the current automation state, the original question sent, the latest reply from the person, the accumulated summary of that same conversation, the topics extracted from that conversation, at most a configured number of recent messages from that conversation, and the safety rules.

#### Scenario: No third party content
- **WHEN** the prompt is assembled
- **THEN** it SHALL NOT contain messages from other conversations, other contacts, contact names, phone numbers, tags or private notes.

#### Scenario: No approved knowledge base yet
- **WHEN** the person asks a factual question about the represented person or about proposals
- **THEN** the system SHALL either route to human handling or answer with a fixed institutional text defined in configuration
- **AND** the model SHALL NOT invent factual content.

### Requirement: Operation Modes
The system SHALL support the modes `disabled`, `draft_only`, `approval_required` and `auto_send_limited`, with production starting in a mode that requires human approval.

#### Scenario: Default mode
- **WHEN** the settings seeder runs
- **THEN** the global mode SHALL NOT be `auto_send_limited`
- **AND** no generated text SHALL be sent without explicit human approval.

#### Scenario: Flow may only restrict
- **WHEN** a flow defines its own mode
- **THEN** the effective mode SHALL be the more restrictive between the global mode and the flow mode.

#### Scenario: Disabled mode
- **WHEN** the effective mode is `disabled`
- **THEN** no generation SHALL occur and no provider call SHALL be made.

#### Scenario: Draft only mode
- **WHEN** the effective mode is `draft_only`
- **THEN** suggestions SHALL be created and stored
- **AND** approval controls SHALL NOT send anything.

### Requirement: Approval Inbox
The system SHALL provide an approval inbox listing pending suggestions with the message from the person, the original question, the summary, the topic, the confidence and the reason, allowing edit before sending, approval, rejection, regeneration with justification and manual takeover.

#### Scenario: Editing before sending
- **WHEN** an operator edits a suggestion and approves it
- **THEN** the generated text and the final text SHALL both be stored
- **AND** the approving user and timestamp SHALL be recorded.

#### Scenario: Bulk approval is forbidden
- **WHEN** the approval interface is used
- **THEN** it SHALL NOT offer approval of more than one suggestion in a single action.

#### Scenario: Regeneration
- **WHEN** an operator regenerates a suggestion
- **THEN** a justification SHALL be required
- **AND** the previous suggestion SHALL remain readable as history.

#### Scenario: Permission required
- **WHEN** a user without the approval permission attempts to approve a suggestion
- **THEN** the request SHALL be denied.

### Requirement: Stale Suggestion Protection
A suggestion SHALL become invalid when a newer incoming message arrives in the conversation after the message that originated it, and SHALL NOT be sent.

#### Scenario: New message before approval
- **WHEN** a new incoming message arrives and an operator then approves the older suggestion
- **THEN** the approval SHALL be refused
- **AND** the suggestion SHALL be marked as superseded.

#### Scenario: Concurrent approval
- **WHEN** two operators approve the same suggestion at the same time
- **THEN** only one send SHALL occur.

#### Scenario: At most one live suggestion per message
- **WHEN** generation runs more than once for the same incoming message
- **THEN** at most one suggestion SHALL remain in a pending or approved state.

### Requirement: Limited Automatic Sending
Automatic sending SHALL be refused unless every condition holds: the effective mode allows it, the classification is in the configured allowlist, the confidence is above the configured threshold, no sensitive flag is present, the conversation is not held by a human without authorisation, the contact is eligible, the current time is inside the sending window, the turn limit is not exceeded, the source message is still the latest one, no other pending outgoing message exists, the lock is acquired and the text passed the deterministic validator.

#### Scenario: Any failed condition blocks the send
- **WHEN** any of the conditions is not met
- **THEN** the automatic send SHALL be refused
- **AND** the specific reason SHALL be recorded.

#### Scenario: Empty allowlist by default
- **WHEN** the settings seeder runs
- **THEN** the automatic sending allowlist SHALL be empty
- **AND** no classification SHALL be eligible for automatic sending.

#### Scenario: No duplicate automatic send
- **WHEN** the automatic send runs twice for the same suggestion
- **THEN** only one outgoing message SHALL be created.

#### Scenario: Opt-out between generation and sending
- **WHEN** the person opts out after the suggestion is created
- **THEN** the pending suggestion SHALL be invalidated and nothing SHALL be sent.

#### Scenario: Contact deactivated between generation and sending
- **WHEN** the contact becomes inactive or marked as do not contact after the suggestion is created
- **THEN** nothing SHALL be sent.

### Requirement: Deepening Limit And Closure
The number of deepening questions SHALL be limited, counted idempotently and followed by a thank-you message and closure when the limit is reached.

#### Scenario: Counting turns
- **WHEN** a suggestion is generated more than once and only one is sent
- **THEN** the turn counter SHALL increase by one.

#### Scenario: Reaching the limit
- **WHEN** the deepening limit is reached
- **THEN** the system SHALL send the closing thank-you message and complete the flow
- **AND** no further deepening question SHALL be generated.

#### Scenario: No repeated question
- **WHEN** a deepening question is generated
- **THEN** it SHALL NOT repeat a question already asked in that conversation.

#### Scenario: Grouping consecutive messages
- **WHEN** several messages arrive in quick succession
- **THEN** generation SHALL be grouped by a configurable debounce
- **AND** only the latest message SHALL produce a suggestion.

### Requirement: Human Handoff
The system SHALL route a conversation to human handling for explicit request, unanswerable factual or political question, report or accusation, threat, individual help request, legal matter, promise or commitment, low confidence, hostile content, unsupported media, context conflict, turn limit reached and repeated provider failure.

#### Scenario: Performing a handoff
- **WHEN** a handoff reason is detected
- **THEN** the automation SHALL be paused, the state SHALL change, an event SHALL be created and the reason SHALL be displayed
- **AND** no improvised text SHALL be sent.

#### Scenario: Priority elevation
- **WHEN** the handoff reason indicates risk, threat or an urgent individual matter
- **THEN** the conversation priority SHALL be raised.

### Requirement: Unified Outgoing Service
Manual and automatic sending SHALL share a single outgoing service responsible for eligibility validation, pending message creation, unique request identifier, queueing, auditing and snapshots.

#### Scenario: Origins
- **WHEN** an outgoing message is created
- **THEN** its origin SHALL be recorded as manual, automation or approved artificial intelligence.

#### Scenario: No regression of manual replies
- **WHEN** an operator sends a manual reply
- **THEN** the behaviour, validations, error messages and queue SHALL remain exactly as before this subphase.

### Requirement: Artificial Intelligence Message Metadata
Outgoing messages produced with artificial intelligence assistance SHALL record whether they were generated by artificial intelligence, the execution reference, the prompt version, the approving user, the approval timestamp, the related state transition and the confidence, and SHALL be visually marked in the timeline.

#### Scenario: Approved suggestion sent
- **WHEN** an approved suggestion is sent
- **THEN** the outgoing message SHALL carry the metadata
- **AND** the timeline SHALL display a badge identifying artificial intelligence assistance.

#### Scenario: Generated and final text stored separately
- **WHEN** an operator edits a suggestion before approval
- **THEN** the original generated text SHALL remain stored separately from the text actually sent.

### Requirement: Operator Feedback
Operators SHALL be able to mark a suggestion as good, bad or inappropriate with an optional reason, and that feedback SHALL NOT change prompts or models automatically.

#### Scenario: Recording feedback
- **WHEN** an operator submits feedback
- **THEN** the value, optional reason, user and timestamp SHALL be stored.

#### Scenario: No automatic learning
- **WHEN** feedback accumulates
- **THEN** no prompt, model, threshold or allowlist SHALL be changed automatically.
