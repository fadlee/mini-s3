package admin

import (
	"encoding/json"
	"fmt"
	"html"
	"math"
	"net/url"
	"strings"
	"time"

	"github.com/fadlee/mini-s3/internal/config"
)

const iconRename = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`
const iconDelete = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`
const iconDownload = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>`

// AdminRenderer generates the HTML for the admin panel.
type AdminRenderer struct{}

// NewAdminRenderer creates a new renderer.
func NewAdminRenderer() *AdminRenderer {
	return &AdminRenderer{}
}

// Installer renders the first-time setup page.
func (r *AdminRenderer) Installer(values map[string]string, errors []string, csrfToken string) string {
	return r.layout("Install Mini S3", r.form("/_", values, errors, csrfToken, true), false)
}

// Login renders the login page.
func (r *AdminRenderer) Login(errMsg, csrfToken string) string {
	var b strings.Builder
	if errMsg != "" {
		b.WriteString(`<div class="error">`)
		b.WriteString(r.e(errMsg))
		b.WriteString(`</div>`)
	}
	b.WriteString(`<form method="post" action="/_">`)
	b.WriteString(`<input type="hidden" name="csrf_token" value="`)
	b.WriteString(r.e(csrfToken))
	b.WriteString(`">`)
	b.WriteString(`<label>Username<input name="username" autocomplete="username" required></label>`)
	b.WriteString(`<label>Password<input type="password" name="password" autocomplete="current-password" required></label>`)
	b.WriteString(`<button type="submit">Log in</button>`)
	b.WriteString(`</form>`)
	return r.layout("Mini S3 Admin Login", b.String(), false)
}

// Dashboard renders the main dashboard page.
func (r *AdminRenderer) Dashboard(stats Stats, cfg *config.Config, endpoint string, updateStatus map[string]interface{}, flashMessage string) string {
	var b strings.Builder
	b.WriteString(r.flashMessage(flashMessage))
	b.WriteString(`<div class="cards">`)
	b.WriteString(r.statCard("Buckets", fmt.Sprintf("%d", stats.BucketCount)))
	b.WriteString(r.statCard("Objects", fmt.Sprintf("%d", stats.ObjectCount)))
	b.WriteString(r.statCard("Storage", formatBytes(stats.TotalBytes)))
	b.WriteString(r.statCard("Data Dir", r.e(stats.Status)))
	b.WriteString(`</div>`)
	b.WriteString(`<section class="panel"><h2>Data directory</h2><code>`)
	b.WriteString(r.e(stats.DataDir))
	b.WriteString(`</code></section>`)
	b.WriteString(r.updatesPanel(updateStatus))
	b.WriteString(r.connectionConfig(cfg, endpoint))
	return r.layout("Dashboard", b.String(), true)
}

// ConfigPage renders the config edit page.
func (r *AdminRenderer) ConfigPage(values map[string]interface{}, errors []string, csrfToken string) string {
	return r.layout("Config", r.formInterface("/_/config", values, errors, csrfToken, false), true)
}

// Files renders the file explorer page.
func (r *AdminRenderer) Files(buckets []BucketInfo, listing *ListObjectsResult, currentBucket, currentPrefix, csrfToken, flashMessage string) string {
	folderCount := 0
	fileCount := 0
	if listing != nil {
		folderCount = len(listing.Folders)
		fileCount = len(listing.Files)
	}
	summary := fmt.Sprintf("%d folders, %d files", folderCount, fileCount)

	var b strings.Builder
	b.WriteString(`<div x-data="miniS3Explorer(`)
	b.WriteString(jsString(csrfToken))
	b.WriteString(`, `)
	b.WriteString(jsString(currentBucket))
	b.WriteString(`, `)
	b.WriteString(jsString(currentPrefix))
	b.WriteString(`, `)
	b.WriteString(jsString(summary))
	b.WriteString(`)" x-init="init()">`)
	b.WriteString(r.flashMessage(flashMessage))
	b.WriteString(`<section class="panel">`)
	b.WriteString(`<div class="toolbar">`)
	b.WriteString(`<div class="toolbar-group toolbar-grow"><input type="search" placeholder="Search current list" x-model="search" x-on:input="filterList()"></div>`)
	b.WriteString(`<div class="toolbar-group">`)
	b.WriteString(`<button type="button" x-on:click="openCreateBucket()">New bucket</button>`)
	if currentBucket != "" {
		b.WriteString(`<button type="button" x-on:click="openCreateFolder()">New folder</button><button type="button" x-on:click="openUpload()">Upload file</button>`)
	}
	b.WriteString(`</div></div>`)
	b.WriteString(r.filesBreadcrumbs(currentBucket, currentPrefix))
	if currentBucket == "" {
		b.WriteString(r.bucketGrid(buckets))
	} else {
		b.WriteString(r.objectTable(currentBucket, currentPrefix, listing))
	}
	b.WriteString(`</section>`)
	b.WriteString(r.filesModal())
	b.WriteString(r.filesScript())
	b.WriteString(`</div>`)
	return r.layout("Files", b.String(), true)
}

// --- private helpers ---

func (r *AdminRenderer) form(action string, values map[string]string, errors []string, csrfToken string, installer bool) string {
	return r.formInterface(action, stringMapToInterface(values), errors, csrfToken, installer)
}

func (r *AdminRenderer) formInterface(action string, values map[string]interface{}, errors []string, csrfToken string, installer bool) string {
	var b strings.Builder
	for _, errMsg := range errors {
		b.WriteString(`<div class="error">`)
		b.WriteString(r.e(errMsg))
		b.WriteString(`</div>`)
	}

	passwordLabel := "New admin password"
	passwordRequired := ""
	if installer {
		passwordLabel = "Admin password"
		passwordRequired = " required"
	}

	b.WriteString(`<form method="post" action="`)
	b.WriteString(r.e(action))
	b.WriteString(`">`)
	b.WriteString(`<input type="hidden" name="csrf_token" value="`)
	b.WriteString(r.e(csrfToken))
	b.WriteString(`">`)

	b.WriteString(`<label><span class="field-label">Admin username</span><input name="admin_username" value="`)
	b.WriteString(r.e(getStr(values, "admin_username", "admin")))
	b.WriteString(`" required></label>`)

	b.WriteString(`<label><span class="field-label">`)
	b.WriteString(passwordLabel)
	b.WriteString(`</span><input type="password" name="admin_password"`)
	b.WriteString(passwordRequired)
	b.WriteString(`></label>`)

	b.WriteString(`<label><span class="field-label">Confirm admin password</span><input type="password" name="admin_password_confirm"`)
	b.WriteString(passwordRequired)
	b.WriteString(`></label>`)

	b.WriteString(`<label><span class="field-label">Data directory</span><input name="data_dir" value="`)
	b.WriteString(r.e(getStr(values, "data_dir", "")))
	b.WriteString(`" required></label>`)

	b.WriteString(`<label><span class="field-label">Access key</span><input name="access_key" value="`)
	b.WriteString(r.e(getStr(values, "access_key", "")))
	b.WriteString(`" required></label>`)

	b.WriteString(`<label><span class="field-label">Secret key</span><input type="password" name="secret_key" value="`)
	b.WriteString(r.e(getStr(values, "secret_key", "")))
	b.WriteString(`" required></label>`)

	b.WriteString(`<label class="checkbox-label"><input type="checkbox" name="public_read_all_buckets" value="1"`)
	b.WriteString(checked(values, "public_read_all_buckets"))
	b.WriteString(`> Public read all buckets</label>`)

	b.WriteString(`<details><summary>Advanced</summary>`)
	b.WriteString(`<label><span class="field-label">Max request size</span><input type="number" min="1" name="max_request_size" value="`)
	b.WriteString(r.e(getStr(values, "max_request_size", "104857600")))
	b.WriteString(`"></label>`)

	b.WriteString(`<label><span class="field-label">Auth debug log</span><input name="auth_debug_log" value="`)
	b.WriteString(r.e(getStr(values, "auth_debug_log", "")))
	b.WriteString(`"></label>`)

	b.WriteString(`<label class="checkbox-label"><input type="checkbox" name="allow_host_candidate_fallbacks" value="1"`)
	b.WriteString(checked(values, "allow_host_candidate_fallbacks"))
	b.WriteString(`> Allow host candidate fallbacks</label>`)

	b.WriteString(`<label><span class="field-label">Clock skew seconds</span><input type="number" min="1" name="clock_skew_seconds" value="`)
	b.WriteString(r.e(getStr(values, "clock_skew_seconds", "900")))
	b.WriteString(`"></label>`)

	b.WriteString(`<label><span class="field-label">Max presign expires</span><input type="number" min="1" name="max_presign_expires" value="`)
	b.WriteString(r.e(getStr(values, "max_presign_expires", "604800")))
	b.WriteString(`"></label>`)

	b.WriteString(`</details>`)
	b.WriteString(`<button type="submit">Save</button>`)
	b.WriteString(`</form>`)
	return b.String()
}

func (r *AdminRenderer) layout(title, body string, nav bool) string {
	var b strings.Builder
	navigation := ""
	if nav {
		navigation = `<nav><a href="/_">Dashboard</a><a href="/_/files">Files</a><a href="/_/config">Config</a><a href="/_/logout">Logout</a></nav>`
	}
	b.WriteString(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">`)
	b.WriteString(`<title>`)
	b.WriteString(r.e(title))
	b.WriteString(`</title>`)
	b.WriteString(`<style>`)
	b.WriteString(rendererCSS)
	b.WriteString(`</style>`)
	b.WriteString(`<script defer src="https://unpkg.com/@alpinejs/ui@3.x.x/dist/cdn.min.js"></script>`)
	b.WriteString(`<script defer src="https://unpkg.com/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>`)
	b.WriteString(`<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>`)
	b.WriteString(`</head><body><header><strong>Mini S3</strong>`)
	b.WriteString(navigation)
	b.WriteString(`</header><main><h1>`)
	b.WriteString(r.e(title))
	b.WriteString(`</h1>`)
	b.WriteString(body)
	b.WriteString(`</main></body></html>`)
	return b.String()
}

func (r *AdminRenderer) filesBreadcrumbs(currentBucket, currentPrefix string) string {
	var b strings.Builder
	if currentBucket == "" {
		return `<div class="breadcrumbs"><strong>All buckets</strong></div>`
	}
	b.WriteString(`<div class="breadcrumbs"><a href="/_/files">Buckets</a>`)
	b.WriteString(`<span>/</span><a href="/_/files?bucket=`)
	b.WriteString(url.QueryEscape(currentBucket))
	b.WriteString(`">`)
	b.WriteString(r.e(currentBucket))
	b.WriteString(`</a>`)
	if currentPrefix != "" {
		segments := strings.Split(currentPrefix, "/")
		path := ""
		for _, segment := range segments {
			if path == "" {
				path = segment
			} else {
				path = path + "/" + segment
			}
			b.WriteString(`<span>/</span><a href="/_/files?bucket=`)
			b.WriteString(url.QueryEscape(currentBucket))
			b.WriteString(`&prefix=`)
			b.WriteString(url.QueryEscape(path))
			b.WriteString(`">`)
			b.WriteString(r.e(segment))
			b.WriteString(`</a>`)
		}
	}
	b.WriteString(`</div>`)
	return b.String()
}

func (r *AdminRenderer) bucketGrid(buckets []BucketInfo) string {
	if len(buckets) == 0 {
		return `<p class="muted">No buckets yet.</p>`
	}
	var b strings.Builder
	b.WriteString(`<div class="bucket-grid">`)
	for _, bucket := range buckets {
		name := bucket.Name
		b.WriteString(`<section class="bucket-card" data-searchable="`)
		b.WriteString(r.e(strings.ToLower(name)))
		b.WriteString(`"><h3><a href="/_/files?bucket=`)
		b.WriteString(url.QueryEscape(name))
		b.WriteString(`">`)
		b.WriteString(r.e(name))
		b.WriteString(`</a></h3>`)
		b.WriteString(`<p class="bucket-meta">`)
		b.WriteString(r.e(fmt.Sprintf("%d", bucket.ObjectCount)))
		b.WriteString(` objects</p>`)
		b.WriteString(`<p class="bucket-meta">`)
		b.WriteString(r.e(formatBytes(bucket.TotalBytes)))
		b.WriteString(`</p>`)
		b.WriteString(`<div class="row-actions">`)
		b.WriteString(r.iconButton(iconRename, "Rename", `x-on:click="renameBucket(`+jsString(name)+`)"`, ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", `x-on:click="deleteBucket(`+jsString(name)+`)"`, "danger"))
		b.WriteString(`</div></section>`)
	}
	b.WriteString(`</div>`)
	return b.String()
}

func (r *AdminRenderer) objectTable(bucket, prefix string, listing *ListObjectsResult) string {
	var folders []FolderInfo
	var files []FileInfo
	if listing != nil {
		folders = listing.Folders
		files = listing.Files
	}

	var b strings.Builder
	b.WriteString(`<div class="status-line muted" x-bind:class="statusError ? 'error-text' : ''" x-text="statusMessage"></div>`)
	b.WriteString(`<div class="row-actions" style="margin-bottom:12px;"><button type="button" class="danger" x-bind:disabled="selectedItems.length===0" x-on:click="bulkDelete()">Delete selected<template x-if="selectedItems.length"><span x-text="' (' + selectedItems.length + ')'"></span></template></button></div>`)
	b.WriteString(`<div class="files-table-wrap"><table class="files-table"><thead><tr><th><input type="checkbox" x-bind:checked="allSelected" x-on:change="toggleSelectAll($event.target.checked)" aria-label="Select all"></th><th>Name</th><th>Preview</th><th>Type</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead><tbody>`)

	if prefix != "" {
		parent := parentDir(prefix)
		b.WriteString(`<tr><td></td><td colspan="6"><a href="/_/files?bucket=`)
		b.WriteString(url.QueryEscape(bucket))
		b.WriteString(`&prefix=`)
		b.WriteString(url.QueryEscape(parent))
		b.WriteString(`">..</a></td></tr>`)
	}

	for _, folder := range folders {
		path := folder.Path
		name := folder.Name
		folderURL := `/_/files?bucket=` + url.QueryEscape(bucket) + `&prefix=` + url.QueryEscape(path)
		b.WriteString(`<tr class="row-clickable" data-searchable="`)
		b.WriteString(r.e(strings.ToLower(name)))
		b.WriteString(`" x-on:click="window.location.href=`)
		b.WriteString(jsString(folderURL))
		b.WriteString(`"><td x-on:click.stop><input type="checkbox" value="`)
		b.WriteString(r.e(path))
		b.WriteString(`" x-model="selectedItems"></td><td><a href="`)
		b.WriteString(folderURL)
		b.WriteString(`">`)
		b.WriteString(r.e(name))
		b.WriteString(`</a></td><td>-</td><td>Folder</td><td>`)
		b.WriteString(r.e(fmt.Sprintf("%d", folder.ObjectCount)))
		b.WriteString(` items</td><td>`)
		b.WriteString(r.e(formatDate(folder.Modified)))
		b.WriteString(`</td><td x-on:click.stop><div class="row-actions">`)
		b.WriteString(r.iconButton(iconRename, "Rename", `x-on:click="renameObject(`+jsString(path)+`, `+jsString(name)+`)"`, ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", `x-on:click="deleteObject(`+jsString(path)+`)"`, "danger"))
		b.WriteString(`</div></td></tr>`)
	}

	for _, file := range files {
		path := file.Path
		name := file.Name
		preview := "-"
		if file.IsImage {
			previewURL := `/_/files?bucket=` + url.QueryEscape(bucket) + `&path=` + url.QueryEscape(path) + `&download=0`
			preview = `<a href="` + previewURL + `" target="_blank" x-on:click.stop><img class="preview-thumb" src="` + previewURL + `" alt="` + r.e(name) + `"></a>`
		}
		downloadURL := `/_/files?bucket=` + url.QueryEscape(bucket) + `&path=` + url.QueryEscape(path) + `&download=1`
		b.WriteString(`<tr class="row-clickable" data-searchable="`)
		b.WriteString(r.e(strings.ToLower(name)))
		b.WriteString(`" x-on:click="window.location.href=`)
		b.WriteString(jsString(downloadURL))
		b.WriteString(`"><td x-on:click.stop><input type="checkbox" value="`)
		b.WriteString(r.e(path))
		b.WriteString(`" x-model="selectedItems"></td><td>`)
		b.WriteString(r.e(name))
		b.WriteString(`</td><td>`)
		b.WriteString(preview)
		b.WriteString(`</td><td>`)
		b.WriteString(r.e(file.Mime))
		b.WriteString(`</td><td>`)
		b.WriteString(r.e(formatBytes(file.Size)))
		b.WriteString(`</td><td>`)
		b.WriteString(r.e(formatDate(file.Modified)))
		b.WriteString(`</td><td x-on:click.stop><div class="row-actions">`)
		b.WriteString(r.iconButton(iconDownload, "Download", `href="`+downloadURL+`"`, "", true))
		b.WriteString(r.iconButton(iconRename, "Rename", `x-on:click="renameObject(`+jsString(path)+`, `+jsString(name)+`)"`, ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", `x-on:click="deleteObject(`+jsString(path)+`)"`, "danger"))
		b.WriteString(`</div></td></tr>`)
	}

	if len(folders) == 0 && len(files) == 0 {
		b.WriteString(`<tr><td colspan="7" class="muted">This location is empty.</td></tr>`)
	}

	b.WriteString(`</tbody></table></div>`)
	return b.String()
}

func (r *AdminRenderer) filesModal() string {
	return `<div x-dialog x-model="dialogOpen" x-cloak class="dialog-shell">` +
		`<div x-dialog:overlay x-transition.opacity class="dialog-overlay"></div>` +
		`<div class="dialog-wrap">` +
		`<div x-dialog:panel x-transition class="dialog-panel">` +
		`<button type="button" class="dialog-close" x-on:click="$dialog.close()" aria-label="Close">&times;</button>` +
		`<div><h2 x-dialog:title x-text="dialogTitle"></h2>` +
		`<div class="mt-2 muted"><p x-text="dialogMessage"></p></div></div>` +
		`<div x-show="dialogError" x-cloak class="dialog-error" x-text="dialogError"></div>` +
		`<label x-show="!dialogShowFile && !dialogIsConfirm" x-cloak>Name<input x-ref="dialogNameInput" x-model="dialogName" x-on:keydown.enter="submitDialog()"></label>` +
		`<label x-show="dialogShowFile" x-cloak>File<input type="file" x-ref="uploadFile"></label>` +
		`<div class="dialog-actions">` +
		`<button type="button" class="dialog-secondary" x-on:click="$dialog.close()">Cancel</button>` +
		`<button type="button" x-on:click="submitDialog()" x-bind:disabled="busy" x-bind:class="dialogIsConfirm ? 'danger' : ''" x-text="busy ? 'Working...' : (dialogIsConfirm ? 'Delete' : 'Confirm')"></button>` +
		`</div></div></div></div>`
}

func (r *AdminRenderer) filesScript() string {
	return `<script>` + filesScriptJS + `</script>`
}

func (r *AdminRenderer) statCard(label, value string) string {
	return `<section class="card"><h2>` + r.e(value) + `</h2><p>` + r.e(label) + `</p></section>`
}

func (r *AdminRenderer) flashMessage(message string) string {
	if message == "" {
		return ""
	}
	return `<div class="notice">` + r.e(message) + `</div>`
}

func (r *AdminRenderer) updatesPanel(status map[string]interface{}) string {
	if len(status) == 0 {
		return ""
	}

	state, _ := status["state"].(string)
	if state == "" {
		state = "unknown"
	}
	message, _ := status["message"].(string)
	if message == "" {
		message = "Update status unavailable."
	}
	current, _ := status["currentVersion"].(string)
	latest, _ := status["latestVersion"].(string)
	csrfToken, _ := status["csrfToken"].(string)
	assetURL, _ := status["assetUrl"].(string)

	var b strings.Builder
	b.WriteString(`<section class="panel"><h2>Updates</h2><p>`)
	b.WriteString(r.e(message))
	b.WriteString(`</p>`)
	if current != "" {
		b.WriteString(`<p><strong>Current version:</strong> `)
		b.WriteString(r.e(current))
		b.WriteString(`</p>`)
	}
	if latest != "" {
		b.WriteString(`<p><strong>Latest version:</strong> `)
		b.WriteString(r.e(latest))
		b.WriteString(`</p>`)
	}
	if state != "unavailable" {
		b.WriteString(`<form method="post" action="/_/check-update"><input type="hidden" name="csrf_token" value="`)
		b.WriteString(r.e(csrfToken))
		b.WriteString(`"><button type="submit">Check update</button></form>`)
	}
	if state == "update_available" && latest != "" {
		b.WriteString(`<form method="post" action="/_/upgrade"><input type="hidden" name="csrf_token" value="`)
		b.WriteString(r.e(csrfToken))
		b.WriteString(`"><input type="hidden" name="latest_version" value="`)
		b.WriteString(r.e(latest))
		b.WriteString(`"><input type="hidden" name="asset_url" value="`)
		b.WriteString(r.e(assetURL))
		b.WriteString(`"><button type="submit">Upgrade to `)
		b.WriteString(r.e(latest))
		b.WriteString(`</button></form>`)
	}
	b.WriteString(`</section>`)
	return b.String()
}

func (r *AdminRenderer) connectionConfig(cfg *config.Config, endpoint string) string {
	if cfg == nil || endpoint == "" {
		return ""
	}
	if len(cfg.Credentials) == 0 {
		return ""
	}

	var accessKey, secretKey string
	for k, v := range cfg.Credentials {
		accessKey = k
		secretKey = v
		break
	}
	region := "us-east-1"
	bucket := "your-bucket"

	generic := genericSnippet(endpoint, region, bucket, accessKey, secretKey)
	genericMasked := genericSnippet(endpoint, region, bucket, mask(accessKey), mask(secretKey))
	laravel := laravelSnippet(endpoint, region, bucket, accessKey, secretKey)
	laravelMasked := laravelSnippet(endpoint, region, bucket, mask(accessKey), mask(secretKey))

	return `<section class="panel"><h2>Connection config</h2>` +
		`<p>Copy these values into applications that need to connect to this Mini S3 server.</p>` +
		`<div class="snippet-actions"><button type="button" onclick="toggleSensitive(this)">Show sensitive</button></div>` +
		`<h3>Generic S3 env vars</h3>` +
		`<div class="snippet-actions"><button type="button" onclick="copySnippet('generic-snippet')">Copy generic</button></div>` +
		`<pre id="generic-snippet" data-masked="` + r.e(genericMasked) + `" data-full="` + r.e(generic) + `">` + r.e(genericMasked) + `</pre>` +
		`<h3>Laravel .env</h3>` +
		`<div class="snippet-actions"><button type="button" onclick="copySnippet('laravel-snippet')">Copy Laravel</button></div>` +
		`<pre id="laravel-snippet" data-masked="` + r.e(laravelMasked) + `" data-full="` + r.e(laravel) + `">` + r.e(laravelMasked) + `</pre>` +
		`<script>let miniS3ShowSensitive=false;function toggleSensitive(button){miniS3ShowSensitive=!miniS3ShowSensitive;document.querySelectorAll("pre[data-masked]").forEach(function(el){el.textContent=miniS3ShowSensitive?el.dataset.full:el.dataset.masked;});button.textContent=miniS3ShowSensitive?"Hide sensitive":"Show sensitive";}function copySnippet(id){var el=document.getElementById(id);if(!el){return;}navigator.clipboard.writeText(el.textContent);}</script>` +
		`</section>`
}

func (r *AdminRenderer) iconButton(icon, label, attrs string, classExt string, asLink ...bool) string {
	class := strings.TrimSpace("icon-btn " + classExt)
	tag := "button"
	typeAttr := ` type="button"`
	if len(asLink) > 0 && asLink[0] {
		tag = "a"
		typeAttr = ""
	}
	return `<` + tag + typeAttr + ` class="` + r.e(class) + `" data-tooltip="` + r.e(label) + `" aria-label="` + r.e(label) + `" ` + attrs + `>` + icon + `</` + tag + `>`
}

func (r *AdminRenderer) e(value string) string {
	return html.EscapeString(value)
}

// --- standalone helpers ---

func formatBytes(bytes int64) string {
	if bytes >= 1073741824 {
		return fmt.Sprintf("%.2f GB", float64(bytes)/1073741824)
	}
	if bytes >= 1048576 {
		return fmt.Sprintf("%.2f MB", float64(bytes)/1048576)
	}
	if bytes >= 1024 {
		return fmt.Sprintf("%.2f KB", float64(bytes)/1024)
	}
	return fmt.Sprintf("%d B", bytes)
}

func formatDate(timestamp int64) string {
	if timestamp <= 0 {
		return "-"
	}
	return time.Unix(timestamp, 0).UTC().Format("2006-01-02 15:04")
}

func jsString(value string) string {
	encoded, err := json.Marshal(value)
	if err != nil {
		return "''"
	}
	// json.Marshal produces a double-quoted string; convert to single-quoted
	// with hex-escaped quotes, matching PHP's JSON_HEX_APOS | JSON_HEX_QUOT
	s := string(encoded)
	if len(s) < 2 {
		return "''"
	}
	inner := s[1 : len(s)-1]
	// Escape single quotes and double quotes for JS single-quoted string
	inner = strings.ReplaceAll(inner, `\u0026`, `&`)
	inner = strings.ReplaceAll(inner, `\u003c`, `<`)
	inner = strings.ReplaceAll(inner, `\u003e`, `>`)
	return "'" + inner + "'"
}

func mask(value string) string {
	if len(value) <= 8 {
		stars := int(math.Max(4, float64(len(value))))
		return strings.Repeat("*", stars)
	}
	return value[:4] + "..." + value[len(value)-4:]
}

func checked(values map[string]interface{}, key string) string {
	v, ok := values[key]
	if !ok {
		return ""
	}
	switch val := v.(type) {
	case bool:
		if val {
			return " checked"
		}
	case string:
		switch strings.ToLower(val) {
		case "1", "true", "on", "yes":
			return " checked"
		}
	}
	return ""
}

func getStr(values map[string]interface{}, key, defaultVal string) string {
	v, ok := values[key]
	if !ok {
		return defaultVal
	}
	switch val := v.(type) {
	case string:
		if val == "" {
			return defaultVal
		}
		return val
	case bool:
		if val {
			return "true"
		}
		return "false"
	default:
		return fmt.Sprintf("%v", val)
	}
}

func stringMapToInterface(m map[string]string) map[string]interface{} {
	result := make(map[string]interface{}, len(m))
	for k, v := range m {
		result[k] = v
	}
	return result
}

func genericSnippet(endpoint, region, bucket, accessKey, secretKey string) string {
	return "MINI_S3_ENDPOINT=" + endpoint + "\n" +
		"MINI_S3_REGION=" + region + "\n" +
		"MINI_S3_BUCKET=" + bucket + "\n" +
		"MINI_S3_ACCESS_KEY_ID=" + accessKey + "\n" +
		"MINI_S3_SECRET_ACCESS_KEY=" + secretKey
}

func laravelSnippet(endpoint, region, bucket, accessKey, secretKey string) string {
	return "AWS_ACCESS_KEY_ID=" + accessKey + "\n" +
		"AWS_SECRET_ACCESS_KEY=" + secretKey + "\n" +
		"AWS_DEFAULT_REGION=" + region + "\n" +
		"AWS_BUCKET=" + bucket + "\n" +
		"AWS_ENDPOINT=" + endpoint + "\n" +
		"AWS_USE_PATH_STYLE_ENDPOINT=true"
}
