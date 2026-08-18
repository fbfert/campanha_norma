## ADDED Requirements

### Requirement: Inbound Sender Name
The WhatsApp service SHALL report the name of the sender when forwarding an inbound message, and the absence of a name SHALL never prevent the message from being forwarded.

#### Scenario: Preferring the name saved in the phone address book
- **WHEN** the connected phone has a saved name for the sender
- **THEN** the forwarded payload SHALL carry that name.

#### Scenario: Falling back to the profile name
- **WHEN** the connected phone has no saved name for the sender but the sender has a profile name
- **THEN** the forwarded payload SHALL carry the profile name.

#### Scenario: No name at all
- **WHEN** neither a saved name nor a profile name is available
- **THEN** the forwarded payload SHALL carry an empty sender name.

#### Scenario: The name is trimmed and bounded
- **WHEN** the obtained name has surrounding whitespace or exceeds the accepted length
- **THEN** the service SHALL trim it and limit it to the length accepted by the receiving validation
- **AND** a name that is empty after trimming SHALL be reported as absent rather than as an empty string.

#### Scenario: Failing to read the contact does not lose the message
- **WHEN** reading the sender contact raises an error
- **THEN** the service SHALL log the failure
- **AND** it SHALL forward the message with an empty sender name.

#### Scenario: The stored message keeps the reported name
- **WHEN** an inbound message carrying a sender name is processed
- **THEN** the stored message SHALL keep that name
- **AND** an inbound message without a sender name SHALL be processed normally.
