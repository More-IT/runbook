# Runbook

Create, execute and track repeatable procedures as structured, collaborative runbooks.

This repository contains the Runbook Nextcloud app: app metadata, a Vue 3 +
TypeScript frontend, a PHP backend built on public Nextcloud APIs, and the
developer tooling needed to build, lint, analyse and test the code base.

It includes the feature milestones **0–7** (foundation, templates, access
control, runs/execution, work/assignments/due dates, collaboration/evidence/
activity, Nextcloud integrations and administration settings), the production
fix releases **0.1.1**, **0.2.0** and **0.3.0**, and the **0.4.0 UX redesign and
template import/export** work (issues #26–#33).

> The numbered **"Milestone N"** labels used below are feature milestones and are
> **not** the release version. The application version is **0.4.0**
> (`appinfo/info.xml`) as a **release candidate packaged for manual acceptance**:
> it is **not** a published, production-verified release and has not been uploaded
> or deployed anywhere. The 0.4.0 UX and import/export work is code-complete and
> awaits the real-Nextcloud acceptance run.

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

Milestone 0.1.1 — Production fixes:

- Unassigned steps are assigned to the user who starts the run, so every step
  has an owner and appears in "My Work".
- The Dashboard widget reuses the exact "My Work" data and shows the correct
  empty state only when there is genuinely no assigned work.
- Boolean responses are shown as translated human-readable values instead of
  `true`/`false`.
- Configured `NUMBER` units are shown in the template editor, run step card,
  response field and submitted response, and survive snapshotting.
- The navigation logo stays visible on dark themes.
- Opening a "My Work" item navigates to and highlights the matching step.
- The activity timeline can be switched between newest-first and oldest-first.

Milestone 0.2.0 — UX and collaboration:

- Section notes authored on templates and carried into every run snapshot; the
  run owner can update a run section's notes while the run is active and the
  change is recorded in the activity history.
- Fully resolved sections (all steps completed or skipped) collapse by default,
  with an accessible expand/collapse control; sections with pending work stay
  expanded and a step opened from "My Work" expands its section.
- Theme-safe step status accents (completed, pending, in progress, skipped and
  reopened) built from Nextcloud theme variables.
- The activity panel can be collapsed and shows richer, non-sensitive detail
  (actor, timestamp, affected section/step and, where safe, previous/new
  values) while keeping file contents and storage paths out of the log.

Milestone 0.3.0 — Process flows and dependencies:

- Archived templates can be unarchived by the owner or a Nextcloud
  administrator. The template returns to the active listing (published when it
  had been published before, otherwise draft) without touching its sections,
  steps, versions or ACL.
- Templates can be duplicated (owner or Nextcloud administrator only) into an
  independent draft owned by the user who duplicated them. Sections, steps and
  their configuration are copied; runs, activity, comments, evidence and the
  source ACL are never copied.
- Runs can be permanently deleted by the owner or a Nextcloud administrator in
  any state. Deletion removes the run, its sections, steps, ACL, activity,
  comments, mentions and notification ledger rows, and deletes the stored
  evidence files first so no orphaned files remain.

Milestone 0.4.0 — UX redesign and template import/export (issues #26–#33):

- A redesigned execution page and template editor on a shared design-token
  system: flow-status badges, section navigator/outline, authoring rule
  summaries, progress language and accessible reason lines.
- App-shell navigation with hash deep links (`#/run/{runId}/step/{stepId}`,
  `#/run/{runId}`, `#/template/{templateId}`, `#{section}`), Back/Forward
  handling and a shared unsaved-changes guard.
- Portable `schemaVersion` 1 template export (`GET .../templates/{id}/export`)
  and import (`POST .../templates/import`), with authoring-rule validation,
  transaction atomicity, a canonical 2 MB server limit and a 32 MB browser
  source-file guard (see "Template export" and "Template import" below).
- This milestone is **packaged as the 0.4.0 release candidate for manual
  acceptance**; it is not a published or production-verified release. The
  acceptance checklist is
  [`docs/manual-acceptance-checklist.md`](docs/manual-acceptance-checklist.md).

> The interactive process-flow engine (issues #15–#21) is **implemented**:
> section dependencies, parallel sections, response conditions, alternative
> paths, controlled returns and flow completion rules. See "Process flows"
> below.

> Template **export and import are implemented and hardened** (see "Template
> export" and "Template import" below). The **0.4.0 release candidate** is
> packaged for manual acceptance; the real-Nextcloud/browser acceptance checklist
> is in
> [`docs/manual-acceptance-checklist.md`](docs/manual-acceptance-checklist.md).
> Automated checks and package validation are not a substitute for that manual
> acceptance. Template replacement and starting a run during import are **not**
> implemented. Recurring runs, conditional steps, calendar, Talk, webhooks, API
> tokens, automation and AI are **not** implemented either.

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
- Steps without a configured assignee are assigned to the user who starts the
  run. A configured assignee is always preserved.
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
docs/               UX architecture and manual acceptance checklist
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
tests/              PHPUnit and Node frontend tests (tests/fixtures holds shared fixtures)
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

Sections and steps are linked with foreign keys using `ON DELETE CASCADE`, so
deleting a template or section also deletes its children. Indexes are added for
the template owner, the template status, the section template id and the step
section id.

Milestone 2 adds the template access list:

- `oc_runbook_template_acl`

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
- Each schema change adds one ordered, additive migration
  (`Version0001…` through `Version0008…`; the numbers follow the migration
  sequence and do not always match a feature-milestone number). Migration names
  and versions are ordered and never rewritten after release.
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
| `POST` | `/apps/runbook/api/v1/templates/import` | Import a template export as a new draft |
| `GET` | `/apps/runbook/api/v1/templates/{id}` | Template with sections and steps |
| `PATCH` | `/apps/runbook/api/v1/templates/{id}` | Update template metadata |
| `DELETE` | `/apps/runbook/api/v1/templates/{id}` | Delete a template |
| `POST` | `/apps/runbook/api/v1/templates/{id}/publish` | Publish a draft |
| `POST` | `/apps/runbook/api/v1/templates/{id}/archive` | Archive a template |
| `POST` | `/apps/runbook/api/v1/templates/{id}/unarchive` | Restore an archived template |
| `POST` | `/apps/runbook/api/v1/templates/{id}/duplicate` | Duplicate a template into a new draft |
| `GET` | `/apps/runbook/api/v1/templates/{id}/export` | Export a template as portable JSON |
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
| `DELETE` | `/apps/runbook/api/v1/runs/{id}` | Delete a run and its data (owner or administrator) |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/start` | Start a pending step |
| `PATCH` | `/apps/runbook/api/v1/run-steps/{id}` | Store a step response and/or update its assignment |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/complete` | Complete a step |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/skip` | Skip a step with a reason |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/reopen` | Reopen a completed or skipped step |
| `POST` | `/apps/runbook/api/v1/run-steps/{id}/return` | Return a step for correction with a reason |
| `PATCH` | `/apps/runbook/api/v1/run-sections/{id}` | Update a run section's notes (owner, active run) |
| `POST` | `/apps/runbook/api/v1/run-sections/{id}/return` | Return a whole section for correction with a reason |
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
| `DELETE` | `/apps/runbook/api/v1/attachments/{id}` | Delete evidence (uploader or owner; not the last file of a completed required FILE step) |
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
- Imported template documents are treated as untrusted: only the documented
  schemaVersion 1 fields are read (unknown fields are ignored), references,
  operators, values, conditions and assignees are validated through the same
  authoring rules, and the transaction is rolled back on any failure. Imports
  never transfer database ids, UUIDs, owner, status, ACL entries or run/file
  data, and the new template is owned by the importing user.
- The import size limit (canonical 2 MB) is enforced server-side; the browser's
  32 MB source-file guard is only a local memory cap and is never an
  authorization or acceptance decision.
- The notification ledger and evidence storage never expose internal storage
  keys or paths through the API.

## Authorization matrix

| Resource | Read | Mutate |
| -------- | ---- | ------ |
| Templates | Owner and any ACL role (VIEWER, EXECUTOR, EDITOR, OWNER) | `EDITOR`/`OWNER` edit content; only `OWNER` publishes, archives, deletes and manages the template ACL; creation is governed by the administration policy |
| Template export | Owner and any ACL role that grants view | n/a (read-only) |
| Template import | Any authenticated user permitted by the template-creation policy (the policy applies to import exactly as to creating a template) | Creates a new `DRAFT` owned by the importer; never modifies an existing template |
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
reopening, notifications, Dashboard, search) are enforced server-side. Template
unarchive and duplicate are available to the template owner or a Nextcloud
administrator; they are not ACL roles and are not shown in the matrix above.

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
description, section titles, descriptions, notes and order and step titles,
types, required flags, configuration (including the configured `NUMBER` unit)
and order. The copied data is authoritative for execution:

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
| `FILE`         | no response value: a non-null value is rejected. A `FILE` step is resolved through evidence attachments |

Required steps must have a valid response before completion and may only be
skipped with a non-empty reason. A skipped required step counts as resolved for
run completion but stays visibly marked as skipped. Run progress is calculated
from the steps (`PENDING` and `IN_PROGRESS` count as pending); it is never
persisted.

`FILE` steps never store a response value, file contents or storage paths. A
**required** `FILE` step can only be completed once at least one persisted
evidence attachment belongs to that exact step and run (verified server-side), so
a failed or in-progress upload never counts; an optional `FILE` step may be
completed without evidence. The last attachment of a completed required `FILE`
step cannot be deleted until it is replaced (see "Evidence attachments").

## Progress

Progress is derived from the run's step snapshot and is never persisted.

- `COMPLETED` and `SKIPPED` both count as *resolved*; skipped steps are never
  counted as pending, so a run whose steps were all completed or skipped is
  100 % resolved.
- `PENDING` and `IN_PROGRESS` count as pending. A run with no steps is treated
  as fully resolved (100 %).
- A run may only be completed while it is active and no required step is still
  pending.
- The run detail shows the percentage, the resolved/total ratio and an explicit
  completed / skipped / pending breakdown. The breakdown is textual as well as
  colour-coded, so status is never conveyed by colour alone.
- "My Work" and the overview use the same rule: skipped steps appear under the
  *completed* filter and are never listed as active work, and the Dashboard
  widget's active/overdue/due-today counts are built from the same
  pending/in-progress definition.

The storage format of a response is always the raw value (`true`/`false` for
booleans, a number for `NUMBER`, a string for the other types), but the user
interface never exposes raw booleans. `CONFIRMATION` responses render as
"Confirmed"/"Not confirmed" and `CHECK` responses as "Yes"/"No". `NUMBER`
responses render with their configured unit when one exists (for example
`15 minutes` or `80 %`); without a unit the plain value is shown. Units are set
in the template step editor and copied into the run snapshot.

## My Work and overview

`GET /api/v1/my-work` returns steps assigned directly to the current user or to
one of their groups. Steps that were unassigned in the template and assigned to
the starter when the run began are included as well. The `filter` query
parameter accepts:

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
- Uploads use the configurable evidence size limit (default 25 MiB,
  `AdminSettings::DEFAULT_MAX_ATTACHMENT_SIZE = 26214400` bytes). The MIME type
  is detected from the file content (client-provided types are ignored) against a
  conservative allowlist (`image/png`, `image/jpeg`, `image/gif`, `image/webp`,
  `application/pdf`, `text/plain`, `text/csv`, `application/json`,
  `application/zip`).
- A `sha256` checksum, size, sanitized filename and uploader are stored. File
  names may not contain path separators, `..` or control characters and are
  limited to 255 characters.
- The uploader and the run owner may delete evidence while the run is active.
  Deleting metadata removes the stored file; a failed metadata write never leaves
  an orphan file behind. The last attachment of a **completed required `FILE`
  step** cannot be deleted: upload a replacement first, then delete the old file.
- Downloads require read access to the run.
- `FILE` steps are resolved entirely through evidence; they never store a
  response value, file contents or storage paths. A **required** `FILE` step can
  only be completed once at least one persisted attachment belongs to that exact
  step and run (verified server-side from stored metadata, so an in-progress or
  failed upload never counts). Completing it without evidence is rejected with a
  clear error; an optional `FILE` step may be completed without evidence. After a
  successful upload the step's evidence list refreshes automatically and
  completion becomes available without a manual reload.

## Activity history

Activity events are append-only; there is no API to update or delete them. Events
record the actor, timestamp, optional step and a small metadata payload. Storage
paths, file contents and comment bodies are never persisted in activity rows.

Recorded events include run start/completion/cancellation/reopen, run participant
changes, section notes updates, step assignment changes (including the automatic
assignment of unassigned steps when a run starts), step start/response/complete/
skip/reopen, comment add/edit/delete and evidence upload/delete. The run activity
endpoint requires read access to the run and returns at most 200 events (default
100). The `order` query parameter accepts `desc` (newest first, the default) or
`asc` (oldest first); the run detail view exposes a control to switch between
them and to collapse the panel. The panel renders the actor and timestamp for
every event and, where it was recorded safely, the affected section/step and
previous/new values; free-text note bodies, response contents, file contents and
storage paths are never stored in activity rows.

## Step status colors

Step status is reinforced with a theme-safe accent on the left edge of each run
step card and on its status label:

| Status | Color family |
| ------ | ------------ |
| Completed | green (`--color-success-element`) |
| Pending / not started | blue (`--color-info-element`) |
| In progress | red (`--color-error-element`) |
| Skipped | dark yellow (`--color-warning-element`) |
| Reopened and actionable | orange (mix of the warning and error element colors) |

The colors are derived from the Nextcloud theme variables, so they adapt to the
light, dark and high-contrast themes instead of being hard-coded. No separate
color configuration is offered: the theming app already provides the accessible
palette, and a second color source would risk breaking contrast and dark theme
support. Administrators who need different colors can change the instance
accent/theme through Nextcloud's own theming settings.

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
shown. It uses the same effective data and filtering (the `all` filter of
`WorkService`) as "My Work", so a real run/step pair and its actual due date are
shown whenever pending work exists. The translated "No assigned work right
now." empty state is rendered only when there are no assigned pending steps; it
is never shown while work exists.

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

## User interface architecture

The visual system and UX architecture for the execution view and template
editor is documented in [`docs/ux-architecture.md`](docs/ux-architecture.md).
It defines the shared page shell, information hierarchy, flow-state pattern and
copy rules, progress language, authoring rule summaries, responsive and
accessibility behavior, and the responsibilities of issues #27–#33.

Design tokens live in `src/styles/tokens.css` and layer on the Nextcloud theme
variables (spacing, content widths, surfaces, state accents, focus and target
sizes). Reusable pieces:

- `src/components/FlowStatusBadge.vue` — state pill (label plus tone);
- `src/components/SectionStatus.vue` — section state label and explanation lines;
- `src/components/SectionNavigator.vue` — execution-page section navigator and
  narrow-screen section picker;
- `src/components/SectionOutline.vue` — template-editor section outline and
  narrow-screen section selector;
- `src/utils/runExecution.ts` — execution-page helpers: section progress,
  display-only progress categories, default selection, deep-link resolution and
  next actionable steps;
- `src/utils/runNavigation.ts` — one-shot deep-link navigation state for the
  execution page;
- `src/utils/templateAuthoring.ts` — template-editor helpers: section outline,
  focus/selection rules, condition draft validation and unsaved-draft
  comparison;
- `src/utils/editorDrafts.ts` — template-editor draft/discard state transitions
  (scoped discard confirmations and step-draft save results);
- `src/utils/navigationGuard.ts` — app-shell hash parsing and the shared
  unsaved-changes decision;
- `src/utils/historyStack.ts` — pure browser-history model (entries + index);
- `src/utils/navigationController.ts` — request/confirm/cancel reducer for the
  app-shell navigation guard;
- `src/utils/navigationHistory.ts` — combines the guard and the history model
  into the entry/index transitions the app shell performs;
- `src/utils/sectionFlow.ts` — pure helpers for the section status explanation
  and the authoring rule summary;
- `src/utils/sectionReason.ts` — reason fragments rendered as separate lines.

Flow state always comes from the backend (`state`, `blockedBy`, `reason[]`); the
frontend only formats it and never derives flow decisions.

## Frontend quality checks

```bash
npm run lint           # ESLint (Nextcloud configuration)
npm run typecheck      # vue-tsc type checking
npm run test:frontend  # Node test runner: section state/reason rendering (EN/PT-PT/PT-BR)
```

`npm run test:frontend` runs the dependency-free frontend tests for the section
flow UI. They load the real `@nextcloud/l10n` translator with the shipped
catalogs and assert the exact rendered status/reason strings, including step
titles containing quotes, apostrophes, ampersands and HTML-like text.

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
- `l10n/pt_PT.json` and `l10n/pt_PT.js` are the **canonical Portuguese source**.
- `l10n/pt_BR.json` and `l10n/pt_BR.js` provide Brazilian Portuguese and
  currently use **exactly the same values as `pt_PT`, character for character**.
  `pt_BR` should only diverge through an intentional, accepted translation
  update (for example via the official Nextcloud translation platform). The app
  always resolves the catalog matching the user's Nextcloud locale (`pt_PT` or
  `pt_BR`); it never falls back from `pt_BR` to `pt_PT`, so both catalogs must
  exist.
- Error `reason` codes returned by the API are mapped to translated messages in
  the frontend (`src/utils/apiError.ts`); notification subjects, Dashboard
  labels, search labels, administration settings labels and activity labels are
  all translated.
- `.l10nignore` lists paths that the translation tool must not scan (build
  output and dependencies).

Translation workflow:

1. Add the new string with `t('runbook', 'Text')` in Vue or `$l->t('Text')` in
   PHP. Never hardcode user-facing text.
2. Add the English source string to `l10n/en.json`.
3. Translate the string into every other locale, keeping the same keys and the
   same `{placeholder}` tokens. Plural forms are declared per locale
   (`nplurals=2; plural=(n != 1);` for English and Portuguese). `pt_BR` must
   currently keep the same values as `pt_PT`.
4. Regenerate the JavaScript catalogs from the JSON sources with
   `composer l10n:generate`. Never hand-edit the `.js` files: the generator
   writes the canonical `OC.L10N.register("runbook", { ... }, pluralForm)`
   structure and repairs keys that leaked outside the `translations` object.
5. Validate the translation files:

   ```bash
   composer validate:json
   ```

   The command checks JSON validity, key parity with `en.json`, placeholder
   parity, that no translation keys exist outside the `translations` object,
   that the generated `.js` mirrors the `.json`, and that `pt_BR` mirrors
   `pt_PT`.

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

## Process flows

Issues #15–#21 add a process-flow model on top of the linear templates. Linear
templates (no dependencies, no conditions) keep their exact previous behaviour.

### Data model

Each template section and each run section may declare:

- `dependsOn`: a list of sibling section ids that must resolve before the
  section becomes available.
- `condition`: an optional `{ stepId, operator, value }` reference to a step in
  the same template.

Both columns are nullable and stored as portable JSON in
`runbook_template_sections.depends_on`, `runbook_template_sections.condition_config`
and their `runbook_run_sections` counterparts (migration
`Version0008Date20260801000000`, `lib/Migration/Version0008Date20260801000000.php`).
Start-run copies the configuration into the run snapshot, mapping template
section/step ids to the newly created run section/step ids; later template edits
never affect existing runs.

### Section states

Flow state is always derived at read time by `lib/Service/FlowService.php` and is
never persisted:

| State | Meaning |
| --- | --- |
| `inapplicable` | The section condition is false. |
| `blocked` | A prerequisite section is unresolved, or the controlling step is unanswered. `blockedBy` lists the offending section titles / step title. |
| `resolved` | Empty section, or every step is completed/skipped. |
| `active` | At least one step is in progress. |
| `available` | Otherwise; the section may be worked on. |

A dependency is satisfied when the prerequisite is `resolved` **or**
`inapplicable`. `inapplicable` steps count as skipped for progress and never
block completion. Blocked required steps still block completion until their
dependencies are satisfied. The run detail API returns `state` and `blockedBy`
per section; My Work, the overview and the Dashboard widget surface actionable
steps only from `available`/`active` sections.

### Conditions

A section may declare zero or more conditions. Every condition of a section
belongs to a single gate and they are combined with a logical **AND**: the
section applies only when *all* of its conditions hold. Alternative (OR)
branches are modelled explicitly as sibling sections with mutually exclusive
conditions, never by mixing conditions inside one section. A condition that
cannot be decided yet (its controlling step is unanswered) keeps the section
`blocked`; a decided-false condition makes it `inapplicable`. Evaluation never
depends on the section's position: every section, including the final one, runs
through the same algorithm.

A missing or empty response never satisfies a condition: it keeps the gate
pending, so `not_equals` cannot become true merely because a value is absent.
Numeric values are coerced and compared as floats with a tolerance for decimals.

`inapplicable` and `blocked` sections also expose a structured, read-only
`reason` alongside `blockedBy` (see the run detail API): the controlling step
title, the operator and the expected value — never the stored response. The run
detail UI renders it as plain text through Vue interpolation (never `v-html`),
with Unicode quotation marks around step titles and a ` — ` separator after the
status. HTML escaping is left entirely to Vue, so step titles containing quotes,
ampersands or HTML-like text are safe and are never shown as HTML entities.

Conditions reference a step in the template (possibly in another section) and
are validated against its type when the section is saved:

| Step type | Operators |
| --- | --- |
| `CHECK`, `CONFIRMATION` | `is_true`, `is_false` |
| `NUMBER` | `equals`, `not_equals`, `greater_than`, `less_than`, `greater_or_equal`, `less_or_equal` |
| `SELECT`, `TEXT`, `DATE`, `USER` | `equals`, `not_equals` |
| `FILE` | not configurable |

The operator map is defined once in `FlowService::operatorsByType()` and mirrored
by `CONDITION_OPERATORS_BY_TYPE` in `src/models/template.ts`; a unit test asserts
the two stay identical, so authoring validation, run-snapshot evaluation and the
frontend can never disagree.

Template validation (`lib/Service/TemplateService.php`) rejects unknown section
references, self-dependencies, dependency cycles (depth-first traversal),
conditions that reference a missing step, operators/values that are invalid for
the referenced step type, duplicate conditions inside one gate, contradictory
conditions inside one gate (e.g. `equals` with two different values, or
`is_true` together with `is_false`), and two sibling sections that share an
identical condition gate (ambiguous alternative paths). When a section or step is
deleted, references to it are stripped from the remaining sections in the same
save. A legacy single-condition payload (`condition`) is still accepted and
upgraded to a one-element list.

### Returns

`RunStepService::returnStep()` (owner or step assignee, active run, closed step)
and `RunService::returnSection()` (owner, active run) record a mandatory
`reason` (max length enforced) and reopen the affected steps to `PENDING`
without deleting responses, evidence or activity. Returning a step preserves
its stored response and records a `step_returned` activity; returning a section
reopens all completed/skipped steps in it and records a `section_returned`
activity. Endpoints: `POST /api/v1/run-steps/{id}/return` and
`POST /api/v1/run-sections/{id}/return`.

### Completion

A run may be completed only when every applicable required step is resolved.
Sections/steps that are blocked because of unsatisfied dependencies are not
counted as pending actionable work, but they still block completion once their
dependencies are satisfied. Detail, My Work, the overview and the Dashboard
widget share this definition.

## Template export

### Endpoint

`GET /api/v1/templates/{id}/export` returns a portable JSON document for a
template the current user may **view** (owner, or any direct/group ACL role that
grants view). Visible draft, published and archived templates are all
exportable. Unauthenticated or unauthorized requests are rejected by the
existing permission check (HTTP 403, reason `not_allowed`) without exposing any
template content. Export is strictly read-only: it never writes, never bumps the
template version, and never touches ACLs, runs or activity. The response body is
the document itself (no wrapper).

### How users export

In the Templates or Archived list, use **Export** on a template. The browser
downloads `<slug>.json`, where the slug is the title lowercased with accents
removed and every non-alphanumeric run collapsed to a single dash (an empty
result falls back to `runbook-template.json`). Export does not navigate away;
the action shows an in-progress state, prevents duplicate clicks and reports
failures inline. The temporary object URL is always revoked.

### Schema (`schemaVersion` 1)

```json
{
  "format": "runbook-template",
  "schemaVersion": 1,
  "template": { "title": "Deploy", "description": "Release procedure" },
  "sections": [
    { "ref": "s1", "title": "Start", "description": "", "notes": "kickoff",
      "dependsOn": [], "conditions": [] },
    { "ref": "s2", "title": "Production", "description": "", "notes": "",
      "dependsOn": ["s1"],
      "conditions": [
        { "stepRef": "t1", "operator": "equals", "value": "prod" },
        { "stepRef": "t2", "operator": "greater_than", "value": 5 }
      ] }
  ],
  "steps": [
    { "ref": "t1", "sectionRef": "s1", "title": "Environment", "description": "",
      "type": "SELECT", "required": true, "position": 0,
      "config": { "options": ["dev", "prod"] },
      "defaultAssignee": "principals/users/alice", "dueOffset": "60" }
  ]
}
```

### Field meanings

- `template.title` / `template.description` — the template metadata.
- `sections[].ref` — stable, document-local reference (`s1`, `s2`, …) assigned in
  section order; never a database id.
- `sections[].title` / `description` / `notes` — section text.
- `sections[].dependsOn` — prerequisite section refs; sorted by ref.
- `sections[].conditions` — the section gate: `{ stepRef, operator, value? }`
  entries combined with **AND**. `value` is present for typed operators and keeps
  its type (string, number or boolean). Alternative paths are separate
  conditional sections, so there is no OR operator.
- `steps[].ref` (`t1`, …), `sectionRef`, `title`, `description`, `type`
  (`CHECK`/`CONFIRMATION`/`TEXT`/`NUMBER`/`SELECT`/`DATE`/`USER`/`FILE`),
  `required`, `position`, `defaultAssignee`, `dueOffset`.
- `steps[].config` — the **complete persisted configuration object**, including
  every authored key for every step type: `SELECT` and `NUMBER` commonly contain
  `options` / `unit`, but any additional keys are exported too, with their values
  and array order preserved. An empty configuration serialises as `{}` (never
  `[]`), and a `NUMBER` step with no stored unit has no `unit` key (it is not
  invented as `""`).

Ordering is deterministic (sections and steps by position then id; dependencies
by ref), so repeated exports of unchanged data are equivalent. Zero-step
sections are included.

The document includes all user-authored content (titles, descriptions, section
notes and step instructions, and step configuration). Downloaded files therefore
contain the template's text and settings; handle and share them accordingly.

### Deliberately excluded

Database ids, UUIDs, owner, status, timestamps, ACL entries, runs and run
snapshots, responses, participants/assignments, activity, comments, evidence and
files, notifications and storage paths. The entity `toArray()` output is never
serialised wholesale.

### Portability caveat

`defaultAssignee` is a Nextcloud principal identifier (for example
`principals/users/alice`). It is **instance-specific**: on a different Nextcloud
the user or group may not exist. Import verifies every non-null assignee against
the local instance and rejects the whole file if one cannot be resolved (set it
to `null` in the JSON and retry).

## Template import

### Endpoint

`POST /api/v1/templates/import` accepts the export document itself (no wrapper)
as a JSON request body and creates **one new DRAFT template owned by the
requesting user**, returning `{ "template": { … } }` with HTTP 201. The static
`/templates/import` route is registered before `/templates/{id}`.

The importer runs the same creation policy as `POST /api/v1/templates`
(`TemplateService::createTemplate`), server-side: a user who may only view or
edit somebody else's template gains no import right when template creation is
disabled. Import never applies imported database ids, UUIDs, owner, status,
timestamps or ACL entries, and never copies runs, responses, activity, comments,
attachments or notifications. No existing template or run is modified.

The whole write sequence runs inside the existing `TransactionRunner`: a failure
at any stage rolls back so no template, section, step or flow-rule row is left
behind. Flow rules are applied in a second pass (template and sections first,
then steps, then dependencies/conditions), so document-local `s…`/`t…` refs are
mapped to new database ids through the normal authoring validation — there is no
second flow engine.

### How users import

In the Templates area (including the empty state) a user who may create templates
sees **Import template**. Choosing a local `.json` file parses it as UTF-8 JSON
and shows a confirmation summary (title, description, section and step counts,
file name) before anything is created, including a reminder that the result is a
new draft owned by the current user. Confirming creates the draft, refreshes the
list and opens it in the editor; the current template is never overwritten. The
action shows progress, blocks double submission, translates validation and
permission errors, and lets the same file be chosen again after a failure. The
selected file is read locally and only sent in the import request — it is never
uploaded or stored elsewhere.

While a request is in flight the confirmation dialog cannot be dismissed:
Cancel is disabled and Escape, the backdrop and the dialog close control are
suppressed, so the user never sees a "cancelled" import that still creates a
template. If the template is created but the list refresh or opening it in the
editor fails, the import is **not** reported as failed: the created draft is
preserved and offered with an "Open imported template" action, so retrying cannot
create a duplicate.

### Validation, reason codes and limits

The document must declare `format: "runbook-template"` and `schemaVersion: 1`.
Unsupported versions are rejected (never an uncaught 500). Every reference is
checked before writing: duplicate refs, a missing `sectionRef`/`stepRef`/
`dependsOn` ref, an unknown assignee, invalid condition operators/values,
dependency cycles and contradictory/duplicate conditions all abort the import.
A JSON `config` object and an empty array are equivalent (Nextcloud decodes a
JSON request body; `{}` and `[]` both arrive as an empty PHP array).

There are **two distinct limits**; only the second decides acceptance.

1. **Browser source-file safety cap: 32,000,000 bytes.** Purely a memory guard,
   applied to `File.size` before `file.text()`. It is not the import rule. Exports
   are pretty-printed (`formatTemplateExport`), so the downloaded file is larger
   than the compact body sent back on import; a maximal valid document (2,000,000
   canonical bytes) expands by roughly 10x for token-dense structures, so a
   realistic maximal export fits well within the cap. Pathologically deep
   configuration nesting can expand super-linearly when pretty-printed, so the cap
   is a guard, not a promise that every canonical-valid document is readable.
2. **Server canonical-document cap: 2,000,000 bytes — authoritative.** The server
   measures the canonical compact UTF-8 encoding of the decoded document
   (`JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`) and accepts or rejects on
   that alone. This is deliberately not a wire-byte limit: the Nextcloud
   `IRequest` API exposes no raw request body (`getParams()`/`getHeader()` only),
   so the actual body length and any client-declared `Content-Length` are neither
   available nor trusted, and the same rule applies to HTTP requests and direct
   service calls. The raw request size is bounded separately by PHP/Nextcloud
   request-size limits.

Because JavaScript and PHP serialise some values differently, the browser
**never** decides document size. Examples: JS `JSON.stringify` writes `1e+21`
where PHP writes `1.0e+21`, `0.000001` where PHP writes `1.0e-6`, and keeps
U+2028 literal where PHP escapes it to `\u2028`. A JavaScript byte estimate can
therefore be larger *or* smaller than the server's canonical size, so the UI must
not reject a document on that basis; the server's localized
`import_document_too_large` error is shown instead, the selected document is kept
for an explicit retry, and no partial template is ever created. Accented
Portuguese is counted as real UTF-8 (never default `json_encode()`, which escapes
`é` to `\u00e9` and over-counts), so valid accented documents are not rejected.

| Reason | Meaning |
| --- | --- |
| `invalid_import_format` | `format` is not `runbook-template`. |
| `unsupported_import_schema_version` | `schemaVersion` is not `1`. |
| `invalid_import_document` | Missing/incorrectly typed fields. |
| `duplicate_import_reference` | A `s…`/`t…` ref is used twice. |
| `invalid_import_reference` | A `sectionRef`, `stepRef` or `dependsOn` ref is unknown. |
| `invalid_import_assignee` | A non-null `defaultAssignee` does not exist locally. |
| `import_document_too_large` | Canonical compact UTF-8 encoding exceeds 2,000,000 bytes (server decision). |
| `import_too_many_sections` | More than 500 sections. |
| `import_too_many_steps` | More than 5,000 steps. |
| `import_too_many_dependencies` | More than 5,000 dependency edges. |
| `import_too_many_conditions` | More than 5,000 conditions. |
| `template_creation_forbidden` | Creation policy denies the user. |

Flow errors reuse the authoring reasons (`invalid_condition_operator`,
`invalid_condition_value`, `section_dependency_cycle`, …).

### Validation and security guarantees (#32)

- **Untrusted input is a 400, never a 500.** Documents that cannot be
  JSON-encoded for measurement — non-finite floats (the `INF` that
  `json_decode('1e400')` produces), malformed UTF-8, or nesting deeper than
  PHP's encoder limit — are reported as `invalid_import_document`. The
  collection limits are checked before the 2 MB canonical encoding is built.
- **Unknown fields are ignored, never applied.** Only `format`, `schemaVersion`,
  `template`, `sections` and `steps` (and the documented child fields) are read.
  Injected `id`, `uuid`, `owner`, `status`, `acl`, `permissions`, `runs`,
  `templateId` or `sectionId` fields have no effect; the new template always gets
  a fresh UUID, version 1, DRAFT status and the acting user as owner, and no ACL
  rows are created.
- **Atomic.** The whole write runs in one `TransactionRunner` transaction.
  Failures at any stage (metadata, section, step, assignee or flow-rule creation)
  roll back to no template, section, step or ACL rows. Template authoring does
  not emit activity or notification side effects, so nothing can escape the
  transaction.
- **Authorization unchanged.** Import runs the existing creation policy; export
  runs the existing view permission. Administrators get no implicit access:
  a Nextcloud admin who is neither owner nor on the ACL cannot export.
- **Data minimization.** Exports contain only authoring fields (titles,
  descriptions, notes, step type/required/position/configuration, assignees, due
  offsets and document-local references). No database ids, UUIDs, owner, status,
  ACLs, runs/responses, comments, evidence or file storage paths, activity or
  notifications are serialised.
- **Reference integrity.** Duplicate, missing, self-referential and cyclic
  references, invalid per-type operators/values, and duplicate or contradictory
  conditions are rejected before any row persists.

Residual limits (not fully eliminable with the installed APIs):

- The canonical 2 MB limit is measured on the decoded document, not the raw
  request body (`IRequest` exposes no raw body). A whitespace-padded body can be
  larger on the wire; the raw request size is bounded by PHP/Nextcloud
  `post_max_size`/request limits, which are not verified here.
- PHP's JSON decoder depth (default 512) applies while the framework parses the
  request body, before this service runs; requests exceeding it are handled by
  the framework layer and are not covered by these tests.
- No real Nextcloud/browser integration test exists in this repository (#33);
  the guarantees above are exercised through the public OCP interfaces, stubs and
  test doubles.

### Ordering rule

Section-array order **is** section order (sections are created in document
order, so their stored positions are contiguous). Within a section, steps are
ordered by their exported `position`, with document order as the stable
tie-breaker, and re-created with contiguous positions through the authoring
service. Zero-step sections are preserved, as are all eight step types,
instructions, required flags, full configuration objects (including additional
authored keys), due offsets and resolvable default assignees.

### Writing large templates by hand

Import makes a manually authored JSON file practical for large templates. Write
a single JSON object with the envelope keys `format`, `schemaVersion`,
`template`, `sections` and `steps`.

1. **Envelope** — `"format": "runbook-template"`, `"schemaVersion": 1`,
   `"template": { "title": "…", "description": "…" }`.
2. **Sections** — one entry per section in the order they should appear. `ref`
   is a document-local id you invent (`s1`, `s2`, …). Keep `sections[].ref`
   unique. `description`/`notes` are optional. A section with no steps is valid.
3. **Steps** — one flat list; each step names its section with `sectionRef`.
   `ref` is unique (`t1`, `t2`, …), `position` is the order inside its section
   (ties fall back to document order), and `type` is one of `CHECK`,
   `CONFIRMATION`, `TEXT`, `NUMBER`, `SELECT`, `DATE`, `USER`, `FILE`.
4. **Dependencies** — `sections[].dependsOn` is a list of other **section**
   refs; the engine rejects self-dependencies and cycles.
5. **Conditions (all must match)** — `sections[].conditions` is a list of
   `{ "stepRef": "t…", "operator": "…", "value": … }` gates combined with **AND**.
   The referenced step must belong to an earlier section. `value` keeps its JSON
   type (string, number, boolean) and is required for typed operators.
6. **Alternative paths** — an OR is expressed as two separate conditional
   sections with mutually exclusive conditions on the same controlling step
   (for example `equals "prod"` and `equals "dev"`); two sections may not share
   an identical condition.
7. **Configuration** — `steps[].config` is an object. `SELECT` needs
   `"options": ["a", "b"]`; `NUMBER` may have `"unit"`. Any additional authored
   keys are preserved.
8. **Assignees and due offsets** — `defaultAssignee` is a Nextcloud principal
   (`principals/users/alice` / `principals/groups/team`) or `null`;
   `dueOffset` is a non-negative number of minutes as a string, or `null`.

Common validation failures: a `sectionRef`/`stepRef`/`dependsOn` typo
(`invalid_import_reference`), a reused ref (`duplicate_import_reference`), a
condition on the same or a later section, a cycle, an operator that does not fit
the step type, or an assignee that does not exist locally (set it to `null`).

Worked example (two parallel branches, a zero-step section, AND conditions and a
typed number comparison):

```json
{
  "format": "runbook-template",
  "schemaVersion": 1,
  "template": { "title": "Deploy", "description": "Release procedure" },
  "sections": [
    { "ref": "s1", "title": "Config", "description": "", "notes": "",
      "dependsOn": [], "conditions": [] },
    { "ref": "s2", "title": "Manual notes", "description": "", "notes": "",
      "dependsOn": [], "conditions": [] },
    { "ref": "s3", "title": "Branch prod", "description": "", "notes": "",
      "dependsOn": ["s1"],
      "conditions": [ { "stepRef": "t1", "operator": "equals", "value": "prod" } ] },
    { "ref": "s4", "title": "Branch dev", "description": "", "notes": "",
      "dependsOn": ["s1"],
      "conditions": [ { "stepRef": "t1", "operator": "equals", "value": "dev" } ] },
    { "ref": "s5", "title": "Final", "description": "", "notes": "",
      "dependsOn": ["s1", "s3"],
      "conditions": [
        { "stepRef": "t1", "operator": "equals", "value": "prod" },
        { "stepRef": "t2", "operator": "greater_than", "value": 5 }
      ] }
  ],
  "steps": [
    { "ref": "t1", "sectionRef": "s1", "title": "Environment", "description": "",
      "type": "SELECT", "required": true, "position": 0,
      "config": { "options": ["dev", "prod"] },
      "defaultAssignee": "principals/users/alice", "dueOffset": null },
    { "ref": "t2", "sectionRef": "s1", "title": "Amount", "description": "",
      "type": "NUMBER", "required": false, "position": 1,
      "config": { "unit": "kg", "decimals": 2 },
      "defaultAssignee": null, "dueOffset": "60" }
  ]
}
```

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
- `FILE` step responses remain unsupported (non-null response values are
  rejected); evidence is stored as step attachments instead. A required `FILE`
  step can only be completed with at least one persisted attachment, and the last
  attachment of a completed required `FILE` step cannot be deleted until it is
  replaced.
- Template import limits the canonical encoding of the decoded document to 2 MB;
  the raw request body is not measured (Nextcloud/PHP request limits apply), and
  the 32 MB browser source-file cap is only a local memory guard.
- Template import/export has not been exercised on a real Nextcloud instance;
  see [`docs/manual-acceptance-checklist.md`](docs/manual-acceptance-checklist.md).
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

- **Version:** 0.4.0 release candidate (`appinfo/info.xml`, `package.json`).
- **Nextcloud:** 33.
- **PHP:** 8.2 – 8.5.
- **Databases:** MySQL/MariaDB, PostgreSQL and SQLite.
- **License:** AGPL-3.0-or-later.

The 0.4.0 UX and template import/export work (#26–#33) is packaged as a **release
candidate for manual acceptance**. It is not a published or production-verified
release: the archives have not been uploaded or deployed, and the real-Nextcloud
acceptance run is still outstanding. The v0.3.0 material below is retained as
historical release information.

### v0.3.0

Process flows for templates and runs: sections can depend on other sections and
carry typed conditions, several sections can run in parallel, alternative paths
are selected by conditions, and an owner or assignee can return a step or a
section for correction with a recorded reason. See "Process flows" above.

- **Data model:** nullable `depends_on` and `condition_config` columns on
  `runbook_template_sections` and `runbook_run_sections` (migration
  `Version0008Date20260801000000`); the flow is copied into the run snapshot at
  start.
- **Validation:** unknown references, self-dependencies, cycles, invalid
  operators/values and ambiguous sibling conditions are rejected on save.
- **Compatibility:** linear templates keep their exact previous behaviour.

### v0.1.1

Production fixes discovered during real use. This release is migration-free: no
database schema change and no new migration were introduced.

- **Automatic assignment:** steps without a configured assignee are assigned to
  the user who starts the run; configured assignees are preserved. The
  assignment is persisted in the run snapshot, appears in "My Work" and is
  recorded in the activity history.
- **Dashboard widget:** uses the same effective data and filtering as "My Work"
  and shows the real run/step information with the actual due date. The empty
  state ("No assigned work right now.") is shown only when there is no assigned
  pending work.
- **Confirmation responses:** boolean responses are rendered as translated,
  human-readable values ("Confirmed"/"Not confirmed", "Yes"/"No"); the API keeps
  storing booleans.
- **NUMBER step units:** the configured unit is shown in the step editor, run
  step card, response field and submitted response (for example `15 minutes`),
  and survives template snapshotting.
- **Theme-aware app icons:** Runbook follows the Nextcloud Forms/Tables/Deck
  convention. `app.svg` is the light (white) variant and is declared as
  `<icon>app.svg</icon>`: the main navigation and the installed-apps list then
  apply `background-invert-if-bright`, so the icon is white on the dark/coloured
  header and dark on a light one. `app-dark.svg` is the dark (black) variant
  used by Administration Settings and the Dashboard widget, which apply
  `background-invert-if-dark` to turn it white in dark themes. Both SVGs share
  the same geometry, declare an explicit `fill` on the root element and on every
  path, and contain no internal CSS, classes or Illustrator metadata. The former
  custom navigation icon was not a Nextcloud convention and was removed.
- **My Work deep-link focus:** opening an item from "My Work" navigates to the
  matching run and step, scrolls to it and applies an accessible highlight,
  including after a reload.
- **Activity ordering:** the activity timeline can be switched between
  newest-first (default) and oldest-first.

### Build and test

```bash
npm ci
npm run lint
npm run typecheck
npm run test:frontend
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
composer package:check
```

`npm run build` emits the compiled assets (`js/runbook-main.mjs`,
`js/runbook-admin-settings.mjs`, the shared chunk and `css/runbook-main.css`).
Source maps are intentionally disabled for release builds.

### Package creation

```bash
npm ci && npm run build
php build/create-package.php
```

`build/create-package.php` reads the version from `appinfo/info.xml`, writes a
staging tree with runtime files only and creates `runbook-<version>.tar.gz`
(archive root `runbook/`) — for example `runbook-0.4.0.tar.gz` for the current
0.4.0 release candidate. Before archiving it validates the staging tree with
`build/validate-package.php --package=<staging-directory>`, which also checks
that every relative link in the packaged `README.md` resolves inside the tree
(and does not escape it through `..` or an absolute path), including the `docs/`
files shipped with it.

`composer package:check` runs `build/validate-package.php` in **repository
mode**: it validates release *readiness* (required files, generated assets,
translation parity) against the working tree and never inspects a package. The
forbidden-file and packaged-link checks run only in package mode
(`--package=<staging-directory>`), which `build/create-package.php` performs.

The package excludes `node_modules/`, `vendor/`, `tests/`, `.github/`, `.git/`,
`build/`, the TypeScript/Vue sources, lock files, source maps, caches, logs,
secrets and editor settings.

### Live Nextcloud verification

Live verification was completed against a disposable Docker environment:

- image `nextcloud:33-apache` (Nextcloud 33.0.9, PHP 8.4, SQLite);
- the packaged app (not the development tree) was installed into
  `custom_apps/runbook`;
- enabling the app applied all migrations and created all thirteen tables;
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

The 0.3.0 verification above predates the milestone 0.4.0 work (#26–#33). The
0.4.0 import/export and UX integration is **code-complete but not yet verified on
a real instance**; run [`docs/manual-acceptance-checklist.md`](docs/manual-acceptance-checklist.md)
against the packaged build. No automated check in this repository claims that
manual acceptance passed.

### Retention

The `retention_days` administration setting is stored and validated only.
Runbook does **not** delete runs or templates automatically; retention
enforcement belongs to a future release.

## Continuous integration

`.github/workflows/ci.yml` runs four deterministic jobs:

- **Frontend (Node 22):** `npm ci`, `npm run lint`, `npm run typecheck`,
  `npm run test:frontend`, `npm run build`.
- **PHP (8.2, 8.3, 8.4, 8.5):** `composer install --prefer-dist`, PHP syntax
  lint, coding style, PHPStan level 8 and the full PHPUnit suite.
- **Migration and security tests:** `composer validate`, XML validation, JSON
  and translation validation, migration/schema tests and the security-focused
  regression tests.
- **Packaging validation:** installs `npm ci`, builds the frontend, installs
  Composer dependencies and runs `composer package:check`.

## License

AGPL-3.0-or-later. See `LICENSE` and the app metadata in `appinfo/info.xml`.
