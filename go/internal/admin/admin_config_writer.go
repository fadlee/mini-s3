package admin

import (
	"fmt"
	"os"
	"path/filepath"
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
	ClockSkewSeconds             string
	MaxPresignExpires            string
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

	// Generate a session secret if none exists.
	sessionSecret := ""
	if existing != nil {
		sessionSecret = existing.Admin.SessionSecret
	}
	if sessionSecret == "" {
		sessionSecret = randomToken(32)
	}

	return &config.Config{
		DataDir:                     dataDir,
		MaxRequestSize:              maxRequestSize,
		Credentials:                 map[string]string{accessKey: secretKey},
		AllowLegacyAccessKeyOnly:    false,
		AllowedAccessKeys:           []string{},
		ClockSkewSeconds:             clockSkewSeconds,
		MaxPresignExpires:            maxPresignExpires,
		AuthDebugLog:                strings.TrimSpace(input.AuthDebugLog),
		AllowHostCandidateFallbacks: checkbox(input.AllowHostCandidateFallbacks),
		PublicReadAllBuckets:        checkboxDefault(input.PublicReadAllBuckets, existing == nil || existing.PublicReadAllBuckets),
		Admin: config.AdminConfig{
			Username:      adminUsername,
			PasswordHash:  adminPasswordHash,
			SessionSecret: sessionSecret,
		},
	}, nil
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
