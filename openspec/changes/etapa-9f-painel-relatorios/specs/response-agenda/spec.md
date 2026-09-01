## ADDED Requirements

### Requirement: Individual Response Dossier
The system SHALL assemble, for each insight, a nominal dossier composed only of stored fields, and SHALL NOT invoke any language model to produce any part of it.

#### Scenario: Assembling a dossier from stored fields
- **WHEN** an authorized user opens the dossier for an insight
- **THEN** the system SHALL show the contact name and city, the literal body of the message that originated the insight, the declared locality, the topic, the urgency, the sentiment, the identified problem, the suggested action and the desired result
- **AND** every one of those values SHALL come from an already stored record.

#### Scenario: The person's own sentence is quoted, never paraphrased
- **WHEN** the dossier presents what the person wrote
- **THEN** it SHALL present the stored message body verbatim
- **AND** it SHALL NOT present a summary, a paraphrase or a generated rewording in its place.

#### Scenario: No model run is created by reading
- **WHEN** the queue, a dossier or the printable notebook is opened
- **THEN** the system SHALL NOT create any model execution record
- **AND** it SHALL NOT call any artificial intelligence provider.

#### Scenario: The same dossier renders identically twice
- **WHEN** the same dossier is assembled twice from unchanged data
- **THEN** both renderings SHALL contain the same text.

#### Scenario: Low confidence is announced
- **WHEN** the confidence of the insight is below the configured low confidence threshold
- **THEN** the dossier SHALL warn the reader to check the original message before answering.

### Requirement: Campaign Guidance And Red Lines Per Topic
The system SHALL store, per topic, a written response guidance and a written red line, and SHALL present both in the dossier of any insight carrying that topic.

#### Scenario: Presenting the written guidance
- **WHEN** the topic of the insight has a response guidance written
- **THEN** the dossier SHALL present it under what the campaign already defends.

#### Scenario: Presenting the red line in strong emphasis
- **WHEN** the topic of the insight has a red line written
- **THEN** the dossier SHALL present it in strong emphasis using the alert colour token.

#### Scenario: A missing red line is stated, not hidden
- **WHEN** the topic of the insight has no red line written
- **THEN** the dossier SHALL state that no red line has been written for this topic
- **AND** it SHALL NOT omit the section silently.

#### Scenario: An insight without a topic still produces a dossier
- **WHEN** the insight has no topic assigned
- **THEN** the dossier SHALL still be assembled from the remaining fields
- **AND** it SHALL state that no topic guidance is available.

### Requirement: Response Queue Ordered By Relevance
The system SHALL present a queue of people to answer, ordered by a priority score computed from configured weights, filtered by period, flow, topic, city and state.

#### Scenario: Ordering by priority score
- **WHEN** the queue is built for a period and flow
- **THEN** the system SHALL order the entries by a score combining declared urgency, answer length and whether the topic is emerging
- **AND** the weight of each component SHALL be read from system settings.

#### Scenario: Priority orders but never discards
- **WHEN** an entry receives the lowest possible priority score
- **THEN** it SHALL remain in the queue
- **AND** it SHALL NOT be hidden or excluded because of its score.

#### Scenario: Counting the queue
- **WHEN** the queue is displayed
- **THEN** the system SHALL show how many entries are pending, how many are answered and how many exist in total.

#### Scenario: Empty state before any insight exists
- **WHEN** no insight exists for the selected period and flow
- **THEN** the queue SHALL open and report the absence of data explicitly
- **AND** it SHALL NOT raise an error.

### Requirement: Answered Detection Without Requiring Discipline
The system SHALL mark an insight as answered when an outgoing message carrying media exists in the same conversation after the insight was created and within the configured window, and SHALL also accept a manual mark.

#### Scenario: Detecting an answer from synchronization
- **WHEN** the same conversation contains an outgoing message with media, sent after the insight was created and within the configured lookback window
- **THEN** the system SHALL consider that insight answered
- **AND** it SHALL report the mark as detected by synchronization.

#### Scenario: An outgoing text message does not mark
- **WHEN** the only later outgoing message in the conversation carries no media
- **THEN** the insight SHALL remain pending.

#### Scenario: An earlier outgoing message does not mark
- **WHEN** the only outgoing message with media was sent before the insight was created
- **THEN** the insight SHALL remain pending.

#### Scenario: An outgoing message outside the window does not mark
- **WHEN** the only outgoing message with media was sent outside the configured lookback window
- **THEN** the insight SHALL remain pending.

#### Scenario: Manual marking takes precedence
- **WHEN** an authorized user marks an insight as answered
- **THEN** the system SHALL record the moment and the author, SHALL report the mark as made by hand, and SHALL keep that state regardless of detection.

#### Scenario: Marking sends nothing
- **WHEN** an insight is marked as answered
- **THEN** the system SHALL only record the mark and its audit entry
- **AND** it SHALL NOT send a message, open a conversation with the provider or schedule anything.

#### Scenario: The condition of the detection is stated on screen
- **WHEN** the queue is displayed
- **THEN** it SHALL state that automatic detection only works when the answer leaves from the number paired with the system
- **AND** it SHALL state that manual marking is the alternative otherwise.

### Requirement: The Response Agenda Sends Nothing
The response agenda SHALL be read-only over already stored data, apart from recording the answered mark and its audit entry.

#### Scenario: No route reaches the messaging provider
- **WHEN** the routes of the response agenda module are inspected
- **THEN** none of them SHALL reach the WhatsApp provider contract.

#### Scenario: No queue, schedule or automation is introduced
- **WHEN** the response agenda is used
- **THEN** it SHALL NOT enqueue a job, schedule a task or change any conversation state, flow stage, classification or insight content.
