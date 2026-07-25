## Why

The project needs message authoring and batch preparation before any automated sending pipeline exists. Etapa 4 adds controlled message templates, placeholders, rendering, recipient eligibility validation, random selection/order, draft batches, prepared batches, snapshots, preview, cancellation, duplication, audit, and documentation.

## What Changes

- Add message templates with versions, placeholder validation, duplication, status changes, soft delete, restore, and preview.
- Add a centralized placeholder catalog, parser, renderer, and validation services.
- Add message batches that can be drafted, validated, randomized, previewed, prepared, duplicated, and cancelled.
- Add recipient snapshots and rendered messages at preparation time.
- Add backend contact selection for manual IDs, filtered results, and random samples.
- Add permissions, menu entries, dashboard metrics, settings, exports, tests, and documentation.
- Preserve Etapa 4 boundaries: no queue processing, workers for sending, automated bulk send, limits, schedule execution, retries, inbox, chatbot, attachments, groups, multiple numbers, or official Meta API.

## Capabilities

### Modified Capabilities
- `message-authoring`: Expands templates, placeholders, rendering, preview, selection, and batch preparation.
- `batch-queue`: Adds draft/ready/cancelled batch preparation behavior while deferring processing states and workers.
- `admin-foundation`: Adds message template and batch permissions/navigation.
- `project-foundation`: Adds real template and prepared-batch dashboard metrics.
- `history-compliance`: Adds message template and message batch audit/events.

## Impact

- Adds migrations, enums, models, factories, services, controllers, Form Requests, views, tests, and documentation.
- Adds settings for message maximum length, preview sample size, manual messages, placeholders, random selection/order, and maximum batch size.
- No WhatsApp batch sending is introduced.
