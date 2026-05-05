# Single-File Release Design

## Goal

Mini S3 should remain modular in the repository, but official release zips should be simple to deploy: one PHP entry file only. The release should not include `README.md`, `LICENSE`, `config.example.php`, `composer.json`, `src/`, `public/`, tests, local config, uploaded data, or repository automation files.

## Release Shape

The release archive will contain a top-level directory named `mini-s3-<version>/` with a generated `index.php` file and no other files. The runtime deliverable and full archive content is exactly one PHP file.

Users deploy by extracting the zip and pointing the web server document root at the extracted directory. All requests route to `index.php`. The generated file preserves the current admin installer at `/_`, so first-run configuration replaces the need for an example config file.

## Source Layout

The repository source stays as-is for development:

- `public/index.php` remains the development entry point.
- `src/*` remains modular and namespaced.
- `composer.json` remains for development scripts.
- Tests continue to target modular source files.

No hand-maintained single-file source will be added. The release script generates the single file at build time to avoid source drift.

## Bundling Strategy

`scripts/build-release.sh` will generate `dist/.stage-*/mini-s3-<version>/index.php` by concatenating the current entry point and required source files in dependency order.

The generated file will:

- Start with one `<?php` opening tag and `declare(strict_types=1);`.
- Define `BASE_PATH` as the directory containing the generated `index.php`.
- Include class/function definitions from `src/*` without repeated PHP opening tags.
- Remove `require_once BASE_PATH . '/src/...';` lines from the copied entry point because source files are already inlined.
- Preserve namespaces, `use` statements, class names, and runtime behavior.

The first implementation can use the explicit dependency order already listed in `public/index.php`. This is simpler and safer than building a generic PHP parser-based bundler.

## Configuration Behavior

Runtime configuration behavior remains unchanged except that no example config ships in the zip.

- The installer/admin UI can create and edit `config/config.php` next to the generated file.
- Environment variable overrides continue to work.
- Legacy root `config.php` compatibility remains because it is persisted deployment behavior.
- Uploaded data remains outside the archive and defaults to `data/` next to `index.php` unless configured otherwise.

## Release Tests

`tests/release-archive.sh` will be updated to assert the new archive contract:

- Contains `index.php` in the archive root directory.
- Does not contain `config.example.php`.
- Does not contain `composer.json`.
- Does not contain `README.md` or `LICENSE`.
- Does not contain `public/`.
- Does not contain `src/`.
- Continues excluding tests, docs internals, GitHub workflow files, data, local config, vendor files, and lockfiles.

Existing lint, unit, and integration tests remain unchanged. The release archive test verifies the packaging change.

## Documentation

README release instructions will be updated from “point document root to `public/`” to “point document root to the extracted release directory.” Configuration instructions should emphasize the web installer and environment variables instead of copying `config.example.php`.

## Non-Goals

- Do not convert repository source into a single file.
- Do not remove Composer from development tooling.
- Do not remove the installer/admin UI.
- Do not change the S3 API, authentication behavior, storage layout, or config keys.
- Do not introduce a general-purpose PHP bundler dependency.

## Risks

The main risk is generating invalid PHP by mishandling opening tags or `declare(strict_types=1);`. Keep the bundler minimal and deterministic, then verify the generated archive file with `php -l` as part of release testing if feasible.
