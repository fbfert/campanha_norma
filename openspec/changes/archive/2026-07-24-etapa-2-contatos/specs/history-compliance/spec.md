## ADDED Requirements

### Requirement: Contact History Events
The system SHALL store contact-specific history for creation, updates, status changes, tag changes, do-not-contact changes, imports, deletes, and restores.

#### Scenario: Contact update history
- **WHEN** a contact is changed
- **THEN** contact history SHALL record user, action, safe old values, safe new values, and timestamp.

### Requirement: Contact Audit Events
The system SHALL audit contact creation, editing, phone changes, status changes, do-not-contact changes, import, export, soft delete, restore, and bulk tag application.

#### Scenario: Import audit safety
- **WHEN** a contact import is audited
- **THEN** audit logs SHALL NOT store complete spreadsheets or imported files.
