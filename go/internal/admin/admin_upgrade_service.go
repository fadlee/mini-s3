package admin

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

const (
	repoOwner       = "fadlee"
	repoName        = "mini-s3"
	maxBinaryBytes  = 100 * 1024 * 1024 // 100 MB — sanity cap on downloaded binary
	cacheTTLSeconds = 21600             // 6 hours
	downloadTimeout = 30 * time.Second
	apiTimeout      = 5 * time.Second
	restartDelay    = 1 * time.Second // allow HTTP response to flush before restart
)

// AdminUpgradeService checks GitHub for newer releases and applies binary
// self-upgrades. It mirrors MiniS3\Admin\AdminUpgradeService but replaces the
// running Go binary instead of index.php.
type AdminUpgradeService struct {
	baseDir     string
	dataDir     string
	entryFile   string
	githubToken string
	httpClient  *http.Client // used for large asset downloads
	apiClient   *http.Client // used for short GitHub API calls
}

// NewAdminUpgradeService creates a new upgrade service.
//
// entryFile is the path to the running binary. If empty, os.Executable() is
// used. The path is resolved to an absolute form so that binary swaps work
// regardless of the current working directory.
func NewAdminUpgradeService(baseDir, dataDir, entryFile, githubToken string) *AdminUpgradeService {
	if entryFile == "" {
		if exe, err := os.Executable(); err == nil {
			entryFile = exe
		}
	}
	if abs, err := filepath.Abs(entryFile); err == nil {
		entryFile = abs
	}
	return &AdminUpgradeService{
		baseDir:     baseDir,
		dataDir:     dataDir,
		entryFile:   entryFile,
		githubToken: githubToken,
		httpClient:  &http.Client{Timeout: downloadTimeout},
		apiClient:   &http.Client{Timeout: apiTimeout},
	}
}

// githubReleaseAsset represents a single asset in a GitHub release.
type githubReleaseAsset struct {
	Name               string `json:"name"`
	BrowserDownloadURL string `json:"browser_download_url"`
}

// githubRelease represents the subset of the GitHub releases/latest response
// that the upgrade service cares about.
type githubRelease struct {
	TagName string               `json:"tag_name"`
	Assets  []githubReleaseAsset `json:"assets"`
}

// -----------------------------------------------------------------------------
// Public API — mirrors the PHP public methods
// -----------------------------------------------------------------------------

// Status returns a quick status without contacting GitHub. If currentVersion
// is empty, auto-upgrade is reported as unavailable; otherwise an "unknown"
// state is returned, indicating a full check has not run yet.
func (s *AdminUpgradeService) Status(currentVersion string) (map[string]interface{}, error) {
	if currentVersion == "" {
		return map[string]interface{}{
			"state":          "unavailable",
			"message":        "Auto-upgrade is only available for generated release installs.",
			"currentVersion": nil,
			"latestVersion":  nil,
			"assetUrl":       nil,
		}, nil
	}

	return map[string]interface{}{
		"state":          "unknown",
		"message":        "Update check has not run yet.",
		"currentVersion": currentVersion,
		"latestVersion":  nil,
		"assetUrl":       nil,
	}, nil
}

// CachedStatus returns a cached upgrade status if fresh and matching the
// current version. When forceRefresh is true, or the cache is stale/missing,
// a fresh check is performed and cached. If the fresh check errors and a
// valid cache still exists for the current version, the cached value is
// returned instead (graceful degradation).
func (s *AdminUpgradeService) CachedStatus(currentVersion string, forceRefresh bool) (map[string]interface{}, error) {
	cached := s.readCachedStatus()
	if !forceRefresh && cached != nil && cachedCurrentVersion(cached) == currentVersion {
		return cached, nil
	}

	status := s.CheckLatest(currentVersion)
	if state, ok := status["state"].(string); ok && state == "error" && cached != nil && cachedCurrentVersion(cached) == currentVersion {
		return cached, nil
	}
	s.writeCachedStatus(status)
	return status, nil
}

// CheckLatest fetches the latest release from GitHub and compares it against
// currentVersion. Returns a map with state, message, currentVersion,
// latestVersion, and assetUrl keys.
func (s *AdminUpgradeService) CheckLatest(currentVersion string) map[string]interface{} {
	metadata, err := s.fetchLatestRelease()
	if err != nil {
		return map[string]interface{}{
			"state":          "error",
			"message":        "Unable to check GitHub releases: " + err.Error(),
			"currentVersion": currentVersion,
			"latestVersion":  nil,
			"assetUrl":       nil,
		}
	}

	latestTag := s.ReleaseTag(metadata)
	if latestTag == "" {
		return map[string]interface{}{
			"state":          "error",
			"message":        "Latest GitHub release does not have a valid version tag.",
			"currentVersion": currentVersion,
			"latestVersion":  nil,
			"assetUrl":       nil,
		}
	}

	if s.CompareVersions(currentVersion, latestTag) >= 0 {
		return map[string]interface{}{
			"state":          "up_to_date",
			"message":        "Mini S3 is up to date.",
			"currentVersion": currentVersion,
			"latestVersion":  latestTag,
			"assetUrl":       nil,
		}
	}

	assetURL := s.AssetUrl(metadata, latestTag)
	if assetURL == "" {
		return map[string]interface{}{
			"state":          "error",
			"message":        "Latest GitHub release does not include the expected binary asset.",
			"currentVersion": currentVersion,
			"latestVersion":  latestTag,
			"assetUrl":       nil,
		}
	}

	return map[string]interface{}{
		"state":          "update_available",
		"message":        "Update available: " + latestTag,
		"currentVersion": currentVersion,
		"latestVersion":  latestTag,
		"assetUrl":       assetURL,
	}
}

// Upgrade downloads the new binary from assetURL, verifies its SHA256
// checksum against the release's checksums file, backs up the current binary,
// swaps it into place, clears the status cache, and schedules a process
// restart. Returns a map with ok, message, and backupPath keys.
func (s *AdminUpgradeService) Upgrade(currentVersion, latestVersion, assetURL string) (map[string]interface{}, error) {
	if s.CompareVersions(currentVersion, latestVersion) >= 0 {
		return map[string]interface{}{
			"ok":      false,
			"message": "No newer version is available.",
		}, nil
	}

	// Verify the current binary exists and its directory is writable.
	entryInfo, err := os.Stat(s.entryFile)
	if err != nil || entryInfo.IsDir() {
		return map[string]interface{}{
			"ok":      false,
			"message": "Current binary or its directory is not writable.",
		}, nil
	}
	entryDir := filepath.Dir(s.entryFile)
	if err := s.ensureDirectory(entryDir); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": "Current binary or its directory is not writable.",
		}, nil
	}

	tmpDir := filepath.Join(s.dataDir, ".upgrade-tmp")
	if err := s.ensureDirectory(tmpDir); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": "Temporary upgrade directory cannot be created or written.",
		}, nil
	}

	binaryName := s.expectedAssetName(latestVersion)
	newPath := filepath.Join(tmpDir, binaryName)

	// Always clean up temp files when done.
	defer func() {
		os.Remove(newPath)
	}()

	// 1. Download the binary.
	if err := s.download(assetURL, newPath); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": err.Error(),
		}, nil
	}

	// 2. Verify size.
	info, err := os.Stat(newPath)
	if err != nil || info.Size() == 0 || info.Size() > maxBinaryBytes {
		return map[string]interface{}{
			"ok":      false,
			"message": "Downloaded binary has an invalid size.",
		}, nil
	}

	// 3. Verify SHA256 checksum.
	// Re-fetch release metadata to locate the checksums file.
	metadata, err := s.fetchLatestRelease()
	if err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": "Unable to fetch release metadata for checksum verification: " + err.Error(),
		}, nil
	}
	checksumURL := s.checksumUrl(metadata, latestVersion)
	if checksumURL == "" {
		return map[string]interface{}{
			"ok":      false,
			"message": "Latest GitHub release does not include a checksums file.",
		}, nil
	}
	checksumPath := filepath.Join(tmpDir, "checksums.txt")
	defer os.Remove(checksumPath)
	if err := s.download(checksumURL, checksumPath); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": "Unable to download checksums file: " + err.Error(),
		}, nil
	}
	checksumsContent, err := os.ReadFile(checksumPath)
	if err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": "Unable to read checksums file.",
		}, nil
	}
	expectedChecksum := findChecksum(string(checksumsContent), binaryName)
	if expectedChecksum == "" {
		return map[string]interface{}{
			"ok":      false,
			"message": "Checksums file does not contain an entry for the expected binary.",
		}, nil
	}
	if err := s.ValidateBinaryChecksum(newPath, expectedChecksum); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": err.Error(),
		}, nil
	}

	// 4. Preserve executable mode (non-Windows).
	if runtime.GOOS != "windows" {
		os.Chmod(newPath, entryInfo.Mode())
	}

	// 5. Back up the current binary.
	backupPath, err := s.backupCurrentBinary()
	if err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": err.Error(),
		}, nil
	}

	// 6. Swap the binary into place.
	if err := s.swapBinary(newPath); err != nil {
		return map[string]interface{}{
			"ok":      false,
			"message": err.Error(),
		}, nil
	}

	// 7. Clear the status cache.
	s.clearCachedStatus()

	// 8. Schedule a restart so the new binary takes over. The delay lets the
	// HTTP handler flush the response before the process exits.
	go func() {
		time.Sleep(restartDelay)
		s.Restart()
	}()

	return map[string]interface{}{
		"ok":         true,
		"message":    "Mini S3 upgraded to " + latestVersion + ".",
		"backupPath": backupPath,
	}, nil
}

// CompareVersions compares two version strings (e.g. "v1.2.3" vs "v1.2.4").
// Returns -1, 0, or 1 like PHP's version_compare for simple semver strings.
func (s *AdminUpgradeService) CompareVersions(current, latest string) int {
	return versionCompare(s.normalizeVersion(current), s.normalizeVersion(latest))
}

// ReleaseTag extracts and normalises the tag_name from GitHub release
// metadata. Returns an empty string if the tag does not match v?X.Y.Z.
func (s *AdminUpgradeService) ReleaseTag(metadata *githubRelease) string {
	tag := metadata.TagName
	if !versionTagRegex.MatchString(tag) {
		return ""
	}
	if strings.HasPrefix(tag, "v") {
		return tag
	}
	return "v" + tag
}

// AssetUrl finds the browser_download_url for the platform-specific binary
// asset in the release. Returns an empty string if not found.
func (s *AdminUpgradeService) AssetUrl(metadata *githubRelease, tag string) string {
	expectedName := s.expectedAssetName(tag)
	for _, asset := range metadata.Assets {
		if asset.Name != expectedName {
			continue
		}
		if asset.BrowserDownloadURL != "" {
			return asset.BrowserDownloadURL
		}
	}
	return ""
}

// ValidateBinaryChecksum computes the SHA256 of the file at binaryPath and
// compares it (case-insensitively) against expectedChecksum.
func (s *AdminUpgradeService) ValidateBinaryChecksum(binaryPath, expectedChecksum string) error {
	f, err := os.Open(binaryPath)
	if err != nil {
		return fmt.Errorf("Unable to read downloaded binary for verification.")
	}
	defer f.Close()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return fmt.Errorf("Unable to compute checksum.")
	}
	actual := hex.EncodeToString(h.Sum(nil))
	if !strings.EqualFold(actual, strings.TrimSpace(expectedChecksum)) {
		return fmt.Errorf("Checksum verification failed.")
	}
	return nil
}

// Restart launches a new process with the same arguments and exits the
// current process. It is called automatically after a successful upgrade but
// can also be invoked manually.
func (s *AdminUpgradeService) Restart() {
	exe := s.entryFile
	if exe == "" {
		if e, err := os.Executable(); err == nil {
			exe = e
		}
	}
	cmd := exec.Command(exe, os.Args[1:]...)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Stdin = os.Stdin
	// Detach from the current process group so the new process survives
	// when the current one exits.
	setDetach(cmd)
	if err := cmd.Start(); err != nil {
		return
	}
	os.Exit(0)
}

// -----------------------------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------------------------

var versionTagRegex = regexp.MustCompile(`^v?\d+\.\d+\.\d+$`)

func (s *AdminUpgradeService) normalizeVersion(version string) string {
	return strings.TrimLeft(version, "vV")
}

// versionCompare compares two dot-separated version strings numerically,
// returning -1, 0, or 1. This is a simplified equivalent of PHP's
// version_compare that handles plain semver (no pre-release suffixes).
func versionCompare(a, b string) int {
	partsA := strings.Split(a, ".")
	partsB := strings.Split(b, ".")
	maxLen := len(partsA)
	if len(partsB) > maxLen {
		maxLen = len(partsB)
	}
	for i := 0; i < maxLen; i++ {
		var na, nb int
		if i < len(partsA) {
			na, _ = strconv.Atoi(partsA[i])
		}
		if i < len(partsB) {
			nb, _ = strconv.Atoi(partsB[i])
		}
		if na < nb {
			return -1
		}
		if na > nb {
			return 1
		}
	}
	return 0
}

// expectedAssetName builds the expected binary asset name for the current
// platform, e.g. "mini-s3-v1.2.3-linux-amd64" or
// "mini-s3-v1.2.3-windows-amd64.exe".
func (s *AdminUpgradeService) expectedAssetName(tag string) string {
	name := "mini-s3-" + tag + "-" + runtime.GOOS + "-" + runtime.GOARCH
	if runtime.GOOS == "windows" {
		name += ".exe"
	}
	return name
}

// checksumUrl finds the browser_download_url for the release checksums file.
func (s *AdminUpgradeService) checksumUrl(metadata *githubRelease, tag string) string {
	// Try a few common checksums-file naming conventions.
	candidates := []string{
		"mini-s3-" + tag + "-checksums.txt",
		"mini-s3-" + tag + "-checksums.sha256",
		"checksums.txt",
	}
	for _, asset := range metadata.Assets {
		for _, c := range candidates {
			if asset.Name == c && asset.BrowserDownloadURL != "" {
				return asset.BrowserDownloadURL
			}
		}
	}
	return ""
}

// findChecksum parses a checksums file (one entry per line:
// "<hex-digest>  <filename>") and returns the digest for the given binary
// name. Returns an empty string if not found.
func findChecksum(checksumsContent, binaryName string) string {
	for _, line := range strings.Split(checksumsContent, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		// The digest is the first field; the filename is the last.
		digest := fields[0]
		filename := fields[len(fields)-1]
		if filepath.Base(filename) == binaryName {
			return digest
		}
	}
	return ""
}

// fetchLatestRelease calls the GitHub releases/latest endpoint and returns
// the parsed response.
func (s *AdminUpgradeService) fetchLatestRelease() (*githubRelease, error) {
	url := fmt.Sprintf("https://api.github.com/repos/%s/%s/releases/latest", repoOwner, repoName)

	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return nil, fmt.Errorf("request failed")
	}
	req.Header.Set("User-Agent", "mini-s3-admin-upgrade")
	req.Header.Set("Accept", "application/vnd.github+json")
	if s.githubToken != "" {
		req.Header.Set("Authorization", "Bearer "+s.githubToken)
	}

	resp, err := s.apiClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("%s", s.githubErrorMessage(body, resp.StatusCode))
	}

	var release githubRelease
	if err := json.Unmarshal(body, &release); err != nil {
		return nil, fmt.Errorf("invalid JSON response")
	}
	return &release, nil
}

// githubErrorMessage extracts a human-readable error message from a GitHub
// API error response body. Falls back to "HTTP <status>" if the body does
// not contain a JSON message field.
func (s *AdminUpgradeService) githubErrorMessage(body []byte, statusCode int) string {
	var decoded map[string]interface{}
	if err := json.Unmarshal(body, &decoded); err == nil {
		if msg, ok := decoded["message"].(string); ok {
			msg = strings.TrimSpace(msg)
			if msg != "" {
				return fmt.Sprintf("HTTP %d %s", statusCode, msg)
			}
		}
	}
	return fmt.Sprintf("HTTP %d", statusCode)
}

// download fetches url and streams the response body to destination.
func (s *AdminUpgradeService) download(url, destination string) error {
	req, err := http.NewRequest("GET", url, nil)
	if err != nil {
		return fmt.Errorf("Download failed.")
	}
	req.Header.Set("User-Agent", "mini-s3-admin-upgrade")
	req.Header.Set("Accept", "application/octet-stream")

	resp, err := s.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("Download failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("Download failed: HTTP %d", resp.StatusCode)
	}

	out, err := os.Create(destination)
	if err != nil {
		return fmt.Errorf("Unable to save downloaded binary.")
	}
	defer out.Close()

	if _, err := io.Copy(out, resp.Body); err != nil {
		return fmt.Errorf("Download failed: %w", err)
	}
	return nil
}

// backupCurrentBinary copies the running binary to a timestamped backup
// directory under dataDir/.upgrade-backups/.
func (s *AdminUpgradeService) backupCurrentBinary() (string, error) {
	backupDir := filepath.Join(
		s.dataDir, ".upgrade-backups",
		time.Now().UTC().Format("20060102-150405")+"-"+randomToken(3),
	)
	if err := os.MkdirAll(backupDir, 0777); err != nil {
		return "", fmt.Errorf("Backup directory is not writable.")
	}
	backupPath := filepath.Join(backupDir, filepath.Base(s.entryFile))
	if err := copyFile(s.entryFile, backupPath); err != nil {
		return "", fmt.Errorf("Unable to back up current binary.")
	}
	return backupPath, nil
}

// swapBinary replaces the running binary at s.entryFile with the file at
// newPath. On Windows the running executable cannot be overwritten, so the
// current binary is renamed to ".old" first and the new binary is moved into
// its place. The ".old" file is removed if possible (it may remain locked
// until the process exits).
func (s *AdminUpgradeService) swapBinary(newPath string) error {
	if runtime.GOOS == "windows" {
		oldPath := s.entryFile + ".old"
		os.Remove(oldPath) // remove any stale .old file
		if err := os.Rename(s.entryFile, oldPath); err != nil {
			return fmt.Errorf("Unable to stage new binary: current binary cannot be renamed.")
		}
		if err := os.Rename(newPath, s.entryFile); err != nil {
			// Rollback: restore the old binary.
			os.Rename(oldPath, s.entryFile)
			return fmt.Errorf("Upgrade failed and rollback succeeded.")
		}
		// Best-effort cleanup; the file may still be locked.
		os.Remove(oldPath)
		return nil
	}

	// On Unix, rename is atomic and works even while the binary is running.
	if err := os.Rename(newPath, s.entryFile); err != nil {
		return fmt.Errorf("Unable to replace current binary: %w", err)
	}
	return nil
}

// ensureDirectory creates path (if missing) and verifies it is writable.
func (s *AdminUpgradeService) ensureDirectory(path string) error {
	if err := os.MkdirAll(path, 0777); err != nil {
		return err
	}
	tmpFile := filepath.Join(path, ".mini-s3-writable-check")
	f, err := os.Create(tmpFile)
	if err != nil {
		return err
	}
	f.Close()
	os.Remove(tmpFile)
	return nil
}

// -----------------------------------------------------------------------------
// Cache helpers
// -----------------------------------------------------------------------------

func (s *AdminUpgradeService) cachePath() string {
	return filepath.Join(s.dataDir, ".upgrade-cache", "latest.json")
}

// readCachedStatus reads and validates the cached status from disk. Returns
// nil if the file is missing, corrupt, or stale.
func (s *AdminUpgradeService) readCachedStatus() map[string]interface{} {
	path := s.cachePath()
	data, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	var decoded struct {
		CachedAt int64                  `json:"cachedAt"`
		Status   map[string]interface{} `json:"status"`
	}
	if err := json.Unmarshal(data, &decoded); err != nil {
		return nil
	}
	if decoded.CachedAt < time.Now().Unix()-cacheTTLSeconds {
		return nil
	}
	return decoded.Status
}

// writeCachedStatus persists status to disk as JSON with a timestamp.
func (s *AdminUpgradeService) writeCachedStatus(status map[string]interface{}) {
	path := s.cachePath()
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0777); err != nil {
		return
	}
	data, err := json.Marshal(map[string]interface{}{
		"cachedAt": time.Now().Unix(),
		"status":   status,
	})
	if err != nil {
		return
	}
	os.WriteFile(path, data, 0666)
}

// clearCachedStatus removes the cache file.
func (s *AdminUpgradeService) clearCachedStatus() {
	os.Remove(s.cachePath())
}

// cachedCurrentVersion extracts the currentVersion string from a cached
// status map, returning "" if absent or not a string.
func cachedCurrentVersion(cached map[string]interface{}) string {
	if cached == nil {
		return ""
	}
	if cv, ok := cached["currentVersion"].(string); ok {
		return cv
	}
	return ""
}

// -----------------------------------------------------------------------------
// File helpers
// -----------------------------------------------------------------------------

// copyFile copies the contents and mode of src to dst.
func copyFile(src, dst string) error {
	srcFile, err := os.Open(src)
	if err != nil {
		return err
	}
	defer srcFile.Close()

	info, err := srcFile.Stat()
	if err != nil {
		return err
	}

	dstFile, err := os.OpenFile(dst, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, info.Mode())
	if err != nil {
		return err
	}
	defer dstFile.Close()

	_, err = io.Copy(dstFile, srcFile)
	return err
}
