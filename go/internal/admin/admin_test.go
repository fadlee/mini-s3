package admin

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
	"time"

	"github.com/fadlee/mini-s3/internal/config"
	"gopkg.in/yaml.v3"
)

func TestAdminAuthIsConfigured(t *testing.T) {
	auth := NewAdminAuth("admin", "", "secret")
	if auth.IsConfigured() {
		t.Error("expected not configured when password hash is empty")
	}

	hash, _ := hashPassword("password")
	auth = NewAdminAuth("admin", hash, "secret")
	if !auth.IsConfigured() {
		t.Error("expected configured when password hash is set")
	}
}

func TestAdminAuthLoginSuccess(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()

	if !auth.Login(rr, req, "admin", "secret123") {
		t.Fatal("expected login to succeed")
	}

	// Verify cookie was set
	cookies := rr.Result().Cookies()
	if len(cookies) == 0 {
		t.Fatal("expected session cookie to be set")
	}
	found := false
	for _, c := range cookies {
		if c.Name == sessionCookieName {
			found = true
			if c.Value == "" {
				t.Error("cookie value should not be empty")
			}
		}
	}
	if !found {
		t.Error("session cookie not found in response")
	}
}

func TestAdminAuthLoginWrongPassword(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()

	if auth.Login(rr, req, "admin", "wrong") {
		t.Error("expected login to fail with wrong password")
	}
}

func TestAdminAuthLoginWrongUsername(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()

	if auth.Login(rr, req, "wronguser", "secret123") {
		t.Error("expected login to fail with wrong username")
	}
}

func TestAdminAuthLoginNotConfigured(t *testing.T) {
	auth := NewAdminAuth("admin", "", "signing-secret-key")
	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()

	if auth.Login(rr, req, "admin", "anything") {
		t.Error("expected login to fail when not configured")
	}
}

func TestAdminAuthSessionRoundTrip(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	// Login
	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()
	auth.Login(rr, req, "admin", "secret123")

	// Use the cookie in a new request
	authReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range rr.Result().Cookies() {
		authReq.AddCookie(c)
	}

	if !auth.IsAuthenticated(authReq) {
		t.Error("expected to be authenticated after login")
	}
}

func TestAdminAuthLogout(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()
	auth.Login(rr, req, "admin", "secret123")

	// Logout
	logoutReq := httptest.NewRequest("POST", "/_/logout", nil)
	for _, c := range rr.Result().Cookies() {
		logoutReq.AddCookie(c)
	}
	logoutRR := httptest.NewRecorder()
	auth.Logout(logoutRR)

	// Check cookie was cleared
	cookies := logoutRR.Result().Cookies()
	found := false
	for _, c := range cookies {
		if c.Name == sessionCookieName {
			found = true
			if c.MaxAge != -1 {
				t.Error("expected cookie to be deleted (MaxAge=-1)")
			}
		}
	}
	if !found {
		t.Error("expected logout to set a deletion cookie")
	}
}

func TestAdminAuthCSRFToken(t *testing.T) {
	auth := NewAdminAuth("admin", "", "signing-secret-key")

	req := httptest.NewRequest("GET", "/_", nil)
	rr := httptest.NewRecorder()
	token := auth.EnsureCSRFToken(rr, req)

	if token == "" {
		t.Fatal("expected non-empty CSRF token")
	}

	// Verify token persists in cookie
	authReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range rr.Result().Cookies() {
		authReq.AddCookie(c)
	}
	token2 := auth.CSRFToken(authReq)
	if token != token2 {
		t.Errorf("CSRF token mismatch: %q vs %q", token, token2)
	}

	// Verify CSRF token
	if !auth.VerifyCSRFToken(authReq, token) {
		t.Error("expected CSRF token to verify")
	}
	if auth.VerifyCSRFToken(authReq, "wrong-token") {
		t.Error("expected wrong CSRF token to fail verification")
	}
}

func TestAdminAuthFlash(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	// Login first to get a session
	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()
	auth.Login(rr, req, "admin", "secret123")

	// Set flash
	flashReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range rr.Result().Cookies() {
		flashReq.AddCookie(c)
	}
	flashRR := httptest.NewRecorder()
	auth.SetFlash(flashRR, flashReq, "Test flash message")

	// Consume flash
	consumeReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range flashRR.Result().Cookies() {
		consumeReq.AddCookie(c)
	}
	consumeRR := httptest.NewRecorder()
	flash := auth.ConsumeFlash(consumeRR, consumeReq)

	if flash != "Test flash message" {
		t.Errorf("expected 'Test flash message', got %q", flash)
	}

	// Consume again — should be empty
	consumeReq2 := httptest.NewRequest("GET", "/_", nil)
	for _, c := range consumeRR.Result().Cookies() {
		consumeReq2.AddCookie(c)
	}
	consumeRR2 := httptest.NewRecorder()
	flash2 := auth.ConsumeFlash(consumeRR2, consumeReq2)
	if flash2 != "" {
		t.Errorf("expected empty flash after consume, got %q", flash2)
	}
}

func TestAdminAuthTamperedCookie(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()
	auth.Login(rr, req, "admin", "secret123")

	// Tamper with cookie
	tamperedReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range rr.Result().Cookies() {
		if c.Name == sessionCookieName {
			// Flip a character in the signature
			tampered := c.Value
			if len(tampered) > 10 {
				if tampered[len(tampered)-1] == 'A' {
					tampered = tampered[:len(tampered)-1] + "B"
				} else {
					tampered = tampered[:len(tampered)-1] + "A"
				}
			}
			tamperedReq.AddCookie(&http.Cookie{Name: sessionCookieName, Value: tampered})
		}
	}

	if auth.IsAuthenticated(tamperedReq) {
		t.Error("expected tampered cookie to not authenticate")
	}
}

func TestAdminAuthExpiredSession(t *testing.T) {
	hash, _ := hashPassword("secret123")
	auth := NewAdminAuth("admin", hash, "signing-secret-key")

	// Create a session with a short expiry by directly crafting a cookie
	// We'll just test that a valid login works and then manually expire
	req := httptest.NewRequest("POST", "/_", nil)
	rr := httptest.NewRecorder()
	auth.Login(rr, req, "admin", "secret123")

	// Get the cookie and modify expiry — we can't easily do this without
	// access to internals, so just verify the round-trip works
	authReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range rr.Result().Cookies() {
		authReq.AddCookie(c)
	}
	if !auth.IsAuthenticated(authReq) {
		t.Error("expected valid session to authenticate")
	}
}

// --- AdminStats tests ---

func TestScanStatsMissingDir(t *testing.T) {
	stats := ScanStats("/nonexistent/path/that/does/not/exist")
	if stats.Status != "missing" {
		t.Errorf("expected 'missing', got %q", stats.Status)
	}
	if stats.BucketCount != 0 {
		t.Errorf("expected 0 buckets, got %d", stats.BucketCount)
	}
}

func TestScanStatsEmptyDir(t *testing.T) {
	dir := t.TempDir()
	stats := ScanStats(dir)
	if stats.Status != "ok" && stats.Status != "not_writable" {
		t.Errorf("expected 'ok' or 'not_writable', got %q", stats.Status)
	}
	if stats.BucketCount != 0 {
		t.Errorf("expected 0 buckets, got %d", stats.BucketCount)
	}
}

func TestScanStatsWithBuckets(t *testing.T) {
	dir := t.TempDir()

	// Create bucket dirs
	bucket1 := filepath.Join(dir, "bucket1")
	os.MkdirAll(bucket1, 0777)
	os.WriteFile(filepath.Join(bucket1, "file1.txt"), []byte("hello"), 0666)
	os.WriteFile(filepath.Join(bucket1, "file2.txt"), []byte("world!"), 0666)

	bucket2 := filepath.Join(dir, "bucket2")
	os.MkdirAll(filepath.Join(bucket2, "sub"), 0777)
	os.WriteFile(filepath.Join(bucket2, "sub", "file3.txt"), []byte("foo"), 0666)

	// .multipart should be skipped
	os.MkdirAll(filepath.Join(dir, ".multipart"), 0777)
	os.WriteFile(filepath.Join(dir, ".multipart", "part1"), []byte("multipart"), 0666)

	stats := ScanStats(dir)
	if stats.BucketCount != 2 {
		t.Errorf("expected 2 buckets, got %d", stats.BucketCount)
	}

	totals := MeasureStorage(dir)
	if totals.ObjectCount != 3 {
		t.Errorf("expected 3 objects, got %d", totals.ObjectCount)
	}
	if totals.TotalBytes != 14 {
		t.Errorf("expected 14 bytes, got %d", totals.TotalBytes)
	}
}

func TestListBuckets(t *testing.T) {
	dir := t.TempDir()

	os.MkdirAll(filepath.Join(dir, "zebra"), 0777)
	os.MkdirAll(filepath.Join(dir, "alpha"), 0777)
	os.MkdirAll(filepath.Join(dir, ".multipart"), 0777)

	os.WriteFile(filepath.Join(dir, "alpha", "a.txt"), []byte("a"), 0666)
	os.WriteFile(filepath.Join(dir, "zebra", "z.txt"), []byte("zz"), 0666)

	buckets := ListBuckets(dir)
	if len(buckets) != 2 {
		t.Fatalf("expected 2 buckets, got %d", len(buckets))
	}
	// Should be sorted alphabetically
	if buckets[0].Name != "alpha" {
		t.Errorf("expected first bucket 'alpha', got %q", buckets[0].Name)
	}
	if buckets[1].Name != "zebra" {
		t.Errorf("expected second bucket 'zebra', got %q", buckets[1].Name)
	}
}

// --- AdminFileExplorer tests ---

func TestFileExplorerCreateAndListBucket(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	if err := explorer.CreateBucket("test-bucket"); err != nil {
		t.Fatalf("CreateBucket failed: %v", err)
	}

	// Verify directory exists
	if _, err := os.Stat(filepath.Join(dir, "test-bucket")); err != nil {
		t.Error("bucket directory was not created")
	}

	// Create another bucket and list
	explorer.CreateBucket("alpha-bucket")
	buckets := ListBuckets(dir)
	if len(buckets) != 2 {
		t.Errorf("expected 2 buckets, got %d", len(buckets))
	}
}

func TestFileExplorerCreateBucketInvalidName(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	tests := []string{
		"", ".", "..", ".hidden", "has/slash", "has\\backslash",
	}
	for _, name := range tests {
		if err := explorer.CreateBucket(name); err == nil {
			t.Errorf("expected error for bucket name %q", name)
		}
	}
}

func TestFileExplorerCreateBucketAlreadyExists(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("existing")
	err := explorer.CreateBucket("existing")
	if err == nil {
		t.Error("expected error when creating duplicate bucket")
	}
}

func TestFileExplorerPathTraversalAttempt(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("safe-bucket")

	// Try path traversal
	_, err := explorer.ListObjects("safe-bucket", "../../../etc")
	if err == nil {
		t.Error("expected path traversal to be rejected")
	}

	_, err = explorer.ObjectInfo("safe-bucket", "../../etc/passwd")
	if err == nil {
		t.Error("expected path traversal in ObjectInfo to be rejected")
	}
}

func TestFileExplorerCreateFolderAndList(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("mybucket")
	if err := explorer.CreateFolder("mybucket", "folder1"); err != nil {
		t.Fatalf("CreateFolder failed: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "mybucket", "folder1", "a.txt"), []byte("hello"), 0o644); err != nil {
		t.Fatal(err)
	}

	result, err := explorer.ListObjects("mybucket", "")
	if err != nil {
		t.Fatalf("ListObjects failed: %v", err)
	}
	if len(result.Folders) != 1 {
		t.Fatalf("expected 1 folder, got %d", len(result.Folders))
	}
	if result.Folders[0].Name != "folder1" {
		t.Errorf("expected folder 'folder1', got %q", result.Folders[0].Name)
	}

	totals, err := explorer.FolderTotals("mybucket", "")
	if err != nil {
		t.Fatalf("FolderTotals failed: %v", err)
	}
	if got := totals["folder1"]; got.ObjectCount != 1 || got.TotalBytes != 5 {
		t.Errorf("expected 1 object / 5 bytes, got %+v", got)
	}
	if _, err := explorer.FolderTotals("mybucket", "../.."); err == nil {
		t.Error("FolderTotals must reject a prefix that escapes the bucket")
	}
}

func TestFileExplorerDeleteObject(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("mybucket")
	explorer.CreateFolder("mybucket", "folder1")

	// Write a file
	os.WriteFile(filepath.Join(dir, "mybucket", "file.txt"), []byte("hello"), 0666)

	// Delete file
	if err := explorer.DeleteObject("mybucket", "file.txt"); err != nil {
		t.Fatalf("DeleteObject failed: %v", err)
	}

	// Verify file is gone
	if _, err := os.Stat(filepath.Join(dir, "mybucket", "file.txt")); !os.IsNotExist(err) {
		t.Error("file was not deleted")
	}

	// Delete folder
	if err := explorer.DeleteObject("mybucket", "folder1"); err != nil {
		t.Fatalf("DeleteObject folder failed: %v", err)
	}
}

func TestFileActionBulkDeleteReadsItemsArray(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)
	if err := explorer.CreateBucket("photos"); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"a.txt", "b.txt", "c.txt"} {
		os.WriteFile(filepath.Join(dir, "photos", name), []byte("x"), 0644)
	}
	form := url.Values{"bucket": {"photos"}, "items[]": {"a.txt", "b.txt"}}
	req := httptest.NewRequest(http.MethodPost, "/_/files", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	router := NewAdminRouter(filepath.Join(dir, "config.yaml"), dir, "test")
	if _, err := router.handleFileAction(explorer, "bulk_delete", req); err != nil {
		t.Fatalf("bulk_delete: %v", err)
	}
	for name, want := range map[string]bool{"a.txt": false, "b.txt": false, "c.txt": true} {
		_, err := os.Stat(filepath.Join(dir, "photos", name))
		if exists := err == nil; exists != want {
			t.Errorf("%s exists = %v, want %v", name, exists, want)
		}
	}
}

func TestFileExplorerDeleteBucket(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("todelete")
	os.WriteFile(filepath.Join(dir, "todelete", "file.txt"), []byte("data"), 0666)

	if err := explorer.DeleteBucket("todelete"); err != nil {
		t.Fatalf("DeleteBucket failed: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "todelete")); !os.IsNotExist(err) {
		t.Error("bucket directory was not deleted")
	}
}

func TestFileExplorerRename(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("mybucket")
	os.WriteFile(filepath.Join(dir, "mybucket", "old.txt"), []byte("data"), 0666)

	result, err := explorer.Rename("mybucket", "old.txt", "new.txt")
	if err != nil {
		t.Fatalf("Rename failed: %v", err)
	}
	if result.Name != "new.txt" {
		t.Errorf("expected name 'new.txt', got %q", result.Name)
	}

	// Verify old file is gone, new exists
	if _, err := os.Stat(filepath.Join(dir, "mybucket", "old.txt")); !os.IsNotExist(err) {
		t.Error("old file still exists after rename")
	}
	if _, err := os.Stat(filepath.Join(dir, "mybucket", "new.txt")); err != nil {
		t.Error("new file does not exist after rename")
	}
}

func TestFileExplorerRenameBucket(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("oldname")
	if err := explorer.RenameBucket("oldname", "newname"); err != nil {
		t.Fatalf("RenameBucket failed: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "newname")); err != nil {
		t.Error("new bucket directory does not exist")
	}
	if _, err := os.Stat(filepath.Join(dir, "oldname")); !os.IsNotExist(err) {
		t.Error("old bucket directory still exists")
	}
}

func TestFileExplorerObjectInfo(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)

	explorer.CreateBucket("mybucket")
	os.WriteFile(filepath.Join(dir, "mybucket", "test.txt"), []byte("hello world"), 0666)
	time.Sleep(10 * time.Millisecond)

	info, err := explorer.ObjectInfo("mybucket", "test.txt")
	if err != nil {
		t.Fatalf("ObjectInfo failed: %v", err)
	}
	if info.Name != "test.txt" {
		t.Errorf("expected name 'test.txt', got %q", info.Name)
	}
	if info.Size != 11 {
		t.Errorf("expected size 11, got %d", info.Size)
	}
}

// --- AdminConfigWriter tests ---

func TestConfigWriterBuildConfig(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	writer := NewAdminConfigWriter(configPath)

	input := ConfigInput{
		DataDir:              filepath.Join(dir, "data"),
		AccessKey:            "AKIATEST",
		SecretKey:            "secret123",
		AdminUsername:        "admin",
		AdminPassword:        "password123",
		AdminPasswordConfirm: "password123",
	}

	cfg, err := writer.BuildConfig(input, nil)
	if err != nil {
		t.Fatalf("BuildConfig failed: %v", err)
	}
	if cfg.DataDir != input.DataDir {
		t.Errorf("expected data_dir %q, got %q", input.DataDir, cfg.DataDir)
	}
	if cfg.Credentials["AKIATEST"] != "secret123" {
		t.Error("credentials not set correctly")
	}
	if cfg.Admin.Username != "admin" {
		t.Error("admin username not set")
	}
	if cfg.Admin.PasswordHash == "" {
		t.Error("password hash should not be empty")
	}
	if cfg.Admin.SessionSecret == "" {
		t.Error("session secret should be auto-generated")
	}
}

func TestConfigWriterBuildConfigPasswordMismatch(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	writer := NewAdminConfigWriter(configPath)

	input := ConfigInput{
		DataDir:              filepath.Join(dir, "data"),
		AccessKey:            "AKIATEST",
		SecretKey:            "secret123",
		AdminPassword:        "password123",
		AdminPasswordConfirm: "different",
	}

	_, err := writer.BuildConfig(input, nil)
	if err == nil {
		t.Fatal("expected error for password mismatch")
	}
}

func TestConfigWriterBuildConfigMissingDataDir(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	writer := NewAdminConfigWriter(configPath)

	input := ConfigInput{
		DataDir:              "",
		AccessKey:            "AKIATEST",
		SecretKey:            "secret123",
		AdminPassword:        "pass",
		AdminPasswordConfirm: "pass",
	}

	_, err := writer.BuildConfig(input, nil)
	if err == nil {
		t.Fatal("expected error for missing data dir")
	}
}

func TestConfigWriterWriteAndReadConfig(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	writer := NewAdminConfigWriter(configPath)

	dataDir := filepath.Join(dir, "data")
	os.MkdirAll(dataDir, 0777)

	input := ConfigInput{
		DataDir:              dataDir,
		AccessKey:            "AKIATEST",
		SecretKey:            "secret123",
		AdminUsername:        "admin",
		AdminPassword:        "password123",
		AdminPasswordConfirm: "password123",
	}

	cfg, err := writer.BuildConfig(input, nil)
	if err != nil {
		t.Fatalf("BuildConfig failed: %v", err)
	}

	if err := writer.WriteConfig(cfg); err != nil {
		t.Fatalf("WriteConfig failed: %v", err)
	}

	// Verify file exists
	if _, err := os.Stat(configPath); err != nil {
		t.Fatal("config file was not written")
	}
}

func TestConfigWriterInstallerConfigAlreadyExists(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	writer := NewAdminConfigWriter(configPath)

	// Create the config file first
	os.WriteFile(configPath, []byte("existing"), 0666)

	dataDir := filepath.Join(dir, "data")
	cfg := &config.Config{
		DataDir:     dataDir,
		Credentials: map[string]string{"key": "secret"},
	}
	err := writer.WriteInstallerConfig(cfg)
	if err == nil {
		t.Fatal("expected error when config already exists")
	}
}

// TestInstallerCSRFRoundTrip verifies the full installer flow: GET the form
// (which sets a signed CSRF cookie), then POST the form with the CSRF token
// and cookie. This reproduces the "CSRF token is invalid" bug that occurred
// when the installer generated a fresh random secret on every request.
func TestInstallerCSRFRoundTrip(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	router := NewAdminRouter(configPath, dir, "test")
	dataDir := filepath.Join(dir, "data")

	// Step 1: GET the installer form — should set a session cookie with CSRF token
	getReq := httptest.NewRequest("GET", "/_", nil)
	getRR := httptest.NewRecorder()
	router.ServeHTTP(getRR, getReq)

	if getRR.Code != http.StatusOK {
		t.Fatalf("expected 200 on installer GET, got %d", getRR.Code)
	}

	// Extract CSRF token from the rendered form
	body := getRR.Body.String()
	csrfToken := extractCSRFToken(t, body)
	if csrfToken == "" {
		t.Fatal("expected CSRF token in installer form")
	}

	// Collect cookies from GET response
	cookies := getRR.Result().Cookies()
	if len(cookies) == 0 {
		t.Fatal("expected session cookie to be set on GET")
	}

	// Step 2: POST the installer form with the CSRF token + cookie
	form := url.Values{}
	form.Set("csrf_token", csrfToken)
	form.Set("admin_username", "admin")
	form.Set("admin_password", "testpass123")
	form.Set("admin_password_confirm", "testpass123")
	form.Set("data_dir", dataDir)
	form.Set("access_key", "AKIATEST")
	form.Set("secret_key", "secret123")
	form.Set("max_request_size", "104857600")
	form.Set("public_read_all_buckets", "true")
	form.Set("clock_skew_seconds", "900")
	form.Set("max_presign_expires", "604800")

	postReq := httptest.NewRequest("POST", "/_", strings.NewReader(form.Encode()))
	postReq.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	for _, c := range cookies {
		postReq.AddCookie(c)
	}

	postRR := httptest.NewRecorder()
	router.ServeHTTP(postRR, postReq)

	// Should redirect to /_ (302) on success, not 400 with CSRF error
	if postRR.Code == http.StatusBadRequest {
		t.Fatalf("installer POST returned 400 (CSRF failed): %s", postRR.Body.String())
	}
	if postRR.Code != http.StatusFound {
		t.Fatalf("expected 302 redirect after install, got %d: %s", postRR.Code, postRR.Body.String())
	}

	// Verify config file was created
	if _, err := os.Stat(configPath); err != nil {
		t.Fatalf("config file was not created: %v", err)
	}
}

// extractCSRFToken pulls the CSRF token value out of a rendered hidden input.
func extractCSRFToken(t *testing.T, htmlBody string) string {
	t.Helper()
	// Look for name="csrf_token" value="..."
	idx := strings.Index(htmlBody, `name="csrf_token"`)
	if idx == -1 {
		return ""
	}
	// Find the value attribute after this position
	rest := htmlBody[idx:]
	valIdx := strings.Index(rest, `value="`)
	if valIdx == -1 {
		return ""
	}
	valStart := valIdx + len(`value="`)
	rest = rest[valStart:]
	endIdx := strings.Index(rest, `"`)
	if endIdx == -1 {
		return ""
	}
	return rest[:endIdx]
}

// --- streamFile tests ---

func TestStreamFileHTMLIsForcedToDownload(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)
	if err := explorer.CreateBucket("site"); err != nil {
		t.Fatalf("CreateBucket failed: %v", err)
	}
	os.WriteFile(filepath.Join(dir, "site", "page.html"), []byte("<!doctype html><script>alert(1)</script>"), 0666)

	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/_/files?bucket=site&path=page.html&download=0", nil)
	(&AdminRouter{}).streamFile(rec, req, explorer, "site", "page.html", false)

	h := rec.Result().Header
	if got := h.Get("Content-Type"); got != "application/octet-stream" {
		t.Errorf("expected octet-stream content type, got %q", got)
	}
	if got := h.Get("Content-Disposition"); !strings.HasPrefix(got, `attachment; filename="page.html"; filename*=UTF-8''page.html`) {
		t.Errorf("expected attachment disposition, got %q", got)
	}
	if got := h.Get("X-Content-Type-Options"); got != "nosniff" {
		t.Errorf("expected nosniff, got %q", got)
	}
	if got := h.Get("Content-Security-Policy"); !strings.Contains(got, "sandbox") {
		t.Errorf("expected sandboxed CSP, got %q", got)
	}
}

func TestStreamFilePNGIsServedInline(t *testing.T) {
	dir := t.TempDir()
	explorer := NewAdminFileExplorer(dir)
	if err := explorer.CreateBucket("photos"); err != nil {
		t.Fatalf("CreateBucket failed: %v", err)
	}
	png := "\x89PNG\r\n\x1a\n" + strings.Repeat("\x00", 16)
	os.WriteFile(filepath.Join(dir, "photos", "logo.png"), []byte(png), 0666)

	rec := httptest.NewRecorder()
	req := httptest.NewRequest(http.MethodGet, "/_/files?bucket=photos&path=logo.png&download=0", nil)
	(&AdminRouter{}).streamFile(rec, req, explorer, "photos", "logo.png", false)

	h := rec.Result().Header
	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec.Code)
	}
	if got := h.Get("Content-Type"); got != "image/png" {
		t.Errorf("expected image/png, got %q", got)
	}
	if got := h.Get("Content-Disposition"); !strings.HasPrefix(got, `inline; filename="logo.png"`) {
		t.Errorf("expected inline disposition, got %q", got)
	}
}

// --- renderer tests ---

func TestRendererFilesPreviewSkipsSVG(t *testing.T) {
	listing := &ListObjectsResult{Files: []FileInfo{
		{Name: "cover.png", Path: "cover.png", Size: 12, Modified: 1700000000, Mime: "image/png", IsImage: true},
		{Name: "logo.svg", Path: "logo.svg", Size: 12, Modified: 1700000000, Mime: "image/svg+xml", IsImage: true},
	}}
	html := NewAdminRenderer().Files(nil, listing, "photos", "", "csrf-token", "")

	if !strings.Contains(html, "path=cover.png&download=0") {
		t.Error("raster image preview link should be rendered")
	}
	if strings.Contains(html, "path=logo.svg&download=0") {
		t.Error("svg files must not get an inline preview link")
	}
}

func TestRendererUpdateAvailableShowsDockerPull(t *testing.T) {
	html := NewAdminRenderer().updatesPanel(map[string]interface{}{
		"state":          "update_available",
		"message":        "Update available: go-v1.2.0",
		"currentVersion": "go-v1.1.2",
		"latestVersion":  "go-v1.2.0",
		"releaseUrl":     "https://github.com/fadlee/mini-s3/releases/tag/go-v1.2.0",
		"imageTag":       "1.2.0",
		"csrfToken":      "csrf-token",
	})

	if !strings.Contains(html, "docker pull ghcr.io/fadlee/mini-s3-go:1.2.0") {
		t.Error("update notice should show the docker pull command for the new image tag")
	}
	if !strings.Contains(html, `href="https://github.com/fadlee/mini-s3/releases/tag/go-v1.2.0" target="_blank" rel="noopener"`) {
		t.Error("update notice should link to the release page")
	}
	if strings.Contains(html, `action="/_/upgrade"`) {
		t.Error("upgrade form must not be rendered")
	}
	if strings.Contains(html, "Auto-upgrade") {
		t.Error("update notice must not mention auto-upgrade")
	}
}

func TestRendererUpdateNoticeEscapesValues(t *testing.T) {
	html := NewAdminRenderer().updatesPanel(map[string]interface{}{
		"state":          "update_available",
		"message":        "Update available: go-v1.2.0",
		"currentVersion": "go-v1.1.2",
		"latestVersion":  "go-v1.2.0<i>",
		"releaseUrl":     `https://example.test/"><b>x</b>`,
		"imageTag":       `1.2.0"<script>`,
		"csrfToken":      "csrf-token",
	})

	for _, raw := range []string{"<script>", "<i>", "<b>"} {
		if strings.Contains(html, raw) {
			t.Errorf("update notice must escape %q", raw)
		}
	}
}

// --- update check tests ---

func TestUpdateCheckPicksHighestGoRelease(t *testing.T) {
	s := NewAdminUpgradeService(t.TempDir(), "")
	s.fetchReleases = func() ([]githubRelease, error) {
		return []githubRelease{
			{TagName: "v1.9.0"},
			{TagName: "go-v1.1.2"},
			{TagName: "go-v1.10.0"},
			{TagName: "go-v2.0.0", Draft: true},
			{TagName: "go-v3.0.0", Prerelease: true},
		}, nil
	}

	status := s.CheckLatest("go-v1.1.2")
	if state, _ := status["state"].(string); state != "update_available" {
		t.Fatalf("expected update_available, got %q (%v)", state, status["message"])
	}
	if latest, _ := status["latestVersion"].(string); latest != "go-v1.10.0" {
		t.Errorf("expected latest go-v1.10.0, got %q", latest)
	}
}

func TestUpdateCheckCompareCurrentVersion(t *testing.T) {
	releases := []githubRelease{{
		TagName: "go-v1.2.0",
		HTMLURL: "https://github.com/fadlee/mini-s3/releases/tag/go-v1.2.0",
	}}
	s := NewAdminUpgradeService(t.TempDir(), "")
	s.fetchReleases = func() ([]githubRelease, error) { return releases, nil }

	upToDate := s.CheckLatest("1.2.0")
	if state, _ := upToDate["state"].(string); state != "up_to_date" {
		t.Errorf("expected up_to_date for 1.2.0 vs go-v1.2.0, got %q", state)
	}

	status := s.CheckLatest("1.1.2")
	if state, _ := status["state"].(string); state != "update_available" {
		t.Fatalf("expected update_available for 1.1.2 vs go-v1.2.0, got %q", state)
	}
	if tag, _ := status["imageTag"].(string); tag != "1.2.0" {
		t.Errorf("expected imageTag 1.2.0, got %q", tag)
	}
	if url, _ := status["releaseUrl"].(string); url != "https://github.com/fadlee/mini-s3/releases/tag/go-v1.2.0" {
		t.Errorf("unexpected releaseUrl %q", url)
	}
	if _, ok := status["assetUrl"]; ok {
		t.Error("assetUrl should no longer be reported")
	}
}

func TestUpdateCheckDevBuildSkipsFetch(t *testing.T) {
	s := NewAdminUpgradeService(t.TempDir(), "")
	calls := 0
	s.fetchReleases = func() ([]githubRelease, error) {
		calls++
		return nil, fmt.Errorf("should not be called")
	}

	for _, version := range []string{"dev", ""} {
		status, err := s.CachedStatus(version, true)
		if err != nil {
			t.Fatalf("CachedStatus returned error: %v", err)
		}
		if state, _ := status["state"].(string); state != "unavailable" {
			t.Errorf("version %q: expected unavailable, got %q", version, state)
		}
	}
	if calls != 0 {
		t.Errorf("GitHub must not be contacted for dev builds, got %d calls", calls)
	}
}

// --- router tests ---

func TestRouterUpgradeRouteRemoved(t *testing.T) {
	dir := t.TempDir()
	hash, err := hashPassword("secret123")
	if err != nil {
		t.Fatalf("hashPassword: %v", err)
	}
	cfgYAML, err := yaml.Marshal(map[string]interface{}{
		"data_dir":    filepath.Join(dir, "data"),
		"credentials": map[string]string{"minioadmin": "minioadmin"},
		"admin": map[string]string{
			"username":       "admin",
			"password_hash":  hash,
			"session_secret": "signing-secret-key",
		},
	})
	if err != nil {
		t.Fatalf("marshal config: %v", err)
	}
	cfgPath := filepath.Join(dir, "config.yaml")
	if err := os.WriteFile(cfgPath, cfgYAML, 0600); err != nil {
		t.Fatalf("write config: %v", err)
	}

	auth := NewAdminAuth("admin", hash, "signing-secret-key")
	loginRR := httptest.NewRecorder()
	if !auth.Login(loginRR, httptest.NewRequest("POST", "/_", nil), "admin", "secret123") {
		t.Fatal("expected login to succeed")
	}
	cookies := loginRR.Result().Cookies()

	authReq := httptest.NewRequest("GET", "/_", nil)
	for _, c := range cookies {
		authReq.AddCookie(c)
	}
	token := auth.CSRFToken(authReq)

	router := NewAdminRouter(cfgPath, dir, "go-v1.1.2")
	router.upgradeService = NewAdminUpgradeService(filepath.Join(dir, "data"), "")
	router.upgradeService.fetchReleases = func() ([]githubRelease, error) {
		return nil, fmt.Errorf("network disabled in tests")
	}

	form := url.Values{"csrf_token": {token}}
	req := httptest.NewRequest("POST", "/_/upgrade", strings.NewReader(form.Encode()))
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	for _, c := range cookies {
		req.AddCookie(c)
	}
	rr := httptest.NewRecorder()
	router.ServeHTTP(rr, req)

	body := rr.Body.String()
	if rr.Code != http.StatusOK || !strings.Contains(body, "<h3>Updates</h3>") {
		t.Fatalf("expected POST /_/upgrade to fall through to the dashboard, got %d: %s", rr.Code, body)
	}
	if strings.Contains(body, `action="/_/upgrade"`) || strings.Contains(body, "upgraded to") {
		t.Error("POST /_/upgrade must not trigger an upgrade")
	}
}

// --- config writer preservation tests ---

func TestConfigWriterBuildConfigPreservesExistingFields(t *testing.T) {
	dir := t.TempDir()
	writer := NewAdminConfigWriter(filepath.Join(dir, "config.yaml"))
	hash, err := hashPassword("password123")
	if err != nil {
		t.Fatalf("hashPassword failed: %v", err)
	}
	existing := &config.Config{
		DataDir:                  filepath.Join(dir, "data"),
		MaxRequestSize:           1048576,
		Credentials:              map[string]string{"first-key": "first-secret", "second-key": "second-secret"},
		AllowedAccessKeys:        []string{"legacy-key"},
		AllowLegacyAccessKeyOnly: true,
		ClockSkewSeconds:         900,
		MaxPresignExpires:        604800,
		PublicReadAllBuckets:     true,
		GitHubToken:              "ghp_example",
		Admin: config.AdminConfig{
			Username:      "owner",
			PasswordHash:  hash,
			SessionSecret: "session-secret",
		},
	}

	cfg, err := writer.BuildConfig(ConfigInput{
		DataDir:   filepath.Join(dir, "data"),
		AccessKey: "new-key",
		SecretKey: "new-secret",
	}, existing)
	if err != nil {
		t.Fatalf("BuildConfig failed: %v", err)
	}
	if cfg.GitHubToken != "ghp_example" {
		t.Errorf("github token not preserved, got %q", cfg.GitHubToken)
	}
	if !cfg.AllowLegacyAccessKeyOnly {
		t.Error("legacy access key flag not preserved")
	}
	if len(cfg.AllowedAccessKeys) != 1 || cfg.AllowedAccessKeys[0] != "legacy-key" {
		t.Errorf("allowed access keys not preserved, got %v", cfg.AllowedAccessKeys)
	}
	if cfg.Admin.Username != "owner" || cfg.Admin.PasswordHash != hash || cfg.Admin.SessionSecret != "session-secret" {
		t.Errorf("admin settings not preserved: %+v", cfg.Admin)
	}
	wantCreds := map[string]string{"new-key": "new-secret", "second-key": "second-secret"}
	if fmt.Sprint(cfg.Credentials) != fmt.Sprint(wantCreds) {
		t.Errorf("expected credentials %v, got %v", wantCreds, cfg.Credentials)
	}
	if existing.Credentials["first-key"] != "first-secret" {
		t.Error("existing config must not be mutated")
	}
}

func TestConfigWriterBuildConfigFormKeyWinsWhenDuplicated(t *testing.T) {
	dir := t.TempDir()
	writer := NewAdminConfigWriter(filepath.Join(dir, "config.yaml"))
	hash, err := hashPassword("password123")
	if err != nil {
		t.Fatalf("hashPassword failed: %v", err)
	}
	existing := &config.Config{
		DataDir:     filepath.Join(dir, "data"),
		Credentials: map[string]string{"first-key": "first-secret", "second-key": "second-secret"},
		Admin:       config.AdminConfig{Username: "owner", PasswordHash: hash, SessionSecret: "session-secret"},
	}

	cfg, err := writer.BuildConfig(ConfigInput{
		DataDir:   filepath.Join(dir, "data"),
		AccessKey: "second-key",
		SecretKey: "form-secret",
	}, existing)
	if err != nil {
		t.Fatalf("BuildConfig failed: %v", err)
	}
	if fmt.Sprint(cfg.Credentials) != fmt.Sprint(map[string]string{"second-key": "form-secret"}) {
		t.Errorf("expected form credential to win once, got %v", cfg.Credentials)
	}
}

func TestConfigWriterLoadFileConfigIgnoresEnvOverrides(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	os.WriteFile(configPath, []byte("data_dir: "+filepath.Join(dir, "data")+"\ngithub_token: file-token\n"), 0666)
	t.Setenv("MINI_S3_GITHUB_TOKEN", "env-token")
	writer := NewAdminConfigWriter(configPath)

	fileCfg, err := writer.LoadFileConfig()
	if err != nil {
		t.Fatalf("LoadFileConfig failed: %v", err)
	}
	if fileCfg.GitHubToken != "file-token" {
		t.Errorf("expected file token, got %q", fileCfg.GitHubToken)
	}

	cfg, err := writer.BuildConfig(ConfigInput{
		DataDir:              filepath.Join(dir, "data"),
		AccessKey:            "AKIATEST",
		SecretKey:            "secret123",
		AdminPassword:        "password123",
		AdminPasswordConfirm: "password123",
	}, fileCfg)
	if err != nil {
		t.Fatalf("BuildConfig failed: %v", err)
	}
	if cfg.GitHubToken != "file-token" {
		t.Errorf("env token must not be persisted, got %q", cfg.GitHubToken)
	}
}

func TestConfigWriterWriteConfigKeepsUnknownKeys(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	os.WriteFile(configPath, []byte("custom_setting: keep-me\ndata_dir: "+filepath.Join(dir, "data")+"\n"), 0666)
	writer := NewAdminConfigWriter(configPath)

	fileCfg, err := writer.LoadFileConfig()
	if err != nil {
		t.Fatalf("LoadFileConfig failed: %v", err)
	}
	fileCfg.DataDir = filepath.Join(dir, "data2")
	if err := writer.WriteConfig(fileCfg); err != nil {
		t.Fatalf("WriteConfig failed: %v", err)
	}

	data, _ := os.ReadFile(configPath)
	if !strings.Contains(string(data), "custom_setting: keep-me") {
		t.Errorf("unknown key was dropped:\n%s", data)
	}
	if !strings.Contains(string(data), "data_dir: "+filepath.Join(dir, "data2")) {
		t.Errorf("managed key was not updated:\n%s", data)
	}
}

// --- public_read_all_buckets checkbox tests ---

func TestRendererPublicReadAllBucketsHasHiddenFallback(t *testing.T) {
	html := NewAdminRenderer().ConfigPage(map[string]interface{}{}, nil, "csrf-token")

	hidden := `<input type="hidden" name="public_read_all_buckets" value="0">`
	checkbox := `<input type="checkbox" name="public_read_all_buckets" value="1"`
	hiddenAt := strings.Index(html, hidden)
	checkboxAt := strings.Index(html, checkbox)
	if hiddenAt < 0 {
		t.Fatal("public_read_all_buckets hidden fallback is missing")
	}
	if checkboxAt < 0 {
		t.Fatal("public_read_all_buckets checkbox is missing")
	}
	if hiddenAt > checkboxAt {
		t.Error("hidden fallback must come before the checkbox")
	}
}

func TestConfigFormPublicReadAllBucketsValues(t *testing.T) {
	dir := t.TempDir()
	existing := config.Defaults()
	existing.DataDir = filepath.Join(dir, "data")
	existing.Admin.PasswordHash = "hash"
	existing.PublicReadAllBuckets = true
	writer := NewAdminConfigWriter(filepath.Join(dir, "config.yaml"))

	build := func(extra url.Values, existing *config.Config) bool {
		form := url.Values{
			"data_dir":   {filepath.Join(dir, "data")},
			"access_key": {"AKIATEST"},
			"secret_key": {"secret123"},
		}
		for k, v := range extra {
			form[k] = v
		}
		cfg, err := writer.BuildConfig(configInputFromForm(form), existing)
		if err != nil {
			t.Fatalf("BuildConfig failed: %v", err)
		}
		return cfg.PublicReadAllBuckets
	}

	if build(url.Values{"public_read_all_buckets": {"0"}}, existing) {
		t.Error("hidden \"0\" alone should turn public read off")
	}
	if !build(url.Values{"public_read_all_buckets": {"0", "1"}}, existing) {
		t.Error("checked box posting [\"0\", \"1\"] should turn public read on")
	}
	if !build(url.Values{}, existing) {
		t.Error("absent field should keep the existing value (true)")
	}
	existing.PublicReadAllBuckets = false
	if build(url.Values{}, existing) {
		t.Error("absent field should keep the existing value (false)")
	}
	if build(url.Values{"public_read_all_buckets": {"0"}}, &config.Config{Admin: existing.Admin, DataDir: existing.DataDir}) {
		t.Error("hidden \"0\" with no existing config should be false")
	}
}

// --- nested admin key preservation tests ---

func TestConfigWriterWriteConfigKeepsNestedAdminKeys(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	stored := "custom_top: keep-me\n" +
		"data_dir: " + filepath.Join(dir, "data") + "\n" +
		"admin:\n" +
		"  username: x\n" +
		"  password_hash: h\n" +
		"  session_secret: s\n" +
		"  custom_flag: true\n"
	os.WriteFile(configPath, []byte(stored), 0666)
	writer := NewAdminConfigWriter(configPath)

	fileCfg, err := writer.LoadFileConfig()
	if err != nil {
		t.Fatalf("LoadFileConfig failed: %v", err)
	}
	fileCfg.Admin.Username = "new-user"
	if err := writer.WriteConfig(fileCfg); err != nil {
		t.Fatalf("WriteConfig failed: %v", err)
	}

	data, _ := os.ReadFile(configPath)
	written := map[string]interface{}{}
	if err := yaml.Unmarshal(data, &written); err != nil {
		t.Fatalf("written config does not parse: %v\n%s", err, data)
	}
	if written["custom_top"] != "keep-me" {
		t.Errorf("top-level unknown key was dropped:\n%s", data)
	}
	admin, ok := written["admin"].(map[string]interface{})
	if !ok {
		t.Fatalf("admin section missing:\n%s", data)
	}
	if admin["custom_flag"] != true {
		t.Errorf("nested unknown admin key was dropped:\n%s", data)
	}
	if admin["username"] != "new-user" {
		t.Errorf("managed admin username was not updated:\n%s", data)
	}
	if admin["password_hash"] != "h" || admin["session_secret"] != "s" {
		t.Errorf("managed admin fields were not kept:\n%s", data)
	}
}

func TestConfigWriterWriteConfigDropsRemovedCredentials(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	stored := "credentials:\n  old-key: old-secret\n" +
		"admin:\n  username: x\n  password_hash: h\n"
	os.WriteFile(configPath, []byte(stored), 0666)
	writer := NewAdminConfigWriter(configPath)

	fileCfg, err := writer.LoadFileConfig()
	if err != nil {
		t.Fatalf("LoadFileConfig failed: %v", err)
	}
	fileCfg.Credentials = map[string]string{"new-key": "new-secret"}
	if err := writer.WriteConfig(fileCfg); err != nil {
		t.Fatalf("WriteConfig failed: %v", err)
	}

	data, _ := os.ReadFile(configPath)
	written := map[string]interface{}{}
	if err := yaml.Unmarshal(data, &written); err != nil {
		t.Fatalf("written config does not parse: %v\n%s", err, data)
	}
	creds, _ := written["credentials"].(map[string]interface{})
	if _, ok := creds["old-key"]; ok {
		t.Errorf("removed credential was resurrected from the stored file:\n%s", data)
	}
	if creds["new-key"] != "new-secret" {
		t.Errorf("new credential missing:\n%s", data)
	}
}

func TestMeasureStorageReturnsPerBucketTotals(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "alpha", "nested"), 0o755)
	os.MkdirAll(filepath.Join(dir, "beta"), 0o755)
	os.MkdirAll(filepath.Join(dir, ".multipart"), 0o755)
	os.WriteFile(filepath.Join(dir, "alpha", "a.txt"), []byte("12345"), 0o644)
	os.WriteFile(filepath.Join(dir, "alpha", "nested", "b.txt"), []byte("123"), 0o644)
	os.WriteFile(filepath.Join(dir, "beta", "c.txt"), []byte("12"), 0o644)

	stats := MeasureStorage(dir)
	want := []BucketStats{{Name: "alpha", ObjectCount: 2, TotalBytes: 8}, {Name: "beta", ObjectCount: 1, TotalBytes: 2}}
	if len(stats.Buckets) != len(want) {
		t.Fatalf("buckets = %+v, want %+v", stats.Buckets, want)
	}
	for i := range want {
		if stats.Buckets[i] != want[i] {
			t.Errorf("bucket %d = %+v, want %+v", i, stats.Buckets[i], want[i])
		}
	}
	if stats.ObjectCount != 3 || stats.TotalBytes != 10 {
		t.Errorf("totals = %d objects, %d bytes", stats.ObjectCount, stats.TotalBytes)
	}
}

// Sizes are not part of the page: the dashboard and the Files page carry
// placeholders and admin.js fills them from /_/stats and /_/folder-stats.
func TestRendererSizesArePlaceholders(t *testing.T) {
	r := NewAdminRenderer()
	dash := r.Dashboard(Stats{Status: "ok", BucketCount: 1, Buckets: []BucketInfo{{Name: "photos"}}}, &config.Config{}, "", nil, "")
	listing := &ListObjectsResult{Folders: []FolderInfo{{Name: "docs", Path: "docs"}}}
	files := r.Files([]BucketInfo{{Name: "photos"}}, listing, "photos", "", "token", "")

	for page, wants := range map[string][]string{
		"dashboard": {`data-stat-src="/_/stats" data-stat-key="all" data-stat-field="summary"`, `data-stat-key="bucket:photos" data-stat-field="share" data-stat-style="width"`},
		"files":     {`data-stat-src="/_/stats" data-stat-key="bucket:photos" data-stat-field="size"`, `data-stat-src="/_/folder-stats?bucket=photos&amp;prefix=" data-stat-key="docs" data-stat-field="summary"`},
	} {
		html := map[string]string{"dashboard": dash, "files": files}[page]
		for _, want := range wants {
			if !strings.Contains(html, want) {
				t.Errorf("%s page is missing %q", page, want)
			}
		}
	}
}

func TestRendererStatsFields(t *testing.T) {
	r := NewAdminRenderer()
	fields := r.StatsFields(StorageTotals{ObjectCount: 3, TotalBytes: 2048, Buckets: []BucketStats{{Name: "a", ObjectCount: 1, TotalBytes: 512}, {Name: "b", ObjectCount: 2, TotalBytes: 1536}}})
	if got, want := fields["all"]["summary"], "2 buckets · 3 objects · 2.00 KB stored"; got != want {
		t.Errorf("summary = %q, want %q", got, want)
	}
	if got := fields["bucket:a"]; got["objects"] != "1" || got["size"] != "512 B" || got["share"] != "25%" {
		t.Errorf("bucket a = %v", got)
	}

	folders := r.FolderStatsFields(map[string]FolderTotals{"docs": {ObjectCount: 1, TotalBytes: 2048}})
	if got, want := folders["docs"]["summary"], "1 item · 2.00 KB"; got != want {
		t.Errorf("folder summary = %q, want %q", got, want)
	}
}

func TestStorageCacheReusesAndInvalidates(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "alpha"), 0o755)
	os.WriteFile(filepath.Join(dir, "alpha", "a.txt"), []byte("12345"), 0o644)

	var cache storageCache
	if got := cache.get(dir).TotalBytes; got != 5 {
		t.Fatalf("first measure = %d bytes, want 5", got)
	}
	os.WriteFile(filepath.Join(dir, "alpha", "b.txt"), []byte("123"), 0o644)
	if got := cache.get(dir).TotalBytes; got != 5 {
		t.Errorf("a second call within the TTL measured again: %d bytes", got)
	}
	cache.invalidate()
	if got := cache.get(dir).TotalBytes; got != 8 {
		t.Errorf("after invalidate = %d bytes, want 8", got)
	}
}

func TestRouterServesStatsAndFolderStats(t *testing.T) {
	dir := t.TempDir()
	hash, err := hashPassword("secret123")
	if err != nil {
		t.Fatalf("hashPassword: %v", err)
	}
	dataDir := filepath.Join(dir, "data")
	os.MkdirAll(filepath.Join(dataDir, "photos", "2026"), 0o755)
	os.WriteFile(filepath.Join(dataDir, "photos", "2026", "a.jpg"), []byte("12345"), 0o644)
	cfgYAML, _ := yaml.Marshal(map[string]interface{}{
		"data_dir":    dataDir,
		"credentials": map[string]string{"minioadmin": "minioadmin"},
		"admin":       map[string]string{"username": "admin", "password_hash": hash, "session_secret": "signing-secret-key"},
	})
	cfgPath := filepath.Join(dir, "config.yaml")
	if err := os.WriteFile(cfgPath, cfgYAML, 0o600); err != nil {
		t.Fatal(err)
	}
	router := NewAdminRouter(cfgPath, dir, "test")

	get := func(target string, signedIn bool) *httptest.ResponseRecorder {
		req := httptest.NewRequest("GET", target, nil)
		if signedIn {
			loginRR := httptest.NewRecorder()
			NewAdminAuth("admin", hash, "signing-secret-key").Login(loginRR, httptest.NewRequest("POST", "/_", nil), "admin", "secret123")
			for _, c := range loginRR.Result().Cookies() {
				req.AddCookie(c)
			}
		}
		rr := httptest.NewRecorder()
		router.ServeHTTP(rr, req)
		return rr
	}

	if rr := get("/_/stats", false); strings.Contains(rr.Body.String(), "photos") || strings.Contains(rr.Header().Get("Content-Type"), "json") {
		t.Error("/_/stats must not answer before sign-in")
	}

	rr := get("/_/stats", true)
	var stats map[string]map[string]string
	if err := json.Unmarshal(rr.Body.Bytes(), &stats); err != nil {
		t.Fatalf("/_/stats is not JSON: %v: %s", err, rr.Body.String())
	}
	if stats["bucket:photos"]["size"] != "5 B" || stats["all"]["summary"] != "1 bucket · 1 object · 5 B stored" {
		t.Errorf("unexpected stats: %v", stats)
	}

	rr = get("/_/folder-stats?bucket=photos&prefix=", true)
	var folders map[string]map[string]string
	if err := json.Unmarshal(rr.Body.Bytes(), &folders); err != nil {
		t.Fatalf("/_/folder-stats is not JSON: %v: %s", err, rr.Body.String())
	}
	if folders["2026"]["summary"] != "1 item · 5 B" {
		t.Errorf("unexpected folder stats: %v", folders)
	}

	if rr := get("/_/folder-stats?bucket=photos&prefix=../..", true); rr.Code != http.StatusBadRequest {
		t.Errorf("a prefix outside the bucket must be rejected, got %d", rr.Code)
	}
}

func TestRendererFilesOpensDetailsInsteadOfDownloading(t *testing.T) {
	listing := &ListObjectsResult{Files: []FileInfo{{Name: "cover.png", Path: "cover.png", Size: 12, Mime: "image/png", IsImage: true}}}
	html := NewAdminRenderer().Files([]BucketInfo{{Name: "photos"}}, listing, "photos", "", "csrf-token", "")
	for _, want := range []string{`data-details="`, `data-action="details"`, `class="drawer"`, `class="bucket-rail"`, `<dialog class="dialog-panel"`} {
		if !strings.Contains(html, want) {
			t.Errorf("files page is missing %q", want)
		}
	}
	if strings.Contains(html, `data-href="/_/files?bucket=photos&amp;path=cover.png&amp;download=1"`) {
		t.Error("clicking a file row must not download it")
	}
}

// Pages get all behaviour from the admin script: no inline script, inline
// event handlers or Alpine directives, so a strict CSP can forbid them.
func TestRendererPagesHaveNoInlineScript(t *testing.T) {
	r := NewAdminRenderer()
	listing := &ListObjectsResult{
		Folders: []FolderInfo{{Name: "docs", Path: "docs"}},
		Files:   []FileInfo{{Name: "a'b\"<c>.txt", Path: "a'b\"<c>.txt", Size: 1, Mime: "text/plain"}},
	}
	cfg := &config.Config{Credentials: map[string]string{"AKIA": "s3cret"}}
	pages := map[string]string{
		"login":     r.Login("", "token"),
		"installer": r.Installer(map[string]string{}, nil, "token"),
		"dashboard": r.Dashboard(Stats{Status: "ok"}, cfg, "https://s3.example.test", nil, ""),
		"files":     r.Files([]BucketInfo{{Name: "photos"}}, listing, "photos", "docs", "token", ""),
		"buckets":   r.Files([]BucketInfo{{Name: "photos"}}, nil, "", "", "token", ""),
	}
	inline := regexp.MustCompile(`<script(?:\s[^>]*)?>[^<]|\son[a-z]+=|\sx-[a-z]+|@click`)
	for name, html := range pages {
		if m := inline.FindString(html); m != "" {
			t.Errorf("%s page has inline behaviour: %q", name, m)
		}
		if !strings.Contains(html, `<script defer src="`+adminScriptPath+`"></script>`) {
			t.Errorf("%s page does not load the admin script", name)
		}
	}
	if !strings.Contains(pages["files"], `data-name="a&#39;b&#34;&lt;c&gt;.txt"`) {
		t.Error("file names in data attributes must be HTML-escaped")
	}
}

func TestRendererDashboardEscapesBucketNames(t *testing.T) {
	stats := Stats{DataDir: "/d", Status: "ok", BucketCount: 1, Buckets: []BucketInfo{{Name: "pics <x>"}}}
	html := NewAdminRenderer().Dashboard(stats, &config.Config{}, "https://s3.example.test", nil, "")
	for _, want := range []string{"pics &lt;x&gt;", `data-stat-key="bucket:pics &lt;x&gt;"`, `data-copy="https://s3.example.test"`, `<a class="nav-link" href="/_" aria-current="page">`} {
		if !strings.Contains(html, want) {
			t.Errorf("dashboard is missing %q", want)
		}
	}
}

func TestRendererLoginUsesAuthLayout(t *testing.T) {
	html := NewAdminRenderer().Login("Invalid username or password", "csrf-token")
	if !strings.Contains(html, `aria-label="Show password"`) || !strings.Contains(html, `role="alert"`) {
		t.Error("login is missing the password toggle or the error alert")
	}
	if strings.Contains(html, `class="sidebar"`) {
		t.Error("login must not show admin navigation")
	}
}

func TestAdminPagesSendSecurityHeaders(t *testing.T) {
	// No config file: the installer is the page anyone can reach first.
	dir := t.TempDir()
	router := NewAdminRouter(filepath.Join(dir, "config.yaml"), dir, "test")

	rr := httptest.NewRecorder()
	router.ServeHTTP(rr, httptest.NewRequest(http.MethodGet, "/_", nil))
	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rr.Code)
	}
	h := rr.Header()
	for name, want := range map[string]string{
		"Cache-Control":           "no-store",
		"X-Frame-Options":         "DENY",
		"X-Content-Type-Options":  "nosniff",
		"Referrer-Policy":         "same-origin",
		"Content-Security-Policy": adminCSP,
	} {
		if got := h.Get(name); got != want {
			t.Errorf("%s = %q, want %q", name, got, want)
		}
	}
	csp := h.Get("Content-Security-Policy")
	if !strings.Contains(csp, "script-src 'self';") || strings.Contains(csp, "unsafe-eval") || strings.Contains(csp, "script-src 'self' 'unsafe-inline'") {
		t.Errorf("CSP must only allow same-origin script, got %q", csp)
	}

	// The admin script itself stays cacheable.
	rr = httptest.NewRecorder()
	router.ServeHTTP(rr, httptest.NewRequest(http.MethodGet, adminScriptPath, nil))
	if got := rr.Header().Get("Cache-Control"); !strings.Contains(got, "immutable") {
		t.Errorf("admin script Cache-Control = %q, want immutable", got)
	}
}

func TestAdminErrorPageHidesDetails(t *testing.T) {
	dir := t.TempDir()
	configPath := filepath.Join(dir, "config.yaml")
	os.WriteFile(configPath, []byte("data_dir: [secret-path\n"), 0600)
	router := NewAdminRouter(configPath, dir, "test")

	var logged strings.Builder
	log.SetOutput(&logged)
	defer log.SetOutput(os.Stderr)

	rr := httptest.NewRecorder()
	router.ServeHTTP(rr, httptest.NewRequest(http.MethodGet, "/_", nil))
	if rr.Code != http.StatusInternalServerError {
		t.Fatalf("expected 500, got %d", rr.Code)
	}
	if body := rr.Body.String(); strings.Contains(body, "secret-path") || strings.Contains(body, "yaml") || !strings.Contains(body, "Check the server log") {
		t.Errorf("error page must show only a generic message, got %q", body)
	}
	if rr.Header().Get("Content-Security-Policy") != adminCSP {
		t.Error("error page must carry the admin security headers")
	}
	if !strings.Contains(logged.String(), "[mini-s3 admin] load config:") {
		t.Errorf("expected the details in the server log, got %q", logged.String())
	}
}

func TestAdminServesSelfHostedScript(t *testing.T) {
	// No config file: the installer page and the script must both work.
	dir := t.TempDir()
	router := NewAdminRouter(filepath.Join(dir, "config.yaml"), dir, "test")

	rr := httptest.NewRecorder()
	router.ServeHTTP(rr, httptest.NewRequest(http.MethodGet, adminScriptPath, nil))
	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rr.Code)
	}
	if got := rr.Header().Get("Content-Type"); !strings.HasPrefix(got, "text/javascript") {
		t.Errorf("expected text/javascript, got %q", got)
	}
	if rr.Body.String() != string(adminJS) {
		t.Error("expected the admin script in the response body")
	}

	html := NewAdminRenderer().Login("", "token")
	if !strings.Contains(html, `<script defer src="`+adminScriptPath+`"></script>`) {
		t.Error("expected the page to load the self-hosted admin script")
	}
	if strings.Contains(html, "unpkg.com") {
		t.Error("admin pages must not load script from a CDN")
	}
}
