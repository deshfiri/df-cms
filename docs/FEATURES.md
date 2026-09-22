# DFCP CMS — Feature Guide

This document explains, in plain language, everything the system can do. It's written for anyone getting familiar with the app — no coding knowledge required. Developers looking for technical/architecture details should read `CLAUDE.md` instead; this file is about *what the app does*, not *how it's built*.

## Table of Contents

1. [Clients — the heart of the system](#1-clients--the-heart-of-the-system)
2. [Categories & Brands](#2-categories--brands)
3. [Staff, Roles & Permissions](#3-staff-roles--permissions)
4. [Tasks](#4-tasks)
5. [Workflows](#5-workflows)
6. [Support Tickets](#6-support-tickets)
7. [Staff Reviews & Reports](#7-staff-reviews--reports)
8. [Meetings](#8-meetings)
9. [Notifications](#9-notifications)
10. [Product & Project Updates](#10-product--project-updates)
11. [Ad Campaigns & Marketing](#11-ad-campaigns--marketing)
12. [Client Portal (what clients see)](#12-client-portal-what-clients-see)
13. [Internal Chat & Calls](#13-internal-chat--calls)
14. [WhatsApp Integration](#14-whatsapp-integration)
15. [File Manager](#15-file-manager)
16. [Storage Settings](#16-storage-settings)
17. [Import & Export](#17-import--export)
18. [Google Integration](#18-google-integration)
19. [Performance Tracking](#19-performance-tracking)
20. [Dashboard & My Work](#20-dashboard--my-work)
21. [Settings & Branding](#21-settings--branding)
22. [Global Search](#22-global-search)
23. [Login & Accounts](#23-login--accounts)
24. [Live/Real-Time Features](#24-liverealtime-features)
25. [Known Gaps](#25-known-gaps--things-not-built-yet)

---

## 1. Clients — the heart of the system

Every customer the agency works with is a **Client** record: name, brand, category, website, the date they joined, a status (Running, Warning, Completed, Hold, Cancelled), and which staff member owns them. Clients can be searched, filtered, bulk-assigned, transferred to another staff member (with a full history of who owned them and when), or soft-deleted (recoverable, not permanently destroyed).

Every client also has a full **activity log** — a timeline of everything that ever happened on their record.

A client is really a folder full of related things, all reachable from that client's page:

- **Notes** — free-text notes staff leave on a client.
- **Documents** — files staff upload for/about the client, organized by document type (e.g. "Contract", "Logo"), with version history so nothing is ever truly overwritten.
- **Meetings** — book, complete, cancel, or mark as no-show; if Google is connected, a Google Meet link is generated automatically.
- **Approvals** — staff ask the client to approve something (like a design); the client says yes/no from their portal.
- **Correction Requests** — a way to flag that some information on file is wrong, from either side, with staff resolving it.
- **Action Requests** — staff ask the client to submit something (information or files); the client submits it, staff reviews it.
- **Invoices & Payments** — billing records with a Paid/Unpaid/Partial status and full history.
- **Payment Proofs** — clients upload proof of a payment made; staff verify or reject it in a review queue.
- **Refunds** — a formal request → review → approve/reject → process → complete workflow, with different people required to request, approve, and process a refund so no one can approve their own refund.
- **Portal Accounts** — staff turn on/off a client's login to their own self-service portal, or force a password reset.
- **Ad Campaigns & Brands** — see sections 2 and 11.
- **Client Satisfaction Ratings** — the data model exists, but there is currently no screen for a client to actually leave a rating (see [Known Gaps](#25-known-gaps--things-not-built-yet)).

## 2. Categories & Brands

- **Categories** classify clients (e.g. Gadgets, Skincare) for filtering and reporting.
- **Brands** sit under a client — one client can have multiple brands/pages. Brands are the unit used for ad-campaign grouping and for connecting to advertising platforms like Meta (Facebook/Instagram).

## 3. Staff, Roles & Permissions

Every staff member is a **User** with a **Role** (Super Admin, Manager, Sales, Document, Design, Website, Product, Marketing, Support, Accounts, Content, Viewer). Roles bundle together specific permissions (e.g. "manage clients", "view payments"), and permissions can also be fine-tuned per individual user.

- **Super Admin** can do everything, including managing integration secrets (Google, Meta, WhatsApp, Storage).
- **Manager** can do nearly everything Super Admin can, except touch those integration secrets.
- **Department roles** (Sales, Document, Design, Website, Product, Marketing, Support) each own their step of the client onboarding pipeline (see Workflows below) and only see/act on their own department's work.
- **Accounts** handles payments, refunds, and reporting.
- **Viewer** is read-only.

Staff can also submit **Employee Requests** (e.g. leave, equipment) for a manager to respond to.

## 4. Tasks

A general-purpose to-do system: create a task, assign it, set a priority and due date, attach files, and comment on progress. Tasks support a **review workflow** — when the assignee finishes, they submit the work, and whoever requested it either approves it or sends it back with a reason (Employee Mistake, Client Requested, Scope Change, or Management Requested). That reason matters — it feeds directly into an employee's [Performance score](#19-performance-tracking).

## 5. Workflows

There are two systems for moving work through stages, one old and one new:

- **Legacy pipeline** — a fixed, 19-step sequence every client goes through from "Deal Completed" to "Deal Closed" (meeting scheduled, agreement signed, documents collected, design, website, product upload, marketing, support...). Each step belongs to one department, who submits it for a gatekeeper's approval before the next step unlocks. This system is being phased out.
- **Flow engine (current system)** — a flexible, admin-configurable workflow builder. Admins define named **Flows** made of ordered stages, assign people to each stage, and then push real work items through them. Anyone working a stage can claim an item, work it, advance it, send it back, or cancel it — with comments and attachments along the way. Everyone has a **"My Queue"** of items waiting for them, plus a company-wide tracker of everything in flight. Items that sit too long automatically get a reminder nudge.

## 6. Support Tickets

A helpdesk: a client opens a ticket from their portal, staff see all open tickets in one queue, assign them to an agent, reply, and track status until resolved.

## 7. Staff Reviews & Reports

An internal feedback feed — any staff member can post a review or report about a colleague or a department. Only Managers/Super Admins can read the feed. This is staff-about-staff feedback, distinct from client satisfaction ratings.

## 8. Meetings

Book meetings with clients (or view a global calendar of every upcoming meeting), mark them complete/cancelled/no-show, and — if Google is connected — get an automatic Google Meet link and reminder emails.

## 9. Notifications

An in-app notification center for staff (bell icon, unread badge, mark-as-read) with a parallel version for clients in the portal. Notification badges update live without refreshing the page.

## 10. Product & Project Updates

- **Product Updates** — an internal record of where a client's product listing/sourcing stands.
- **Project Updates** — a client-visible progress log; whatever staff post here shows up on the client's portal "Updates" page.

## 11. Ad Campaigns & Marketing

- **Ad Campaigns** track advertising spend and performance per client/brand: budget, platform, who's running it, and daily reports. There's a per-client view and a company-wide view across every client.
- **Marketing / Platform Integrations** go a step further: brands can be connected directly to their advertising accounts on **Meta (Facebook/Instagram)** via a login-with-Meta flow. Once connected, the system automatically discovers ad accounts, campaigns, ad sets and ads, and syncs performance numbers (impressions, spend, etc.) into the app's own dashboards every 20 minutes — no manual data entry required.

## 12. Client Portal (what clients see)

Clients get their own separate, self-service website (`/portal`) with its own login — entirely separate from the staff login, so a client account can never accidentally see staff-only screens. From here a client can:

- View a personal **Dashboard** and a friendly **Journey** page showing how far along their project is.
- See their **Services**, **Updates**, and **Documents** (and upload their own files).
- View and download **Invoices**, and submit **proof of payment**.
- Respond to **Action Requests** and **Approvals** from staff.
- Flag incorrect information via **Correction Requests**.
- Open and reply to **Support tickets**.
- Manage **Notifications** and their own **Profile**/password.

Overdue invoices and approaching deadlines are flagged automatically every day.

## 13. Internal Chat & Calls

A real-time messaging system for staff, completely separate from WhatsApp (see next section) — this is staff-to-staff only, never customer-facing:

- 1:1 and group conversations, message reactions, delete, read receipts, and file attachments.
- Group chats can be created, renamed, and have members added/removed by the owner or an admin.
- Admins with the right permission can **monitor** any conversation for oversight/quality purposes.
- Built-in **audio calling** between staff (ring, accept/reject, end), with call history.
- Chat file attachments can be configured to auto-delete after a set retention period.

## 14. WhatsApp Integration

A separate, customer-facing system that connects the agency's (or its clients') WhatsApp Business numbers via Meta's official WhatsApp Cloud API, so staff can receive and reply to real customer WhatsApp messages from inside the app instead of a phone:

- A unified **Inbox** shows conversations, filtered to what each staff member/brand is allowed to see.
- Conversations can be **assigned** to a specific agent and their status tracked.
- Message **templates** are supported for outbound messages.
- Every incoming message is verified as genuinely coming from Meta before being processed, and traffic is rate-limited since this is the one part of the app reachable without logging in (Meta needs to be able to post to it).
- Permissions are split finely: viewing your own assigned conversations vs. viewing every brand's inbox, replying, assigning, and managing the WhatsApp numbers/templates/settings themselves are all separate rights.

This is intentionally kept isolated from Internal Chat at every level, so staff-to-staff conversations and staff-to-customer conversations never mix.

## 15. File Manager

A general-purpose, browsable folder tree for company files that don't belong to a specific client record — create folders, upload, rename, preview, download, delete. Viewing and managing are separate permissions.

## 16. Storage Settings

Controls **where uploaded files are actually stored**: on the server itself ("local"), or in the cloud via **Cloudflare R2** or **Cloudinary**. Only Super Admins can change this. Before a provider can be switched on, the system runs a live connection test — so a typo in a credential can never silently break every upload in the app. Files already stored under a previous provider keep working after a switch; there's also a command to migrate existing files from one provider to another.

## 17. Import & Export

- **Import** — upload a spreadsheet of clients, preview how its columns map to the system's fields before committing, and import them in bulk (including workflow status, product status, and payment info). Every import is logged and can be **rolled back** if something went wrong.
- **Export** — download the client list (with whatever filters are currently applied) as **Excel, CSV, or PDF**.

## 18. Google Integration

Connects the app to **Google Calendar** (and, through it, Google Meet) — nothing else. Once connected (via Google login or a service-account key, Super Admin only), booking a client meeting automatically creates a real Calendar event with a Meet video link attached. A "test connection" button confirms it's actually working, not just that credentials were saved.

## 19. Performance Tracking

A monthly 0–100 performance score for each employee, built from up to six weighted areas:

- **Task Completion** — how much of their assigned work got done.
- **On-Time Delivery** — whether it was done by the deadline.
- **Quality** — how often work was sent back specifically because of an employee mistake (other rejection reasons, like a client-requested change, don't count against them).
- **Sales Achievement** — payments collected against a personal monthly target.
- **Client Satisfaction** — planned but not yet collectible (no client-facing rating screen exists yet), so this area is currently always skipped.
- **Client Care** — how actively they're working the clients assigned to them.

If an area has no data to measure (like Client Satisfaction right now), it's skipped entirely and the other areas' weights adjust so the score still totals 100 — nobody is penalized for a metric that isn't being collected yet. When several people touch the same task, credit is split between them fairly using a documented points system.

Screens include a company scoreboard, an individual scorecard per employee, a history of past monthly scores, a live workload board (who's over/under capacity right now), and a configuration screen (Super Admin/Manager) for setting weights, sales targets, and capacity per person or department.

## 20. Dashboard & My Work

What you see on login depends on your role:

- **Super Admins and Managers** get a company-wide dashboard: client counts by status, unassigned clients, today's activity, payment totals and trends, clients that have gone quiet (no update in 30+ days), pipeline progress across all 8 major stages, upcoming meetings, top performers, recent ownership transfers, and several charts.
- **Everyone else** gets a personal "My Work" view instead, scoped to their own department and their own clients: what's waiting in their department's pipeline, their personal task list (open, submitted, completed, and things they sent back for review), their assigned clients, and today's follow-ups.

## 21. Settings & Branding

A collection of Super-Admin-only configuration screens, each following the same pattern (save credentials, then explicitly test the connection before it goes live):

- **General Settings** — core company-wide app settings.
- **Document Types** — the categories client documents get filed under.
- **Payment Categories** — what a client can be billed for.
- **Sound Settings** — which alert sound plays for which event.
- **Chat Settings** — how long internal chat attachments are kept before auto-deleting.
- **Meta App Settings**, **WhatsApp Settings**, **Google Settings**, **Storage Settings** — credentials for each external integration (see their respective sections above).

## 22. Global Search

A search box available to every logged-in user, at the top of every page, that searches across client names, brands, DFID numbers, and client notes. It only ever returns clients the searching user is actually allowed to see.

## 23. Login & Accounts

- **Staff** log in with the standard login/forgot-password/reset-password/email-verification flow. Self-registration is turned off — accounts are created by an admin, not signed up.
- **Clients** log in through a completely separate portal login system (see [Client Portal](#12-client-portal-what-clients-see)), with its own password reset flow and its own protection against deactivated accounts.

## 24. Live/Real-Time Features

Several parts of the app update instantly without refreshing the page, powered by a self-hosted real-time messaging server:

- Chat messages and unread badges.
- "Who's online now" indicators.
- Live updates on who has claimed a workflow item.
- WhatsApp inbox updates and unread counts.

## 25. Known Gaps — things not built yet

- **Client Satisfaction Ratings** — the database is ready to store them, but there is no screen yet for a client to actually submit a rating, so this part of the Performance score is always skipped for now.
- The **legacy 19-step workflow pipeline** is still active on older clients but is being phased out in favor of the newer, configurable **Flow engine** — expect it to eventually disappear.
