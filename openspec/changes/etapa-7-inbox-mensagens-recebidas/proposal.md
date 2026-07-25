## Why

Etapa 7 adds the human continuation layer after the initial automated contact. The project now needs to receive WhatsApp replies, register them safely, stop pending automated sends for that person, and expose a protected inbox for manual operator follow-up.

## What Changes

- Add an authenticated and signed internal incoming-message webhook from the Node.js WhatsApp service to Laravel.
- Add conversation, conversation message, event, assignment, note, and conversation tag persistence.
- Add idempotent incoming message processing with phone/contact matching and reply-to-initial-send inference.
- Mark contacts as replied and interrupt pending automated batch recipients after a reply.
- Add inbox screens, permissions, conversation actions, notes, tags, manual replies, and basic conversation reports.
- Extend monitoring, dashboard, scheduler, queues, Node.js service, README, and operational documentation for incoming messages.

## Impact

- Affected specs: `history-compliance`, `whatsapp-connection`, `contact-management`, `batch-queue`, `admin-foundation`, `project-foundation`
- Affected code: Laravel migrations/models/enums/services/jobs/controllers/views/routes/tests, Node.js WhatsApp service incoming event forwarding, documentation.
- Constraints: no chatbot, no automated replies, no AI, no attachments download, no groups, no multiple accounts, no official Meta API in this stage.
