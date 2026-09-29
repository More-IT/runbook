# Public 1.0.0 readiness

This is the internal milestone for making Runbook a public GitHub project and
preparing it for a future Nextcloud App Store submission.

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

- [ ] Confirm the supported Nextcloud range against the current App Store
      compatibility requirement and test every declared major version.
- [ ] Add a complete OpenAPI contract for the public API, or document and
      justify the chosen API documentation strategy.
- [ ] Run acceptance tests on a real supported Nextcloud instance, including
      Files mounts, permissions, migrations, background jobs and upgrades.
- [ ] Review uninstall, downgrade and upgrade behaviour on real instances.
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
