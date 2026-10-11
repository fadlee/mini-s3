package admin

import (
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	"github.com/fadlee/mini-s3/internal/config"
	"gopkg.in/yaml.v3"
)

// AdminConfigWriter builds and writes config.yaml, mirroring
// MiniS3\Admin\AdminConfigWriter but targeting YAML instead of PHP arrays.
type AdminConfigWriter struct {
	configPath string
}

// NewAdminConfigWriter creates a writer for the given config file path.
func NewAdminConfigWriter(configPath string) *AdminConfigWriter {
	return &AdminConfigWriter{configPath: configPath}
}

// ConfigInput holds the form data from the installer/config edit page.
type ConfigInput struct {
	DataDir                     string
	AccessKey                   string
	SecretKey                   string
	AdminUsername               string
	AdminPassword               string
	AdminPasswordConfirm        string
	MaxRequestSize              string
	ClockSkewSeconds            string
	MaxPresignExpires           string
	AuthDebugLog                string
	AllowHostCandidateFallbacks string
	PublicReadAllBuckets        string
}

// BuildConfig creates a Config from the input, merging with existing config.
func (w *AdminConfigWriter) BuildConfig(input ConfigInput, existing *config.Config) (*config.Config, error) {
	dataDir := strings.TrimSpace(input.DataDir)
	if dataDir == "" {
		return nil, fmt.Errorf("data directory is required")
	}

	accessKey := strings.TrimSpace(input.AccessKey)
	if accessKey == "" {
		return nil, fmt.Errorf("access key is required")
	}

	secretKey := input.SecretKey
	if secretKey == "" {
		return nil, fmt.Errorf("secret key is required")
	}

	adminUsername := strings.TrimSpace(input.AdminUsername)
	if adminUsername == "" {
		if existing != nil {
			adminUsername = existing.Admin.Username
		} else {
			adminUsername = "admin"
		}
	}
	if adminUsername == "" {
		return nil, fmt.Errorf("admin username is required")
	}

	adminPasswordHash := ""
	if existing != nil {
		adminPasswordHash = strings.TrimSpace(existing.Admin.PasswordHash)
	}

	if input.AdminPassword != "" || input.AdminPasswordConfirm != "" || adminPasswordHash == "" {
		if input.AdminPassword == "" || input.AdminPassword != input.AdminPasswordConfirm {
			return nil, fmt.Errorf("admin passwords must match")
		}
		h, err := hashPassword(input.AdminPassword)
		if err != nil {
			return nil, fmt.Errorf("failed to hash password: %w", err)
		}
		adminPasswordHash = h
	}

	maxRequestSize := positiveInt64(input.MaxRequestSize, 100*1024*1024, "Max request size")
	clockSkewSeconds := positiveInt(input.ClockSkewSeconds, 900, "Clock skew seconds")
	maxPresignExpires := positiveInt(input.MaxPresignExpires, 604800, "Max presign expires")

	// Start from existing config so fields this form does not manage (GitHub
	// token, legacy access keys, session secret, ...) are kept.
	updated := config.Defaults()
	if existing != nil {
		updated = cloneConfig(existing)
	}
	publicReadAllBuckets := checkboxDefault(input.PublicReadAllBuckets, existing == nil || existing.PublicReadAllBuckets)

	// Generate a session secret if none exists.
	sessionSecret := updated.Admin.SessionSecret
	if sessionSecret == "" {
		sessionSecret = randomToken(32)
	}

	updated.DataDir = dataDir
	updated.MaxRequestSize = maxRequestSize
	updated.Credentials = mergeCredentials(accessKey, secretKey, updated.Credentials)
	updated.ClockSkewSeconds = clockSkewSeconds
	updated.MaxPresignExpires = maxPresignExpires
	updated.AuthDebugLog = strings.TrimSpace(input.AuthDebugLog)
	updated.AllowHostCandidateFallbacks = checkbox(input.AllowHostCandidateFallbacks)
	updated.PublicReadAllBuckets = publicReadAllBuckets
	updated.Admin.Username = adminUsername
	updated.Admin.PasswordHash = adminPasswordHash
	updated.Admin.SessionSecret = sessionSecret
	return updated, nil
}

// mergeCredentials returns the credential set after saving the form's key
// pair. The form pair replaces the first existing credential (the one the
// config page pre-fills), other existing credentials are kept, and a form key
// that already exists takes the form's secret.
func mergeCredentials(accessKey, secretKey string, existing map[string]string) map[string]string {
	creds := map[string]string{accessKey: secretKey}
	first, _, hasFirst := firstCredential(existing)
	for k, v := range existing {
		if (hasFirst && k == first) || k == accessKey {
			continue
		}
		creds[k] = v
	}
	return creds
}

// firstCredential returns the credential the config page pre-fills. Go maps
// are unordered, so the lowest access key is used to keep the choice stable.
func firstCredential(creds map[string]string) (string, string, bool) {
	if len(creds) == 0 {
		return "", "", false
	}
	keys := make([]string, 0, len(creds))
	for k := range creds {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	return keys[0], creds[keys[0]], true
}

// cloneConfig copies cfg so edits do not alias its maps or slices.
func cloneConfig(cfg *config.Config) *config.Config {
	dst := *cfg
	dst.Credentials = make(map[string]string, len(cfg.Credentials))
	for k, v := range cfg.Credentials {
		dst.Credentials[k] = v
	}
	dst.AllowedAccessKeys = append([]string{}, cfg.AllowedAccessKeys...)
	return &dst
}

// LoadFileConfig reads config.yaml as stored on disk, without environment
// overrides or normalization. It returns nil when the file does not exist.
func (w *AdminConfigWriter) LoadFileConfig() (*config.Config, error) {
	data, err := os.ReadFile(w.configPath)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, fmt.Errorf("config file cannot be read: %w", err)
	}
	cfg := config.Defaults()
	if err := yaml.Unmarshal(data, cfg); err != nil {
		return nil, fmt.Errorf("config file cannot be parsed: %w", err)
	}
	return cfg, nil
}

// WriteInstallerConfig writes the config for the first-time installer.
// Fails if the config file already exists.
func (w *AdminConfigWriter) WriteInstallerConfig(cfg *config.Config) error {
	if _, err := os.Stat(w.configPath); err == nil {
		return fmt.Errorf("config file already exists; log in instead")
	}

	if err := ensureWritableDataDir(cfg.DataDir); err != nil {
		return err
	}
	return w.WriteConfig(cfg)
}

// WriteConfig writes the config to disk atomically (temp + rename).
func (w *AdminConfigWriter) WriteConfig(cfg *config.Config) error {
	configDir := filepath.Dir(w.configPath)
	if err := os.MkdirAll(configDir, 0777); err != nil {
		return fmt.Errorf("config directory cannot be created: %w", err)
	}

	data, err := yaml.Marshal(cfg)
	if err != nil {
		return fmt.Errorf("failed to marshal config: %w", err)
	}
	data, err = w.keepUnknownKeys(data)
	if err != nil {
		return err
	}

	tmpPath := w.configPath + ".tmp." + randomToken(4)
	if err := os.WriteFile(tmpPath, data, 0666); err != nil {
		return fmt.Errorf("config file cannot be written: %w", err)
	}

	if err := os.Rename(tmpPath, w.configPath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("config file cannot be saved: %w", err)
	}
	return nil
}

// keepUnknownKeys merges the typed config over the keys already in the file,
// so top-level keys this binary does not model are not dropped on save.
func (w *AdminConfigWriter) keepUnknownKeys(managed []byte) ([]byte, error) {
	existing, err := os.ReadFile(w.configPath)
	if err != nil {
		if os.IsNotExist(err) {
			return managed, nil
		}
		return nil, fmt.Errorf("config file cannot be read: %w", err)
	}
	stored := map[string]interface{}{}
	if err := yaml.Unmarshal(existing, &stored); err != nil {
		return nil, fmt.Errorf("config file cannot be parsed: %w", err)
	}
	typed := map[string]interface{}{}
	if err := yaml.Unmarshal(managed, &typed); err != nil {
		return nil, fmt.Errorf("failed to marshal config: %w", err)
	}
	merged, err := yaml.Marshal(mergeYAMLMaps(stored, typed))
	if err != nil {
		return nil, fmt.Errorf("failed to marshal config: %w", err)
	}
	return merged, nil
}

// mergeYAMLMaps overlays typed onto stored key by key; typed wins. The admin
// section is merged one level deep so unmodeled keys under admin survive.
// Other maps (notably credentials) are replaced wholesale so removed entries
// stay removed.
func mergeYAMLMaps(stored, typed map[string]interface{}) map[string]interface{} {
	for k, v := range typed {
		storedMap, storedIsMap := stored[k].(map[string]interface{})
		typedMap, typedIsMap := v.(map[string]interface{})
		if k == "admin" && storedIsMap && typedIsMap {
			for nk, nv := range typedMap {
				storedMap[nk] = nv
			}
			continue
		}
		stored[k] = v
	}
	return stored
}

// BuildAdminAccountConfig updates the admin username and optionally password.
func (w *AdminConfigWriter) BuildAdminAccountConfig(input ConfigInput, existing *config.Config) (*config.Config, error) {
	if existing == nil || strings.TrimSpace(existing.Admin.PasswordHash) == "" {
		return nil, fmt.Errorf("admin password is not configured")
	}
	username := strings.TrimSpace(input.AdminUsername)
	if username == "" {
		return nil, fmt.Errorf("admin username is required")
	}
	updated := *existing
	updated.Admin = existing.Admin
	updated.Admin.Username = username
	if input.AdminPassword != "" || input.AdminPasswordConfirm != "" {
		if input.AdminPassword == "" || input.AdminPassword != input.AdminPasswordConfirm {
			return nil, fmt.Errorf("new admin passwords must match")
		}
		h, err := hashPassword(input.AdminPassword)
		if err != nil {
			return nil, fmt.Errorf("failed to hash password: %w", err)
		}
		updated.Admin.PasswordHash = h
	}
	return &updated, nil
}

// ConfigPath returns the path to the config file.
func (w *AdminConfigWriter) ConfigPath() string {
	return w.configPath
}

func ensureWritableDataDir(dataDir string) error {
	if err := os.MkdirAll(dataDir, 0777); err != nil {
		return fmt.Errorf("data directory cannot be created: %w", err)
	}
	tmpFile := filepath.Join(dataDir, ".mini-s3-writable-check")
	f, err := os.Create(tmpFile)
	if err != nil {
		return fmt.Errorf("data directory must be readable and writable")
	}
	f.Close()
	os.Remove(tmpFile)
	return nil
}

func positiveInt(value string, defaultVal int, label string) int {
	if value == "" {
		return defaultVal
	}
	n, err := strconv.Atoi(value)
	if err != nil || n < 1 {
		return defaultVal
	}
	return n
}

func positiveInt64(value string, defaultVal int64, label string) int64 {
	if value == "" {
		return defaultVal
	}
	n, err := strconv.ParseInt(value, 10, 64)
	if err != nil || n < 1 {
		return defaultVal
	}
	return n
}

func checkbox(value string) bool {
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "on", "yes":
		return true
	default:
		return false
	}
}

func checkboxDefault(value string, defaultVal bool) bool {
	if value == "" {
		return defaultVal
	}
	return checkbox(value)
}
