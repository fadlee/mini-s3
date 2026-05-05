# Admin and Installer UI Design

## Context

Mini S3 is a minimal PHP 8 S3-compatible object storage server. It currently routes all public requests through `public/index.php`, loads configuration from `config/config.php`, legacy `config.php`, or environment variables, and stores objects in the local filesystem. The new UI must keep the S3 API behavior stable while adding a small browser-based setup and administration surface.

## Goals

- Add an installer that appears automatically when `config/config.php` does not exist.
- Add a simple admin UI for viewing storage statistics and editing config.
- Keep the admin surface separate from S3 object routes by using the reserved `/_` route prefix.
- Keep the implementation dependency-free and compatible with the existing PHP-only project structure.
- Avoid a database, request metrics store, or full storage console in the first version.

## Route Model

The admin and installer UI will live under `/_`.

- `/_` routes to the installer when `config/config.php` is missing.
- `/_` routes to admin login or dashboard after `config/config.php` exists.
- `/_/config` shows and saves editable config after login.
- `/_/logout` destroys the admin session.
- All non-`/_` paths continue to use the existing S3 router.

This keeps the S3 API path space mostly unchanged. The `/_` prefix is intentionally reserved for Mini S3 internal UI routes.

## Configuration

The installer writes `config/config.php` using the same array-returning format already documented by the project. The file will include existing config keys plus one new admin-specific key:

- `ADMIN_PASSWORD_HASH`
- `DATA_DIR`
- `MAX_REQUEST_SIZE`
- `CREDENTIALS`
- `ALLOW_LEGACY_ACCESS_KEY_ONLY`
- `ALLOWED_ACCESS_KEYS`
- `CLOCK_SKEW_SECONDS`
- `MAX_PRESIGN_EXPIRES`
- `AUTH_DEBUG_LOG`
- `ALLOW_HOST_CANDIDATE_FALLBACKS`
- `PUBLIC_READ_ALL_BUCKETS`

The installer will require an admin password, data directory, access key, and secret key. Advanced fields remain available but visually secondary.

Environment variables may still override runtime config as they do today. The admin config page edits `config/config.php`; it does not attempt to edit environment-provided deployment values.

## Installer UI

The installer is a single setup page shown automatically under `/_` when `config/config.php` is missing.

Primary fields:

- Admin password
- Confirm admin password
- Data directory
- Access key
- Secret key
- Public read all buckets

Advanced collapsible fields:

- Max request size
- Auth debug log path
- Allow host candidate fallbacks
- Clock skew seconds
- Max presign expires

On submit, the installer validates the CSRF token and input, creates the config directory if needed, checks that the data directory can be created and written, hashes the admin password with PHP password hashing, writes `config/config.php`, starts an authenticated admin session, and redirects to the dashboard.

`PUBLIC_READ_ALL_BUCKETS` defaults to `true` for new installs and is shown as a primary setting instead of an advanced setting.

The installer must not silently overwrite an existing `config/config.php`. If the file appears during submission, the request fails with a clear message and asks the user to log in instead.

## Admin UI

The admin UI uses a compact top navigation layout selected during brainstorming:

- Mini S3 brand/title
- Dashboard
- Config
- Logout

The layout should be simple, responsive, and usable on desktop and mobile. It should avoid a sidebar because the first version has only two main admin pages.

## Dashboard

The dashboard shows storage basics derived from the filesystem:

- Bucket count
- Object count
- Total storage size
- Data directory path and status
- Copy-paste connection configuration snippets for external applications

The statistics are computed by scanning `DATA_DIR`. The first version does not include request counts, upload/download metrics, recent object lists, charts, or background indexing.

If the data directory is missing, unreadable, or not writable, the dashboard shows an explicit status message instead of failing with a generic server error.

The dashboard also shows a “Connection config” panel for applications that need to connect to this Mini S3 endpoint. The panel includes generic S3 environment variables and Laravel `.env` variables. Values come from the current request scheme/host for the endpoint, configured credentials for access key and secret key, default region `us-east-1`, and bucket placeholder `your-bucket`. Sensitive values are masked by default. A client-side “Show sensitive” toggle swaps between masked and full snippets, and copy buttons copy the currently visible snippet.

## Config Page

The config page edits the same values collected by the installer.

Editable fields:

- Data directory
- Credentials map, with at least one access key and secret key
- Public read all buckets
- Max request size
- Auth debug log path
- Allow host candidate fallbacks
- Clock skew seconds
- Max presign expires
- Admin password change fields

Secret values should not require unnecessary re-entry. The admin password only changes when a new password and confirmation are provided. The S3 secret key can be edited, but the UI should avoid displaying more secret material than needed.

On save, validation mirrors installer validation. The config writer rewrites the local config file in a stable PHP array format.

## Authentication And Session Safety

Admin access uses the `ADMIN_PASSWORD_HASH` stored in `config/config.php`.

- Login verifies the submitted password with PHP password verification.
- Successful login starts a PHP session and regenerates the session id.
- Logout destroys the session.
- Installer and admin mutating POST requests require a CSRF token stored in the session.
- Installer and admin forms show validation errors inline.

The first version does not add user management, password reset, rate limiting, or multi-admin roles.

## Components

The implementation should keep responsibilities small and explicit:

- Admin route handling for `/_` requests.
- Installer form handling and config creation.
- Admin authentication/session helpers.
- Config form handling and config rewriting.
- Filesystem statistics scanning.
- Shared HTML rendering helpers for layout, form fields, navigation, and error messages.

These units should remain plain PHP and fit the existing dependency-free style.

## Error Handling

Expected errors should render clear HTML messages in the admin/installer UI:

- Missing or mismatched password confirmation.
- Empty access key or secret key.
- Invalid positive integer fields.
- Data directory cannot be created, read, or written.
- Config directory cannot be created.
- Config file already exists during installer submit.
- CSRF token is missing or invalid.
- Invalid login password.

Unexpected errors in S3 routes should continue to return S3 XML errors as they do today. Admin route errors should return HTML responses.

## Testing

Tests should cover the new behavior without introducing external services.

- Config loader accepts and preserves `ADMIN_PASSWORD_HASH`.
- Installer validation rejects invalid required fields.
- Installer writes a valid `config/config.php` without overwriting an existing config.
- Admin login accepts the configured password hash and rejects invalid passwords.
- Mutating admin requests reject invalid CSRF tokens.
- Config save validates and rewrites supported fields.
- Dashboard stats count buckets, objects, total bytes, and data directory status from fixture directories.
- Existing lint, unit, integration, and release archive checks continue to pass.

## Out Of Scope

- Object browser or object deletion UI.
- Request/upload/download metrics.
- Database-backed stats.
- Multiple admin users or roles.
- Editing environment variables.
- JavaScript-heavy single-page app.
- Dependency-based UI framework.
