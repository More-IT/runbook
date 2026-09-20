# Security Policy

## Supported versions

Runbook targets Nextcloud 33 on PHP 8.2 – 8.5. Security fixes are applied to the
latest release line.

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
- `composer package:check` validates that no `.env` files, log files or
  development-only artifacts are packaged.

## Known limitations

There is no live Nextcloud end-to-end test environment in this repository.
Security behavior is verified through unit and regression tests against the
public Nextcloud OCP interfaces; real instance integration testing remains a
release-process responsibility.
