# WhatsApp Integration — Phase 0 Implementation Audit

Audit of the existing DFCP COMS application, performed before any WhatsApp code is
written, as required by `WHATSAPP_INTEGRATION_SPEC.md` §3, §4 and §68.

**Status:** Phase 0 complete. No implementation code written. Awaiting architecture
sign-off on the open decisions in §12 before Phase 1 begins.

**Audited against:** `main`, Laravel 12, PHP 8.2+.

---

## 1. Authentication

| Aspect | Finding |
|---|---|
| Principal | `App\Models\User` extends `Illuminate\Foundation\Auth\User` |
| Traits | `HasFactory`, `Notifiable`, `Spatie\Permission\Traits\HasRoles` |
| Mechanism | Session-based (Laravel UI scaffolding), `web` guard |
| Route protection | `routes/web.php`, everything inside a single `auth` middleware group |
| Current user | `Auth::user()` / `Auth::id()` / `$request->user()` — all three are used |
| Active flag | `users.is_active` (boolean) |

**Second authentication principal.** The client portal has its own login and its own
model, `App\Models\ClientPortalUser`, with its own policies under `app/Policies/Portal/`.
It is *not* a `User`, has no roles, and deliberately never routes through `Gate`.

> **Consequence for WhatsApp:** portal users must never reach the WhatsApp module.
> Because the module will live inside the staff `auth` group and authorize through
> `Gate`/Spatie, this is satisfied by construction — but no WhatsApp code may assume
> `Auth::user()` is a `User` without checking, mirroring the existing `Gate::before`
> guard in `AppServiceProvider`.

---

## 2. RBAC

**Two layers, both already established.**

1. **Spatie permissions**, string-based. Seeded in `DatabaseSeeder::$permissions` and
   assigned to roles in the same file.
2. **Policies**, registered explicitly in `AppServiceProvider::boot()` via `Gate::policy()`.

**Super admin:**

```php
Gate::before(function ($user, $ability) {
    if ($user instanceof User && $user->hasRole('Super Admin')) {
        return true;
    }
});
```

No hard-coded user IDs anywhere — spec §29 is already satisfied by the existing design.

**Roles present:** Super Admin, Manager, Sales, Document, Design, Website, Product,
Marketing, Support, Accounts, Content, Viewer.

### Permission naming convention — deviation from the spec

The spec (§28) suggests dot-notation (`whatsapp.view`). **This codebase does not use
dot-notation.** Every existing permission is `verb noun` with a space:

```
view clients      manage clients     delete clients
view ads          manage ads         monitor chats
view performance  manage workflows   manage file-manager
```

Spec §20 ("follow the existing project's coding conventions where practical") and §28
("integrate with the project's existing permission system") both point the same way, so
the proposal in §11 below uses the existing convention.

### Brand-level authorization as it exists today

This matters a great deal for spec §25/§26/§54 and is the **single biggest gap**.

`BrandIntegrationPolicy::canReachBrand()` defines brand visibility as:

```php
$client = $brand->client;
return $client !== null && $user->can('view', $client);
```

…and `ClientPolicy::view()` is:

```php
return $user->hasAnyPermission(['view clients', 'manage clients']);
```

**There is no per-user brand scoping in the system today.** Anyone holding
`view clients` can reach every brand. The application has never needed brand-level
tenancy because all staff are internal to one agency.

Spec §26 demands that a user see a conversation only via global access, explicit access,
or assignment. That is implementable on top of what exists, but it means WhatsApp will
introduce the *first* per-user data-scoping model in this application. See open
decision **D5**.

---

## 3. Internal Chat — full inventory

**This section exists so the module can be left alone. Nothing listed here may be
modified.**

### Routes (`routes/web.php`, `auth` group)

```
GET    /chat                              chat.index
GET    /chat/conversations                chat.conversations
GET    /chat/users                        chat.users
GET    /chat/with/{user}                  chat.open
POST   /chat/with/{user}                  chat.send
POST   /chat/{conversation}/read          chat.read
DELETE /chat/messages/{message}           chat.messages.destroy
POST   /chat/messages/{message}/react     chat.messages.react
GET    /chat/attachments/{message}        chat.attachment
GET    /chat/monitor                      chat.monitor
GET    /chat/monitor/conversations        chat.monitor.conversations
GET    /chat/monitor/{conversation}       chat.monitor.show
```

### Code

| Type | File |
|---|---|
| Controller | `app/Http/Controllers/ChatController.php` |
| Service | `app/Services/ChatService.php` |
| Models | `app/Models/Conversation.php`, `Message.php`, `MessageReaction.php` |
| Events | `app/Events/MessageSent.php`, `MessageUpdated.php` |
| Views | `resources/views/chat/index.blade.php`, `monitor.blade.php` |
| Tests | `tests/Feature/ChatAttachmentTest.php`, `ChatModerationTest.php`, `ChatReplyTest.php` |

### Schema

- `conversations` — strictly 1:1: `user_one_id`, `user_two_id`, `last_message_at`.
  **There is no concept of a group or an external participant.**
- `messages` — `conversation_id`, `sender_id`, `reply_to_id`, `body`, `read_at`,
  `attachment_path`, `attachment_disk`, `attachment_name`, `attachment_mime`,
  `attachment_size`, `attachment_duration`, `deleted_at`, `deleted_by`.
- `message_reactions` — `message_id`, `user_id`, `emoji`.

### Behaviour worth preserving

- **Unread:** `messages.read_at` is null until read; `ChatService::unreadCountFor(User)`.
- **Deletion is a flag, never a row removal** — monitors still see retracted content.
- **Attachments** follow the storage provider (`attachment_disk` per row) and are
  streamed through `ChatController::attachment` with per-request authorization.
- **Sidebar badge** is computed inline in `layouts/app.blade.php` (line ~368):
  `app(\App\Services\ChatService::class)->unreadCountFor(auth()->user())`.

### Why WhatsApp cannot reuse these tables

`conversations` is a two-column user-to-user join. A WhatsApp conversation is
brand ↔ external phone number, has a status lifecycle, an assignment, an unread counter
of its own, and a provider account behind it. Forcing it into `user_one_id`/`user_two_id`
would require nullable participant columns and a discriminator on the hottest table in
the chat module — exactly the coupling spec §3, §26 and §32 forbid.

**Decision: fully separate tables.** No shared table, no shared controller, no shared
model. The only things reused are cross-cutting infrastructure (auth, RBAC, notifications,
Reverb, queue, storage, activity log, design system).

---

## 4. Settings architecture

**Storage:** `App\Models\Setting` — a `key`/`value` table with `group` and `type`.

- `Setting::get($key, $default)` / `Setting::set($key, $value)`
- Cached with `Cache::rememberForever('settings.all')` plus a per-request container
  memo; `Setting::set()` flushes both. Reads are effectively free.

**Credential pattern — three existing precedents, all identical in shape:**

| Service | Purpose |
|---|---|
| `App\Services\Meta\MetaAppSettings` | Meta *Marketing* app id/secret |
| `App\Services\Google\GoogleIntegrationSettings` | Google Meet OAuth / service account |
| `App\Services\Storage\StorageSettings` | R2 / Cloudinary credentials |

The shared contract, which WhatsApp must follow:

1. Secrets stored with `Crypt::encryptString`, decrypted only inside the service.
2. `.env`/`config()` used as a fallback when no setting row exists.
3. **A blank secret field on submit means "keep the stored value"** — the form never
   renders an existing secret back to the browser.
4. `DecryptException` is swallowed and treated as "not configured" rather than fatal
   (survives an `APP_KEY` rotation without a 500).
5. `isConfigured()` gates the feature; nothing is marked connected merely because
   credentials were saved.

This satisfies spec §13 and §14 with no new mechanism required.

**Settings UI:** `resources/views/settings/` with a shared nav partial
(`settings/partials/nav.blade.php`) grouping sections into **Workspace / Integrations /
Access**. Adding `Settings → WhatsApp` is a one-line addition to that partial's
`$groups` array under Integrations.

### ⚠ Conflict: a Meta app already exists

`MetaAppSettings` already holds a **Meta App ID and App Secret** for the Marketing
module (ad-account sync via `BrandIntegration`). Its OAuth scopes are ads-specific
(`ads_read`, `ads_management`, …) and its redirect URI is `marketing.meta.callback`.

WhatsApp Cloud API also needs Meta app credentials, plus
`whatsapp_business_management` and `whatsapp_business_messaging` scopes.

Reusing the same credential rows would couple two unrelated integrations: changing
scopes or rotating the secret for WhatsApp would silently affect live ad syncing for
every connected brand. See open decision **D3**.

---

## 5. Notification architecture

Standard Laravel notifications, 25 existing classes in `app/Notifications/`.

**The house pattern:**

```php
class TaskAssigned extends Notification
{
    use BroadcastsToDashboard;                 // adds toBroadcast() = toDatabase()

    public function via($notifiable): array { return ['database', 'broadcast']; }

    public function toDatabase($notifiable): array
    {
        return [
            'title'     => '…',
            'message'   => '…',
            'client_id' => …,     // nullable
            'url'       => route(…),
        ];
    }
}
```

The bell UI in `layouts/app.blade.php` polls every 60 s **and** listens on the
recipient's private `App.Models.User.{id}` channel for Echo's `.notification()`
callback, so a `broadcast` channel arrives live.

**Reusable for WhatsApp as-is.** Spec §33 (distinguishable notifications) is met by the
`title`/`message` text plus a `/whatsapp/inbox` URL; `client_id` is nullable so a
WhatsApp notification simply omits it.

**Unread counts stay separate** (spec §33): internal chat unread comes from
`ChatService::unreadCountFor()`; WhatsApp will have its own counter derived from
`whatsapp_conversations.unread_count`, rendered as a second sidebar badge. Neither
touches the other.

---

## 6. Realtime architecture

| Aspect | Finding |
|---|---|
| Driver | **Laravel Reverb**, self-hosted (`BROADCAST_CONNECTION=reverb`) |
| Client | Pusher JS + Laravel Echo, loaded from `public/vendor/js` in the layout (no bundler step) |
| Started by | `php artisan reverb:start` (included in `composer dev`) |
| Channel auth | `routes/channels.php` |

**Existing channels:**

```php
App.Models.User.{id}          // per-user; notifications + chat badge
conversation.{conversationId} // internal chat thread; participants or 'monitor chats'
online                        // presence; drives online dots
```

Events use `ShouldBroadcastNow` so delivery does not depend on a queue worker, and every
`broadcast()` call is wrapped in try/catch with `report($e)` — **a Reverb outage never
fails a write.** WhatsApp must copy that discipline.

**Reuse. Do not introduce a second WebSocket provider** (spec §66). New private channels
will be namespaced `whatsapp.conversation.{id}` and `whatsapp.user.{id}` so they cannot
collide with the internal-chat channels, and `routes/channels.php` gains new entries
without touching the existing three.

---

## 7. Queue architecture

| Aspect | Finding |
|---|---|
| Driver | `database` (`QUEUE_CONNECTION=database`) |
| Worker | `php artisan queue:listen` — part of `composer dev`; supervisor/systemd in production |
| Horizon / Redis | **Not installed.** No Redis dependency anywhere |
| Existing job | `app/Jobs/SyncBrandIntegration.php` (Meta ad sync, 20-min schedule) |
| Scheduler | `php artisan schedule:work`, also in `composer dev` |

**Reuse.** Outgoing WhatsApp sends and inbound webhook processing both become queued
jobs (spec §22, §18). Retry/backoff is configured per job class; no infrastructure change
is needed. Note for production: the queue worker becomes load-bearing for message
delivery, which it currently is not.

---

## 8. Storage architecture

Recently reworked and directly relevant to spec §35 (media).

- Where uploads go is one installation-wide setting:
  `StorageSettings::activeDisk()` → `local` | `cloudflare` (R2) | `cloudinary`.
- **Every stored file records its own disk** next to its path, and is read back through
  that column — never through the active disk.
- Downloads are always **proxied through authorized controllers**; provider URLs are
  never handed to the browser. `Storage::disk(...)->path()` is banned (local-only).
- `php artisan storage:status` / `storage:migrate` exist for diagnosis and moving files.

**WhatsApp media fits this exactly**, and it satisfies spec §35's "do not expose provider
media URLs permanently / authorization must apply to media" without inventing anything:
download Meta's media (their URLs expire in minutes), store it on the active disk with a
`disk` column, serve it through an authorized controller.

---

## 9. Frontend architecture

- **Blade + jQuery.** No Vue, no React, no Inertia. Page JS lives in `@push('scripts')`.
- Bootstrap 5, Bootstrap Icons (`bi bi-*`) exclusively.
- DataTables (server-side AJAX) for list views; Select2; SweetAlert2.
- **Theming via CSS custom properties only** — `--primary`, `--surface`, `--text3`,
  `--border`, `--radius-md`, `--space-*`, `--c-red`… Bootstrap contextual classes
  (`bg-primary`, `text-muted`) are explicitly banned by `CLAUDE.md` for custom UI.
- Dark mode via `data-theme="dark"` on `<html>`; status pills use `.spill` +
  `.spill-{status}` modifiers.

The internal chat view is a working precedent for a multi-column messaging layout with
Echo wiring, and the WhatsApp inbox should mirror its CSS approach (not its code).

---

## 10. Recommended integration points

| Need | Reuse | Notes |
|---|---|---|
| Auth | `auth` middleware group, `web` guard | No change |
| RBAC | Spatie permissions + a new `WhatsAppConversationPolicy` | New permissions seeded alongside existing ones |
| Super admin | Existing `Gate::before` | Automatic |
| Brand entity | **Existing `App\Models\Brand`** | Spec §10 — do not duplicate |
| Notifications | `Notification` + `BroadcastsToDashboard` | Separate classes, separate wording |
| Realtime | Reverb + Echo, new `whatsapp.*` channels | Existing channels untouched |
| Queue | `database` queue | New job classes |
| Media | `StorageSettings::activeDisk()` + per-row `disk` | Proxied downloads |
| Audit log | `ActivityLogService::log(module: 'WhatsApp', clientId: null)` | `client_id` is nullable — verified |
| Settings UI | `settings/partials/nav.blade.php` → Integrations group | One array entry |
| Credentials | New `WhatsAppSettings` service following `StorageSettings` | Encrypted, blank-means-keep |
| Design system | CSS custom properties, `.spill`, existing components | No new framework |

---

## 11. Proposed architecture

### Permissions (existing naming convention)

| Permission | Grants |
|---|---|
| `view whatsapp` | Open the inbox; see conversations you are entitled to |
| `reply whatsapp` | Send messages (independent of view — spec §27) |
| `assign whatsapp` | Assign/reassign conversations |
| `view all whatsapp` | See every brand's conversations, not just assigned ones |
| `manage whatsapp numbers` | Connect/disable WhatsApp accounts |
| `manage whatsapp templates` | Sync and manage templates |
| `manage whatsapp settings` | Meta app credentials |

Super Admin gets all via `Gate::before`. Suggested seeding: Manager → all except
settings; Support/Marketing → `view whatsapp` + `reply whatsapp`.

### Tables

`whatsapp_accounts`, `whatsapp_contacts`, `whatsapp_conversations`,
`whatsapp_messages`, `whatsapp_templates`, `whatsapp_webhook_events`
(+ `whatsapp_conversation_user` if D1 resolves to multi-assignee).

Indexes exactly as spec §12. Uniqueness on `whatsapp_messages.whatsapp_message_id`
and on `whatsapp_webhook_events.event_id` — that unique constraint *is* the idempotency
mechanism for spec §42, not application-level de-duplication.

### Authorization model (spec §26/§27)

```
CAN VIEW conversation:
    Super Admin (Gate::before)
    OR ('view whatsapp' AND 'view all whatsapp')
    OR ('view whatsapp' AND assigned to this conversation)

CAN REPLY to conversation:
    CAN VIEW
    AND 'reply whatsapp'
    AND conversation status != closed
    AND whatsapp_account.status == connected
```

Enforced in a policy, and **every query scoped at the database layer** via a
`visibleTo(User)` scope — never fetched-then-filtered (spec §54).

### Wrong-brand protection (spec §53)

The send endpoint accepts **only** a conversation id and a body. Account, phone number
id, brand and access token are all resolved server-side from the conversation. There is
no request shape in which a client can name a `phone_number_id`.

### Layering

```
Controller (thin)
  → WhatsAppConversationService / WhatsAppSendService   (authorization, persistence)
    → MetaWhatsAppClient                                (all HTTP to Meta; the only place)
    → Job (queued send)
      → Event (Reverb) + Notification
```

Mirrors the existing `Controller → Service → Model` convention, and matches how
`MetaAuthService` / `MetaResourceService` / `PlatformSyncService` already isolate Meta
HTTP in the Marketing module.

---

## 12. Open decisions — needed before Phase 1

The spec explicitly refuses to let these be decided blindly.

**D1 — Assignment model (spec §11).** Single `assigned_user_id` on the conversation, or
a `whatsapp_conversation_user` pivot for multiple assignees? Spec: *"Do not make this
decision blindly. Confirm from existing requirements."* The existing app assigns tasks
and clients to exactly one user, which argues for single-assignee with the pivot deferred.

**D2 — Contact identity (spec §36).** One global contact per phone number (a customer
messaging two brands is one contact with two conversations), or a contact per brand?
Spec prefers global; existing CRM has no customer-phone entity to conflict with.

**D3 — Meta app credentials.** Separate WhatsApp app credentials, or reuse the Marketing
`MetaAppSettings` app? Recommend **separate** — see §4 conflict above.

**D4 — Onboarding flow (spec §16).** Embedded Signup requires Meta **Tech Provider**
status and App Review; most organisations do not have it. The practical path is manual
entry of WABA ID / Phone Number ID / permanent System User token per number, validated
by a live `validateAccount()` call before the account is marked connected (which still
satisfies §16's "do not mark connected merely because credentials were saved"). Confirm
which applies to your Meta account.

**D5 — Brand scoping.** The app currently has **no per-user brand restriction**. Options:
(a) assignment-only scoping — an agent sees only conversations assigned to them,
`view all whatsapp` sees everything; (b) additionally introduce a user↔brand pivot so an
agent can be scoped to Brand A's whole inbox. (a) is smaller and satisfies the spec;
(b) is more work and introduces the app's first tenancy table.

---

## 13. Files that MUST NOT be modified

```
app/Http/Controllers/ChatController.php
app/Services/ChatService.php
app/Models/Conversation.php
app/Models/Message.php
app/Models/MessageReaction.php
app/Events/MessageSent.php
app/Events/MessageUpdated.php
resources/views/chat/index.blade.php
resources/views/chat/monitor.blade.php
routes/web.php            — the /chat route block only
```

Tests that must keep passing untouched: `ChatAttachmentTest`, `ChatModerationTest`,
`ChatReplyTest`.

## 14. Files that may be safely extended (additively)

```
routes/web.php                              — new /whatsapp + /settings/whatsapp block
routes/channels.php                         — new whatsapp.* channels
database/seeders/DatabaseSeeder.php         — new permissions + role grants
app/Providers/AppServiceProvider.php        — Gate::policy() registration
resources/views/layouts/app.blade.php       — sidebar group (see below)
resources/views/settings/partials/nav.blade.php — Settings → WhatsApp entry
config/services.php                         — whatsapp config block with env fallbacks
.env.example                                — placeholders only
```

### Sidebar (spec §6)

Today the sidebar is a flat list of `@can`-gated links with `.sb-section` headers; there
is no nesting component. The lowest-risk change that satisfies §6 is a new section
header with two links, replacing the current ungrouped Chat entries:

```
Communication            (new .sb-section header)
  Internal Chat          (existing /chat link, relabelled — route unchanged)
  Chat Monitor           (existing, unchanged)
  WhatsApp               (new, @can('view whatsapp'))
```

**Route names and URLs for internal chat do not change** — only the visible label and
its grouping. Spec §2 rule 2 permits this; if even the label change is unwanted, say so
and it stays "Chat".

---

## 15. Potential conflicts and risks

| # | Risk | Mitigation |
|---|---|---|
| 1 | Meta app credentials shared with Marketing (§4) | Separate settings keys — decision D3 |
| 2 | Permission naming differs from spec's dot-notation | Follow existing convention (§2) |
| 3 | No per-user brand scoping exists (§2) | Decision D5 |
| 4 | Sidebar has no nested-menu component | Flat section header, no new component |
| 5 | Queue becomes load-bearing for delivery | Document worker as a production requirement |
| 6 | `messages` is a hot table; a discriminator would slow chat | Separate tables — already decided |
| 7 | Webhook must be exempt from CSRF and from `auth` | Dedicated route outside the `auth` group, secured by Meta signature verification |
| 8 | Meta media URLs expire | Download-and-store on the active disk at receive time |
| 9 | Reverb outage | Copy the existing try/catch + `report()` discipline; never fail a write |
| 10 | Webhook retries duplicate messages | DB unique constraint on provider message id |

---

## 16. Phase plan

| Phase | Deliverable | Status |
|---|---|---|
| 0 | This document | ✅ decisions D1–D5 resolved (see below) |
| 1 | Migrations + models + indexes | ✅ 6 tables, 6 models |
| 2 | `WhatsAppSettings` + `MetaWhatsAppClient` + Settings → WhatsApp UI | ✅ |
| 3 | Webhook (verify, signature, idempotent ingest, queued processing) | ✅ |
| 4 | Numbers management + Embedded Signup onboarding | ⬜ **not started** |
| 5 | Contacts / conversations / messages ingestion | ✅ |
| 6 | Unified inbox (three-column) | ✅ |
| 7 | Assignment + notifications | ✅ |
| 8 | RBAC + policy + the 10 security tests from §55 | ✅ 17 tests |
| 9 | Realtime events + separate unread badge | ✅ |
| 10 | Media (receive + send) | ✅ |
| 10b | Templates UI + sending a template from the inbox | ⬜ model/API done, no UI |
| 11 | Full security + regression suite | ✅ 426 passing |
| 12 | `docs/WHATSAPP_SETUP.md`, `.env.example` placeholders | ⬜ |

### Decisions taken

| # | Decision |
|---|---|
| D1 | **Single assignee** — `assigned_user_id`, matching how tasks and clients are assigned |
| D2 | **Global contact identity** — one contact per phone, brand separation on the conversation |
| D3 | **Separate Meta credentials** — `services.whatsapp.*`, never the Marketing app's |
| D4 | **Embedded Signup** — requires Tech Provider status on the Meta app before it will run |
| D5 | **Assignment-based access** — no new tenancy table; `view all whatsapp` is the global grant |

Internal-chat regression (spec §67) runs at the end of **every** phase, not just at the
end: `php artisan test --filter=Chat`.

---

## 17. Baseline

Full suite at time of audit: **409 passed (1173 assertions)**. This is the number that
must not regress.

Working tree at audit time contained uncommitted storage-module work (see `git status`);
no WhatsApp changes have been made.
