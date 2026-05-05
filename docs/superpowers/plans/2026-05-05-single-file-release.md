# Single-File Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make official Mini S3 release zips contain exactly one deployable PHP file, `index.php`, while keeping repository source modular.

**Architecture:** Keep `public/index.php` and `src/*` as development source. Update the release script to generate a bundled `index.php` by inlining the explicitly ordered source files listed in `public/index.php`, then stage only that file in the archive. Update release tests and README instructions to match the new one-file release contract.

**Tech Stack:** PHP 8.0+, Bash release tooling, `zip`/`unzip`, existing shell/PHP tests.

---

## File Structure

- Modify `tests/release-archive.sh`: change archive assertions from project-root layout to exactly one generated `index.php` file.
- Modify `scripts/build-release.sh`: generate a single bundled PHP file and zip only that file.
- Modify `README.md`: update release installation instructions to deploy the extracted archive directory directly and remove references to copying `config.example.php` for releases.
- No new runtime source files are created. No changes to `src/*` behavior are planned.

## Task 1: Update Release Archive Contract Test

**Files:**
- Modify: `tests/release-archive.sh`

- [ ] **Step 1: Replace release archive assertions with one-file expectations**

Change `tests/release-archive.sh` so the assertion block after `ZIP_LIST="$(unzip -l "$ZIP_PATH")"` is:

```bash
assert_zip_contains "index.php"

assert_zip_not_contains_exact "README.md"
assert_zip_not_contains_exact "LICENSE"
assert_zip_not_contains_exact "config.example.php"
assert_zip_not_contains_exact "composer.json"
assert_zip_not_contains_prefix "public/"
assert_zip_not_contains_prefix "src/"
assert_zip_not_contains_exact ".htaccess"
assert_zip_not_contains_prefix "tests/"
assert_zip_not_contains_prefix "docs/"
assert_zip_not_contains_prefix ".github/"
assert_zip_not_contains_prefix "data/"
assert_zip_not_contains_prefix "config/"
assert_zip_not_contains_prefix ".env"
assert_zip_not_contains_prefix "dist/"
assert_zip_not_contains_prefix "vendor/"
assert_zip_not_contains_prefix "composer.lock"
```

- [ ] **Step 2: Add exact file count assertion**

Add this helper after `assert_zip_not_contains_exact()`:

```bash
assert_zip_file_count() {
  local expected_count="$1"
  local actual_count
  actual_count="$(unzip -Z1 "$ZIP_PATH" | grep -Ev '/$' | wc -l | tr -d '[:space:]')"
  if [ "$actual_count" != "$expected_count" ]; then
    fail "zip should contain exactly $expected_count file(s), found $actual_count"
  fi
}
```

Then add this after the assertion block:

```bash
assert_zip_file_count 1
```

- [ ] **Step 3: Add generated PHP lint assertion**

Add this after `assert_zip_file_count 1`:

```bash
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
unzip -q "$ZIP_PATH" -d "$TMP_DIR"
php -l "$TMP_DIR/mini-s3-$VERSION/index.php" >/dev/null
```

- [ ] **Step 4: Run release archive test and confirm it fails**

Run: `tests/release-archive.sh`

Expected: FAIL because the current release still includes `README.md`, `config.example.php`, `composer.json`, `public/`, and `src/`.

- [ ] **Step 5: Commit test change**

Only commit if the user explicitly requested commits. Otherwise skip this step.

```bash
git add tests/release-archive.sh
git commit -m "test: expect single-file release archive"
```

## Task 2: Generate Single-File Release Archive

**Files:**
- Modify: `scripts/build-release.sh`

- [ ] **Step 1: Simplify required release paths**

Change `REQUIRED_PATHS` to require only the entry point and source directory:

```bash
REQUIRED_PATHS=(
  "public/index.php"
  "src"
)
```

- [ ] **Step 2: Add source order list**

Add this after required path validation:

```bash
SOURCE_FILES=(
  "src/Config/ConfigLoader.php"
  "src/Admin/AdminAuth.php"
  "src/Admin/AdminConfigWriter.php"
  "src/Admin/AdminStats.php"
  "src/Admin/AdminRenderer.php"
  "src/Admin/AdminRouter.php"
  "src/Auth/AuthException.php"
  "src/Auth/SigV4Authenticator.php"
  "src/Http/RequestContext.php"
  "src/Storage/FileStorage.php"
  "src/S3/S3Response.php"
  "src/S3/RequestValidator.php"
  "src/S3/S3Router.php"
)

for path in "${SOURCE_FILES[@]}"; do
  if [ ! -f "$ROOT/$path" ]; then
    printf 'Error: required source file missing: %s\n' "$path" >&2
    exit 1
  fi
done
```

- [ ] **Step 3: Add PHP file append helper**

Add this function before staging starts:

```bash
append_php_body() {
  local file="$1"
  php -r '
    $path = $argv[1];
    $code = file_get_contents($path);
    if ($code === false) {
        fwrite(STDERR, "Error: unable to read $path\n");
        exit(1);
    }
    $code = preg_replace("/^<\\?php\\s*/", "", $code, 1);
    $code = preg_replace("/^declare\\(strict_types=1\\);\\s*/", "", $code, 1);
    $code = preg_replace("/\\?>\\s*$/", "", $code, 1);
    echo rtrim($code) . "\n\n";
  ' "$file"
}
```

- [ ] **Step 4: Replace copy-based staging with bundle generation**

Replace the current staging copy block:

```bash
cp "$ROOT/README.md" "$STAGE_DIR/README.md"
cp "$ROOT/config.example.php" "$STAGE_DIR/config.example.php"
cp "$ROOT/composer.json" "$STAGE_DIR/composer.json"
cp -R "$ROOT/public" "$STAGE_DIR/public"
cp -R "$ROOT/src" "$STAGE_DIR/src"

if [ -f "$ROOT/LICENSE" ]; then
  cp "$ROOT/LICENSE" "$STAGE_DIR/LICENSE"
fi
```

with:

```bash
BUNDLE_PATH="$STAGE_DIR/index.php"

{
  printf '<?php\n\n'
  printf 'declare(strict_types=1);\n\n'
  printf "define('BASE_PATH', __DIR__);\n\n"

  for path in "${SOURCE_FILES[@]}"; do
    append_php_body "$ROOT/$path"
  done

  php -r '
    $path = $argv[1];
    $code = file_get_contents($path);
    if ($code === false) {
        fwrite(STDERR, "Error: unable to read $path\n");
        exit(1);
    }
    $code = preg_replace("/^<\\?php\\s*/", "", $code, 1);
    $code = preg_replace("/^declare\\(strict_types=1\\);\\s*/", "", $code, 1);
    $code = preg_replace("/^define\\('BASE_PATH', realpath\\(__DIR__\\.'\\/\\.\\.'\\)\\);\\s*/m", "", $code, 1);
    $code = preg_replace("/^require_once BASE_PATH \\. '\\/src\\/[^']+';\\s*$/m", "", $code);
    echo ltrim($code);
  ' "$ROOT/public/index.php"
} > "$BUNDLE_PATH"

php -l "$BUNDLE_PATH" >/dev/null
```

- [ ] **Step 5: Run release archive test and confirm it passes**

Run: `tests/release-archive.sh`

Expected: PASS with `[PASS] Release archive test passed`.

- [ ] **Step 6: Run full release-relevant checks**

Run: `tests/lint.sh`

Expected: PASS.

Run: `php tests/unit/config-loader.php`

Expected: PASS.

Run: `php tests/unit/request-validator.php`

Expected: PASS.

Run: `tests/integration/run.sh`

Expected: PASS.

- [ ] **Step 7: Commit release builder change**

Only commit if the user explicitly requested commits. Otherwise skip this step.

```bash
git add scripts/build-release.sh tests/release-archive.sh
git commit -m "build: generate single-file release archive"
```

## Task 3: Update README Release Instructions

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update TLDR**

Change the TLDR sentence from:

```markdown
Set your web server root to this project's `public/` directory, configure credentials with environment variables or `config/config.php`, then route all requests to `public/index.php`.
```

to:

```markdown
For source installs, set your web server root to this project's `public/` directory. For release zips, extract the archive, set your web server root to the extracted directory, and route all requests to `index.php`.
```

- [ ] **Step 2: Update Release Zip Installation**

Change the release zip paragraph from:

```markdown
Official release zips use project-root layout. Extract the archive, point your web server root to the extracted `public/` directory, then configure credentials with environment variables or by copying `config.example.php` to `config/config.php`.

Release zips exclude uploaded data, local config, tests, and repository automation files.
```

to:

```markdown
Official release zips contain a single generated `index.php` file. Extract the archive, point your web server root to the extracted directory, then open `/_` to run the installer or configure credentials with environment variables.

Release zips exclude source files, Composer metadata, example config, uploaded data, local config, tests, documentation internals, and repository automation files.
```

- [ ] **Step 3: Clarify source-only local config copy instruction**

Change the installation step:

```markdown
4. Configure credentials using environment variables or a local `config/config.php` copied from `config.example.php`.
```

to:

```markdown
4. Configure credentials using the web installer, environment variables, or a local `config/config.php`. Source installs may copy `config.example.php`; release zips do not include it.
```

- [ ] **Step 4: Run README-sensitive checks**

Run: `tests/release-archive.sh`

Expected: PASS.

- [ ] **Step 5: Commit documentation change**

Only commit if the user explicitly requested commits. Otherwise skip this step.

```bash
git add README.md
git commit -m "docs: document single-file release install"
```

## Task 4: Final Verification

**Files:**
- No planned edits.

- [ ] **Step 1: Run complete check script if available**

Run: `composer check`

Expected: PASS. If Composer is unavailable, run the component commands in Step 2.

- [ ] **Step 2: Run component checks**

Run: `tests/lint.sh`

Expected: PASS.

Run: `php tests/unit/config-loader.php`

Expected: PASS.

Run: `php tests/unit/request-validator.php`

Expected: PASS.

Run: `tests/integration/run.sh`

Expected: PASS.

Run: `tests/release-archive.sh`

Expected: PASS.

- [ ] **Step 3: Inspect archive contents**

Run: `unzip -Z1 dist/mini-s3-v0.0.0-test.zip`

Expected exactly:

```text
mini-s3-v0.0.0-test/
mini-s3-v0.0.0-test/index.php
```

- [ ] **Step 4: Review git diff**

Run: `git diff -- scripts/build-release.sh tests/release-archive.sh README.md docs/superpowers/specs/2026-05-05-single-file-release-design.md docs/superpowers/plans/2026-05-05-single-file-release.md`

Expected: Diff only covers the single-file release spec, plan, release builder, release test, and README release instructions.

- [ ] **Step 5: Report result**

Summarize changed files, verification commands, and any failures. Do not claim completion unless the commands above passed or the failure is explicitly explained.

## Self-Review Notes

- Spec coverage: the plan keeps source modular, generates a single `index.php`, removes `config.example.php` and `composer.json` from releases, preserves installer/config behavior, updates archive tests, and updates README instructions.
- Placeholder scan: no TBD/TODO placeholders remain. Code snippets and commands are concrete.
- Consistency check: the generated archive path is consistently `mini-s3-<version>/index.php`; release tests use `VERSION="v0.0.0-test"` and existing zip path conventions.
