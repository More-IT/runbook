# Runbook UX architecture (milestone 0.4.0, issue #26)

This document is the shared design foundation for the execution view and the
template editor. It is intentionally **not** a redesign of either page: the full
layouts are delivered by issues #27 and #28. Issue #26 establishes the shell,
hierarchy, state pattern, copy rules and tokens, and applies them to one
representative area (the run section header/status) so the direction can be
reviewed in a real screen.

## 1. Product questions the UI must answer immediately

1. **Where am I in this process?** — current section, position in the flow, and
   what is done so far.
2. **What is waiting, complete or outside the current path?** — section states
   and their reasons, without relying on colour.
3. **What can I do next?** — one dominant primary action, with the next
   actionable step made prominent.

## 2. Shared page shell

Goals: a calm, readable column; a compact header; one dominant action; clear
separation of secondary and destructive actions; no horizontal scrolling.

- **Content width.** `--runbook-content-max` (60rem) for the focused column;
  `--runbook-content-narrow` (44rem) for form-heavy and reading surfaces. Very
  wide screens must never stretch short inputs across the viewport.
- **Header.** Title + status badge + one line of essential context (owner,
  due date, progress). Height is content-driven, not fixed.
- **Primary action.** Exactly one visually dominant action per screen (for
  example “Start”, “Complete step”, “Publish”).
- **Secondary / destructive.** Secondary actions are grouped in a toolbar;
  destructive actions (“Delete”, “Cancel run”) are pushed to the trailing side
  and never share prominence with the primary action.
- **Spacing/typography.** Use the token rhythm (§7); no ad-hoc pixel values.

### Desktop shell (≥ 1024px) — target for #27 / #28

```
┌──────────────────────────────────────────────────────────────────────┐
│  [icon] Runbook · Run                              [status] [·····]   │  page header
├───────────────┬──────────────────────────────────────────────────────┤
│ Section nav   │  Section title                          [status]      │  focused content
│ (navigator)   │  ────────────────────────────────────────────────────│
│               │  Explanation line(s)                                 │
│ ▸ 1 Start  ✓  │                                                      │
│ ▸ 2 Prep   •  │  Step title                                          │
│ ▸ 3 Review ▸  │  Instructions …                                      │
│ ▸ 4 Ship   –  │  ┌ form control (max ~44rem) ┐   [Primary action]   │
│               │  Notes / evidence / comments (collapsed by default)  │
│ 264px         │                                                      │
└───────────────┴──────────────────────────────────────────────────────┘
```

### Mobile shell (< 768px) — target for #27 / #28

```
┌───────────────────────────────┐
│ [☰] Runbook · Run    [status] │
├───────────────────────────────┤
│ [ Section 3 of 5  ▾ ]         │  compact section picker (opens sheet)
│ Review                   ▸    │
│ ───────────────────────────── │
│ Explanation line(s)           │
│ Step title                    │
│ Instructions …                │
│ [ form control            ]   │
│ [ Primary action          ]   │
│ Notes / evidence / comments ▾ │
└───────────────────────────────┘
```

No horizontal scrolling at any width. Long translated strings wrap
(`overflow-wrap: anywhere`) instead of truncating actions.

## 3. Section navigator contract (#29 implements)

A clear section **list** with status and progress — no graph or canvas.

| Property | Contract |
| --- | --- |
| Placement | Left column on desktop, inside the app content area; a compact picker/sheet on narrow screens. |
| Width | 240–280px desktop; collapses to a single-row picker below 768px. |
| Entry content | Position number, section title, status label, step progress (e.g. `3/4`). |
| States | available, active, blocked, inapplicable, resolved, no-steps (+ step skipped/reopened shown within a section). |
| Interaction | Click/keyboard selects the section; the focused section scrolls into view. The current section is marked with `aria-current="true"`, not by colour alone. |
| Scale | Must remain usable with ≥ 20 sections and ≥ 50 steps: fixed rail, internal scroll, keyboard reachable. |
| Selection vs execution | Selecting a section only focuses it; flow availability still comes from the backend state. The navigator never enables actions the API forbids. |

## 4. Information hierarchy

Ordered from most to least prominent (top → bottom, strongest → weakest):

1. Page title + run/template status.
2. Progress summary (see §6).
3. Section title + section status.
4. Section explanation (reasons).
5. Section description.
6. Step title + instructions.
7. Form controls + primary action.
8. Section notes.
9. Evidence, comments, activity, participants (supporting, collapsible).

The next actionable step is the visual anchor. Notes, evidence, comments and
activity are accessible on demand (collapsed/summary) so they never pull focus
from the step being executed.

## 5. Flow state pattern

Implemented as `FlowStatusBadge.vue` (label + tone) and `SectionStatus.vue`
(short label + separate explanation lines), driven by the pure helpers in
`src/utils/sectionFlow.ts` and the structured reasons from
`src/utils/sectionReason.ts`. The frontend **never** derives flow decisions;
it renders backend `state`, `blockedBy` and `reason[]`.

Rules:

- Every state has a **short label**; tone is supplementary, never the only
  signal. The label text is always rendered.
- Explanations are a **list of separate lines**, never a concatenated sentence.
- Reasons use only the data the API provides. The stored user response is never
  shown in a reason.
- A section with **no steps keeps its real backend state** as the primary label
  (it can be `resolved`, `blocked` or `inapplicable`). The “no steps” fact is
  explained separately, and every state reason is retained.

| State | Label (EN) | Tone | Explanation (EN) |
| --- | --- | --- | --- |
| available | Available | info | “You can start this section.” |
| active | In progress | info | “Continue with the steps in this section.” |
| blocked (dependency) | Blocked | warning | “Waiting for “Testing” to be completed.” |
| blocked (condition) | Blocked | warning | “Waiting for an answer to “Approved”.” |
| inapplicable | Not applicable | muted | “This section is outside the current path.” + “The condition on “Result” was not satisfied.” |
| resolved (with steps) | Resolved | success | “All steps are resolved. Completed: 3, Skipped: 1.” |
| resolved (no steps) | Resolved | success | “This section contains information but has no steps to complete.” |
| blocked / inapplicable (no steps) | Blocked / Not applicable | warning / muted | the state reasons above + “This section contains information but has no steps to complete.” |
| skipped (step) | Skipped | muted | Shown on the step card; counts as resolved progress. |
| reopened (step) | Reopened | info | Step-level modifier (`reopenedAt`); the step becomes actionable again. |

The `resolved` count uses label-style copy (`Completed: 3, Skipped: 1`) so it
reads correctly for 0, 1 and many steps in every language, including Portuguese
where an adjective would otherwise have to agree with the number.

`skipped` and `reopened` are **step-level** concepts: the backend exposes no
section-level `skipped`/`reopened` state, so the pattern presents them on the
step (within a resolved section) rather than inventing a section state.

Multiple reasons: one line per reason (e.g. two failed conditions become two
lines). Blocked reasons list each dependency/pending answer separately.

## 6. Progress language

Current backend calculation (`RunService::calculateProgress`) is unchanged in
#26 and must not be reinterpreted in the UI:

- `total` = all steps in the run.
- `completed` = steps with status `COMPLETED`.
- `skipped` = steps with status `SKIPPED` **plus** steps in inapplicable
  sections (they are folded together).
- `pending` = every other step.
- `percentage` = `floor((completed + skipped) / total * 100)`.

Distinction the UI must make:

- **Sections**: count of sections by state (available / active / blocked /
  inapplicable / resolved / no-steps).
- **Total steps** vs **completed steps** vs **user-skipped steps**.
- **Outside the path** (inapplicable): different from a user skipping a step.
- **Pending vs blocked work**: blocked is pending that cannot start yet.

Because the API merges “user-skipped” and “outside the path” into `skipped`,
the display cannot separate them from the progress payload alone.

> **Needed for #27 (no calculation change in #26):** expose
> `inapplicableSteps` (count) alongside `completed`/`skipped`/`pending` in the
> run progress payload, or derive it in the client from section `state ===
> 'inapplicable'`. Proposed display: `12 completed · 2 skipped by user ·
> 3 outside the path · 5 pending (2 blocked)`. Until then, show the merged
> `skipped` value without claiming the two are the same.

## 7. Template authoring pattern (future section card, #28)

The section card in the template editor shows at a glance:

- section number and title;
- number of steps (and an explicit `No steps` notice);
- a concise **rule summary** (dependencies + conditions);
- editing fields revealed when the section is selected/expanded;
- destructive actions (delete) separated from routine editing (move, save).

### Rule summary

Implemented as the pure `ruleSummary()` helper and surfaced in `SectionEditor`.

Examples:

- No rules → “Opens immediately.”
- One dependency → “Opens after “Testing” is complete.”
- Dependency + condition → “Opens after “Testing” is complete AND if “Result”
  equals “3”.”
- Boolean condition → “… AND if “Approved” is yes.”
- Several dependencies → “Opens after “A”, “B” are complete …”.

Precise semantics (must not be misrepresented):

- Conditions **within one section** are combined with **AND**.
- **Alternative paths** come from **separate conditional sections**
  (mutually exclusive conditions).
- There is **no OR operator**; the summary never implies one.
- A **zero-step section may be intentional**; the editor shows a clear notice
  and does not block publication (that would need a separate product decision).

## 8. Visual language and tokens

Tokens live in `src/styles/tokens.css` and layer on Nextcloud theme variables
(no fixed colours, no decorative gradients, no new UI framework):

- spacing rhythm (`--runbook-space-1..6`),
- content widths (`--runbook-content-max`, `--runbook-content-narrow`),
- surfaces/borders/radii,
- text hierarchy helpers,
- state accents (`--runbook-accent-*`, always paired with a text label),
- focus ring and minimum target size (`--runbook-target-min: 44px`).

New shared components: `FlowStatusBadge.vue` (state pill) and
`SectionStatus.vue` (label + explanation lines). Both are used by the run view
and are intended for reuse by #28.

## 9. Accessibility and scale

- **Visible focus:** interactive controls keep the Nextcloud focus ring; custom
  surfaces use `--runbook-focus-ring`.
- **Semantics:** page and section titles use real headings; the section status
  is a labelled block with a `ul` of reasons read as separate items.
- **Status announcements:** state is always text, so it is announced; tone adds
  emphasis only. Do not move focus on state change.
- **Wrapping:** long titles and translated strings wrap safely; badges use
  `white-space: nowrap` and wrap onto new lines via the flex container.
- **Responsive:** desktop (navigator + focused column), tablet (collapsible
  rail), mobile (single column + section picker). No horizontal scroll.
- **Scale:** the navigator must stay usable with ≥ 20 sections / 50 steps.

## 10. Visual verification

The token and component work was validated by `npm run lint`,
`npm run typecheck`, `npm run test:frontend` and `npm run build`. Rendering of
light/dark/high-contrast themes and the exact pixel layout could **not** be
verified visually in this environment (no browser/Nextcloud theme runtime
available), so no visual test is claimed. Light/dark/high-contrast behaviour
rests on the Nextcloud theme variables and their fallbacks; #29 must confirm it
in a real instance.

## 11. Issue responsibilities

The UX redesign and the import/export work are separate tracks. The UX work is
#27–#29; the import/export work is #30–#33.

- **#27 — Run execution view (full page).** Apply the shell (header, one
  dominant action, separation of secondary/destructive actions), the desktop
  section navigator and mobile section picker, the information hierarchy and the
  progress language to the run detail screen. Add the proposed progress fields
  and display (see §6). Reuse `SectionStatus`/`FlowStatusBadge`; do not change
  flow calculations or authorization.
- **#28 — Template editor (full page).** Apply the shell and authoring pattern
  (section cards, rule summaries from `ruleSummary()`, explicit `No steps`
  notice, revealed edit fields, separated destructive actions). Reuse the
  tokens and status components.
- **#29 — Navigation, accessibility and responsiveness.** Deliver the section
  navigator interaction contract (§3), the responsive desktop/tablet/mobile
  behavior and the accessibility behavior (§9) across the redesigned screens.
- **#30 — Template export (delivered).** Serialise a template (sections, steps,
  flow rules) into the portable document format; no flow-engine changes.
- **#31 — Template import (delivered).** Create a DRAFT template owned by the
  importing user from an exported document, validated by the authoring rules and
  written atomically. The export/import JSON contract is `schemaVersion` 1.
- **#32 — Import/export validation and security (delivered).** Adversarial
  hardening of the #30/#31 contract: JSON-encoding failures become 400s (not
  500s), unknown fields are ignored, collection limits are pre-checked, import
  stays atomic and side-effect free, export/import authorization and data
  minimization are verified, and malicious text is never rendered as HTML.
- **#33 — Integration (code-complete; real-Nextcloud acceptance pending).** In-repo integration
  tests, cross-runtime contract coverage and documentation are delivered; the
  production acceptance checklist is in `docs/manual-acceptance-checklist.md`.

Any further extraction of tokens/components should be driven by #27–#29, not
speculation.

## 12. Non-goals for #26

- No full-page redesign (#27/#28).
- No navigation/responsiveness delivery (#29).
- No template import or import/export validation (#31–#33).
- No database, flow-engine, authorization or snapshot changes.
- No graph/canvas view; a section list with status and progress is the model.

## 13. Execution page (delivered in #27)

The run execution view (`RunDetailView.vue`) implements the foundation above.

- **Header.** “Back to runs”, the run title, status badge, context (template
  version, role, due date), description, a progress bar with readable counts, a
  next-action hint, and the actions. “Complete run” is the single primary
  action; “Reopen run” is secondary; “Cancel run” and “Delete run” sit in a
  separate destructive group and keep their confirmation dialogs.
- **Progress.** Display-only, disjoint categories derived in
  `src/utils/runExecution.ts` from section states and step statuses, matching
  `RunService::calculateProgress`: sections, total steps, completed,
  **skipped by user** (status `SKIPPED`), **outside the current path** (steps in
  `inapplicable` sections), pending and blocked (a subset of pending). The
  merged backend `progress.skipped` is never labelled “skipped by user”. When a
  run has no steps the bar is hidden and an explicit “no steps” message is shown
  instead of a misleading 100%.
- **Section navigator.** `SectionNavigator.vue` shows one entry per section with
  position, title, real backend state and progress. Applicable sections show
  `done/total`; **inapplicable sections show “Outside the current path”** instead
  of a misleading `0/1`; zero-step sections show “No steps”. Selecting an entry
  only changes what is displayed — it never changes flow state or enables an
  action. Blocked and inapplicable sections remain inspectable with all reasons.
- **Focused work.** One section is shown at a time (`SectionStatus` + notes +
  steps). Per-section expand/collapse is preserved while navigating and across
  API reloads, and resets when a different run is opened.
- **Next action.** Only for ACTIVE runs and always respecting the backend
  `executableStepIds`. The header shows “Next: …” with a “Go to step” shortcut
  when exactly one step is actionable; when several parallel steps are available
  it counts **startable** and **in-progress** steps separately (nothing is
  started automatically); when nothing is actionable it shows either “ready to
  complete” or a waiting message, never a contradiction. Completed/cancelled
  runs show no next-action message at all. Runs without steps show only the
  no-work explanation.
- **Deep links.** Opening a step selects its section, expands it, scrolls the
  step into view and moves focus/highlight to it. The deep link is a **one-shot
  intent**: it is applied once and does not reselect that section after later
  mutations, so a manual section choice is preserved across reloads. A missing
  step, a changed deep-link target and a different run are all handled safely.
- **Supporting information.** Evidence, comments, activity and participants
  (ACL) live in collapsible panels below the work area so they stay discoverable
  without competing with the active step.
- **Responsive.** Desktop (≥ 900px) shows the sticky navigator beside the
  focused content; below 900px the navigator becomes a section picker and the
  layout is a single column. Navigation stays usable with 20 sections / 50
  steps.

Remaining cross-screen navigation, accessibility and responsive polish stays
with #29. Template import (#31) and its validation/security hardening (#32) are
delivered; the #33 in-repo integration work is code-complete, with real-Nextcloud
production acceptance still pending (see `docs/manual-acceptance-checklist.md`).

## 14. Template editor (delivered in #28)

`TemplateEditorView.vue` implements the authoring experience.

- **Header and lifecycle.** Back, title, status badge, version and access role;
  exactly one prominent lifecycle action (Start run when published and
  permitted, otherwise Publish); Archive and Delete live in a separate
  destructive group behind their confirmations. Archived/read-only templates
  show a plain-language notice and readable content with editing controls
  disabled rather than a wall of disabled fields.
- **Named areas.** Template title/description (“Template details”) and “Access”
  are collapsible panels, so they are easy to find without dominating the
  section workspace.
- **Focused workspace.** `SectionOutline.vue` lists every section with position,
  title and `{count} steps` / “No steps”; the selected entry is marked with an
  explicit “Editing” label plus `aria-current`, not colour alone. Desktop shows
  the outline beside a restrained-width editor column; below 900px it becomes a
  section selector and a single column. Selecting a section only moves the
  editor focus — it never changes flow rules. Adding a section focuses it;
  reordering keeps the selected section by id; deleting selects a sensible
  neighbour; an empty template shows an empty state with an Add section action.
- **Section card.** The focused section shows a concise summary first (title,
  step count, description preview and the saved human-readable rule summary),
  then reveals grouped editing: **Basics** (title/description/notes) and
  **Opening rules** (dependencies and conditions), with **Steps** always
  visible. Condition rows show the controlling step (disambiguated by its
  parent section), the operator and the value where needed; incomplete rows are
  outlined and block saving with a clear message instead of being dropped. A
  live “Preview (unsaved changes)” is visually distinct from the saved rule. A
  zero-step section is allowed and gets an explicit, non-alarming notice plus an
  Add step action.
- **Steps.** Compact numbered rows show title, type, unit, Required and the
  assignee/due offset, plus the instructions when not editing. Only one step is
  open for editing at a time; a single explicit “Edit step” action opens it (the
  old chevron that revealed nothing is gone). Save/Cancel and a separated Delete
  are preserved. A failed save keeps the form open with the entered values and an
  error next to it.
- **Unsaved changes.** Drafts are never silently lost, and confirmations are
  scoped truthfully:
  - switching or adding a section warns only about the mounted section and step
    drafts — a metadata draft stays mounted and does not trigger a prompt;
  - opening another step is **step-scoped**: it only warns about the current step
    draft, and preserves the section and metadata drafts;
  - Publish, Start run, Archive, Delete and Back consider every visible edit
    (metadata, section and step) and require saving or an explicit discard;
  - confirming “Discard” restores exactly the drafts it named (metadata only for
    lifecycle discards, section=for section/step scopes, step-only for a step
    switch) and clears the dirty flags, so no second prompt is shown; cancelling
    keeps the draft and stays in place.
  - Save success closes the editor and clears the draft; save failure keeps the
    draft and surfaces the (server) error next to the form.
- **Read-only.** With `canEdit` false (viewer or archived) the section and step
  editors expose no Edit/Delete/move/Save controls and show readable information
  instead of a form. Effective permissions combine the server permissions with
  the current template status, so archiving makes the page read-only
  immediately (no reload) and closes any open editor. Server-side authorization
  remains the source of truth.
- **Rule authoring.** `ruleSummary()` renders “Opens after … AND if …”;
  conditions within one section are AND, and alternative paths are separate
  conditional sections — no OR is implied. Authoring never uses runtime
  flow-state badges.

Remaining cross-screen polish (navigation, accessibility, responsiveness) stays
with #29. Template import (#31) and its validation/security hardening (#32) are
delivered; the #33 in-repo integration work is code-complete, with real-Nextcloud
production acceptance still pending (see `docs/manual-acceptance-checklist.md`).

## 15. Navigation, accessibility and responsiveness (delivered in #29)

**App-shell navigation guard.** All in-app navigation flows through one guard
(`src/utils/navigationGuard.ts` parses hash targets, `src/utils/navigationController.ts`
runs the request/confirm/cancel transitions, and `App.vue` applies them). The
sidebar, template switching, closing the editor and browser Back/Forward share
the same rule: leaving the template editor while it has metadata, section or
step drafts opens a “Discard unsaved changes” dialog.

- The editor exposes `hasDrafts()` and the app reads it **synchronously**, so a
  decide-then-emit sequence (e.g. “Back to templates” → discard) can never show
  a second dialog. One decision discards the drafts and navigates.
- The reducer consumes the pending target exactly once: a stray confirm is a
  no-op (no loop, no repeated prompt).
- **Cancel** keeps the editor and its drafts and restores the URL to the editor
  route.
- **History strategy (physical positions, never a guessed id offset).** Every app
  entry stores its **physical position** (0-based index in the browser stack) in
  `history.state` under the namespaced key `runbookNav`, merged into (never
  replacing) an existing plain-object state. Invariant: `pushState`/`replaceState`
  never remove entries *before* the current one, so a surviving entry's physical
  position never changes; therefore
  `history.go(editorPosition - destinationPosition)` is an exact step count even
  after a branch truncated forward entries.
  - **Cancel** uses that exact distance; **Confirm** shows the entry the browser
    already reached (no write) or pushes one entry for a programmatic request.
  - Returning to the entry the app still renders (Back → Forward while the dialog
    is open) clears the pending decision, closes the dialog and keeps the drafts.
  - **Foreign / pre-mount entries** (no stored position) or state shapes that
    cannot carry metadata (primitive, array, `Date`, `Map`, class instance) are
    **never guessed**: Cancel re-anchors the editor URL with a new entry instead
    of a possibly wrong `history.go`. A **dirty editor with an unknown position
    still guards** leaving it — unknown never means “safe to leave”.
  - On mount the stored position is **retained**; it is never overwritten with
    `history.length - 1` (that is the last entry, not the current one). An entry
    with no valid position stays explicitly unknown.
  - Initial load normalises the URL with `replaceState` while preserving the
    existing state; the app listens only to `popstate`.
  - State contract: metadata is merged **only** into a plain record; any other
    `history.state` value (primitive, array, `Date`, `Map`, class instance) is
    preserved exactly as-is and the entry is treated as unknown. Universal
    Nextcloud-state preservation is therefore **not** claimed — the precise
    contract is “plain records are extended, everything else is left untouched”.
  - Trade-offs: a foreign/unknown destination re-anchors (adds one editor entry)
    instead of restoring the exact index.
- Run deep links (including `#/run/{id}/step/{id}` one-shot focus) and
  navigation from My Work are unaffected, because they are not inside the
  editor.
- The sidebar marks the parent area active while viewing a run (`Runs`) or
  editing a template (`Templates`).
- **Stale-load protection:** `TemplateEditorView` load is sequence-guarded and
  drops stale detail when the template changes, so an earlier template’s
  response can never be shown under a newer route; the editor is keyed per
  template id in the shell.

**Section navigation.** Both pages use the same contract: a desktop list with
number, title and status/progress (runs) or step count (templates), and a
compact native selector on narrow screens. The selected entry is marked with
`aria-current` and a visible text marker (never colour alone). Explicit user
selection scrolls the section content into view and moves focus to the section
container; background API reloads never steal focus. The selected entry stays
visible in the internally scrollable list (`scrollIntoView({ block: 'nearest' })`),
and the narrow-screen selector is re-synced with the real selection, so a
cancelled selection visibly returns to the original section.

**Accessibility.** Dialogs (`NcDialog`) manage focus and return it on close;
section containers receive a visible focus ring; every status has a readable
text label with colour as a supplement; destructive actions stay separated with
action-specific confirmation copy; the inactive desktop/mobile navigation
variant is `display: none`, so it is not keyboard-focusable; condition and
section fields keep their labels. Where the previous chevron revealed nothing it
was removed for a single labelled action (steps); expand/collapse controls use a
clear action label.

**Responsive and theme.** Both pages are single-column and fluid at ~320–768px,
move to the outline/editor split at ≥900px, and cap content at
`--runbook-content-max` / `--runbook-content-narrow` on wide desktops. Action
groups wrap or stack; the navigator works inside the Nextcloud app shell with
its own internal scroll; touch targets use `--runbook-target-min` (44px); focus
rings use `--runbook-focus-ring`. Only Nextcloud theme variables and Runbook
tokens are used, so light/dark/high-contrast keep working.

Template export (#30), template import (#31) and the import/export
validation/security hardening (#32) are delivered; the #33 in-repo integration
work is code-complete, with real-Nextcloud production acceptance still pending
(see `docs/manual-acceptance-checklist.md`).
