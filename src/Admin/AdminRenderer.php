<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminRenderer
{
    private const ICON_RENAME = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
    private const ICON_DELETE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>';
    private const ICON_DOWNLOAD = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>';

    private const ICON_COPY = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>';

    private const NAV = [
        '/_' => 'Dashboard',
        '/_/files' => 'Files',
        '/_/keys' => 'Access Keys',
        '/_/settings' => 'Settings',
        '/_/security' => 'Security',
    ];

    public function installer(array $values, array $errors, string $csrfToken, bool $credentialsFromEnv = false): string
    {
        $keyNote = $credentialsFromEnv
            ? '<div class="notice">Access keys come from <code>MINI_S3_CREDENTIALS_JSON</code>, so no key will be generated.</div>'
            : '';
        $body = '<p class="lead">Create the admin account for this server. Mini S3 then creates your first access key and shows you how to connect an app.</p>'
            . '<div class="warning">Until you finish this step, anyone who can open this page can take over the server. Finish setup now.</div>'
            . $keyNote
            . $this->errorHtml($errors)
            . '<form method="post" action="/_">'
            . $this->csrfField($csrfToken)
            . $this->textField('admin_username', 'Admin username', (string) ($values['admin_username'] ?? 'admin'), 'You log in to this panel at <code>/_</code> with this name.', ['required' => true, 'autocomplete' => 'username'])
            . $this->textField('admin_password', 'Admin password', '', 'At least 8 characters. This password controls every bucket and key, so use a password manager.', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8'])
            . $this->textField('admin_password_confirm', 'Confirm password', '', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'minlength' => '8'])
            . '<details><summary>Advanced</summary>'
            . $this->textField('data_dir', 'Data directory', (string) ($values['data_dir'] ?? ''), 'Objects are stored as plain files at <code>&lt;data directory&gt;/&lt;bucket&gt;/&lt;key&gt;</code>. PHP must be able to write here. Prefer a folder outside the web root.', ['required' => true])
            . '</details>'
            . '<button type="submit">Finish setup</button>'
            . '</form>';

        return $this->layout('Set up Mini S3', $body, null);
    }

    public function login(string $error, string $csrfToken): string
    {
        $errorHtml = $error === '' ? '' : '<div class="error">' . $this->e($error) . '</div>';
        $body = $errorHtml . '<form method="post" action="/_">'
            . $this->csrfField($csrfToken)
            . '<label>Username<input name="username" autocomplete="username" required autofocus></label>'
            . '<label>Password<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button type="submit">Log in</button>'
            . '</form>'
            . '<p class="muted">Forgot the password? On the server, run <code>php -r \'echo password_hash("new-password", PASSWORD_DEFAULT);\'</code> and put the result in <code>ADMIN_PASSWORD_HASH</code> in <code>config/config.php</code>.</p>';

        return $this->layout('Mini S3 Admin Login', $body, null);
    }

    public function dashboard(
        array $stats,
        array $config = [],
        string $endpoint = '',
        array $updateStatus = [],
        string $flashMessage = '',
        string $csrfToken = '',
        array $warnings = []
    ): string {
        $keyCount = count((array) ($config['CREDENTIALS'] ?? []));
        $body = $this->flashMessage($flashMessage)
            . $this->warningBanners($warnings)
            . '<div class="cards">'
            . $this->statCard('Buckets', (string) $stats['bucket_count'])
            . $this->statCard('Objects', (string) $stats['object_count'])
            . $this->statCard('Storage', $this->formatBytes((int) $stats['total_bytes']))
            . $this->statCard('Access keys', (string) $keyCount)
            . '</div>'
            . $this->connectPanel($endpoint, (int) $stats['bucket_count'])
            . '<section class="panel"><h2>Data directory</h2>'
            . '<p><code>' . $this->e((string) $stats['data_dir']) . '</code></p>'
            . '<p class="muted">Status: ' . $this->e((string) $stats['status']) . '. Every object is a plain file under this folder; back it up like any other directory.</p>'
            . '</section>'
            . $this->updatesPanel($updateStatus);

        return $this->layout('Dashboard', $body, $csrfToken);
    }

    /**
     * @param array<string, string> $envLocked config key => environment variable
     */
    public function settings(array $values, array $errors, string $csrfToken, string $flashMessage = '', array $envLocked = []): string
    {
        $postMax = (string) ini_get('post_max_size');
        $uploadMax = (string) ini_get('upload_max_filesize');
        $debugLog = (string) ($values['auth_debug_log'] ?? '');
        $debugHelp = $debugLog === ''
            ? 'Off. To debug "SignatureDoesNotMatch" errors, set <code>AUTH_DEBUG_LOG</code> in <code>config/config.php</code> (or <code>MINI_S3_AUTH_DEBUG_LOG</code>) to a log file path outside the web root.'
            : 'On, writing to <code>' . $this->e($debugLog) . '</code>. It can only be changed in <code>config/config.php</code> or the environment, so a stolen admin session cannot write files elsewhere.';

        $body = $this->flashMessage($flashMessage)
            . $this->errorHtml($errors)
            . '<p class="lead">Server settings. Access keys are managed on the <a href="/_/keys">Access Keys</a> page and the admin password on the <a href="/_/security">Security</a> page.</p>'
            . ($envLocked === [] ? '' : '<div class="notice">Fields marked <span class="badge badge-env">env</span> are set by environment variables. They override <code>config/config.php</code> and cannot be changed here.</div>')
            . '<form method="post" action="/_/settings">'
            . $this->csrfField($csrfToken)
            . '<fieldset><legend>Storage</legend>'
            . $this->textField('data_dir', 'Data directory', (string) ($values['data_dir'] ?? ''), 'Where objects are stored. Changing this does not move existing files: move them yourself first, or the buckets will appear empty.', ['required' => true], $envLocked['DATA_DIR'] ?? null)
            . $this->textField('max_request_size', 'Max upload size (bytes)', (string) ($values['max_request_size'] ?? ''), 'Largest single request body accepted by the S3 API. 104857600 = 100 MiB. Larger files should use multipart upload (most S3 tools do this automatically). Uploads from this admin panel are also limited by PHP: upload_max_filesize = ' . $this->e($uploadMax) . ', post_max_size = ' . $this->e($postMax) . '. A reverse proxy may have its own limit (Nginx: <code>client_max_body_size</code>).', ['type' => 'number', 'min' => '1', 'required' => true], $envLocked['MAX_REQUEST_SIZE'] ?? null)
            . '</fieldset>'
            . '<fieldset><legend>Access</legend>'
            . $this->checkboxField('public_read_all_buckets', 'Public read', !empty($values['public_read_all_buckets']), 'Allow anyone to download objects without a key', 'When on, every object in every bucket can be downloaded and listed by anyone who knows the URL. Uploads and deletes still need a key. Leave off unless this server only stores public files; for read-only apps, create a <a href="/_/keys">read-only key</a> instead.', $envLocked['PUBLIC_READ_ALL_BUCKETS'] ?? null)
            . '</fieldset>'
            . '<details><summary>Advanced</summary>'
            . $this->textField('clock_skew_seconds', 'Clock skew (seconds)', (string) ($values['clock_skew_seconds'] ?? ''), 'How far a client clock may differ from the server clock. Default 900 (15 minutes). Raise it only if clients get "RequestTimeTooSkewed"; fixing the client clock is better.', ['type' => 'number', 'min' => '1', 'required' => true])
            . $this->textField('max_presign_expires', 'Max presigned URL lifetime (seconds)', (string) ($values['max_presign_expires'] ?? ''), 'Longest validity a presigned (shareable) URL may request. Default 604800 (7 days), the S3 maximum. Lower it to limit how long a leaked link works.', ['type' => 'number', 'min' => '1', 'required' => true])
            . $this->checkboxField('allow_host_candidate_fallbacks', 'Host fallbacks', !empty($values['allow_host_candidate_fallbacks']), 'Accept signatures made for a different Host', 'Only for reverse proxies that change the Host header. It also accepts X-Forwarded-Host and Host with or without the port, which weakens signature checks. Fix the proxy instead when you can (Nginx: <code>proxy_set_header Host $http_host;</code>).', $envLocked['ALLOW_HOST_CANDIDATE_FALLBACKS'] ?? null)
            . '<div class="field"><span class="field-label">Auth debug log</span><div><p class="help">' . $debugHelp . '</p></div></div>'
            . '</details>'
            . '<button type="submit">Save settings</button>'
            . '</form>';

        return $this->layout('Settings', $body, $csrfToken);
    }

    public function security(array $values, array $errors, string $csrfToken, string $flashMessage): string
    {
        $body = $this->flashMessage($flashMessage)
            . $this->errorHtml($errors)
            . '<p class="lead">The admin account for this panel. It is separate from the S3 <a href="/_/keys">access keys</a> that apps use.</p>'
            . '<form method="post" action="/_/security">'
            . $this->csrfField($csrfToken)
            . $this->textField('admin_username', 'Admin username', (string) ($values['admin_username'] ?? 'admin'), '', ['required' => true, 'autocomplete' => 'username'])
            . $this->textField('current_password', 'Current password', '', 'Required to save any change here.', ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
            . $this->textField('new_password', 'New password', '', 'At least 8 characters. Leave blank to keep the current password.', ['type' => 'password', 'autocomplete' => 'new-password', 'minlength' => '8'])
            . $this->textField('new_password_confirm', 'Confirm new password', '', '', ['type' => 'password', 'autocomplete' => 'new-password', 'minlength' => '8'])
            . '<button type="submit">Save admin account</button>'
            . '</form>';

        return $this->layout('Security', $body, $csrfToken);
    }

    /**
     * @param array<string, array{secret: string, access: string, label: string, created: int}> $credentials
     */
    public function keys(array $credentials, string $endpoint, string $csrfToken, string $flashMessage, string $newKey = '', bool $managedByEnv = false): string
    {
        $keyList = [];
        foreach ($credentials as $accessKey => $entry) {
            $keyList[] = ['key' => (string) $accessKey, 'label' => (string) $entry['label'], 'access' => (string) $entry['access']];
        }
        $initialSecrets = $newKey !== '' && isset($credentials[$newKey]) ? [$newKey => (string) $credentials[$newKey]['secret']] : [];
        $selected = $newKey !== '' ? $newKey : (string) (array_key_first($credentials) ?? '');
        $state = [
            'csrf' => $csrfToken,
            'keys' => $keyList,
            'secrets' => (object) $initialSecrets,
            'selected' => $selected,
            'templates' => $this->snippetTemplates($endpoint),
        ];

        $body = '<script type="application/json" id="keys-state">' . $this->jsonForScript($state) . '</script>'
            . '<div x-data="miniS3Keys()" x-init="init()">'
            . $this->flashMessage($flashMessage)
            . '<section class="panel"><h2>What are access keys?</h2>'
            . '<p>Apps and tools (AWS CLI, rclone, Laravel, SDKs) connect to Mini S3 with an <strong>access key</strong> and its <strong>secret</strong>. Create one key per app, so you can remove one app without touching the others.</p>'
            . '<ul class="help-list">'
            . '<li><span class="badge badge-rw">Read &amp; write</span> can list, download, upload, overwrite and delete objects in every bucket.</li>'
            . '<li><span class="badge badge-read">Read only</span> can only list and download. Use it for websites, CDNs and anything that should never change data.</li>'
            . '</ul>'
            . '<p class="muted">Anyone with a secret has that key\'s access, so treat it like a password. You can view secrets again on this page at any time. Deleting a key immediately stops every app that uses it.</p>'
            . '</section>'
            . ($newKey !== '' ? $this->newKeyPanel($newKey, (string) $credentials[$newKey]['secret'], $endpoint) : '')
            . ($managedByEnv
                ? '<div class="notice">These keys come from the <code>MINI_S3_CREDENTIALS_JSON</code> environment variable. Change that variable to add or remove keys.</div>'
                : $this->createKeyForm($csrfToken))
            . $this->keysTable($credentials, $csrfToken, $managedByEnv)
            . ($credentials === [] ? '' : $this->snippetsPanel())
            . '</div>'
            . $this->keysScript();

        return $this->layout('Access Keys', $body, $csrfToken);
    }

    public function files(array $buckets, array $listing, string $currentBucket, string $currentPrefix, string $csrfToken, string $flashMessage): string
    {
        $summary = count((array) ($listing['folders'] ?? [])) . ' folders, ' . count((array) ($listing['files'] ?? [])) . ' files';
        $body = '<div x-data="miniS3Explorer(' . $this->jsString($csrfToken) . ', ' . $this->jsString($currentBucket) . ', ' . $this->jsString($currentPrefix) . ', ' . $this->jsString($summary) . ')" x-init="init()">'
            . $this->flashMessage($flashMessage)
            . '<section class="panel">'
            . '<div class="toolbar">'
            . '<div class="toolbar-group toolbar-grow"><input type="search" placeholder="Search current list" x-model="search" x-on:input="filterList()"></div>'
            . '<div class="toolbar-group">'
            . '<button type="button" x-on:click="openCreateBucket()">New bucket</button>'
            . ($currentBucket === '' ? '' : '<button type="button" x-on:click="openCreateFolder()">New folder</button><button type="button" x-on:click="openUpload()">Upload file</button>')
            . '</div></div>'
            . $this->filesBreadcrumbs($currentBucket, $currentPrefix)
            . ($currentBucket === '' ? $this->bucketGrid($buckets) : $this->objectTable($currentBucket, $currentPrefix, $listing))
            . '</section>'
            . $this->filesModal()
            . $this->filesScript()
            . '</div>';

        return $this->layout('Files', $body, $csrfToken);
    }

    // --- form helpers -------------------------------------------------------

    private function csrfField(string $csrfToken): string
    {
        return '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">';
    }

    /**
     * A labelled input with help text. $help is trusted HTML written in this
     * class; values are escaped. A field locked by an environment variable is
     * disabled, so the browser does not post it and the saved value is kept.
     */
    private function textField(string $name, string $label, string $value, string $help, array $attrs = [], ?string $envVar = null): string
    {
        $attrs += ['type' => 'text'];
        if ($envVar !== null) {
            $attrs['disabled'] = true;
            unset($attrs['required']);
        }
        $attrHtml = '';
        foreach ($attrs as $attr => $attrValue) {
            if ($attrValue === true) {
                $attrHtml .= ' ' . $attr;
            } elseif ($attrValue !== false) {
                $attrHtml .= ' ' . $attr . '="' . $this->e((string) $attrValue) . '"';
            }
        }
        $id = 'field-' . $name;
        $helpId = $id . '-help';
        $describedBy = $help === '' ? '' : ' aria-describedby="' . $helpId . '"';

        return '<div class="field"><label class="field-label" for="' . $id . '">' . $this->e($label) . $this->envBadge($envVar) . '</label><div>'
            . '<input id="' . $id . '" name="' . $this->e($name) . '" value="' . $this->e($value) . '"' . $attrHtml . $describedBy . '>'
            . ($help === '' ? '' : '<p class="help" id="' . $helpId . '">' . $help . '</p>')
            . '</div></div>';
    }

    private function checkboxField(string $name, string $label, bool $checked, string $caption, string $help, ?string $envVar = null): string
    {
        $disabled = $envVar !== null ? ' disabled' : '';
        $id = 'field-' . $name;

        return '<div class="field"><span class="field-label">' . $this->e($label) . $this->envBadge($envVar) . '</span><div>'
            . '<input type="hidden" name="' . $this->e($name) . '" value="0"' . $disabled . '>'
            . '<label class="checkbox-label" for="' . $id . '"><input type="checkbox" id="' . $id . '" name="' . $this->e($name) . '" value="1"' . ($checked ? ' checked' : '') . $disabled . ' aria-describedby="' . $id . '-help"> ' . $this->e($caption) . '</label>'
            . '<p class="help" id="' . $id . '-help">' . $help . '</p>'
            . '</div></div>';
    }

    private function envBadge(?string $envVar): string
    {
        return $envVar === null ? '' : ' <span class="badge badge-env" title="Set by ' . $this->e($envVar) . '">env: ' . $this->e($envVar) . '</span>';
    }

    private function errorHtml(array $errors): string
    {
        $html = '';
        foreach ($errors as $error) {
            $html .= '<div class="error" role="alert">' . $this->e((string) $error) . '</div>';
        }

        return $html;
    }

    // --- dashboard ----------------------------------------------------------

    /**
     * @param array<string, string> $warnings id => message
     */
    private function warningBanners(array $warnings): string
    {
        $html = '';
        foreach ($warnings as $id => $message) {
            $storageKey = 'miniS3:dismiss:' . $id;
            $html .= '<div class="warning" x-data="{hidden: localStorage.getItem(' . $this->jsString($storageKey) . ') === \'1\'}" x-show="!hidden">'
                . '<span>' . $this->e($message) . '</span>'
                . '<button type="button" class="link-button" x-on:click="localStorage.setItem(' . $this->jsString($storageKey) . ', \'1\'); hidden = true">Dismiss</button>'
                . '</div>';
        }

        return $html;
    }

    private function connectPanel(string $endpoint, int $bucketCount): string
    {
        if ($endpoint === '') {
            return '';
        }
        $html = '<section class="panel"><h2>Connect an app</h2>'
            . '<p>S3 endpoint: <code class="strong-code">' . $this->e($endpoint) . '</code></p>'
            . '<p class="muted">Use path-style addressing (the bucket goes in the URL path) and any region name, for example <code>us-east-1</code>. Ready-made settings for the AWS CLI, rclone, s5cmd, Laravel and JavaScript are on the <a href="/_/keys">Access Keys</a> page.</p>';
        if ($bucketCount === 0) {
            $html .= '<h3>Getting started</h3><ol class="help-list">'
                . '<li>Create a bucket on the <a href="/_/files">Files</a> page, or from a terminal: <code>aws --endpoint-url ' . $this->e($endpoint) . ' s3 mb s3://my-bucket</code></li>'
                . '<li>Copy the connection settings for your app from <a href="/_/keys">Access Keys</a>.</li>'
                . '<li>Upload files with your app, any S3 tool, or the Files page.</li>'
                . '</ol>';
        }

        return $html . '</section>';
    }

    // --- access keys --------------------------------------------------------

    private function newKeyPanel(string $accessKey, string $secret, string $endpoint): string
    {
        return '<section class="panel panel-highlight"><h2>Your new access key</h2>'
            . '<p>Copy these into your app now. You can come back to this page to view the secret again.</p>'
            . '<dl class="key-details">'
            . '<dt>Endpoint</dt><dd><code>' . $this->e($endpoint) . '</code></dd>'
            . '<dt>Access key</dt><dd><code>' . $this->e($accessKey) . '</code> ' . $this->copyButton($accessKey, 'Copy access key') . '</dd>'
            . '<dt>Secret key</dt><dd><code>' . $this->e($secret) . '</code> ' . $this->copyButton($secret, 'Copy secret key') . '</dd>'
            . '<dt>Region</dt><dd><code>us-east-1</code> <span class="muted">(any value works)</span></dd>'
            . '</dl>'
            . '<p class="muted">Ready-made settings for common tools are under <a href="#connect">Connection settings</a> below.</p>'
            . '</section>';
    }

    private function copyButton(string $value, string $label): string
    {
        return $this->iconButton(self::ICON_COPY, $label, 'x-on:click="copy(' . $this->jsString($value) . ', $el)"');
    }

    private function createKeyForm(string $csrfToken): string
    {
        return '<section class="panel"><h2>Create an access key</h2>'
            . '<form method="post" action="/_/keys" class="plain inline-form">'
            . $this->csrfField($csrfToken)
            . '<input type="hidden" name="action" value="create">'
            . '<label class="stacked">Label<input name="label" maxlength="64" placeholder="e.g. Website, Backups, Laravel app"></label>'
            . '<label class="stacked">Permission<select name="access">'
            . '<option value="readwrite">Read &amp; write</option>'
            . '<option value="read">Read only</option>'
            . '</select></label>'
            . '<button type="submit">Create key</button>'
            . '</form>'
            . '<p class="muted">The access key and secret are generated for you.</p>'
            . '</section>';
    }

    private function keysTable(array $credentials, string $csrfToken, bool $managedByEnv): string
    {
        if ($credentials === []) {
            return '<section class="panel"><p class="muted">No access keys yet. Create one above to let an app connect.</p></section>';
        }

        $rows = '';
        foreach ($credentials as $accessKey => $entry) {
            $accessKey = (string) $accessKey;
            $label = (string) $entry['label'];
            $isRead = $entry['access'] === 'read';
            $created = (int) $entry['created'];
            $js = $this->jsString($accessKey);
            $rows .= '<tr>'
                . '<td>' . ($label === '' ? '<span class="muted">No label</span>' : $this->e($label)) . '</td>'
                . '<td><code>' . $this->e($accessKey) . '</code> ' . $this->copyButton($accessKey, 'Copy access key')
                . '<div class="secret-row"><code x-show="secrets[' . $js . ']" x-text="secrets[' . $js . ']" x-cloak></code>'
                . '<button type="button" class="link-button" x-on:click="toggleSecret(' . $js . ')" x-text="secrets[' . $js . '] ? \'Hide secret\' : \'Show secret\'">Show secret</button>'
                . '<button type="button" class="link-button" x-show="secrets[' . $js . ']" x-cloak x-on:click="copy(secrets[' . $js . '], $el)">Copy secret</button>'
                . '</div></td>'
                . '<td>' . ($isRead ? '<span class="badge badge-read">Read only</span>' : '<span class="badge badge-rw">Read &amp; write</span>') . '</td>'
                . '<td>' . ($created > 0 ? $this->e($this->formatDate($created)) : '<span class="muted">-</span>') . '</td>'
                . '<td>' . ($managedByEnv ? '<span class="muted">env</span>' : $this->keyActions($accessKey, $label, $isRead, $csrfToken)) . '</td>'
                . '</tr>';
        }

        return '<section class="panel"><h2>Keys</h2><div class="files-table-wrap"><table class="files-table keys-table">'
            . '<thead><tr><th>Label</th><th>Access key / secret</th><th>Permission</th><th>Created</th><th>Actions</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section>';
    }

    private function keyActions(string $accessKey, string $label, bool $isRead, string $csrfToken): string
    {
        $hiddenFields = $this->csrfField($csrfToken) . '<input type="hidden" name="access_key" value="' . $this->e($accessKey) . '">';

        return '<details class="row-details"><summary>Edit</summary>'
            . '<form method="post" action="/_/keys" class="plain">'
            . $hiddenFields . '<input type="hidden" name="action" value="update">'
            . '<label class="stacked">Label<input name="label" maxlength="64" value="' . $this->e($label) . '"></label>'
            . '<label class="stacked">Permission<select name="access">'
            . '<option value="readwrite"' . ($isRead ? '' : ' selected') . '>Read &amp; write</option>'
            . '<option value="read"' . ($isRead ? ' selected' : '') . '>Read only</option>'
            . '</select></label>'
            . '<button type="submit">Save</button>'
            . '</form></details>'
            . '<details class="row-details"><summary class="danger-text">Delete</summary>'
            . '<form method="post" action="/_/keys" class="plain">'
            . $hiddenFields . '<input type="hidden" name="action" value="delete">'
            . '<p class="help">Apps using this key will stop working immediately. Type <code>' . $this->e($accessKey) . '</code> to confirm.</p>'
            . '<input name="confirm" autocomplete="off" required aria-label="Type the access key to confirm">'
            . '<button type="submit" class="danger">Delete key</button>'
            . '</form></details>';
    }

    private function snippetsPanel(): string
    {
        return '<section class="panel" id="connect"><h2>Connection settings</h2>'
            . '<p class="muted">Pick a key and a tool, then copy. Replace <code>your-bucket</code> with your bucket name.</p>'
            . '<div class="inline-form">'
            . '<label class="stacked">Key<select x-model="selected"><template x-for="k in keys" :key="k.key"><option :value="k.key" x-text="k.key + (k.label ? \' (\' + k.label + \')\' : \'\') + (k.access === \'read\' ? \' - read only\' : \'\')" :selected="k.key === selected"></option></template></select></label>'
            . '</div>'
            . '<div class="tabs" role="tablist">'
            . '<template x-for="(t, id) in templates" :key="id"><button type="button" role="tab" class="tab" :class="tab === id ? \'active\' : \'\'" :aria-selected="tab === id" x-on:click="tab = id" x-text="t.title"></button></template>'
            . '</div>'
            . '<p class="muted" x-text="templates[tab] ? templates[tab].hint : \'\'"></p>'
            . '<pre x-text="snippet()"></pre>'
            . '<div class="snippet-actions">'
            . '<button type="button" x-on:click="toggleSecret(selected)" x-text="secrets[selected] ? \'Hide secret\' : \'Show secret\'"></button>'
            . '<button type="button" x-on:click="copySnippet($el)">Copy</button>'
            . '</div>'
            . '</section>';
    }

    /**
     * Connection snippets with {{ACCESS_KEY}} and {{SECRET_KEY}} placeholders,
     * filled in by the browser for the selected key.
     *
     * @return array<string, array{title: string, hint: string, body: string}>
     */
    private function snippetTemplates(string $endpoint): array
    {
        $region = 'us-east-1';

        return [
            'env' => [
                'title' => 'Env vars',
                'hint' => 'Standard AWS variables, read by the AWS CLI, SDKs and most S3 tools.',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}}\nAWS_SECRET_ACCESS_KEY={{SECRET_KEY}}\nAWS_DEFAULT_REGION={$region}\nAWS_ENDPOINT_URL={$endpoint}",
            ],
            'laravel' => [
                'title' => 'Laravel',
                'hint' => 'Add to .env and use the "s3" disk (needs league/flysystem-aws-s3-v3).',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}}\nAWS_SECRET_ACCESS_KEY={{SECRET_KEY}}\nAWS_DEFAULT_REGION={$region}\nAWS_BUCKET=your-bucket\nAWS_ENDPOINT={$endpoint}\nAWS_USE_PATH_STYLE_ENDPOINT=true",
            ],
            'awscli' => [
                'title' => 'AWS CLI',
                'hint' => 'Creates a "mini-s3" profile, then lists your buckets.',
                'body' => "aws configure set aws_access_key_id {{ACCESS_KEY}} --profile mini-s3\naws configure set aws_secret_access_key {{SECRET_KEY}} --profile mini-s3\naws configure set region {$region} --profile mini-s3\naws --profile mini-s3 --endpoint-url {$endpoint} s3 ls",
            ],
            'rclone' => [
                'title' => 'rclone',
                'hint' => 'Add to rclone.conf (rclone config file shows where it is), then: rclone ls mini-s3:your-bucket',
                'body' => "[mini-s3]\ntype = s3\nprovider = Other\naccess_key_id = {{ACCESS_KEY}}\nsecret_access_key = {{SECRET_KEY}}\nendpoint = {$endpoint}\nregion = {$region}\nforce_path_style = true",
            ],
            's5cmd' => [
                'title' => 's5cmd',
                'hint' => 'Fast parallel transfers from the command line.',
                'body' => "AWS_ACCESS_KEY_ID={{ACCESS_KEY}} AWS_SECRET_ACCESS_KEY={{SECRET_KEY}} AWS_REGION={$region} \\\n  s5cmd --endpoint-url {$endpoint} ls",
            ],
            'js' => [
                'title' => 'JavaScript',
                'hint' => 'AWS SDK v3 for Node.js (npm install @aws-sdk/client-s3). Keep secrets on the server, never in browser code.',
                'body' => "import { S3Client, ListObjectsV2Command } from \"@aws-sdk/client-s3\";\n\nconst s3 = new S3Client({\n  endpoint: \"{$endpoint}\",\n  region: \"{$region}\",\n  forcePathStyle: true,\n  credentials: {\n    accessKeyId: \"{{ACCESS_KEY}}\",\n    secretAccessKey: \"{{SECRET_KEY}}\",\n  },\n});\n\nconst result = await s3.send(new ListObjectsV2Command({ Bucket: \"your-bucket\" }));",
            ],
        ];
    }

    private function keysScript(): string
    {
        return '<script>'
            . 'function miniS3Keys(){const state=JSON.parse(document.getElementById("keys-state").textContent);return {'
            . 'csrf:state.csrf,keys:state.keys,secrets:state.secrets||{},selected:state.selected,templates:state.templates,tab:"env",'
            . 'init(){},'
            . 'async fetchSecret(key){if(this.secrets[key]){return this.secrets[key];}const fd=new FormData();fd.append("csrf_token",this.csrf);fd.append("action","reveal");fd.append("access_key",key);const res=await fetch("/_/keys",{method:"POST",body:fd,headers:{Accept:"application/json"}});const data=await res.json().catch(()=>({ok:false,message:"Invalid response"}));if(!res.ok||!data.ok){alert(data.message||"Could not load the secret.");return "";}this.secrets={...this.secrets,[key]:data.secret};return data.secret;},'
            . 'async toggleSecret(key){if(this.secrets[key]){const next={...this.secrets};delete next[key];this.secrets=next;return;}await this.fetchSecret(key);},'
            . 'snippet(){const t=this.templates[this.tab];if(!t||!this.selected){return "";}const secret=this.secrets[this.selected]||"<click Show secret>";return t.body.split("{{ACCESS_KEY}}").join(this.selected).split("{{SECRET_KEY}}").join(secret);},'
            . 'async copySnippet(el){const t=this.templates[this.tab];if(!t){return;}const secret=await this.fetchSecret(this.selected);if(!secret){return;}this.copy(t.body.split("{{ACCESS_KEY}}").join(this.selected).split("{{SECRET_KEY}}").join(secret),el);},'
            . 'copy(text,el){navigator.clipboard.writeText(text).then(()=>{if(!el){return;}const old=el.getAttribute("data-tooltip");el.setAttribute("data-tooltip","Copied");if(el.tagName==="BUTTON"&&!el.classList.contains("icon-btn")){const label=el.textContent;el.textContent="Copied";setTimeout(()=>{el.textContent=label;},1200);}setTimeout(()=>{if(old!==null){el.setAttribute("data-tooltip",old);}},1200);});}'
            . '}}'
            . '</script>';
    }

    private function jsonForScript(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }

    private function layout(string $title, string $body, ?string $navCsrfToken): string
    {
        $navigation = '';
        if ($navCsrfToken !== null) {
            $links = '';
            foreach (self::NAV as $href => $label) {
                $current = $label === $title ? ' aria-current="page"' : '';
                $links .= '<a href="' . $href . '"' . $current . '>' . $this->e($label) . '</a>';
            }
            $navigation = '<nav>' . $links
                . '<form method="post" action="/_/logout" class="plain nav-logout">' . $this->csrfField($navCsrfToken) . '<button type="submit" class="link-button">Log out</button></form>'
                . '</nav>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e(str_contains($title, 'Mini S3') ? $title : $title . ' · Mini S3') . '</title>'
            . '<style>'
            . 'body { font-family: "SF Pro Display", "Geist Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #FBFBFA; color: #111111; -webkit-font-smoothing: antialiased; }'
            . '[x-cloak] { display: none !important; }'
            . 'header { position: sticky; top: 0; z-index: 30; background: #FFFFFF; border-bottom: 1px solid #EAEAEA; padding: 18px 24px; display: flex; gap: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; }'
            . 'header strong { font-size: 16px; font-weight: 600; color: #111111; letter-spacing: -0.01em; }'
            . 'header nav { display: flex; gap: 18px; }'
            . 'header a { color: #787774; text-decoration: none; font-size: 14px; font-weight: 500; transition: color 0.2s ease, text-decoration 0.2s ease; }'
            . 'header a:hover { color: #111111; text-decoration: underline; text-underline-offset: 4px; }'
            . 'main { max-width: 980px; margin: 32px auto; padding: 0 24px; }'
            . 'h1 { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; margin-bottom: 24px; color: #111111; }'
            . 'h2 { font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 14px; color: #111111; }'
            . '.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }'
            . '.card, .panel, form { background: #FFFFFF; border: 1px solid #EAEAEA; border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: none; }'
            . '.card { margin-bottom: 0; display: flex; flex-direction: column-reverse; justify-content: flex-end; gap: 4px; }'
            . '.card h2 { font-size: 32px; font-weight: 600; margin: 0; line-height: 1; letter-spacing: -0.02em; color: #111111; }'
            . '.card p { font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; margin: 0; }'
            . 'label { display: flex; align-items: center; gap: 16px; margin: 14px 0; font-size: 14px; font-weight: 500; color: #111111; }'
            . 'label.checkbox-label { gap: 8px; }'
            . 'label span.field-label { flex: 0 0 220px; }'
            . 'input, select { box-sizing: border-box; flex: 1; width: 100%; padding: 10px 12px; margin-top: 0; border: 1px solid #EAEAEA; border-radius: 6px; font-family: inherit; font-size: 14px; color: #111111; background: #FFFFFF; transition: border-color 0.2s ease, box-shadow 0.2s ease; }'
            . 'input:focus, select:focus { border-color: #111111; outline: none; box-shadow: 0 0 0 1px #111111; }'
            . 'input[type=checkbox] { flex: none; width: auto; margin-top: 0; }'
            . 'button { background: #111111; color: #FFFFFF; border: 0; border-radius: 6px; padding: 10px 16px; font-size: 14px; font-weight: 600; cursor: pointer; transition: background 0.2s ease, transform 0.1s ease; }'
            . 'button:hover { background: #333333; }'
            . 'button:active { transform: scale(0.98); }'
            . 'button:disabled { opacity: 0.65; cursor: wait; }'
            . 'button.danger { background: #9F2F2D; color: #FFFFFF; }'
            . 'button.danger:hover { background: #7A2422; color: #FFFFFF; }'
            . '.row-actions button.danger { background: #F7F6F3; color: #9F2F2D; }'
            . '.row-actions button.danger:hover { background: #FDEBEC; color: #9F2F2D; }'
            . '.error { background: #FDEBEC; border: 1px solid #FDEBEC; color: #9F2F2D; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }'
            . '.notice { background: #EDF3EC; border: 1px solid #EDF3EC; color: #346538; padding: 12px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; margin-bottom: 16px; }'
            . 'code { font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; color: #787774; word-break: break-all; }'
            . 'pre { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; border-radius: 6px; overflow: auto; padding: 16px; font-family: "Geist Mono", "SF Mono", "JetBrains Mono", monospace; font-size: 13px; line-height: 1.6; white-space: pre-wrap; margin: 12px 0; }'
            . '.snippet-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 10px 0; }'
            . '.snippet-actions button { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 12px; }'
            . '.snippet-actions button:hover { background: #EAEAEA; }'
            . 'details { margin-top: 20px; border-top: 1px solid #EAEAEA; padding-top: 4px; }'
            . 'details summary { list-style: none; display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer; color: #111111; font-size: 14px; outline: none; padding: 12px 0; user-select: none; }'
            . 'details summary::-webkit-details-marker { display: none; }'
            . 'details summary::before { content: ""; flex: none; width: 0; height: 0; border-left: 5px solid #787774; border-top: 4px solid transparent; border-bottom: 4px solid transparent; transition: transform 0.15s ease; }'
            . 'details[open] summary::before { transform: rotate(90deg); }'
            . 'details summary:hover { color: #333333; }'
            . 'details summary:hover::before { border-left-color: #333333; }'
            . 'details[open] { padding-bottom: 8px; }'
            . 'details label:first-of-type { margin-top: 4px; }'
            . '.hidden { display: none !important; }'
            . '.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }'
            . '.toolbar-group { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }'
            . '.toolbar-grow { flex: 1 1 320px; }'
            . '.bucket-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; }'
            . '.bucket-card { border: 1px solid #EAEAEA; border-radius: 8px; padding: 18px; background: #FCFCFB; }'
            . '.bucket-card h3, .files-table a { margin: 0; color: #111111; text-decoration: none; }'
            . '.bucket-meta, .muted { color: #787774; font-size: 13px; }'
            . '.breadcrumbs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; font-size: 14px; }'
            . '.breadcrumbs a { color: #111111; }'
            . '.files-table-wrap { overflow-x: auto; }'
            . '.files-table { width: 100%; border-collapse: collapse; font-size: 13px; }'
            . '.files-table th, .files-table td { padding: 8px 10px; border-bottom: 1px solid #EAEAEA; text-align: left; vertical-align: middle; }'
            . '.files-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; }'
            . '.files-table tbody tr:hover { background: #FCFCFB; }'
            . '.files-table tbody tr.row-clickable { cursor: pointer; }'
            . '.row-actions { display: flex; gap: 6px; flex-wrap: wrap; }'
            . '.row-actions button, .row-actions a { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 10px; border-radius: 6px; text-decoration: none; }'
            . '.icon-btn { width: 30px; height: 30px; padding: 0; display: inline-flex; align-items: center; justify-content: center; box-sizing: border-box; }'
            . '.row-actions .icon-btn { width: 30px; height: 30px; padding: 0; }'
            . '.icon-btn:hover { background: #EAEAEA; }'
            . '.icon-btn.danger:hover { background: #FDEBEC; }'
            . '[data-tooltip] { position: relative; }'
            . '[data-tooltip]::after { content: attr(data-tooltip); position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%) translateY(4px); background: #111111; color: #FFFFFF; font-size: 11px; font-weight: 500; padding: 4px 8px; border-radius: 4px; white-space: nowrap; opacity: 0; pointer-events: none; transition: opacity 0.15s ease, transform 0.15s ease; z-index: 50; }'
            . '[data-tooltip]:hover::after { opacity: 1; transform: translateX(-50%) translateY(0); }'
            . '.preview-thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 6px; border: 1px solid #EAEAEA; background: #F7F6F3; }'
            . '.dialog-shell { position: fixed; inset: 0; z-index: 40; overflow-y: auto; }'
            . '.dialog-overlay { position: fixed; inset: 0; background: rgba(17, 17, 17, 0.25); }'
            . '.dialog-wrap { position: relative; display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 16px; }'
            . '.dialog-panel { position: relative; width: min(480px, 100%); border-radius: 12px; background: #FFFFFF; border: 1px solid #EAEAEA; padding: 24px; box-shadow: 0 24px 60px rgba(0,0,0,0.18); }'
            . '.dialog-close { position: absolute; right: 12px; top: 12px; width: 32px; height: 32px; padding: 0; display: flex; align-items: center; justify-content: center; background: transparent; color: #787774; border-radius: 50%; font-size: 18px; line-height: 1; transition: background 0.15s ease, color 0.15s ease; }'
            . '.dialog-close:hover { background: #F2F1EE; color: #111111; }'
            . '.dialog-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }'
            . '.dialog-secondary { background: transparent; color: #111111; border: 1px solid #EAEAEA; }'
            . '.dialog-secondary:hover { background: #F7F6F3; color: #111111; }'
            . '.dialog-error { background: #FDEBEC; border: 1px solid #F7C9C7; color: #9F2F2D; padding: 10px 12px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 16px; }'
            . '.status-line { margin-bottom: 16px; font-size: 14px; }'
            . '.status-line.error-text { color: #9F2F2D; }'
            . '@media (max-width: 720px) { main { padding: 0 16px; } .files-table thead { display: none; } .files-table, .files-table tbody, .files-table tr, .files-table td { display: block; width: 100%; } .files-table tr { padding: 12px 0; } .files-table td { border-bottom: 0; padding: 6px 0; } }'
            . 'header nav a[aria-current="page"] { color: #111111; text-decoration: underline; text-underline-offset: 4px; }'
            . 'header nav { align-items: center; flex-wrap: wrap; }'
            . 'form.plain { background: none; border: 0; padding: 0; margin: 0; }'
            . 'main a { color: #1F6C9F; }'
            . 'main .breadcrumbs a, main .files-table a, main .bucket-card a, main .row-actions a { color: #111111; }'
            . '.icon-btn { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; border-radius: 6px; vertical-align: middle; }'
            . '.update-actions { display: flex; gap: 10px; flex-wrap: wrap; }'
            . '.nav-logout { display: inline; }'
            . '.link-button { background: none; border: 0; padding: 0; color: #787774; font-size: 14px; font-weight: 500; cursor: pointer; text-decoration: none; }'
            . '.link-button:hover { background: none; color: #111111; text-decoration: underline; text-underline-offset: 4px; }'
            . '.lead { color: #444444; font-size: 15px; line-height: 1.6; margin: -8px 0 20px; }'
            . 'fieldset { border: 0; padding: 0; margin: 0 0 12px; }'
            . 'legend { font-size: 16px; font-weight: 600; padding: 0; margin-bottom: 4px; }'
            . '.field { display: grid; grid-template-columns: 220px 1fr; gap: 6px 16px; margin: 18px 0; align-items: start; }'
            . '.field > .field-label { display: block; margin: 0; padding-top: 10px; font-size: 14px; font-weight: 500; }'
            . '.field > div > label.checkbox-label { margin: 10px 0 0; }'
            . '.field input:disabled { background: #F7F6F3; color: #787774; }'
            . '.help { font-size: 13px; color: #787774; line-height: 1.55; margin: 6px 0 0; }'
            . '.help code, .lead code, .muted code { font-size: 12px; }'
            . '.help-list { padding-left: 20px; line-height: 1.7; font-size: 14px; }'
            . '.help-list li { margin: 4px 0; }'
            . '.badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; vertical-align: middle; }'
            . '.badge-rw { background: #EDF3EC; color: #346538; }'
            . '.badge-read { background: #E1F3FE; color: #1F6C9F; }'
            . '.badge-env { background: #F2F1EE; color: #5F5E5B; font-weight: 500; }'
            . '.warning { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; background: #FBF3DB; border: 1px solid #F1E3B5; color: #7A5B12; padding: 12px 16px; border-radius: 6px; font-size: 14px; line-height: 1.5; margin-bottom: 16px; }'
            . '.warning .link-button { color: #7A5B12; flex: none; }'
            . '.panel-highlight { border-color: #346538; }'
            . '.key-details { display: grid; grid-template-columns: 120px 1fr; gap: 10px 16px; align-items: center; }'
            . '.key-details dt { color: #787774; font-size: 13px; }'
            . '.key-details dd { margin: 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }'
            . '.key-details code, .strong-code, .keys-table code { color: #111111; }'
            . '.inline-form { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }'
            . 'label.stacked { display: flex; flex-direction: column; align-items: stretch; gap: 6px; margin: 8px 0; font-size: 13px; }'
            . '.inline-form label.stacked { flex: 1 1 200px; margin: 0; }'
            . '.secret-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 6px; font-size: 13px; }'
            . '.secret-row .link-button { font-size: 13px; }'
            . '.row-details { margin: 0 0 6px; border: 0; padding: 0; }'
            . '.row-details summary { padding: 2px 0; font-weight: 500; font-size: 13px; }'
            . '.row-details summary::before { display: none; }'
            . '.row-details form { margin: 8px 0 12px; display: flex; flex-direction: column; gap: 8px; min-width: 220px; }'
            . '.danger-text { color: #9F2F2D !important; }'
            . '.tabs { display: flex; gap: 6px; flex-wrap: wrap; margin: 16px 0 4px; }'
            . '.tab { background: #F7F6F3; color: #111111; border: 1px solid #EAEAEA; font-weight: 500; font-size: 13px; padding: 6px 12px; }'
            . '.tab:hover { background: #EAEAEA; }'
            . '.tab.active { background: #111111; color: #FFFFFF; border-color: #111111; }'
            . '@media (max-width: 720px) { .field { grid-template-columns: 1fr; } .field > .field-label { padding-top: 0; } .key-details { grid-template-columns: 1fr; } }'
            . '</style>'
            . '<script defer src="' . $this->e(AdminAssets::alpinePath()) . '"></script>'
            . '</head><body><header><strong>Mini S3</strong>' . $navigation . '</header><main><h1>' . $this->e($title) . '</h1>' . $body . '</main></body></html>';
    }

    private function filesBreadcrumbs(string $currentBucket, string $currentPrefix): string
    {
        if ($currentBucket === '') {
            return '<div class="breadcrumbs"><strong>All buckets</strong></div>';
        }

        $html = '<div class="breadcrumbs"><a href="/_/files">Buckets</a>';
        $html .= '<span>/</span><a href="/_/files?bucket=' . rawurlencode($currentBucket) . '">' . $this->e($currentBucket) . '</a>';
        if ($currentPrefix !== '') {
            $segments = explode('/', $currentPrefix);
            $path = '';
            foreach ($segments as $segment) {
                $path = $path === '' ? $segment : $path . '/' . $segment;
                $html .= '<span>/</span><a href="/_/files?bucket=' . rawurlencode($currentBucket) . '&prefix=' . rawurlencode($path) . '">' . $this->e($segment) . '</a>';
            }
        }

        return $html . '</div>';
    }

    private function bucketGrid(array $buckets): string
    {
        if ($buckets === []) {
            return '<div class="empty-state"><p><strong>No buckets yet.</strong></p>'
                . '<p class="muted">A bucket is a top-level folder for your objects. Click <em>New bucket</em> above, or let your app create one through the S3 API. Bucket names use lowercase letters, numbers, dots and hyphens (3 to 63 characters).</p></div>';
        }

        $html = '<div class="bucket-grid">';
        foreach ($buckets as $bucket) {
            $name = (string) ($bucket['name'] ?? '');
            $html .= '<section class="bucket-card" data-searchable="' . $this->e(strtolower($name)) . '">'
                . '<h3><a href="/_/files?bucket=' . rawurlencode($name) . '">' . $this->e($name) . '</a></h3>'
                . '<p class="bucket-meta">' . $this->e((string) ($bucket['object_count'] ?? 0)) . ' objects</p>'
                . '<p class="bucket-meta">' . $this->e($this->formatBytes((int) ($bucket['total_bytes'] ?? 0))) . '</p>'
                . '<div class="row-actions">'
                . $this->iconButton(self::ICON_RENAME, 'Rename', 'x-on:click="renameBucket(' . $this->jsString($name) . ')"')
                . $this->iconButton(self::ICON_DELETE, 'Delete', 'x-on:click="deleteBucket(' . $this->jsString($name) . ')"', 'danger')
                . '</div></section>';
        }

        return $html . '</div>';
    }

    private function objectTable(string $bucket, string $prefix, array $listing): string
    {
        $folders = (array) ($listing['folders'] ?? []);
        $files = (array) ($listing['files'] ?? []);
        $html = '<div class="status-line muted" x-bind:class="statusError ? \'error-text\' : \'\'" x-text="statusMessage"></div>';
        $html .= '<div class="row-actions" style="margin-bottom:12px;"><button type="button" class="danger" x-bind:disabled="selectedItems.length===0" x-on:click="bulkDelete()">Delete selected<template x-if="selectedItems.length"><span x-text="\' (\' + selectedItems.length + \')\'"></span></template></button></div>';
        $html .= '<div class="files-table-wrap"><table class="files-table"><thead><tr><th><input type="checkbox" x-bind:checked="allSelected" x-on:change="toggleSelectAll($event.target.checked)" aria-label="Select all"></th><th>Name</th><th>Preview</th><th>Type</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>';

        if ($prefix !== '') {
            $parent = dirname($prefix);
            $parent = $parent === '.' ? '' : $parent;
            $html .= '<tr><td></td><td colspan="6"><a href="/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($parent) . '">..</a></td></tr>';
        }

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            $name = (string) ($folder['name'] ?? '');
            $folderUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($path);
            $html .= '<tr class="row-clickable" data-searchable="' . $this->e(strtolower($name)) . '" x-on:click="window.location.href=' . $this->jsString($folderUrl) . '">'
                . '<td x-on:click.stop><input type="checkbox" value="' . $this->e($path) . '" x-model="selectedItems"></td>'
                . '<td><a href="' . $folderUrl . '">' . $this->e($name) . '</a></td>'
                . '<td>-</td><td>Folder</td>'
                . '<td>' . $this->e((string) ($folder['object_count'] ?? 0)) . ' items</td>'
                . '<td>' . $this->e($this->formatDate((int) ($folder['modified'] ?? 0))) . '</td>'
                . '<td x-on:click.stop><div class="row-actions">'
                . $this->iconButton(self::ICON_RENAME, 'Rename', 'x-on:click="renameObject(' . $this->jsString($path) . ', ' . $this->jsString($name) . ')"')
                . $this->iconButton(self::ICON_DELETE, 'Delete', 'x-on:click="deleteObject(' . $this->jsString($path) . ')"', 'danger')
                . '</div></td></tr>';
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? '');
            $preview = '-';
            if (!empty($file['is_image']) && ($file['mime'] ?? '') !== 'image/svg+xml') {
                $previewUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=0';
                $preview = '<a href="' . $previewUrl . '" target="_blank" x-on:click.stop><img class="preview-thumb" src="' . $previewUrl . '" alt="' . $this->e($name) . '"></a>';
            }
            $downloadUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=1';
            $html .= '<tr class="row-clickable" data-searchable="' . $this->e(strtolower($name)) . '" x-on:click="window.location.href=' . $this->jsString($downloadUrl) . '">'
                . '<td x-on:click.stop><input type="checkbox" value="' . $this->e($path) . '" x-model="selectedItems"></td>'
                . '<td>' . $this->e($name) . '</td>'
                . '<td>' . $preview . '</td>'
                . '<td>' . $this->e((string) ($file['mime'] ?? 'file')) . '</td>'
                . '<td>' . $this->e($this->formatBytes((int) ($file['size'] ?? 0))) . '</td>'
                . '<td>' . $this->e($this->formatDate((int) ($file['modified'] ?? 0))) . '</td>'
                . '<td x-on:click.stop><div class="row-actions">'
                . $this->iconButton(self::ICON_DOWNLOAD, 'Download', 'href="' . $downloadUrl . '"', '', true)
                . $this->iconButton(self::ICON_RENAME, 'Rename', 'x-on:click="renameObject(' . $this->jsString($path) . ', ' . $this->jsString($name) . ')"')
                . $this->iconButton(self::ICON_DELETE, 'Delete', 'x-on:click="deleteObject(' . $this->jsString($path) . ')"', 'danger')
                . '</div></td></tr>';
        }

        if ($folders === [] && $files === []) {
            $html .= '<tr><td colspan="7" class="muted">This location is empty. Use <em>Upload file</em> or <em>New folder</em> above.</td></tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private function filesModal(): string
    {
        return '<div x-dialog x-model="dialogOpen" x-cloak class="dialog-shell">'
            . '<div x-dialog:overlay x-transition.opacity class="dialog-overlay"></div>'
            . '<div class="dialog-wrap">'
            . '<div x-dialog:panel x-transition class="dialog-panel">'
            . '<button type="button" class="dialog-close" x-on:click="$dialog.close()" aria-label="Close">&times;</button>'
            . '<div>'
            . '<h2 x-dialog:title x-text="dialogTitle"></h2>'
            . '<div class="mt-2 muted"><p x-text="dialogMessage"></p></div>'
            . '</div>'
            . '<div x-show="dialogError" x-cloak class="dialog-error" x-text="dialogError"></div>'
            . '<label x-show="!dialogShowFile && !dialogIsConfirm" x-cloak>Name<input x-ref="dialogNameInput" x-model="dialogName" x-on:keydown.enter="submitDialog()"></label>'
            . '<label x-show="dialogShowFile" x-cloak>File<input type="file" x-ref="uploadFile"></label>'
            . '<div class="dialog-actions">'
            . '<button type="button" class="dialog-secondary" x-on:click="$dialog.close()">Cancel</button>'
            . '<button type="button" x-on:click="submitDialog()" x-bind:disabled="busy" x-bind:class="dialogIsConfirm ? \'danger\' : \'\'" x-text="busy ? \'Working...\' : (dialogIsConfirm ? \'Delete\' : \'Confirm\')"></button>'
            . '</div>'
            . '</div></div></div>';
    }

    private function filesScript(): string
    {
        return '<script>'
            . 'function miniS3Explorer(csrf,bucket,prefix,summary){return {'
            . 'csrf:csrf,bucket:bucket,prefix:prefix,search:"",selectedItems:[],statusMessage:summary,statusError:false,busy:false,dialogOpen:false,dialogAction:"",dialogTitle:"",dialogMessage:"",dialogPath:"",dialogName:"",dialogShowFile:false,dialogIsConfirm:false,dialogConfirmPayload:null,dialogError:"",'
            . 'init(){const key="miniS3:"+this.bucket+":"+this.prefix;const saved=sessionStorage.getItem(key);if(saved){try{const s=JSON.parse(saved);if(typeof s.search==="string"){this.search=s.search;this.$nextTick(()=>this.filterList());}if(typeof s.scrollY==="number"){this.$nextTick(()=>window.scrollTo(0,s.scrollY));}}catch(e){}sessionStorage.removeItem(key);}},'
            . 'saveState(){sessionStorage.setItem("miniS3:"+this.bucket+":"+this.prefix,JSON.stringify({search:this.search,scrollY:window.scrollY}));},'
            . 'filterList(){const q=this.search.trim().toLowerCase();document.querySelectorAll("[data-searchable]").forEach((el)=>{el.classList.toggle("hidden",q!==""&&!String(el.dataset.searchable||"").includes(q));});},'
            . 'visibleCheckboxes(){return Array.from(document.querySelectorAll(".files-table tbody tr:not(.hidden) input[type=checkbox]"));},'
            . 'get allSelected(){const boxes=this.visibleCheckboxes();return boxes.length>0&&boxes.every((b)=>this.selectedItems.includes(b.value));},'
            . 'toggleSelectAll(checked){const values=this.visibleCheckboxes().map((b)=>b.value);if(checked){this.selectedItems=Array.from(new Set([...this.selectedItems,...values]));}else{this.selectedItems=this.selectedItems.filter((v)=>!values.includes(v));}},'
            . 'setStatus(message,isError=false){if(this.dialogOpen){this.dialogError=isError?message:"";return;}this.statusMessage=message;this.statusError=isError;},'
            . 'openDialog(title,message,action,path,name,showFile){this.dialogIsConfirm=false;this.dialogConfirmPayload=null;this.dialogError="";this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction=action;this.dialogPath=path||"";this.dialogName=name||"";this.dialogShowFile=showFile;this.dialogOpen=true;this.busy=false;if(!showFile){this.$nextTick(()=>{const el=this.$refs.dialogNameInput;if(el){el.focus();el.select();}});}},'
            . 'openConfirm(title,message,payload){this.dialogIsConfirm=true;this.dialogConfirmPayload=payload;this.dialogError="";this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction="";this.dialogPath="";this.dialogName="";this.dialogShowFile=false;this.dialogOpen=true;this.busy=false;},'
            . 'openCreateBucket(){this.openDialog("Create bucket","Enter a new bucket name.","create_bucket","","",false);},'
            . 'openCreateFolder(){this.openDialog("Create folder","Create a folder inside the current bucket.","create_folder","","",false);},'
            . 'openUpload(){this.openDialog("Upload file","Upload to the current folder.","upload","","",true);},'
            . 'renameBucket(name){this.openDialog("Rename bucket","Choose a new bucket name.","rename_bucket",name,name,false);},'
            . 'renameObject(path,name){this.openDialog("Rename item","Choose a new name.","rename_object",path,name,false);},'
            . 'deleteBucket(name){this.openConfirm("Delete bucket","Delete bucket "+name+" and all its contents?",{action:"delete_bucket",csrf_token:this.csrf,bucket:name});},'
            . 'deleteObject(path){this.openConfirm("Delete item","Delete this item?",{action:"delete_object",csrf_token:this.csrf,bucket:this.bucket,path:path});},'
            . 'bulkDelete(){if(this.selectedItems.length===0){this.setStatus("Select at least one item.",true);return;}this.openConfirm("Delete selected","Delete "+this.selectedItems.length+" selected item(s)?",{action:"bulk_delete",csrf_token:this.csrf,bucket:this.bucket,prefix:this.prefix,items:this.selectedItems});},'
            . 'submitDialog(){if(this.dialogIsConfirm){if(this.dialogConfirmPayload){this.request(this.dialogConfirmPayload);}return;}if(this.dialogAction!=="upload"&&this.dialogName.trim()===""){this.setStatus("Name is required.",true);return;}if(this.dialogAction==="upload"){const file=this.$refs.uploadFile?.files?.[0];if(!file){this.setStatus("Choose a file first.",true);return;}const fd=new FormData();fd.append("csrf_token",this.csrf);fd.append("action","upload");fd.append("bucket",this.bucket);fd.append("prefix",this.prefix);fd.append("file",file);this.request(fd,true);return;}const payload={action:this.dialogAction,csrf_token:this.csrf,bucket:this.bucket,prefix:this.prefix};if(this.dialogAction==="create_bucket"||this.dialogAction==="rename_bucket"){payload.name=this.dialogName.trim();}if(this.dialogAction==="create_folder"){payload.path=this.prefix?this.prefix+"/"+this.dialogName.trim():this.dialogName.trim();}if(this.dialogAction==="rename_object"){payload.path=this.dialogPath;payload.name=this.dialogName.trim();}this.request(payload);},'
            . 'async request(payload,isForm=false){if(this.busy){return;}this.busy=true;this.setStatus("",false);const options={method:"POST",headers:{Accept:"application/json"}};if(isForm){options.body=payload;}else{const fd=new FormData();Object.entries(payload).forEach(([key,value])=>{if(Array.isArray(value)){value.forEach((item)=>fd.append(key+"[]",item));}else{fd.append(key,String(value));}});options.body=fd;}try{const res=await fetch("/_/files",options);const data=await res.json().catch(()=>({ok:false,message:"Invalid response"}));if(!res.ok||!data.ok){this.setStatus(data.message||"Request failed",true);this.busy=false;return;}this.saveState();window.location.href=data.redirect||"/_/files";}catch(e){this.setStatus("Request failed. Check your connection and try again.",true);this.busy=false;}}
'
            . '}}'
            . '</script>';
    }

    private function statCard(string $label, string $value): string
    {
        return '<section class="card"><h2>' . $this->e($value) . '</h2><p>' . $this->e($label) . '</p></section>';
    }


    private function flashMessage(string $message): string
    {
        if ($message === '') {
            return '';
        }
        if (str_starts_with($message, 'Error: ')) {
            return '<div class="error" role="alert">' . $this->e(substr($message, 7)) . '</div>';
        }

        return '<div class="notice" role="status">' . $this->e($message) . '</div>';
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
        $actions = '';
        if ($state !== 'unavailable') {
            $actions .= '<form method="post" action="/_/check-update" class="plain">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit">Check update</button>'
                . '</form>';
        }
        if ($state === 'update_available' && is_string($latest) && $latest !== '') {
            $actions .= '<form method="post" action="/_/upgrade" class="plain">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit">Upgrade to ' . $this->e($latest) . '</button>'
                . '</form>';
        }

        if ($actions !== '') {
            $body .= '<div class="update-actions">' . $actions . '</div>';
        }

        return '<section class="panel"><h2>Updates</h2>' . $body . '</section>';
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

    private function formatDate(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
    }

    private function jsString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG);
        if ($encoded === false) {
            return "''";
        }
        return "'" . substr($encoded, 1, -1) . "'";
    }

    private function iconButton(string $icon, string $label, string $attrs, string $class = '', bool $asLink = false): string
    {
        $class = trim('icon-btn ' . $class);
        $tag = $asLink ? 'a' : 'button';
        $type = $asLink ? '' : ' type="button"';
        return '<' . $tag . $type . ' class="' . $this->e($class) . '" data-tooltip="' . $this->e($label) . '" aria-label="' . $this->e($label) . '" ' . $attrs . '>' . $icon . '</' . $tag . '>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
