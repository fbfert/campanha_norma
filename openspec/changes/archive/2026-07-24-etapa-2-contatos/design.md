## Context

Etapa 1 created a Laravel administrative foundation with custom roles, gates, audit logging, settings, dashboard, and Blade layout. Etapa 2 builds only the contact module on that foundation.

## Goals / Non-Goals

**Goals:**
- Implement contacts, tags, do-not-contact controls, filters, bulk actions, import, export, history, dashboard metrics, permissions, tests, and documentation.
- Keep business logic in services/actions, not controllers.
- Store imported files outside `public`.
- Keep contact records ready for future placeholders `{nome}`, `{primeiro_nome}`, `{telefone}`, `{email}`, and `{cidade}`.

**Non-Goals:**
- Do not implement WhatsApp, QR Code, Node.js, message templates, placeholders, lots, queues, sending limits, schedules, or message history.
- Do not verify whether a number has WhatsApp.
- Do not implement contact merge or permanent delete in this stage.

## Decisions

- Use `phpoffice/phpspreadsheet` directly for XLSX and CSV. Rationale: it supports both formats without adding opinionated import abstractions and is enough for this administrative workflow.
- Use custom services: `PhoneNormalizerService`, `ContactDuplicateService`, `ContactImportService`, `ContactExportService`, `ContactHistoryService`, and `ContactQueryService`. Rationale: controllers remain thin and tests can target behavior.
- Use database records for import preview and processing. Rationale: the user can inspect validation results before confirmation, and repeated confirmation can be made idempotent.
- Use soft deletes for contacts and tags. Rationale: history and audit trail must be preserved.
- Use application-level duplicate validation plus indexes. Rationale: MySQL-compatible partial uniqueness for active/non-deleted contacts is not portable without generated columns; service validation gives clear user messages.

## Risks / Trade-offs

- Large XLSX files may still be heavier than CSV -> enforce configurable file size and row limits and process rows in database-backed blocks.
- Application-level duplicate checks need transactions -> create/update/restore operations run duplicate checks before write and use indexed `phone_normalized`.
- Bulk “all filtered” actions can affect many rows -> resolve affected contacts from backend filters, not from thousands of browser IDs.
- Import merge is deferred -> document that duplicates can be ignored or existing contacts updated, but full merge is for a later stage.
