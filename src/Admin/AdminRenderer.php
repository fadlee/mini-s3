<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminRenderer
{
    public function installer(array $values, array $errors, string $csrfToken): string
    {
        return $this->layout('Install Mini S3', $this->form('/_', $values, $errors, $csrfToken, true), false);
    }

    public function login(string $error, string $csrfToken): string
    {
        $errorHtml = $error === '' ? '' : '<div class="error">' . $this->e($error) . '</div>';
        $body = $errorHtml . '<form method="post" action="/_">'
            . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
            . '<label>Username<input name="username" autocomplete="username" required></label>'
            . '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button type="submit">Log in</button>'
            . '</form>';

        return $this->layout('Mini S3 Admin Login', $body, false);
    }

    public function dashboard(array $stats, array $config = [], string $endpoint = '', array $updateStatus = [], string $flashMessage = ''): string
    {
        $body = $this->flashMessage($flashMessage)
            . '<div class="cards">'
            . $this->statCard('Buckets', (string) $stats['bucket_count'])
            . $this->statCard('Objects', (string) $stats['object_count'])
            . $this->statCard('Storage', $this->formatBytes((int) $stats['total_bytes']))
            . $this->statCard('Data Dir', $this->e((string) $stats['status']))
            . '</div>'
            . '<section class="panel"><h2>Data directory</h2><code>' . $this->e((string) $stats['data_dir']) . '</code></section>'
            . $this->updatesPanel($updateStatus)
            . $this->connectionConfig($config, $endpoint);

        return $this->layout('Dashboard', $body, true);
    }

    public function config(array $values, array $errors, string $csrfToken): string
    {
        return $this->layout('Config', $this->form('/_/config', $values, $errors, $csrfToken, false), true);
    }

    private function form(string $action, array $values, array $errors, string $csrfToken, bool $installer): string
    {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= '<div class="error">' . $this->e((string) $error) . '</div>';
        }

        $passwordLabel = $installer ? 'Admin password' : 'New admin password';
        $passwordRequired = $installer ? ' required' : '';

        return $errorHtml . '<form method="post" action="' . $this->e($action) . '">'
            . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
            . '<label>Admin username<input name="admin_username" value="' . $this->e((string) ($values['admin_username'] ?? 'admin')) . '" required></label>'
            . '<label>' . $passwordLabel . '<input type="password" name="admin_password"' . $passwordRequired . '></label>'
            . '<label>Confirm admin password<input type="password" name="admin_password_confirm"' . $passwordRequired . '></label>'
            . '<label>Data directory<input name="data_dir" value="' . $this->e((string) ($values['data_dir'] ?? '')) . '" required></label>'
            . '<label>Access key<input name="access_key" value="' . $this->e((string) ($values['access_key'] ?? '')) . '" required></label>'
            . '<label>Secret key<input type="password" name="secret_key" value="' . $this->e((string) ($values['secret_key'] ?? '')) . '" required></label>'
            . '<label><input type="checkbox" name="public_read_all_buckets" value="1"' . $this->checked($values, 'public_read_all_buckets') . '> Public read all buckets</label>'
            . '<details><summary>Advanced</summary>'
            . '<label>Max request size<input type="number" min="1" name="max_request_size" value="' . $this->e((string) ($values['max_request_size'] ?? '104857600')) . '"></label>'
            . '<label>Auth debug log<input name="auth_debug_log" value="' . $this->e((string) ($values['auth_debug_log'] ?? '')) . '"></label>'
            . '<label><input type="checkbox" name="allow_host_candidate_fallbacks" value="1"' . $this->checked($values, 'allow_host_candidate_fallbacks') . '> Allow host candidate fallbacks</label>'
            . '<label>Clock skew seconds<input type="number" min="1" name="clock_skew_seconds" value="' . $this->e((string) ($values['clock_skew_seconds'] ?? '900')) . '"></label>'
            . '<label>Max presign expires<input type="number" min="1" name="max_presign_expires" value="' . $this->e((string) ($values['max_presign_expires'] ?? '604800')) . '"></label>'
            . '</details>'
            . '<button type="submit">Save</button>'
            . '</form>';
    }

    private function layout(string $title, string $body, bool $nav): string
    {
        $navigation = $nav ? '<nav><a href="/_">Dashboard</a><a href="/_/config">Config</a><a href="/_/logout">Logout</a></nav>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f7f7f8;color:#17202a}header{background:#101827;color:white;padding:16px 20px;display:flex;gap:20px;align-items:center;justify-content:space-between;flex-wrap:wrap}header a{color:white;text-decoration:none;margin-right:14px}main{max-width:920px;margin:24px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}.card,.panel,form{background:white;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}label{display:block;margin:14px 0;font-weight:600}input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px}input[type=checkbox]{width:auto}button{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;font-weight:700}.error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}.notice{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}code{word-break:break-all}pre{background:#0f172a;color:#e2e8f0;border-radius:10px;overflow:auto;padding:14px;white-space:pre-wrap}.snippet-actions{display:flex;gap:10px;flex-wrap:wrap;margin:10px 0}.hidden{display:none}</style>'
            . '</head><body><header><strong>Mini S3</strong>' . $navigation . '</header><main><h1>' . $this->e($title) . '</h1>' . $body . '</main></body></html>';
    }

    private function statCard(string $label, string $value): string
    {
        return '<section class="card"><h2>' . $this->e($value) . '</h2><p>' . $this->e($label) . '</p></section>';
    }

    private function flashMessage(string $message): string
    {
        return $message === '' ? '' : '<div class="notice">' . $this->e($message) . '</div>';
    }

    private function updatesPanel(array $status): string
    {
        if ($status === []) {
            return '';
        }

        $state = (string) ($status['state'] ?? 'unknown');
        $message = (string) ($status['message'] ?? 'Update status unavailable.');
        $current = $status['currentVersion'] ?? null;
        $latest = $status['latestVersion'] ?? null;
        $body = '<p>' . $this->e($message) . '</p>';
        if (is_string($current) && $current !== '') {
            $body .= '<p><strong>Current version:</strong> ' . $this->e($current) . '</p>';
        }
        if (is_string($latest) && $latest !== '') {
            $body .= '<p><strong>Latest version:</strong> ' . $this->e($latest) . '</p>';
        }
        if ($state !== 'unavailable') {
            $body .= '<form method="post" action="/_/check-update">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit">Check update</button>'
                . '</form>';
        }
        if ($state === 'update_available' && is_string($latest) && $latest !== '') {
            $body .= '<form method="post" action="/_/upgrade">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<input type="hidden" name="latest_version" value="' . $this->e($latest) . '">'
                . '<input type="hidden" name="asset_url" value="' . $this->e((string) ($status['assetUrl'] ?? '')) . '">'
                . '<button type="submit">Upgrade to ' . $this->e($latest) . '</button>'
                . '</form>';
        }

        return '<section class="panel"><h2>Updates</h2>' . $body . '</section>';
    }

    private function connectionConfig(array $config, string $endpoint): string
    {
        if ($config === [] || $endpoint === '') {
            return '';
        }

        $credentials = (array) ($config['CREDENTIALS'] ?? []);
        if ($credentials === []) {
            return '';
        }

        $accessKey = (string) array_key_first($credentials);
        $secretKey = (string) $credentials[$accessKey];
        $region = 'us-east-1';
        $bucket = 'your-bucket';

        $generic = $this->genericSnippet($endpoint, $region, $bucket, $accessKey, $secretKey);
        $genericMasked = $this->genericSnippet($endpoint, $region, $bucket, $this->mask($accessKey), $this->mask($secretKey));
        $laravel = $this->laravelSnippet($endpoint, $region, $bucket, $accessKey, $secretKey);
        $laravelMasked = $this->laravelSnippet($endpoint, $region, $bucket, $this->mask($accessKey), $this->mask($secretKey));

        return '<section class="panel"><h2>Connection config</h2>'
            . '<p>Copy these values into applications that need to connect to this Mini S3 server.</p>'
            . '<div class="snippet-actions"><button type="button" onclick="toggleSensitive(this)">Show sensitive</button></div>'
            . '<h3>Generic S3 env vars</h3>'
            . '<div class="snippet-actions"><button type="button" onclick="copySnippet(\'generic-snippet\')">Copy generic</button></div>'
            . '<pre id="generic-snippet" data-masked="' . $this->e($genericMasked) . '" data-full="' . $this->e($generic) . '">' . $this->e($genericMasked) . '</pre>'
            . '<h3>Laravel .env</h3>'
            . '<div class="snippet-actions"><button type="button" onclick="copySnippet(\'laravel-snippet\')">Copy Laravel</button></div>'
            . '<pre id="laravel-snippet" data-masked="' . $this->e($laravelMasked) . '" data-full="' . $this->e($laravel) . '">' . $this->e($laravelMasked) . '</pre>'
            . '<script>let miniS3ShowSensitive=false;function toggleSensitive(button){miniS3ShowSensitive=!miniS3ShowSensitive;document.querySelectorAll("pre[data-masked]").forEach(function(el){el.textContent=miniS3ShowSensitive?el.dataset.full:el.dataset.masked;});button.textContent=miniS3ShowSensitive?"Hide sensitive":"Show sensitive";}function copySnippet(id){var el=document.getElementById(id);if(!el){return;}navigator.clipboard.writeText(el.textContent);}</script>'
            . '</section>';
    }

    private function genericSnippet(string $endpoint, string $region, string $bucket, string $accessKey, string $secretKey): string
    {
        return 'MINI_S3_ENDPOINT=' . $endpoint . "\n"
            . 'MINI_S3_REGION=' . $region . "\n"
            . 'MINI_S3_BUCKET=' . $bucket . "\n"
            . 'MINI_S3_ACCESS_KEY_ID=' . $accessKey . "\n"
            . 'MINI_S3_SECRET_ACCESS_KEY=' . $secretKey;
    }

    private function laravelSnippet(string $endpoint, string $region, string $bucket, string $accessKey, string $secretKey): string
    {
        return 'AWS_ACCESS_KEY_ID=' . $accessKey . "\n"
            . 'AWS_SECRET_ACCESS_KEY=' . $secretKey . "\n"
            . 'AWS_DEFAULT_REGION=' . $region . "\n"
            . 'AWS_BUCKET=' . $bucket . "\n"
            . 'AWS_ENDPOINT=' . $endpoint . "\n"
            . 'AWS_USE_PATH_STYLE_ENDPOINT=true';
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 8) {
            return str_repeat('*', max(4, strlen($value)));
        }

        return substr($value, 0, 4) . '...' . substr($value, -4);
    }

    private function checked(array $values, string $key): string
    {
        return !empty($values[$key]) ? ' checked' : '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
