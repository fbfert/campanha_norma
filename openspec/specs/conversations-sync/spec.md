# conversations-sync Specification

## Purpose

Define the CONVERSAS administrative module, WhatsApp Web chat synchronization, idempotent persistence into the existing inbox tables, manual conversation continuity, and operational limits.

## Requirements

### Requirement: Conversations Menu
The system SHALL expose the existing inbox module as `CONVERSAS` in the administrative menu without removing legacy `/admin/inbox` routes.

#### Scenario: Authorized menu item
- **WHEN** a user with `inbox.view` opens the administration área
- **THEN** the menu SHALL show `CONVERSAS`
- **AND** the link SHALL point to the existing conversation/inbox module
- **AND** a non-zero unread badge SHALL respect the user's inbox visibility scope.

### Requirement: Friendly Routes
The system SHALL preserve `admin.inbox.*` routes and SHALL add `/admin/conversations` aliases that use the same controller, views, permissions, and business logic.

#### Scenario: Opening alias route
- **WHEN** an authorized user opens `/admin/conversations/{conversation}`
- **THEN** the existing conversation detail SHALL be rendered
- **AND** the legacy `/admin/inbox/{conversation}` route SHALL continue working.

### Requirement: Conversation Interface
The system SHALL present conversations as an operator conversation interface with a conversation list, chronological message timeline, manual reply box, and conversation details.

#### Scenario: Viewing a conversation
- **WHEN** an operator opens a conversation
- **THEN** messages SHALL be escaped, ordered chronologically, and visually separated by direction
- **AND** manual replies SHALL continue through `ManualReplyService`, `whatsapp-manual-replies`, and `WhatsAppProvider`.

### Requirement: WhatsApp Web Chat Sync API
The Node.js service SHALL expose private authenticated endpoints for listing individual chats and fetching messages from a selected chat using the installed `whatsapp-web.js` runtime.

#### Scenario: Listing chats
- **WHEN** Laravel calls `GET /api/conversations`
- **THEN** the service SHALL require the internal token
- **AND** return only non-group, non-status, non-channel individual chats within configured limits.

#### Scenario: Fetching messages
- **WHEN** Laravel calls `GET /api/conversations/{chatId}/messages`
- **THEN** the service SHALL validate the chat id and limit
- **AND** return normalized incoming and outgoing message metadata without session, cookies, QR Code, or downloaded média.

### Requirement: Laravel Provider Boundary
Laravel SHALL access conversation sync endpoints only through the WhatsApp provider/client abstraction.

#### Scenario: Sync service requests chats
- **WHEN** the sync service needs chats or messages
- **THEN** it SHALL call `WhatsAppProvider`
- **AND** controllers SHALL NOT call Node.js endpoints directly.

### Requirement: Idempotent Conversation Sync
The system SHALL import synchronized chats and messages into the existing `conversations` and `conversation_messages` tables idempotently.

#### Scenario: Repeated sync
- **WHEN** the same WhatsApp message is returned in multiple sync runs
- **THEN** it SHALL NOT create duplicate messages
- **AND** idempotency SHALL prioritize `provider + external_message_id`.

### Requirement: Conversation Sync Runs
The system SHALL record sync execution metadata without storing full message bodies in the run table.

#### Scenario: Completed sync
- **WHEN** a sync finishes
- **THEN** the run SHALL store status, metrics, timestamps, options, and sanitized error fields.

### Requirement: Sync Queue And Lock
Conversation synchronization SHALL run in the `whatsapp-conversation-sync` queue and SHALL prevent concurrent sync runs with a distributed lock.

#### Scenario: Duplicate sync request
- **WHEN** a sync is already pending or running
- **THEN** another manual request SHALL be rejected or ignored safely.

### Requirement: Manual-Only Continuation
The module SHALL NOT implement chatbot, automatic replies, keyword flows, AI, média download, groups, broadcast lists, channels, multiple accounts, or Meta Cloud API behavior.

#### Scenario: Recipient replies
- **WHEN** a synced incoming message is recorded
- **THEN** pending automatic messages for the contact SHALL be interrupted by existing reply interruption behavior
- **AND** continuation SHALL remain manual.
