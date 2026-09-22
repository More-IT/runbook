# Runbook 0.4.0 release candidate — manual acceptance checklist

This checklist is for the **real-Nextcloud production acceptance test** of the
milestone 0.4.0 release candidate (issues #26–#33). It must be executed manually
on a packaged build; **nothing here is marked as passed by the automated suite**,
and no item may be ticked without being observed.

The release-preparation task set the application version to **0.4.0**; verify in
the package that `appinfo/info.xml` and `package.json` say 0.4.0 **before** this
checklist is run. If the packaged version is still 0.3.0, stop and rebuild the
package.

Record for every run: source commit **or** a complete source manifest/diff with
hashes that covers untracked files, package checksum (SHA-256), packaged app
version, Nextcloud version, PHP version, database, browser and OS, and the date.

## 0. Setup

- [ ] Build the release archive from an **identified and reproducible source state**: either a specific commit hash, or a complete source manifest/diff with SHA-256 hashes that **includes untracked files** (`git status --short` alone is not sufficient, because it does not capture the content of untracked files). A clean tree is not required as long as the state is fully recorded and reproducibly rebuildable.
- [ ] Record the archive SHA-256 and the **packaged app version**; confirm it is the intended 0.4.0 release candidate set by the release-preparation task.
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

Sign-off: tester name, date, packages/build hash, and any deviation with a
reference to the file and line if applicable.
