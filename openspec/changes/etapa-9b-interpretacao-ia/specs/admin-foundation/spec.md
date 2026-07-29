## ADDED Requirements

### Requirement: Interpretation Permissions
The system SHALL provide dedicated permissions for viewing interpretation results, viewing unmasked contact data on analytical screens, correcting results, reprocessing items, managing the topic taxonomy and viewing interpretation monitoring.

#### Scenario: Permission driven menu
- **WHEN** a user lacks the interpretation view permission
- **THEN** the interpretation entries SHALL be hidden from the menu
- **AND** direct access to the routes SHALL be denied.

#### Scenario: Role defaults
- **WHEN** roles are seeded
- **THEN** the administrator role SHALL receive every interpretation permission
- **AND** the operator role SHALL receive view and correction permissions without taxonomy management
- **AND** the read only role SHALL receive the view permission only.

#### Scenario: Reprocessing is separately authorized
- **WHEN** a user holds the view permission but not the reprocessing permission
- **THEN** reprocessing controls SHALL NOT be available to that user.
