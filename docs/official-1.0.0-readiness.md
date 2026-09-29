# Public 1.0.0 readiness

This is the internal milestone for making Runbook a public GitHub project and
preparing it for a future Nextcloud App Store submission.

Evidence from the current validation pass is recorded in
[`docs/release-validation-2026-09-29.md`](release-validation-2026-09-29.md).

## Completed in this milestone

- [x] Version aligned to `1.0.0` in `appinfo/info.xml` and `package.json`.
- [x] AGPL metadata uses the canonical `AGPL-3.0-or-later` identifier.
- [x] Public author contact, homepage and issue tracker added to `info.xml`.
- [x] Root `CHANGELOG.md` added for App Store/release visibility.
- [x] Root `CODE_OF_CONDUCT.md` added for public collaboration.
- [x] Node version pinned to the CI version through `.nvmrc`.
- [x] Stylelint configuration and CI validation added.
- [x] Existing package, test and security validation retained.

## Required before App Store submission

- [x] Confirm and test the declared runtime range: Nextcloud 33–35 was
      installed from the packaged build and passed the real-instance smoke
      checks; Nextcloud 36 remains pending until an image/release is available.
- [ ] Confirm that Nextcloud App Store approval accepts the declared range
      against its current latest-release-plus-one policy before submission.
- [ ] Add a complete OpenAPI contract for the public API. The current API uses
      legacy JSON controllers and hand-written `appinfo/routes.php`; official
      extraction requires an OCS/typed-controller decision, Psalm coverage and
      a generated spec that is checked for drift in CI.
- [ ] Run acceptance tests on a real supported Nextcloud instance, including
      Files mounts, permissions, migrations, background jobs and upgrades.
- [x] Verify on a disposable Nextcloud 33 instance that 0.5.0 → 1.0.0
      upgrade succeeds, uninstall removes app files while retaining the app
      tables, and re-enable succeeds. Downgrade was observed to be accepted by
      `occ upgrade`; this remains a documented compatibility decision, not an
      unqualified production guarantee.
- [ ] Request an app signing certificate and keep the private key outside Git.
- [ ] Build a clean release archive, sign it, and validate the archive rather
      than only the development checkout.
- [ ] Configure GitHub release automation only after the signing and App Store
      credentials are available as protected secrets.
- [ ] Submit the public repository and release to the Nextcloud App Store.

## Public GitHub release gate

- [ ] Confirm the GitHub repository URL and access rights.
- [ ] Push the existing history to GitHub without reinitialising the repository.
- [ ] Confirm CI passes on the GitHub default branch.
- [ ] Create and push tag `v1.0.0` only after the final release checks pass.
- [ ] Publish GitHub release notes from `CHANGELOG.md`.

The public GitHub source release and the Nextcloud App Store approval are
separate gates. Passing local CI does not prove real-instance compatibility or
App Store acceptance.
