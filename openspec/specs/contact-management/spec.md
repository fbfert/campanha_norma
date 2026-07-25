# contact-management Specification

## Purpose

Define contact storage, phone normalization, duplicate prevention, import review, tags, segmentation, and the do-not-contact rules.
## Requirements
### Requirement: Contact Fields
The system SHALL store contacts with name, first name, phone, normalized phone, email, city, state, country, notes, status, contact source, consent data, do-not-contact data, last-contact timestamp, creator, updater, timestamps, and soft deletion.

#### Scenario: Required fields
- **WHEN** a contact is created or imported
- **THEN** name and phone SHALL be required when `contacts.require_phone` is enabled
- **AND** status SHALL be required with one of `active`, `inactive`, or `blocked`.

#### Scenario: First name suggestion
- **WHEN** a contact is created with a full name and no first name
- **THEN** the system SHALL fill `first_name` from the first significant word in `name`.

### Requirement: Phone Normalization
The system SHALL store phone numbers in international numeric format without spaces, parentheses, dashes, or symbols.

#### Scenario: Valid normalized phone
- **WHEN** the input phone is accepted
- **THEN** the stored `phone_normalized` SHALL follow a numeric international format such as `5549999999999`.

#### Scenario: Default country code
- **WHEN** a phone is missing DDI and `contacts.default_country_code` is configured
- **THEN** the normalizer SHALL apply the configured DDI before validating the number.

#### Scenario: Invalid phone
- **WHEN** a phone is absent or invalid
- **THEN** the system SHALL identify the problem in Portuguese
- **AND** the contact SHALL NOT be eligible for creation/import until corrected.

### Requirement: Duplicate Prevention
The system SHALL prevent exact duplicate active contacts by normalized phone number when `contacts.prevent_duplicate_phone` is enabled.

#### Scenario: Existing phone on create or import
- **WHEN** a new or imported record has a normalized phone that already exists in a non-deleted contact
- **THEN** the system SHALL flag the duplicate before saving a duplicate contact.

#### Scenario: Restore conflict
- **WHEN** a deleted contact is restored and another non-deleted contact already has the same normalized phone
- **THEN** the restore SHALL be rejected with a clear conflict message.

### Requirement: Relevant Change Audit
The system SHALL record relevant contact changes in both contact history and general audit logs without storing unnecessary sensitive content.

#### Scenario: Phone update
- **WHEN** a contact phone number is updated
- **THEN** the system SHALL record the relevant change for audit purposes.

#### Scenario: Do-not-contact change
- **WHEN** a contact is marked or unmarked as do-not-contact
- **THEN** the system SHALL record the action, reason when provided, user, and timestamp.

### Requirement: Contact Import Review
The system MAY support CSV or XLSX import and SHALL present a review before final import.

#### Scenario: Import preview
- **WHEN** a user uploads contacts for import
- **THEN** the system SHALL show valid records, duplicate numbers, missing required fields, invalid phones, existing contacts, and records that will be ignored before concluding the import.

### Requirement: Tags and Segmentation
The system SHALL allow contacts to be associated with active tags for filtering, segmentation, bulk actions, import, and export.

#### Scenario: Filtering by tag
- **WHEN** a user filters contacts by a tag
- **THEN** the results SHALL include only contacts associated with that tag.

#### Scenario: Inactive tag application
- **WHEN** a user attempts to apply an inactive tag
- **THEN** the system SHALL reject the operation.

### Requirement: Do-Not-Contact Enforcement
The system SHALL treat do-not-contact as the highest-priority contact restriction and SHALL preserve it during updates and imports.

#### Scenario: Blocked contact selected
- **WHEN** a blocked or do-not-contact contact is included in a future sending selection
- **THEN** the system SHALL prevent that contact from being included in the batch
- **AND** SHALL present the reason.

#### Scenario: Reimported blocked phone
- **WHEN** a phone in the do-not-contact list is imported again
- **THEN** the block SHALL prevail over the imported record.

#### Scenario: Removing do-not-contact
- **WHEN** a user without `contacts.mark_do_not_contact` attempts to remove the restriction
- **THEN** the system SHALL deny the action.

### Requirement: Contact Permissions
The system SHALL protect contact actions using backend authorization for view, create, update, delete, restore, export, import, tag management, do-not-contact marking, and sensitive data viewing.

#### Scenario: Consulta cannot create
- **WHEN** a Consulta user attempts to create a contact
- **THEN** access SHALL be denied.

### Requirement: Contact Listing Filters
The system SHALL provide contact list search, combinable filters, pagination, sorting, and optional inclusion of soft-deleted contacts.

#### Scenario: Phone search with formatting
- **WHEN** a user searches for a phone with spaces, parentheses, or hyphens
- **THEN** the system SHALL normalize the search digits and match `phone_normalized`.

### Requirement: Bulk Contact Actions
The system SHALL support selected contacts and all-filtered-result bulk actions for tags, status, do-not-contact, export, and soft delete.

#### Scenario: All filtered results
- **WHEN** a user chooses all filtered contacts for a bulk action
- **THEN** the backend SHALL resolve contacts from the active filters instead of trusting a large browser-provided ID list.

### Requirement: Contact Detail
The system SHALL show contact details, tags, creator, updater, contact history, soft-delete state, do-not-contact alert, and future reserved message sections.

#### Scenario: Future message sections
- **WHEN** a contact detail page displays message history, lots, responses, or last send before those modules exist
- **THEN** each section SHALL state `Modulo ainda nao implementado`.

### Requirement: Contact Dashboard Metrics
The dashboard SHALL show real contact metrics after Etapa 2.

#### Scenario: Contact cards
- **WHEN** a user opens the dashboard
- **THEN** it SHALL show total contacts, active contacts, blocked contacts, do-not-contact contacts, contacts created today, contacts imported this month, contacts without city, and contacts without email.
