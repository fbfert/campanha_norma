## Why

The project needs a secure Laravel administrative foundation before adding contact management or WhatsApp sending features. This establishes authentication, authorization, auditability, settings, layout, tests, and deployment documentation without implementing message delivery.

## What Changes

- Create a Laravel application configured for Brazilian Portuguese, `America/Sao_Paulo`, MySQL, Blade, Livewire, Alpine.js, Vite, Apache deployment, and database-backed sessions/cache/queue.
- Add authentication without public registration, including password recovery, password reset, secure login throttling, logout, and forced password change for temporary credentials.
- Add user management with roles, permissions, user status, soft deletes, self-protection rules, and initial administrator seeding.
- Add system settings with cached service access and audit logging for updates.
- Add audit logs for security and administrative events without recording secrets.
- Add an initial dashboard and administrative layout.
- Add tests, factories, seeders, `.env.example`, README installation documentation, and Apache VirtualHost example.
- Do not implement contacts, WhatsApp, QR Code, Node.js, message templates, placeholders, batches, sending queues, sending limits, or message history in this stage.

## Capabilities

### New Capabilities
- `admin-foundation`: Authentication, user management, roles, permissions, settings, audit logs, dashboard, layout, seeders, tests, and deployment documentation for Etapa 1.

### Modified Capabilities
- `project-foundation`: Clarifies the concrete Etapa 1 foundation behavior and explicitly excludes WhatsApp/contact/message modules from this implementation stage.
- `history-compliance`: Adds audit-log requirements for administrative and security events in Etapa 1.

## Impact

- Creates the Laravel application structure in the current project.
- Adds Composer and npm dependencies required by Laravel, Livewire, Alpine.js, Vite, and tests.
- Adds migrations and seeders for users, roles, permissions, settings, sessions/cache/jobs, and audit logs.
- Adds Blade views, controllers, requests, policies, middleware, services, models, enums, factories, and automated tests.
- Produces deployment documentation for Apache and production preparation.
- No WhatsApp provider, contacts module, message sending, Redis worker processing, or Node.js service is introduced.
