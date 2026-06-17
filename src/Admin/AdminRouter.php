<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use MiniS3\Config\ConfigLoader;
use RuntimeException;
use Throwable;

final class AdminRouter
{
    public function __construct(
        private readonly string $baseDir,
        private readonly string $method,
        private readonly string $uri,
        private readonly array $post
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

    private function upgradeService(array $config): AdminUpgradeService
    {
        $entryFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($entryFile === '') {
            $entryFile = $this->baseDir . '/index.php';
        }

        return new AdminUpgradeService($this->baseDir, (string) $config['DATA_DIR'], $entryFile);
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

    private function redirect(string $path): never
    {
        http_response_code(302);
        header('Location: ' . $path);
        exit;
    }
}
