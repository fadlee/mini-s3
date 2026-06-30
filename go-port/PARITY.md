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
| PUT object                           | [x] | [ ] | |
| GET object                           | [x] | [ ] | |
| GET object with Range                | [x] | [ ] | |
| HEAD object                          | [x] | [ ] | |
| DELETE object                        | [x] | [ ] | |
| List objects (prefix)                | [x] | [ ] | `LastModified` format `Y-m-d\TH:i:s.000\Z` (always `.000` ms since mtime has no sub-second precision) — replicate exact format string |
| POST create multipart upload         | [x] | [ ] | |
| PUT multipart part                   | [x] | [ ] | |
| POST complete multipart              | [x] | [ ] | |
| DELETE abort multipart               | [x] | [ ] | |
| POST bulk delete (?delete)           | [x] | [ ] | |
| OPTIONS / CORS                       | [x] | [ ] | |
| Bucket name validation               | [x] | [ ] | |
| Object key validation                | [x] | [ ] | |
| Request size limit (413)             | [x] | [ ] | |
| Invalid range (416)                  | [x] | [ ] | |
| XML error responses                  | [x] | [ ] | |
| Public read all buckets (GET/HEAD)   | [x] | [ ] | |

## Auth (SigV4)

No PHP unit tests exist for this class — coverage is via
`tests/integration/run.php` + `tests/integration/sigv4.php` only. See
PLAN.md "Security-critical ports" before marking any row below `[x]`.

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Authorization header SigV4 verify    | [x] | [ ] | |
| Presigned URL SigV4 verify           | [x] | [ ] | |
| Clock skew check (header auth)       | [x] | [ ] | |
| Clock skew + expiry check (presign)  | [x] | [ ] | future-dated and expired both rejected |
| Max presign expiry                   | [x] | [ ] | |
| Legacy access-key-only mode          | [x] | [ ] | |
| Allowed access keys whitelist        | [x] | [ ] | |
| Host candidate fallbacks (X-Forwarded-Host, SERVER_NAME, default-port variants) | [x] | [ ] | only active when `ALLOW_HOST_CANDIDATE_FALLBACKS=true` and `host` is a signed header |
| Auth debug log (JSON lines, append)  | [x] | [ ] | logs canonical request + string-to-sign per host-candidate attempt on mismatch |
| AWS percent-encoding (canonical URI/query) | [x] | [ ] | `rawurlencode` then unescape `%7E`->`~` |
| Signed-headers must be lowercase/unique/sorted | [x] | [ ] | |

## Storage

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Ensure data dir                      | [x] | [ ] | |
| Atomic write (temp + rename)         | [x] | [ ] | |
| MIME detection                       | [x] | [ ] | Go uses mime.DetectType (512B sniff) vs PHP mime_content_type — verify parity |
| Multipart dir layout (.multipart)    | [x] | [ ] | Must keep on-disk layout identical for shared data dirs |
| Multipart cleanup of empty dirs      | [x] | [ ] | |
| Recursive list with prefix           | [x] | [ ] | |

## Admin panel

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Installer page                       | [x] | [ ] | |
| Login + session                      | [x] | [ ] | |
| Dashboard stats                      | [x] | [ ] | |
| Config edit/write                    | [x] | [ ] | Go writes config.yaml, not config.php |
| File explorer (list)                 | [x] | [ ] | |
| File explorer (rename)               | [x] | [ ] | |
| File explorer (delete)               | [x] | [ ] | |
| File explorer (download)             | [x] | [ ] | |
| Select-all checkbox                  | [x] | [ ] | |
| Icon buttons + tooltip               | [x] | [ ] | |
| Sticky header                        | [x] | [ ] | |
| Inline error in dialog               | [x] | [ ] | |
| CSRF token                           | [x] | [ ] | |
| Check for updates (cached, 6h TTL)   | [x] | [ ] | Go: same cache file layout `<DATA_DIR>/.upgrade-cache/latest.json`, different asset-matching (Go asset names, see PLAN.md) |
| Self-upgrade apply + backup + rollback on failure | [x] | [ ] | See PLAN.md — redesigned for binary; backup dir layout `.upgrade-backups/<ts>-<rand>/` kept |
| GitHub token support for rate limits | [x] | [ ] | same `Authorization: Bearer` header |
| Session survives upgrade restart     | [x] (PHP never restarts) | [ ] | Go: stateless HMAC-signed cookie session (confirmed design, see PLAN.md) |

## Config & infra

| Feature                              | PHP | Go | Notes |
|--------------------------------------|-----|----|-------|
| Config loader                        | [x] | [ ] | Go: YAML + env, env var names reuse existing `MINI_S3_*` prefix |
| Legacy root `config.php` fallback    | [x] | [-] | PHP-only, not ported — Go reads `config.yaml` only |
| Single-file/binary release           | [x] | [ ] | Go: cross-compiled binary per OS/arch |
| Release zip / archive                | [x] | [ ] | Go: per-platform archive + `checksums.txt`, distinct asset names (see PLAN.md) |
| Build script                         | [x] | [ ] | Go: GitHub Actions matrix |
| Web server (Apache/Nginx) required   | [x] | [-] | Go is a standalone HTTP server; no rewrite rules/PHP-FPM needed (can still sit behind a reverse proxy for TLS) |
| Versioning/tag scheme                | tags `vX.Y.Z` | [ ] | Confirmed: separate `go-vX.Y.Z` tag line, starting `go-v0.1.0` — see PLAN.md |

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
