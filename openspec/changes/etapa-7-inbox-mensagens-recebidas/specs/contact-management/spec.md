## ADDED Requirements

### Requirement: Contact Reply Markers
The system SHALL mark contacts that have replied with `has_replied`, `first_replied_at`, and `last_replied_at`.

#### Scenario: First reply
- **WHEN** a matched contact replies for the first time
- **THEN** `has_replied` SHALL become true
- **AND** `first_replied_at` SHALL be set once
- **AND** `last_replied_at` SHALL be updated.

### Requirement: Unknown Incoming Contact
Incoming messages from unknown phones SHALL create conversations without automatically creating contacts.

#### Scenario: Unknown phone replies
- **WHEN** a received message cannot be matched to a contact
- **THEN** the conversation SHALL be created with no contact
- **AND** the UI SHALL allow manual association or contact creation by an authorized user.
