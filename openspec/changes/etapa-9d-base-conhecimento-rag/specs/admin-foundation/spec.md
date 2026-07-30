## ADDED Requirements

### Requirement: Knowledge Base Permissions And Menu
The administration área SHALL expose the knowledge base module behind dedicated permissions, with viewing separated from managing, uploading, approving, deleting, downloading, testing retrieval and managing settings.

#### Scenario: Menu visibility
- **WHEN** a user with the knowledge viewing permission opens the administration área
- **THEN** the menu SHALL show the knowledge base entries
- **AND** a user without that permission SHALL NOT see them.

#### Scenario: Approval requires its own permission
- **WHEN** a user without the document approval permission attempts to approve a document
- **THEN** the request SHALL be denied
- **AND** the document SHALL remain non retrievable.

#### Scenario: Deletion requires its own permission
- **WHEN** a user without the document deletion permission attempts to delete a document or a base
- **THEN** the request SHALL be denied.

#### Scenario: Download requires its own permission
- **WHEN** a user without the download permission requests the original file of a document
- **THEN** the request SHALL be denied.

#### Scenario: Role defaults
- **WHEN** the role and permission seeder runs
- **THEN** the administrator role SHALL receive every knowledge permission
- **AND** the operator role SHALL receive viewing and retrieval testing without approval or deletion
- **AND** the query role SHALL receive viewing only.
