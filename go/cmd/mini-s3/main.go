package main

import (
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"path/filepath"

	"github.com/fadlee/mini-s3/internal/admin"
	"github.com/fadlee/mini-s3/internal/auth"
	"github.com/fadlee/mini-s3/internal/config"
	"github.com/fadlee/mini-s3/internal/s3"
	"github.com/fadlee/mini-s3/internal/storage"
)

// Version is set at build time via -ldflags.
var Version = "dev"

func main() {
	addr := flag.String("addr", ":80", "listen address (host:port)")
	configPath := flag.String("config", "", "path to config.yaml (default: alongside executable)")
	showVersion := flag.Bool("version", false, "print version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Println("mini-s3", Version)
		os.Exit(0)
	}

	// Resolve config path and base directory.
	resolvedConfigPath := *configPath
	if resolvedConfigPath == "" {
		if exe, err := os.Executable(); err == nil {
			resolvedConfigPath = filepath.Join(filepath.Dir(exe), "config.yaml")
		} else {
			resolvedConfigPath = "config.yaml"
		}
	}
	baseDir := filepath.Dir(filepath.Dir(resolvedConfigPath))

	// Load config (may fail if config doesn't exist yet — that's OK for installer).
	cfg, err := config.Load(resolvedConfigPath)
	if err != nil {
		// If config doesn't exist, the admin installer will handle it.
		if !os.IsNotExist(err) && !isNotExist(err) {
			log.Fatalf("config: %v", err)
		}
		cfg = config.Defaults()
	}

	log.Printf("mini-s3 %s starting on %s (data_dir=%s)", Version, *addr, cfg.DataDir)

	st := storage.New(cfg.DataDir)
	authenticator := auth.New(
		cfg.Credentials,
		cfg.AllowedAccessKeys,
		cfg.AllowLegacyAccessKeyOnly,
		cfg.ClockSkewSeconds,
		cfg.MaxPresignExpires,
		cfg.AuthDebugLog,
		cfg.AllowHostCandidateFallbacks,
	)
	s3Router := s3.New(st, authenticator, cfg.MaxRequestSize, cfg.PublicReadAllBuckets)

	// Admin router handles /_ paths; everything else goes to S3.
	adminRouter := admin.NewAdminRouter(resolvedConfigPath, baseDir, Version)

	mux := http.NewServeMux()
	mux.Handle("/_", adminRouter)
	mux.Handle("/_/", adminRouter)
	mux.Handle("/", s3Router)

	server := &http.Server{
		Addr:    *addr,
		Handler: mux,
	}

	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("server: %v", err)
	}
}

func isNotExist(err error) bool {
	if err == nil {
		return false
	}
	return os.IsNotExist(err)
}
