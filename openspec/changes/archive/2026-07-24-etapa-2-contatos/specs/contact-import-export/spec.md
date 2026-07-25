## ADDED Requirements

### Requirement: Import Workflow
The system SHALL import contacts through upload, header reading, mapping, pre-validation, summary, confirmation, processing, and final report.

#### Scenario: Upload does not import immediately
- **WHEN** a user uploads a CSV or XLSX file
- **THEN** contacts SHALL NOT be created until the user confirms after pre-validation.

### Requirement: Import Storage
The system SHALL store imported files outside the public directory with random internal filenames and original filenames only in the database.

#### Scenario: Private import file
- **WHEN** an import file is stored
- **THEN** it SHALL be stored under a private storage path such as `storage/app/private/contact-imports`
- **AND** the user-provided filename SHALL NOT be used as the physical path.

### Requirement: Import Pre-validation
The system SHALL record import rows with raw data, normalized data, status, and error messages before confirmation.

#### Scenario: Invalid row report
- **WHEN** a row has missing name, invalid phone, invalid email, ambiguous date, or duplicate phone
- **THEN** the row SHALL be marked invalid or duplicate with field, value, problem, and suggested action.

### Requirement: Import Duplicate Handling
The system SHALL support ignoring duplicates, updating existing contacts, creating only new contacts, and interrupting import on duplicates, defaulting to ignoring duplicates.

#### Scenario: Update does not overwrite with empty
- **WHEN** an import updates an existing contact
- **THEN** filled existing values SHALL NOT be overwritten by empty imported values.

#### Scenario: Do-not-contact preservation
- **WHEN** an imported row matches an existing do-not-contact contact
- **THEN** the import SHALL preserve the do-not-contact restriction.

### Requirement: Import Processing
The system SHALL process imports with database records, transactions by blocks, row counts, final status, and duplicate protection.

#### Scenario: Repeated confirmation
- **WHEN** an already processed import is confirmed again
- **THEN** contacts SHALL NOT be duplicated.

### Requirement: Export Contacts
The system SHALL export filtered or selected contacts to CSV or XLSX with approved columns only and audit the export.

#### Scenario: Export respects filters
- **WHEN** a user exports filtered contacts
- **THEN** the file SHALL include only contacts matching those filters.

### Requirement: Spreadsheet Template
The system SHALL provide a contact import template with the approved headers and no real personal data.

#### Scenario: Download template
- **WHEN** a user downloads the template
- **THEN** it SHALL contain the expected contact columns and no personal rows.
