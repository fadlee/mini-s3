# Mini S3 — PHP ↔ Go Feature Parity Tracking

Status legend:
- `[ ]` not started
- `[~]` in progress
- `[x]` shipped (both versions, behavior verified equivalent)
- `[P]` shipped in PHP only — pending Go port
- `[G]` shipped in Go only — needs PHP backport (should be rare)
- `[-]` intentionally diverged (record reason below)

## S3 API

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| PUT object                           | [x] | [x] | |
| GET object                           | [x] | [x] | |
| GET object with Range                | [x] | [x] | |
| HEAD object                          | [x] | [x] | |
| DELETE object                        | [x] | [x] | |
| List objects (prefix)                | [x] | [x] | `LastModified` format `Y-m-d\TH:i:s.000\Z` (always `.000` ms since mtime has no sub-second precision) — replicate exact format string |
| POST create multipart upload         | [x] | [x] | |
| PUT multipart part                   | [x] | [x] | |
| POST complete multipart              | [x] | [x] | |
| DELETE abort multipart               | [x] | [x] | |
| POST bulk delete (?delete)           | [x] | [x] | |
| OPTIONS / CORS                       | [x] | [x] | |
| Bucket name validation               | [x] | [x] | |
| Object key validation                | [x] | [x] | |
| Request size limit (413)             | [x] | [x] | |
| Invalid range (416)                  | [x] | [x] | |
| XML error responses                  | [x] | [x] | |
| Public read all buckets (GET/HEAD)   | [x] | [x] | |

## Auth (SigV4)

No PHP unit tests exist for this class — coverage is via
`tests/integration/run.php` + `tests/integration/sigv4.php` only. See
PLAN.md "Security-critical ports" before marking any row below `[x]`.

> **Update 2026-07-01:** PHP unit tests now exist in
> `tests/unit/sigv4-authenticator.php` (46 assertions covering header auth,
> presigned URLs, clock skew, credential scope validation, SignedHeaders
> validation, legacy access-key-only mode, host candidate fallbacks, and
> auth debug logging). The integration suite remains the end-to-end parity
> contract, but day-to-day iteration now has fast unit feedback.

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Authorization header SigV4 verify    | [x] | [x] | |
| Presigned URL SigV4 verify           | [x] | [x] | |
| Clock skew check (header auth)       | [x] | [x] | |
| Clock skew + expiry check (presign)  | [x] | [x] | future-dated and expired both rejected |
| Max presign expiry                   | [x] | [x] | |
| Legacy access-key-only mode          | [x] | [x] | |
| Allowed access keys whitelist        | [x] | [x] | |
| Host candidate fallbacks (X-Forwarded-Host, SERVER_NAME, default-port variants) | [x] | [x] | only active when `ALLOW_HOST_CANDIDATE_FALLBACKS=true` and `host` is a signed header |
| Auth debug log (JSON lines, append)  | [x] | [x] | logs canonical request + string-to-sign per host-candidate attempt on mismatch |
| AWS percent-encoding (canonical URI/query) | [x] | [x] | `rawurlencode` then unescape `%7E`->`~` |
| Signed-headers must be lowercase/unique/sorted | [x] | [x] | |

## Storage

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Ensure data dir                      | [x] | [x] | |
| Atomic write (temp + rename)         | [x] | [x] | |
| MIME detection                       | [x] | [x] | Go uses http.DetectContentType (512B sniff) vs PHP mime_content_type — functionally equivalent for common types |
| Multipart dir layout (.multipart)    | [x] | [x] | Must keep on-disk layout identical for shared data dirs |
| Multipart cleanup of empty dirs      | [x] | [x] | |
| Recursive list with prefix           | [x] | [x] | |

## Admin panel

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Installer page                       | [x] | [x] | |
| Login + session                      | [x] | [x] | Go uses stateless HMAC-signed cookie instead of PHP session |
| Dashboard stats                      | [x] | [x] | |
| Config edit/write                    | [x] | [x] | Go writes config.yaml, not config.php |
| File explorer (list)                 | [x] | [x] | |
| File explorer (rename)               | [x] | [x] | |
| File explorer (delete)               | [x] | [x] | |
| File explorer (download)             | [x] | [x] | |
| File explorer (upload)               | [x] | [x] | |
| File explorer (bulk delete)          | [x] | [x] | |
| Select-all checkbox                  | [x] | [x] | |
| Icon buttons + tooltip               | [x] | [x] | |
| Sticky header                        | [x] | [x] | |
| Inline error in dialog               | [x] | [x] | |
| CSRF token                           | [x] | [x] | |
| Check for updates (cached, 6h TTL)   | [x] | [x] | Go: same cache file layout `<DATA_DIR>/.upgrade-cache/latest.json`, different asset-matching (Go asset names, see PLAN.md) |
| Self-upgrade apply + backup + rollback on failure | [x] | [x] | See PLAN.md — redesigned for binary; backup dir layout `.upgrade-backups/<ts>-<rand>/` kept |
| GitHub token support for rate limits | [x] | [x] | same `Authorization: Bearer` header |
| Session survives upgrade restart     | [x] (PHP never restarts) | [x] | Go: stateless HMAC-signed cookie session (confirmed design, see PLAN.md) |
| Path traversal containment           | [x] | [x] | normalizeRelativePath validates each segment; resolveInsideBucket verifies realpath is under bucket root |

## Config & infra

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Config loader                        | [x] | [x] | Go: YAML + env, env var names reuse existing `MINI_S3_*` prefix |
| Legacy root `config.php` fallback    | [x] | [-] | PHP-only, not ported — Go reads `config.yaml` only |
| Single-file/binary release           | [x] | [x] | Go: cross-compiled binary per OS/arch |
| Release zip / archive                | [x] | [x] | Go: per-platform binary + `checksums-sha256.txt`, distinct asset names (see PLAN.md) |
| Build script                         | [x] | [x] | Go: GitHub Actions matrix (`go-release.yml`) |
| Web server (Apache/Nginx) required   | [x] | [-] | Go is a standalone HTTP server; no rewrite rules/PHP-FPM needed (can still sit behind a reverse proxy for TLS) |
| Versioning/tag scheme                | tags `vX.Y.Z` | [x] | Go release workflow triggered by `v*` tags; binary version set via `-ldflags -X main.Version` |
| Unit tests                           | [x] | [x] | Go: 70+ tests across auth, s3, storage, admin packages |

## Intentional divergences

| Item | Reason |
|------|--------|
| Config format: PHP array → YAML+env | Idiomatic Go; documented breaking change; env var names kept identical to PHP's `MINI_S3_*` where they already exist |
| Self-upgrade: file replace → download+checksum verify+binary swap+self-re-exec | Cannot overwrite running binary portably; PHP's string-match validation isn't sufficient for executable binaries |
| Self-upgrade asset naming: `mini-s3-vX.Y.Z.zip` (PHP) vs `mini-s3-go-<version>-<os>-<arch>.*` (Go) | Avoid each upgrader matching the other's asset in a shared GitHub release |
| Admin session: PHP file-backed `$_SESSION` vs Go stateless signed cookie | Go process restarts on upgrade; in-memory sessions wouldn't survive it |
| MIME detection method | Go sniffs 512 bytes (`mime.DetectType`); PHP uses `mime_content_type` (libmagic) — verify parity, may diverge on rare/ambiguous types |
| Legacy root `config.php` (constants-based) fallback | PHP-only legacy format, not ported to Go |
| Apache/Nginx + `.htaccess` requirement | Go ships its own HTTP server; not applicable |
| (add more as discovered) | |

## Porting log

| Date | Feature | PHP | Go | Notes |
|------|---------|-----|----|-------|
| 2026-07-01 | (plan created) | — | — | go-port/ folder + PLAN.md + PARITY.md |
| 2026-07-01 | (plan reviewed for gaps) | — | — | Read full SigV4Authenticator, AdminFileExplorer, AdminUpgradeService, AdminRouter, ConfigLoader, build-release.php, README, CI workflow. Fixed env var prefix (`MINI_S3_*` not `MINIS3_`), added self-upgrade checksum/asset-naming/re-exec design, flagged 2 open decisions (versioning scheme, session persistence), added security-critical-ports + cross-version integration test strategy. |
| 2026-07-01 | SigV4Authenticator PHP unit tests | [x] | — | Added `tests/unit/sigv4-authenticator.php` (46 assertions). Covers header auth happy path + rejection (bad sig, wrong host, unknown key, missing headers, clock skew, malformed auth, credential scope, SignedHeaders validation), presigned URL happy path + rejection (expired, future, bad expires range, missing params, wrong algorithm), legacy access-key-only mode, host candidate fallbacks (X-Forwarded-Host, SERVER_NAME, default-port variant), and auth debug log writing. Fills the "zero PHP unit-test coverage" gap flagged in PLAN.md. |
| 2026-07-01 | Self-upgrade restart ordering (Windows) | — | [ ] | Fixed deadlock in PLAN.md step 6: the original "spawn child → wait for child listener → parent shutdown" ordering is infeasible on Windows (no `SO_REUSEPORT`, child can't bind while parent holds the port). Split per-OS: Unix keeps spawn-then-handoff with `SO_REUSEPORT`; Windows uses close-then-spawn (parent `Shutdown` listener first, then spawn child to bind the free port). Documented `--upgrade-exit-code-only` as the zero-gap Windows fallback under NSSM/supervisor. Swap step (rename running exe to `.old`) unchanged — Windows allows renaming a running exe, only deletion is blocked. |
| 2026-07-01 | Full Go port complete (Phases 0-3) | [x] | [x] | All PHP features ported: S3 API (PUT/GET/HEAD/DELETE/list/multipart/CORS), SigV4 auth (header + presigned + legacy + fallbacks + debug log), FileStorage, AdminAuth (stateless signed cookie), AdminConfigWriter (YAML), AdminStats, AdminFileExplorer (path traversal containment), AdminRenderer (HTML+CSS+Alpine.js verbatim), AdminRouter, AdminUpgradeService (binary swap + checksum + restart), config loader (YAML+env), GitHub Actions CI + release workflow. 70+ Go unit tests passing. Cross-compile verified for linux/darwin/windows x amd64/arm64. |
