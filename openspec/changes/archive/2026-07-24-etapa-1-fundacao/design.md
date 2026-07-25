## Context

The repository currently contains OpenSpec artifacts and planning documentation, but no Laravel application code. Etapa 1 must establish a secure administrative Laravel foundation that later modules can build on, while preserving the architectural separation for future WhatsApp integration.

## Goals / Non-Goals

**Goals:**
- Build a Laravel foundation with authentication, authorization, user administration, settings, audit logs, dashboard, responsive Blade layout, seeders, factories, tests, and deployment documentation.
- Keep controllers thin by using Form Requests, policies/gates, enums, services, and models.
- Prepare MySQL production configuration while allowing isolated SQLite tests.
- Keep interface text in Portuguese do Brasil.

**Non-Goals:**
- Do not implement contacts, contact import, WhatsApp connection, QR Code, Node.js service, message templates, placeholders, batches, random ordering, sending queues, sending limits, schedules, message delivery, or message history.
- Do not add React, Vue, Angular, chatbot behavior, or automation beyond the administrative foundation.

## Decisions

- Use Laravel first-party authentication scaffolding patterns implemented with Blade controllers instead of public registration. Rationale: keeps dependencies minimal and allows explicit control over forced password changes and role policies. Alternative considered: Breeze, but it includes public registration flows that would need removal.
- Use a small custom role/permission system. Rationale: Etapa 1 requires simple roles and permissions only, and avoiding a package reduces dependency surface. Alternative considered: Spatie Laravel Permission, but the current scope does not need its full feature set.
- Use PHP enums for user status and permission slugs. Rationale: fixed values are explicit and testable.
- Use `SystemSettingService` with cache. Rationale: settings are read across views and controllers without repeated direct database access.
- Use an `AuditLogger` service and immutable audit-log UI. Rationale: sensitive events must be recorded centrally while filtering passwords, tokens, sessions, and secrets.
- Use SQLite for automated tests while `.env.example` targets MySQL. Rationale: tests should run locally without requiring a MySQL server, while production remains MySQL as specified.

## Risks / Trade-offs

- MySQL client/server may be absent locally -> configure MySQL in `.env.example`, document setup, and run tests against isolated SQLite.
- Building auth manually increases implementation surface -> keep it close to Laravel conventions, use Form Requests, rate limiting, password broker, session regeneration, and tests.
- Custom permissions can grow complex later -> keep schema conventional (`roles`, `permissions`, `permission_role`, `role_user`) so migration to a package remains possible.
- Forced password change can block legitimate navigation -> allow only logout and password-change routes until the flag is cleared.

## Migration Plan

1. Create Laravel project files in the repository without removing OpenSpec artifacts.
2. Configure locale, timezone, env defaults, sessions/cache/queue tables, and frontend assets.
3. Add database schema, models, enums, services, requests, middleware, policies, seeders, and factories.
4. Add administrative routes, views, dashboard, user management, settings, profile, audit logs, and security headers.
5. Add tests for required acceptance flows.
6. Run migrations, seeders, asset build, OpenSpec validation, and automated tests.
