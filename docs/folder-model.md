# Runbook 0.5.0 — Folder model and destination precedence (issue #45)

Status: **contract / architecture for issue #45**, implemented by issues #46–#55
and hardened by #56. The Files storage contract described below is implemented;
each capability carries an **Implementation status** note. Released 0.4.0
predates this milestone and stored run attachments in **AppData**; from this
milestone new evidence is written to Nextcloud Files and legacy AppData evidence
is migrated by #55.

Scope boundaries:

- New run attachments live in Nextcloud Files, never in AppData.
- Existing AppData attachments are migrated by the verified migration in #55;
  this document does not delete or abandon them.
- No application behaviour, API payload, database schema, translation or
  authorization change was made by #45 itself (it is the contract).

Issue mapping used throughout (authoritative for this milestone):

| Issue | Delivers |
| ----- | -------- |
| #45 | This contract (folder model + destination precedence) |
| #46 | Default folder (`Files/Runbook`) creation + schema/entities |
| #47 | Global administration destination setting (implemented) |
| #48 | Template destination setting (implemented) |
| #49 | Run-time destination override (start-run payload/UI) (implemented) |
| #50 | Attachment storage in Files (new upload path + file identity) (implemented) |
| #51 | Permissions (participant/assignee upload and download rules) (implemented) |
| #52 | Out-of-band Files changes (rename/move/delete/revoke reconciliation) (implemented) |
| #53 | Opening/copying existing Files into a run (implemented) |
| #54 | Safe deletion boundaries (implemented) |
| #55 | Legacy AppData migration (implemented) |
| #56 | Documentation/tests/hardening (implemented) |

## 0. Product rules (fixed)

1. New run attachments live in Nextcloud Files, never in AppData.
2. With no destination configured, use (create if absent) a **base folder named
   `Runbook`** at the root of the **run owner's user-visible Files**, and create
   a **dedicated managed subfolder per run**.
3. Precedence for a new run:
   **run-time choice > template setting > global administration setting >
   default (`Files/Runbook`)**.
4. An inaccessible, missing, incomplete or unwritable selected destination is a
   **hard error** or requires an explicit valid replacement. Never silently fall
   back to another destination or to AppData.
5. Each run owns a distinct managed subfolder. Deleting a run never deletes the
   base folder, another run's files, manually added unrelated files, or an
   original file copied from elsewhere in Files.
6. AppData evidence is migrated by #55 only after verification (copy → verify →
   update metadata → remove legacy). The milestone cannot be accepted while any
   active attachment still depends on AppData.

## 1. Destination decision table

For every precedence level there are exactly three states:

- **NOT SUPPLIED** — no value for this level → fall through to the next level.
- **SUPPLIED · VALID** — resolves to a writable folder in the intended user's
  view → use it (this level is authoritative).
- **SUPPLIED · INVALID / INCOMPLETE / UNAVAILABLE** — fail closed with an
  actionable error. **It never falls through to a lower level.**

A partially populated configuration (some but not all identity fields present) is
**SUPPLIED · INCOMPLETE**, not "unset", and fails closed.

| # | Source | State | Result |
| - | ------ | ----- | ------ |
| 1 | Run-time override | not supplied | fall through to #2 |
| 1 | Run-time override | supplied, valid, `READ`+`CREATE` in the run owner's view | **use it** (`source = runtime`); may replace an invalid suggestion from #2/#3 because it is validated independently |
| 1 | Run-time override | supplied, not found / not a folder | `destination_invalid` (400) |
| 1 | Run-time override | supplied, incomplete coordinates | `destination_invalid_config` (400) |
| 1 | Run-time override | supplied, no `READ` | `destination_no_access` (403) |
| 1 | Run-time override | supplied, no `CREATE` | `destination_not_writable` (409) |
| 1 | Run-time override | supplied, ambiguous across mounts (§2) | `destination_ambiguous` (409) |
| 1 | Run-time override | supplied, storage/mount unavailable | `destination_unavailable` (409, retryable) |
| 2 | Template setting | not supplied | fall through to #3 |
| 2 | Template setting | supplied, valid + writable in the run owner's view | **use it** (`source = template`) |
| 2 | Template setting | supplied, invalid / incomplete / no access / not writable / ambiguous / unavailable | fail closed as in #1 (never fall through) |
| 3 | Global administration setting | not supplied | fall through to #4 |
| 3 | Global administration setting | supplied, valid + writable in the run owner's view | **use it** (`source = admin`) |
| 3 | Global administration setting | supplied, invalid / incomplete / no access / not writable / ambiguous / unavailable | fail closed as in #1 |
| 4 | Default `Files/Runbook` | always defined | resolve or create `<run owner>/Files/Runbook` in the run owner's view; **use it** (`source = default`); creation failure is `destination_unavailable` |

Independent failure states (regardless of which source is configured):

| Condition | Result |
| --------- | ------ |
| Run owner user no longer exists / is disabled | `destination_owner_missing` (409); never fall back |
| Owner's Files not available (mount backend down) | `destination_unavailable` (409) |
| Destination node is a **file**, not a folder | `destination_invalid` (400) |
| Destination folder `isCreatable()` is false or lacks `PERMISSION_CREATE` | `destination_not_writable` (409) |
| Quota exhausted on the destination storage | `destination_quota_exceeded` (409/507) from `NotEnoughSpaceException` |
| A matching managed-folder name exists but its ownership marker is missing / malformed / unreadable / a foreign UUID | `destination_ownership_conflict` (409); never adopt or overwrite |
| No supplied source **and** `Files/Runbook` cannot be created | `destination_unavailable` (409) |

Resolution order is fixed and must not be reordered per caller. Every error
carries the failing level (`runtime|template|admin|default`).

**One resolution user: the run owner.** Every destination is validated,
re-resolved and used **in the run owner's Files view** (`viewUid = run owner`).
At run start the initiator *is* the run owner, so their view is the same. When an
administrator or template owner *selects* a folder, that selection is only a
**reference**: the chooser's own view is used to pick the node at configuration
time, and `configured_by` records who chose it, but the folder must still resolve
in the **run owner's** view at run start. If it does not, the destination fails
closed (`destination_no_access` / `destination_unavailable`); Runbook never
switches to the administrator's or template owner's view.

A configured reference is stored as `(storage_id, file_id)` (+ advisory path); the
**chooser's descriptor fields are not authoritative** and are never compared to
the run owner's. At run start the node is re-resolved in the run owner's view; the
run owner's descriptor fields are recorded as audit metadata (§4.3). If the run
owner's view yields more than one in-scope candidate (the exposed mount fields are
descriptive and cannot be assumed to identify a mount, including when `mountId`
or `numericStorageId` is `null`), resolution fails closed with
`destination_ambiguous` (§2.1.1).

### 1.1 Legacy runs vs corrupt new runs

The new run columns are only trustworthy once #46 ships. To distinguish a
pre-migration/legacy run from a corrupt record:

- **Legacy run:** all destination columns are `NULL`, `destination_source` is
  `NULL`, **and** `destination_migrated_at` is `NULL`. (Such a run was created
  before the feature; #55 establishes and freezes its destination as described in
  §8.1, and until then nothing changes.) These are not "invalid"; they are simply
  not yet resolved by the new system.
- **Corrupt/invalid run:** `destination_source` is set but one or more coordinate
  columns are missing or fail validation; or coordinate columns are set while
  `destination_source` is `NULL`; or `destination_migrated_at` is set but the
  destination is incomplete. This is `destination_invalid_config`: fail closed,
  log, and require an explicit re-selection (the folder-repair action of §5.2 is
  **not** implemented in 0.5.0). It is **never** treated as legacy and never
  silently resolved to the default folder.

## 2. Ownership and identity

Nextcloud has **no universal shared Files root**, and the installed OCP interface
warns that the same `fileid` may be reachable through more than one mount **with
different permissions**. Authorization must therefore never use an arbitrary
first match.

### 2.1 Identity fields and what each one means

A destination (or attachment) is stored as:

```
{
  viewUid:          string,    // user whose Files view resolves the node (run owner for run ops)
  storageId:        string,    // IStorage::getId() — STRING, exact (see §2.3)
  fileId:           int,       // Node::getId() (oc_filecache.fileid)
  storageRootId:    int,       // IMountPoint::getStorageRootId() — always an int
  mountType:        string,    // IMountPoint::getMountType()
  mountProvider:    string,    // IMountPoint::getMountProvider()
  mountId:          int|null,  // IMountPoint::getMountId() — NULLABLE (see §2.1.1)
  numericStorageId: int|null,  // IMountPoint::getNumericStorageId() — NULLABLE
  mountPoint:       string,    // IMountPoint::getMountPoint() — advisory display only
  path:             string     // display path; advisory only
}
```

- **`viewUid`** is the **user whose Files view / mount context is resolved**;
  for every run operation it is the **run owner** (§1). It is **not** the run
  owner necessarily for a *configured* reference (the chooser is recorded as
  `configured_by`), and **not** the underlying storage owner.
- **File-owner APIs exist, but are not the identity.** The installed interfaces
  do expose ownership:
  - `OCP\Files\Node extends OCP\Files\FileInfo`, and `FileInfo::getOwner()`
    returns `?\OCP\IUser` (`@since 9.0.0`) — it may return **`null`** when no
    owner is known.
  - `OCP\Files\Storage\IStorage::getOwner(string $path)` returns
    `string|false` (`@since 9.0.0`) — it returns **`false`** when the storage
    does not know an owner (for example some external/object stores). It does
    **not** return `null`.

  What they establish: the owning user of the file *as reported by the node's
  storage implementation*. Limits: the interfaces do not document which user is
  reported for every storage wrapper in shared or mounted folders (the storage's
  own owner handling applies). Neither is documented to reliably identify the
  user whose Files view resolves the node (`viewUid`), and neither disambiguates
  the same `fileid` reached through multiple mounts with different permissions.
  Therefore:
  - **Do not** use `getOwner()` for identity, authorization, or equality checks;
  - the authoritative identity is `viewUid` + `(storageId, fileId)` (see below
    and §2.1.1);
  - `getOwner()` may be used only for **best-effort display/diagnostics** and is
    never persisted as contract identity — `FileInfo::getOwner()` must handle a
    possible `null`, and `IStorage::getOwner()` must handle `false`.
- The stable identity is `viewUid` + `(storageId (exact string), fileId)`. The
  mount descriptor fields (`storageRootId`, `mountType`, `mountProvider`,
  `numericStorageId`, `mountId`) and `path`/`mountPoint` are **descriptive
  metadata only** and are never used for existence, authorization, deduplication
  or tie-breaking (§2.1.1).

### 2.1.1 Mount identity, `mountId` nullability and the limits of the exposed fields

Verified types and documented meaning from the installed `vendor/nextcloud/ocp`
(`OCP\Files\Mount\IMountPoint`):

- `getMountId(): int|null` — "mount id or **null if not applicable**" (nullable,
  not documented as unique or stable).
- `getStorageId(): string|null` — nullable.
- `getNumericStorageId(): int|null` — nullable.
- `getStorageRootId(): int` — "the file id of the **root of the storage**"
  (a node id, not a mount identifier; not documented as unique per mount).
- `getMountType(): string` — "the type of mount point, used to distinguish
  things like shares and external storage" (descriptive).
- `getMountProvider(): string` — "the class of the mount provider"
  (descriptive).
- `getMountPoint(): string` — the mount point path.

**The public OCP contract does not guarantee that any combination of these fields
uniquely identifies a mount.** `mountId`/`numericStorageId` may both be `null`;
`storageRootId` is a storage-root node id; `mountType`/`mountProvider` are
descriptive. Therefore:

- Runbook must **not** claim a "reliable" or "discriminating" mount tuple, and
  must **not** deduplicate two candidate mounts merely because these fields
  compare equal.
- The exposed fields are recorded for **audit, diagnostics and display only**;
  they are never a uniqueness key, an authorization input, or a dedup key.
- The only resolution context that is contract-supported is **scope**: a
  candidate is *in scope* only if it is the intended node (exact `fileId` and
  `storageId`) and, for run operations, inside the run's **run-managed folder**
  (verified with `Folder::isSubNode()` / parent-chain), reached in the given
  `viewUid`'s view.
- **Resolution must yield exactly one in-scope candidate.** If more than one
  candidate remains — including when distinct mounts expose otherwise identical
  `storageRootId`/`mountType`/`mountProvider`/`numericStorageId`/`mountId` values,
  and when those fields are `null` — the public OCP data cannot distinguish the
  mounts, so Runbook **fails closed with `destination_ambiguous`**. It never picks
  a first candidate and never uses permissions alone to select one.
- User-facing outcome for that case: Runbook reports that the referenced folder
  is reachable through more than one mount and **cannot be used as a destination
  under the current selection model**. The identity model stores only
  `viewUid + (storageId, fileId)`, so *re-selecting* the same folder does not
  remove the ambiguity (the mount choice is not a storable identifier). The
  supported remedies are therefore to use a **different folder that resolves to
  exactly one in-scope candidate** for that user, or to change the sharing/mount
  setup so that the folder is reachable through a single mount. No silent choice
  is made and the operation stays failed closed until resolution is unambiguous.
- If a future implementation proposes another public identity field, its exact
  semantics, nullability and availability for the declared Nextcloud 33 target
  must be verified first; until then, tie-breaking is not attempted.
- `mountPoint` (path) and `path` are **advisory display only** and are never a
  discriminator or tie-breaker.

### 2.2 Resolution strategy (no arbitrary first match)

Resolve a node by `fileId` for a specific `viewUid` and **select an explicit
candidate**:

1. `$view = $rootFolder->getUserFolder($viewUid);` — the resolution user's view
   root (their own Files plus shares/mounts available to them).
2. **Collect candidates from exactly one canonical source per resolution**
   (never merge overlapping query results):
   - If an authoritative **parent folder node** is known from stored identity (the
     base folder for a destination, or the run-managed folder for an attachment),
     the canonical source is `$parentNode->getById($fileId)`
     (`OCP\Files\Folder::getById()` — nodes for that id inside that folder).
   - Otherwise (a bare `(storageId, fileId)` reference with no authoritative
     parent folder) the canonical source is `$view->getById($fileId)` on the
     resolution user's view root (also `Folder::getById()`).
   - Use **one** of the two for a given resolution step. Do **not** union them:
     the same mount/node can be returned by both queries, and merging would count
     it twice and create a false ambiguity.
   - The candidate set is exactly the result of the chosen single source; it is
     not deduplicated or extended by any other query, and never by comparing
     descriptive mount fields.
   Do **not** use `getFirstNodeById()` / `getFirstNodeByIdInPath()` for
   authorization: they return an unspecified single node that may have fewer
   permissions than another reachable node.

   **Attachment reconciliation diagnostic (issue #52).** For a tracked attachment
   the run-managed folder is the known parent, so its `getById($fileId)` is the
   canonical **authoritative** source. When (and only when) it yields **zero**
   in-scope candidates, the reconciler performs a second, **diagnostic**
   `$view->getById($fileId)` on the run owner's view root **solely to classify
   the failure**, using only the authoritative identity
   `(storageId, fileId)` — never mount descriptor fields, never a first match:
   **exactly one** same-storage node found elsewhere → `out_of_scope`;
   **two or more** same-storage nodes → `unavailable` (ambiguous); a node visible
   **only on another storage** → `unavailable`; **no** node → `missing`. The
   diagnostic query is never an authoritative candidate source, is never merged
   with the parent result (so an in-scope node is never double-counted) and never
   selects a node; ambiguity in **either** stage fails closed (`unavailable`).
3. Filter candidates:
   - must be `instanceof \OCP\Files\Folder` (for a folder identity);
   - `$node->getStorage()->getId() === $storedStorageId` (string comparison);
   - **scope**: the candidate must be the intended node under the configured
     parent path and, for run operations, inside that run's **run-managed
     scope** (§3) — not merely a same-id node reached through a different mount.
4. **Decide on the in-scope candidates (fail closed, no tuple dedup):**
   - The exposed mount fields (`getStorageRootId()`, `getMountType()`,
     `getMountProvider()`, `getNumericStorageId()`, `getMountId()`) are
     **descriptive**. The OCP contract does **not** guarantee that equal values
     mean the same mount, so candidates are **not** deduplicated by comparing
     these fields. Record them for audit/display only.
   - **exactly one** in-scope candidate → authoritative node.
   - **zero** in-scope candidates → `destination_unavailable`
     (destination/migration) or `attachment_missing` (attachment).
   - **two or more** in-scope candidates → `destination_ambiguous`: fail closed
     and require the user to resolve the folder explicitly. This holds **even
     when their permissions are identical**, **even when the exposed mount fields
     are identical**, and **even when `mountId`/`numericStorageId` are `null`**;
     the resolver never picks the first candidate and never uses permissions to
     choose one.
   - The stored identity is `viewUid` + `(storageId, fileId)` (a shared storage id
     and file id are one node); the descriptor fields and `path`/`mountPoint` are
     metadata and are never sufficient to resolve a node, deduplicate candidates
     or break a tie.
5. **Authorize on the selected candidate**:
   - destination/base folder operations require the folder node's own
     `PERMISSION_READ` and `PERMISSION_CREATE` (create a per-run subfolder);
   - deleting a **tracked file** requires that **file node's own**
     `PERMISSION_DELETE` (`isDeletable()`), evaluated per file;
   - removing the **managed folder itself** requires that **folder node's own**
     `PERMISSION_DELETE` (`isDeletable()`) and that it is empty.
   Never infer permission to delete child files from the parent folder's
   permission, never require `PERMISSION_SHARE`, and never create a share to
   satisfy a check.

### 2.3 Types and availability (verified against vendor/nextcloud/ocp)

- `OCP\Files\IStorage::getId(): string` → `storageId` is a **string**.
- `OCP\Files\Folder::getById($id): Node[]` and
  `OCP\Files\IRootFolder::getByIdInPath(int $id, string $path): Node[]`.
- `OCP\Files\Folder::getFirstNodeById(int $id): ?Node` and
  `IRootFolder::getFirstNodeByIdInPath(...)` are documented as "no guarantee
  which node … might have less permissions"; **not for authorization**.
- `OCP\Files\Folder::getOrCreateFolder(string $path, int $maxRetries = 5)` is
  `@since 33.0.0`; Nextcloud 33 is the declared minimum, so it is available.
- `OCP\Files\Node::getStorage()`, `getPermissions()`, `getPath()`,
  `getInternalPath()`, `getParent()`, `getId()`, `isSubNode()` (on `Folder`),
  `isDeletable()`, `isCreatable()`.
- Mount context (`Node::getMountPoint(): IMountPoint`):
  `getMountId(): int|null`, `getStorageId(): string|null`,
  `getNumericStorageId(): int|null`, `getStorageRootId(): int`,
  `getMountType(): string`, `getMountProvider(): string`,
  `getMountPoint(): string` (see §2.1.1). These are **descriptive metadata**: the
  interface does not guarantee any of them (or a combination) uniquely identifies
  a mount, so they are never used alone to select, deduplicate or authorize.
- Ownership: `FileInfo::getOwner(): ?\OCP\IUser` (via `Node extends FileInfo`)
  and `IStorage::getOwner(string $path): string|false` exist but are used only
  for best-effort display (§2.1), never for identity or authorization.
- `IStorage::getId(): string` has **no documented maximum length**; see §4.1 for
  the storage representation.

## 3. Folder tree, naming and creation

```
Files/                       (run owner's user-visible root)
└── Runbook/                 base folder   (default; configurable elsewhere)
    ├── 2026-03-12 Deploy (a1b2c3d4-0000-4000-8000-000000000000)/  managed subfolder
    │   ├── .runbook-run.json          ownership marker (full run uuid)
    │   ├── Divisões.png               attachment copies (tracked by fileid)
    │   └── report.pdf
    └── 2026-03-13 Incident (d4e5f6a7-0000-4000-8000-000000000001)/
        └── .runbook-run.json
```

- **Base folder:** default literal name `Runbook` at the run owner's Files root.
  Admin/template/run-time configuration may point at another folder. The base
  folder is never deleted by Runbook.
- **Managed per-run subfolder:** exactly one per run, inside the base folder.
  Display name `<sanitized run title> (<full run uuid>)`; the run `uuid` (already
  generated at start) is the deterministic collision discriminator and is
  embedded **in full**, so the name is stable for a given UUID (re-resolving the
  same UUID yields the same folder) and distinct for every different UUID. Two
  runs that share a title and a UUID prefix therefore never derive the same name,
  and a pre-existing unrelated folder whose name only matches a name prefix is
  never adopted. A **new** `startRun()` generates a new UUID, so it creates a new
  folder and never reuses a previous attempt's folder (§3 failed-start bullet).
- **Safe display names:** strip control characters and path separators; reject
  `..`; collapse whitespace; enforce `IFilenameValidator`/reserved names and the
  255-character limit by trimming **only the title** — the full-UUID
  discriminator is never truncated, so the identity suffix is always preserved.
- **Ownership marker (required):** every managed folder carries one small file
  `.runbook-run.json` written through the public Nextcloud Files API
  (`Folder::newFile()`), containing
  `{"format":"runbook-run-folder","version":1,"uuid":"<full run uuid>"}`. The
  folder name alone is **never** proof of ownership. A folder is treated as the
  managed folder of a run **only** when this marker exists, is valid
  (recognised `format`/`version`) and its `uuid` matches that run exactly.
- **Adoption / recovery rule (fail closed):** when resolving an existing
  deterministic name, the folder is reused **only** when its marker proves
  ownership. Because the name embeds the UUID, this only ever applies to the same
  attempt/UUID; a new `startRun()` uses a new UUID and never reaches a previous
  attempt's folder (§3 failed-start bullet). A missing, malformed, unreadable,
  foreign-UUID or unknown-version marker is an **ownership conflict**
  (`destination_ownership_conflict`, 409): the folder is left untouched and never
  adopted or overwritten.
- **Two distinct scopes (do not conflate):**
  - **Destination/base scope** — the configured/created **base folder** and its
    descendants. This is the only place Runbook may create a per-run managed
    subfolder, and is used when resolving/validating the destination. It is
    **never** used to manage or delete attachment files.
  - **Run-managed scope** — the **exact managed subfolder of one run** and its
    descendants (resolved by that run's `run_folder_file_id`), reached through
    the same resolved candidate context (§2.2). Scope is verified with
    `Folder::isSubNode()` / parent-chain **and** storage id, never by string
    prefix alone.
  - A file elsewhere in the base scope (another run's subfolder, the base folder
    root, or unrelated content) is **never** managed evidence for a run and is
    **never** deleted with that run.
  - Which scope each operation uses:
    - attachment validation / completion (`present` + in scope): **run-managed
      scope**;
    - out-of-band move detection: **run-managed scope** (outside it →
      `out_of_scope`);
    - evidence download: **run-managed scope**;
    - run deletion: **run-managed scope only** (never base scope).
- **Idempotent default base creation:** `getUserFolder($owner)->getOrCreateFolder('Runbook')`
  under an exclusive `ILockingProvider` lock keyed
  `runbook/destination/<viewUid>/<base>`; tolerate `AlreadyExistsException` from
  a racing request and re-read the node.
- **Concurrency:** per-run folder creation takes an exclusive lock keyed
  `runbook/run-folder/<runUuid>`. The folder is created and its ownership marker
  is written **inside the same lock**, so a concurrent start can never observe or
  accept a partially created / unmarked / partially written folder (it either
  blocks, or fails closed with `destination_ownership_conflict`). A racing
  duplicate is resolved by re-reading the node and validating its marker, never
  by creating a second folder for the same run.
- **Failed start / failed marker write (folder preservation, manual cleanup):**
  compensation never deletes the managed folder. The public Files API only offers
  recursive `Folder::delete()`, which cannot be made atomic against a user adding
  a file after any emptiness check (issues #46/#54); the folder, its ownership
  marker and any content are therefore preserved. This is **not** a resumable
  state: `RunService::startRun()` generates a fresh run UUID on every invocation
  (there is no start idempotency key) and the folder name embeds that UUID, so a
  later start — even with the same title — creates a *new* folder and never
  adopts the preserved one (adoption requires the marker UUID to match the
  current attempt). The preserved folder is therefore an **orphan requiring
  manual cleanup** by the owner/administrator; Runbook logs its authoritative
  identity `(viewUid, storageId, fileId)` to locate it. A failed marker write
  leaves whatever marker state exists; only an **owned** marker (content proves
  this attempt's UUID) may be removed as a single file, and the original
  marker-write error is always reported.
- **Crash between folder creation and marker write:** leaves an unmarked folder,
  which fails closed on the next attempt (`destination_ownership_conflict`);
  Runbook never adopts it and never deletes it. This is a deliberate trade-off:
  an unmarked folder is indistinguishable from a user-created one, so it is
  never taken over or removed.

## 4. Persistent data contract (implemented by #46/#50/#55)

### 4.1 Destination coordinates (value object)

| Field | Type | Meaning |
| ----- | ---- | ------- |
| `viewUid` | string(64) | user whose Files view resolves the node (run owner for run operations; §1/§6) |
| `configuredBy` | string(64) | who selected the reference (admin/template owner/initiator); never used for resolution |
| `storageId` | text (unbounded) | full `IStorage::getId()` string; **identity** (exact) |
| `fileId` | bigint | stored `fileid`; **identity** |
| `storageRootId` | bigint | `IMountPoint::getStorageRootId()` — descriptive metadata only |
| `mountType` | text (unbounded) | `IMountPoint::getMountType()` — descriptive metadata only |
| `mountProvider` | text (unbounded) | `IMountPoint::getMountProvider()` — descriptive metadata only |
| `mountId` | int, nullable | `IMountPoint::getMountId()` — may be `NULL`; descriptive metadata only |
| `numericStorageId` | int, nullable | `IMountPoint::getNumericStorageId()` — may be `NULL`; descriptive metadata only |
| `mountPoint` | text | `IMountPoint::getMountPoint()` — advisory display only |
| `path` | text | display path (advisory only; never identity, dedup or tie-breaker) |

**Identity is `viewUid` + `(storageId, fileId)`.** A folder is the same node for
a given user when the exact storage id and file id match; the mount descriptor
fields (`storageRootId`, `mountType`, `mountProvider`, `numericStorageId`,
`mountId`, `mountPoint`) are recorded for **audit, diagnostics and display only**
and are **not** an identity or uniqueness key. Because the OCP contract does not
guarantee that any combination of those fields uniquely identifies a mount (§2.1.1),
Runbook never deduplicates candidates by comparing them, and resolution requires a
single in-scope candidate or fails closed with `destination_ambiguous` (§2.2).

**Storage/mount string representation.** `IStorage::getId()`, `getMountType()`
and `getMountProvider()` are typed `string` and the installed interfaces document
**no maximum length**, so no length bound may be assumed. They are stored as
**unbounded text** (`storageId`, `mountType`, `mountProvider`), kept and compared
as the *complete* value with strict, case-sensitive string equality — never
truncated, never hashed, never prefix-matched and never case-folded, so no
truncation or collision is possible. Numeric descriptor fields (`storageRootId`,
`numericStorageId`, `mountId`) are stored as integers. Because resolution is by
`file_id` (bigint, indexed) and `storage_id` only compared after a `file_id` match
in PHP, no other field needs an index. If a bounded column is later desired, the
bound must be taken from authoritative Nextcloud schema/API evidence, not invented
here.

### 4.2 Template / administration destination reference (#47/#48)

A configured reference stores only what is needed to **re-resolve the folder in
the run owner's view**:

`destination_storage_id` (string), `destination_file_id` (bigint),
`destination_path` (advisory), `destination_configured_by`. All nullable; all
`NULL` = not supplied; partly populated = supplied-but-incomplete → fail closed
(§1).

> **Implementation status (#47 global / #48 template / #49 run-time).** All
> destination levels are implemented.
>
> **Administration (#47):** persisted **atomically** as one validated JSON value
> under a single `IAppConfig` key (`destination_reference` =
> `{"v":1,"storageId":…,"fileId":…,"path":…,"configuredBy":…}`). Unset = key
> absent; a present but malformed value, or a partial/conflicting stored state,
> fails closed (`destination_invalid_config`). Because a save and a clear are
> single-key writes, a run-start reader observes either the complete old
> reference or the complete new reference, never a mix, and a failed save or
> clear leaves the previous reference intact. For compatibility, a complete
> deprecated four-key reference (`destination_storage_id`, `destination_file_id`,
> `destination_path`, `destination_configured_by`) is still read; it is migrated
> into `destination_reference` on the next save/clear (preserving the value
> before removing the legacy keys, so a valid destination is never observed as
> unset). A complete legacy value that disagrees with a present
> `destination_reference` is ambiguous and fails closed.
>
> **Template (#48):** the same reference fields are stored as four nullable
> columns on `runbook_templates` (`destination_storage_id`,
> `destination_file_id`, `destination_path`, `destination_configured_by`). All
> `NULL` = unset (falls through); a partial reference fails closed. The reference
> is set/cleared only by a user with the template **edit** permission on a
> non-archived template, and is captured server-side from the chooser's own view.
>
> **Run-time (#49):** a one-time choice in the Start run dialog. It is
> **request-scoped**: the client submits only a user-visible path
> (`destinationPath`) on `POST /api/v1/templates/{id}/runs`; the identity is
> captured server-side from the authenticated starter's own view and **never
> written back** to the template or administration setting. Any client-supplied
> storage/file id is ignored. When no path is submitted the request is unchanged
> and the template/administration/default path applies.
>
> In every case the chooser submits only a user-visible **path**; identity is
> captured server-side from the chooser's view. At run start the effective
> reference is re-resolved in the **run owner's** view and frozen with
> `destination_source = runtime|template|admin|default` and the matching
> `destination_configured_by`. An explicit run-time choice is authoritative and
> fails closed (no fall-through) when it cannot be resolved. The implemented
> precedence is **run-time (#49) > template (#48) > administration (#47) >
> default `Files/Runbook` (#46)**.


- The **chooser's descriptor fields are not stored** as authoritative (the chooser
  and the run owner can see the same shared folder through different mounts with
  different descriptor values). The chooser's own view is used only to pick the
  node at configuration time; `destination_configured_by` records who chose it.
- `viewUid` is **not** stored: it is always the **run owner** at run start (§1/§6).
- At run start the reference is resolved in the **run owner's** view (§2.2) by
  `file_id` + exact `storage_id`; on success the **run owner's** descriptor fields
  are recorded as metadata (§4.3). The chooser's fields are never compared to the
  run owner's, and the descriptor fields are not identity.

### 4.3 Frozen run destination and managed subfolder

On `runbook_runs` (#46), the frozen **run-owner-resolved** destination:
`destination_view_uid` (the run owner), `destination_storage_id`,
`destination_file_id`, `destination_storage_root_id`, `destination_mount_type`,
`destination_mount_provider`, `destination_mount_id` (int, nullable),
`destination_numeric_storage_id` (int, nullable), `destination_path` (advisory),
`destination_source` (`runtime|template|admin|default`),
`destination_configured_by`, plus the managed folder identity
`run_folder_file_id`, `run_folder_storage_id`, `run_folder_storage_root_id`,
`run_folder_mount_type`, `run_folder_mount_provider`, `run_folder_mount_id`
(nullable), `run_folder_numeric_storage_id` (nullable), `run_folder_path`, and a
`destination_migrated_at` / `migration_state` marker used only by the #55 legacy
migration.

The run destination is **frozen at start** (or, for legacy runs, **frozen when
#55 first migrates that run**, §8) by resolving the node **in the run owner's
view** and recording its identity `(viewUid, storageId, fileId)` plus the
descriptor fields as **metadata for audit/display only** (they are not an
identity or uniqueness key; §2.1.1). Later changes to the admin/template setting
never alter an existing run. `destination_source NULL` + all coordinates NULL +
`destination_migrated_at NULL` = legacy (§1.1); a run with a non-null
`destination_migrated_at` must have a complete, valid destination.

### 4.4 Attachment file identity (#50, backfilled by #55)

On `runbook_attachments`: `storage_kind` (`appdata|files`), `file_id`,
`storage_id`, `storage_root_id`, `mount_type`, `mount_provider`, `mount_id`
(nullable), `numeric_storage_id` (nullable), `path`, plus a derived `file_state`
(`present|missing|out_of_scope|unavailable`, not necessarily persisted; computed
by reconciliation in #52). Legacy `storage_key` is retained for `appdata` rows
until #55 removes it. Stable identity is `(view_uid = run owner, storage_id,
file_id)` compared exactly; `storage_root_id`, `mount_type`, `mount_provider`,
`mount_id`, `numeric_storage_id` and `path` are descriptive metadata (§2.1.1),
never identity, dedup or tie-breakers.

> **Implementation status (#50).** The Files attachment columns exist and new
> evidence is written to the run's managed folder in Nextcloud Files:
> `storage_kind='files'`, identity `(storage_id, file_id)`, plus the descriptive
> metadata and advisory `path`. Uploads resolve the managed folder in the **run
> owner's** view by exact identity and fail closed (`destination_unavailable`)
> when it is missing/ambiguous/unwritable; the folder is never silently
> recreated and AppData is never used as a fallback. Uploads use a unique
> physical name (`getNonExistingName`) and reject the reserved ownership marker
> name. Downloads and deletes resolve the tracked file by exact identity and
> verify it is still inside the run-managed folder (`attachment_out_of_scope`
> otherwise, with no bytes returned). **New uploads never use AppData:** a
> legacy run (started before the Files milestone, with no frozen run-managed
> folder) has no managed folder and its uploads fail closed
> (`destination_unavailable`) until it is migrated (#55); a partially populated
> or corrupt destination identity is invalid (`destination_invalid_config`), never
> treated as legacy. Existing AppData evidence rows remain readable/deletable,
> are never extended, and are migrated to Files by #55. Out-of-band
> reconciliation and `file_state` are implemented by #52 (§7/§7.1); safe
> pre-flight run-deletion with the preserve/blocked cleanup policy is implemented
> by #54 (§8).

### 4.5 Existing rows and import/export

- **Existing rows:** `storage_kind='appdata'`, `file_id=NULL`; fully functional
  and readable until migrated by #55.
- **Existing templates:** destination columns default `NULL` → behave as before.
- **Portable `schemaVersion 1` exports carry no instance Files identifiers**
  (`viewUid`, `storageId`, `fileId`). Proposed optional advisory
  `template.folderHint` (a user-relative display path) is a **hint only**,
  re-resolved for the importing user; if it does not resolve to a writable
  folder the import stores no destination (`NULL`). `format`/`schemaVersion`
  stay unchanged.

## 5. Lifecycle and failure semantics

Database and Files operations have **no shared transaction**; the design is
validate-first, fail-closed and compensating.

Run start (`RunService::startRun`), in order:

1. **Resolve** the destination by §1 **in the run owner's view** (no writes). On
   failure: precise error, no run row. A run-time override (#49) supplied as a
   `destinationPath` on the start request is captured from the authenticated
   starter's own view (the starter is the run owner) and takes precedence; a
   template/administration reference is re-resolved from `(storage_id, file_id)`
   in the run owner's view and the chooser's fields are ignored. Resolution must
   yield exactly one in-scope candidate or fail closed with
   `destination_ambiguous`. An explicit run-time choice never falls through.
2. **Prepare** the managed subfolder *before* inserting the run, named from the
   run `uuid`; under the per-run lock, create the folder and write its ownership
   marker (`.runbook-run.json`, full run UUID) through the public Files API. An
   existing folder is reused **only** when its marker proves ownership of this
   run; otherwise fail closed with `destination_ownership_conflict`. Capture the
   folder identity (`fileId`, `storageId`) and descriptor fields for
   audit/display.
3. **Insert** the run and snapshot inside the existing `TransactionRunner`,
   writing the frozen **identity** `(destination_view_uid, destination_storage_id,
   destination_file_id)` plus the descriptor metadata (§4.3) and the managed
   folder identity captured in step 2 — never the chooser's fields.
4. **Compensate** on insert failure by **preserving** the folder this attempt
   created (issues #46/#54): Runbook never deletes the managed folder, because the
   public Files API only offers recursive `Folder::delete()` and a delete after an
   emptiness check can destroy a file a user adds in between. The folder (with its
   owned marker and all content) is left in place as an **orphan requiring manual
   cleanup**: because every `startRun()` gets a fresh UUID and the folder name
   embeds it, a later start creates a new folder and never adopts the preserved
   one. No folder is ever removed and no content is deleted.

### 5.1 Ownership marker lifecycle (constraints for #50/#52/#54)

The marker (`.runbook-run.json`, §3) is **reserved Runbook metadata**, not user
content. Constraints for the later attachment and deletion work:

- It is created and read only through the public OCP Files API
  (`Folder::newFile()` / `Folder::get()` / `File::getContent()`); Runbook never
  accesses a storage path directly. This keeps it working across mounts and
  object stores.
- It is **never** counted or surfaced as evidence: attachment listing,
  required-FILE completion and evidence download must exclude it (it is inside
  the run-managed scope, but it is not a tracked attachment).
- Reconciliation (#52) must not report it as an unknown/out-of-band file; it is
  expected run-managed content. If it is deleted or renamed out of band, the run
  folder becomes **unowned**: it is not regenerated silently, and any future
  recovery/adoption fails closed with `destination_ownership_conflict`.
- Neither run deletion (#54) nor compensation (#46) removes the managed folder,
  so the marker is never deleted together with the folder: the folder and its
  marker are preserved. The marker is deleted on its own **only** by the failed
  marker-write path, and only when its content proves this run's UUID.
- The marker does **not** participate in identity: the authoritative folder
  identity remains `viewUid + (storageId, fileId)` (§4.1); the marker only
  answers "was this folder created for this run?".
- **Failed marker write (fail-closed, non-recursive):** because the new folder is
  already visible in Files, a marker-write failure never deletes it (recursive
  `Folder::delete()` would race with a user write, issues #46/#54). Runbook
  removes only the exact valid marker this attempt wrote (a single, non-recursive
  file delete); a partial/foreign/unreadable marker or any other content is left
  untouched, and the folder itself is always preserved for manual cleanup. The
  original (mapped) marker-write error is always reported; cleanup failures are
  logged and never mask it.

### 5.2 Missing managed run folder — fail closed (single rule)

There is **one** behaviour: if the managed run folder is missing at upload time,
the operation **fails closed** with `destination_unavailable`. Uploads do **not**
silently recreate the folder, because a silent re-creation would mint a new
folder identity, orphan existing attachment identities, and make deletion
unsafe. Until the explicit recovery workflow exists, the UI only *reports* the
loss (it must not promise a replacement upload will succeed); nothing is created
without an explicit user action.

**Explicit recovery workflow (planned; the #52 reconciliation detects and reports
the loss but does not create a replacement folder yet):**

1. Re-resolve the frozen base folder by identity in the run owner's view (§2.2).
   If the base folder is also gone, the destination must be re-selected
   (§1) — the run is not silently repointed.
2. Present the run's attachments and their computed `file_state`. The owner
   confirms an explicit **repair** action.
3. Create a new managed subfolder under the base folder under the concurrency
   lock, and update the managed-folder identity (`run_folder_file_id`,
   `run_folder_storage_id`, `run_folder_storage_root_id`, `run_folder_mount_type`,
   `run_folder_mount_provider`, `run_folder_mount_id`,
   `run_folder_numeric_storage_id`) to the **new** owner-resolved context,
   recording the old identity in activity/history for audit.
4. Attachments whose files still exist at their recorded identity are
   re-associated only if they are inside the new folder; otherwise they are
   `missing`/`out_of_scope` and must be re-uploaded explicitly. A required FILE
   step then needs a new present attachment to complete (§7).
5. Never copy or move unrelated/untracked files; never delete the base folder.

## 6. Authorization and visibility (0.4.0 baseline and implemented 0.5.0 behaviour)

**Current behaviour (0.4.0, unchanged by #45):**

| Actor | Current Runbook behaviour |
| ----- | ------------------------- |
| Run owner | full run control; view/execute/complete/cancel/reopen; manage participants; delete |
| Participant / assigned user | view the run and execute only their steps; cannot manage or delete |
| Viewer | read-only run; no execution |
| Administrator | **no implicit view/execute/participate**; but administrators **do currently have a delete privilege** for runs (`RunService` sets `canDelete` for owner or admin, and `deleteRun` allows an admin) and for templates (`TemplateService`). This is existing behaviour, not changed by this contract |

So "administrators have no implicit access" is accurate for **content
access/execution**, but **not** for **deletion**: the current delete privilege is
real and is described here as existing behaviour. Any change to it is a separate
product/authorization decision and is **not** made by #45 (documentation does not
change authorization).

**Implemented 0.5.0 Files interaction (#51):**

- Runbook ACL governs Runbook features; Files access is Nextcloud's own
  ownership/mount/share model. Neither implies the other.
- **Single resolution user: the run owner (`viewUid`).** All run destination
  operations — validation at run start, uploads, downloads and the #55 migration
  — resolve the node in the **run owner's** Files view (§1/§2.2). A destination
  configured by an administrator or template owner is a *reference* recorded with
  `configured_by`; it must resolve in the run owner's view, and if it does not
  the operation fails closed. Runbook never switches to the chooser's view or
  falls back to another user's Files view. (At run start the initiator *is* the
  run owner, so a run-time override is chosen in the same view.)
- **The uploader does not need Files access to the destination** (decision for
  the former open question §11.1): uploads are written server-side into the run
  owner's managed folder, so an executor only needs Runbook execute permission on
  the step plus a writable run-managed node in the run owner's view. The uploader
  is recorded (`uploader_uid`).
- Downloads are served through the Runbook attachment endpoint gated by Runbook
  run access (viewers included); the downloader needs no Files access to the
  destination.
- **No automatic public shares** and no implicit Nextcloud share creation.

Attachment permission matrix (enforced server-side; ACTIVE run required for
upload/delete):

| Actor | Upload | Download | Delete |
| ----- | ------ | -------- | ------ |
| Run owner | any step | yes | any attachment |
| Step assignee (user or group) | only their assigned steps | yes | their own uploads |
| ACL participant without step assignment | no | yes | only uploads they made (never another user's) |
| ACL viewer | no | yes | no |
| Unrelated user | no (reported as not found) | no (reported as not found) | no (reported as not found) |

Owners additionally bypass step assignment for execution/upload, and deletion is
blocked for the last evidence of a completed required `FILE` step (§7.1). A step
in a blocked/inapplicable section cannot be executed or uploaded to.

## 7. Out-of-band changes in Files

| Change | Effect |
| ------ | ------ |
| Rename of the managed folder or of a tracked file (same storage, still in scope) | `fileId` unchanged; resolve by identity; refresh cached `path` |
| Move of a tracked file **within** the managed folder (same storage) | still managed; `fileId` unchanged; refresh `path` |
| Move of a tracked file **outside** the managed folder | **no longer managed evidence**: detected by the scope check (§3); `file_state = out_of_scope`; it does not satisfy a new required FILE completion, is **not** deleted with the run, and is surfaced to the user |
| Move to another storage | `storageId` mismatch → `unavailable`; require re-selection |
| Managed subfolder deleted | attachments become `missing`; uploads fail closed (§5.2); the run is not destroyed |
| Single tracked file deleted | that attachment is `missing`; listed as such; download errors |
| Owner's Files access to the configured base revoked | run start fails `destination_no_access`; existing runs keep their frozen identity but uploads fail |
| External mount unavailable | `destination_unavailable` (retryable); no fallback |

### 7.1 Missing/inaccessible/out-of-scope evidence and FILE steps (implemented by #52)

> **Implementation status (#52).** Tracked Files evidence is reconciled on every
> listing, download, delete and FILE completion against the run-managed scope
> (§2.2/§3) and exposed as `file_state`:
> - **Rename / move within the run-managed folder** — still `present`; the
>   advisory `path`/descriptor metadata is refreshed.
> - **Move outside the run-managed folder** — `out_of_scope`; never usable as
>   new evidence, never deleted with the run, and download/delete fail closed
>   (`attachment_out_of_scope`).
> - **Move to another storage** — `unavailable` (never matched by descriptor).
> - **File or managed subfolder deleted** — `missing`; download fails
>   (`attachment_missing`) and the folder is never silently recreated.
> - **Ambiguous / incomplete identity / storage or mount unavailable** —
>   `unavailable`; fail closed (`destination_unavailable`).
> - **Legacy AppData rows** - reported `present` (compatibility; migrated to
>   Files by #55, but still `present` until individually migrated).
>
> A required FILE step can be newly completed only with at least one `present`
> attachment for that exact step/run; otherwise completion is rejected
> (`file_evidence_missing`). Completed steps are never rewritten: a completed
> step whose evidence later degrades **stays completed**, and the run detail
> reports `evidenceDegraded` for a data-integrity notice. Only still-pending /
> in-progress required FILE steps block run completion. Deleting the last
> `present` attachment of a completed required FILE step stays blocked
> (`last_file_evidence_required`); when the managed folder is still available,
> uploading a replacement restores completion (a missing/unavailable folder must
> be repaired first — not implemented in 0.5.0).
>
> **Not yet implemented:** the explicit **folder-repair** action of §5.2 (steps
> 1–4: re-create a managed subfolder and update the frozen `run_folder_*`
> identity). The two loss cases are distinct: a **missing evidence file** whose
> managed folder still exists can be replaced by uploading a new file (the step
> may then be completed); a **missing managed folder** cannot receive new uploads
> at all — the upload fails closed (`destination_unavailable`), no folder is
> silently recreated and the frozen identity is never changed, until the explicit
> repair action is implemented.
>
> The run-level degraded notice is **state-aware**: it uses the reconciled
> `file_state` values plus the run-managed folder availability
> (`managedFolderState`: `available` | `missing` | `unavailable` |
> `not_applicable`) so it never promises an upload that would fail closed. A
> **missing evidence file with the managed folder available** is the only case
> that offers a replacement upload; a **missing/unavailable managed folder**
> reports that uploads are blocked until repair; `out_of_scope`/`unavailable`
> files explain the actual next step instead of promising a replacement.


- A required FILE step can be **newly completed** only when it has at least one
  attachment whose current `file_state` is `present` **and** in managed scope for
  that exact step and run. Missing, inaccessible, `unavailable` or
  `out_of_scope` evidence **cannot** satisfy a new completion; completion is
  rejected with `file_evidence_missing` (and the UI explains which file is
  unavailable; it offers a replacement upload only when the managed folder is
  still resolvable and writable).
- **Historical/completed steps are not rewritten.** If a step was already
  completed and its evidence is later deleted/moved/revoked, the step **stays
  completed**; the run is flagged with a data-integrity notice
  (`run_evidence_degraded`) and the affected attachment is shown as degraded
  (`missing`/`out_of_scope`/`unavailable`).
- **Run completion validation:** only still-`PENDING`/`IN_PROGRESS` required FILE
  steps block completion. A previously completed step with degraded evidence does
  **not** retroactively block the run, but the degradation is reported.
- **Repair/re-upload path:** when the run-managed folder still exists, the user
  uploads a replacement attachment (a new, `present` managed file); the step may
  then be completed or re-completed according to the normal step-transition
  rules. When the folder is missing/unavailable this upload fails closed
  (`destination_unavailable`) and the folder-repair action of §5.2 (not yet
  implemented) is required first. Deletion of the last `present` attachment of a
  completed required FILE step remains blocked as in 0.4.0.

### 7.2 Attaching a copy of an existing Files item (implemented by #53)

> **Implementation status (#53).** An authorised run user can select an existing
> file in **their own** Nextcloud Files and attach a **copy** of it as evidence
> to an eligible step; the original is never moved, renamed, overwritten or
> deleted.
>
> - **Action:** `POST /api/v1/run-steps/{id}/attachments/copy` with
>   `{"sourcePath": "<user-visible path>"}`. The path is an **advisory locator
>   only**; any client-supplied storage/file id or descriptor is ignored.
> - **Authorization (#51):** the step is resolved with
>   `RunStepService::requireExecutableStep()` — the acting user must be able to
>   view the run, the run must be `ACTIVE`, the user must be able to execute the
>   step (owner or assignee), and the section must not be blocked/inapplicable.
>   The uploader needs no Files access to the destination (owner-side write).
> - **Source resolution:** the path is resolved in the **acting user's** view and
>   re-verified by exact `(storageId, fileId)`; exactly one same-storage candidate
>   is required, and `READ` permission is enforced. A missing, unreadable,
>   ambiguous, non-file or invalid source fails closed (`attachment_source_missing`
>   / `attachment_source_no_access` / `attachment_source_ambiguous` /
>   `attachment_source_invalid`) and creates no attachment.
> - **Destination resolution:** the frozen run-managed folder is resolved in the
>   **run owner's** view by exact identity and must be writable; a
>   missing/ambiguous/unwritable destination fails closed with no fallback
>   (never AppData, never another folder).
> - **Copy semantics:** bytes are read from the source and written into the
>   managed folder under a safe, collision-free name (`getNonExistingName`,
>   `attachment_name_collision` after retries). The persisted attachment stores
>   the **destination** node's server-resolved identity/descriptor, so it behaves
>   exactly like an uploaded attachment (completion, reconciliation, download,
>   delete and last-evidence protections all apply unchanged). The source is
>   never deleted; if metadata persistence fails, only the file created by this
>   attempt is removed.

## 8. Safe deletion boundaries and migration contract

**Deleting a run (#54) — delete-or-block, never orphan:**

The policy is **pre-flight and fail closed for files, with a durable cleanup
record for the empty folder**. A run's database rows are **never** removed while
any in-scope tracked file still exists and could not be deleted; the attachment
rows are the tracking metadata that makes a retry possible, so they are retained.

1. Collect the run's tracked attachment identities (`storage_kind=files`).
2. **Pre-flight every in-scope `present` tracked file**: resolve **its own** node
   in the run owner's view (§2.2) and require **that file node's**
   `PERMISSION_DELETE` / `isDeletable()`. Never infer a child's deletability from
   the parent folder. If any file fails pre-flight (permission denial, unavailable
   mount, ambiguous resolution), **abort the whole run deletion before touching
   any database row** (`run_delete_blocked`, with the failing file(s) reported).
   No file has been deleted and the run, its attachments and their identities are
   intact for retry.
3. Delete the pre-flighted, in-scope `present` files by their own identity. If a
   deletion fails mid-way (race, mount goes away), **abort before removing any
   run database row**, keep the attachment rows, mark the successfully deleted
   files as `missing`/deleted, and report the remaining file(s); retrying skips
   already-deleted files (idempotent). `missing` files need no deletion.
   `out_of_scope` nodes are **not** managed: they are never deleted and are
   documented as left untouched; their rows carry no further ownership of the
   node.
4. **Persist the cleanup intent and delete the run rows atomically.** When every
   in-scope tracked file is gone (deleted or already `missing`), open a single
   database transaction (`TransactionRunner`) and, in that same transaction:
   1. insert the **`runbook_files_cleanup`** row for the managed folder with
      `status = pending` and the full owner-resolved identity (§8.2); then
   2. delete the run's rows (cascade as today).
   Commit. If the transaction fails, **nothing is committed**: the run and its
   attachment rows remain and no cleanup record exists — the deletion can simply
   be retried. A crash can therefore never leave a managed folder without either
   its run or a durable cleanup record.
5. **After the commit**, resolve the managed subfolder by exact identity and
   decide its outcome — **without ever deleting it recursively.**
   - The public Nextcloud Files API exposes **no non-recursive/conditional
     empty-folder deletion**: `Folder::delete()` is recursive
     (`View::rmdir()` → `Storage\Local::rmdir()`, which removes every child with
     a `RecursiveIteratorIterator`), so a check-then-delete can destroy a file a
     user adds in between. The lower-level `IStorage::rmdir()` primitive is
     non-recursive (PHP `rmdir` semantics) but bypasses the Files cache, hooks and
     locking, so it is **not** a safe substitute.
   - Consequently a folder that still exists is **preserved** and the record is
     finalized `blocked`: `reason = not_empty` when untracked content is present,
     otherwise `reason = removal_unsupported` (Runbook cannot remove an
     empty/marker-only folder safely).
   - A folder that is already gone closes the record (`done`, i.e. the row is
     deleted) — idempotent crash/parallel recovery.
   - A mount/owner failure that prevents resolution keeps the record `pending`
     for retry; an ambiguous identity is `blocked`.
   - The base folder is never touched and nothing is ever deleted recursively to
     make content disappear.
6. Retry rules for the cleanup record:
   - **Folder already gone**: the retry resolves by identity, finds nothing and
     closes the record.
   - **Transaction failed**: no record and no run-row deletion; retrying the run
     deletion repeats step 4.
   - **Folder still present**: the folder is preserved (never recursively
     deleted). Retries re-resolve by identity and re-affirm the `blocked`
     `removal_unsupported`/`not_empty` outcome; they never act on the tree.
7. Never delete an **original**: uploads copy content into the managed folder.

> **Implementation status (#54).** `RunService::deleteRun` now follows the
> delete-or-block policy:
> - Every Files-backed attachment is resolved by exact identity in the run
>   owner's view **on its own node**. A present, in-scope file that denies
>   `isDeletable()`/`PERMISSION_DELETE`, or is `unavailable`, aborts the whole
>   deletion (`run_delete_blocked`) before any database row is touched; the run,
>   its attachment rows and all identities stay intact for retry. A file whose
>   delete capability cannot be probed (unreachable storage, race deletion,
>   unexpected Files/DB error) is treated as `unavailable` for the same abort,
>   so an unexpected Files error is never served as a generic 500. `missing`
>   files are treated as gone; `out_of_scope` files are never deleted and never
>   block.
> - Present, in-scope files are then deleted by their own node; a mid-way failure
>   aborts before the database transaction (already-deleted files are reported
>   `missing` on retry and skipped).
> - In **one transaction** the `runbook_files_cleanup` row is inserted **first**
>   (complete owner-resolved folder identity, `status = pending`) and only then
>   the run rows are deleted. If the transaction fails, nothing is committed: the
>   run and attachment rows remain and no cleanup record exists.
> - After commit the managed folder is resolved by exact identity and, because
>   the public API only offers **recursive** deletion, it is **never deleted**:
>   an existing folder is preserved and the record is finalized `blocked`
>   (`reason = not_empty` with untracked content, otherwise
>   `reason = removal_unsupported`); a mount/owner failure stays `pending`. The
>   base folder is never touched and nothing is deleted recursively, so a file a
>   user adds after the emptiness check can never be destroyed.
> - `runbook_files_cleanup` is retried by the hourly `FilesCleanupRetryJob` (and
>   by `FilesCleanupService::processPending()`), always re-resolving by
>   `(view_uid, storage_id, file_id)`. A folder already gone closes the record
>   (idempotent crash recovery); a preserved folder keeps a `blocked` record for
>   review.
> - Legacy `storage_kind='appdata'` evidence is removed exactly as before; #54
>   neither migrates it nor introduces new AppData writes.

### 8.2 Durable cleanup records (implemented by #54)

A managed folder may survive the run (empty but not removable, mount unavailable,
or holding untracked files), and after the run rows are gone no attachment row
tracks it, so it is recorded instead of lost:

`runbook_files_cleanup`: `id`, `kind` (`folder`), `status`
(`pending|done|blocked`), `view_uid`, `storage_id`, `file_id` (**identity**),
plus descriptor metadata `storage_root_id`, `mount_type`, `mount_provider`,
`mount_id` (int, nullable), `numeric_storage_id` (int, nullable),
`mount_point`/`path` (advisory), and `reason`, `attempts`, `last_attempt_at`,
`created_at`. Retry re-resolves by the identity `(view_uid, storage_id, file_id)`
in the run owner's view; the descriptor fields are for audit/display only
(§2.1.1).

It is written **in the same transaction as the run-row deletion** (§8.1 step 4),
so the intent is durable before the run disappears. A background job retries in
the run **owner's** view and closes the record only when the folder is already
gone; otherwise the folder is preserved and the record is finalized `blocked`
(`reason = not_empty` or `removal_unsupported`) for human review, because the
public Files API cannot delete it non-recursively (§8 step 5). This is the
**only** place a tombstone is needed: tracked files always block run deletion
until resolved, so their identity is never dropped while the file still exists.

### 8.1 Legacy AppData migration (#55)

Legacy runs have **no frozen destination and no managed-folder identity**, so the
migration must first *establish* one, then migrate the bytes.

**Destination resolution for a legacy run** (run-time override does not exist for
historical runs; evaluate the remaining levels in order, first **supplied** level
is authoritative):

1. **Template setting** of the run's source template, if the template still
   exists and the setting is supplied.
2. **Global administration setting**, if supplied.
3. **Default `Files/Runbook`** in the run owner's view (create if absent).

For the first supplied level, the same fail-closed rules as §1 apply. On success
the migration **freezes** the destination on the run exactly as a new run would
(writing `destination_*`, `destination_source = template|admin|default`, and
`destination_migrated_at`), and creates/reuses the managed per-run subfolder
(recording `run_folder_file_id`). If the resolved level is invalid, inaccessible,
incomplete or unavailable:

- **Do not fall back** to a lower level, to AppData, or to another user's folder.
- **Skip and block that run's migration** (`migration_state = blocked`), leave the
  run's destination columns unset, keep every AppData attachment intact and
  usable, and surface a clear error to the administrator/owner. The run can be
  retried after the configuration is corrected.
- Legacy evidence is **never deleted** while migration for that run is blocked.

**Per-attachment flow (idempotent, resumable):** once the run has a frozen
destination and managed folder:

1. Resolve the frozen managed subfolder by identity (create only if a prior
   attempt is resuming and the folder is provably the run's own; otherwise treat
   as missing and block, per §5.2).
2. Read the AppData bytes.
3. Copy into the managed folder under a safe name.
4. Verify size and `sha256` against the recorded metadata.
5. Update the attachment row (`storage_kind='files'`, `storage_id`, `file_id`,
   `storage_root_id`, `mount_type`, `mount_provider`, `mount_id`,
   `numeric_storage_id`, `path`) with the **run owner's** resolved context.
6. Delete the AppData file **only after** the verified update.

Each step is recorded (`migration_state` per run/attachment); re-running skips
already-completed items, so an interrupted migration resumes safely. The
milestone is **not accepted while any active attachment has
`storage_kind='appdata'`**.

> **Implementation status (#55).** Legacy AppData evidence is migrated into the
> run-managed Files folder by `LegacyMigrationService` (admin endpoints
> `GET/POST /api/v1/admin/migration`, plus the hourly `LegacyMigrationJob`).
>
> - **Precedence:** template destination (of the run's source template, if it
>   still exists) → global administration destination → default `Files/Runbook`,
>   resolved in the **run owner's** view. An already-frozen destination is reused.
>   A supplied but invalid/inaccessible/incomplete level is authoritative and
>   blocks the run (`destination_*` reason); it never falls through, never uses
>   another view and never stays on AppData by default.
> - **Freeze/state:** the destination is frozen on the run (`destination_*`,
>   `destination_source`, `destination_migrated_at`) and the managed folder is
>   created/reused only with a matching ownership marker. Run state is
>   `migration_state = pending|blocked|done` with `migration_reason`; attachment
>   state is `pending|blocked|done` with its own reason. A corrupt run
>   (coordinates set while the source is NULL, or vice versa) fails closed with
>   `destination_invalid_config`.
> - **Per attachment (safe order):** read AppData → copy under a
>   **deterministic** collision-free name (`<name> (<attachment uuid>)`) →
>   verify byte size + SHA-256 against recorded metadata → update the row to
>   `storage_kind='files'` with the owner-resolved identity/descriptors → delete
>   the AppData source only after the metadata update. Because the target name is
>   deterministic, an interrupted copy is re-verified on retry instead of
>   duplicated.
> - **Failures:** any failure preserves the AppData source and records a reason
>   (`migration_source_missing`, `migration_source_unreadable`,
>   `migration_verify_failed`, `migration_target_conflict`,
>   `migration_metadata_failed`, `migration_copy_failed`). No recursive folder
>   deletion and no AppData writes are introduced. Existing attachment
>   authorization is unchanged and AppData evidence stays readable until
>   individually migrated.
> - **Bounded batches / fairness:** each batch is selected with a durable
>   **rotating keyset cursor** over the ascending candidate run-id order: it
>   starts after the last selected run id and wraps to the beginning at the end,
>   so every candidate is selected within one rotation regardless of equal or
>   NULL `migration_attempted_at` timestamps. Blocked runs stay in the candidate
>   set and are retried on the next rotation once their cause is fixed. The
>   hourly job and the manual admin trigger share this ordering.
> - **Concurrency:** because a batch can wrap and re-select the same runs, the
>   whole selection **and** processing is a single critical section guarded by an
>   exclusive `ILockingProvider` lock on the stable key
>   `runbook/legacy-migration`. A contended invocation returns
>   `{busy: true, processed: 0, ...}` without reading/advancing the cursor or
>   touching any migration record; the lock is released on every success and
>   exception path. This coordinates separate PHP workers that share the same
>   locking backend; it does **not** protect against a backend without shared
>   locks.
> - **Residual AppData cleanup (distinct from incomplete migration):** when the
>   Files copy is verified and persisted but deleting the AppData source fails,
>   the attachment stays `storage_kind='files'` and is marked
>   `migration_source_delete_failed` (its `storage_key` is retained). This is a
>   **cleanup** task, not an incomplete migration: the run is `done`, and the
>   status endpoint reports it separately as
>   `residualCleanupAttachments` / `cleanup` — distinct from
>   `remainingAppDataAttachments` / `runs` (the active AppData dependency). The
>   cleanup is retried safely on subsequent batches (deleting only the exact
>   recorded AppData key; never the verified Files copy) and can also be handled
>   manually.

## 9. Dependency map and contract acceptance criteria

| Contract § | Capability | Issue |
| ---------- | ---------- | ----- |
| §1, §3, §4.3 | Default `Files/Runbook` folder, schema/entities, frozen destination | #46 |
| §1, §4.2 | Global administration destination setting (implemented) | #47 |
| §1, §4.2 | Template destination setting (implemented) | #48 |
| §1, §4.2 | Run-time destination override (start-run payload/UI) (implemented) | #49 |
| §4.4, §5, §2.2, §4.1 | Attachment storage in Files + file/mount identity resolution (implemented) | #50 |
| §6 | Participant/assignee upload & download permissions (implemented) | #51 |
| §7, §2.2 | Out-of-band Files reconciliation (rename/move/delete/revoke) (implemented) | #52 |
| §7.1, §8 | Opening/copying existing Files into a run (implemented) | #53 |
| §8, §8.2 | Safe deletion boundaries + durable cleanup records (implemented) | #54 |
| §8 | Legacy AppData migration (implemented) | #55 |
| all | Documentation, tests, hardening, packaging | #56 |

Contract acceptance criteria (**not yet executed**: these are the scenarios the
manual production acceptance run in
[`manual-acceptance-checklist.md`](manual-acceptance-checklist.md) §8 must observe
on a real Nextcloud instance; the automated suite only exercises them against
in-memory doubles):

| Scenario | Expected |
| -------- | -------- |
| Owner starts a run, nothing configured | `Files/Runbook` + per-run subfolder created; run frozen (`source=default`) |
| Precedence | runtime > template > admin > default, verified per level |
| Higher level supplied but invalid/incomplete | fail closed with a level-specific error; **no fallback** |
| Valid run-time override of an invalid suggestion | accepted; frozen to the override (`source=runtime`) |
| Admin folder shared to owner (owner's view) | resolves via `getById` candidate selection in the owner's view; writable check passes |
| Admin folder not shared to owner | `destination_no_access`; admin's own visibility is irrelevant |
| Same `fileId` reachable through two mounts | **no tuple-based dedup**: two or more in-scope candidates → `destination_ambiguous` (even with identical permissions); never arbitrary |
| Two distinct mounts expose **identical** `storageRootId`/`mountType`/`mountProvider`/`numericStorageId`/`mountId` values | treated as ambiguous (`destination_ambiguous`); equal descriptive values are not assumed to mean the same mount |
| Exactly one in-scope candidate, descriptor fields irrelevant | resolution succeeds; descriptor fields are recorded for audit/display only |
| `mountId` is `null` for the candidates | not used as a discriminator; if any other in-scope candidate exists → `destination_ambiguous`; a single in-scope candidate resolves normally |
| `numericStorageId` is `null` (and/or `mountId` null) | same rule; nulls are not treated as "distinct" or "equal" to select a candidate |
| Admin/template chooser sees the folder through a different mount than the run owner | chooser's descriptor fields stored for audit only and **not compared**; the reference re-resolves in the run owner's view |
| Owner-visible resolution yields multiple in-scope candidates | `destination_ambiguous`; no first-candidate pick and no user switch |
| A view query and a parent-scoped query would return the same node | one canonical source is used per resolution, so the node is counted once; no false ambiguity from overlapping queries |
| Ambiguous folder re-selected through the same reference | still ambiguous (the mount choice is not stored identity); remedy is a folder that resolves to exactly one candidate or a single-mount sharing setup; still failed closed |
| Multi-user: participant uploads, owner sees file | file present in the frozen managed folder; uploader recorded (#51 decision) |
| Two runs started concurrently into the same base | two distinct managed subfolders; no collision |
| Two deletes of the same run concurrently | idempotent; base/other-run/untracked files untouched |
| Quota exhausted | `destination_quota_exceeded`; run not created |
| Mount unavailable at start | `destination_unavailable`; no run row |
| Managed folder deleted mid-run, then upload | fail closed `destination_unavailable`; **no silent recreate**; UI reports the loss but repair is not implemented (no upload promised) |
| Tracked file renamed/moved **within** the run-managed folder | still managed; resolved by `viewUid + (storage_id, file_id)`; `path` refreshed (descriptors are metadata) |
| Tracked file moved **outside** the run-managed folder | `out_of_scope`; not managed evidence; not deleted with the run; surfaced |
| Required FILE step; only evidence is missing/out-of-scope | new completion rejected (`file_evidence_missing`); replacement upload offered only when the managed folder is available, otherwise uploads stay blocked |
| Previously completed FILE step whose file is later deleted | step stays completed; run flagged `run_evidence_degraded`; not retroactively blocked |
| Delete a run with an untracked/manually added file in its folder | untracked file preserved; the managed folder is **not** deleted (recursively or otherwise) and the record is finalized `blocked`/`not_empty` for manual review |
| File in the **base scope** but outside the run-managed folder | never managed evidence for the run and never deleted with it |
| Delete run: a tracked file denies delete / mount unavailable (pre-flight) | whole deletion aborts with `run_delete_blocked`; **no DB rows removed**, no file deleted; retry after fix |
| Delete run: partial failure after some files deleted | abort before removing run DB rows; deleted files marked `missing`; remaining rows retained; idempotent retry |
| Delete run: files gone, managed folder still exists | the run-row deletion transaction writes the `runbook_files_cleanup` record **first**, then removes the run rows; the folder is **preserved** (never recursively deleted) and the record is finalized `blocked`/`removal_unsupported` |
| Delete run: crash after run rows removed but the folder still exists | the pre-committed cleanup record still exists; retry re-resolves the folder and finalizes the `blocked` outcome (or, if the folder is gone, closes the record) |
| Delete run: folder already gone before the retry | retry finds the folder missing, treats it as success, and closes the record |
| Delete run: the cleanup-record/run-row transaction fails | nothing is committed: run and attachment rows remain and no record exists; deletion is retried |
| Delete run: folder has untracked content | folder and untracked files preserved; record finalized `blocked`/`not_empty` for human review; a file added after the emptiness check is never deleted |
| Delete permission: file deletable, folder not (or vice versa) | the file's own node is checked before deletion; the folder is never deleted at all, so its permission only affects the (now moot) terminal state |
| `storage_id` full value differs only after the 64th character | exact string comparison detects the mismatch; no truncation or collision |
| Legacy AppData attachments before #55 | still listed/downloadable; never deleted by #46–#54 |
| Legacy run migration, configured level valid | reference re-resolved in the **run owner's view**; destination frozen to that level (`source=template|admin`) with the owner's descriptor metadata; managed folder created; bytes copied, verified, metadata updated, then AppData removed |
| Legacy run migration, no configured level | `Files/Runbook` created/frozen in the run owner's view (`source=default`); migration proceeds |
| Legacy run migration, configured level invalid/unavailable | run marked `migration_state=blocked`; **no fallback** (not to lower level, not to AppData); AppData evidence kept and usable; retry after fix |
| Migration interrupted mid-way | resumable: completed attachments/files skipped; re-run continues without duplicates |
| Migration failure on one attachment | that attachment stays `appdata`; run/others unaffected; idempotent re-run |
| Corrupt new run (coordinates set, source NULL, or vice versa) | `destination_invalid_config`; never treated as legacy, never silently defaulted |

## 10. Nextcloud API surface used by this contract (verified)

- `IRootFolder::getUserFolder($uid)` — the user's view root (own Files plus the
  shares/mounts available to them; not a universal root).
- `Folder::getById(int $id): Node[]` — the **canonical** candidate source
  (parent-scoped for a base/run-managed folder, or the view root). `IRootFolder::getByIdInPath(int $id, string $path): Node[]`
  also exists but is **not** combined with it (one canonical source per
  resolution; §2.2).
- `Folder::getFirstNodeById`/`IRootFolder::getFirstNodeByIdInPath` — **not** for
  authorization (unspecified node, possibly fewer permissions).
- `Folder::getOrCreateFolder(string $path, int $maxRetries = 5)` (`@since 33.0.0`),
  `Folder::getNonExistingName()`, `Folder::verifyPath()`, `Folder::isSubNode()`.
- `Node::getId(): int`, `Node::getStorage(): IStorage`,
  `IStorage::getId(): string` (no documented maximum length; §4.1),
  `Node::getPath()`, `Node::getInternalPath()`, `Node::getPermissions(): int`,
  `Node::isDeletable()`, `Node::isCreatable()`, with
  `OCP\Constants::PERMISSION_READ|CREATE|UPDATE|DELETE`.
- Mount context: `Node::getMountPoint(): IMountPoint` with
  `getMountId(): int|null` ("null if not applicable"), `getStorageId(): string|null`,
  `getNumericStorageId(): int|null`, `getStorageRootId(): int` (the storage root
  node id), `getMountType(): string`, `getMountProvider(): string`,
  `getMountPoint(): string`. These are **descriptive**: the interface does **not**
  guarantee that any tuple of them uniquely identifies a mount, so they are
  recorded for audit/display only and never used to deduplicate candidates or
  break ties (§2.1.1/§2.2). `getMountType()`/`getMountProvider()` are unbounded
  strings (no documented maximum; §4.1).
- Ownership (diagnostics only, not identity): `Node extends FileInfo`, so
  `FileInfo::getOwner(): ?\OCP\IUser` is available and may return **`null`**;
  `IStorage::getOwner(string $path): string|false` is also available and returns
  **`false`** (not `null`) when unknown. Neither disambiguates mounts (§2.1).
- `Folder::getFreeSpace()` / `NotEnoughSpaceException` for quota.
- `ILockingProvider` for coordination (already used for FILE-step evidence).
- Exception mapping targets: `NotFoundException`, `NotPermittedException`,
  `StorageNotAvailableException`, `NotEnoughSpaceException`,
  `AlreadyExistsException`, `InvalidPathException`, `FileNameTooLongException`,
  `ReservedWordException`.

## 11. Open product decisions

1. **Internal sharing:** should Runbook ever create an explicit Nextcloud share
   for the run folder? The default design does not (no implicit/public shares).
2. **UI granularity for degraded evidence:** how prominently to surface
   `run_evidence_degraded` and per-file `missing`/`out_of_scope` states.
3. **Per-run folder display-name format** (title + full uuid is the implemented
   deterministic scheme; an alternative suffix style could be revisited).
4. **Administrator delete privilege:** the current code lets administrators
   delete runs/templates; whether that should be restricted or extended to
   viewing is a product/authorization decision for a future issue (not #45).

> The former open question "must the participant uploader also hold Files
> access?" is **resolved by #51**: no — Runbook execute permission plus a writable
> run-managed node resolved in the run owner's view is sufficient (§6).

