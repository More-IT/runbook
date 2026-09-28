# Runbook release candidate — manual acceptance checklist

This checklist is for the **real-Nextcloud production acceptance test** of the
release candidate. It covers the 0.4.0 UX redesign and template import/export
(issues #26–#33) and the 0.5.0 Nextcloud Files integration (issues #45–#56). It
must be executed manually on a packaged build; **nothing here is marked as passed
by the automated suite**, and no item may be ticked without being observed.

The application version for this candidate is **0.5.0** (`appinfo/info.xml`,
`package.json`); the 0.5.0 Nextcloud Files integration is packaged as a release
candidate and is **not** production-verified. Verify in the package that
`appinfo/info.xml` and `package.json` report the intended version **before** this
checklist is run. If the packaged version differs from the intended candidate,
stop and rebuild the package.

Record for every run: source commit **or** a complete source manifest/diff with
hashes that covers untracked files, package checksum (SHA-256), packaged app
version, Nextcloud version, PHP version, database, browser and OS, and the date.

## 0. Setup

- [ ] Build the release archive from an **identified and reproducible source state**: either a specific commit hash, or a complete source manifest/diff with SHA-256 hashes that **includes untracked files** (`git status --short` alone is not sufficient, because it does not capture the content of untracked files). A clean tree is not required as long as the state is fully recorded and reproducibly rebuildable.
- [ ] Record the archive SHA-256 and the **packaged app version**; confirm it is the intended 0.5.0 release candidate.
- [ ] Install/enable `runbook` on the test instance and confirm the installed version matches the recorded package version.
- [ ] Run the database migrations and confirm no errors on an upgrade from a previous version.
- [ ] Seed at least one user, one group, and an admin account that is **not** a template owner.
- [ ] Confirm the app loads with no console errors before any test.
- [ ] **Cache refresh:** hard-reload the page (Ctrl/Cmd+Shift+R) after install/upgrade; confirm the rebuilt JS/CSS is served (no stale bundle).

## 1. Execution page (#26–#27)

- [ ] Blocked, Available, Active, Inapplicable and Resolved sections each show the correct status badge/tone.
- [ ] A zero-step section shows its real state and the "information only / no steps" copy, not "0/0 pending".
- [ ] Progress bar counts (completed, skipped, pending, inapplicable) match the section/step reality; no phantom pending or resolved steps.
- [ ] A blocked section lists dependency reasons and pending-answer reasons as separate, readable lines.
- [ ] Reason text is translated, not a literal key, and reads correctly.
- [ ] Keyboard/screen-reader users can reach the status badge and its explanation (accessible name/description).
- [ ] Multi-dependency section: stays blocked until every prerequisite resolves.
- [ ] Multi-condition gate (AND): stays blocked until **all** conditions match.
- [ ] Alternative branches: use a template with **mutually exclusive** conditions on the same controlling step (e.g. `equals "prod"` vs `equals "dev"`); exactly one branch becomes available and the other shows Inapplicable. Overlapping conditions need not produce exactly one available branch.
- [ ] Final section becomes Available once its branches resolve (including when one branch is Inapplicable).
- [ ] Reopen a completed step and a resolved section; the dependent gate re-evaluates.
- [ ] Skip a step; it counts as resolved, not pending, and does not block completion.
- [ ] Complete an optional step without a response; required steps still block completion.
- [ ] Next-action hint is correct for Active, Available, completed, cancelled and read-only runs.

## 2. Template editor and navigation (#28–#29)

- [ ] Section outline, rule summaries and step editing stay understandable with a large template (e.g. 50+ sections).
- [ ] Rule summary reads as a sentence and never implies OR; zero-step and unconditional sections are labelled.
- [ ] Edit metadata only, switch section → **no warning**, and the metadata draft remains intact (and is saved/reflected when you return to it). Only a mounted section or step draft may warn.
- [ ] Edit a section, open another step/draft, switch section → warning; Cancel keeps every draft.
- [ ] **Discard** clears only the intended scope (step draft vs section draft vs full editor).
- [ ] Publish, Start run, Archive and Delete each respect unsaved-change protection.
- [ ] Back/Forward across editor states preserves or discards drafts according to the answer given, exactly once per decision.
- [ ] Deep links use the actual route format `#/run/{runId}/step/{stepId}` (also `#/run/{runId}`, `#/template/{templateId}` and `#{section}`); they focus the intended section/step once, and repeated navigation and duplicate hashes behave.
- [ ] Rapid run/template switching commits only the newest response (no stale overwrite).
- [ ] **Keyboard:** tab order reaches every action; focus is visible; Escape closes dialogs. The import confirmation dialog must **not** close via Escape, Cancel, backdrop or the close control while an import request is in flight.
- [ ] **Light/dark:** text, borders, status colours and focus rings meet contrast in both themes.
- [ ] **Responsive:** desktop, tablet and mobile (section picker) layouts are usable; touch targets are large enough.

## 3. Import / export (#30–#32)

- [ ] Export a representative template (flow refs, AND conditions, zero-step section, notes, every step type); the file downloads and is readable JSON.
- [ ] Import that same file; a new DRAFT owned by the current user is created and opens in the editor.
- [ ] Re-export the imported template; content and flow semantics are equivalent (ids regenerated).
- [ ] A large near-limit export (pretty-printed file > 2 MB) imports successfully (server canonical limit is authoritative).
- [ ] A file over the 32 MB source cap is rejected **before** reading.
- [ ] A file below the 32 MB source-file cap whose canonical document exceeds the 2 MB server limit shows the localized size error (the server decides); the selected document is kept for an explicit retry and no template is created.
- [ ] Invalid files (malformed JSON, wrong format/schema, bad refs) show understandable localized errors, not raw codes.
- [ ] A failed import leaves no partial template in the list.
- [ ] A successful import whose list refresh fails does **not** report "import failed"; the created draft is offered for reopening.

## 4. Authorization

- [ ] Owner and each ACL role (Viewer/Executor/Editor) and group principal can export.
- [ ] An unrelated user cannot export (403, no content).
- [ ] A Nextcloud admin who is neither owner nor on the ACL cannot export.
- [ ] Import respects the template-creation policy (e.g. restrict to admins/selected groups); denied users get the localized error.
- [ ] Imported templates are owned by the importer and never modify an existing template or run.

## 5. Localization and copy

- [ ] Switch the user language to English: no literal translation keys and no HTML entities anywhere.
- [ ] Switch to **Português (Portugal)**: all newly added text is translated and grammatical.
- [ ] Switch to **Português (Brasil)**: identical to PT-PT.
- [ ] Status labels, reason templates (`{step}`, `{section}`), plural forms (0/1/many) render correctly.
- [ ] Dates and numbers render in the locale.

## 6. Large run and recovery

- [ ] Start a run from a large published template; the page remains responsive.
- [ ] Complete, cancel and reopen a run: completion and cancellation are terminal until reopened; **reopening applies to a completed run when the reopen feature is enabled, never to a cancelled run**. Historical data (responses, activity) is preserved.
- [ ] Comments, attachments/evidence and mentions behave; disabled features are reflected in the UI.
- [ ] Notifications/due-date jobs behave under the configured cron mode.

## 7. Integrity / cache

- [ ] A run snapshot is unaffected by later template edits.
- [ ] After an upgrade, clear the Nextcloud/app cache and confirm the new bundle loads.
- [ ] Confirm the packaged archive contains no `build/` or `src/` development sources.

## 8. Milestone 0.5.0 — Nextcloud Files integration (#45–#56)

These items require a real Nextcloud instance with a real Files/AppData backend
and real users/mounts. They are **not** covered by the automated suite (which
uses in-memory doubles) and are **not** verified until observed here.

### 8.1 Destination precedence and folder model

- [ ] Nothing configured: starting a run creates `Files/Runbook` and a per-run subfolder `<title> (<full uuid>)` carrying a valid `.runbook-run.json` marker; evidence is stored there.
- [ ] A run-time folder chosen in the Start run dialog is used and frozen (`source=runtime`); an unresolvable run-time choice fails the start with a clear error and **no** fallback.
- [ ] A template destination is used when no run-time choice is supplied; an invalid/unavailable template destination blocks the start (no fallback) and the chooser's own mount is irrelevant.
- [ ] A global administration destination is used when neither run-time nor template supplies one; it must resolve in the **run owner's** view (a folder shared only to the admin fails with `destination_no_access`).
- [ ] A folder reachable through two mounts (or two in-scope candidates) resolves as `destination_ambiguous`; there is no first-candidate pick.
- [ ] Quota exhausted / mount unavailable surface `destination_quota_exceeded` / `destination_unavailable` and no run is created.

### 8.2 Evidence stored in Files

- [ ] Uploads store the file inside the run-managed folder by exact identity; storage paths/ids are never exposed by the API.
- [ ] Owner, assigned user and group assignee can upload/download per #51; viewers and unrelated users are denied.
- [ ] "Attach a copy from Files" leaves the original untouched and stores a normal Files-backed attachment.
- [ ] The reserved `.runbook-run.json` marker cannot be uploaded as evidence; duplicate names never overwrite existing files.

### 8.3 Out-of-band reconciliation and degraded evidence

- [ ] Rename/move **within** the run folder stays `present`; move **outside** shows `out_of_scope`; delete shows `missing`.
- [ ] A required FILE step cannot be newly completed with missing/out-of-scope evidence; a completed step stays completed and the run reports `evidenceDegraded`.
- [ ] The degraded notice is truthful about whether a replacement upload is possible (folder available vs missing/unavailable).
- [ ] Revoked Files access and a mid-run folder deletion fail closed and are surfaced.

### 8.4 Run deletion and cleanup records

- [ ] A tracked file that denies delete blocks the whole deletion (`run_delete_blocked`); the run and identities remain.
- [ ] Deleting a run removes tracked Files evidence and **preserves** the managed folder (it is never recursively deleted); a `runbook_files_cleanup` record is committed in the same transaction as the run-row removal.
- [ ] Untracked files added to the run folder are preserved and the record is finalized `blocked`/`not_empty`.
- [ ] The hourly retry closes the record once the folder is gone; the base folder and other runs' folders are never touched.

### 8.5 Legacy AppData migration (#55)

- [ ] A legacy run's AppData evidence is copied to the resolved destination, verified (size + SHA-256), switched to `storage_kind='files'`, and only **then** is the AppData source deleted.
- [ ] AppData evidence stays readable until migrated and remains readable from Files afterwards.
- [ ] An invalid/unavailable configured destination blocks that run's migration without fallback; AppData evidence is kept.
- [ ] `GET`/`POST /api/v1/admin/migration` (administrators only) reports remaining active AppData attachments, residual cleanup and blocked runs with actionable reasons.
- [ ] An interrupted migration resumes without duplicates; a residual `migration_source_delete_failed` is reported and retried without touching the verified Files copy.
- [ ] Two overlapping batches (hourly job vs admin trigger) do not process the same batch; the overlapping call reports `busy` without advancing the cursor.

### 8.6 Localization and packaging

- [ ] The packaged archive contains the compiled `js/`, `l10n/`, `lib/`, `templates/`, `img/`, `appinfo/` and the `docs/` linked from README, and no `src/`, `build/`, `tests/`, `.github/`, `vendor/` or `node_modules/`.
- [ ] `pt_BR` equals `pt_PT` value-for-value for every key on the running instance.

Sign-off: tester name, date, packages/build hash, and any deviation with a
reference to the file and line if applicable.
