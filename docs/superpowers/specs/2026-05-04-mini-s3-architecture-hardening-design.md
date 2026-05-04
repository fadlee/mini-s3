# Mini S3 Architecture Hardening Design

## Purpose

Improve Mini S3's deployment safety, configuration handling, tooling, and internal boundaries without turning the project into a framework-based application.

This work keeps the project small and easy to deploy while removing the highest-risk architecture problems found during review:

- Project-root deploy instructions conflict with `public/index.php`.
- Runtime credentials live in tracked source config.
- Test and lint commands are not standardized.
- `S3Router` owns too many responsibilities.
- Storage path safety depends too much on caller discipline.

## Goals

- Make `public/` the documented and intended web root.
- Remove tracked runtime credentials from source control.
- Support hybrid config: environment variables for modern deploys and local config files for shared hosting.
- Add dev-only Composer scripts while keeping runtime dependency-free.
- Reduce `S3Router` responsibility through small, targeted boundaries.
- Strengthen storage access so route handlers do not need raw filesystem paths for normal object reads.
- Add focused tests for config and validation behavior.

## Non-Goals

- Do not add a PHP framework.
- Do not require Composer for runtime execution.
- Do not replace the integration harness with PHPUnit.
- Do not redesign S3 API behavior beyond preserving existing behavior.
- Do not add backward compatibility beyond existing legacy config support unless needed by current persisted or deployed behavior.

## Approach

Use staged hardening plus small architecture splits.

Rejected alternatives:

- Minimal hardening only: lowers immediate deployment risk but leaves router and storage boundaries fragile.
- Full restructure: cleaner long-term but too much complexity for a mini zero-runtime-dependency project.

The selected approach improves safety first, then extracts only the smallest units needed to make future changes safer.

## Deployment Boundary

`public/` becomes the only intended web root.

Documentation must direct Apache and Nginx to serve from `/path/to/mini-s3/public`. Requests route to `public/index.php`. The project root must not be used as a web root in recommended configuration because it contains `config/`, `src/`, `tests/`, `.env`, and `data/`.

`data/` remains the default local storage path for simple deploys, but docs should recommend moving `DATA_DIR` outside the web root when hosting allows it. Existing web-server deny rules remain as defense in depth for deployments that cannot move data outside the project.

Acceptance criteria:

- README Apache and Nginx examples use `public/` as root.
- README no longer says to route root-project requests to `index.php`.
- README explains safe `DATA_DIR` placement.
- No recommended deployment exposes `config/`, `src/`, `tests/`, `.env`, or `data/`.

## Config Boundary

Configuration uses a hybrid model:

- Environment variables are supported for modern deploys.
- `config/config.php` remains supported for local/shared-host deploys.
- `config/config.php` is local-only and ignored by git.
- `config.example.php` remains the source-controlled template.

Environment variables:

- `MINI_S3_DATA_DIR`
- `MINI_S3_MAX_REQUEST_SIZE`
- `MINI_S3_CREDENTIALS_JSON`
- `MINI_S3_PUBLIC_READ_ALL_BUCKETS`
- `MINI_S3_AUTH_DEBUG_LOG`
- `MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS`

`MINI_S3_CREDENTIALS_JSON` contains a JSON object mapping access keys to secret keys, for example:

```json
{"access-key":"secret-key"}
```

Config merge order:

1. Built-in defaults.
2. `config/config.php`, if present.
3. Environment overrides, if set.

This order lets env override file config in container and platform deployments. Shared hosts can rely on `config/config.php` when env management is unavailable.

Existing legacy constant config remains supported only through the current fallback path when `config/config.php` is absent. Legacy mode is not expanded.

Security rules:

- `PUBLIC_READ_ALL_BUCKETS` defaults to `false`.
- Empty credentials remain fatal unless `ALLOW_LEGACY_ACCESS_KEY_ONLY=true` and `ALLOWED_ACCESS_KEYS` is non-empty.
- Invalid credential JSON fails startup instead of silently disabling auth.

Acceptance criteria:

- No tracked file contains live default credentials intended for runtime.
- `.gitignore` ignores `config/config.php` and `.env`.
- `config.example.php` documents safe defaults.
- `ConfigLoader` can load from env and config file.
- Misconfiguration still fails closed.

## Tooling

Add dev-only Composer support without making Composer a runtime dependency.

Runtime entrypoint keeps explicit `require_once` statements. `composer install` must not be required to serve requests.

Add:

- `composer.json` with scripts only.
- `tests/lint.sh` for portable PHP syntax checks.
- `composer lint` mapped to `tests/lint.sh`.
- `composer test` mapped to `tests/integration/run.sh`.
- `composer check` mapped to lint then integration tests.

Update integration harness:

- Default `PHP_BIN` to `php`.
- Allow override through `PHP_BIN=/path/to/php`.
- Remove personal machine paths from defaults.

Acceptance criteria:

- `tests/lint.sh` runs syntax checks for all PHP files.
- `composer lint` works when Composer is installed.
- `tests/integration/run.sh` remains directly executable.
- Runtime does not require Composer autoload.

## Internal Architecture

### Request Validation

Add a small validation unit for request-level rules now embedded in `S3Router`.

Responsibilities:

- Bucket name validation.
- Object key validation.
- Positive integer validation for multipart params.
- Request size validation.
- Range parsing.

This unit should return plain values or throw/use existing response paths in a way that preserves current S3 errors. The implementation should avoid a large exception hierarchy.

### Response Boundary

Keep XML response generation in `S3Response`, and move common object response header behavior there when practical:

- Object metadata headers.
- Range headers.
- Standard content type/length handling.

Body streaming can stay in the route handler or object handling code to avoid over-abstracting file IO.

### Storage Boundary

Reduce normal route-handler dependency on raw filesystem paths.

Add small methods such as:

- `objectMetadata(string $bucket, string $key): ?array`
- `openObjectReadStream(string $bucket, string $key)`

Keep `objectPath()` only where direct path access is still required during transition. New read paths should prefer metadata and stream methods.

Storage should keep paths scoped to `DATA_DIR`. If path containment checks are added, they must fail closed.

### Router Boundary

`S3Router` remains the HTTP dispatcher. It should no longer own all parsing and validation details. Keep the first refactor small; do not split into controllers unless the file remains hard to reason about after extracting validation and response/storage helpers.

Acceptance criteria:

- `S3Router` has less embedded validation logic.
- GET/HEAD no longer need raw `objectPath()` for normal metadata and stream access.
- Existing integration behavior is preserved.
- No framework or broad service layer is introduced.

## Tests

Keep the integration harness as the behavior safety net. Add focused test scripts for config and validation behavior.

Config coverage:

- Env credentials JSON valid.
- Env credentials JSON invalid fails.
- Config file merge works.
- Empty credentials fail unless legacy access-key-only mode is explicitly valid.

Validation coverage:

- Valid and invalid bucket names.
- Object key rejects NUL, `.`, and `..` path segments.
- Valid and invalid range headers.
- Oversized request behavior remains 413 in integration tests.

Integration coverage:

- Existing PUT/list/GET/HEAD/DELETE scenarios remain covered.
- Public read behavior remains covered according to test config.
- Multipart behavior remains covered.
- Host mismatch and presigned URL behavior remain covered.

Acceptance criteria:

- `tests/lint.sh` passes.
- Focused config/validation tests pass.
- `tests/integration/run.sh` passes in a local environment with write access to `data/`.

## Error Handling

S3 errors must keep XML responses. Auth failures continue through `AuthException` and `S3Response::error()`.

Config startup failures may still return generic `InternalError` to clients, but details must not be exposed in HTTP responses. Detailed diagnostics can go to logs if already configured.

Validation errors preserve current status/code pairs where behavior already exists:

- Invalid bucket: `400 InvalidBucketName`.
- Invalid object key: `400 InvalidObjectKey`.
- Oversized request: `413 EntityTooLarge`.
- Invalid range: `416` with `Content-Range: bytes */<size>`.

## Rollout Order

1. Add tooling scripts and portable linting.
2. Harden config handling and git ignore rules.
3. Update deployment documentation for `public/` root.
4. Add focused config and validation tests.
5. Extract request validation from `S3Router`.
6. Add storage metadata/stream methods and update GET/HEAD.
7. Move common object headers into response helper if still useful after storage changes.
8. Run lint and tests.

## Risks

- Config merge order can surprise users if env overrides file values. Document explicitly.
- Making `config/config.php` ignored may require removing currently tracked config from git in the implementation plan.
- Integration tests write to `data/`; test docs must warn before running on a real data directory.
- Refactoring router can accidentally change S3 error semantics; focused and integration tests must guard this.

## Success Definition

Work is complete when:

- Recommended deploy uses `public/` web root.
- Runtime credentials are no longer tracked source defaults.
- Hybrid config works and fails closed on invalid auth config.
- Lint/test commands are standardized.
- Router, validation, response, and storage boundaries are clearer without adding runtime dependencies.
- Existing S3 behavior covered by integration tests still passes.
