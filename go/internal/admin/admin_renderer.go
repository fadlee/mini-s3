package admin

import (
	"encoding/json"
	"fmt"
	"html"
	"math"
	"net/url"
	"path/filepath"
	"strings"
	"time"

	"github.com/fadlee/mini-s3/internal/config"
)

const iconRename = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`
const iconDelete = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>`
const iconAlert = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>`
const iconDownload = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>`

// AdminRenderer generates the HTML for the admin panel.
type AdminRenderer struct{}

// NewAdminRenderer creates a new renderer.
func NewAdminRenderer() *AdminRenderer {
	return &AdminRenderer{}
}

// Installer renders the first-time setup page.
func (r *AdminRenderer) Installer(values map[string]string, errors []string, csrfToken string) string {
	body := `<h1>Set up Mini S3</h1>` +
		`<p class="lead">Create the admin account and the first access key for this server.</p>` +
		r.form("/_", values, errors, csrfToken, true)
	return r.authLayout("Set up Mini S3", body, true, "")
}

// Login renders the login page.
func (r *AdminRenderer) Login(errMsg, csrfToken string) string {
	var b strings.Builder
	b.WriteString(`<h1>Sign in to admin</h1><p class="lead">Manage buckets, files and access keys.</p>`)
	if errMsg != "" {
		b.WriteString(`<div class="error" role="alert">`)
		b.WriteString(iconAlert)
		b.WriteString(`<span>`)
		b.WriteString(r.e(errMsg))
		b.WriteString(`</span></div>`)
	}
	b.WriteString(`<form method="post" action="/_">`)
	b.WriteString(`<input type="hidden" name="csrf_token" value="`)
	b.WriteString(r.e(csrfToken))
	b.WriteString(`">`)
	b.WriteString(`<label>Username<input name="username" autocomplete="username" required autofocus></label>`)
	b.WriteString(`<div class="stack"><label for="login-password">Password</label>`)
	b.WriteString(`<div class="password-row"><input id="login-password" type="password" name="password" autocomplete="current-password" required>`)
	b.WriteString(`<button type="button" aria-label="Show password" aria-pressed="false" data-toggle-password="login-password">Show</button></div></div>`)
	b.WriteString(`<button type="submit">Sign in</button>`)
	b.WriteString(`</form>`)
	b.WriteString(`<details><summary>Forgot the password?</summary>`)
	b.WriteString(`<p class="help">Put a new bcrypt hash in <code>admin.password_hash</code> in <code>config.yaml</code> (or <code>MINI_S3_ADMIN_PASSWORD_HASH</code>), then restart the server.</p>`)
	b.WriteString(`</details>`)
	return r.authLayout("Sign in · Mini S3 Admin", b.String(), false, "This account is only for this panel. Apps connect to S3 with access keys, not with this password.")
}

// Dashboard renders the main dashboard page.
func (r *AdminRenderer) Dashboard(stats Stats, cfg *config.Config, endpoint string, updateStatus map[string]interface{}, flashMessage string) string {
	var b strings.Builder
	b.WriteString(`<p class="lead">`)
	b.WriteString(r.e(fmt.Sprintf("%s · %s · %s stored", plural(stats.BucketCount, "bucket"), plural(stats.ObjectCount, "object"), formatBytes(stats.TotalBytes))))
	b.WriteString(`</p>`)
	b.WriteString(r.flashMessage(flashMessage))
	b.WriteString(r.connectPanel(endpoint))
	b.WriteString(`<div class="split"><div class="main-col">`)
	b.WriteString(r.bucketsPanel(stats.Buckets, stats.TotalBytes))
	b.WriteString(r.connectionConfig(cfg, endpoint))
	b.WriteString(`</div><div class="side-col">`)
	b.WriteString(r.healthPanel(stats, cfg, updateStatus))
	b.WriteString(`</div></div>`)
	return r.layout("Overview", b.String(), true)
}

// ConfigPage renders the config edit page.
func (r *AdminRenderer) ConfigPage(values map[string]interface{}, errors []string, csrfToken string) string {
	return r.layout("Config", r.formInterface("/_/config", values, errors, csrfToken, false), true)
}

// Security renders the admin account page.
func (r *AdminRenderer) Security(values map[string]interface{}, errors []string, csrfToken, flashMessage string) string {
	var b strings.Builder
	b.WriteString(r.flashMessage(flashMessage))
	for _, errMsg := range errors {
		b.WriteString(`<div class="error">`)
		b.WriteString(r.e(errMsg))
		b.WriteString(`</div>`)
	}
	b.WriteString(`<form method="post" action="/_/security">`)
	b.WriteString(`<input type="hidden" name="csrf_token" value="`)
	b.WriteString(r.e(csrfToken))
	b.WriteString(`">`)
	b.WriteString(`<label><span class="field-label">Admin username</span><input name="admin_username" value="`)
	b.WriteString(r.e(getStr(values, "admin_username", "admin")))
	b.WriteString(`" required></label>`)
	b.WriteString(`<label><span class="field-label">Current password</span><input type="password" name="current_password" autocomplete="current-password" required></label>`)
	b.WriteString(`<label><span class="field-label">New password</span><input type="password" name="new_password" autocomplete="new-password"></label>`)
	b.WriteString(`<label><span class="field-label">Confirm new password</span><input type="password" name="new_password_confirm" autocomplete="new-password"></label>`)
	b.WriteString(`<p class="muted">Leave new password blank to keep the current password.</p>`)
	b.WriteString(`<button type="submit">Save admin account</button></form>`)
	return r.layout("Admin account", b.String(), true)
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
	b.WriteString(`<div data-explorer data-csrf="`)
	b.WriteString(r.e(csrfToken))
	b.WriteString(`" data-bucket="`)
	b.WriteString(r.e(currentBucket))
	b.WriteString(`" data-prefix="`)
	b.WriteString(r.e(currentPrefix))
	b.WriteString(`">`)
	b.WriteString(r.flashMessage(flashMessage))
	b.WriteString(`<div class="files-layout">`)
	b.WriteString(r.bucketRail(buckets, currentBucket))
	b.WriteString(`<div class="files-main">`)
	b.WriteString(r.filesBreadcrumbs(currentBucket, currentPrefix))
	b.WriteString(`<div class="toolbar">`)
	b.WriteString(`<div class="toolbar-group toolbar-grow"><input type="search" aria-label="Filter this list" placeholder="Filter this list" data-filter></div>`)
	b.WriteString(`<div class="toolbar-group">`)
	b.WriteString(`<button type="button" class="secondary" data-action="create-bucket">New bucket</button>`)
	if currentBucket != "" {
		b.WriteString(`<button type="button" class="secondary" data-action="create-folder">New folder</button><button type="button" data-action="upload">Upload file</button>`)
	}
	b.WriteString(`</div></div>`)
	if currentBucket == "" {
		b.WriteString(r.bucketGrid(buckets))
	} else {
		b.WriteString(r.objectTable(currentBucket, currentPrefix, listing, summary))
	}
	b.WriteString(`</div>`)
	if currentBucket != "" {
		b.WriteString(fileDrawerHTML)
	}
	b.WriteString(`</div>`)
	b.WriteString(filesModalHTML)
	b.WriteString(`</div>`)
	return r.layout("Buckets & files", b.String(), true)
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

	b.WriteString(`<label class="checkbox-label"><input type="hidden" name="public_read_all_buckets" value="0"><input type="checkbox" name="public_read_all_buckets" value="1"`)
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

type navLink struct{ href, label, icon string }

var navGroups = []struct {
	heading string
	links   []navLink
}{
	{"", []navLink{{"/_", "Overview", `<path d="M3 4.5A1.5 1.5 0 0 1 4.5 3h4A1.5 1.5 0 0 1 10 4.5v6A1.5 1.5 0 0 1 8.5 12h-4A1.5 1.5 0 0 1 3 10.5Z"/><path d="M14 4.5A1.5 1.5 0 0 1 15.5 3h4A1.5 1.5 0 0 1 21 4.5v2A1.5 1.5 0 0 1 19.5 8h-4A1.5 1.5 0 0 1 14 6.5Z"/><path d="M14 13.5a1.5 1.5 0 0 1 1.5-1.5h4a1.5 1.5 0 0 1 1.5 1.5v6a1.5 1.5 0 0 1-1.5 1.5h-4a1.5 1.5 0 0 1-1.5-1.5Z"/><path d="M3 17.5A1.5 1.5 0 0 1 4.5 16h4a1.5 1.5 0 0 1 1.5 1.5v2A1.5 1.5 0 0 1 8.5 21h-4A1.5 1.5 0 0 1 3 19.5Z"/>`}}},
	{"Storage", []navLink{{"/_/files", "Buckets & files", `<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>`}}},
	{"Access", []navLink{{"/_/security", "Admin account", `<path d="M8 7a4 4 0 1 0 8 0 4 4 0 0 0-8 0"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>`}}},
	{"System", []navLink{{"/_/config", "Config", `<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/><circle cx="9" cy="6" r="2" fill="#fff"/><circle cx="15" cy="12" r="2" fill="#fff"/><circle cx="8" cy="18" r="2" fill="#fff"/>`}}},
}

func (r *AdminRenderer) layout(title, body string, nav bool) string {
	if !nav {
		return r.authLayout(title, `<h1>`+r.e(title)+`</h1>`+body, false, "")
	}
	var b strings.Builder
	b.WriteString(`<div class="shell"><aside class="sidebar"><a class="brand" href="/_"><span class="brand-mark" aria-hidden="true">S3</span>Mini S3</a><nav aria-label="Main">`)
	for _, group := range navGroups {
		b.WriteString(`<div class="nav-group">`)
		if group.heading != "" {
			b.WriteString(`<span class="nav-heading">`)
			b.WriteString(r.e(group.heading))
			b.WriteString(`</span>`)
		}
		for _, link := range group.links {
			b.WriteString(`<a class="nav-link" href="`)
			b.WriteString(link.href)
			b.WriteString(`"`)
			if link.label == title {
				b.WriteString(` aria-current="page"`)
			}
			b.WriteString(`><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">`)
			b.WriteString(link.icon)
			b.WriteString(`</svg>`)
			b.WriteString(r.e(link.label))
			b.WriteString(`</a>`)
		}
		b.WriteString(`</div>`)
	}
	b.WriteString(`</nav><div class="sidebar-foot"><span class="muted">Signed in</span><a class="link-button" href="/_/logout">Log out</a></div></aside>`)
	b.WriteString(`<main class="content"><div class="page"><div class="page-head"><h1>`)
	b.WriteString(r.e(title))
	b.WriteString(`</h1></div>`)
	b.WriteString(body)
	b.WriteString(`</div></main></div>`)
	return r.document(title+" · Mini S3", b.String())
}

// authLayout is the centered single-card layout for pages shown before login.
func (r *AdminRenderer) authLayout(title, body string, wide bool, footnote string) string {
	var b strings.Builder
	b.WriteString(`<div class="auth"><div class="brand"><span class="brand-mark" aria-hidden="true">S3</span>Mini S3</div><main class="auth-card`)
	if wide {
		b.WriteString(` wide`)
	}
	b.WriteString(`">`)
	b.WriteString(body)
	b.WriteString(`</main>`)
	if footnote != "" {
		b.WriteString(`<p class="auth-foot">`)
		b.WriteString(r.e(footnote))
		b.WriteString(`</p>`)
	}
	b.WriteString(`</div>`)
	return r.document(title, b.String())
}

func (r *AdminRenderer) document(title, body string) string {
	var b strings.Builder
	b.WriteString(`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">`)
	b.WriteString(`<title>`)
	b.WriteString(r.e(title))
	b.WriteString(`</title>`)
	b.WriteString(`<style>`)
	b.WriteString(rendererCSS)
	b.WriteString(`</style>`)
	b.WriteString(`<script defer src="`)
	b.WriteString(r.e(adminScriptPath))
	b.WriteString(`"></script>`)
	b.WriteString(`</head><body>`)
	b.WriteString(body)
	b.WriteString(`</body></html>`)
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
		b.WriteString(r.iconButton(iconRename, "Rename", `data-action="rename-bucket" data-name="`+r.e(name)+`"`, ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", `data-action="delete-bucket" data-name="`+r.e(name)+`"`, "danger"))
		b.WriteString(`</div></section>`)
	}
	b.WriteString(`</div>`)
	return b.String()
}

func (r *AdminRenderer) bucketRail(buckets []BucketInfo, currentBucket string) string {
	if currentBucket == "" || len(buckets) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString(`<nav class="bucket-rail" aria-label="Buckets"><span class="nav-heading">Buckets</span><ul>`)
	for _, bucket := range buckets {
		b.WriteString(`<li><a href="/_/files?bucket=`)
		b.WriteString(url.QueryEscape(bucket.Name))
		b.WriteString(`"`)
		if bucket.Name == currentBucket {
			b.WriteString(` aria-current="page"`)
		}
		b.WriteString(`><span>`)
		b.WriteString(r.e(bucket.Name))
		b.WriteString(`</span><span>`)
		b.WriteString(r.e(formatBytes(bucket.TotalBytes)))
		b.WriteString(`</span></a></li>`)
	}
	b.WriteString(`</ul></nav>`)
	return b.String()
}

func (r *AdminRenderer) objectTable(bucket, prefix string, listing *ListObjectsResult, summary string) string {
	var folders []FolderInfo
	var files []FileInfo
	if listing != nil {
		folders = listing.Folders
		files = listing.Files
	}

	var b strings.Builder
	b.WriteString(`<div class="status-line muted" role="status" data-status>`)
	b.WriteString(r.e(summary))
	b.WriteString(`</div>`)
	b.WriteString(`<div class="selection-bar" data-selection-bar hidden><span data-selection-count></span>`)
	b.WriteString(`<button type="button" class="danger" data-action="bulk-delete">Delete selected</button>`)
	b.WriteString(`<button type="button" class="link-button" data-action="clear-selection">Clear</button></div>`)
	b.WriteString(`<section class="panel panel-flush"><div class="files-table-wrap"><table class="files-table"><thead><tr><th><input type="checkbox" data-select-all aria-label="Select all"></th><th>Name</th><th>Size</th><th>Modified</th><th><span class="visually-hidden">Actions</span></th></tr></thead><tbody>`)

	if prefix != "" {
		parent := parentDir(prefix)
		b.WriteString(`<tr><td></td><td colspan="4"><a href="/_/files?bucket=`)
		b.WriteString(url.QueryEscape(bucket))
		b.WriteString(`&prefix=`)
		b.WriteString(url.QueryEscape(parent))
		b.WriteString(`" class="file-name"><span class="file-icon" aria-hidden="true">..</span>Up one level</a></td></tr>`)
	}

	for _, folder := range folders {
		path := folder.Path
		name := folder.Name
		folderURL := `/_/files?bucket=` + url.QueryEscape(bucket) + `&prefix=` + url.QueryEscape(path)
		b.WriteString(`<tr class="row-clickable" data-searchable="`)
		b.WriteString(r.e(strings.ToLower(name)))
		b.WriteString(`" data-href="`)
		b.WriteString(r.e(folderURL))
		b.WriteString(`"><td data-stop><input type="checkbox" data-select value="`)
		b.WriteString(r.e(path))
		b.WriteString(`" aria-label="Select `)
		b.WriteString(r.e(name))
		b.WriteString(`"></td><td><a href="`)
		b.WriteString(folderURL)
		b.WriteString(`" class="file-name"><span class="file-icon" aria-hidden="true">DIR</span>`)
		b.WriteString(r.e(name))
		b.WriteString(`/</a></td><td class="num">`)
		b.WriteString(r.e(fmt.Sprintf("%d", folder.ObjectCount)))
		b.WriteString(` items</td><td class="num">`)
		b.WriteString(r.e(formatDate(folder.Modified)))
		b.WriteString(`</td><td data-stop><div class="row-actions">`)
		b.WriteString(r.iconButton(iconRename, "Rename", r.objectAttrs("rename-object", path, name), ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", r.objectAttrs("delete-object", path, name), "danger"))
		b.WriteString(`</div></td></tr>`)
	}

	for _, file := range files {
		path := file.Path
		name := file.Name
		previewURL := ""
		icon := `<span class="file-icon" aria-hidden="true">` + r.e(fileBadge(name)) + `</span>`
		if file.IsImage && file.Mime != "image/svg+xml" {
			previewURL = `/_/files?bucket=` + url.QueryEscape(bucket) + `&path=` + url.QueryEscape(path) + `&download=0`
			icon = `<span class="file-icon is-image" aria-hidden="true"><img class="preview-thumb" src="` + previewURL + `" alt="" loading="lazy"></span>`
		}
		downloadURL := `/_/files?bucket=` + url.QueryEscape(bucket) + `&path=` + url.QueryEscape(path) + `&download=1`
		details := r.e(jsonForScript(map[string]string{
			"name":     name,
			"path":     path,
			"type":     file.Mime,
			"size":     formatBytes(file.Size) + " (" + groupThousands(file.Size) + " bytes)",
			"modified": formatDate(file.Modified),
			"preview":  previewURL,
			"download": downloadURL,
		}))
		b.WriteString(`<tr class="row-clickable" data-searchable="`)
		b.WriteString(r.e(strings.ToLower(name)))
		b.WriteString(`" data-details="`)
		b.WriteString(details)
		b.WriteString(`"><td data-stop><input type="checkbox" data-select value="`)
		b.WriteString(r.e(path))
		b.WriteString(`" aria-label="Select `)
		b.WriteString(r.e(name))
		b.WriteString(`"></td><td><button type="button" class="link-button file-name" data-action="details">`)
		b.WriteString(icon)
		b.WriteString(r.e(name))
		b.WriteString(`</button></td><td class="num">`)
		b.WriteString(r.e(formatBytes(file.Size)))
		b.WriteString(`</td><td class="num">`)
		b.WriteString(r.e(formatDate(file.Modified)))
		b.WriteString(`</td><td data-stop><div class="row-actions">`)
		b.WriteString(r.iconButton(iconDownload, "Download", `href="`+downloadURL+`"`, "", true))
		b.WriteString(r.iconButton(iconRename, "Rename", r.objectAttrs("rename-object", path, name), ""))
		b.WriteString(r.iconButton(iconDelete, "Delete", r.objectAttrs("delete-object", path, name), "danger"))
		b.WriteString(`</div></td></tr>`)
	}

	if len(folders) == 0 && len(files) == 0 {
		b.WriteString(`<tr><td colspan="5" class="muted">This location is empty. Use <em>Upload file</em> or <em>New folder</em> above.</td></tr>`)
	}

	b.WriteString(`</tbody></table></div></section>`)
	return b.String()
}

func (r *AdminRenderer) objectAttrs(action, path, name string) string {
	return `data-action="` + action + `" data-path="` + r.e(path) + `" data-name="` + r.e(name) + `"`
}

const fileDrawerHTML = `<aside class="drawer" aria-label="File details" data-drawer hidden><div class="drawer-inner">` +
	`<div class="drawer-head"><h2 data-detail="name"></h2><button type="button" class="drawer-close" aria-label="Close details" data-action="close-details">&times;</button></div>` +
	`<img class="drawer-preview" data-detail-preview alt="" hidden>` +
	`<div class="drawer-actions"><a class="button" data-detail-download href="#">Download</a><button type="button" class="secondary" data-action="rename-object" data-detail-target>Rename</button></div>` +
	`<dl><dt>Type</dt><dd data-detail="type"></dd><dt>Size</dt><dd data-detail="size"></dd><dt>Modified</dt><dd data-detail="modified"></dd><dt>Key</dt><dd><code data-detail="path"></code></dd></dl>` +
	`<button type="button" class="danger-outline" data-action="delete-object" data-detail-target>Delete file&hellip;</button>` +
	`</div></aside>`

const filesModalHTML = `<dialog class="dialog-panel" aria-labelledby="dialog-title" data-dialog>` +
	`<button type="button" class="dialog-close" data-action="close-dialog" aria-label="Close">&times;</button>` +
	`<form method="dialog" class="plain">` +
	`<h2 id="dialog-title" data-dialog-title></h2>` +
	`<p class="muted" data-dialog-message></p>` +
	`<div class="dialog-error" role="alert" data-dialog-error hidden></div>` +
	`<label data-dialog-name-row>Name<input autocomplete="off" data-dialog-name></label>` +
	`<label data-dialog-file-row hidden>File<input type="file" data-dialog-file></label>` +
	`<div class="dialog-actions">` +
	`<button type="button" class="dialog-secondary" data-action="close-dialog">Cancel</button>` +
	`<button type="submit" data-dialog-submit>Confirm</button>` +
	`</div>` +
	`</form></dialog>`

func fileBadge(name string) string {
	ext := strings.ToUpper(strings.TrimPrefix(filepath.Ext(name), "."))
	if ext == "" || len(ext) > 4 {
		return "FILE"
	}
	return ext
}

// jsonForScript encodes a value as a JS literal. json.Marshal escapes <, > and
// &; callers HTML-escape the result before putting it in an attribute.
func jsonForScript(value interface{}) string {
	encoded, err := json.Marshal(value)
	if err != nil {
		return "null"
	}
	return string(encoded)
}

func groupThousands(n int64) string {
	s := fmt.Sprintf("%d", n)
	if n < 0 {
		return s
	}
	var out []byte
	for i, c := range []byte(s) {
		if i > 0 && (len(s)-i)%3 == 0 {
			out = append(out, ',')
		}
		out = append(out, c)
	}
	return string(out)
}

func plural(n int, word string) string {
	if n == 1 {
		return groupThousands(int64(n)) + " " + word
	}
	return groupThousands(int64(n)) + " " + word + "s"
}

func (r *AdminRenderer) connectPanel(endpoint string) string {
	if endpoint == "" {
		return ""
	}
	return `<section class="panel"><div class="panel-head"><h2>Connect an app</h2></div>` +
		`<div class="endpoint-row"><span class="visually-hidden">S3 endpoint:</span><code class="code-box">` + r.e(endpoint) + `</code>` +
		`<button type="button" class="secondary" data-copy="` + r.e(endpoint) + `" aria-label="Copy S3 endpoint">Copy</button></div>` +
		`<p class="help">Use path-style addressing (the bucket goes in the URL path) and any region name, for example <code>us-east-1</code>.</p>` +
		`</section>`
}

func (r *AdminRenderer) bucketsPanel(buckets []BucketStats, totalBytes int64) string {
	head := `<div class="panel-head"><h2>Buckets</h2><a href="/_/files">Open Files &rarr;</a></div>`
	if len(buckets) == 0 {
		return `<section class="panel">` + head + `<p class="muted">No buckets yet. A bucket is a top-level folder for your objects.</p></section>`
	}
	var b strings.Builder
	b.WriteString(`<section class="panel panel-flush">`)
	b.WriteString(head)
	b.WriteString(`<div class="files-table-wrap"><table class="files-table"><thead><tr><th>Name</th><th>Objects</th><th>Size</th><th>Share of storage</th></tr></thead><tbody>`)
	for _, bucket := range buckets {
		share := 0
		if totalBytes > 0 {
			share = int(math.Round(float64(bucket.TotalBytes) / float64(totalBytes) * 100))
		}
		b.WriteString(`<tr><td><a href="/_/files?bucket=`)
		b.WriteString(url.QueryEscape(bucket.Name))
		b.WriteString(`"><strong>`)
		b.WriteString(r.e(bucket.Name))
		b.WriteString(`</strong></a></td><td class="num">`)
		b.WriteString(groupThousands(int64(bucket.ObjectCount)))
		b.WriteString(`</td><td class="num">`)
		b.WriteString(r.e(formatBytes(bucket.TotalBytes)))
		b.WriteString(fmt.Sprintf(`</td><td><span class="share"><span class="share-bar" aria-hidden="true"><span style="width: %d%%"></span></span><span class="muted">%d%%</span></span></td></tr>`, share, share))
	}
	b.WriteString(`</tbody></table></div></section>`)
	return b.String()
}

func (r *AdminRenderer) healthPanel(stats Stats, cfg *config.Config, updateStatus map[string]interface{}) string {
	dataTone, dataState := "warn", stats.Status
	switch stats.Status {
	case "ok":
		dataTone, dataState = "ok", "Writable"
	case "not_writable":
		dataTone, dataState = "bad", "Not writable"
	case "missing":
		dataTone, dataState = "bad", "Missing"
	case "unreadable":
		dataTone, dataState = "bad", "Unreadable"
	}
	keyCount := 0
	publicRead, hostFallbacks, debugLog := false, false, false
	if cfg != nil {
		keyCount = len(cfg.Credentials)
		publicRead = cfg.PublicReadAllBuckets
		hostFallbacks = cfg.AllowHostCandidateFallbacks
		debugLog = cfg.AuthDebugLog != ""
	}
	type item struct{ tone, title, state, detail string }
	pick := func(on bool, yes, no string) string {
		if on {
			return yes
		}
		return no
	}
	items := []item{
		{dataTone, "Data directory", dataState, `<code>` + r.e(stats.DataDir) + `</code> Every object is a plain file here; back it up like any folder.`},
		{pick(keyCount > 0, "ok", "warn"), "Access keys", fmt.Sprintf("%d", keyCount), pick(keyCount > 0, "Apps connect with these.", "No app can connect yet.")},
		{pick(publicRead, "warn", "ok"), "Public read", pick(publicRead, "On", "Off"), pick(publicRead, "Anyone with a URL can download objects.", "Every request needs a key.")},
		{pick(hostFallbacks, "warn", "ok"), "Host fallbacks", pick(hostFallbacks, "On", "Off"), pick(hostFallbacks, "Signature checks are relaxed for proxies.", "Strict signature checks.")},
		{pick(debugLog, "warn", "muted"), "Auth debug log", pick(debugLog, "On", "Off"), pick(debugLog, "Turn it off when you are done debugging.", "Turn on only to debug SignatureDoesNotMatch.")},
	}
	var b strings.Builder
	b.WriteString(`<section class="panel"><h2>Server health</h2><ul class="health">`)
	for _, it := range items {
		b.WriteString(`<li><span class="dot dot-`)
		b.WriteString(it.tone)
		b.WriteString(`" aria-hidden="true"></span><span class="health-body"><span class="health-title"><strong>`)
		b.WriteString(r.e(it.title))
		b.WriteString(`</strong><span>`)
		b.WriteString(r.e(it.state))
		b.WriteString(`</span></span><span>`)
		b.WriteString(it.detail)
		b.WriteString(`</span></span></li>`)
	}
	b.WriteString(`</ul>`)
	b.WriteString(r.updatesPanel(updateStatus))
	b.WriteString(`</section>`)
	return b.String()
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
	releaseURL, _ := status["releaseUrl"].(string)
	imageTag, _ := status["imageTag"].(string)
	csrfToken, _ := status["csrfToken"].(string)

	notice := state == "update_available" && latest != "" && imageTag != ""

	var b strings.Builder
	b.WriteString(`<div class="updates"><h3>Updates</h3>`)
	if notice {
		b.WriteString(`<p>Update available: <a href="`)
		b.WriteString(r.e(releaseURL))
		b.WriteString(`" target="_blank" rel="noopener">`)
		b.WriteString(r.e(latest))
		b.WriteString(`</a></p>`)
		b.WriteString(`<p>Upgrade by pulling the new image. If the image tag is pinned in your compose file or run command, change it to the new tag first, then recreate the container:</p>`)
		b.WriteString(`<pre>docker pull `)
		b.WriteString(r.e(dockerImage + ":" + imageTag))
		b.WriteString("\ndocker compose up -d</pre>")
		b.WriteString(`<p>Binary installs: download the new binary from the release page and replace the old one.</p>`)
	} else {
		b.WriteString(`<p>`)
		b.WriteString(r.e(message))
		b.WriteString(`</p>`)
	}
	if current != "" {
		b.WriteString(`<p><strong>Current version:</strong> `)
		b.WriteString(r.e(current))
		b.WriteString(`</p>`)
	}
	if latest != "" && !notice {
		b.WriteString(`<p><strong>Latest version:</strong> `)
		b.WriteString(r.e(latest))
		b.WriteString(`</p>`)
	}
	if state != "unavailable" {
		b.WriteString(`<form method="post" action="/_/check-update" class="plain"><input type="hidden" name="csrf_token" value="`)
		b.WriteString(r.e(csrfToken))
		b.WriteString(`"><button type="submit" class="secondary">Check update</button></form>`)
	}
	b.WriteString(`</div>`)
	return b.String()
}

func (r *AdminRenderer) connectionConfig(cfg *config.Config, endpoint string) string {
	if cfg == nil || endpoint == "" {
		return ""
	}
	if len(cfg.Credentials) == 0 {
		return ""
	}

	accessKey, secretKey, _ := firstCredential(cfg.Credentials)
	region := "us-east-1"
	bucket := "your-bucket"

	generic := genericSnippet(endpoint, region, bucket, accessKey, secretKey)
	genericMasked := genericSnippet(endpoint, region, bucket, mask(accessKey), mask(secretKey))
	laravel := laravelSnippet(endpoint, region, bucket, accessKey, secretKey)
	laravelMasked := laravelSnippet(endpoint, region, bucket, mask(accessKey), mask(secretKey))

	return `<section class="panel"><h2>Connection config</h2>` +
		`<p>Copy these values into applications that need to connect to this Mini S3 server.</p>` +
		`<div class="snippet-actions"><button type="button" aria-pressed="false" data-toggle-masked>Show sensitive</button></div>` +
		`<h3>Generic S3 env vars</h3>` +
		`<div class="snippet-actions"><button type="button" class="secondary" data-copy="` + r.e(generic) + `">Copy generic</button></div>` +
		`<pre id="generic-snippet" data-masked="` + r.e(genericMasked) + `" data-full="` + r.e(generic) + `">` + r.e(genericMasked) + `</pre>` +
		`<h3>Laravel .env</h3>` +
		`<div class="snippet-actions"><button type="button" class="secondary" data-copy="` + r.e(laravel) + `">Copy Laravel</button></div>` +
		`<pre id="laravel-snippet" data-masked="` + r.e(laravelMasked) + `" data-full="` + r.e(laravel) + `">` + r.e(laravelMasked) + `</pre>` +
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
