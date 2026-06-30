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

        return $this->layout('Files', $body, true);
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
        $navigation = $nav ? '<nav><a href="/_">Dashboard</a><a href="/_/files">Files</a><a href="/_/config">Config</a><a href="/_/logout">Logout</a></nav>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($title) . '</title>'
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
            . 'label { display: block; margin: 18px 0 6px 0; font-size: 14px; font-weight: 500; color: #111111; }'
            . 'input, select { box-sizing: border-box; width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid #EAEAEA; border-radius: 6px; font-family: inherit; font-size: 14px; color: #111111; background: #FFFFFF; transition: border-color 0.2s ease, box-shadow 0.2s ease; }'
            . 'input:focus, select:focus { border-color: #111111; outline: none; box-shadow: 0 0 0 1px #111111; }'
            . 'input[type=checkbox] { width: auto; margin-top: 0; margin-right: 8px; vertical-align: middle; }'
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
            . 'details { margin-top: 18px; border-top: 1px solid #EAEAEA; padding-top: 12px; }'
            . 'details summary { font-weight: 600; cursor: pointer; color: #111111; font-size: 14px; outline: none; }'
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
            . '.files-table { width: 100%; border-collapse: collapse; }'
            . '.files-table th, .files-table td { padding: 12px 10px; border-bottom: 1px solid #EAEAEA; text-align: left; vertical-align: middle; }'
            . '.files-table th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #787774; }'
            . '.files-table tbody tr:hover { background: #FCFCFB; }'
            . '.row-actions { display: flex; gap: 8px; flex-wrap: wrap; }'
            . '.row-actions button, .row-actions a { background: #F7F6F3; border: 1px solid #EAEAEA; color: #111111; font-weight: 500; font-size: 12px; padding: 6px 10px; border-radius: 6px; text-decoration: none; }'
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
            . '.status-line { margin-bottom: 16px; font-size: 14px; }'
            . '.status-line.error-text { color: #9F2F2D; }'
            . '@media (max-width: 720px) { main { padding: 0 16px; } .files-table thead { display: none; } .files-table, .files-table tbody, .files-table tr, .files-table td { display: block; width: 100%; } .files-table tr { padding: 12px 0; } .files-table td { border-bottom: 0; padding: 6px 0; } }'
            . '</style>'
            . '<script defer src="https://unpkg.com/@alpinejs/ui@3.x.x/dist/cdn.min.js"></script>'
            . '<script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>'
            . '<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>'
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
            return '<p class="muted">No buckets yet.</p>';
        }

        $html = '<div class="bucket-grid">';
        foreach ($buckets as $bucket) {
            $name = (string) ($bucket['name'] ?? '');
            $html .= '<section class="bucket-card" data-searchable="' . $this->e(strtolower($name)) . '">'
                . '<h3><a href="/_/files?bucket=' . rawurlencode($name) . '">' . $this->e($name) . '</a></h3>'
                . '<p class="bucket-meta">' . $this->e((string) ($bucket['object_count'] ?? 0)) . ' objects</p>'
                . '<p class="bucket-meta">' . $this->e($this->formatBytes((int) ($bucket['total_bytes'] ?? 0))) . '</p>'
                . '<div class="row-actions">'
                . '<button type="button" x-on:click="renameBucket(' . $this->jsString($name) . ')">Rename</button>'
                . '<button type="button" class="danger" x-on:click="deleteBucket(' . $this->jsString($name) . ')">Delete</button>'
                . '</div></section>';
        }

        return $html . '</div>';
    }

    private function objectTable(string $bucket, string $prefix, array $listing): string
    {
        $folders = (array) ($listing['folders'] ?? []);
        $files = (array) ($listing['files'] ?? []);
        $html = '<div class="status-line muted" x-bind:class="statusError ? \'error-text\' : \'\'" x-text="statusMessage"></div>';
        $html .= '<div class="row-actions" style="margin-bottom:12px;"><button type="button" class="danger" x-on:click="bulkDelete()">Delete selected</button></div>';
        $html .= '<div class="files-table-wrap"><table class="files-table"><thead><tr><th></th><th>Name</th><th>Preview</th><th>Type</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>';

        if ($prefix !== '') {
            $parent = dirname($prefix);
            $parent = $parent === '.' ? '' : $parent;
            $html .= '<tr><td></td><td colspan="6"><a href="/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($parent) . '">..</a></td></tr>';
        }

        foreach ($folders as $folder) {
            $path = (string) ($folder['path'] ?? '');
            $name = (string) ($folder['name'] ?? '');
            $html .= '<tr data-searchable="' . $this->e(strtolower($name)) . '">'
                . '<td><input type="checkbox" value="' . $this->e($path) . '" x-model="selectedItems"></td>'
                . '<td><a href="/_/files?bucket=' . rawurlencode($bucket) . '&prefix=' . rawurlencode($path) . '">' . $this->e($name) . '</a></td>'
                . '<td>-</td><td>Folder</td>'
                . '<td>' . $this->e((string) ($folder['object_count'] ?? 0)) . ' items</td>'
                . '<td>' . $this->e($this->formatDate((int) ($folder['modified'] ?? 0))) . '</td>'
                . '<td><div class="row-actions">'
                . '<button type="button" x-on:click="renameObject(' . $this->jsString($path) . ', ' . $this->jsString($name) . ')">Rename</button>'
                . '<button type="button" class="danger" x-on:click="deleteObject(' . $this->jsString($path) . ')">Delete</button>'
                . '</div></td></tr>';
        }

        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? '');
            $name = (string) ($file['name'] ?? '');
            $preview = '-';
            if (!empty($file['is_image'])) {
                $previewUrl = '/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=0';
                $preview = '<a href="' . $previewUrl . '" target="_blank"><img class="preview-thumb" src="' . $previewUrl . '" alt="' . $this->e($name) . '"></a>';
            }
            $html .= '<tr data-searchable="' . $this->e(strtolower($name)) . '">'
                . '<td><input type="checkbox" value="' . $this->e($path) . '" x-model="selectedItems"></td>'
                . '<td>' . $this->e($name) . '</td>'
                . '<td>' . $preview . '</td>'
                . '<td>' . $this->e((string) ($file['mime'] ?? 'file')) . '</td>'
                . '<td>' . $this->e($this->formatBytes((int) ($file['size'] ?? 0))) . '</td>'
                . '<td>' . $this->e($this->formatDate((int) ($file['modified'] ?? 0))) . '</td>'
                . '<td><div class="row-actions">'
                . '<a href="/_/files?bucket=' . rawurlencode($bucket) . '&path=' . rawurlencode($path) . '&download=1">Download</a>'
                . '<button type="button" x-on:click="renameObject(' . $this->jsString($path) . ', ' . $this->jsString($name) . ')">Rename</button>'
                . '<button type="button" class="danger" x-on:click="deleteObject(' . $this->jsString($path) . ')">Delete</button>'
                . '</div></td></tr>';
        }

        if ($folders === [] && $files === []) {
            $html .= '<tr><td colspan="7" class="muted">This location is empty.</td></tr>';
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
            . 'csrf:csrf,bucket:bucket,prefix:prefix,search:"",selectedItems:[],statusMessage:summary,statusError:false,busy:false,dialogOpen:false,dialogAction:"",dialogTitle:"",dialogMessage:"",dialogPath:"",dialogName:"",dialogShowFile:false,dialogIsConfirm:false,dialogConfirmPayload:null,'
            . 'init(){const key="miniS3:"+this.bucket+":"+this.prefix;const saved=sessionStorage.getItem(key);if(saved){try{const s=JSON.parse(saved);if(typeof s.search==="string"){this.search=s.search;this.$nextTick(()=>this.filterList());}if(typeof s.scrollY==="number"){this.$nextTick(()=>window.scrollTo(0,s.scrollY));}}catch(e){}sessionStorage.removeItem(key);}},'
            . 'saveState(){sessionStorage.setItem("miniS3:"+this.bucket+":"+this.prefix,JSON.stringify({search:this.search,scrollY:window.scrollY}));},'
            . 'filterList(){const q=this.search.trim().toLowerCase();document.querySelectorAll("[data-searchable]").forEach((el)=>{el.classList.toggle("hidden",q!==""&&!String(el.dataset.searchable||"").includes(q));});},'
            . 'setStatus(message,isError=false){this.statusMessage=message;this.statusError=isError;},'
            . 'openDialog(title,message,action,path,name,showFile){this.dialogIsConfirm=false;this.dialogConfirmPayload=null;this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction=action;this.dialogPath=path||"";this.dialogName=name||"";this.dialogShowFile=showFile;this.dialogOpen=true;this.busy=false;if(!showFile){this.$nextTick(()=>{const el=this.$refs.dialogNameInput;if(el){el.focus();el.select();}});}},'
            . 'openConfirm(title,message,payload){this.dialogIsConfirm=true;this.dialogConfirmPayload=payload;this.dialogTitle=title;this.dialogMessage=message||"";this.dialogAction="";this.dialogPath="";this.dialogName="";this.dialogShowFile=false;this.dialogOpen=true;this.busy=false;},'
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

    private function formatDate(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i', $timestamp) : '-';
    }

    private function jsString(string $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($encoded === false) {
            return "''";
        }
        return "'" . substr($encoded, 1, -1) . "'";
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
