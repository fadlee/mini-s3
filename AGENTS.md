# Architecture Notes

## Single-file release bundle (IMPORTANT)

Mini S3 ships as a **single-file `index.php`** for production. The release
artifact is built by `scripts/build-release.php`, which concatenates a
hardcoded `$sourceFiles` list into one bundled `public/index.php`.

**Pitfall:** `public/index.php` (dev) uses individual `require_once
BASE_PATH . '/src/...'` lines and works fine locally even if a new class
file is missing from the build script's list — PHP just autoloads/requires
it normally in dev. The breakage only shows up in the **bundled production
build**, as a `Class "..." not found` fatal error, because the build script
silently drops any source file not explicitly listed.

**Rule: whenever you add a new `src/**/*.php` class file, you MUST also:**
1. Add a `require_once BASE_PATH . '/src/...'` line to `public/index.php`
2. Add the same path to `$sourceFiles` in `scripts/build-release.php`

Verify before tagging a release:
```bash
php scripts/build-release.php v0.0.0-test
unzip -p dist/mini-s3-v0.0.0-test.zip "*/index.php" | grep -c "class YourNewClass"
rm -rf dist
```
(This caused the v1.4.0 release to ship broken; fixed in v1.4.1.)

## Directory layout

- `src/Admin/` — admin panel (auth, config writer, dashboard stats,
  self-upgrade, file explorer/manager, HTML renderer, router). Rendering
  is done by hand-built PHP string concatenation in `AdminRenderer.php`
  (no template engine), with a small Alpine.js layer for interactivity
  (dialogs, search filter, file table state) embedded inline in the
  rendered HTML/`<script>`.
- `src/S3/` — S3-compatible HTTP API (request validation, router,
  XML/response building).
- `src/Auth/` — SigV4 request signing/auth for the S3 API.
- `src/Storage/` — filesystem-backed object storage (`FileStorage`).
- `src/Config/` — config file loading.
- `src/Http/` — request context helpers.
- `public/index.php` — dev entrypoint; requires each `src/` file
  individually. This is what `scripts/build-release.php` parses (via the
  `require_once BASE_PATH . '/src/...'` lines) to assemble the entrypoint
  body for the bundle, but the actual *list of files to inline* comes from
  the separate `$sourceFiles` array — keep both in sync (see Pitfall above).
- `scripts/build-release.php` — builds the single-file release zip in
  `dist/`. Run `php scripts/build-release.php <version>` to build locally.
- `tests/unit/` — one test file per class, kebab-case filenames matching
  the class (e.g. `AdminFileExplorer` -> `admin-file-explorer.php`). No
  PHPUnit; run with `php tests/unit/<file>.php` or loop all of them.

## Testing

```bash
for f in tests/unit/*.php; do php "$f"; done
```

No build/lint step beyond `php -l` / `node --check` (for inline JS) is
required, but always run the relevant unit test file after touching
`AdminRenderer.php`'s generated HTML/JS, since several tests assert on
exact rendered string output.
