# Mini S3 — Go Port Plan

## Goal

Produce a single multiplatform binary (`mini-s3`) that is behaviorally
compatible with the PHP version. The PHP version remains the **reference
implementation** and source of truth for behavior; the Go version tracks
it. New features land in PHP first, then get ported.

## Dual-version workflow

```
idea/feature
  │
  ▼
implement in PHP (src/**/*.php)
  │
  ▼
verify: php tests/unit/*.php + manual test with s5cmd/aws-cli
  │
  ▼
mark feature as "shipped (PHP)" in go-port/PARITY.md
  │
  ▼
port to Go (go-port/...)
  │
  ▼
verify: go test ./... + same manual test
  │
  ▼
mark feature as "shipped (Go)" in go-port/PARITY.md
```

Rules:
- **Never** port a feature that is not yet stable in PHP.
- **Never** diverge Go behavior from PHP without documenting the reason
  in PARITY.md under "Intentional divergences".
- Bug fixes: fix in PHP first, then backport to Go. Record in PARITY.md.

## Repository layout

```
mini-s3/
├── src/                # PHP reference (unchanged)
├── public/             # PHP dev entrypoint
├── tests/unit/         # PHP tests
├── scripts/            # PHP release builder
├── go-port/            # <-- this folder: Go port effort
│   ├── PLAN.md         # this file
│   └── PARITY.md       # feature parity tracking
└── go/                 # Go module root (created in phase 1)
    ├── go.mod
    ├── cmd/mini-s3/    # main entrypoint
    ├── internal/
    │   ├── config/     # ConfigLoader  -> YAML + env
    │   ├── storage/    # FileStorage
    │   ├── s3/         # S3Router, S3Response, RequestValidator
    │   ├── auth/       # SigV4Authenticator, AuthException
    │   ├── http/       # RequestContext
    │   └── admin/      # AdminAuth, AdminRouter, AdminRenderer, ...
    └── tests/          # Go test files
```

The Go module lives under `go/` so the PHP repo root stays clean and
the two never collide. `go-port/` holds only planning/tracking docs.

## Architecture mapping

| PHP class                       | Go package                | Notes |
|---------------------------------|---------------------------|-------|
| Config/ConfigLoader             | internal/config           | YAML + env (breaking change vs PHP array) |
| Http/RequestContext             | internal/http             | wraps net/http Request |
| Storage/FileStorage             | internal/storage          | os/io/filepath |
| Auth/AuthException              | internal/auth             | error type |
| Auth/SigV4Authenticator         | internal/auth             | crypto/hmac + crypto/sha256 |
| S3/RequestValidator             | internal/s3               | pure validation funcs |
| S3/S3Response                   | internal/s3               | writes to http.ResponseWriter |
| S3/S3Router                     | internal/s3               | net/http handler |
| Admin/AdminAuth                 | internal/admin            | session/cookie auth |
| Admin/AdminConfigWriter         | internal/admin            | writes config.yaml |
| Admin/AdminStats                | internal/admin            | filesystem stats |
| Admin/AdminFileExplorer         | internal/admin            | file ops |
| Admin/AdminRenderer             | internal/admin            | HTML via html/template or string concat |
| Admin/AdminRouter               | internal/admin            | net/http sub-router for /_ |
| Admin/AdminUpgradeService       | internal/admin            | **redesigned** — see below |

## Config format (YAML + env)

Breaking change from PHP `config.php`. New `config.yaml`:

```yaml
data_dir: ./data
max_request_size: 104857600
credentials:
  access-key: secret-key
allowed_access_keys: []
allow_legacy_access_key_only: false
clock_skew_seconds: 900
max_presign_expires: 604800
auth_debug_log: ""
allow_host_candidate_fallbacks: false
public_read_all_buckets: true
admin:
  username: admin
  password_hash: ""
github_token: ""
```

**Env var names MUST reuse the existing `MINI_S3_*` prefix** (with the
underscore between `MINI` and `S3`) that the PHP version already
documents in README — not a new `MINIS3_` prefix. This keeps deployment
scripts/env files portable across both implementations:

| Env var                                  | PHP supports today | Go    |
|-------------------------------------------|---------------------|-------|
| `MINI_S3_DATA_DIR`                        | yes                 | yes   |
| `MINI_S3_MAX_REQUEST_SIZE`                | yes                 | yes   |
| `MINI_S3_CREDENTIALS_JSON`                | yes                 | yes   |
| `MINI_S3_PUBLIC_READ_ALL_BUCKETS`         | yes                 | yes   |
| `MINI_S3_AUTH_DEBUG_LOG`                  | yes                 | yes   |
| `MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS`  | yes                 | yes   |
| `MINI_S3_GITHUB_TOKEN`                    | yes                 | yes   |
| `MINI_S3_ADMIN_USERNAME`                  | no                  | yes (new, Go-only) |
| `MINI_S3_ADMIN_PASSWORD_HASH`             | no                  | yes (new, Go-only) |
| `MINI_S3_CLOCK_SKEW_SECONDS`              | no                  | yes (new, Go-only) |
| `MINI_S3_MAX_PRESIGN_EXPIRES`             | no                  | yes (new, Go-only) |
| `MINI_S3_ALLOWED_ACCESS_KEYS`             | no                  | yes, comma-separated (new, Go-only) |
| `MINI_S3_ALLOW_LEGACY_ACCESS_KEY_ONLY`    | no                  | yes (new, Go-only) |

Go does **not** parse the PHP `config.php`/`config/config.php` array
format — that file format is PHP-only and out of scope. Document the
manual field-by-field mapping in README instead of building a PHP-array
parser in Go (low value, high risk of subtle bugs). The installer
(`/_` admin route, ported in Phase 2) is the intended path for fresh
Go setups, same as PHP.

## Self-upgrade redesign (biggest design change)

PHP replaces a single `index.php` file in place; every request just
re-includes it, so there is no real "restart" and no integrity check
beyond a weak string match (`str_contains($code, "define('MINI_S3_VERSION', ...)"`).
That check is not sufficient once we're executing a downloaded
**binary** instead of interpreted text — a corrupt/truncated download
becomes an unbootable server, not a PHP parse error. Go needs a
stronger pipeline:

1. **Asset naming** — Go release assets must be named distinctly from
   the existing PHP zip asset (`mini-s3-vX.Y.Z.zip`) so neither
   upgrader's `assetUrl()` matcher can mismatch the other's asset in a
   shared release. Convention:
   - `mini-s3-go-<version>-linux-amd64.tar.gz`
   - `mini-s3-go-<version>-linux-arm64.tar.gz`
   - `mini-s3-go-<version>-darwin-amd64.tar.gz`
   - `mini-s3-go-<version>-darwin-arm64.tar.gz`
   - `mini-s3-go-<version>-windows-amd64.zip`
   - `mini-s3-go-<version>-checksums.txt` (SHA256SUMS for all of the above)
2. **Download + verify** — download the matching archive AND
   `checksums.txt`, recompute SHA256, reject on mismatch. This is the
   Go equivalent integrity gate to PHP's string-match validation, but
   actually catches corruption/truncation (PHP's check never verified
   integrity, only "looks like our file").
3. **Stage** — extract to `<DATA_DIR>/.upgrade-tmp/`, same convention
   PHP uses for its temp dir.
4. **Backup** — copy current running binary to
   `<DATA_DIR>/.upgrade-backups/<timestamp>-<rand>/mini-s3`, mirroring
   PHP's `.upgrade-backups` layout.
5. **Swap**:
   - Unix: write new binary next to the running one as `<exe>.new`,
     `chmod +x`, `os.Rename(<exe>.new, <exe>)` (atomic same-filesystem
     rename).
   - Windows: cannot replace a running exe's file; move current exe to
     `<exe>.old`, move new binary into place, schedule deletion of
     `<exe>.old` on next start.
6. **Restart — self re-exec as the primary mechanism** (not
   supervisor-dependent by default, to avoid the multi-second-or-more
   downtime / extra ops burden PHP never had): the running process
   spawns the freshly-swapped binary as a child with the same args/env
   (`os.StartProcess`/`exec.Command` + re-exec), waits for the child's
   listener to come up (e.g. child signals readiness on a pipe or by
   successfully binding), then the parent calls
   `http.Server.Shutdown(ctx)` and exits. Expected downtime: roughly
   the time to bind a new listener on the same port (typically
   sub-second on Unix with `SO_REUSEPORT`/`SO_REUSEADDR`; on Windows,
   slightly longer since the old listener must fully close first).
   Document this trade-off plainly in README: **Go upgrade has a brief
   reconnect window; PHP upgrade has none.**
   - Also support `--upgrade-exit-code-only` (or similar flag) for
     operators who run under systemd/launchd/Docker with
     `Restart=always` and prefer the supervisor to do the restart
     instead of self re-exec. Keep this as a documented fallback, not
     the default.
7. **Cache GitHub status** — same as PHP: `<DATA_DIR>/.upgrade-cache/latest.json`,
   6h TTL, same `state` values (`unavailable`, `unknown`, `up_to_date`,
   `update_available`, `error`).
8. **Version comparison** — PHP uses `version_compare()` on tags
   matching `^v?\d+\.\d+\.\d+$`. Go: implement the same strict
   3-component semver comparison directly (no external dependency
   needed for this narrow case); do not reach for a generic semver
   library that accepts pre-release/build metadata PHP's regex would
   have rejected.
9. **GitHub API** — same endpoint
   (`https://api.github.com/repos/fadlee/mini-s3/releases/latest`),
   same `Authorization: Bearer <GITHUB_TOKEN>` header when configured,
   same error-message extraction from the JSON body on non-200.

### Decision: versioning/tagging scheme (confirmed)

**Separate tags.** Go ships its own tag line `go-vX.Y.Z`, starting at
`go-v0.1.0`, decoupled from PHP's `vX.Y.Z`. Each Go release's notes
must state which PHP version/feature set it has parity with (cross-
reference PARITY.md). PHP's `AdminUpgradeService.assetUrl()` only
matches `mini-s3-vX.Y.Z.zip`, so it's safe for Go release assets to
live in completely separate GitHub releases (not just separate assets
on the same release) without touching PHP's upgrade matching at all.
The Go upgrader's GitHub release lookup must be updated accordingly to
list/filter releases by the `go-v` tag prefix, not just "latest"
(since "latest" on the repo would otherwise alternate between PHP and
Go releases depending on publish order).

### Decision: admin session persistence (confirmed)

**Stateless signed cookie.** Session data (authenticated flag, CSRF
token, flash message, expiry) lives entirely in an HMAC-signed cookie;
no server-side state, survives the self-upgrade restart automatically.
Implementation notes for Phase 2:
- Signing secret (32 random bytes) generated once by the installer and
  stored in `config.yaml` alongside `admin.password_hash` (e.g.
  `admin.session_secret`), analogous to how PHP's `ADMIN_PASSWORD_HASH`
  is generated and stored.
- Cookie: `HttpOnly`, `Secure` (when served over HTTPS), `SameSite=Strict`,
  HMAC-SHA256 signed, payload includes an expiry timestamp so stale
  cookies are rejected without server-side revocation lists.
- CSRF token can be derived from/bound to the session cookie's HMAC
  rather than stored separately, simplifying the "flash message" and
  "csrf token" PHP session keys into one signed payload.
- Logout simply clears the cookie; no server-side `session_destroy()`
  equivalent needed.

## Porting phases

### Phase 0 — scaffolding (no behavior)
- [ ] `go/` module init, `go.mod`
- [ ] `cmd/mini-s3/main.go` with flag parsing (`--config`, `--addr`)
- [ ] `internal/config` YAML + env loader
- [ ] CI: `go build`, `go test ./...` on linux/amd64, darwin/amd64,
      darwin/arm64, windows/amd64 cross-compile check

### Phase 1 — S3 core (read path first)
- [ ] `internal/http` RequestContext
- [ ] `internal/storage` FileStorage (list, metadata, open read stream)
- [ ] `internal/s3` RequestValidator
- [ ] `internal/s3` S3Response (XML helpers, range headers)
- [ ] `internal/s3` S3Router: GET, HEAD, OPTIONS, CORS
- [ ] `internal/auth` SigV4 (header + presigned)
- [ ] `internal/s3` S3Router: PUT, POST (multipart), DELETE

### Phase 2 — admin panel
- [ ] `internal/admin` AdminAuth (session)
- [ ] `internal/admin` AdminConfigWriter
- [ ] `internal/admin` AdminStats
- [ ] `internal/admin` AdminFileExplorer
- [ ] `internal/admin` AdminRenderer (port HTML + Alpine.js verbatim)
- [ ] `internal/admin` AdminRouter

### Phase 3 — self-upgrade + release
- [ ] `internal/admin` AdminUpgradeService (binary swap + restart doc)
- [ ] GitHub Actions matrix build: linux/darwin/windows x amd64/arm64
- [ ] Release artifacts: per-platform binary + checksums
- [ ] PARITY.md final review — every PHP feature checked off

## Security-critical ports (extra scrutiny required)

These pieces get a security review pass (not just behavioral parity)
before being considered "done", since bugs here are exploitable, not
just incorrect:

- **SigV4 canonical request building** (`SigV4Authenticator`) — custom
  hand-rolled implementation (not using an AWS SDK), including the
  `hostCandidates()` fallback logic for reverse-proxy deployments.
  Currently has **zero PHP unit-test coverage** — it's only exercised
  end-to-end via `tests/integration/run.php` + `tests/integration/sigv4.php`
  (spins up `php -S`, signs requests, asserts HTTP status/body).
  Before porting: extend that integration harness to also drive a Go
  server binary with the *same* `sigv4.php` signing helper and assert
  identical outcomes (status code + XML error code on rejection), for
  both header-auth and presigned-URL flows, valid and invalid
  signatures, expired presigned URLs, and the host-candidate-fallback
  path. Treat this integration suite as the parity contract, not just
  a smoke test.
- **`AdminFileExplorer` path traversal containment** — PHP resolves
  `realpath()` of the target parent dir and the bucket root, then
  checks `str_starts_with()`. Go port must use an equivalent resolved
  path containment check (`filepath.EvalSymlinks` + `filepath.Clean` +
  prefix check, or `os.Root` if on Go 1.24+) and gets dedicated table
  tests for `..`, encoded traversal, symlink escape, and null-byte
  segment names (mirroring `validateSegmentName`'s checks for `/`,
  `\`, `\0`, leading `.`).
- **CSRF + session secret handling** — whatever session mechanism is
  chosen (see open decision above), the signing/encryption key must
  never be logged, must be generated with a CSPRNG, and must not end
  up readable in default file permissions broader than the PHP
  `ADMIN_PASSWORD_HASH` field already gets in `config/config.php`.
- **Self-upgrade download/verify/swap** — checksum verification must
  happen before any file is made executable or moved into place; on
  any verification failure, leave the currently-running binary
  untouched (mirrors PHP's rollback-on-failure behavior in `upgrade()`).

## Test strategy

- **Unit tests** in Go mirroring `tests/unit/*.php` one-for-one. Each
  PHP test file maps to a Go test file with the same scenarios:
  `admin-auth.php`, `admin-config-writer.php`, `admin-file-explorer.php`,
  `admin-renderer.php`, `admin-stats.php`, `admin-upgrade-service.php`,
  `config-loader.php`, `file-storage.php`, `request-validator.php`.
  Note PHP itself is missing unit tests for `SigV4Authenticator`,
  `S3Router`, `S3Response`, `RequestContext`, `AuthException` — adding
  focused PHP unit tests for these *first* (cheap, fast feedback) is
  recommended before/alongside porting them, rather than relying solely
  on the slower integration suite for day-to-day iteration.
- **Cross-version integration parity test**: extend
  `tests/integration/run.php` (or add a sibling runner) to start both
  `php -S` and the Go binary against the same `DATA_DIR`/config-equivalent
  values, run the same `sigv4.php`-signed requests against both, and
  diff status codes + response bodies. This reuses the existing,
  already-trusted signing helper instead of writing a new one.
- **SigV4 parity test**: in addition to the above, generate a signed
  request with `aws-sdk-go-v2` (the actual AWS SDK, as an external
  cross-check independent of the PHP `sigv4.php` helper) and verify the
  Go server accepts it; generate a bad signature, verify rejection with
  the correct XML error code.
- **Release pipeline smoke test**: after Phase 3, actually download a
  built artifact for the current OS/arch in CI, run `--upgrade` against
  a previous local build, and assert the swap + re-exec succeeds and
  the new binary reports the new version — this is the Go analog of
  the existing `tests/release-archive.php` check.

## Verification commands

PHP:
```bash
for f in tests/unit/*.php; do php "$f"; done
```

Go:
```bash
cd go && go test ./... && go build ./cmd/mini-s3
```

Cross-compile check:
```bash
cd go
for os in linux darwin windows; do
  for arch in amd64 arm64; do
    GOOS=$os GOARCH=$arch go build -o /dev/null ./cmd/mini-s3
  done
done
```
