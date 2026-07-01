package config

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"gopkg.in/yaml.v3"
)

// Config holds all runtime configuration for mini-s3.
type Config struct {
	DataDir                     string            `yaml:"data_dir"`
	MaxRequestSize              int64             `yaml:"max_request_size"`
	Credentials                 map[string]string `yaml:"credentials"`
	AllowedAccessKeys           []string          `yaml:"allowed_access_keys"`
	AllowLegacyAccessKeyOnly    bool              `yaml:"allow_legacy_access_key_only"`
	ClockSkewSeconds             int               `yaml:"clock_skew_seconds"`
	MaxPresignExpires            int               `yaml:"max_presign_expires"`
	AuthDebugLog                string            `yaml:"auth_debug_log"`
	AllowHostCandidateFallbacks bool              `yaml:"allow_host_candidate_fallbacks"`
	PublicReadAllBuckets        bool              `yaml:"public_read_all_buckets"`
	Admin                       AdminConfig       `yaml:"admin"`
	GitHubToken                 string            `yaml:"github_token"`
}

// AdminConfig holds admin-panel-specific settings.
type AdminConfig struct {
	Username       string `yaml:"username"`
	PasswordHash   string `yaml:"password_hash"`
	SessionSecret  string `yaml:"session_secret"`
}

// Load reads config.yaml from baseDir, applies environment overrides, and
// returns a validated Config. The configPath argument (if non-empty) overrides
// the default location.
func Load(configPath string) (*Config, error) {
	cfg := defaults()

	path := configPath
	if path == "" {
		exe, err := os.Executable()
		if err == nil {
			path = filepath.Join(filepath.Dir(exe), "config.yaml")
		} else {
			path = "config.yaml"
		}
	}

	data, err := os.ReadFile(path)
	fileMissing := err != nil && os.IsNotExist(err)
	if err != nil {
		if !fileMissing {
			return nil, fmt.Errorf("read config: %w", err)
		}
		// No config file — use defaults + env only.
	} else {
		if err := yaml.Unmarshal(data, cfg); err != nil {
			return nil, fmt.Errorf("parse config: %w", err)
		}
	}

	if err := applyEnvOverrides(cfg); err != nil {
		return nil, err
	}

	normalize(cfg)

	// Skip validation when the config file doesn't exist yet: the admin
	// installer needs the server to start with empty credentials so the
	// user can configure them via the web UI on first run.
	if !fileMissing {
		if err := validate(cfg); err != nil {
			return nil, err
		}
	}

	return cfg, nil
}

// Defaults returns a Config populated with default values.
func Defaults() *Config {
	return defaults()
}

func defaults() *Config {
	return &Config{
		DataDir:                  "./data",
		MaxRequestSize:           100 * 1024 * 1024,
		Credentials:              map[string]string{},
		AllowedAccessKeys:        []string{},
		AllowLegacyAccessKeyOnly: false,
		ClockSkewSeconds:         900,
		MaxPresignExpires:        604800,
		AuthDebugLog:             "",
		AllowHostCandidateFallbacks: false,
		PublicReadAllBuckets:     true,
		Admin: AdminConfig{
			Username:      "admin",
			PasswordHash:  "",
			SessionSecret: "",
		},
		GitHubToken: "",
	}
}

func applyEnvOverrides(cfg *Config) error {
	// String overrides.
	if v := envString("MINI_S3_DATA_DIR"); v != "" {
		cfg.DataDir = v
	}
	if v := envString("MINI_S3_AUTH_DEBUG_LOG"); v != "" {
		cfg.AuthDebugLog = v
	}
	if v := envString("MINI_S3_GITHUB_TOKEN"); v != "" {
		cfg.GitHubToken = v
	}
	if v := envString("MINI_S3_ADMIN_USERNAME"); v != "" {
		cfg.Admin.Username = v
	}
	if v := envString("MINI_S3_ADMIN_PASSWORD_HASH"); v != "" {
		cfg.Admin.PasswordHash = v
	}

	// Integer overrides.
	if v := envString("MINI_S3_MAX_REQUEST_SIZE"); v != "" {
		n, err := strconv.ParseInt(v, 10, 64)
		if err != nil {
			return fmt.Errorf("MINI_S3_MAX_REQUEST_SIZE: invalid integer %q", v)
		}
		cfg.MaxRequestSize = n
	}
	if v := envString("MINI_S3_CLOCK_SKEW_SECONDS"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil {
			return fmt.Errorf("MINI_S3_CLOCK_SKEW_SECONDS: invalid integer %q", v)
		}
		cfg.ClockSkewSeconds = n
	}
	if v := envString("MINI_S3_MAX_PRESIGN_EXPIRES"); v != "" {
		n, err := strconv.Atoi(v)
		if err != nil {
			return fmt.Errorf("MINI_S3_MAX_PRESIGN_EXPIRES: invalid integer %q", v)
		}
		cfg.MaxPresignExpires = n
	}

	// Boolean overrides.
	if v := envString("MINI_S3_PUBLIC_READ_ALL_BUCKETS"); v != "" {
		cfg.PublicReadAllBuckets = parseBool(v)
	}
	if v := envString("MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS"); v != "" {
		cfg.AllowHostCandidateFallbacks = parseBool(v)
	}
	if v := envString("MINI_S3_ALLOW_LEGACY_ACCESS_KEY_ONLY"); v != "" {
		cfg.AllowLegacyAccessKeyOnly = parseBool(v)
	}

	// Comma-separated list.
	if v := envString("MINI_S3_ALLOWED_ACCESS_KEYS"); v != "" {
		cfg.AllowedAccessKeys = splitTrim(v, ",")
	}

	// JSON credentials.
	if v := envString("MINI_S3_CREDENTIALS_JSON"); v != "" {
		var decoded map[string]string
		if err := json.Unmarshal([]byte(v), &decoded); err != nil {
			return fmt.Errorf("MINI_S3_CREDENTIALS_JSON: invalid JSON: %w", err)
		}
		cfg.Credentials = decoded
	}

	return nil
}

func normalize(cfg *Config) {
	if cfg.MaxRequestSize < 1 {
		cfg.MaxRequestSize = 1
	}
	if cfg.ClockSkewSeconds < 1 {
		cfg.ClockSkewSeconds = 1
	}
	if cfg.MaxPresignExpires < 1 {
		cfg.MaxPresignExpires = 1
	}
	cfg.AuthDebugLog = strings.TrimSpace(cfg.AuthDebugLog)
	cfg.Admin.Username = strings.TrimSpace(cfg.Admin.Username)
	if cfg.Admin.Username == "" {
		cfg.Admin.Username = "admin"
	}
	cfg.Admin.PasswordHash = strings.TrimSpace(cfg.Admin.PasswordHash)
	cfg.GitHubToken = strings.TrimSpace(cfg.GitHubToken)

	// Normalize credentials: trim keys, drop empty.
	creds := make(map[string]string, len(cfg.Credentials))
	for k, v := range cfg.Credentials {
		k = strings.TrimSpace(k)
		if k == "" {
			continue
		}
		creds[k] = v
	}
	cfg.Credentials = creds

	// Normalize allowed access keys: trim, dedupe, drop empty.
	seen := make(map[string]bool)
	cleaned := cfg.AllowedAccessKeys[:0]
	for _, k := range cfg.AllowedAccessKeys {
		k = strings.TrimSpace(k)
		if k == "" || seen[k] {
			continue
		}
		seen[k] = true
		cleaned = append(cleaned, k)
	}
	cfg.AllowedAccessKeys = cleaned
}

func validate(cfg *Config) error {
	if len(cfg.Credentials) == 0 {
		hasLegacyAllowance := cfg.AllowLegacyAccessKeyOnly && len(cfg.AllowedAccessKeys) > 0
		if !hasLegacyAllowance {
			return fmt.Errorf("misconfiguration: credentials is empty. Configure credentials or enable allow_legacy_access_key_only with allowed_access_keys")
		}
	}
	return nil
}

func envString(name string) string {
	v := os.Getenv(name)
	if v == "" {
		return ""
	}
	return v
}

func parseBool(v string) bool {
	switch strings.ToLower(strings.TrimSpace(v)) {
	case "1", "true", "yes", "on":
		return true
	default:
		return false
	}
}

func splitTrim(s, sep string) []string {
	parts := strings.Split(s, sep)
	result := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			result = append(result, p)
		}
	}
	return result
}
