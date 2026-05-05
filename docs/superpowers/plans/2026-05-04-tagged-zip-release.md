# Tagged Zip Release Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build project-root release zip files locally and publish them automatically to GitHub Releases on `v*` tag pushes.

**Architecture:** Add one local packaging script that is reused by GitHub Actions. The script stages only runtime/install files into a top-level `mini-s3-<version>/` directory, creates `dist/mini-s3-<version>.zip`, and keeps secrets/data/dev-only files out. The workflow runs existing checks, builds the zip, then creates a GitHub Release with the zip asset.

**Tech Stack:** Bash, zip, GitHub Actions, PHP 8.2 for workflow checks, GitHub CLI.

---

## File Map

- Create: `scripts/build-release.sh` to build `dist/mini-s3-<version>.zip` locally and in CI.
- Create: `public/.htaccess` so Apache rewrites live inside the web root.
- Create: `tests/release-archive.sh` to verify zip include/exclude rules.
- Create: `.github/workflows/release.yml` to publish releases from `v*` tags.
- Modify: `.gitignore` to ignore `/dist/`.
- Modify: `composer.json` to add `release:test` script.
- Modify: `README.md` to document release zip install and tag release flow.
- Modify: `docs/superpowers/specs/2026-05-04-tagged-zip-release-design.md` only if implementation reveals spec ambiguity.

## Task 1: Release Archive Test

**Files:**
- Create: `tests/release-archive.sh`
- Future create: `scripts/build-release.sh`

- [ ] **Step 1: Write failing archive test**

Create `tests/release-archive.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="v0.0.0-test"
ZIP_PATH="$ROOT/dist/mini-s3-$VERSION.zip"

fail() {
  printf '[FAIL] %s\n' "$1" >&2
  exit 1
}

assert_zip_contains() {
  local path="$1"
  if ! unzip -Z1 "$ZIP_PATH" | grep -Fxq "mini-s3-$VERSION/$path"; then
    fail "zip should contain $path"
  fi
}

assert_zip_not_contains_prefix() {
  local path="$1"
  if unzip -Z1 "$ZIP_PATH" | grep -Eq "^mini-s3-$VERSION/$path"; then
    fail "zip should not contain $path"
  fi
}

rm -f "$ZIP_PATH"
"$ROOT/scripts/build-release.sh" "$VERSION"

[ -f "$ZIP_PATH" ] || fail "zip file was not created"

assert_zip_contains "README.md"
assert_zip_contains "config.example.php"
assert_zip_contains "composer.json"
assert_zip_contains "public/.htaccess"
assert_zip_contains "public/index.php"
assert_zip_contains "src/S3/S3Router.php"

assert_zip_not_contains_exact ".htaccess"
assert_zip_not_contains_prefix "tests/"
assert_zip_not_contains_prefix "docs/superpowers/"
assert_zip_not_contains_prefix ".github/"
assert_zip_not_contains_prefix "data/"
assert_zip_not_contains_prefix "config/config.php"
assert_zip_not_contains_prefix ".env"
assert_zip_not_contains_prefix "dist/"
assert_zip_not_contains_prefix "vendor/"
assert_zip_not_contains_prefix "composer.lock"

printf '[PASS] Release archive test passed\n'
```

- [ ] **Step 2: Make test executable**

Run:

```bash
chmod +x tests/release-archive.sh
```

Expected: no output.

- [ ] **Step 3: Run test and verify it fails**

Run:

```bash
tests/release-archive.sh
```

Expected: FAIL because `scripts/build-release.sh` does not exist.

## Task 2: Build Release Script

**Files:**
- Create: `scripts/build-release.sh`
- Modify: `.gitignore`
- Test: `tests/release-archive.sh`

- [ ] **Step 1: Create release script**

Create `scripts/build-release.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"

if [ -z "$VERSION" ]; then
  printf 'Usage: scripts/build-release.sh <version>\n' >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  printf 'Error: zip command not found\n' >&2
  exit 1
fi

REQUIRED_PATHS=(
  "README.md"
  "config.example.php"
  "composer.json"
  "public"
  "src"
)

for path in "${REQUIRED_PATHS[@]}"; do
  if [ ! -e "$ROOT/$path" ]; then
    printf 'Error: required release path missing: %s\n' "$path" >&2
    exit 1
  fi
done

DIST_DIR="$ROOT/dist"
PACKAGE_NAME="mini-s3-$VERSION"
ZIP_PATH="$DIST_DIR/$PACKAGE_NAME.zip"
STAGE_PARENT="$DIST_DIR/.stage-$PACKAGE_NAME"
STAGE_DIR="$STAGE_PARENT/$PACKAGE_NAME"

rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE_DIR" "$DIST_DIR"

cp "$ROOT/README.md" "$STAGE_DIR/README.md"
cp "$ROOT/config.example.php" "$STAGE_DIR/config.example.php"
cp "$ROOT/composer.json" "$STAGE_DIR/composer.json"
cp -R "$ROOT/public" "$STAGE_DIR/public"
cp -R "$ROOT/src" "$STAGE_DIR/src"

if [ -f "$ROOT/LICENSE" ]; then
  cp "$ROOT/LICENSE" "$STAGE_DIR/LICENSE"
fi

rm -f "$ZIP_PATH"
(
  cd "$STAGE_PARENT"
  zip -qr "$ZIP_PATH" "$PACKAGE_NAME"
)

rm -rf "$STAGE_PARENT"
printf 'Created %s\n' "$ZIP_PATH"
```

- [ ] **Step 2: Make release script executable**

Run:

```bash
chmod +x scripts/build-release.sh
```

Expected: no output.

- [ ] **Step 3: Ignore dist output**

Append to `.gitignore` if not present:

```gitignore
/dist/
```

- [ ] **Step 4: Run archive test**

Run:

```bash
tests/release-archive.sh
```

Expected: `[PASS] Release archive test passed`.

- [ ] **Step 5: Inspect zip list manually**

Run:

```bash
unzip -Z1 dist/mini-s3-v0.0.0-test.zip
```

Expected: output includes `mini-s3-v0.0.0-test/public/index.php` and excludes `tests/`, `.github/`, `data/`, `config/config.php`, and `docs/superpowers/`.

## Task 3: Composer Release Test Script

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Add Composer release test script**

Update `composer.json` scripts block to include `release:test`:

```json
  "scripts": {
    "lint": "tests/lint.sh",
    "test": "tests/integration/run.sh",
    "release:test": "tests/release-archive.sh",
    "check": [
      "@lint",
      "@test",
      "@release:test"
    ]
  },
```

- [ ] **Step 2: Validate JSON**

Run:

```bash
php -r 'json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR); echo "composer.json valid\n";'
```

Expected: `composer.json valid`.

- [ ] **Step 3: Run release test via Composer if available**

Run:

```bash
composer run release:test
```

Expected: `[PASS] Release archive test passed`.

If Composer is unavailable, run `tests/release-archive.sh` and report Composer was not available.

## Task 4: GitHub Release Workflow

**Files:**
- Create: `.github/workflows/release.yml`

- [ ] **Step 1: Create release workflow**

Create `.github/workflows/release.yml`:

```yaml
name: Release

on:
  push:
    tags:
      - 'v*'

permissions:
  contents: write

jobs:
  release:
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          tools: none

      - name: Run lint
        run: tests/lint.sh

      - name: Run unit tests
        run: |
          php tests/unit/config-loader.php
          php tests/unit/request-validator.php

      - name: Run integration tests
        run: tests/integration/run.sh

      - name: Build release archive
        run: scripts/build-release.sh "$GITHUB_REF_NAME"

      - name: Verify release archive
        run: tests/release-archive.sh

      - name: Create GitHub Release
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          gh release create "$GITHUB_REF_NAME" \
            "dist/mini-s3-$GITHUB_REF_NAME.zip" \
            --title "$GITHUB_REF_NAME" \
            --notes "Release $GITHUB_REF_NAME"
```

- [ ] **Step 2: Validate YAML presence and trigger**

Run:

```bash
grep -F "tags:" .github/workflows/release.yml && grep -F "'v*'" .github/workflows/release.yml && grep -F "gh release create" .github/workflows/release.yml
```

Expected: all three grep commands print matching lines.

- [ ] **Step 3: Run local workflow-equivalent checks**

Run:

```bash
tests/lint.sh
php tests/unit/config-loader.php
php tests/unit/request-validator.php
tests/integration/run.sh
scripts/build-release.sh v0.0.0-test
tests/release-archive.sh
```

Expected: all commands pass.

## Task 5: README Release Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add release zip installation docs**

Add under Quick Start or after Installation:

```markdown
### Release Zip Installation

Official release zips use project-root layout. Extract the archive, point your web server root to the extracted `public/` directory, then configure credentials with environment variables or by copying `config.example.php` to `config/config.php`.

Release zips exclude uploaded data, local config, tests, and repository automation files.
```

- [ ] **Step 2: Add maintainer release docs**

Add near Development Checks:

```markdown
### Creating a Release

Maintainers create releases by pushing a version tag:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The GitHub Actions release workflow runs lint, tests, builds `mini-s3-v1.0.0.zip`, and attaches it to the GitHub Release.

To test packaging locally:

```bash
scripts/build-release.sh v0.0.0-test
tests/release-archive.sh
```
```

- [ ] **Step 3: Run docs-safe checks**

Run:

```bash
tests/lint.sh
tests/release-archive.sh
```

Expected: both pass.

## Task 6: Final Verification and Commit

**Files:**
- All changed files

- [ ] **Step 1: Run full verification**

Run:

```bash
tests/lint.sh
php tests/unit/config-loader.php
php tests/unit/request-validator.php
tests/integration/run.sh
tests/release-archive.sh
```

Expected:

```text
[PASS] ConfigLoader tests passed
[PASS] RequestValidator tests passed
[PASS] All integration scenarios passed
[PASS] Release archive test passed
```

- [ ] **Step 2: Confirm generated dist is ignored**

Run:

```bash
git status --short --ignored
```

Expected: `dist/` appears only as ignored output if present, not as a normal untracked file.

- [ ] **Step 3: Check release package contents**

Run:

```bash
unzip -Z1 dist/mini-s3-v0.0.0-test.zip
```

Expected: includes runtime files under `mini-s3-v0.0.0-test/` and excludes local/secrets/dev-only files.

- [ ] **Step 4: Commit changes**

Only commit if user explicitly requested commits. If committing, use:

```bash
git add .github/workflows/release.yml .gitignore README.md composer.json scripts/build-release.sh tests/release-archive.sh docs/superpowers/plans/2026-05-04-tagged-zip-release.md
git commit -m "ci: publish tagged zip releases"
```

## Self-Review Notes

- Spec coverage: trigger Task 4; package shape Tasks 1-2; include/exclude rules Tasks 1-2 and 6; local script Task 2; workflow Task 4; docs Task 5.
- Red-flag scan: no incomplete sections or vague future work remain.
- Type/name consistency: asset name, directory name, script path, workflow path, and test paths match across tasks.
- Scope check: release zip automation is one cohesive subsystem and does not need decomposition.
