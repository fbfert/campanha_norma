# project-foundation Specification

## Purpose

Define the base architecture, hosting model, authentication, dashboard, deployment processes, and provider abstraction for the WhatsApp initial-message manager.
## Requirements
### Requirement: Application Stack
The system SHALL use Laravel as the main administrative application, with PHP 8.3 or newer, MySQL for persistence, Redis and Laravel Queue for asynchronous processing, Blade, Livewire, Alpine.js, Apache, cron, and Supervisor or systemd.

#### Scenario: Main application responsibilities
- **WHEN** business rules, administration, queues, history, or audit features are implemented
- **THEN** they SHALL be implemented in the Laravel application
- **AND** they SHALL NOT be delegated to the WhatsApp Web service.

### Requirement: WhatsApp Service Separation
The system SHALL run the WhatsApp Web integration as a separate Node.js service.

#### Scenario: Service boundary
- **WHEN** the system needs QR Code authentication, connection status, session control, or message dispatch through WhatsApp Web
- **THEN** the Laravel application SHALL call the private Node.js service
- **AND** the Node.js service SHALL remain limited to WhatsApp connection and sending operations.

### Requirement: Hosting Layout
The system SHALL support hosting on a VPS with Apache serving the Laravel public directory.

#### Scenario: Public web root
- **WHEN** Apache is configured for the application
- **THEN** the document root SHALL point to `/var/www/gerenciador-mensagens/public`
- **AND** session files, credentials, queues, logs, and Node.js service files SHALL NOT be exposed from the public directory.

### Requirement: Permanent Processes
The system SHALL keep the Laravel queue worker and WhatsApp service running as supervised processes.

#### Scenario: Process supervision
- **WHEN** the VPS restarts or a managed process fails
- **THEN** Supervisor or systemd SHALL restart `php artisan queue:work` and the Node.js WhatsApp service automatically.

### Requirement: Authentication and Access Control
The system SHALL provide login, password recovery, administrator users, access control, action logging, and a future-compatible path for two-factor authentication.

#### Scenario: Protected administration
- **WHEN** a user accesses administrative screens
- **THEN** the system SHALL require authentication
- **AND** authorization SHALL restrict access according to user permissions.

### Requirement: Dashboard
The system SHALL provide a dashboard with administrative foundation metrics, real contact metrics after Etapa 2, real WhatsApp connection status after Etapa 3, real message template and prepared-batch metrics after Etapa 4, and processing metrics only when those future modules exist.

#### Scenario: Dashboard metrics
- **WHEN** an administrator opens the dashboard
- **THEN** the system SHALL show total contacts, active contacts, blocked contacts, messages sent today, remaining daily limit, pending messages, failed messages, active batches, WhatsApp connection status, allowed sending window, and latest service activity.

#### Scenario: Etapa 1 dashboard metrics
- **WHEN** an administrator opens the dashboard during Etapa 1
- **THEN** the system SHALL show active users, blocked users, administrator count, current user's latest access, general system situation, environment, Laravel version, and PHP version.

#### Scenario: Etapa 2 contact dashboard metrics
- **WHEN** a user opens the dashboard after Etapa 2
- **THEN** the system SHALL show real contact totals, active contacts, blocked contacts, do-not-contact contacts, contacts created today, contacts imported this month, contacts without city, and contacts without email.

#### Scenario: Etapa 3 WhatsApp dashboard metric
- **WHEN** a user opens the dashboard after Etapa 3
- **THEN** the system SHALL show the real WhatsApp connection status, connected number when permitted, latest activity, and a link to the connection screen when authorized.

#### Scenario: Etapa 4 message dashboard metrics
- **WHEN** a user opens the dashboard after Etapa 4
- **THEN** the system SHALL show active templates, inactive templates, draft batches, ready batches, cancelled batches, eligible contacts in the latest batch, and excluded contacts in the latest batch.

#### Scenario: Future dashboard metrics are reserved
- **WHEN** a dashboard card refers to messages sent today, active processing queue, send failures, or sending limits before those modules are implemented
- **THEN** it SHALL display `Modulo ainda nao implementado`
- **AND** SHALL NOT create fake operational records.

### Requirement: Provider Abstraction
The Laravel application SHALL depend on an abstract WhatsApp provider interface instead of concrete provider details.

#### Scenario: Provider migration
- **WHEN** the WhatsApp Web provider is replaced by an official WhatsApp Business API provider
- **THEN** contact, message, batch, queue, history, and dashboard logic SHALL continue using the provider abstraction without knowing provider-specific internals.
