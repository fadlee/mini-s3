package admin

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"
)

const (
	repoOwner       = "fadlee"
	repoName        = "mini-s3"
	dockerImage     = "ghcr.io/fadlee/mini-s3" // image tag = release tag without "go-v"
	cacheTTLSeconds = 21600                    // 6 hours
	apiTimeout      = 5 * time.Second
	releasesPerPage = 20
)

// AdminUpgradeService checks GitHub for newer Go releases and reports them to
// the admin dashboard. It never replaces the running binary: upgrades are done
// by pulling a new Docker image (or downloading a new binary by hand).
type AdminUpgradeService struct {
	dataDir     string
	githubToken string
	apiClient   *http.Client

	// Seam for tests; the constructor wires it to the real GitHub call.
	fetchReleases func() ([]githubRelease, error)
}

// NewAdminUpgradeService creates an update-check service. dataDir holds the
// status cache.
func NewAdminUpgradeService(dataDir, githubToken string) *AdminUpgradeService {
	s := &AdminUpgradeService{
		dataDir:     dataDir,
		githubToken: githubToken,
		apiClient:   &http.Client{Timeout: apiTimeout},
	}
	s.fetchReleases = s.listReleases
	return s
}

// githubRelease represents the subset of a GitHub release object that the
// update check cares about.
type githubRelease struct {
	TagName    string `json:"tag_name"`
	HTMLURL    string `json:"html_url"`
	Draft      bool   `json:"draft"`
	Prerelease bool   `json:"prerelease"`
}

// -----------------------------------------------------------------------------
// Public API
// -----------------------------------------------------------------------------

// CachedStatus returns a cached update status if fresh and matching the
// current version. When forceRefresh is true, or the cache is stale/missing,
// a fresh check is performed and cached. If the fresh check errors and a
// valid cache still exists for the current version, the cached value is
// returned instead (graceful degradation). Dev builds are always reported as
// "unavailable" without contacting GitHub.
func (s *AdminUpgradeService) CachedStatus(currentVersion string, forceRefresh bool) (map[string]interface{}, error) {
	if _, ok := releaseBuildVersion(currentVersion); !ok {
		return unavailableStatus(currentVersion), nil
	}

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

// CheckLatest fetches the GitHub releases and compares the highest published
// go-vX.Y.Z release against currentVersion. Returns a map with state, message,
// currentVersion and latestVersion keys. When an update is available it also
// contains releaseUrl and imageTag.
func (s *AdminUpgradeService) CheckLatest(currentVersion string) map[string]interface{} {
	current, ok := releaseBuildVersion(currentVersion)
	if !ok {
		return unavailableStatus(currentVersion)
	}

	releases, err := s.fetchReleases()
	if err != nil {
		return map[string]interface{}{
			"state":          "error",
			"message":        "Unable to check GitHub releases: " + err.Error(),
			"currentVersion": currentVersion,
			"latestVersion":  nil,
		}
	}

	latest, found := latestGoRelease(releases)
	if !found {
		return map[string]interface{}{
			"state":          "error",
			"message":        "No GitHub release with a valid go-vX.Y.Z tag was found.",
			"currentVersion": currentVersion,
			"latestVersion":  nil,
		}
	}

	imageTag := normalizeVersion(latest.TagName)
	if versionCompare(current, imageTag) >= 0 {
		return map[string]interface{}{
			"state":          "up_to_date",
			"message":        "Mini S3 is up to date.",
			"currentVersion": currentVersion,
			"latestVersion":  latest.TagName,
		}
	}

	releaseURL := latest.HTMLURL
	if releaseURL == "" {
		releaseURL = "https://github.com/" + repoOwner + "/" + repoName + "/releases/tag/" + latest.TagName
	}
	return map[string]interface{}{
		"state":          "update_available",
		"message":        "Update available: " + latest.TagName,
		"currentVersion": currentVersion,
		"latestVersion":  latest.TagName,
		"releaseUrl":     releaseURL,
		"imageTag":       imageTag,
	}
}

// -----------------------------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------------------------

// goReleaseTagRegex matches the release tags of the Go build (go-vX.Y.Z).
var goReleaseTagRegex = regexp.MustCompile(`^go-v\d+\.\d+\.\d+$`)

// semverRegex matches a normalized version such as "1.2.3".
var semverRegex = regexp.MustCompile(`^\d+\.\d+\.\d+$`)

// unavailableStatus is returned when update checks do not apply, such as for
// dev builds that were not produced with -ldflags.
func unavailableStatus(currentVersion string) map[string]interface{} {
	return map[string]interface{}{
		"state":          "unavailable",
		"message":        "Update checks are only available for release builds.",
		"currentVersion": currentVersion,
		"latestVersion":  nil,
	}
}

// normalizeVersion strips the "go-" and "v" prefixes so that "go-v1.2.0",
// "v1.2.0" and "1.2.0" all become "1.2.0".
func normalizeVersion(version string) string {
	v := strings.TrimPrefix(strings.TrimSpace(version), "go-")
	return strings.TrimLeft(v, "vV")
}

// releaseBuildVersion returns the normalized running version and whether it is
// a real release version (plain X.Y.Z). Dev builds and empty versions return
// false.
func releaseBuildVersion(version string) (string, bool) {
	v := normalizeVersion(version)
	return v, semverRegex.MatchString(v)
}

// latestGoRelease returns the non-draft, non-prerelease Go release with the
// highest semantic version. List order is ignored.
func latestGoRelease(releases []githubRelease) (githubRelease, bool) {
	var latest githubRelease
	found := false
	for _, rel := range releases {
		if rel.Draft || rel.Prerelease || !goReleaseTagRegex.MatchString(rel.TagName) {
			continue
		}
		if !found || versionCompare(normalizeVersion(rel.TagName), normalizeVersion(latest.TagName)) > 0 {
			latest = rel
			found = true
		}
	}
	return latest, found
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

// listReleases calls the GitHub releases endpoint and returns the most recent
// releases. Tags are filtered by the caller.
func (s *AdminUpgradeService) listReleases() ([]githubRelease, error) {
	url := fmt.Sprintf("https://api.github.com/repos/%s/%s/releases?per_page=%d", repoOwner, repoName, releasesPerPage)

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

	var releases []githubRelease
	if err := json.Unmarshal(body, &releases); err != nil {
		return nil, fmt.Errorf("invalid JSON response")
	}
	return releases, nil
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
