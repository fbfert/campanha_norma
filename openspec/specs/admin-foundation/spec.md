# admin-foundation Specification

## Purpose
TBD - created by archiving change etapa-1-fundação. Update Purpose after archive.
## Requirements
### Requirement: Laravel Foundation Configuration
The system SHALL be a Laravel administrative application configured with Brazilian Portuguese locale, `America/Sao_Paulo` timezone, MySQL defaults, database-backed sessions, database cache, database queue, Blade, Livewire, Alpine.js, Vite, and Apache deployment support.

#### Scenario: Application defaults
- **WHEN** the application boots
- **THEN** locale SHALL be `pt_BR`
- **AND** fallback locale SHALL be `pt_BR`
- **AND** timezone SHALL be `America/Sao_Paulo`.

### Requirement: Authentication Without Public Registration
The system SHALL provide login, logout, forgot password, reset password, remember-me, login throttling, secure session regeneration, and protected routes without allowing public user registration.

#### Scenario: Public registration unavailable
- **WHEN** an unauthenticated visitor navigates through public authentication routes
- **THEN** no public registration route or screen SHALL be available.

#### Scenario: Inactive or blocked login
- **WHEN** a user with status `inactive` or `blocked` submits valid credentials
- **THEN** authentication SHALL be denied.

### Requirement: Forced Password Change
Users marked with `must_change_password` SHALL be unable to access regular protected screens until they set a new password.

#### Scenario: Temporary password login
- **WHEN** a user with `must_change_password` logs in
- **THEN** the user SHALL be redirected to the password-change screen
- **AND** other protected screens SHALL remain inaccessible until the password is changed.

### Requirement: Initial Administrator Seeder
The system SHALL seed an initial administrator from `ADMIN_NAME`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD`, generating a secure temporary password when no password is provided.

#### Scenario: Seeder rerun
- **WHEN** the database seeder is executed more than once
- **THEN** duplicate administrator, role, permission, or setting records SHALL NOT be created.

### Requirement: Roles and Permissions
The system SHALL provide `Administrador`, `Operador`, and `Consulta` roles with permissions that control access to dashboard, users, settings, audit logs, and profile features.

#### Scenario: Operator user management denial
- **WHEN** an operator attempts to access user management
- **THEN** access SHALL be denied.

#### Scenario: Administrator user management access
- **WHEN** an administrator accesses user management
- **THEN** access SHALL be allowed.

### Requirement: User Management
Administrators SHALL be able to list, filter, view, create, edit, activate, inactivate, block, unblock, reset password, and soft-delete users.

#### Scenario: Administrator self-protection
- **WHEN** an administrator attempts to block or delete their own account
- **THEN** the system SHALL reject the action.

#### Scenario: Last administrator protection
- **WHEN** an action would leave the system without an active administrator
- **THEN** the system SHALL reject the action.

### Requirement: Profile Management
Authenticated users SHALL be able to view their profile, update their own name, and change their own password by providing the current password.

#### Scenario: User changes own password
- **WHEN** a user submits the current password, a valid new password, and confirmation
- **THEN** the password SHALL be updated
- **AND** the change SHALL be audited.

### Requirement: Administrative Layout
The system SHALL provide a responsive administrative layout with sidebar, header, breadcrumbs, page title, flash messages, validation messages, empty states, and permission-aware navigation.

#### Scenario: Permission-aware menu
- **WHEN** a user lacks permission for settings, users, or audit logs
- **THEN** those menu links SHALL not be available as active navigation targets.

### Requirement: Initial Dashboard
The dashboard SHALL show active users, blocked users, administrator count, current user's latest access, general system situation, environment, Laravel version, PHP version, and reserved cards for future modules.

#### Scenario: Future module card
- **WHEN** the dashboard displays contacts, sent messages, active batches, send failures, or WhatsApp status in Etapa 1
- **THEN** the card SHALL state `Modulo ainda nao implementado`
- **AND** no fake module tables SHALL be created for those values.

### Requirement: System Settings
Administrators SHALL be able to edit system name, timezone, date format, datetime format, and default records per page through a service-backed settings screen.

#### Scenario: Non-administrator settings update
- **WHEN** a non-administrator attempts to update settings
- **THEN** access SHALL be denied.

#### Scenario: Settings audit
- **WHEN** settings are changed
- **THEN** the old and new non-sensitive values SHALL be recorded in audit logs.

### Requirement: Audit Log Consultation
The system SHALL provide a read-only audit log screen with filters by user, action, entity, date range, and IP, plus detail view for old values, new values, user agent, and entity information.

#### Scenario: Audit logs immutable in UI
- **WHEN** a user views audit logs
- **THEN** the interface SHALL NOT provide edit or delete actions for audit records.

### Requirement: Security Controls
The system SHALL implement CSRF protection, guarded mass assignment, input validation, escaped output, login rate limits, database sessions, secure production cookie defaults, authorization checks, security headers, safe error handling, and logs without credentials.

#### Scenario: Production environment example
- **WHEN** `.env.example` is used for production preparation
- **THEN** it SHALL document `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax`.

### Requirement: Tests and Documentation
The system SHALL include automated tests for core Etapa 1 flows and README documentation for installation, environment, database, migrations, seeders, assets, local execution, tests, Apache, permissions, future cron, maintenance, and production preparation.

#### Scenario: Required exclusions documented
- **WHEN** a maintainer reads the README
- **THEN** it SHALL explicitly state that contacts, WhatsApp, QR Code, messages, placeholders, batches, sending queues, sending limits, and message history are not implemented in Etapa 1.

### Requirement: Contact Role Permissions
The system SHALL add contact permissions to existing roles.

#### Scenario: Administrator contact permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all contact permissions.

#### Scenario: Operator contact permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive view, create, update, import, export, manage tags, mark do-not-contact, and sensitive-data contact permissions, but not restore.

#### Scenario: Consulta contact permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL receive view contact permission and export only when authorized by configuration.

### Requirement: Contact Navigation
The administrative menu SHALL include contact navigation according to permissions.

#### Scenario: Contact menu
- **WHEN** a user has `contacts.view`
- **THEN** the menu SHALL include links for all contacts, new contact, import, import history, and tags as permitted.

### Requirement: WhatsApp Role Permissions
The system SHALL add backend-protected WhatsApp permissions for connection viewing, connection management, disconnection, session clearing, test-message sending, and technical event viewing.

#### Scenario: Administrator permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all WhatsApp permissions.

#### Scenario: Operator permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL receive WhatsApp connection view permission by default
- **AND** SHALL NOT receive disconnect, session clear, or test-message permission by default.

### Requirement: WhatsApp Navigation
The administrative menu SHALL expose WhatsApp connection navigation according to permissions.

#### Scenario: Connection menu
- **WHEN** a user has `whatsapp.connection.view`
- **THEN** the menu SHALL include an active link to the WhatsApp connection screen.

### Requirement: Message Authoring Role Permissions
The system SHALL add backend-protected permissions for message templates and message batches.

#### Scenario: Administrator message permissions
- **WHEN** permissions are seeded
- **THEN** Administrador SHALL receive all message template and batch permissions.

#### Scenario: Operator message permissions
- **WHEN** permissions are seeded
- **THEN** Operador SHALL be able to view/create/update templates, create/update/cancel batches, view recipients, and export previews.

#### Scenario: Consulta message permissions
- **WHEN** permissions are seeded
- **THEN** Consulta SHALL be able to view templates and batches only.

### Requirement: Message Navigation
The administrative menu SHALL include message template and batch navigation according to permissions.

#### Scenario: Message menu
- **WHEN** a user has message authoring permissions
- **THEN** the menu SHALL include links to models, new batch, and batches as permitted.
