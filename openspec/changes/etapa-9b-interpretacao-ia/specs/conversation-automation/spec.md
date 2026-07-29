## ADDED Requirements

### Requirement: Conversational Flow Extension Point
The conversational flow SHALL publish an extension event after the deterministic rules have finished handling an incoming message, and SHALL NOT depend on any listener being registered.

#### Scenario: Event published after deterministic evaluation
- **WHEN** the flow finishes handling an incoming message
- **THEN** an evaluation event SHALL be published with the message, the flow state and whether the deterministic engine ran
- **AND** the event SHALL be published after every deterministic decision has been taken.

#### Scenario: Flow does not know its observers
- **WHEN** the conversational flow code is inspected
- **THEN** it SHALL NOT reference any artificial intelligence class, job or service.

#### Scenario: No listener registered
- **WHEN** no listener is registered for the event
- **THEN** the flow behaviour SHALL be identical to having no extension point at all.

#### Scenario: Listener failure is contained
- **WHEN** a listener throws
- **THEN** the failure SHALL be reported
- **AND** the deterministic processing that already happened SHALL remain valid.

### Requirement: Independent Interpretation Enablement
Interpretation SHALL be enabled by its own configuration keys and SHALL NOT require the conversational flow engine to be enabled, while still requiring a valid survey context.

#### Scenario: Flow engine disabled, interpretation enabled
- **WHEN** the conversational flow engine is disabled and interpretation is enabled
- **THEN** interpretation SHALL still be dispatched for messages belonging to a conversation with an automation state.

#### Scenario: Interpretation disabled, flow engine enabled
- **WHEN** interpretation is disabled
- **THEN** no interpretation job SHALL be dispatched
- **AND** the deterministic flow SHALL behave exactly as before.

#### Scenario: Survey context required
- **WHEN** an incoming message belongs to a conversation without an automation state
- **THEN** interpretation SHALL be refused with a recorded reason
- **AND** no provider call SHALL be made.

#### Scenario: Interpretation failure does not affect the flow
- **WHEN** the interpretation job fails for any reason
- **THEN** the conversational flow stage SHALL remain as the deterministic rules decided
- **AND** no automatic message SHALL be created as a consequence of the failure.

### Requirement: Human Review Flag From Interpretation
Interpretation SHALL be allowed to flag a conversation for human review, and SHALL NOT be allowed to perform any other change to the conversational flow state.

#### Scenario: Flagging for review
- **WHEN** interpretation detects low confidence or a sensitive situation
- **THEN** the conversation automation state SHALL be marked as needing human review
- **AND** the reason SHALL be recorded.

#### Scenario: No stage manipulation by AI
- **WHEN** an interpretation result is persisted
- **THEN** the current stage, selected question, counters, end reason, pause flag and completion timestamp SHALL remain unchanged
- **AND** it SHALL NOT reopen a terminal flow.
