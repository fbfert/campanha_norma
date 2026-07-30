## MODIFIED Requirements

### Requirement: Manual-Only Continuation
The module SHALL NOT implement a general purpose chatbot, keyword flows, media download, groups, broadcast lists, channels, multiple accounts or Meta Cloud API behavior.

Automatic outgoing messages SHALL exist only inside the conversational survey flow and SHALL be limited to: the survey question drawn by the deterministic flow, the configured acknowledgement and closing texts, and replies generated with artificial intelligence assistance that were explicitly approved by a human or sent under the limited automatic sending guards.

Outside the conversational survey flow, continuation SHALL remain manual.

#### Scenario: Recipient replies
- **WHEN** a synced incoming message is recorded
- **THEN** pending automatic messages for the contact SHALL be interrupted by existing reply interruption behavior
- **AND** continuation outside the conversational survey flow SHALL remain manual.

#### Scenario: Conversation without a survey flow
- **WHEN** an incoming message belongs to a conversation with no automation state
- **THEN** no automatic message and no generated suggestion SHALL be produced.

#### Scenario: Generated text requires approval by default
- **WHEN** a reply is generated with artificial intelligence assistance
- **THEN** it SHALL NOT reach the person without explicit human approval
- **AND** automatic sending SHALL only occur when it has been deliberately enabled and every guard passed.

#### Scenario: No general purpose chatbot
- **WHEN** a person writes about a subject outside the survey
- **THEN** the system SHALL NOT hold an open ended conversation
- **AND** it SHALL route to human handling or close the flow.
