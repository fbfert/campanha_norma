## 1. Spec and Environment

- [x] 1.1 Read `.codex/rules.md`, project docs, and applicable OpenSpec specs.
- [x] 1.2 Validate PHP, required PHP extensions, Composer, Node, npm, and MySQL availability.
- [x] 1.3 Create and validate OpenSpec change artifacts for Etapa 1.

## 2. Laravel Foundation

- [x] 2.1 Create Laravel application files without removing OpenSpec/project documentation.
- [x] 2.2 Configure locale, fallback locale, timezone, app name, database defaults, database sessions/cache/queue, Vite, Blade, Livewire, and Alpine.js.
- [x] 2.3 Prepare `.env.example`, README, Apache VirtualHost example, and production notes.

## 3. Data Model

- [x] 3.1 Add migrations for users, roles, permissions, pivots, system settings, audit logs, sessions, cache, and jobs.
- [x] 3.2 Add models, enums, casts, relationships, factories, and seeders.
- [x] 3.3 Seed roles, permissions, initial administrator, and default system settings idempotently.

## 4. Authentication and Authorization

- [x] 4.1 Implement login, logout, forgot password, reset password, throttling, and blocked/inactive login denial.
- [x] 4.2 Implement forced password change middleware and profile password update.
- [x] 4.3 Implement policies/gates for dashboard, users, settings, audit logs, and profile.

## 5. Admin Features

- [x] 5.1 Implement responsive admin layout, navigation, breadcrumbs, flash/validation messages, empty states, and Portuguese interface text.
- [x] 5.2 Implement dashboard foundation metrics and future-module placeholder cards.
- [x] 5.3 Implement user management list, filters, create, show, edit, status changes, password reset, and soft delete protections.
- [x] 5.4 Implement settings screen backed by service and cache.
- [x] 5.5 Implement read-only audit log list, filters, and details.

## 6. Verification

- [x] 6.1 Add automated tests for required Etapa 1 flows using isolated test database.
- [x] 6.2 Run migrations and seeders.
- [x] 6.3 Build frontend assets.
- [x] 6.4 Run `php artisan test`.
- [x] 6.5 Run OpenSpec validation.
