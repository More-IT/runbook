# Security Policy

## Supported versions

Runbook targets Nextcloud 33–35 on PHP 8.2–8.5. Security fixes are applied to
the latest release line.

## Reporting a vulnerability

Do not open a public issue for security problems. Report them privately to the
maintainers (More-IT) with:

- a description of the issue and its impact;
- steps to reproduce, including the affected endpoint or role;
- the Runbook, Nextcloud and PHP versions involved.

You will receive an acknowledgement and, where applicable, a coordinated
disclosure timeline.

## Security model

- Every API endpoint requires an authenticated Nextcloud session with CSRF
  protection; only the administration settings read/write endpoints are
  administrator-only.
- Authorization is enforced server-side in the service layer. The frontend only
  hides or disables controls and never grants access.
- Access is derived from ownership, template/run ACL entries and step
  assignments, resolved through Nextcloud users and groups. Runbook never
  creates or modifies users or groups.
- Inaccessible resources are reported as `404` where revealing existence would
  leak information, `403` for policy denials and `409` for state conflicts.
- User content is stored and returned as plain text and is never rendered as
  HTML.
- Imported template documents are treated as untrusted input: only the
  documented `schemaVersion` 1 fields are read (unknown fields are ignored),
  references, operators, values, conditions and assignees are validated through
  the same authoring rules, and any failure rolls the whole import back. Imports
  transfer no database ids, UUIDs, owner, status, ACL entries or run/file data;
  the new template is a DRAFT owned by the importing user, subject to the
  existing template-creation policy.
- Export requires the same view permission as reading the template; neither
  export nor import grants implicit administrator access. Administrators are not
  exempt from the template-creation policy unless that policy includes them.
- Evidence is stored in Nextcloud AppData under application-controlled paths.
  Storage keys and physical paths are never exposed through the API, and MIME
  type and size are validated from the actual file content.
- Notification payloads contain only translated subjects, run/step titles and
  deep links; comment bodies, storage keys and file contents are never included.
- The notification delivery ledger is append-only from the application's point
  of view; only administrators can change global feature settings.

See the "Security model" and "Authorization matrix" sections of `README.md` for
the full per-resource matrix.

## Dependency and build security

- CI runs `npm ci` and `composer install --prefer-dist` for a deterministic,
  lockfile-based install.
- Release artifacts must exclude development-only directories such as
  `node_modules/`, `vendor/`, `tests/`, `.github/` and `.git/`.
- `composer package:check` runs `build/validate-package.php` in repository mode:
  it verifies required files, generated assets and translation parity before a
  release, but does **not** inspect a package tree.
- The forbidden-file checks (no `.env`, log files, source maps or
  development-only directories) run only when validating a staging/release tree
  with `build/validate-package.php --package=<staging-directory>`, which
  `build/create-package.php` invokes automatically before archiving.

## Known limitations

There is no live Nextcloud end-to-end test environment in this repository.
Security behavior is verified through unit and regression tests against the
public Nextcloud OCP interfaces; real instance integration testing remains a
release-process responsibility.

The template import size limit is enforced on the canonical encoding of the
decoded document (2 MB); the raw request body is not measured by the app, so
whitespace padding is bounded only by the Nextcloud/PHP request limits, and the
browser's 32 MB source-file cap is a local memory guard, not a security control.
Template import/export has not been exercised on a real Nextcloud instance.
