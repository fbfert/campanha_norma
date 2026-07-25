## Why

The project needs reliable contact data before WhatsApp connection and message sending can be implemented. Etapa 2 adds contact registration, organization, import, export, duplicate prevention, tags, do-not-contact enforcement, permissions, and auditability.

## What Changes

- Add a complete contact module with CRUD, soft delete, restore, filters, sorting, bulk actions, tags, contact history, and dashboard metrics.
- Add phone normalization and duplicate detection services.
- Add CSV/XLSX import with upload, mapping, pre-validation, confirmation, processing, import row records, and reports.
- Add CSV/XLSX export for filtered or selected contacts.
- Add contact-related permissions to the existing role/permission structure.
- Add settings for contact defaults, import limits, export permission, duplicate prevention, and do-not-contact reason requirement.
- Preserve Etapa 2 boundaries: no WhatsApp connection, QR Code, Node.js service, message templates, placeholders, batches, sending queues, sending limits, or message history.

## Capabilities

### New Capabilities
- `contact-import-export`: Contact import/export workflow, import storage, row validation, duplicate handling, spreadsheet template, and export auditing.

### Modified Capabilities
- `contact-management`: Expands the contact capability from base storage into the complete Etapa 2 module.
- `admin-foundation`: Adds contact permissions, contact menu entries, and role behavior.
- `project-foundation`: Updates dashboard behavior to show real contact cards while keeping message/WhatsApp cards reserved.
- `history-compliance`: Adds contact-specific history and audit events.

## Impact

- Adds migrations for contacts, tags, contact history, contact imports, import rows, and contact settings.
- Adds models, enums, services, actions, controllers, Form Requests, policies/gates, factories, seeders, views, tests, and documentation.
- Adds spreadsheet dependency for CSV/XLSX handling.
- No public API or WhatsApp integration is introduced.
