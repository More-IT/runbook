# Runbook

Create, execute and track repeatable procedures as structured, collaborative runbooks.

This repository contains the Runbook Nextcloud app up to **Milestone 4
(Work, assignments and due dates)**: app metadata, a Vue 3 + TypeScript
frontend, a PHP backend with public Nextcloud APIs and the developer tooling
needed to build, lint, analyse and test the code base.

## Feature set

Milestone 0 — Foundation:

- App metadata, navigation entry and JSON status endpoint.
- Vue 3 + TypeScript frontend mounted through the standard Nextcloud asset
  mechanism.

Milestone 1 — Templates:

- List templates, create draft templates and edit their title and description.
- Add, edit, reorder and delete sections.
- Add, edit, reorder and delete steps.
- The eight step types: `CHECK`, `CONFIRMATION`, `TEXT`, `NUMBER`, `SELECT`,
  `DATE`, `USER`, `FILE`.
- Publish and archive templates.
- Template details with sections and steps.
- Server-side ownership and state rules.
- API client, typed models and status badges in the frontend.

Milestone 2 — Access control:

- Direct user and group sharing through a template access control list.
- Four ACL roles: `VIEWER`, `EXECUTOR`, `EDITOR`, `OWNER`.
- Effective permission resolution with strongest-role wins.
- ACL editor in the template editor (owner only).
- Principal search for users and groups.
- ACL-controlled visibility for the template list and details.

Milestone 3 — Runs and execution:

- Start a run from a published template with an independent snapshot.
- Execute steps and collect validated responses for every step type.
- Skip steps with a reason and reopen steps.
- Complete, cancel and reopen runs.
- Progress calculation and read-only completed/cancelled runs.

Milestone 4 — Work, assignments and due dates:

- Assign runs to users and groups (participants) and step assignees.
- Automatic participant access through assignments.
- Run and step due dates, plus template due offsets.
- "My Work" with all/today/upcoming/overdue/completed filters.
- Overview work counters and recent runs.

Milestone 5 — Collaboration, evidence and activity:

- Plain-text comments on runs and individual steps.
- `@userid` mentions with validation against Nextcloud users.
- Evidence attachments stored in Nextcloud AppData with a MIME allowlist and
  size limit.
- An append-only activity history with a timeline in the run detail view.

Milestone 6 — Nextcloud integrations:

- Native notifications for assignments, mentions and due/overdue steps.
- A Dashboard widget with the assigned work of the current user.
- Unified Search over templates and runs.
- An hourly background job that sends due and overdue notifications with
  persistent deduplication.

Milestone 7 — Administration settings:

- Administration settings under Settings → Administration → Runbook.
- Template creation policy (everyone, selected groups or administrators only).
- Execution toggles for comments, step/run reopening and skip reasons.
- Configurable evidence attachment size.
- Feature toggles for the Dashboard widget, notifications and Unified Search.
- A retention period setting that is stored only (no automatic deletion yet).

> Recurring runs, conditional steps, template import/export, calendar, Talk,
> webhooks, API tokens, automation and AI are **not** implemented.

## ACL roles and permissions

| Role       | View template | Start run | Edit template | Manage ACL | Delete template |
| ---------- | ------------- | --------- | ------------- | ---------- | --------------- |
| `VIEWER`   | yes           | no        | no            | no         | no              |
| `EXECUTOR` | yes           | yes       | no            | no         | no              |
| `EDITOR`   | yes           | yes       | yes           | no         | no              |
| `OWNER`    | yes           | yes       | yes           | yes        | yes             |

Users with `EXECUTOR`, `EDITOR` or `OWNER` access to a published template may
start a run. Starting a run is separate from editing a template.

The template owner is always `OWNER` and never needs a duplicate `OWNER` row in
the ACL table. A user's effective role is the strongest of:

1. ownership;
2. a direct user ACL entry;
3. an entry for a group the user belongs to.

Group membership is resolved through Nextcloud; it is never cached in the
Runbook database. ACL rows only store the principal type (`USER` or `GROUP`)
and the principal identifier. No local users or groups are created or modified.

## Run roles and assignments

A run has its own access list with two assignable roles:

| Role          | View run | Execute assigned steps | Manage assignments |
| ------------- | -------- | ---------------------- | ------------------ |
| `VIEWER`      | yes      | no                     | no                 |
| `PARTICIPANT` | yes      | yes                    | no                 |
| `OWNER`       | yes      | yes (every step)       | yes                |

- The run owner is always `OWNER` and never needs an ACL row.
- A user or group assigned to a run becomes a `PARTICIPANT`; assigning a
  principal to a run does **not** assign every step.
- A user or group assigned to a step can view the run and execute that step
  only within the run owner's consent.
- Assigning a step grants participant access to the run; removing an
  assignment never deletes historical execution data.
- The strongest role wins (OWNER > PARTICIPANT > VIEWER).
- Only the run owner may change assignments, and only while the run is active.
- Completed and cancelled runs keep their historical assignments but are
  read-only.

## Due dates and due offsets

- All timestamps are stored as Unix seconds in UTC.
- A run and a step may each have an optional `due_at`.
- A step due date must not be later than the run due date when both exist; the
  API rejects such values.
- Completed and skipped steps keep their due date for historical display.
- Due dates are never recalculated after execution starts.
- Template steps may define a `dueOffset` expressed as a **non-negative number
  of minutes** from the run start (`60` = one hour, `1440` = one day). New
  templates reject invalid offsets. Legacy or invalid stored values are ignored
  when a run starts, so they result in a step without a due date.

## Requirements

- Nextcloud 33
- PHP 8.2 – 8.5
- Node.js 20, 22 or 24 (LTS)
- npm 10 or 11
- Composer 2

## Project layout

```
appinfo/            App metadata (info.xml) and routes
build/stubs/        PHPStan stubs for runtime-only Nextcloud dependencies
css/                Generated stylesheet (build output)
img/                App icon and static images
js/                 Generated frontend bundle (build output)
l10n/               Translation files
lib/Db/             Entities and mappers
lib/Enum/           Status and type enums
lib/Middleware/     API exception to JSON middleware
lib/Migration/      Database migrations
lib/Service/        Business logic and domain exceptions
src/                Vue 3 + TypeScript frontend sources
templates/          PHP templates rendered by controllers
tests/              PHPUnit tests
```

## Install

```bash
npm install
composer install
```

## Build the frontend

The app loads the compiled bundle from `js/runbook-main.mjs` and the stylesheet
from `css/runbook-main.css`, both referenced by `templates/main.php`.

```bash
npm run build       # production build
npm run dev         # watch mode for local development
```

Build output is written to `js/` and `css/` and is not committed.

## Database migrations

Migrations live in `lib/Migration` and are applied automatically when the app
is installed or upgraded. They can also be triggered manually:

```bash
php occ migrations:migrate runbook
# or, during a server upgrade
php occ upgrade
```

Milestone 1 creates the following tables (with the configured `oc_` prefix):

- `oc_runbook_templates`
- `oc_runbook_template_sections`
- `oc_runbook_template_steps`
- `oc_runbook_template_acl`

Sections and steps are linked with foreign keys using `ON DELETE CASCADE`, so
deleting a template or section also deletes its children. Indexes are added for
the template owner, the template status, the section template id and the step
section id.

The ACL table stores one row per principal with a unique constraint on
`(template_id, principal_type, principal_id)`, a foreign key to
`runbook_templates` with `ON DELETE CASCADE`, and indexes on `template_id`,
`(principal_type, principal_id)` and `role`.

Milestone 3 adds the run tables:

- `oc_runbook_runs`
- `oc_runbook_run_sections`
- `oc_runbook_run_steps`

Run sections and run steps cascade from their parent run. The reference from a
run to its source template is nullable and uses `ON DELETE SET NULL`, so
deleting a template never deletes its historical runs. Indexes are added for the
run owner, run status, source template, run section and run step status.

Milestone 4 extends the run tables and adds a run access list:

- `oc_runbook_runs` gains a nullable `due_at`.
- `oc_runbook_run_steps` gains nullable `assignee_type`, `assignee_id` and
  `due_at` plus an index on `(assignee_type, assignee_id)`.
- `oc_runbook_run_acl` stores participant/viewer entries with a unique
  constraint on `(run_id, principal_type, principal_id)`, indexes on `run_id`,
  `(principal_type, principal_id)` and `role`, and a foreign key to
  `runbook_runs` with `ON DELETE CASCADE`.

Milestone 5 adds the collaboration tables:

- `oc_runbook_comments`
- `oc_runbook_comment_mentions`
- `oc_runbook_attachments`
- `oc_runbook_activity`

Comments, mentions and attachments cascade from their parent run (and run step)
with `ON DELETE CASCADE`; the activity history is append-only.

Milestone 6 adds the notification delivery ledger:

- `oc_runbook_notification_deliveries` stores a unique dedupe key per
  notification type, recipient and object together with a delivery state
  (`pending`/`sent`/`failed`) and attempt counter, and cascades from its run and
  run step.

The app declares MySQL/MariaDB, PostgreSQL and SQLite as supported databases.
Oracle is not declared because the required table name
`runbook_template_sections` exceeds Oracle's legacy identifier limit.

### Migration strategy

- Migrations use only public Nextcloud schema abstractions
  (`ISchemaWrapper`/`Doctrine\DBAL\Schema\Table`); no database-specific SQL is
  written.
- Each milestone adds one ordered, additive migration
  (`Version0001…` through `Version0006…`). Migration names and versions are
  ordered and never rewritten after release.
- Structural changes are guarded with `hasTable`, `hasColumn` and `hasIndex`
  checks, so a partially applied upgrade is safe to re-run.
- Foreign keys have intentional delete behavior: template/section/step children
  cascade, runs reference their source template with `ON DELETE SET NULL` so
  deleting a template preserves historical runs, and collaboration rows cascade
  from their run or run step.
- Nullable columns default to `NULL`; the notification ledger defaults to
  `status = 'pending'`, `attempts = 0` and `updated_at = 0`, which keeps the
  retryable delivery state valid after an upgrade.
- No migration deletes user data. Identifier lengths stay within the portable
  63-character limit for the supported databases.
- `tests/Unit/Migration/MigrationSchemaTest.php` verifies fresh creation,
  sequential application, guarded re-runs, tables, columns, indexes, foreign
  keys and nullable/default behavior through an in-memory schema wrapper.
  **Limitation:** executing the migrations against real MySQL, PostgreSQL and
  SQLite instances is not covered in this environment; no live Nextcloud
  integration test matrix is available.

## API overview

All endpoints require an authenticated user and CSRF protection and return JSON.
Errors use `{ "error": ..., "reason": ..., "message": ... }` with an appropriate
HTTP status code (`400`, `403`, `404`, `409`).

| Method | Path | Description |
| ------ | ---- | ----------- |
| `GET` | `/apps/runbook/api/v1/templates` | List visible templates |
| `POST` | `/apps/runbook/api/v1/templates` | Create a draft template |
| `GET` | `/apps/runbook/api/v1/templates/{id}` | Template with sections and steps |
| `PATCH` | `/apps/runbook/api/v1/templates/{id}` | Update template metadata |
| `DELETE` | `/apps/runbook/api/v1/templates/{id}` | Delete a template |
| `POST` | `/apps/runbook/api/v1/templates/{id}/publish` | Publish a draft |
| `POST` | `/apps/runbook/api/v1/templates/{id}/archive` | Archive a template |
| `POST` | `/apps/runbook/api/v1/templates/{id}/sections` | Add a section |
| `PATCH` | `/apps/runbook/api/v1/sections/{id}` | Update a section |
| `DELETE` | `/apps/runbook/api/v1/sections/{id}` | Delete a section |
| `POST` | `/apps/runbook/api/v1/sections/{id}/reorder` | Move a section |
| `POST` | `/apps/runbook/api/v1/sections/{id}/steps` | Add a step |
| `PATCH` | `/apps/runbook/api/v1/steps/{id}` | Update a step |
| `DELETE` | `/apps/runbook/api/v1/steps/{id}` | Delete a step |
| `POST` | `/apps/runbook/api/v1/steps/{id}/reorder` | Move a step |
| `GET` | `/apps/runbook/api/v1/templates/{id}/acl` | Read the access list (owner only) |
| `PUT` | `/apps/runbook/api/v1/templates/{id}/acl` | Replace the access list atomically (owner only) |
| `GET` | `/apps/runbook/api/v1/principals` | Search selectable users and groups |
| `GET` | `/apps/runbook/api/v1/runs` | List runs accessible to the current user |
| `POST` | `/apps/runbook/api/v1/templates/{id}/runs` | Start a run from a published template |
| `GET` | `/apps/runbook/api/v1/runs/{id}` | Run with sections, steps and progress |
| `POST` | `/apps/runbook/api/v1/runs/{id}/complete` | Complete a run |
| `POST` | `/apps/runbook/api/v1/runs/{id}/cancel` | Cancel a run |
| `POST` | `/apps/runbook/api/v1/runs/{id}/reopen` | Reopen a completed run |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/start` | Start a pending step |
| `PATCH` | `/apps/runbook/api/v1/run-steps/{id}` | Store a step response and/or update its assignment |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/complete` | Complete a step |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/skip` | Skip a step with a reason |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/reopen` | Reopen a completed or skipped step |
| `GET` | `/apps/runbook/api/v1/runs/{id}/acl` | Read the run participant list (owner only) |
| `PUT` | `/apps/runbook/api/v1/runs/{id}/acl` | Replace the run participant list atomically (owner only) |
| `GET` | `/apps/runbook/api/v1/my-work` | Assigned work of the current user (`filter`) |
| `GET` | `/apps/runbook/api/v1/overview` | Work counters and short lists |
| `GET` | `/apps/runbook/api/v1/runs/{id}/comments` | List run and step comments |
| `POST` | `/apps/runbook/api/v1/runs/{id}/comments` | Add a comment (`body`, optional `stepId`) |
| `PATCH` | `/apps/runbook/api/v1/comments/{id}` | Edit an own comment |
| `DELETE` | `/apps/runbook/api/v1/comments/{id}` | Delete an own comment or any comment as owner |
| `GET` | `/apps/runbook/api/v1/runs/{id}/attachments` | List evidence metadata of a run |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/attachments` | Upload evidence (`multipart/form-data`, field `file`) |
| `GET` | `/apps/runbook/api/v1/attachments/{id}` | Download evidence |
| `DELETE` | `/apps/runbook/api/v1/attachments/{id}` | Delete evidence (uploader or owner) |
| `GET` | `/apps/runbook/api/v1/runs/{id}/activity` | Activity history (`limit`), newest first |
| `GET` | `/apps/runbook/api/v1/admin/settings` | Read administration settings (administrators only) |
| `PUT` | `/apps/runbook/api/v1/admin/settings` | Save administration settings (administrators only) |
| `GET` | `/apps/runbook/api/v1/features` | Read-only feature flags for the current user |
| `GET` | `/apps/runbook/api/status` | Foundation status endpoint |

Reorder endpoints accept `{ "position": <zero-based index> }`.

The ACL `PUT` endpoint replaces the complete ACL set:

```json
{
  "entries": [
    { "principalType": "USER", "principalId": "alice", "role": "EDITOR" },
    { "principalType": "GROUP", "principalId": "engineering", "role": "EXECUTOR" }
  ]
}
```

Validation runs before any write and the replacement runs in a transaction, so
a rejected request never leaves a partial ACL behind. `OWNER` entries, unknown
users or groups, duplicate principals and unsupported types or roles are
rejected. Returned entries are normalized.

## Security model

- Every API endpoint requires an authenticated Nextcloud session. Only the
  administration settings read/write endpoints are administrator-only; all
  other endpoints use `#[NoAdminRequired]` and enforce per-resource
  authorization in the services.
- All mutating endpoints keep Nextcloud CSRF protection; only the HTML app page
  uses `#[NoCSRFRequired]`.
- Authorization is always enforced server-side. The frontend only hides or
  disables controls and never grants access.
- Access is resolved through Nextcloud identities and group membership. Runbook
  never creates or modifies users or groups.
- Inaccessible resources are reported as `404` where revealing existence would
  leak information, `403` for policy denials, and `409` for state conflicts.
- Error payloads are produced by `ExceptionMiddleware` and never contain
  storage keys, physical paths or secrets.
- User content (titles, descriptions, comments, responses) is stored and
  returned as plain text; it is never rendered as HTML.
- The notification ledger and evidence storage never expose internal storage
  keys or paths through the API.

## Authorization matrix

| Resource | Read | Mutate |
| -------- | ---- | ------ |
| Templates | Owner and any ACL role (VIEWER, EXECUTOR, EDITOR, OWNER) | `EDITOR`/`OWNER` edit content; only `OWNER` publishes, archives, deletes and manages the template ACL; creation is governed by the administration policy |
| Template sections and steps | Anyone who can view the template | `EDITOR`/`OWNER` |
| Template ACL | `OWNER` only | `OWNER` only |
| Runs | Owner, run ACL participants/viewers and step assignees | Owner completes/cancels/reopens and manages run participants; step assignees execute their steps |
| Run ACL | `OWNER` only | `OWNER` only |
| Run steps | Anyone who can view the run | Only users who may execute the step (owner or the step assignee/group) on an ACTIVE run |
| Comments | Anyone who can view the run | Owner, participants and step assignees while the run is ACTIVE and comments are enabled; only the author edits; owner or author deletes |
| Attachments | Anyone who can view the run | Only a step executor on an ACTIVE run; uploader or run owner deletes |
| Activity | Anyone who can view the run | Append-only; no API to mutate |
| Administration settings | Administrators only | Administrators only |
| Principals (user/group search) | Any authenticated user | n/a (read-only) |
| My Work / overview | The current user's own assigned work | n/a (read-only) |
| Dashboard widget | The current user's own assigned work | n/a (read-only) |
| Unified Search | Results are restricted to accessible templates and runs | n/a (read-only) |

Archived templates are read-only. Completed and cancelled runs are read-only for
steps, comments and attachments. Global feature toggles (comments, step/run
reopening, notifications, Dashboard, search) are enforced server-side.

## Visibility rules

- Owners can always view their templates.
- Users with any ACL role can view the template.
- Users without an ACL role cannot view private drafts.
- Published templates are **not** automatically visible to every authenticated
  user; publishing and sharing are separate actions.
- Archived templates remain visible to users who already have access but are
  read-only.
- Published templates created before ACL support that have no ACL entries stay
  accessible to their owner only until they are shared.

## Authorization and state rules

- Only the owner may publish, archive, delete a template or manage its ACL.
- `EDITOR` and `OWNER` may edit metadata, sections and steps.
- `VIEWER` and `EXECUTOR` have read-only access. `EXECUTOR` is additionally
  marked as eligible to start future runs.
- Authorization is always enforced on the server, never in the frontend.
- Inaccessible templates are reported as not accessible (HTTP 403) without
  leaking whether they exist.
- New templates start as `DRAFT` with version `1`.
- Publishing sets the version to `1` and requires a non-empty title.
- Every meaningful change to a `PUBLISHED` template increments its version.
  No-op updates do not increment the version.
- `ARCHIVED` templates cannot be edited or published; archiving is possible
  from `DRAFT` or `PUBLISHED`.
- Section and step positions are deterministic, contiguous and zero-based.
  Reordering and deleting always renormalize positions.

## Run lifecycle

Run states are `ACTIVE`, `COMPLETED` and `CANCELLED`:

- `ACTIVE → COMPLETED` and `ACTIVE → CANCELLED`.
- `COMPLETED → ACTIVE` only through an explicit reopen.
- `CANCELLED` runs cannot be reopened.
- Completed and cancelled runs are read-only; no step mutation is allowed.
- Owners may view and modify their runs and execute every step. Participants
  and viewers may view the run; participants may execute only the steps
  assigned to them or to one of their groups.
- A run can only be started from a `PUBLISHED` template by a user with
  `EXECUTOR`, `EDITOR` or `OWNER` access to that template.

## Step lifecycle

Step states are `PENDING`, `IN_PROGRESS`, `COMPLETED` and `SKIPPED`:

- `PENDING → IN_PROGRESS` and `PENDING → COMPLETED`.
- `IN_PROGRESS → COMPLETED` and `IN_PROGRESS → SKIPPED`.
- `PENDING → SKIPPED`.
- `COMPLETED → PENDING` and `SKIPPED → PENDING` through an explicit reopen.
- Arbitrary status changes are rejected server-side.

## Snapshot behavior

Starting a run copies the template structure into independent run records. The
snapshot stores the source template id and version, the run title and
description, section titles and order and step titles, types, required flags,
configuration and order. The copied data is authoritative for execution:

- later changes to the template never modify an existing run;
- a run stays readable if its source template is archived or deleted;
- deleting a template never cascades into runs.

## Response validation

Responses are always validated server-side:

| Step type      | Accepted response |
| -------------- | ----------------- |
| `CHECK`        | boolean |
| `CONFIRMATION` | boolean |
| `TEXT`         | string |
| `NUMBER`       | integer or float |
| `SELECT`       | one of the configured options |
| `DATE`         | ISO date (`YYYY-MM-DD`) |
| `USER`         | existing Nextcloud user UID |
| `FILE`         | not supported yet; a clear error is returned |

Required steps must have a valid response before completion and may only be
skipped with a non-empty reason. A skipped required step counts as resolved for
run completion but stays visibly marked as skipped. Run progress is calculated
from the steps (`PENDING` and `IN_PROGRESS` count as pending); it is never
persisted.

## My Work and overview

`GET /api/v1/my-work` returns steps assigned directly to the current user or to
one of their groups. The `filter` query parameter accepts:

- `all` — active (pending or in progress) work on active runs;
- `today` — active work due today;
- `upcoming` — active work due after today;
- `overdue` — active work whose due date has passed;
- `completed` — assigned steps that were completed or skipped.

"Today" is resolved in the user's Nextcloud timezone (`core`/`timezone`),
falling back to the server timezone. All storage is UTC. Notifications are not
sent in M4.

`GET /api/v1/overview` returns bounded counters (active runs, assigned active
steps, overdue, completed steps and runs this month) together with a short list
of assigned work and recent runs.

## Comments and mentions

Comments are plain text attached to a run or to one of its steps. They are never
rendered as HTML or Markdown.

- Only users who can comment (owner, participants and step assignees) may create
  or edit comments. Viewers and unrelated users may read the comments of a run
  they can access but cannot add comments.
- A comment body is trimmed, limited to 10,000 characters, must not be empty and
  must not contain HTML-like tags.
- A comment may only reference a step that belongs to the same run.
- Authors may edit and delete their own comments. The run owner may additionally
  delete any comment. Comments cannot be changed once a run is completed or
  cancelled.
- Mentions use `@userid`. Only mentions of existing Nextcloud users are stored; a
  maximum of 50 mentions per comment. Unknown usernames are ignored. Mentions
  never grant access to a run.
- No notifications are sent for mentions in this milestone.

## Evidence attachments

Evidence is uploaded per run step and stored in Nextcloud AppData under an
application-controlled path (`runs/{runId}/steps/{stepId}/evidence/{key}`). The
database only stores metadata and a random storage key; storage paths and keys
are never exposed through the API.

- Uploading requires permission to execute the step (owner or assigned user or
  group) and an `ACTIVE` run.
- Uploads are limited to 10 MiB. The MIME type is detected from the file content
  (client-provided types are ignored) against a conservative allowlist
  (`image/png`, `image/jpeg`, `image/gif`, `image/webp`, `application/pdf`,
  `text/plain`, `text/csv`, `application/json`, `application/zip`).
- A `sha256` checksum, size, sanitized filename and uploader are stored. File
  names may not contain path separators, `..` or control characters and are
  limited to 255 characters.
- The uploader and the run owner may delete evidence while the run is active.
  Deleting metadata removes the stored file; a failed metadata write never leaves
  an orphan file behind.
- Downloads require read access to the run.

## Activity history

Activity events are append-only; there is no API to update or delete them. Events
record the actor, timestamp, optional step and a small metadata payload. Storage
paths, file contents and comment bodies are never persisted in activity rows.

Recorded events include run start/completion/cancellation/reopen, run participant
changes, step assignment changes, step start/response/complete/skip/reopen,
comment add/edit/delete and evidence upload/delete. The run activity endpoint
returns the newest events first (default 100, maximum 200) and requires read
access to the run.

## Notifications

Runbook registers a native Nextcloud notification provider
(`lib/Notification/Notifier.php`) with these notification types:

- a step was assigned to the user;
- a run was shared with the user (run participant or viewer added);
- the user was mentioned in a run or step comment;
- an assigned step is due tomorrow;
- an assigned step is overdue.

Rules and guarantees:

- Only individual users are notified. Group assignments are expanded to the
  current members through Nextcloud at delivery time; groups are never notified
  directly.
- The actor of an action is never notified about their own action.
- Only recipients who can actually view the run (owner, participant, viewer or
  step assignee) receive a notification; inaccessible runs never produce one.
- Notification subjects are machine identifiers; all user-facing text is
  translated in the notifier using the recipient's language.
- Subjects use rich text with rich objects for the run and step. Comment bodies,
  evidence storage keys, file contents and storage paths are never part of a
  notification payload.
- Every notification links to the relevant Runbook view
  (`#/run/{id}` or `#/run/{id}/step/{stepId}`). The link is a plain relative
  path; the app still enforces access control server-side when the link is
  opened.
- Due and overdue timing is evaluated in the recipient's Nextcloud timezone;
  the server timezone is the fallback for users without a configured timezone.

## Dashboard widget

The Dashboard widget (`lib/Dashboard/Widget.php`) shows the current user's
assigned work using the same `WorkService` that powers "My Work":

- the number of active assigned steps, overdue steps and steps due today;
- a bounded list of the most urgent assigned work (at most seven items);
- one link per item to the relevant run and a button to the "My Work" view.

The widget reuses the existing access rules, so inaccessible runs are never
shown. When there is no assigned work it renders a translated empty state.

## Unified Search

A Unified Search provider (`lib/Search/Provider.php`) searches accessible
templates and runs.

- Access is applied server-side and before any query: templates are limited to
  owned templates and templates granted through the template ACL; runs are
  limited to owned runs plus runs reached through the run ACL or a step
  assignment.
- Inaccessible titles and metadata are never loaded or returned.
- Search terms are sanitized (LIKE wildcards and escape characters are removed)
  and bound as query parameters, so user input cannot inject SQL.
- Queries shorter than two characters return no results, and the result count is
  capped.
- Each result has a title, a type label (`Template` or `Run`), a bounded short
  description and a deep link (`#/template/{id}` or `#/run/{id}`).
- Comments, attachment contents and activity bodies are intentionally not
  searchable.

## Background jobs and deduplication

A single hourly `TimedJob` (`lib/BackgroundJob/DueStepNotificationJob.php`)
sends the due-tomorrow and overdue notifications through
`DueNotificationService`:

- it scans active runs only, for pending or in-progress assigned steps with a
  due date, in bounded batches (a global window with a fixed result limit);
- "tomorrow" and "today" are evaluated per recipient in the recipient's
  Nextcloud timezone, so a group-assigned step may notify members on different
  local days; the server timezone is the fallback;
- completed, skipped and cancelled steps and cancelled runs never produce due or
  overdue notifications;
- missing or deleted users are skipped safely and failures are logged without
  aborting the batch.

Deduplication is persistent and shared by all triggers. A dedicated ledger table
(`runbook_notification_deliveries`, added by migration
`Version0006Date20260601000000`) stores a stable dedupe key per notification
type, recipient and object, together with a delivery state. Event-driven
notifications (assignment, sharing, mention) and background notifications all
use the same ledger, so repeated saves, comment edits or job runs never deliver
the same notification twice.

Each ledger row moves through `pending` → `sent` on success and
`pending` → `failed` on a transient failure:

- a `sent` row is terminal and suppresses all future attempts;
- a `failed` row remains retryable by a later trigger or job run, up to a fixed
  attempt limit, so a transient Nextcloud notification failure is not lost
  permanently;
- a `pending` row is claimed atomically before delivery, so concurrent workers
  cannot both send the same notification, and a stale `pending` row (from a
  crashed worker) becomes retryable after a short window;
- once the attempt limit is reached the row is treated as terminal, which bounds
  duplicate notifications.

## Administration settings

Administrators configure Runbook under **Settings → Administration → Runbook**.
The form is served by `lib/Settings/Admin.php` (registered through `info.xml`)
inside a dedicated `Runbook` section, and all values are read and written
through an admin-only API (`GET`/`PUT /apps/runbook/api/v1/admin/settings`).
Settings are validated server-side and stored with the typed Nextcloud app
configuration API (`OCP\IAppConfig`); there is no custom settings table. Missing
values fall back to the defaults listed below.

| Setting | Default | Description |
| ------- | ------- | ----------- |
| `template_creation_policy` | `everyone` | Who may create templates: `everyone`, `selected_groups` or `admins`. |
| `template_creator_groups` | `[]` | Group IDs allowed to create templates when the policy is `selected_groups`. Every group is validated against Nextcloud; groups are never created or modified. |
| `comments_enabled` | `true` | When disabled, comments cannot be created, edited or deleted. Existing comments stay readable and no mention notifications are produced. |
| `step_reopen_enabled` | `true` | When disabled, completed or skipped steps cannot be reopened. |
| `require_skip_reason` | `true` | When enabled, skipping a step requires a non-empty reason; otherwise the reason is optional and stored as `null`. |
| `run_reopen_enabled` | `true` | When disabled, completed runs cannot be reopened. |
| `max_attachment_size` | `26214400` (25 MiB) | Maximum evidence upload size in bytes, enforced in addition to the MIME allowlist. Bounds: 1 KiB to 1 GiB. |
| `dashboard_enabled` | `true` | When disabled, the Dashboard widget returns no content. |
| `notifications_enabled` | `true` | When disabled, event and due/overdue notifications are not delivered. |
| `search_enabled` | `true` | When disabled, Unified Search returns no Runbook results. |
| `retention_days` | `0` | Retention period in days (`0` = keep forever). **Configuration only:** Runbook does not delete runs automatically yet; enforcement belongs to a future milestone. |

Notes:

- Only administrators may read or modify these settings; the endpoints are
  protected by the Nextcloud admin-only controller default and CSRF protection.
- The template creation policy is always enforced server-side in
  `TemplateService`; the frontend only hides controls and never grants access.
- Feature toggles are enforced server-side in the affected services
  (`CommentService`, `RunStepService`, `RunService`, `AttachmentService`,
  `NotificationService`, `DueNotificationService`, the Dashboard widget and the
  Unified Search provider). Disabling notifications does not delete existing
  notifications or notification-ledger rows.
- The main application reads a small read-only feature endpoint
  (`GET /apps/runbook/api/v1/features`) to reflect globally disabled features in
  its UI. It is never used for authorization.

## Frontend quality checks

```bash
npm run lint        # ESLint (Nextcloud configuration)
npm run typecheck   # vue-tsc type checking
```

## PHP quality checks

```bash
composer lint:php:syntax   # php -l over lib/, tests/ and build/
composer lint:php          # php-cs-fixer dry run (Nextcloud coding standard)
composer phpstan           # static analysis (level 8)
composer test              # PHPUnit
composer test:migration    # migration/schema tests
composer test:security     # authorization, hardening and notification tests
composer validate:xml      # appinfo/info.xml validation
composer validate:json     # JSON and translation validation
composer package:check     # release/packaging validation (run after a build)
```

Fix coding style issues automatically with:

```bash
composer lint:php:fix
```

## Translations

All user-facing strings are translated with `@nextcloud/l10n` in the frontend
and with `$l->t()` in PHP. Source strings are English and are prepared for the
Nextcloud translation workflow:

- `l10n/en.json` and `l10n/en.js` are the English source translation files.
- `l10n/pt_PT.json` and `l10n/pt_PT.js` provide European Portuguese.
- Error `reason` codes returned by the API are mapped to translated messages in
  the frontend (`src/utils/apiError.ts`); notification subjects, Dashboard
  labels, search labels, administration settings labels and activity labels are
  all translated.
- `.l10nignore` lists paths that the translation tool must not scan (build
  output and dependencies).

Translation workflow:

1. Add the new string with `t('runbook', 'Text')` in Vue or `$l->t('Text')` in
   PHP. Never hardcode user-facing text.
2. Add the English source string to `l10n/en.json` and `l10n/en.js` (keys and
   values match the source string).
3. Translate the string into every other locale, keeping the same keys and the
   same `{placeholder}` tokens. Plural forms are declared per locale
   (`nplurals=2; plural=(n != 1);` for English and Portuguese).
4. Validate the translation files:

   ```bash
   composer validate:json
   ```

   The command checks JSON validity, key parity with `en.json` and placeholder
   parity, and fails on mismatches.

## Backend

- `lib/AppInfo/Application.php` — app bootstrap and middleware registration.
- `lib/Controller/PageController.php` — renders the main app page.
- `lib/Controller/TemplateController.php` — template endpoints.
- `lib/Controller/SectionController.php` — section endpoints.
- `lib/Controller/StepController.php` — step endpoints.
- `lib/Controller/AclController.php` — template ACL endpoints.
- `lib/Controller/PrincipalController.php` — user and group search.
- `lib/Controller/RunController.php` — run lifecycle endpoints.
- `lib/Controller/RunStepController.php` — run step execution endpoints.
- `lib/Controller/RunAclController.php` — run participant list endpoints.
- `lib/Controller/WorkController.php` — My Work and overview endpoints.
- `lib/Controller/CommentController.php` — run and step comment endpoints.
- `lib/Controller/AttachmentController.php` — evidence upload, download and
  deletion endpoints.
- `lib/Controller/ActivityController.php` — run activity history endpoint.
- `lib/Service/TemplateService.php` — authorization, validation, versioning and
  ordering rules.
- `lib/Service/PermissionService.php` — effective template role resolution.
- `lib/Service/RunAccessService.php` — effective run role and assignment access.
- `lib/Service/AclService.php` — template ACL validation and atomic replacement.
- `lib/Service/RunAclService.php` — run ACL validation and atomic replacement.
- `lib/Service/PrincipalValidator.php` — user and group principal validation.
- `lib/Service/RunService.php` — run lifecycle, snapshot, due dates and progress.
- `lib/Service/RunStepService.php` — step execution, assignment and transitions.
- `lib/Service/WorkService.php` — My Work and overview aggregation.
- `lib/Service/CommentService.php` — run and step comment rules.
- `lib/Service/MentionService.php` — `@userid` parsing and storage.
- `lib/Service/AttachmentService.php` — evidence upload, download and deletion.
- `lib/Service/EvidenceStorage.php` — AppData-backed evidence file storage.
- `lib/Service/ActivityService.php` — append-only activity recording and listing.
- `lib/Service/NotificationService.php` — notification delivery and deduplication.
- `lib/Service/DueNotificationService.php` — bounded due/overdue scanning.
- `lib/Service/SearchService.php` — access-aware template and run search.
- `lib/Service/AdminSettings.php` — typed administration settings, defaults and
  validation.
- `lib/Service/TemplateCreationPolicyService.php` — server-side template creation
  policy.
- `lib/Service/StepResponseValidator.php` — typed response validation.
- `lib/Service/TransactionRunner.php` — small transaction helper.
- `lib/Controller/AdminSettingsController.php` — admin-only settings API and the
  read-only feature endpoint.
- `lib/Notification/Notifier.php` — translated notification subjects and links.
- `lib/Dashboard/Widget.php` — Dashboard widget.
- `lib/Search/Provider.php` — Unified Search provider.
- `lib/BackgroundJob/DueStepNotificationJob.php` — hourly due/overdue job.
- `lib/Settings/Admin.php` — administration settings form.
- `lib/Settings/Section.php` — administration settings section.
- `lib/Db/*` — entities and mappers.
- `lib/Middleware/ExceptionMiddleware.php` — maps domain exceptions to JSON.

Notifications, the Dashboard widget, the Unified Search provider and the
background job are registered through the public Nextcloud bootstrap
registration API in `Application::register()` / `Application::boot()`. The
administration settings are registered through `appinfo/info.xml` with the
public Nextcloud Settings API.

Milestone 7 does not add a database migration; all settings are stored through
the typed Nextcloud app configuration API.

## Known limitations

- The retention period is stored and validated but **not** enforced: Runbook does
  not delete runs or templates automatically yet. Enforcement belongs to a future
  milestone.
- Notification delivery depends on the Nextcloud notification and background job
  infrastructure; with AJAX cron the due/overdue job only runs while users are
  active.
- Due/overdue timing uses the recipient's Nextcloud timezone and falls back to
  the server timezone for users without a configured timezone. The overdue scan
  only looks back a bounded number of days.
- Evidence uploads use a configurable size limit (default 25 MiB) and a fixed
  MIME allowlist; the allowlist is not yet configurable.
- Comments are plain text (no rich formatting); mentions notify once and do not
  send follow-up notifications.
- The activity history is not surfaced through unified search.
- `FILE` step responses remain unsupported (a clear error is returned); evidence
  is stored as step attachments instead of a step response value.
- The template and run detail endpoints load steps per section (no batching yet).
- Reordering and deleting update rows sequentially without an explicit database
  transaction.
- The ACL editor displays principal identifiers for stored entries; display
  names are shown while searching.
- Oracle is not a declared database (see above).
- There is no live Nextcloud integration test environment in this repository:
  Nextcloud services are exercised through the public OCP interfaces, stubs and
  test doubles, and migrations through an in-memory schema wrapper. Real
  database execution and end-to-end Nextcloud integration remain out of scope
  here.

## Release

- **Version:** 0.1.0 (`appinfo/info.xml`, `package.json`).
- **Nextcloud:** 33.
- **PHP:** 8.2 – 8.5.
- **Databases:** MySQL/MariaDB, PostgreSQL and SQLite.
- **License:** AGPL-3.0-or-later.

### Build and test

```bash
npm ci
npm run lint
npm run typecheck
npm run build

composer install --prefer-dist --no-interaction
composer lint:php:syntax
composer lint:php
composer phpstan
composer test
composer test:migration
composer test:security
composer validate:xml
composer validate:json
```

`npm run build` emits the compiled assets (`js/runbook-main.mjs`,
`js/runbook-admin-settings.mjs`, the shared chunk and `css/runbook-main.css`).
Source maps are intentionally disabled for release builds.

### Package creation

```bash
npm ci && npm run build
php build/create-package.php
```

`build/create-package.php` writes a staging tree with runtime files only and
creates `runbook-0.1.0.tar.gz` (archive root `runbook/`). It validates the
staging tree with `build/validate-package.php` before archiving. Validate an
existing tree at any time with `composer package:check`.

The package excludes `node_modules/`, `vendor/`, `tests/`, `.github/`, `.git/`,
`build/`, the TypeScript/Vue sources, lock files, source maps, caches, logs,
secrets and editor settings.

### Live Nextcloud verification

Live verification was completed against a disposable Docker environment:

- image `nextcloud:33-apache` (Nextcloud 33.0.9, PHP 8.4, SQLite);
- the packaged app (not the development tree) was installed into
  `custom_apps/runbook`;
- enabling the app applied all six migrations and created all thirteen tables;
- the main page and the administration settings page loaded and their compiled
  assets were served (HTTP 200);
- the app appeared in the Nextcloud navigation;
- disable and re-enable succeeded without errors; `occ app:remove` disabled and
  removed the app files (Nextcloud retains the app tables, which is the default
  behavior);
- a full end-to-end acceptance scenario with one administrator, two normal
  users and one group passed all 48 checks.

Live testing found and fixed two release-blocking defects that unit tests could
not detect: the run `template_version` snapshot was omitted from the INSERT
because it equalled the entity default, and notification links were relative
whereas Nextcloud 33 requires absolute links. Both are fixed and covered by
regression tests.

Limitation: only SQLite was exercised live in this environment; MySQL and
PostgreSQL are declared and covered by the portable schema/migration tests but
were not run against live database servers here.

### Retention

The `retention_days` administration setting is stored and validated only.
Runbook does **not** delete runs or templates automatically; retention
enforcement belongs to a future release.

## Continuous integration

`.github/workflows/ci.yml` runs four deterministic jobs:

- **Frontend (Node 22):** `npm ci`, `npm run lint`, `npm run typecheck`,
  `npm run build`.
- **PHP (8.2, 8.3, 8.4, 8.5):** `composer install --prefer-dist`, PHP syntax
  lint, coding style, PHPStan level 8 and the full PHPUnit suite.
- **Migration and security tests:** `composer validate`, XML validation, JSON
  and translation validation, migration/schema tests and the security-focused
  regression tests.
- **Packaging validation:** installs `npm ci`, builds the frontend, installs
  Composer dependencies and runs `composer package:check`.

## License

AGPL-3.0-or-later. See `LICENSE` and the app metadata in `appinfo/info.xml`.
