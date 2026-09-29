# Release validation — 2026-09-29

This report records the evidence collected for the private 1.0.0 release
preparation. It is not an App Store approval or a substitute for the final
manual sign-off.

## Package

- Source branch: `main`
- Source commit before this report: `fe99441`
- Application version: `1.0.0`
- Declared Nextcloud range: `33–35`
- Declared PHP range: `8.2–8.5`
- The final archive SHA-256 must be recorded next to the release artifact after
  the last source change; hashes are intentionally not embedded in this
  packaged report because the report itself is part of the archive.

## Automated validation

- ESLint passed.
- Stylelint passed with no warnings.
- Vue/TypeScript typecheck passed.
- Frontend tests passed: 191 tests.
- PHPUnit passed: 929 tests and 28,748 assertions.
- PHP syntax lint, PHPStan, XML, JSON and package validation passed.
- Production archive validation passed; development-only files were excluded.
- Production dependency audit reported zero vulnerabilities.

## Real Nextcloud runtime smoke tests

The packaged app was installed into disposable Docker instances with SQLite:

- Nextcloud 33.0.9.1 / PHP 8.4: install, enable, page and authenticated status
  endpoint passed.
- Nextcloud 34.0.4.1: install, enable, page and authenticated status endpoint
  passed.
- Nextcloud 35.0.1.1: install, enable, page and authenticated status endpoint
  passed.

On Nextcloud 33, an authenticated API smoke flow also created a template,
created a section and step, published the template and started a run.

## Upgrade and uninstall observations

- A disposable Nextcloud 33 instance accepted the 0.5.0 package, then the
  1.0.0 package after `occ upgrade`; the app reported version `1.0.0` and the
  database upgrade completed without errors.
- Removing the enabled app removed its application files. The 13 Runbook
  database tables remained, matching the current documented Nextcloud removal
  behaviour, and reinstall/re-enable succeeded.
- Replacing 1.0.0 with 0.5.0 and running `occ upgrade` was accepted by this
  instance. The release must therefore document this as a tested, schema-safe
  downgrade for 1.0.0, or add an explicit downgrade policy before publication.

## Still blocking public/App Store release

- Full real-instance acceptance of Files mounts, permissions, sharing, jobs,
  migrations, notifications, localization and the browser UI.
- MySQL/MariaDB and PostgreSQL live acceptance; only SQLite was exercised here.
- A generated, complete OpenAPI contract. The current API uses legacy JSON
  controllers and hand-written routes; official extraction guidance expects an
  OCS/typed-controller and Psalm-compatible design.
- Compatibility decision for Nextcloud 36 / the App Store latest-plus-one rule.
- Official code-signing certificate and signed `appinfo/signature.json`.
- GitHub release and App Store submission.

No GitHub push or App Store action was performed from this validation run.
