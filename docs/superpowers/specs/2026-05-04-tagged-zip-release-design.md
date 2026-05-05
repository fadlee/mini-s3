# Tagged Zip Release Design

## Purpose

Add automatic GitHub releases that publish a ready-to-install project-root zip whenever a `v*` git tag is pushed.

The release package should be installable on a PHP host by extracting the zip, configuring credentials, and pointing the web server root to `public/`.

## Goals

- Build a deterministic `mini-s3-<version>.zip` package from tracked source files.
- Trigger official releases from git tags matching `v*`.
- Attach the zip to a GitHub Release.
- Keep package layout aligned with the current `public/` web-root architecture.
- Exclude secrets, local data, tests, and development-only docs from the release zip.
- Provide a local script so packaging can be tested before pushing a tag.

## Non-Goals

- Do not create multiple archive shapes.
- Do not support shared-hosting zip layout where `index.php` lives at archive root.
- Do not require Composer or vendor dependencies for runtime.
- Do not publish Docker images or package-manager releases.
- Do not auto-generate changelog content beyond GitHub Release defaults.

## Release Trigger

GitHub Actions runs the release workflow on pushes to tags matching:

```text
v*
```

Examples:

- `v1.0.0`
- `v1.2.3`
- `v1.2.3-beta.1`

The tag name is used as the version string. The zip asset name is:

```text
mini-s3-<tag>.zip
```

## Package Shape

The release zip uses project-root layout. After extraction, users should see files such as:

```text
mini-s3-v1.0.0/
  README.md
  config.example.php
  public/
  src/
```

Users install by extracting the package, setting web root to `public/`, and configuring credentials through environment variables or local `config/config.php` copied from `config.example.php`.

## Included Files

The package includes runtime and installation files:

- `README.md`
- `config.example.php`
- `composer.json`
- `public/`
- `src/`
- `LICENSE` if present

`composer.json` is included because it documents project metadata and optional development scripts. Runtime must still work without Composer.

## Excluded Files

The package excludes local, generated, secret, and development-only files:

- `.git/`
- `.github/`
- `.htaccess` at archive root
- `.env`
- `.DS_Store`
- `config/config.php`
- `data/`
- `dist/`
- `docs/superpowers/`
- `tests/`
- `vendor/`
- `composer.lock`

The package must never include live local credentials or uploaded object data.

## Local Packaging Script

Add `scripts/build-release.sh`.

Responsibilities:

- Accept one version argument, for example `v1.0.0`.
- Create `dist/mini-s3-<version>.zip`.
- Stage files in a temporary directory under `dist/` or system temp.
- Put files under a top-level directory named `mini-s3-<version>/` inside the archive.
- Fail if required files/directories are missing.
- Remove temporary staging directories after completion.

The script should use standard Unix tools available on GitHub Actions Ubuntu runners, especially `mkdir`, `cp`, `rm`, and `zip`.

## GitHub Actions Workflow

Add `.github/workflows/release.yml`.

Workflow steps:

1. Checkout repository.
2. Setup PHP.
3. Run `tests/lint.sh`.
4. Run focused unit scripts.
5. Run `tests/integration/run.sh`.
6. Build zip with `scripts/build-release.sh "$GITHUB_REF_NAME"`.
7. Create GitHub Release for the tag and upload the zip asset.

Use GitHub's built-in `GITHUB_TOKEN` permissions:

```yaml
permissions:
  contents: write
```

The release should be non-draft and non-prerelease by default. If the tag contains a hyphen, such as `v1.0.0-beta.1`, GitHub Release may still be created as a normal release; prerelease detection is not required for the first version.

## Testing

Local verification:

- `tests/lint.sh`
- `php tests/unit/config-loader.php`
- `php tests/unit/request-validator.php`
- `tests/integration/run.sh`
- `scripts/build-release.sh v0.0.0-test`
- Inspect zip file list to confirm included/excluded paths.

Workflow verification:

- The workflow should be syntactically valid YAML.
- The workflow should call the same local script used in manual packaging.
- The workflow should fail before release creation if tests fail.

## Acceptance Criteria

- Pushing `v1.0.0` creates a GitHub Release.
- Release contains `mini-s3-v1.0.0.zip`.
- Zip extracts to `mini-s3-v1.0.0/` with project-root layout.
- Zip includes runtime files and excludes `.env`, `data/`, `config/config.php`, `tests/`, `docs/superpowers/`, and `.github/`.
- Local build script can create the same zip without GitHub Actions.
- Existing lint, unit, and integration tests pass before release packaging.

## Risks

- `zip` may be unavailable on some local machines; document that the script requires it.
- Missing `LICENSE` should not fail packaging because the repository may not have one.
- The release workflow needs `contents: write`; without it, release creation fails.
- Tag pushes are irreversible in normal workflow; failed releases should be fixed by a new tag or deleting/recreating the tag intentionally.
