<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use MiniS3\Config\ConfigLoader;
use RuntimeException;
use Throwable;

final class AdminRouter
{
    private const DOWNLOAD_QUERY_KEY = 'download';
    private const INLINE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly string $baseDir,
        private readonly string $method,
        private readonly string $uri,
        private readonly array $post,
        private readonly array $files = []
    ) {
    }

    public function handle(): never
    {
        try {
            $path = parse_url($this->uri, PHP_URL_PATH) ?: '/_';
            if ($path === AdminAssets::alpinePath()) {
                $this->serveAlpine();
            }

            $renderer = new AdminRenderer();
            $writer = new AdminConfigWriter($this->baseDir);

            if (!is_file($this->configPath())) {
                $this->handleInstaller($renderer, $writer, new AdminAuth('admin', ''));
            }

            $config = ConfigLoader::load($this->baseDir);
            $auth = new AdminAuth((string) ($config['ADMIN_USERNAME'] ?? 'admin'), (string) ($config['ADMIN_PASSWORD_HASH'] ?? ''));

            if ($path === '/_/logout') {
                $this->handleLogout($auth);
            }

            if (in_array($path, ['/_/upgrade', '/_/check-update'], true) && !$auth->isAuthenticated()) {
                $this->html($renderer->login('', $auth->csrfToken()));
            }

            if (!$auth->isAuthenticated()) {
                $this->handleLogin($renderer, $auth);
            }

            $envLocked = ConfigLoader::envOverriddenKeys();
            match ($path) {
                '/_/config' => $this->redirect('/_/settings'),
                '/_/settings' => $this->handleSettings($renderer, $writer, $auth, $config, $envLocked),
                '/_/keys' => $this->handleKeys($renderer, $writer, $auth, $config, $envLocked),
                '/_/security' => $this->handleSecurity($renderer, $writer, $auth, $config),
                '/_/files' => $this->handleFiles($renderer, $auth, $config),
                '/_/upgrade' => $this->handleUpgrade($auth, $config),
                '/_/check-update' => $this->handleCheckUpdate($auth, $config),
                default => null,
            };

            $upgradeService = $this->upgradeService($config);
            $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
            $updateStatus = $currentVersion === null
                ? $upgradeService->status(null)
                : $upgradeService->cachedStatus($currentVersion);
            $updateStatus['csrfToken'] = $auth->csrfToken();
            $stats = (new AdminStats())->scan((string) $config['DATA_DIR']);
            $this->html($renderer->dashboard(
                $stats,
                $config,
                $this->endpoint(),
                $updateStatus,
                $auth->consumeFlash(),
                $auth->csrfToken(),
                $this->warnings($config)
            ));
        } catch (Throwable $e) {
            // Details go to the server log only; this page can be reached before login.
            error_log('[mini-s3 admin] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            $this->sendSecurityHeaders();
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><title>Admin Error</title><h1>Admin Error</h1><p>Something went wrong. Check the PHP error log on the server for details.</p>';
            exit;
        }
    }

    private function configPath(): string
    {
        return $this->baseDir . '/config/config.php';
    }

    private function serveAlpine(): never
    {
        http_response_code(200);
        header('Content-Type: text/javascript; charset=UTF-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo AdminAssets::alpine();
        exit;
    }

    private function handleInstaller(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth): never
    {
        $values = $this->defaultValues();
        $credentialsFromEnv = isset(ConfigLoader::envOverriddenKeys()['CREDENTIALS']);
        if ($this->method === 'POST') {
            $submitted = array_intersect_key($this->post, array_flip(['admin_username', 'data_dir'])) + $values;
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->installer($submitted, ['Your session expired. Please submit the form again.'], $auth->csrfToken(), $credentialsFromEnv), 400);
            }
            try {
                [$config, $accessKey] = $writer->buildInstallerConfig($this->post, !$credentialsFromEnv);
                $writer->writeInstallerConfig($config);
                $loginAuth = new AdminAuth((string) $config['ADMIN_USERNAME'], (string) $config['ADMIN_PASSWORD_HASH']);
                $loginAuth->login((string) $this->post['admin_username'], (string) $this->post['admin_password']);
                if ($accessKey !== null) {
                    $loginAuth->setFlash('Mini S3 is ready. Your first access key is below: copy it into your app.');
                    $this->redirect('/_/keys?new=' . rawurlencode($accessKey));
                }
                $loginAuth->setFlash('Mini S3 is ready.');
                $this->redirect('/_');
            } catch (RuntimeException $e) {
                $this->html($renderer->installer($submitted, [$e->getMessage()], $auth->csrfToken(), $credentialsFromEnv), 400);
            }
        }

        $this->html($renderer->installer($values, [], $auth->csrfToken(), $credentialsFromEnv));
    }

    private function handleLogin(AdminRenderer $renderer, AdminAuth $auth): never
    {
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->login('Your session expired. Please log in again.', $auth->csrfToken()), 400);
            }

            $throttle = new LoginThrottle($this->baseDir . '/config/.login-throttle.json');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $retryAfter = $throttle->retryAfter($ip);
            if ($retryAfter > 0) {
                header('Retry-After: ' . $retryAfter);
                $this->html($renderer->login('Too many failed attempts. Try again in ' . (int) ceil($retryAfter / 60) . ' minute(s).', $auth->csrfToken()), 429);
            }

            if ($auth->login((string) ($this->post['username'] ?? ''), (string) ($this->post['password'] ?? ''))) {
                $throttle->reset($ip);
                $this->redirect('/_');
            }
            $throttle->recordFailure($ip);
            $this->html($renderer->login('Invalid username or password', $auth->csrfToken()), 401);
        }

        $this->html($renderer->login('', $auth->csrfToken()));
    }

    private function handleLogout(AdminAuth $auth): never
    {
        // POST + CSRF only, so other sites cannot log the admin out.
        if ($this->method === 'POST' && $auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
            $auth->logout();
        }
        $this->redirect('/_');
    }

    private function handleSettings(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth, array $config, array $envLocked): never
    {
        $values = $this->settingsValues($config);
        if ($this->method === 'POST') {
            $submitted = $this->post + $values;
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->settings($submitted, ['Your session expired. Please submit the form again.'], $auth->csrfToken(), '', $envLocked), 400);
            }
            try {
                $newConfig = $writer->buildSettingsConfig($this->post, ConfigLoader::loadFile($this->baseDir));
                if (!isset($envLocked['DATA_DIR'])) {
                    $writer->ensureWritableDataDir((string) $newConfig['DATA_DIR']);
                }
                $writer->writeConfig($newConfig);
                $auth->setFlash('Settings saved.');
                $this->redirect('/_/settings');
            } catch (RuntimeException $e) {
                $this->html($renderer->settings($submitted, [$e->getMessage()], $auth->csrfToken(), '', $envLocked), 400);
            }
        }

        $this->html($renderer->settings($values, [], $auth->csrfToken(), $auth->consumeFlash(), $envLocked));
    }

    private function handleKeys(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth, array $config, array $envLocked): never
    {
        $credentials = (array) $config['CREDENTIALS'];
        $managedByEnv = isset($envLocked['CREDENTIALS']);

        if ($this->method === 'POST') {
            $action = (string) ($this->post['action'] ?? '');
            $accessKey = trim((string) ($this->post['access_key'] ?? ''));
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                if ($action === 'reveal') {
                    $this->json(['ok' => false, 'message' => 'Your session expired. Reload the page.'], 400);
                }
                $auth->setFlash('Your session expired. Please try again.');
                $this->redirect('/_/keys');
            }

            if ($action === 'reveal') {
                if (!isset($credentials[$accessKey])) {
                    $this->json(['ok' => false, 'message' => 'Access key not found'], 404);
                }
                $this->json(['ok' => true, 'secret' => (string) $credentials[$accessKey]['secret']]);
            }

            if ($managedByEnv) {
                $auth->setFlash('Access keys are set by MINI_S3_CREDENTIALS_JSON and cannot be changed here.');
                $this->redirect('/_/keys');
            }

            try {
                $fileConfig = ConfigLoader::loadFile($this->baseDir);
                $label = (string) ($this->post['label'] ?? '');
                $access = (string) ($this->post['access'] ?? '');
                switch ($action) {
                    case 'create':
                        [$newConfig, $newKey] = $writer->addKey($fileConfig, $label, $access);
                        $writer->writeConfig($newConfig);
                        $auth->setFlash('Access key created. Copy the secret into your app.');
                        $this->redirect('/_/keys?new=' . rawurlencode($newKey));
                        // no break: redirect() exits
                    case 'update':
                        $writer->writeConfig($writer->updateKey($fileConfig, $accessKey, $label, $access));
                        $auth->setFlash('Access key ' . $accessKey . ' updated.');
                        break;
                    case 'delete':
                        if (trim((string) ($this->post['confirm'] ?? '')) !== $accessKey) {
                            throw new RuntimeException('Type the access key exactly to confirm deletion.');
                        }
                        $writer->writeConfig($writer->deleteKey($fileConfig, $accessKey));
                        $auth->setFlash('Access key ' . $accessKey . ' deleted. Apps using it can no longer connect.');
                        break;
                    default:
                        throw new RuntimeException('Unknown action');
                }
            } catch (RuntimeException $e) {
                $auth->setFlash('Error: ' . $e->getMessage());
            }
            $this->redirect('/_/keys');
        }

        $newKey = trim((string) ($_GET['new'] ?? ''));
        $this->html($renderer->keys(
            $credentials,
            $this->endpoint(),
            $auth->csrfToken(),
            $auth->consumeFlash(),
            isset($credentials[$newKey]) ? $newKey : '',
            $managedByEnv
        ));
    }

    private function handleSecurity(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth, array $config): never
    {
        $values = $this->accountValuesFromConfig($config);
        if ($this->method === 'POST') {
            $submitted = ['admin_username' => (string) ($this->post['admin_username'] ?? '')] + $values;
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->security($submitted, ['Your session expired. Please submit the form again.'], $auth->csrfToken(), ''), 400);
            }

            $currentPassword = (string) ($this->post['current_password'] ?? '');
            $currentHash = (string) ($config['ADMIN_PASSWORD_HASH'] ?? '');
            if ($currentHash === '' || !password_verify($currentPassword, $currentHash)) {
                $this->html($renderer->security($submitted, ['Current password is invalid'], $auth->csrfToken(), ''), 401);
            }

            try {
                $newConfig = $writer->buildAdminAccountConfig($this->post, ConfigLoader::loadFile($this->baseDir));
                $writer->writeConfig($newConfig);
                if (PHP_SAPI !== 'cli') {
                    session_regenerate_id(true);
                }
                $auth->setFlash('Admin account updated.');
                $this->redirect('/_/security');
            } catch (RuntimeException $e) {
                $this->html($renderer->security($submitted, [$e->getMessage()], $auth->csrfToken(), ''), 400);
            }
        }

        $this->html($renderer->security($values, [], $auth->csrfToken(), $auth->consumeFlash()));
    }

    private function handleCheckUpdate(AdminAuth $auth, array $config): never
    {
        if ($this->method !== 'POST') {
            $this->redirect('/_');
        }
        if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
            $auth->setFlash('CSRF token is invalid.');
            $this->redirect('/_');
        }

        $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
        if ($currentVersion === null) {
            $auth->setFlash('Auto-upgrade is only available for generated release installs.');
            $this->redirect('/_');
        }

        $status = $this->upgradeService($config)->cachedStatus($currentVersion, true);
        $auth->setFlash((string) $status['message']);
        $this->redirect('/_');
    }

    private function handleUpgrade(AdminAuth $auth, array $config): never
    {
        if ($this->method !== 'POST') {
            $this->redirect('/_');
        }
        if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
            $auth->setFlash('CSRF token is invalid.');
            $this->redirect('/_');
        }

        $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
        if ($currentVersion === null) {
            $auth->setFlash('Auto-upgrade is only available for generated release installs.');
            $this->redirect('/_');
        }

        $result = $this->upgradeService($config)->upgrade($currentVersion);
        $auth->setFlash((string) $result['message']);
        $this->redirect('/_');
    }

    private function handleFiles(AdminRenderer $renderer, AdminAuth $auth, array $config): never
    {
        $explorer = new AdminFileExplorer((string) $config['DATA_DIR']);
        $action = trim((string) ($this->post['action'] ?? ''));
        $bucket = trim((string) ($_GET['bucket'] ?? $this->post['bucket'] ?? ''));
        $prefix = trim((string) ($_GET['prefix'] ?? $this->post['prefix'] ?? ''), '/');

        if ($this->method === 'GET' && isset($_GET[self::DOWNLOAD_QUERY_KEY])) {
            $objectPath = trim((string) ($_GET['path'] ?? ''), '/');
            $download = (string) $_GET[self::DOWNLOAD_QUERY_KEY] === '1';
            $this->streamFile($explorer, $bucket, $objectPath, $download);
        }

        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->json(['ok' => false, 'message' => 'CSRF token is invalid.'], 400);
            }

            try {
                $result = match ($action) {
                    'create_bucket' => $this->createBucketAction($explorer),
                    'rename_bucket' => $this->renameBucketAction($explorer),
                    'delete_bucket' => $this->deleteBucketAction($explorer),
                    'create_folder' => $this->createFolderAction($explorer),
                    'rename_object' => $this->renameObjectAction($explorer),
                    'delete_object' => $this->deleteObjectAction($explorer),
                    'bulk_delete' => $this->bulkDeleteAction($explorer),
                    'upload' => $this->uploadAction($explorer),
                    default => throw new RuntimeException('Unknown action'),
                };
                $this->json(['ok' => true] + $result);
            } catch (RuntimeException $e) {
                $this->json(['ok' => false, 'message' => $e->getMessage()], 400);
            }
        }

        try {
            $body = $renderer->files(
                $explorer->listBuckets(),
                $bucket === '' ? ['folders' => [], 'files' => []] : $explorer->listObjects($bucket, $prefix),
                $bucket,
                $prefix,
                $auth->csrfToken(),
                $auth->consumeFlash()
            );
        } catch (RuntimeException $e) {
            $auth->setFlash($e->getMessage());
            $this->redirect('/_/files');
        }

        $this->html($body);
    }

    private function createBucketAction(AdminFileExplorer $explorer): array
    {
        $name = trim((string) ($this->post['name'] ?? ''));
        $explorer->createBucket($name);

        return [
            'message' => 'Bucket created.',
            'redirect' => '/_/files?bucket=' . rawurlencode($name),
        ];
    }

    private function renameBucketAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $name = trim((string) ($this->post['name'] ?? ''));
        $explorer->renameBucket($bucket, $name);

        return [
            'message' => 'Bucket renamed.',
            'redirect' => '/_/files?bucket=' . rawurlencode($name),
        ];
    }

    private function deleteBucketAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $explorer->deleteBucket($bucket);

        return [
            'message' => 'Bucket deleted.',
            'redirect' => '/_/files',
        ];
    }

    private function createFolderAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $path = trim((string) ($this->post['path'] ?? ''), '/');
        $explorer->createFolder($bucket, $path);

        return [
            'message' => 'Folder created.',
            'redirect' => '/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($path),
        ];
    }

    private function renameObjectAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $oldPath = trim((string) ($this->post['path'] ?? ''), '/');
        $name = trim((string) ($this->post['name'] ?? ''));
        $renamed = $explorer->rename($bucket, $oldPath, $name);
        $parentPrefix = dirname($renamed['path']);
        $parentPrefix = $parentPrefix === '.' ? '' : $parentPrefix;

        return [
            'message' => 'Item renamed.',
            'redirect' => '/_/files?bucket=' . rawurlencode($bucket) . ($parentPrefix === '' ? '' : '&prefix=' . rawurlencode($parentPrefix)),
        ];
    }

    private function deleteObjectAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $path = trim((string) ($this->post['path'] ?? ''), '/');
        $parentPrefix = dirname($path);
        $parentPrefix = $parentPrefix === '.' ? '' : $parentPrefix;
        $explorer->deleteObject($bucket, $path);

        return [
            'message' => 'Item deleted.',
            'redirect' => '/_/files?bucket=' . rawurlencode($bucket) . ($parentPrefix === '' ? '' : '&prefix=' . rawurlencode($parentPrefix)),
        ];
    }

    private function bulkDeleteAction(AdminFileExplorer $explorer): array
    {
        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $items = $this->post['items'] ?? [];
        if (!is_array($items) || $items === []) {
            throw new RuntimeException('No items selected');
        }

        foreach ($items as $item) {
            $explorer->deleteObject($bucket, trim((string) $item, '/'));
        }

        $prefix = trim((string) ($this->post['prefix'] ?? ''), '/');

        return [
            'message' => 'Selected items deleted.',
            'redirect' => '/_/files?bucket=' . rawurlencode($bucket) . ($prefix === '' ? '' : '&prefix=' . rawurlencode($prefix)),
        ];
    }

    private function uploadAction(AdminFileExplorer $explorer): array
    {
        $file = $this->files['file'] ?? null;
        if (!is_array($file)) {
            throw new RuntimeException('No file uploaded');
        }

        $bucket = trim((string) ($this->post['bucket'] ?? ''));
        $prefix = trim((string) ($this->post['prefix'] ?? ''), '/');
        $uploadedPath = $explorer->uploadFile($bucket, $prefix, $file);
        $redirectPrefix = dirname($uploadedPath);
        $redirectPrefix = $redirectPrefix === '.' ? '' : $redirectPrefix;

        return [
            'message' => 'File uploaded.',
            'redirect' => '/_/files?bucket=' . rawurlencode($bucket) . ($redirectPrefix === '' ? '' : '&prefix=' . rawurlencode($redirectPrefix)),
        ];
    }

    private function upgradeService(array $config): AdminUpgradeService
    {
        $entryFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($entryFile === '') {
            $entryFile = $this->baseDir . '/index.php';
        }

        return new AdminUpgradeService($this->baseDir, (string) $config['DATA_DIR'], $entryFile, null, null, (string) ($config['GITHUB_TOKEN'] ?? ''));
    }

    private function defaultValues(): array
    {
        return [
            'admin_username' => 'admin',
            'data_dir' => $this->baseDir . '/data',
        ];
    }

    private function settingsValues(array $config): array
    {
        return [
            'data_dir' => (string) $config['DATA_DIR'],
            'max_request_size' => (string) $config['MAX_REQUEST_SIZE'],
            'public_read_all_buckets' => (bool) $config['PUBLIC_READ_ALL_BUCKETS'],
            'allow_host_candidate_fallbacks' => (bool) $config['ALLOW_HOST_CANDIDATE_FALLBACKS'],
            'clock_skew_seconds' => (string) $config['CLOCK_SKEW_SECONDS'],
            'max_presign_expires' => (string) $config['MAX_PRESIGN_EXPIRES'],
            'auth_debug_log' => (string) $config['AUTH_DEBUG_LOG'],
        ];
    }

    /**
     * Risky settings the admin should know about, shown on the dashboard.
     *
     * @return array<string, string> id => message
     */
    private function warnings(array $config): array
    {
        $warnings = [];
        if ((bool) $config['PUBLIC_READ_ALL_BUCKETS']) {
            $warnings['public-read'] = 'Public read is on: anyone who knows a URL can download every object in every bucket without a key. Turn it off in Settings unless this server only holds public files.';
        }
        if (!AdminAuth::isHttpsRequest()) {
            $warnings['plain-http'] = 'This admin panel is served over plain HTTP, so your password and secret keys travel unencrypted. Put the server behind HTTPS.';
        }
        if ((string) $config['AUTH_DEBUG_LOG'] !== '') {
            $warnings['debug-log'] = 'Auth debug logging is on (AUTH_DEBUG_LOG). It records request details for every failed signature. Turn it off when you are done debugging.';
        }
        $serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        if (!str_contains($serverSoftware, 'apache') && $this->isInsideDocumentRoot((string) $config['DATA_DIR'])) {
            $warnings['data-in-docroot'] = 'The data directory is inside the web root. Apache blocks it with .htaccess, but other servers (such as Nginx) may serve stored files directly and skip authentication. Move DATA_DIR outside the web root, or deny it in the server config (see README).';
        }

        return $warnings;
    }

    private function isInsideDocumentRoot(string $path): bool
    {
        $docRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $real = realpath($path);
        if ($docRoot === false || $real === false || $docRoot === '/') {
            return false;
        }

        return $real === $docRoot || str_starts_with($real, rtrim($docRoot, '/') . '/');
    }

    private function accountValuesFromConfig(array $config): array
    {
        return [
            'admin_username' => (string) ($config['ADMIN_USERNAME'] ?? 'admin'),
        ];
    }

    private function endpoint(): string
    {
        $scheme = AdminAuth::isHttpsRequest() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return $scheme . '://' . $host;
    }

    /**
     * Admin pages show secrets: never cache them, never frame them, and only
     * run script from this origin.
     */
    private function sendSecurityHeaders(): void
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        // Alpine.js evaluates directive expressions, which needs 'unsafe-eval'.
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'");
    }

    private function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        $this->sendSecurityHeaders();
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        $this->sendSecurityHeaders();
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function streamFile(AdminFileExplorer $explorer, string $bucket, string $objectPath, bool $download): never
    {
        try {
            $info = $explorer->objectInfo($bucket, $objectPath);
            $fullPath = $explorer->objectFullPath($bucket, $objectPath);
        } catch (RuntimeException $e) {
            $this->html('<!doctype html><title>Not found</title><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>', 404);
        }

        $name = (string) $info['name'];
        $mime = (string) $info['mime'];
        $inline = !$download && in_array($mime, self::INLINE_MIME_TYPES, true);
        $asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'download';

        http_response_code(200);
        header('Cache-Control: no-store');
        header('Content-Type: ' . (in_array($mime, self::INLINE_MIME_TYPES, true) ? $mime : 'application/octet-stream'));
        header('Content-Length: ' . (string) $info['size']);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        readfile($fullPath);
        exit;
    }

    private function redirect(string $path): never
    {
        http_response_code(302);
        header('Cache-Control: no-store');
        header('Location: ' . $path);
        exit;
    }
}
