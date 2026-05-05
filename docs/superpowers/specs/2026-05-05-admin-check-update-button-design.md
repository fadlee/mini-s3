# Admin Check Update Button Design

## Goal

The admin dashboard should show a "Check update" button so admins can manually refresh GitHub release status, while the dashboard still performs cached automatic update checks for generated release installs.

## Behavior

- Generated release installs keep showing the existing Updates panel.
- Dashboard update status comes from a cache under `<DATA_DIR>/.upgrade-cache/latest.json` when that cache is fresh.
- Freshness is a fixed short TTL of 6 hours for the first version.
- If cache is missing or stale, the dashboard performs one GitHub latest-release check and refreshes the cache.
- The Updates panel shows a `Check update` button for generated release installs.
- `Check update` posts to `/_/check-update` with the existing admin CSRF token.
- `/_/check-update` forces a GitHub latest-release check, refreshes the cache, stores a flash message, and redirects back to `/_`.
- The existing `Upgrade to vX.Y.Z` button remains visible only when cached status says an update is available.
- Source/development installs still show auto-upgrade unavailable and do not call GitHub or show the check button.

## Error Handling

- GitHub/network errors should be cached as an error status so repeated dashboard loads do not hammer GitHub.
- Manual `Check update` should overwrite an error cache with the latest result.
- Cache write failures should not make the dashboard fail; the UI should still render the fresh status for that request.

## Non-Goals

- No JavaScript/AJAX loading state.
- No configurable cache TTL in the first version.
- No background scheduler.
- No cache management UI.
