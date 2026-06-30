<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use MiniS3\Config\ConfigLoader;
use RuntimeException;
use Throwable;

final class AdminRouter
{
    private const DOWNLOAD_QUERY_KEY = 'download';

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
            $renderer = new AdminRenderer();
            $writer = new AdminConfigWriter($this->baseDir);
            $configPath = $this->baseDir . '/config/config.php';

            if (!is_file($configPath)) {
                $auth = new AdminAuth('admin', '');
                $this->handleInstaller($renderer, $writer, $auth);
            }

            $config = ConfigLoader::load($this->baseDir);
            $auth = new AdminAuth((string) ($config['ADMIN_USERNAME'] ?? 'admin'), (string) ($config['ADMIN_PASSWORD_HASH'] ?? ''));
            $path = parse_url($this->uri, PHP_URL_PATH) ?: '/_';

            if ($path === '/_/logout') {
                $auth->logout();
                $this->redirect('/_');
            }

            if (in_array($path, ['/_/upgrade', '/_/check-update'], true) && !$auth->isAuthenticated()) {
                $this->html($renderer->login('', $auth->csrfToken()));
            }

            if (!$auth->isAuthenticated()) {
                $this->handleLogin($renderer, $auth);
            }

            if ($path === '/_/config') {
                $this->handleConfig($renderer, $writer, $auth, $config);
            }

            if ($path === '/_/files') {
                $this->handleFiles($renderer, $auth, $config);
            }

            if ($path === '/_/upgrade') {
                $this->handleUpgrade($auth, $config);
            }

            if ($path === '/_/check-update') {
                $this->handleCheckUpdate($auth, $config);
            }

            $upgradeService = $this->upgradeService($config);
            $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
            $updateStatus = $currentVersion === null
                ? $upgradeService->status(null)
                : $upgradeService->cachedStatus($currentVersion);
            $updateStatus['csrfToken'] = $auth->csrfToken();
            $stats = (new AdminStats())->scan((string) $config['DATA_DIR']);
            $this->html($renderer->dashboard($stats, $config, $this->endpoint(), $updateStatus, $auth->consumeFlash()));
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><title>Admin Error</title><h1>Admin Error</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            exit;
        }
    }

    private function handleInstaller(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth): never
    {
        $values = $this->defaultValues();
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->installer($this->post + $values, ['CSRF token is invalid'], $auth->csrfToken()), 400);
            }
            try {
                $config = $writer->buildConfig($this->post);
                $writer->writeInstallerConfig($config);
                $loginAuth = new AdminAuth((string) $config['ADMIN_USERNAME'], (string) $config['ADMIN_PASSWORD_HASH']);
                $loginAuth->login((string) $this->post['admin_username'], (string) $this->post['admin_password']);
                $this->redirect('/_');
            } catch (RuntimeException $e) {
                $this->html($renderer->installer($this->post + $values, [$e->getMessage()], $auth->csrfToken()), 400);
            }
        }

        $this->html($renderer->installer($values, [], $auth->csrfToken()));
    }

    private function handleLogin(AdminRenderer $renderer, AdminAuth $auth): never
    {
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->login('CSRF token is invalid', $auth->csrfToken()), 400);
            }
            if ($auth->login((string) ($this->post['username'] ?? ''), (string) ($this->post['password'] ?? ''))) {
                $this->redirect('/_');
            }
            $this->html($renderer->login('Invalid username or password', $auth->csrfToken()), 401);
        }

        $this->html($renderer->login('', $auth->csrfToken()));
    }

    private function handleConfig(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth, array $config): never
    {
        $values = $this->valuesFromConfig($config);
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->config($this->post + $values, ['CSRF token is invalid'], $auth->csrfToken()), 400);
            }
            try {
                $newConfig = $writer->buildConfig($this->post, $config);
                $writer->ensureWritableDataDir((string) $newConfig['DATA_DIR']);
                $writer->writeConfig($newConfig);
                $this->redirect('/_/config');
            } catch (RuntimeException $e) {
                $this->html($renderer->config($this->post + $values, [$e->getMessage()], $auth->csrfToken()), 400);
            }
        }

        $this->html($renderer->config($values, [], $auth->csrfToken()));
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

        $latestVersion = (string) ($this->post['latest_version'] ?? '');
        $assetUrl = (string) ($this->post['asset_url'] ?? '');
        $result = $this->upgradeService($config)->upgrade($currentVersion, $latestVersion, $assetUrl);
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
            'max_request_size' => '104857600',
            'public_read_all_buckets' => true,
            'clock_skew_seconds' => '900',
            'max_presign_expires' => '604800',
        ];
    }

    private function valuesFromConfig(array $config): array
    {
        $credentials = (array) ($config['CREDENTIALS'] ?? []);
        $accessKey = $credentials === [] ? '' : (string) array_key_first($credentials);

        return [
            'admin_username' => (string) ($config['ADMIN_USERNAME'] ?? 'admin'),
            'data_dir' => (string) $config['DATA_DIR'],
            'access_key' => $accessKey,
            'secret_key' => $accessKey === '' ? '' : (string) $credentials[$accessKey],
            'max_request_size' => (string) $config['MAX_REQUEST_SIZE'],
            'public_read_all_buckets' => (bool) $config['PUBLIC_READ_ALL_BUCKETS'],
            'auth_debug_log' => (string) $config['AUTH_DEBUG_LOG'],
            'allow_host_candidate_fallbacks' => (bool) $config['ALLOW_HOST_CANDIDATE_FALLBACKS'],
            'clock_skew_seconds' => (string) $config['CLOCK_SKEW_SECONDS'],
            'max_presign_expires' => (string) $config['MAX_PRESIGN_EXPIRES'],
        ];
    }

    private function endpoint(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');

        return $scheme . '://' . $host;
    }

    private function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
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

        http_response_code(200);
        header('Content-Type: ' . (string) $info['mime']);
        header('Content-Length: ' . (string) $info['size']);
        header('X-Content-Type-Options: nosniff');
        if ($download) {
            header('Content-Disposition: attachment; filename="' . rawurlencode((string) $info['name']) . '"');
        }
        readfile($fullPath);
        exit;
    }

    private function redirect(string $path): never
    {
        http_response_code(302);
        header('Location: ' . $path);
        exit;
    }
}
