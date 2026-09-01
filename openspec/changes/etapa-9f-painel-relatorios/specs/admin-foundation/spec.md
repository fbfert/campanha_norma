## ADDED Requirements

### Requirement: Response Agenda Screens Are Separate From Analytics
The administrative interface SHALL keep the nominal response agenda in its own module, with its own menu and its own permission, separate from the aggregated analytical screens.

#### Scenario: The nominal module demands three permissions together
- **WHEN** a user opens the queue, a dossier or the printable notebook
- **THEN** the system SHALL require the response agenda permission together with the identification permission and the content permission
- **AND** it SHALL refuse the request when any of the three is missing.

#### Scenario: Aggregated screens reuse the aggregate permission
- **WHEN** a user opens the locality by topic crossing or the positioning report
- **THEN** the system SHALL require only the aggregate analytics permission.

#### Scenario: The menu separates the two modules
- **WHEN** the administrative menu is rendered
- **THEN** the response agenda SHALL appear as its own entry, apart from the analytical reports
- **AND** it SHALL appear only for users holding its permission.

#### Scenario: Every screen of the module announces what it is
- **WHEN** any screen of the response agenda module is rendered
- **THEN** it SHALL display a permanent notice that the document is nominal and must not be forwarded
- **AND** the notice SHALL name the user who is viewing it.

### Requirement: New Analytical Screens Follow The Existing Pattern
The new analytical screens SHALL follow the pattern already established by the analytics screens, including filters preserved in the address and explicit empty states.

#### Scenario: Filters are preserved in the address
- **WHEN** a user applies period and flow filters to the crossing or to the positioning report
- **THEN** the filters SHALL be reflected in the address so the view can be shared or reloaded.

#### Scenario: Navigation trail entries exist for every new screen
- **WHEN** a new screen of this subetapa is rendered
- **THEN** it SHALL have its entry in the navigation trail registry
- **AND** the registry key SHALL follow the unaccented form used by the neighbouring entries, because the key is compared by code.

#### Scenario: Empty states are explicit
- **WHEN** a new screen has no data for the selected filters
- **THEN** it SHALL state the situation in plain language
- **AND** it SHALL NOT present an empty table as though it were a result.

### Requirement: Print Layout Without New Dependencies
The administrative interface SHALL provide a print layout that removes navigation and controls, and SHALL implement it with the existing stylesheet and the existing client library.

#### Scenario: The print layout removes what is not content
- **WHEN** a screen is rendered in the print layout
- **THEN** it SHALL omit the menu, the sidebar, the navigation trail and the action buttons
- **AND** it SHALL keep a header with the title, the period, the flow, the generation date and who generated it.

#### Scenario: Cards and table rows are not split across pages
- **WHEN** the printed output is paginated
- **THEN** a dossier card SHALL NOT be split across two pages
- **AND** a table row SHALL NOT be split across two pages.

#### Scenario: Print rules live in the stylesheet
- **WHEN** the print rules are implemented
- **THEN** they SHALL be declared in the project stylesheet using the colour tokens already declared at the root
- **AND** no style element SHALL be written inside a view.
