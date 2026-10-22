package s3

import (
	"encoding/xml"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/fadlee/mini-s3/internal/auth"
	minihttp "github.com/fadlee/mini-s3/internal/http"
	"github.com/fadlee/mini-s3/internal/storage"
)

func TestS3RouterRejectsTraversalUploadID(t *testing.T) {
	root := t.TempDir()
	dataDir := filepath.Join(root, "data")
	victimDir := filepath.Join(root, "victim")
	if err := os.MkdirAll(victimDir, 0777); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(victimDir, "secret.txt"), []byte("keep"), 0666); err != nil {
		t.Fatal(err)
	}

	router := New(storage.New(dataDir), auth.New(nil, nil, false, 0, 0, "", false), 0, true, "public, max-age=3600")
	// Handlers are called directly because signing SigV4 requests is out of
	// scope here; ServeHTTP only adds auth in front of the same dispatch.
	call := func(method, target, body string, handle func(*minihttp.RequestContext, *S3Response, string, string)) (int, string) {
		req := httptest.NewRequest(method, target, strings.NewReader(body))
		rec := httptest.NewRecorder()
		handle(minihttp.NewRequestContext(req), NewS3Response(rec), "bucket", "k")
		return rec.Code, rec.Body.String()
	}

	code, body := call("POST", "/bucket/k?uploads", "", router.handlePost)
	if code != http.StatusOK {
		t.Fatalf("initiate status = %d, body = %s", code, body)
	}
	var initiated struct {
		UploadID string `xml:"UploadId"`
	}
	if err := xml.Unmarshal([]byte(body), &initiated); err != nil {
		t.Fatalf("initiate response: %v", err)
	}

	traversal := url.QueryEscape("../../../../victim")
	code, body = call("DELETE", "/bucket/k?uploadId="+traversal, "", router.handleDelete)
	if code != http.StatusNotFound || !strings.Contains(body, "NoSuchUpload") {
		t.Errorf("traversal DELETE = %d %s, want 404 NoSuchUpload", code, body)
	}
	code, body = call("PUT", "/bucket/k?uploadId="+traversal+"&partNumber=1", "x", router.handlePut)
	if code != http.StatusNotFound || !strings.Contains(body, "NoSuchUpload") {
		t.Errorf("traversal PUT part = %d %s, want 404 NoSuchUpload", code, body)
	}
	code, body = call("POST", "/bucket/k?uploadId="+traversal, "<CompleteMultipartUpload></CompleteMultipartUpload>", router.handlePost)
	if code != http.StatusNotFound || !strings.Contains(body, "NoSuchUpload") {
		t.Errorf("traversal POST complete = %d %s, want 404 NoSuchUpload", code, body)
	}
	if _, err := os.Stat(filepath.Join(victimDir, "secret.txt")); err != nil {
		t.Errorf("victim file must survive: %v", err)
	}

	code, body = call("PUT", "/bucket/k?uploadId="+initiated.UploadID+"&partNumber=10001", "x", router.handlePut)
	if code != http.StatusBadRequest || !strings.Contains(body, "InvalidPart") {
		t.Errorf("partNumber 10001 = %d %s, want 400 InvalidPart", code, body)
	}

	code, _ = call("DELETE", "/bucket/k?uploadId="+initiated.UploadID, "", router.handleDelete)
	if code != http.StatusNoContent {
		t.Errorf("legit DELETE = %d, want 204", code)
	}
}

func TestS3RouterRejectsProtectedObjectKey(t *testing.T) {
	router := New(storage.New(t.TempDir()), auth.New(nil, nil, false, 0, 0, "", false), 0, true, "public, max-age=3600")
	req := httptest.NewRequest("PUT", "/bucket/a/.htaccess", strings.NewReader("x"))
	rec := httptest.NewRecorder()
	router.ServeHTTP(rec, req)
	if rec.Code != http.StatusBadRequest || !strings.Contains(rec.Body.String(), "InvalidObjectKey") {
		t.Errorf("PUT a/.htaccess = %d %s, want 400 InvalidObjectKey", rec.Code, rec.Body.String())
	}
}
