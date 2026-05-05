# Admin Auto-Upgrade Design

## Goal

Mini S3 admins should be able to see when a newer official GitHub release is available and upgrade a release install from the admin dashboard with one click. The first version targets the current single-file release shape: each release zip contains a generated `index.php` and `.htaccess`, and the upgrader replaces only `index.php`.

## Release Assumptions

- Official releases come from `fadlee/mini-s3` GitHub Releases.
- The release asset is `mini-s3-<version>.zip`.
- The archive contains `mini-s3-<version>/index.php` and may contain `mini-s3-<version>/.htaccess`.
- The admin auto-upgrader ignores `.htaccess` so custom web server rules are not overwritten.
- Local `config/` and `data/` directories are never overwritten.

## Current Version

`scripts/build-release.sh` will embed a generated runtime constant in release `index.php` near the existing `BASE_PATH` definition:

```php
define('MINI_S3_VERSION', 'v1.0.1');
```

The admin dashboard uses this constant to compare the installed version against the latest GitHub release tag. Source installs that do not have this generated constant are treated as development installs. Development installs should not call GitHub on every dashboard render; they show auto-upgrade as unavailable with a clear message that the feature only supports generated release installs.

The version constant is only defined by generated release files. Modular source files should not define a fallback value, because that would make source installs look upgradeable.

## Admin UX

The dashboard gets an "Updates" panel.

When the admin is logged in from a generated release install, the backend checks GitHub's latest release endpoint for `fadlee/mini-s3`, compares the latest semver tag to `MINI_S3_VERSION`, and renders one of these states:

- Up to date.
- Update available, with latest version and an "Upgrade to vX.Y.Z" button.
- Unable to check updates, with a concise error.
- Auto-upgrade unavailable for source/development installs.

The upgrade button posts to `/_/upgrade` with the existing admin CSRF token. The route is only available after successful admin authentication. The request performs the upgrade server-side and redirects back to the dashboard with a success or error message stored in the admin session, matching the existing session-based admin auth model. The first version does not include a real-time progress bar because shared hosting commonly buffers PHP responses and the single-file swap is small.

## Upgrade Service

Add an `AdminUpgradeService` for update checks and file replacement. The service has one clear responsibility: update the currently deployed single-file release from the official GitHub release asset.

The service will:

- Fetch latest release metadata from `https://api.github.com/repos/fadlee/mini-s3/releases/latest` using PHP stream functions.
- Send GitHub-compatible request headers, including a `User-Agent` and `Accept: application/vnd.github+json`.
- Require a semver-style tag newer than the current `MINI_S3_VERSION`; tags are normalized by trimming one leading `v` before comparison.
- Locate the expected release zip asset.
- Download the zip to a temporary file under the configured `DATA_DIR` at `.upgrade-tmp/`.
- Extract only `mini-s3-<version>/index.php` with `ZipArchive`.
- Validate the extracted file before installing it.
- Back up the current entry file to `<DATA_DIR>/.upgrade-backups/<timestamp>/index.php`.
- Install the new file by writing it next to the current entry file and swapping it into place with `rename()`. The current entry file is resolved from `$_SERVER['SCRIPT_FILENAME']` when available, falling back to `BASE_PATH . '/index.php'` for CLI tests.
- Clean temporary files when possible.

The service will not update modular source installs, run Composer, run migrations, call shell tools, or modify `.htaccess`.

## Validation

The upgrader validates the downloaded file before replacing the current entry file:

- The archive path must exactly match `mini-s3-<version>/index.php`.
- Zip entries with path traversal are ignored and never extracted.
- The extracted file must start with `<?php`.
- The extracted file must contain the expected `MINI_S3_VERSION` value.
- The extracted file must contain recognizable Mini S3 runtime code, such as admin and S3 router class names.
- The file size must be non-zero and within a conservative maximum for a generated single-file release.
- The target file must be writable, and the target directory must allow creating the temporary sibling file needed for the final swap.

The first version intentionally avoids shelling out to `php -l`; shared hosts may disable process execution. GitHub release integrity is based on using the official release API, matching a newer semver tag, downloading the release asset, and validating the generated file shape.

## Backup And Rollback

Backups live under `<DATA_DIR>/.upgrade-backups/` because the configured data directory is already an application-writable location and is preserved across releases. For default installs this is `data/.upgrade-backups/` next to the release `index.php`.

Before installing a new file, the upgrader copies the current `index.php` to `<DATA_DIR>/.upgrade-backups/<timestamp>/index.php`. If backup fails, the upgrade stops before changing the app. If the file swap fails after backup, the service attempts to restore the backup and reports whether rollback succeeded.

After a successful swap, the old `index.php` remains in the backup directory for manual recovery. A fully automatic post-swap health check is out of scope for the first version because the currently executing request may still be running old code and shared hosting can make self-HTTP requests unreliable.

## Error Handling

The admin UI should show actionable errors without exposing sensitive paths beyond what an admin already controls.

Expected errors include:

- GitHub API unreachable.
- Latest release has no valid semver tag.
- Release zip asset missing.
- Download failed.
- `ZipArchive` extension missing.
- Archive does not contain the expected `index.php`.
- Extracted file validation failed.
- Backup directory is not writable.
- Temporary upgrade directory cannot be created or written.
- Current `index.php` is not writable or cannot be swapped.
- Rollback failed and manual restore is required.

## Testing

Tests should cover update logic without making real GitHub network calls.

- Unit tests for version comparison.
- Unit tests for GitHub release metadata parsing.
- Unit tests for release asset selection.
- Unit tests for extracted file validation.
- Unit tests for backup and install behavior using temporary directories.
- Admin renderer tests for up-to-date, update-available, unavailable, and error states.
- Router tests or integration harness coverage for CSRF-protected `/_/upgrade` POST behavior.
- Release archive tests confirming generated `index.php` embeds `MINI_S3_VERSION`.

## Non-Goals

- Do not build a package manager.
- Do not support arbitrary GitHub repositories in the first version.
- Do not update `.htaccess` automatically.
- Do not support modular source-install upgrades.
- Do not add background jobs or real-time progress streaming.
- Do not add checksum or signature verification in the first version.
- Do not delete old backups automatically in the first version unless backup growth becomes a practical issue.

## Risks

The main operational risk is replacing the currently executing PHP entry file on shared hosting. The design mitigates this by keeping the update to one file, writing the new file separately, backing up the old file first, and using `rename()` for the final swap. Some hosts may still deny write access to the web root; in that case the admin UI must fail clearly and leave the existing app untouched.

The main supply-chain risk is trusting the official GitHub release asset without checksums or signatures. This is accepted for the first version to keep the feature simple, but the design keeps validation isolated so checksum verification can be added later.
