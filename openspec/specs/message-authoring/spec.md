# message-authoring Specification

## Purpose

Define message templates, placeholders, personalized previews, recipient eligibility checks, drafts, and batch creation pre-validation.
## Requirements
### Requirement: Message Templates
The system SHALL allow authorized users to list, search, filter, create, view, edit, duplicate, activate, inactivate, soft-delete, and restore message templates with name, slug, description, body text, status, version, timestamps, and responsible users.

#### Scenario: Active template selection
- **WHEN** a user creates a sending batch
- **THEN** active message templates SHALL be available for selection.

#### Scenario: Template versioning
- **WHEN** the body text of a template is changed
- **THEN** the previous version SHALL be preserved
- **AND** the template version SHALL be incremented.

### Requirement: Initial Placeholders
The system SHALL support the initial placeholders `{nome}`, `{primeiro_nome}`, `{telefone}`, `{email}`, `{cidade}`, `{estado}`, and `{pais}` through a centralized catalog.

#### Scenario: Personalized rendering
- **WHEN** a template contains `Oi {primeiro_nome}, como esta {cidade}?` for a contact named `Mariana de Souza` in `Brasilia`
- **THEN** the rendered message SHALL include `Oi Mariana, como esta Brasilia?`.

### Requirement: Missing Placeholder Handling
Before creating a batch, the system SHALL analyze all selected recipients and SHALL exclude contacts with required placeholder values missing.

#### Scenario: Missing city
- **WHEN** a selected contact lacks `cidade` and the message requires `{cidade}`
- **THEN** the contact SHALL NOT be included in the batch
- **AND** the system SHALL show that the placeholder `{cidade}` has no value.

### Requirement: Recipient Summary Before Batch
The system SHALL show a summary of selected, eligible, and ineligible recipients before batch creation.

#### Scenario: Validation summary
- **WHEN** selected recipients include valid and invalid contacts
- **THEN** the system SHALL show counts such as selected, eligible, missing city, invalid phone, and blocked contacts.

### Requirement: Sending Creation Screen
The sending creation screen SHALL provide contact search, filters, individual and bulk selection, random sample selection, message template selection, manual message editor, placeholder list, preview, character counter, recipient count, draft saving, batch creation, and ready-state preparation without automated scheduling or sending.

#### Scenario: Select all filtered results
- **WHEN** the user chooses all filtered contacts
- **THEN** the system SHALL preserve the filter criteria used for selection and validate the selected recipients before creating a batch.

### Requirement: Preview Samples
Before confirming a batch, the system SHALL allow the user to preview personalized message examples.

#### Scenario: Preview at least five samples
- **WHEN** a batch has five or more eligible recipients
- **THEN** the user SHALL be able to inspect at least five personalized samples before confirming.

### Requirement: Fallback Placeholder Syntax Is Deferred
The fallback placeholder syntax such as `{cidade|sua cidade}` SHALL NOT be required for the first version.

#### Scenario: First version implementation
- **WHEN** placeholder replacement is implemented for the first version
- **THEN** the system SHALL implement required-value validation
- **AND** SHALL NOT depend on fallback values to create a valid batch.

### Requirement: Placeholder Syntax Validation
The system SHALL accept only `{placeholder}` syntax for registered lowercase placeholder names and SHALL reject unknown, malformed, nested, spaced, or incomplete placeholders.

#### Scenario: Unknown placeholder
- **WHEN** a message contains `{empresa}`
- **THEN** the message SHALL be rejected with a Portuguese validation message.

### Requirement: Message Rendering Safety
The system SHALL render messages by controlled text substitution only and SHALL NOT interpret templates as Blade, PHP, JavaScript, or HTML.

#### Scenario: Contact HTML value
- **WHEN** a contact field contains HTML tags
- **THEN** rendered output SHALL contain safe text without executing HTML.

### Requirement: Batch Preview
The system SHALL provide paginated or sampled personalized previews before marking a batch ready.

#### Scenario: Preview samples
- **WHEN** a batch has eligible recipients
- **THEN** the screen SHALL show original message, placeholders, totals, rendered messages, and samples from the preserved random order.
