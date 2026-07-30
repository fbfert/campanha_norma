## ADDED Requirements

### Requirement: Suggestion Dispatch From The Conversational Flow
The conversational flow SHALL allow reply generation to be triggered from its existing extension point, without the flow knowing the generation layer and without changing any deterministic decision.

#### Scenario: Generation observes the extension point
- **WHEN** the flow finishes handling an incoming message
- **THEN** the generation layer MAY be triggered by the published event
- **AND** the conversational flow code SHALL NOT reference the generation layer.

#### Scenario: Generation failure does not affect the flow
- **WHEN** generation fails for any reason
- **THEN** the flow stage SHALL remain as the deterministic rules decided
- **AND** no message SHALL be created as a consequence of the failure.

### Requirement: Deepening Turn Counter
The automation state SHALL keep an idempotent counter of deepening turns actually sent, separate from the counter of automatic messages.

#### Scenario: Counting only confirmed sends
- **WHEN** a deepening reply is confirmed as sent
- **THEN** the deepening counter SHALL increase by exactly one.

#### Scenario: Rejected suggestions do not count
- **WHEN** a suggestion is rejected or superseded
- **THEN** the deepening counter SHALL NOT change.

### Requirement: Opt-out Invalidates Pending Suggestions
An opt-out SHALL invalidate every pending or approved suggestion of that conversation.

#### Scenario: Opt-out with a pending suggestion
- **WHEN** the deterministic classifier records an opt-out
- **THEN** all pending and approved suggestions of that conversation SHALL be invalidated
- **AND** nothing SHALL be sent.
