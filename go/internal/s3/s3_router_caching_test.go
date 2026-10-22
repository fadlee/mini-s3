package s3

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/fadlee/mini-s3/internal/auth"
	"github.com/fadlee/mini-s3/internal/storage"
)

func TestS3RouterObjectCachingHeaders(t *testing.T) {
	dataDir := t.TempDir()
	path := filepath.Join(dataDir, "bucket", "img.jpg")
	if err := os.MkdirAll(filepath.Dir(path), 0777); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("\xff\xd8\xff\xe0fakejpeg"), 0666); err != nil {
		t.Fatal(err)
	}
	modTime := time.Unix(1761170000, 0)
	if err := os.Chtimes(path, modTime, modTime); err != nil {
		t.Fatal(err)
	}
	etag := `"68f95250-c"`
	lastModified := "Wed, 22 Oct 2025 21:53:20 GMT"
	cache := "public, max-age=31536000, immutable"
	router := New(storage.New(dataDir), auth.New(nil, nil, false, 0, 0, "", false), 1024, true, cache)

	serve := func(method string, headers map[string]string) *httptest.ResponseRecorder {
		req := httptest.NewRequest(method, "/bucket/img.jpg", nil)
		for k, v := range headers {
			req.Header.Set(k, v)
		}
		rec := httptest.NewRecorder()
		router.ServeHTTP(rec, req)
		return rec
	}

	rec := serve("GET", nil)
	if rec.Code != http.StatusOK {
		t.Fatalf("GET status = %d", rec.Code)
	}
	h := rec.Header()
	if h.Get("ETag") != etag || h.Get("Last-Modified") != lastModified || h.Get("Cache-Control") != cache {
		t.Errorf("GET validators = %q %q %q", h.Get("ETag"), h.Get("Last-Modified"), h.Get("Cache-Control"))
	}
	if h.Get("Content-Disposition") != "" || h.Get("Pragma") != "" {
		t.Errorf("GET must not send Content-Disposition/Pragma, got %q %q", h.Get("Content-Disposition"), h.Get("Pragma"))
	}

	head := serve("HEAD", nil)
	if head.Code != http.StatusOK || head.Header().Get("ETag") != etag || head.Header().Get("Cache-Control") != cache {
		t.Errorf("HEAD = %d %q %q", head.Code, head.Header().Get("ETag"), head.Header().Get("Cache-Control"))
	}

	for name, headers := range map[string]map[string]string{
		"If-None-Match":      {"If-None-Match": etag},
		"weak If-None-Match": {"If-None-Match": `"x", W/` + etag},
		"If-Modified-Since":  {"If-Modified-Since": lastModified},
	} {
		for _, method := range []string{"GET", "HEAD"} {
			rec := serve(method, headers)
			if rec.Code != http.StatusNotModified || rec.Body.Len() != 0 {
				t.Errorf("%s %s = %d body %d, want 304 empty", method, name, rec.Code, rec.Body.Len())
			}
			if rec.Header().Get("ETag") != etag || rec.Header().Get("Cache-Control") != cache || rec.Header().Get("Content-Type") != "" {
				t.Errorf("%s %s 304 headers = %v", method, name, rec.Header())
			}
		}
	}

	if rec := serve("GET", map[string]string{"If-None-Match": `"stale"`, "If-Modified-Since": lastModified}); rec.Code != http.StatusOK || rec.Body.Len() != 12 {
		t.Errorf("stale If-None-Match = %d body %d, want 200 full", rec.Code, rec.Body.Len())
	}
	if rec := serve("GET", map[string]string{"Range": "bytes=0-3", "If-Range": etag}); rec.Code != http.StatusPartialContent || rec.Body.Len() != 4 {
		t.Errorf("matching If-Range = %d body %d, want 206 of 4", rec.Code, rec.Body.Len())
	}
	if rec := serve("GET", map[string]string{"Range": "bytes=0-3", "If-Range": `"stale"`}); rec.Code != http.StatusOK || rec.Body.Len() != 12 {
		t.Errorf("stale If-Range = %d body %d, want 200 full", rec.Code, rec.Body.Len())
	}

	private := New(storage.New(dataDir), nil, 1024, false, cache)
	if got := private.cacheControl(); got != "private" {
		t.Errorf("cacheControl without public read = %q, want private", got)
	}
}
