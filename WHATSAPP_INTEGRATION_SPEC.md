# WHATSAPP_INTEGRATION_SPEC.md

# Multi-Brand WhatsApp Communication Module
## Meta WhatsApp Cloud API Integration

---

## 1. PURPOSE

Implement a production-ready, multi-brand WhatsApp communication module inside the existing management system.

The system must allow administrators to connect and manage 100+ brand WhatsApp numbers through the official Meta WhatsApp Business Platform / Cloud API and allow authorized employees to receive, view, assign, and reply to WhatsApp conversations directly from the existing management system.

This module MUST remain logically and technically separate from the existing Internal Chat system.

The existing Internal Chat must continue working exactly as it currently works.

---

# 2. NON-NEGOTIABLE RULES

Claude Code MUST follow these rules throughout implementation.

1. Do NOT replace the existing Internal Chat.
2. Do NOT rename existing Internal Chat routes/components unless absolutely necessary.
3. Do NOT merge WhatsApp messages into existing internal-chat tables if doing so creates coupling.
4. WhatsApp must be an independent module.
5. Reuse existing authentication, RBAC, notifications, realtime infrastructure, UI components, or utilities ONLY when doing so does not alter existing behavior.
6. Do not duplicate existing infrastructure unnecessarily.
7. Do not hard-code WhatsApp credentials.
8. Never expose Meta Access Tokens or App Secrets to frontend JavaScript.
9. Store sensitive credentials encrypted.
10. Do not assume one WhatsApp number belongs to one brand forever; the architecture must support multiple numbers per brand.
11. Do not assume there will only be 100 brands. Design for future growth.
12. All WhatsApp authorization MUST be enforced server-side.
13. Frontend hiding is NOT considered authorization.
14. A user must never be able to access a WhatsApp conversation merely by manipulating a URL, ID, request payload, or API request.
15. A user may only see/reply to conversations they are authorized to access.
16. Admin users may have global WhatsApp access according to existing RBAC.
17. Existing application behavior must be regression-tested after implementation.
18. Do not perform destructive database or filesystem operations.
19. Do not remove existing functionality to make the WhatsApp module work.
20. Follow the existing project's coding conventions where practical.

---

# 3. FIRST STEP — MANDATORY PROJECT AUDIT

Before writing implementation code, inspect the existing project.

Do NOT immediately create migrations/controllers/components.

Audit at minimum:

### Authentication
- User model
- Authentication mechanism
- Session/token authentication
- Current user retrieval
- Middleware
- Guards

### RBAC
- Roles
- Permissions
- Role-permission relationships
- Existing authorization middleware
- Policies
- Gates
- Permission helper methods
- Super-admin behavior

### Internal Chat
Find and document:

- Routes
- Controllers
- Models
- Migrations
- Components
- API endpoints
- Realtime/broadcasting
- Notifications
- Read/unread handling
- Attachments
- Message authorization

Do NOT modify these during the audit.

### Existing Settings
Determine:

- Settings architecture
- Admin settings UI
- Credential storage pattern
- Encryption helpers
- Existing configuration management

### Sidebar / Navigation
Determine:

- Sidebar component
- Navigation configuration
- Permission-based menu rendering
- Existing naming conventions

### Notifications
Determine whether the existing system can safely be reused for:

- New WhatsApp message
- Conversation assignment
- Mention/assignment notification

### Realtime
Inspect whether the existing application already uses:

- Laravel Broadcasting
- Pusher
- Laravel Reverb
- WebSockets
- Echo
- SSE
- polling

Reuse the existing realtime infrastructure if safe.

### Queue
Inspect:

- Laravel Queue
- Redis
- Horizon
- Supervisor
- Existing jobs

WhatsApp API operations should preferably use queues.

---

# 4. AUDIT OUTPUT

Before implementation, create/update a technical audit document:

docs/WHATSAPP_IMPLEMENTATION_AUDIT.md

Include:

1. Existing authentication architecture
2. Existing RBAC architecture
3. Existing Internal Chat architecture
4. Existing settings architecture
5. Existing notification architecture
6. Existing realtime architecture
7. Existing queue architecture
8. Existing frontend architecture
9. Recommended integration points
10. Files that must NOT be modified
11. Files that may be safely reused
12. Potential conflicts
13. Final implementation plan

Do not proceed with destructive changes.

---

# 5. PRODUCT CONCEPT

The system will have two completely separate communication systems:

## Internal Chat

Existing system.

Used for:

- Employee-to-employee communication
- Internal conversations
- Existing functionality

## WhatsApp

New system.

Used for:

- Customer communication
- Brand WhatsApp numbers
- Customer conversations
- WhatsApp media
- WhatsApp templates
- Customer messaging

They must remain separate.

---

# 6. SIDEBAR STRUCTURE

Do NOT call the module "Community".

Preferred structure:

Communication
    ├── Internal Chat
    └── WhatsApp

If the existing sidebar architecture requires another suitable label, use:

Messaging
    ├── Internal Chat
    └── WhatsApp

The WhatsApp menu must be permission-aware.

Suggested submenu:

WhatsApp
    ├── Inbox
    ├── Numbers
    └── Templates

Settings → WhatsApp must remain under Settings.

---

# 7. WHATSAPP MODULE STRUCTURE

Recommended conceptual structure:

WhatsApp/
    ├── Settings
    ├── Numbers
    ├── Brands
    ├── Contacts
    ├── Conversations
    ├── Messages
    ├── Templates
    ├── Webhooks
    ├── Services
    ├── Jobs
    ├── Policies
    └── Notifications

Follow existing project structure if it has a different convention.

Do not force a new architecture if an existing architecture is already established.

---

# 8. META WHATSAPP CLOUD API

Use the official Meta WhatsApp Business Platform / Cloud API.

Do NOT use:

- Twilio
- WATI
- 360dialog
- AiSensy
- Interakt
- unofficial WhatsApp APIs
- WhatsApp Web automation
- QR-code based unofficial sessions

The system must communicate directly with Meta's official WhatsApp Business Platform.

---

# 9. MULTI-BRAND REQUIREMENT

The application must support at least:

100+ brands

but the architecture must not contain:

- hard-coded brand limits
- hard-coded phone numbers
- brand-specific controller logic
- brand-specific credentials in source code

A future system with 500+ connected WhatsApp numbers should remain architecturally possible.

---

# 10. BRAND MODEL RELATIONSHIP

Existing Brand model/table MUST be reused if the system already has one.

Do NOT create a duplicate Brand entity unless the existing system genuinely has no concept of brands.

Relationship:

Brand
    hasMany WhatsAppAccounts

WhatsAppAccount
    belongsTo Brand

Conversation
    belongsTo Brand

This allows brand-level isolation.

---

# 11. DATABASE DESIGN

Adapt naming to existing project conventions.

Recommended tables:

## whatsapp_accounts

Fields:

- id
- brand_id
- business_account_id
- phone_number_id
- display_phone_number
- verified_name
- access_token
- token_expires_at
- status
- webhook_status
- metadata
- created_at
- updated_at

Sensitive values MUST be encrypted.

Suggested status values:

- pending
- connected
- disconnected
- error
- disabled

Do not assume the exact enum implementation; follow existing project conventions.

---

## whatsapp_contacts

Fields:

- id
- name
- phone
- profile_name
- wa_id
- metadata
- created_at
- updated_at

`wa_id` should be indexed.

Phone should be normalized consistently.

---

## whatsapp_conversations

Fields:

- id
- brand_id
- whatsapp_account_id
- contact_id
- assigned_user_id
- status
- priority
- last_message_at
- last_message_preview
- unread_count
- metadata
- created_at
- updated_at

Possible statuses:

- open
- pending
- closed

Do not use Internal Chat conversation tables unless there is a safe, intentional abstraction already present.

---

## whatsapp_conversation_users

If multiple users can be assigned to one conversation, use a pivot table.

Fields:

- id
- conversation_id
- user_id
- assigned_by
- assigned_at
- created_at
- updated_at

This is preferred over forcing a single-user assignment if the business requirement may grow.

However, if the existing business process explicitly requires one responsible employee only, `assigned_user_id` may be used.

Do not make this decision blindly. Confirm from existing requirements.

---

## whatsapp_messages

Fields:

- id
- conversation_id
- whatsapp_message_id
- direction
- message_type
- body
- media_id
- media_url
- status
- error_code
- error_message
- sent_by_user_id
- metadata
- sent_at
- delivered_at
- read_at
- created_at
- updated_at

Direction:

- incoming
- outgoing

Message types should support at minimum:

- text
- image
- video
- audio
- document
- sticker
- location
- template
- interactive
- reaction
- unknown

Do not store unnecessary raw payloads in the main message columns.

Store relevant provider metadata in JSON.

---

## whatsapp_templates

Fields should support:

- id
- whatsapp_account_id
- name
- language
- category
- status
- components
- metadata
- created_at
- updated_at

Templates are controlled by Meta and should not be treated as arbitrary user-generated messages.

---

# 12. DATABASE INDEXING

Important indexes:

whatsapp_accounts:
- brand_id
- phone_number_id
- business_account_id
- status

whatsapp_contacts:
- wa_id
- phone

whatsapp_conversations:
- brand_id
- whatsapp_account_id
- contact_id
- assigned_user_id
- status
- last_message_at

whatsapp_messages:
- conversation_id
- whatsapp_message_id
- direction
- status
- created_at

Ensure uniqueness where appropriate, especially for provider message IDs.

---

# 13. SETTINGS

Admin should be able to configure Meta integration from:

Settings → WhatsApp

Required configuration fields depend on the official Meta integration architecture selected during implementation.

Potential configuration:

- Meta App ID
- Meta App Secret
- System-level configuration
- Webhook Verify Token
- API configuration
- Graph API version if intentionally configurable

Do not expose secrets in normal GET API responses.

When editing:

- masked secrets should remain masked
- empty secret fields must mean "keep existing value"
- changing credentials must be explicit

---

# 14. CREDENTIAL SECURITY

Critical:

Never expose:

- Meta App Secret
- Access Token
- Webhook secret
- system credentials

to frontend clients.

Use server-side environment/configuration where appropriate.

If database storage is required:

- encrypt at rest
- decrypt only inside service layer
- never log raw secrets
- never return raw secrets through API
- never include secrets in exception messages

Do not commit credentials into:

- Git
- source code
- `.env.example`
- documentation
- screenshots
- test fixtures

Use fake placeholders in documentation.

---

# 15. WHATSAPP NUMBER MANAGEMENT

Page:

WhatsApp → Numbers

Display:

- Brand
- WhatsApp number
- Display name
- Connection status
- Business Account
- Phone Number ID
- Last webhook activity
- Created date
- Actions

Actions:

- Add Number
- View
- Reconnect
- Disable
- Remove

"Remove" must not blindly delete historical conversations/messages.

Preferred behavior:

disable/disconnect the account while preserving historical data.

Hard deletion should require an explicit business requirement.

---

# 16. NUMBER ONBOARDING

Do not manually ask the user to type random Meta IDs unless required by the selected official onboarding flow.

Prefer Meta's official onboarding / Embedded Signup flow where applicable.

The onboarding flow must:

1. Authenticate the business
2. Authorize the required WhatsApp assets
3. Obtain the required identifiers
4. Store them securely
5. Associate the WhatsApp account with the selected brand
6. Validate connectivity
7. Register/verify webhook handling as required
8. Mark the number connected only after successful verification

Do not mark an account as connected merely because credentials were saved.

---

# 17. BRAND ASSIGNMENT DURING ONBOARDING

When connecting a WhatsApp number:

Admin must select the internal Brand.

Example:

Connect WhatsApp Number
    ↓
Select Brand
    ↓
Meta authorization
    ↓
WhatsApp Account created
    ↓
Brand ↔ WhatsApp Account relationship

One brand may have multiple WhatsApp accounts.

---

# 18. WEBHOOK

Create a dedicated WhatsApp webhook endpoint.

Do not mix WhatsApp webhook logic into unrelated controllers.

Webhook responsibilities:

- Verify Meta webhook requests
- Receive incoming events
- Validate payload
- Identify phone_number_id
- Find corresponding WhatsApp account
- Identify brand
- Process incoming message
- Process message status updates
- Process relevant events
- Store normalized data
- Trigger notifications
- Trigger realtime updates

Webhook endpoint must be fast.

Do not perform long-running processing synchronously if avoidable.

---

# 19. WEBHOOK SECURITY

Verify Meta webhook authenticity according to the official API requirements.

Do not trust:

- arbitrary phone_number_id
- arbitrary brand_id
- arbitrary conversation_id
- arbitrary message_id

from client requests.

All mappings must be resolved server-side.

Unknown phone_number_id:

- log safely
- reject or ignore appropriately
- never assign it to a random brand

---

# 20. INCOMING MESSAGE FLOW

Expected:

Meta
    ↓
Webhook
    ↓
Validate event
    ↓
phone_number_id
    ↓
WhatsApp Account
    ↓
Brand
    ↓
Contact
    ↓
Conversation
    ↓
Message
    ↓
Unread count
    ↓
Assigned user notification
    ↓
Realtime UI update

If no conversation exists:

Create one.

If conversation exists:

Append message.

Update:

- last_message_at
- last_message_preview
- unread_count

---

# 21. OUTGOING MESSAGE FLOW

Agent opens authorized conversation.

Agent writes:

"Hello, how can we help you?"

Frontend sends request to Laravel.

Laravel MUST:

1. Authenticate user
2. Check WhatsApp permission
3. Check conversation access
4. Check account status
5. Resolve correct WhatsApp account
6. Resolve correct phone_number_id
7. Validate message
8. Queue/send message
9. Store outgoing message
10. Process Meta response
11. Update message status

Never trust frontend-provided:

- brand_id
- phone_number_id
- access_token
- WhatsApp account ID

without server-side verification.

---

# 22. MESSAGE QUEUE

Outgoing messages should preferably use Laravel Queue.

Flow:

Agent
    ↓
Laravel
    ↓
Authorization
    ↓
Create pending message
    ↓
Queue Job
    ↓
Meta API
    ↓
Response
    ↓
Update status

Possible states:

- pending
- sent
- delivered
- read
- failed

Retry transient failures.

Do not infinitely retry permanent errors.

---

# 23. MESSAGE STATUS WEBHOOKS

Handle provider status updates.

At minimum:

- sent
- delivered
- read
- failed

Update the corresponding local message.

Use the provider's unique message ID.

Never rely only on timestamps or text matching.

---

# 24. UNIFIED INBOX

Page:

WhatsApp → Inbox

Three-column layout preferred:

Left:
- Brand/account filter
- conversation list
- unread count
- status filter
- assignment filter
- search

Middle:
- conversation messages

Right:
- customer information
- brand
- WhatsApp number
- assignment
- conversation status
- metadata
- actions

---

# 25. BRAND FILTER

Users with global WhatsApp access:

All Brands

Then:

Brand A
Brand B
Brand C
...
100+

Regular users:

Only brands/conversations they are authorized to access.

Do not rely only on frontend filtering.

Backend must apply the same restriction.

---

# 26. CONVERSATION VISIBILITY

This is a critical security requirement.

A user can view a conversation only if:

1. They are a WhatsApp administrator/global authorized user

OR

2. They have explicit access to that conversation

OR

3. They have an authorized assignment through the project's approved permission model

Otherwise:

HTTP 403 / equivalent authorization response.

Do NOT return the conversation data and hide it in frontend.

---

# 27. MESSAGE SEND AUTHORIZATION

Viewing and sending must be independently authorized.

A user may have:

`whatsapp.view`

without:

`whatsapp.reply`

Therefore:

- view permission = read access
- reply permission = send access

Additionally, conversation assignment must be respected.

Recommended logic:

CAN VIEW:
    global WhatsApp access
    OR assigned conversation access

CAN REPLY:
    whatsapp.reply permission
    AND conversation access
    AND account is active

---

# 28. RECOMMENDED PERMISSIONS

Add permissions according to the existing RBAC architecture.

Suggested:

whatsapp.view

whatsapp.reply

whatsapp.assign

whatsapp.manage_numbers

whatsapp.manage_templates

whatsapp.manage_settings

whatsapp.view_all_conversations

whatsapp.manage_conversations

Do NOT blindly create duplicate roles.

Integrate with the project's existing permission system.

---

# 29. ADMIN ACCESS

A super administrator may have:

- all WhatsApp numbers
- all brands
- all conversations
- assignment management
- settings
- templates
- number management

But this must be determined through existing RBAC/super-admin rules rather than hard-coded `if user_id == 1`.

Never hard-code user IDs.

---

# 30. ASSIGNMENT

Admin/authorized users can assign conversation(s) to employee(s).

Assignment UI:

Conversation
    ↓
Assign
    ↓
Select employee
    ↓
Save

After assignment:

- assigned employee gets notification
- conversation appears in their inbox
- authorized employee can view/reply

If multiple assignees are supported, define clear ownership rules.

---

# 31. UNASSIGNED CONVERSATIONS

New conversations may initially be:

Unassigned

Authorized users with:

`whatsapp.assign`

may assign them.

Normal agents should not automatically gain access to all unassigned conversations unless explicitly permitted.

---

# 32. INTERNAL CHAT ISOLATION

This is mandatory.

Existing Internal Chat must not be affected by:

- WhatsApp conversations
- WhatsApp messages
- WhatsApp contacts
- WhatsApp webhooks
- WhatsApp permissions
- WhatsApp unread counters

Do NOT add WhatsApp messages to Internal Chat message queries.

Do NOT change existing Internal Chat message types merely to support WhatsApp.

Do NOT reuse an existing Internal Chat controller for WhatsApp.

Shared services may be extracted ONLY if:

1. behavior remains unchanged
2. tests prove no regression
3. abstraction genuinely improves maintainability

---

# 33. NOTIFICATION ISOLATION

WhatsApp notifications must be distinguishable from Internal Chat notifications.

Example:

Internal Chat:
"New internal message"

WhatsApp:
"New WhatsApp message from Rahim — Brand A"

Do not mix unread counts.

Recommended:

Internal Chat unread:
23

WhatsApp unread:
41

---

# 34. REALTIME

If the existing system has a working realtime infrastructure, reuse it carefully.

Events may include:

WhatsAppMessageReceived
WhatsAppMessageSent
WhatsAppMessageStatusUpdated
WhatsAppConversationAssigned
WhatsAppConversationUpdated

Do not break existing broadcasting.

If realtime infrastructure is not available/reliable, implement a safe fallback such as polling while documenting the limitation.

---

# 35. MEDIA

Support receiving/sending where officially supported:

- images
- documents
- audio
- video
- stickers

Do not directly expose provider media URLs permanently if they expire.

Implement a secure media retrieval/storage strategy.

Do not make private customer media publicly accessible.

Authorization must also apply to media.

---

# 36. CUSTOMER CONTACTS

A WhatsApp contact belongs to the communication system, not necessarily to an internal employee.

Contact fields:

- name
- phone
- WhatsApp ID
- profile name
- metadata

A customer may interact with multiple brands.

The system must define whether the same phone number can have:

- one global contact record
- or brand-specific contact records

Prefer a reusable contact identity with brand-specific conversation relationships unless existing CRM requirements dictate otherwise.

---

# 37. SEARCH

Inbox should support:

- customer name
- phone number
- conversation
- message text where practical
- brand

Search must respect authorization.

A user must never be able to search and discover an unauthorized conversation.

---

# 38. FILTERS

Support:

- All
- Unread
- Open
- Pending
- Closed
- Assigned to me
- Unassigned
- Specific brand
- Specific WhatsApp number

All filters must be server-side where data volume requires it.

---

# 39. CONVERSATION STATUS

Recommended:

Open
Pending
Closed

Status transitions should be auditable.

Closing a conversation must NOT delete messages.

---

# 40. AUDIT LOG

Record sensitive administrative operations:

- WhatsApp number connected
- WhatsApp number disconnected
- WhatsApp number disabled
- settings changed
- credentials changed
- conversation assigned
- conversation reassigned
- conversation closed
- message send failure where useful

Never log secrets.

---

# 41. ERROR HANDLING

Handle:

- invalid credentials
- expired token
- disconnected number
- Meta API errors
- rate limits
- invalid recipient
- unsupported message type
- media errors
- webhook validation failure
- duplicate webhook events
- timeout
- temporary API outage

User-facing error messages should be understandable.

Developer logs should contain enough diagnostic context without exposing credentials.

---

# 42. IDEMPOTENCY

Webhook processing must be idempotent.

Meta may retry webhook events.

If the same provider message/event is received multiple times:

DO NOT create duplicate messages.

Use provider message/event IDs and unique constraints where appropriate.

---

# 43. RATE LIMITING

Respect Meta API limits.

Do not implement uncontrolled loops that send hundreds of messages instantly.

Queue outgoing messages where appropriate.

Implement reasonable application-level throttling if required.

---

# 44. TEMPLATE MESSAGES

The module should support Meta-approved templates.

Template management should include:

- template name
- language
- category
- status
- components

Do not allow agents to bypass Meta template requirements.

The UI should clearly distinguish:

Normal session messaging

and

Template messaging.

---

# 45. WHATSAPP POLICY / WINDOW

Do not implement business logic that assumes unlimited free-form messaging at all times.

Respect Meta's current conversation/session/template policies.

The implementation must follow the current official Meta WhatsApp Business Platform rules.

Do not hard-code outdated pricing or policy assumptions into the system.

---

# 46. API SERVICE LAYER

Do not place raw Meta HTTP calls throughout controllers.

Create a dedicated service layer.

Conceptually:

WhatsAppService
    ├── sendText()
    ├── sendImage()
    ├── sendDocument()
    ├── sendAudio()
    ├── sendVideo()
    ├── sendTemplate()
    ├── getMedia()
    ├── validateAccount()
    └── other required operations

Use the project's existing HTTP client conventions.

---

# 47. CONTROLLER RULE

Controllers should remain thin.

Bad:

Controller
    ├── API HTTP request
    ├── token handling
    ├── webhook parsing
    ├── database logic
    └── notification logic

Preferred:

Controller
    ↓
Service / Action
    ↓
Repository/Model
    ↓
Job/Event
    ↓
Notification

Follow existing architecture where applicable.

---

# 48. API ROUTES

Create a dedicated route namespace.

Example conceptual structure:

/api/whatsapp/...

Do NOT mix WhatsApp endpoints with Internal Chat endpoints.

Protect all application endpoints with authentication and authorization.

Webhook endpoint is the exception and must use Meta's webhook verification/security mechanism.

---

# 49. FRONTEND ROUTES

Keep separate namespace.

Example:

/whatsapp/inbox
/whatsapp/numbers
/whatsapp/templates

Settings:

/settings/whatsapp

Do not reuse:

/chat
/internal-chat
or existing Internal Chat routes.

---

# 50. UI DESIGN

Follow the existing management system's design system.

Do not introduce an unrelated visual framework.

Reuse:

- buttons
- dialogs
- tables
- dropdowns
- forms
- notifications
- typography
- spacing
- theme
- dark mode

where safe.

---

# 51. INBOX UX

Conversation list should show:

- customer name
- profile/phone
- last message
- timestamp
- unread count
- brand
- assignment
- status

Message area should show:

- incoming/outgoing distinction
- timestamp
- message status
- media
- sender/agent
- templates when applicable

---

# 52. BRAND IDENTIFICATION

Every WhatsApp conversation must visibly identify its brand.

Example:

Brand:
Karima World Shop

WhatsApp:
+88017xxxxxxx

This prevents agents from accidentally replying from the wrong brand account.

---

# 53. WRONG-BRAND PROTECTION

Before sending a message:

The backend must resolve:

Conversation
    ↓
WhatsApp Account
    ↓
Phone Number
    ↓
Brand

The frontend must never be allowed to choose an arbitrary `phone_number_id` and send through it.

This is a critical tenant-isolation requirement.

---

# 54. DATA ISOLATION

Every query must respect:

- brand authorization
- conversation assignment
- WhatsApp permissions

Never fetch all conversations and filter them only in frontend.

Bad:

Conversation::all()

then filter in React.

Correct:

Query only authorized conversations at the database/backend layer.

---

# 55. SECURITY TESTS

At minimum test:

### Test 1
User without WhatsApp permission cannot open Inbox.

### Test 2
User with view permission cannot send message without reply permission.

### Test 3
Agent A cannot access Agent B's assigned conversation.

### Test 4
Changing conversation ID manually does not bypass authorization.

### Test 5
Changing brand ID in request does not bypass authorization.

### Test 6
Changing phone_number_id in request does not change sending account.

### Test 7
Meta credentials never appear in frontend responses.

### Test 8
Meta credentials never appear in application logs.

### Test 9
Duplicate webhook does not create duplicate message.

### Test 10
Existing Internal Chat continues working.

---

# 56. REGRESSION TESTS

Before declaring completion:

Verify existing:

- login
- logout
- dashboard
- RBAC
- Internal Chat
- notifications
- employee management
- existing API
- existing settings
- existing sidebar
- existing realtime features

No existing functionality may regress.

---

# 57. PERFORMANCE

The architecture must be designed for:

- 100+ brands
- 100+ WhatsApp numbers
- potentially thousands/millions of messages

Avoid:

- N+1 queries
- loading entire conversation history at once
- loading all messages for all conversations
- unbounded webhook processing
- synchronous long-running API requests

Use:

- pagination
- indexes
- eager loading where appropriate
- queue
- caching where useful
- incremental message loading

---

# 58. PAGINATION

Inbox:

Paginated conversation list.

Conversation:

Paginated message history.

Prefer loading latest messages first.

Do not load thousands of messages into the browser at once.

---

# 59. LOGGING

Create structured logs for:

- webhook received
- webhook processed
- message send requested
- message send success
- message send failure
- account connection
- account disconnection

Never log:

- access tokens
- app secrets
- full sensitive payloads unnecessarily
- customer-sensitive information unnecessarily

---

# 60. CONFIGURATION

Never hard-code:

- API version
- tokens
- phone IDs
- business IDs
- webhook secrets

Use configuration/env/database securely according to the architecture.

If API version is configurable, validate it.

---

# 61. DOCUMENTATION

Create:

docs/WHATSAPP_SETUP.md

Document:

1. Meta Developer setup
2. WhatsApp Business setup
3. App configuration
4. Required permissions
5. Webhook setup
6. Embedded Signup/onboarding
7. Local development
8. Production configuration
9. Connecting a brand
10. Connecting a WhatsApp number
11. Troubleshooting
12. Security notes

Do NOT place real credentials in documentation.

---

# 62. ENVIRONMENT DOCUMENTATION

Update `.env.example` only with placeholders if the architecture requires environment-level configuration.

Example concept:

META_WHATSAPP_APP_ID=
META_WHATSAPP_APP_SECRET=
META_WHATSAPP_WEBHOOK_VERIFY_TOKEN=

Never commit real values.

If production credentials are stored in the database through the Settings UI, document that instead of duplicating the secret architecture unnecessarily.

---

# 63. DEVELOPMENT MODE

Provide a safe way to test:

- webhook
- incoming messages
- outgoing messages
- status updates

without affecting production customers.

Do not create fake Meta credentials.

---

# 64. WEBHOOK LOCAL DEVELOPMENT

If local testing requires tunneling, document it separately.

Do not permanently depend on:

- ngrok
- local IP
- localhost

for production.

Production webhook must use a public HTTPS endpoint.

---

# 65. PRODUCTION REQUIREMENTS

Before production:

- HTTPS
- valid Meta webhook
- secure secrets
- queue worker
- Redis if required
- database indexes
- logging
- error monitoring
- backup
- rate limiting
- authorization tests

---

# 66. NO POLLING IF REALTIME EXISTS

If the existing application already has stable realtime broadcasting:

Use it.

Do not introduce an entirely new WebSocket provider just for WhatsApp unless technically necessary.

---

# 67. INTERNAL CHAT REGRESSION RULE

After every major WhatsApp implementation phase:

Run a regression check on Internal Chat.

At minimum verify:

- messages send
- messages receive
- unread counts
- notifications
- conversation list
- authorization
- realtime
- attachments if existing

---

# 68. IMPLEMENTATION PHASES

Claude Code must implement incrementally.

## Phase 0
Audit existing system.

Deliver:

docs/WHATSAPP_IMPLEMENTATION_AUDIT.md

STOP and verify architecture before major code changes.

## Phase 1
Database foundation.

Create migrations/models.

## Phase 2
Meta service + secure configuration.

## Phase 3
Webhook.

## Phase 4
WhatsApp account/number management.

## Phase 5
Contacts/conversations/messages.

## Phase 6
Unified Inbox.

## Phase 7
Assignment.

## Phase 8
RBAC.

## Phase 9
Realtime/notifications.

## Phase 10
Media/templates.

## Phase 11
Testing.

## Phase 12
Documentation and deployment.

---

# 69. GIT / CHANGE SAFETY

Before implementation:

Inspect git status.

Do not overwrite unrelated work.

Do not reset user changes.

Do not use destructive git commands.

Keep WhatsApp implementation changes identifiable.

If commits are part of the existing workflow, use meaningful commits.

---

# 70. DEFINITION OF DONE

The implementation is NOT complete until all are true:

[ ] Existing Internal Chat still works.

[ ] WhatsApp is a separate module.

[ ] WhatsApp appears separately in sidebar.

[ ] Admin can configure Meta integration.

[ ] Secrets are securely stored.

[ ] Admin can connect WhatsApp numbers.

[ ] Numbers can be mapped to brands.

[ ] Multiple numbers per brand are supported.

[ ] 100+ brands are architecturally supported.

[ ] Incoming WhatsApp messages are received.

[ ] Incoming messages create/update conversations.

[ ] Messages are persisted.

[ ] Customers/contacts are persisted.

[ ] Outgoing replies work.

[ ] Correct brand number is always used.

[ ] Delivery/read status is handled.

[ ] Duplicate webhooks do not duplicate messages.

[ ] Inbox supports search/filter.

[ ] Brand filtering works.

[ ] Conversation assignment works.

[ ] Assignment notifications work.

[ ] Unauthorized users cannot see conversations.

[ ] Unauthorized users cannot send messages.

[ ] View and reply permissions are separate.

[ ] Admin can manage assignments.

[ ] WhatsApp unread count is separate from Internal Chat.

[ ] Media handling works where supported.

[ ] Templates are supported where required.

[ ] Queue processing works.

[ ] Webhook failures are handled.

[ ] Meta API failures are handled.

[ ] Audit logging exists for sensitive operations.

[ ] Credentials are never exposed.

[ ] Security tests pass.

[ ] Regression tests pass.

[ ] Documentation is complete.

---

# 71. IMPORTANT IMPLEMENTATION PRINCIPLE

Do not optimize for "make the API work quickly".

Optimize for:

SECURITY
+
MULTI-BRAND ISOLATION
+
AUTHORIZATION
+
RELIABILITY
+
SCALABILITY
+
MAINTAINABILITY

The system must be able to safely handle customer communication for 100+ brands from one management panel.

---

# 72. FINAL INSTRUCTION TO CLAUDE CODE

Before changing code:

1. Read the entire project documentation.
2. Read this WHATSAPP_INTEGRATION_SPEC.md completely.
3. Audit the existing application.
4. Understand the existing RBAC.
5. Understand the existing Internal Chat.
6. Understand the existing Settings.
7. Understand the existing notification/realtime infrastructure.
8. Understand the existing Brand structure.
9. Produce the audit document.
10. Identify potential conflicts.
11. Propose the final implementation architecture.
12. Only then begin implementation.

Never guess existing architecture.

Never overwrite existing functionality merely to simplify implementation.

When an existing feature can safely be reused, reuse it.

When reuse would couple WhatsApp to Internal Chat or introduce regression risk, keep WhatsApp independent.

Every implementation decision must preserve the following invariant:

    Existing Internal Chat
            |
            | MUST CONTINUE WORKING
            v

    New WhatsApp Module
            |
            +-- 100+ Brands
            +-- Multiple Numbers
            +-- Unified Inbox
            +-- Assignment
            +-- Permission-Based Access
            +-- Direct Meta Cloud API
            +-- Secure Credentials
            +-- Queue
            +-- Webhook
            +-- Realtime
            +-- Auditability

No production credentials should be requested, invented, hard-coded, or committed.

If any Meta API capability or current requirement is uncertain, verify against the current official Meta documentation before implementation instead of guessing.

END OF SPECIFICATION