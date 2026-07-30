## ADDED Requirements

### Requirement: Reply Suggestion Permissions
The system SHALL provide dedicated permissions for viewing suggestions, approving and sending them, rejecting them, regenerating them, submitting feedback and managing generation settings.

#### Scenario: Approval is separately authorized
- **WHEN** a user holds the view permission but not the approval permission
- **THEN** approval controls SHALL NOT be available to that user
- **AND** direct access to the approval route SHALL be denied.

#### Scenario: Role defaults
- **WHEN** roles are seeded
- **THEN** the administrator role SHALL receive every suggestion permission
- **AND** the read only role SHALL receive the view permission only.

#### Scenario: Permission driven menu
- **WHEN** a user lacks the suggestion view permission
- **THEN** the approval inbox SHALL be hidden from the menu.
