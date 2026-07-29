## ADDED Requirements

### Requirement: Conversation Automation Permissions
The system SHALL provide specific permissions for conversational research covering viewing, managing flows, managing questions, viewing conversation automation state and controlling automation execution.

#### Scenario: Administrator automation permissions
- **WHEN** the role seeder runs
- **THEN** the Administrador role SHALL receive every conversation automation permission.

#### Scenario: Operator automation permissions
- **WHEN** the role seeder runs
- **THEN** the Operador role SHALL receive viewing and execution control permissions
- **AND** SHALL NOT receive flow or question management permissions.

#### Scenario: Consulta automation permissions
- **WHEN** the role seeder runs
- **THEN** the Consulta role SHALL receive only the viewing permission.

### Requirement: Conversation Automation Navigation
The administrative menu SHALL expose the conversational research area only to users holding the viewing permission.

#### Scenario: Menu visibility
- **WHEN** a user without the viewing permission opens the administration area
- **THEN** the conversational research menu item SHALL NOT be rendered.
