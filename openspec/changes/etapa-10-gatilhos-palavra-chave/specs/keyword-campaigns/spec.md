## ADDED Requirements

### Requirement: Keyword Trigger Evaluation
The system SHALL evaluate every inbound text message against the keywords of the active campaigns, matching whole words over normalized text, without artificial intelligence and without tolerance for misspelling.

#### Scenario: Matching any keyword in the list
- **WHEN** an inbound message contains any word from an active campaign keyword list
- **THEN** the system SHALL register a participation for that campaign
- **AND** it SHALL record which keyword matched.

#### Scenario: Matching ignores case, accents, punctuation and emoji
- **WHEN** the inbound text differs from the keyword only by letter case, accent, punctuation or emoji
- **THEN** the system SHALL treat it as a match.

#### Scenario: Partial words never match
- **WHEN** a keyword appears only as a fragment inside a longer word
- **THEN** the system SHALL NOT treat it as a match.

#### Scenario: Transcribed audio does not match
- **WHEN** an inbound message carries no written text and its transcription contains a campaign keyword
- **THEN** the system SHALL NOT register a participation.

#### Scenario: No active campaign is the cheap path
- **WHEN** no campaign is within its validity window
- **THEN** the evaluation SHALL end without touching the participation tables
- **AND** the inbound pipeline SHALL behave exactly as it did before this capability existed.

#### Scenario: Evaluation never disturbs an ongoing conversation flow
- **WHEN** a conversation whose flow state is waiting for an answer receives a message containing a campaign keyword
- **THEN** the system SHALL register the participation
- **AND** it SHALL NOT change the flow stage
- **AND** it SHALL NOT change the last processed message of the flow.

#### Scenario: Echoes and group messages are ignored
- **WHEN** the message is an echo of an outbound message or comes from a group
- **THEN** the system SHALL NOT register a participation.

#### Scenario: Several campaigns sharing a keyword
- **WHEN** two campaigns are active and share a keyword that the message contains
- **THEN** the system SHALL register one participation in each campaign.

#### Scenario: Near matches are reported, never acted upon
- **WHEN** a word in the message is within edit distance one of a campaign keyword but is not equal to it
- **THEN** the system SHALL NOT register a participation
- **AND** the near match SHALL be available for human reading in a report.

### Requirement: Participation Traceability
The system SHALL store every participation as a projection of the inbound message that produced it, so that participations can be rebuilt from stored messages.

#### Scenario: Every participation points at its message
- **WHEN** a participation is created
- **THEN** it SHALL reference the inbound message that originated it
- **AND** that reference SHALL be mandatory.

#### Scenario: Rebuilding lost participations
- **WHEN** an operator reprocesses a period after a failure in the queue
- **THEN** the system SHALL create the missing participations from the stored messages
- **AND** running the reprocessing twice SHALL produce the same state.

#### Scenario: One participation per person per campaign
- **WHEN** the same contact matches a keyword of the same campaign a second time
- **THEN** the database SHALL reject the duplicate
- **AND** the system SHALL treat the rejection as already enrolled, without raising an error.

#### Scenario: Participation without a captured name is valid
- **WHEN** the inbound message carries no sender name
- **THEN** the participation SHALL be recorded as valid with the status indicating the missing name
- **AND** it SHALL NOT be excluded from the campaign for that reason.

#### Scenario: An ambiguous phone waits for a human
- **WHEN** the phone number of the message matches more than one active contact
- **THEN** the participation SHALL be recorded for human review
- **AND** it SHALL NOT be counted as valid.

### Requirement: Contact Creation From Inbound Opt-In
The system SHALL create a contact when a keyword arrives from an unknown number, recording the purpose of the consent, and SHALL keep such contacts out of the default recipient selection.

#### Scenario: Unknown number becomes a contact
- **WHEN** a keyword arrives from a phone number with no matching contact
- **THEN** the system SHALL create a contact with the trigger origin, consent granted, the campaign as the recorded purpose of that consent, and the campaign tag.

#### Scenario: Known number is reused and its name is preserved
- **WHEN** a keyword arrives from a phone number that matches an existing contact
- **THEN** the system SHALL reuse that contact
- **AND** it SHALL NOT overwrite the registered name with the WhatsApp profile name.

#### Scenario: Purpose barrier on batch selection
- **WHEN** a message batch is built without explicitly including trigger-origin contacts
- **THEN** the selection SHALL exclude every contact whose origin is the trigger.

#### Scenario: Including trigger contacts is deliberate and explained
- **WHEN** an operator explicitly includes trigger-origin contacts in a batch
- **THEN** the selection SHALL include them
- **AND** the screen SHALL state that these people consented to the campaign and not to receiving a batch.

#### Scenario: Invalid phone creates nothing
- **WHEN** the phone number of the message cannot be normalized
- **THEN** the system SHALL NOT create a contact
- **AND** it SHALL NOT register a participation.

### Requirement: Confirmation Reply Under Global Throttle
The system SHALL reply to each enrolment with the campaign confirmation text, under a global throttle of its own, deferring the excess instead of discarding it.

#### Scenario: A burst is spread, not dropped
- **WHEN** more enrolments occur in a minute than the configured per-minute ceiling
- **THEN** the system SHALL send up to the ceiling in that minute
- **AND** it SHALL schedule the remainder for later
- **AND** it SHALL NOT discard any confirmation.

#### Scenario: Concurrent workers cannot exceed the ceiling
- **WHEN** two workers process confirmations at the same time
- **THEN** the counter SHALL be incremented atomically
- **AND** the total sent SHALL NOT exceed the ceiling.

#### Scenario: Confirmation ignores the automation time window
- **WHEN** an enrolment happens outside the conversation automation time window
- **THEN** the system SHALL send the confirmation anyway.

#### Scenario: Already enrolled receives the corresponding text
- **WHEN** a contact already enrolled in a campaign sends a keyword of that campaign again
- **THEN** the system SHALL send the already-enrolled text
- **AND** it SHALL NOT create a second participation.

#### Scenario: The campaign reply replaces the inbound attendance opening
- **WHEN** a campaign registers a participation for an inbound message and replies to it
- **THEN** the inbound attendance service SHALL NOT open an attendance for that same message
- **AND** a later message from the same person SHALL be routed normally.

#### Scenario: Quota is consumed when the message leaves
- **WHEN** a confirmation is created but its sending is blocked
- **THEN** the throttle quota SHALL NOT be consumed.

#### Scenario: Hourly ceiling raises an alarm while it is happening
- **WHEN** a campaign reaches its configured hourly ceiling
- **THEN** the system SHALL record an event and mark the campaign
- **AND** the mark SHALL be visible without opening the database.

### Requirement: Campaign Validity Window
The system SHALL accept participations only while a campaign is active and within its validity window and below its participant limit.

#### Scenario: Before the start and after the end
- **WHEN** a keyword arrives outside the validity window
- **THEN** the system SHALL NOT register a participation
- **AND** it SHALL send the out-of-window text when that text is configured
- **AND** it SHALL send nothing when that text is empty.

#### Scenario: Participant limit reached
- **WHEN** a campaign has reached its configured participant limit
- **THEN** the system SHALL NOT register further participations
- **AND** it SHALL report the reason to the caller.

#### Scenario: A frozen campaign accepts nobody
- **WHEN** the campaign list has been frozen
- **THEN** the system SHALL NOT register new participations.

### Requirement: Student Eligibility Marking
The system SHALL mark participations as eligible by importing a list of student phone numbers, without filtering enrolment at entry and without creating contacts.

#### Scenario: Importing marks and never blocks
- **WHEN** an operator imports a CSV of student phone numbers for a campaign
- **THEN** the system SHALL mark the matching participations as confirmed students
- **AND** it SHALL leave the others unverified
- **AND** it SHALL NOT create any contact
- **AND** it SHALL NOT remove any participation.

#### Scenario: Importing twice changes nothing
- **WHEN** the same import runs a second time
- **THEN** the resulting state SHALL be identical
- **AND** no record SHALL be duplicated.

#### Scenario: Phone matching uses the project normalization
- **WHEN** a student phone in the CSV differs from the stored one only by the ninth digit or by formatting
- **THEN** the system SHALL treat them as the same number.

#### Scenario: CSV headers are identifiers
- **WHEN** the importer reads the CSV header
- **THEN** the expected header names SHALL be unaccented.

### Requirement: Human Review of Participations
The system SHALL let an authorized person review, correct and invalidate participations, always keeping the record and the reason.

#### Scenario: Invalidation requires a written reason
- **WHEN** a user invalidates a participation without writing a reason
- **THEN** the system SHALL refuse the invalidation.

#### Scenario: Invalidation never deletes
- **WHEN** a participation is invalidated
- **THEN** it SHALL remain stored with the reason, the author and the timestamp
- **AND** it SHALL be excluded from the draw.

#### Scenario: Editing a captured name preserves the original
- **WHEN** a user edits the captured name of a participation
- **THEN** the system SHALL keep the value originally reported by the provider
- **AND** it SHALL record who changed it and when.

#### Scenario: Bulk review of the unverified queue
- **WHEN** a user reviews the queue of unverified participations
- **THEN** the system SHALL allow marking several at once as confirmed students or as non-students
- **AND** it SHALL record who reviewed each one and when.

#### Scenario: Exporting participants requires its own permission
- **WHEN** a user without the export permission requests a participant export
- **THEN** the system SHALL refuse the request.

### Requirement: Auditable Draw
The system SHALL draw winners only from a frozen list, recording everything needed to reproduce the result.

#### Scenario: Freezing requires the review queue to be empty
- **WHEN** an operator freezes the list of a campaign that still has unverified participations
- **THEN** the system SHALL refuse the freeze
- **AND** it SHALL state how many participations are still unverified.

#### Scenario: The frozen list holds only valid confirmed students
- **WHEN** a list is frozen
- **THEN** it SHALL contain only valid participations marked as confirmed students
- **AND** the system SHALL store a hash of that list.

#### Scenario: Freezing the same list twice produces the same hash
- **WHEN** the same set of participations is frozen again
- **THEN** the resulting hash SHALL be identical.

#### Scenario: The draw is reproducible
- **WHEN** a draw is re-executed with the same frozen list and the same seed
- **THEN** the result SHALL be exactly the same, in the same order.

#### Scenario: The recorded seed is the real seed
- **WHEN** the system derives the random state from the recorded seed
- **THEN** it SHALL NOT reduce the seed to a narrower value before deriving
- **AND** the existing batch recipient selection SHALL keep its current observable behaviour.

#### Scenario: No draw without a frozen list
- **WHEN** a draw is requested for a campaign whose list is not frozen
- **THEN** the system SHALL refuse it.

#### Scenario: Invalidating after the freeze changes nothing already decided
- **WHEN** a participation is invalidated after the list was frozen
- **THEN** the frozen list SHALL remain unchanged
- **AND** an already executed draw SHALL remain unchanged
- **AND** a new draw SHALL require a new freeze.

#### Scenario: A draw is a deliberate human act
- **WHEN** a draw is executed
- **THEN** it SHALL require an explicit confirmation from a user holding the draw permission
- **AND** the system SHALL record who executed it and when
- **AND** the system SHALL NOT offer scheduled automatic draws.

### Requirement: Prize Coupon Delivery
The system SHALL deliver the prize as a coupon assigned to a winner, and SHALL treat the coupon code as a value that must not leak.

#### Scenario: Importing a coupon batch is idempotent
- **WHEN** the same coupon CSV is imported twice for a campaign
- **THEN** no code SHALL be duplicated.

#### Scenario: Not enough coupons refuses the draw
- **WHEN** a draw would produce more winners than there are available coupons
- **THEN** the system SHALL refuse the draw
- **AND** it SHALL state how many coupons are missing.

#### Scenario: Two winners never receive the same code
- **WHEN** coupons are assigned to winners, including under concurrency
- **THEN** each coupon code SHALL be assigned to at most one participation.

#### Scenario: The code never appears where it should not
- **WHEN** the system writes a log entry, an error message, an audit event, a participant export or a conversation history entry
- **THEN** the coupon code SHALL NOT appear in clear text
- **AND** the conversation history SHALL store a reference to the coupon instead of the code.

#### Scenario: Seeing a code requires the coupon permission
- **WHEN** a user without the coupon administration permission opens a screen that lists coupons
- **THEN** the system SHALL NOT display the codes.

#### Scenario: Coupon delivery obeys the confirmation throttle
- **WHEN** coupon messages are sent to winners
- **THEN** they SHALL pass through the same global throttle as the confirmations.

### Requirement: Survey Started From Enrolment
The system SHALL be able to start a conversational survey from a keyword enrolment, reusing the existing flow engine, and SHALL make this optional per campaign.

#### Scenario: A campaign without a flow only enrols
- **WHEN** a campaign has no conversational flow selected
- **THEN** the system SHALL send only the confirmation
- **AND** it SHALL NOT create any flow state.

#### Scenario: Confirmation and survey invitation are one message
- **WHEN** a campaign with a flow registers a new participation
- **THEN** the confirmation and the permission request SHALL be delivered in a single message.

#### Scenario: The survey opens only after the confirmation is delivered
- **WHEN** the confirmation has not been sent yet, or its sending failed
- **THEN** the system SHALL NOT create the flow state
- **AND** the participation SHALL remain registered regardless.

#### Scenario: The keyword is not read as the permission answer
- **WHEN** the flow state is created from an enrolment
- **THEN** the message that carried the keyword SHALL already be marked as processed by the flow
- **AND** the flow SHALL wait for permission without sending a question.

#### Scenario: Granting permission sends the configured question
- **WHEN** the person answers the permission request positively
- **THEN** the flow engine SHALL send a question belonging to the selected flow.

#### Scenario: Someone already in a survey is enrolled without a second invitation
- **WHEN** a keyword arrives in a conversation that already has a flow state
- **THEN** the system SHALL register the participation
- **AND** it SHALL send only the confirmation
- **AND** it SHALL NOT create a second flow state.

#### Scenario: Someone already enrolled is not invited again
- **WHEN** an already enrolled contact sends the keyword again
- **THEN** the system SHALL send the already-enrolled text without the survey invitation.

### Requirement: Contact Linked To Its Conversation
The system SHALL attach the contact created from a keyword message to the conversation that carried it.

#### Scenario: An unknown number receives its confirmation
- **WHEN** a keyword arrives from a phone number with no matching contact
- **THEN** the conversation SHALL be linked to the newly created contact
- **AND** the confirmation SHALL be created and sent
- **AND** the inbound message SHALL be linked to the same contact.
