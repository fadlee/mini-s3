package admin

import (
	"encoding/json"
	"fmt"
	"html"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"

	"github.com/fadlee/mini-s3/internal/config"
)

// AdminRouter handles all admin panel HTTP routes.
type AdminRouter struct {
	configPath    string
	baseDir       string
	version       string
	upgradeService *AdminUpgradeService
}

// NewAdminRouter creates an admin router. configPath is the path to config.yaml.
// baseDir is the base directory for the data dir default. version is the
// current binary version (empty for dev builds).
func NewAdminRouter(configPath, baseDir, version string) *AdminRouter {
	return &AdminRouter{
		configPath: configPath,
		baseDir:    baseDir,
		version:    version,
	}
}

// ServeHTTP implements http.Handler for the admin panel.
func (r *AdminRouter) ServeHTTP(w http.ResponseWriter, req *http.Request) {
	defer func() {
		if rv := recover(); rv != nil {
			http.Error(w, fmt.Sprintf("<!doctype html><title>Admin Error</title><h1>Admin Error</h1><p>%s</p>",
				html.EscapeString(fmt.Sprintf("%v", rv))), http.StatusInternalServerError)
		}
	}()

	renderer := NewAdminRenderer()
	writer := NewAdminConfigWriter(r.configPath)

	// If no config file exists, show installer.
	if _, err := os.Stat(r.configPath); err != nil {
		r.handleInstaller(w, req, renderer, writer)
		return
	}

	cfg, err := config.Load(r.configPath)
	if err != nil {
		http.Error(w, fmt.Sprintf("<!doctype html><title>Admin Error</title><h1>Admin Error</h1><p>%s</p>",
			html.EscapeString(err.Error())), http.StatusInternalServerError)
		return
	}

	auth := NewAdminAuth(cfg.Admin.Username, cfg.Admin.PasswordHash, cfg.Admin.SessionSecret)
	path := req.URL.Path
	if path == "" {
		path = "/_"
	}

	// Logout
	if path == "/_/logout" {
		auth.Logout(w)
		r.redirect(w, req, "/_")
		return
	}

	// Upgrade/check-update require auth
	if (path == "/_/upgrade" || path == "/_/check-update") && !auth.IsAuthenticated(req) {
		r.html(w, renderer.Login("", auth.EnsureCSRFToken(w, req)), http.StatusOK)
		return
	}

	// Login gate
	if !auth.IsAuthenticated(req) {
		r.handleLogin(w, req, renderer, auth)
		return
	}

	switch path {
	case "/_/config":
		r.handleConfig(w, req, renderer, writer, auth, cfg)
	case "/_/files":
		r.handleFiles(w, req, renderer, auth, cfg)
	case "/_/upgrade":
		r.handleUpgrade(w, req, auth, cfg)
	case "/_/check-update":
		r.handleCheckUpdate(w, req, auth, cfg)
	default:
		r.handleDashboard(w, req, renderer, auth, cfg)
	}
}

func (r *AdminRouter) handleInstaller(w http.ResponseWriter, req *http.Request, renderer *AdminRenderer, writer *AdminConfigWriter) {
	// Installer auth: no password configured, so use a temporary auth
	// with empty hash. CSRF token is generated fresh.
	tempAuth := NewAdminAuth("admin", "", randomToken(32))
	values := r.defaultInstallerValues()
	csrfToken := tempAuth.EnsureCSRFToken(w, req)

	if req.Method == http.MethodPost {
		if err := req.ParseForm(); err != nil {
			r.html(w, renderer.Installer(values, []string{"Invalid form data"}, csrfToken), http.StatusBadRequest)
			return
		}
		if !tempAuth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
			r.html(w, renderer.Installer(mergeValues(values, req.Form), []string{"CSRF token is invalid"}, csrfToken), http.StatusBadRequest)
			return
		}

		input := configInputFromForm(req.Form)
		cfg, err := writer.BuildConfig(input, nil)
		if err != nil {
			r.html(w, renderer.Installer(mergeValues(values, req.Form), []string{err.Error()}, csrfToken), http.StatusBadRequest)
			return
		}
		if err := writer.WriteInstallerConfig(cfg); err != nil {
			r.html(w, renderer.Installer(mergeValues(values, req.Form), []string{err.Error()}, csrfToken), http.StatusBadRequest)
			return
		}

		// Log in with the new credentials
		loginAuth := NewAdminAuth(cfg.Admin.Username, cfg.Admin.PasswordHash, cfg.Admin.SessionSecret)
		loginAuth.Login(w, req, req.FormValue("admin_username"), req.FormValue("admin_password"))
		r.redirect(w, req, "/_")
		return
	}

	r.html(w, renderer.Installer(values, nil, csrfToken), http.StatusOK)
}

func (r *AdminRouter) handleLogin(w http.ResponseWriter, req *http.Request, renderer *AdminRenderer, auth *AdminAuth) {
	csrfToken := auth.EnsureCSRFToken(w, req)

	if req.Method == http.MethodPost {
		if err := req.ParseForm(); err != nil {
			r.html(w, renderer.Login("Invalid form data", csrfToken), http.StatusBadRequest)
			return
		}
		if !auth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
			r.html(w, renderer.Login("CSRF token is invalid", csrfToken), http.StatusBadRequest)
			return
		}
		if auth.Login(w, req, req.FormValue("username"), req.FormValue("password")) {
			r.redirect(w, req, "/_")
			return
		}
		r.html(w, renderer.Login("Invalid username or password", csrfToken), http.StatusUnauthorized)
		return
	}

	r.html(w, renderer.Login("", csrfToken), http.StatusOK)
}

func (r *AdminRouter) handleConfig(w http.ResponseWriter, req *http.Request, renderer *AdminRenderer, writer *AdminConfigWriter, auth *AdminAuth, cfg *config.Config) {
	csrfToken := auth.EnsureCSRFToken(w, req)
	values := valuesFromConfig(cfg)

	if req.Method == http.MethodPost {
		if err := req.ParseForm(); err != nil {
			r.html(w, renderer.ConfigPage(mergeValuesInterface(values, req.Form), []string{"Invalid form data"}, csrfToken), http.StatusBadRequest)
			return
		}
		if !auth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
			r.html(w, renderer.ConfigPage(mergeValuesInterface(values, req.Form), []string{"CSRF token is invalid"}, csrfToken), http.StatusBadRequest)
			return
		}

		input := configInputFromForm(req.Form)
		newCfg, err := writer.BuildConfig(input, cfg)
		if err != nil {
			r.html(w, renderer.ConfigPage(mergeValuesInterface(values, req.Form), []string{err.Error()}, csrfToken), http.StatusBadRequest)
			return
		}
		if err := ensureWritableDataDir(newCfg.DataDir); err != nil {
			r.html(w, renderer.ConfigPage(mergeValuesInterface(values, req.Form), []string{err.Error()}, csrfToken), http.StatusBadRequest)
			return
		}
		if err := writer.WriteConfig(newCfg); err != nil {
			r.html(w, renderer.ConfigPage(mergeValuesInterface(values, req.Form), []string{err.Error()}, csrfToken), http.StatusBadRequest)
			return
		}
		r.redirect(w, req, "/_/config")
		return
	}

	r.html(w, renderer.ConfigPage(values, nil, csrfToken), http.StatusOK)
}

func (r *AdminRouter) handleDashboard(w http.ResponseWriter, req *http.Request, renderer *AdminRenderer, auth *AdminAuth, cfg *config.Config) {
	csrfToken := auth.EnsureCSRFToken(w, req)
	upgradeService := r.getUpgradeService(cfg)
	var updateStatus map[string]interface{}
	if r.version == "" {
		updateStatus, _ = upgradeService.Status("")
	} else {
		updateStatus, _ = upgradeService.CachedStatus(r.version, false)
	}
	updateStatus["csrfToken"] = csrfToken

	stats := ScanStats(cfg.DataDir)
	flash := auth.ConsumeFlash(w, req)
	endpoint := r.endpoint(req)

	r.html(w, renderer.Dashboard(stats, cfg, endpoint, updateStatus, flash), http.StatusOK)
}

func (r *AdminRouter) handleCheckUpdate(w http.ResponseWriter, req *http.Request, auth *AdminAuth, cfg *config.Config) {
	if req.Method != http.MethodPost {
		r.redirect(w, req, "/_")
		return
	}
	if err := req.ParseForm(); err != nil {
		auth.SetFlash(w, req, "Invalid form data.")
		r.redirect(w, req, "/_")
		return
	}
	if !auth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
		auth.SetFlash(w, req, "CSRF token is invalid.")
		r.redirect(w, req, "/_")
		return
	}

	if r.version == "" {
		auth.SetFlash(w, req, "Auto-upgrade is only available for generated release installs.")
		r.redirect(w, req, "/_")
		return
	}

	upgradeService := r.getUpgradeService(cfg)
	status, _ := upgradeService.CachedStatus(r.version, true)
	if msg, ok := status["message"].(string); ok {
		auth.SetFlash(w, req, msg)
	}
	r.redirect(w, req, "/_")
}

func (r *AdminRouter) handleUpgrade(w http.ResponseWriter, req *http.Request, auth *AdminAuth, cfg *config.Config) {
	if req.Method != http.MethodPost {
		r.redirect(w, req, "/_")
		return
	}
	if err := req.ParseForm(); err != nil {
		auth.SetFlash(w, req, "Invalid form data.")
		r.redirect(w, req, "/_")
		return
	}
	if !auth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
		auth.SetFlash(w, req, "CSRF token is invalid.")
		r.redirect(w, req, "/_")
		return
	}

	if r.version == "" {
		auth.SetFlash(w, req, "Auto-upgrade is only available for generated release installs.")
		r.redirect(w, req, "/_")
		return
	}

	latestVersion := req.FormValue("latest_version")
	assetURL := req.FormValue("asset_url")
	upgradeService := r.getUpgradeService(cfg)
	result, _ := upgradeService.Upgrade(r.version, latestVersion, assetURL)
	if msg, ok := result["message"].(string); ok {
		auth.SetFlash(w, req, msg)
	}
	r.redirect(w, req, "/_")
}

func (r *AdminRouter) handleFiles(w http.ResponseWriter, req *http.Request, renderer *AdminRenderer, auth *AdminAuth, cfg *config.Config) {
	csrfToken := auth.EnsureCSRFToken(w, req)
	explorer := NewAdminFileExplorer(cfg.DataDir)

	bucket := strings.TrimSpace(req.URL.Query().Get("bucket"))
	if bucket == "" && req.Method == http.MethodPost {
		if err := req.ParseForm(); err != nil {
			r.json(w, map[string]interface{}{"ok": false, "message": "Invalid form data"}, http.StatusBadRequest)
			return
		}
		bucket = strings.TrimSpace(req.FormValue("bucket"))
	}
	prefix := strings.Trim(strings.TrimSpace(req.URL.Query().Get("prefix")), "/")
	if prefix == "" && req.Method == http.MethodPost {
		prefix = strings.Trim(strings.TrimSpace(req.FormValue("prefix")), "/")
	}

	// GET with download param — stream file
	if req.Method == http.MethodGet {
		if _, ok := req.URL.Query()["download"]; ok {
			objectPath := strings.Trim(req.URL.Query().Get("path"), "/")
			download := req.URL.Query().Get("download") == "1"
			r.streamFile(w, explorer, bucket, objectPath, download)
			return
		}
	}

	// POST — file management actions
	if req.Method == http.MethodPost {
		if err := req.ParseMultipartForm(32 << 20); err != nil {
			if err := req.ParseForm(); err != nil {
				r.json(w, map[string]interface{}{"ok": false, "message": "Invalid form data"}, http.StatusBadRequest)
				return
			}
		}
		if !auth.VerifyCSRFToken(req, req.FormValue("csrf_token")) {
			r.json(w, map[string]interface{}{"ok": false, "message": "CSRF token is invalid."}, http.StatusBadRequest)
			return
		}

		action := strings.TrimSpace(req.FormValue("action"))
		result, err := r.handleFileAction(explorer, action, req)
		if err != nil {
			r.json(w, map[string]interface{}{"ok": false, "message": err.Error()}, http.StatusBadRequest)
			return
		}
		response := map[string]interface{}{"ok": true}
		for k, v := range result {
			response[k] = v
		}
		r.json(w, response, http.StatusOK)
		return
	}

	// GET — render files page
	buckets := ListBuckets(cfg.DataDir)
	var listing *ListObjectsResult
	if bucket != "" {
		var err error
		listing, err = explorer.ListObjects(bucket, prefix)
		if err != nil {
			auth.SetFlash(w, req, err.Error())
			r.redirect(w, req, "/_/files")
			return
		}
	} else {
		listing = &ListObjectsResult{Folders: nil, Files: nil}
	}

	flash := auth.ConsumeFlash(w, req)
	r.html(w, renderer.Files(buckets, listing, bucket, prefix, csrfToken, flash), http.StatusOK)
}

func (r *AdminRouter) handleFileAction(explorer *AdminFileExplorer, action string, req *http.Request) (map[string]interface{}, error) {
	switch action {
	case "create_bucket":
		name := strings.TrimSpace(req.FormValue("name"))
		if err := explorer.CreateBucket(name); err != nil {
			return nil, err
		}
		return map[string]interface{}{
			"message":  "Bucket created.",
			"redirect": "/_/files?bucket=" + url.QueryEscape(name),
		}, nil

	case "rename_bucket":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		name := strings.TrimSpace(req.FormValue("name"))
		if err := explorer.RenameBucket(bucket, name); err != nil {
			return nil, err
		}
		return map[string]interface{}{
			"message":  "Bucket renamed.",
			"redirect": "/_/files?bucket=" + url.QueryEscape(name),
		}, nil

	case "delete_bucket":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		if err := explorer.DeleteBucket(bucket); err != nil {
			return nil, err
		}
		return map[string]interface{}{
			"message":  "Bucket deleted.",
			"redirect": "/_/files",
		}, nil

	case "create_folder":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		path := strings.Trim(strings.TrimSpace(req.FormValue("path")), "/")
		if err := explorer.CreateFolder(bucket, path); err != nil {
			return nil, err
		}
		return map[string]interface{}{
			"message":  "Folder created.",
			"redirect": "/_/files?bucket=" + url.QueryEscape(bucket) + "&prefix=" + url.QueryEscape(path),
		}, nil

	case "rename_object":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		oldPath := strings.Trim(strings.TrimSpace(req.FormValue("path")), "/")
		name := strings.TrimSpace(req.FormValue("name"))
		renamed, err := explorer.Rename(bucket, oldPath, name)
		if err != nil {
			return nil, err
		}
		parentPrefix := parentDir(renamed.Path)
		redirect := "/_/files?bucket=" + url.QueryEscape(bucket)
		if parentPrefix != "" {
			redirect += "&prefix=" + url.QueryEscape(parentPrefix)
		}
		return map[string]interface{}{
			"message":  "Item renamed.",
			"redirect": redirect,
		}, nil

	case "delete_object":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		path := strings.Trim(strings.TrimSpace(req.FormValue("path")), "/")
		parentPrefix := parentDir(path)
		if err := explorer.DeleteObject(bucket, path); err != nil {
			return nil, err
		}
		redirect := "/_/files?bucket=" + url.QueryEscape(bucket)
		if parentPrefix != "" {
			redirect += "&prefix=" + url.QueryEscape(parentPrefix)
		}
		return map[string]interface{}{
			"message":  "Item deleted.",
			"redirect": redirect,
		}, nil

	case "bulk_delete":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		req.ParseMultipartForm(32 << 20)
		items := req.PostForm["items"]
		if len(items) == 0 {
			items = req.Form["items"]
		}
		if len(items) == 0 {
			return nil, fmt.Errorf("no items selected")
		}
		for _, item := range items {
			if err := explorer.DeleteObject(bucket, strings.Trim(item, "/")); err != nil {
				return nil, err
			}
		}
		prefix := strings.Trim(strings.TrimSpace(req.FormValue("prefix")), "/")
		redirect := "/_/files?bucket=" + url.QueryEscape(bucket)
		if prefix != "" {
			redirect += "&prefix=" + url.QueryEscape(prefix)
		}
		return map[string]interface{}{
			"message":  "Selected items deleted.",
			"redirect": redirect,
		}, nil

	case "upload":
		bucket := strings.TrimSpace(req.FormValue("bucket"))
		prefix := strings.Trim(strings.TrimSpace(req.FormValue("prefix")), "/")
		file, header, err := req.FormFile("file")
		if err != nil {
			return nil, fmt.Errorf("no file uploaded")
		}
		file.Close()
		uploadedPath, err := explorer.UploadFile(bucket, prefix, header)
		if err != nil {
			return nil, err
		}
		redirectPrefix := parentDir(uploadedPath)
		redirect := "/_/files?bucket=" + url.QueryEscape(bucket)
		if redirectPrefix != "" {
			redirect += "&prefix=" + url.QueryEscape(redirectPrefix)
		}
		return map[string]interface{}{
			"message":  "File uploaded.",
			"redirect": redirect,
		}, nil

	default:
		return nil, fmt.Errorf("unknown action")
	}
}

func (r *AdminRouter) streamFile(w http.ResponseWriter, explorer *AdminFileExplorer, bucket, objectPath string, download bool) {
	info, err := explorer.ObjectInfo(bucket, objectPath)
	if err != nil {
		http.Error(w, fmt.Sprintf("<!doctype html><title>Not found</title><p>%s</p>", html.EscapeString(err.Error())), http.StatusNotFound)
		return
	}
	fullPath, err := explorer.ObjectFullPath(bucket, objectPath)
	if err != nil {
		http.Error(w, fmt.Sprintf("<!doctype html><title>Not found</title><p>%s</p>", html.EscapeString(err.Error())), http.StatusNotFound)
		return
	}

	w.Header().Set("Content-Type", info.Mime)
	w.Header().Set("Content-Length", fmt.Sprintf("%d", info.Size))
	w.Header().Set("X-Content-Type-Options", "nosniff")
	if download {
		w.Header().Set("Content-Disposition", fmt.Sprintf("attachment; filename=\"%s\"", url.QueryEscape(info.Name)))
	}
	w.WriteHeader(http.StatusOK)
	http.ServeFile(w, &http.Request{Method: "GET", URL: &url.URL{}}, fullPath)
}

func (r *AdminRouter) getUpgradeService(cfg *config.Config) *AdminUpgradeService {
	if r.upgradeService != nil {
		return r.upgradeService
	}
	exe, _ := os.Executable()
	r.upgradeService = NewAdminUpgradeService(r.baseDir, cfg.DataDir, exe, cfg.GitHubToken)
	return r.upgradeService
}

func (r *AdminRouter) defaultInstallerValues() map[string]string {
	return map[string]string{
		"admin_username":         "admin",
		"data_dir":               filepath.Join(r.baseDir, "data"),
		"max_request_size":       "104857600",
		"public_read_all_buckets": "true",
		"clock_skew_seconds":     "900",
		"max_presign_expires":    "604800",
	}
}

func (r *AdminRouter) endpoint(req *http.Request) string {
	scheme := "http"
	if req.TLS != nil {
		scheme = "https"
	}
	host := req.Host
	if host == "" {
		host = "localhost"
	}
	return scheme + "://" + host
}

func (r *AdminRouter) html(w http.ResponseWriter, body string, status int) {
	w.Header().Set("Content-Type", "text/html; charset=UTF-8")
	w.WriteHeader(status)
	w.Write([]byte(body))
}

func (r *AdminRouter) json(w http.ResponseWriter, payload map[string]interface{}, status int) {
	w.Header().Set("Content-Type", "application/json; charset=UTF-8")
	w.WriteHeader(status)
	json.NewEncoder(w).Encode(payload)
}

func (r *AdminRouter) redirect(w http.ResponseWriter, req *http.Request, path string) {
	http.Redirect(w, req, path, http.StatusFound)
}

// --- helpers ---

func configInputFromForm(form url.Values) ConfigInput {
	return ConfigInput{
		DataDir:                     form.Get("data_dir"),
		AccessKey:                   form.Get("access_key"),
		SecretKey:                   form.Get("secret_key"),
		AdminUsername:               form.Get("admin_username"),
		AdminPassword:               form.Get("admin_password"),
		AdminPasswordConfirm:        form.Get("admin_password_confirm"),
		MaxRequestSize:              form.Get("max_request_size"),
		ClockSkewSeconds:             form.Get("clock_skew_seconds"),
		MaxPresignExpires:            form.Get("max_presign_expires"),
		AuthDebugLog:                form.Get("auth_debug_log"),
		AllowHostCandidateFallbacks: form.Get("allow_host_candidate_fallbacks"),
		PublicReadAllBuckets:        form.Get("public_read_all_buckets"),
	}
}

func valuesFromConfig(cfg *config.Config) map[string]interface{} {
	accessKey := ""
	secretKey := ""
	for k, v := range cfg.Credentials {
		accessKey = k
		secretKey = v
		break
	}
	return map[string]interface{}{
		"admin_username":                cfg.Admin.Username,
		"data_dir":                      cfg.DataDir,
		"access_key":                    accessKey,
		"secret_key":                    secretKey,
		"max_request_size":              fmt.Sprintf("%d", cfg.MaxRequestSize),
		"public_read_all_buckets":       cfg.PublicReadAllBuckets,
		"auth_debug_log":                cfg.AuthDebugLog,
		"allow_host_candidate_fallbacks": cfg.AllowHostCandidateFallbacks,
		"clock_skew_seconds":            fmt.Sprintf("%d", cfg.ClockSkewSeconds),
		"max_presign_expires":           fmt.Sprintf("%d", cfg.MaxPresignExpires),
	}
}

func mergeValues(defaults map[string]string, form url.Values) map[string]string {
	result := make(map[string]string)
	for k, v := range defaults {
		result[k] = v
	}
	for k := range form {
		result[k] = form.Get(k)
	}
	return result
}

func mergeValuesInterface(defaults map[string]interface{}, form url.Values) map[string]interface{} {
	result := make(map[string]interface{})
	for k, v := range defaults {
		result[k] = v
	}
	for k := range form {
		result[k] = form.Get(k)
	}
	return result
}

func parentDir(path string) string {
	idx := strings.LastIndex(path, "/")
	if idx == -1 {
		return ""
	}
	return path[:idx]
}
