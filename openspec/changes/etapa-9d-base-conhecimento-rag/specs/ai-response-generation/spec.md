## MODIFIED Requirements

### Requirement: Restricted Generation Context
The generation prompt SHALL contain only the current automation state, the original question sent, the latest reply from the person, the accumulated summary of that same conversation, the topics extracted from that conversation, at most a configured number of recent messages from that conversation, the excerpts retrieved from approved knowledge bases associated with the flow, and the safety rules.

Official retrieved content and conversation content SHALL appear in distinct labelled blocks, and factual statements SHALL derive exclusively from the official block.

#### Scenario: No third party content
- **WHEN** the prompt is assembled
- **THEN** it SHALL NOT contain messages from other conversations, other contacts, contact names, phone numbers, tags or private notes.

#### Scenario: Factual question with approved evidence
- **WHEN** the person asks a factual question about the represented person or about proposals and an approved knowledge base associated with the flow contains supporting excerpts
- **THEN** the answer SHALL derive exclusively from those excerpts
- **AND** the excerpts used SHALL be recorded as citations.

#### Scenario: Factual question without approved evidence
- **WHEN** the person asks a factual question and retrieval returns no excerpt above the configured threshold
- **THEN** the system SHALL either route to human handling or answer with a fixed institutional text defined in configuration
- **AND** the model SHALL NOT invent factual content.

#### Scenario: Knowledge retrieval disabled
- **WHEN** knowledge retrieval is disabled or no active approved base is associated with the flow
- **THEN** the prompt SHALL contain no official block
- **AND** every factual question SHALL be routed to human handling or answered with the configured institutional text.

#### Scenario: Retrieved content is data
- **WHEN** retrieved excerpts are included
- **THEN** they SHALL be enclosed in a delimited reference block
- **AND** instructions contained inside that block SHALL NOT be obeyed.

### Requirement: Human Handoff
The system SHALL route a conversation to human handling for explicit request, unanswerable factual or political question, report or accusation, threat, individual help request, legal matter, promise or commitment, low confidence, hostile content, unsupported media, context conflict, turn limit reached, repeated provider failure, an answer that failed grounding validation and a factual question with insufficient approved evidence.

#### Scenario: Performing a handoff
- **WHEN** a handoff reason is detected
- **THEN** the automation SHALL be paused, the state SHALL change, an event SHALL be created and the reason SHALL be displayed
- **AND** no improvised text SHALL be sent.

#### Scenario: Priority elevation
- **WHEN** the handoff reason indicates risk, threat or an urgent individual matter
- **THEN** the conversation priority SHALL be raised.

#### Scenario: Grounding failure routes to a human
- **WHEN** grounding validation refuses a generated answer
- **THEN** the conversation SHALL be routed to human handling with the grounding reason recorded
- **AND** no text SHALL be sent.

## ADDED Requirements

### Requirement: Grounded Suggestion Metadata
Suggestions produced with retrieval support SHALL record whether they are grounded, the grounding verdict, the retrieval reference and the citations used, and SHALL expose those sources in the administrative interface.

#### Scenario: Grounded suggestion stores its evidence
- **WHEN** a grounded suggestion is created
- **THEN** the retrieval reference, the grounding status and each citation with its document, version, page, section and content snapshot SHALL be stored.

#### Scenario: Automatic sending requires valid grounding
- **WHEN** a suggestion contains a factual statement and its grounding verdict is not valid
- **THEN** automatic sending SHALL be refused with the grounding reason recorded.

#### Scenario: Approval of an ungrounded factual answer is refused
- **WHEN** an operator approves a suggestion whose grounding verdict is invalid
- **THEN** the send SHALL be refused
- **AND** the reason SHALL be displayed.
