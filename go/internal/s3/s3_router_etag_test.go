package s3

import (
	"crypto/md5"
	"encoding/hex"
	"encoding/xml"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/fadlee/mini-s3/internal/auth"
	minihttp "github.com/fadlee/mini-s3/internal/http"
	"github.com/fadlee/mini-s3/internal/storage"
)

func quotedMD5(s string) string {
	sum := md5.Sum([]byte(s))
	return `"` + hex.EncodeToString(sum[:]) + `"`
}

// Strict S3 clients (AWS SDK v2, Litestream) treat an upload without an
// ETag as failed, and "aws s3 mb" sends PUT /bucket.
func TestS3RouterReturnsETagsAndCreatesBuckets(t *testing.T) {
	dataDir := t.TempDir()
	router := New(storage.New(dataDir), auth.New(nil, nil, false, 0, 0, "", false), 0, true, "public, max-age=3600")
	call := func(method, target, body, key string, handle func(*minihttp.RequestContext, *S3Response, string, string)) *httptest.ResponseRecorder {
		req := httptest.NewRequest(method, target, strings.NewReader(body))
		rec := httptest.NewRecorder()
		handle(minihttp.NewRequestContext(req), NewS3Response(rec), "bucket", key)
		return rec
	}

	rec := call("PUT", "/bucket", "", "", router.handlePut)
	if rec.Code != http.StatusOK {
		t.Fatalf("CreateBucket = %d %s, want 200", rec.Code, rec.Body.String())
	}
	if info, err := os.Stat(filepath.Join(dataDir, "bucket")); err != nil || !info.IsDir() {
		t.Fatalf("CreateBucket must create the bucket directory: %v", err)
	}
	if rec = call("PUT", "/bucket", "", "", router.handlePut); rec.Code != http.StatusOK {
		t.Errorf("CreateBucket on existing bucket = %d, want 200", rec.Code)
	}

	rec = call("PUT", "/bucket/hello.txt", "hello etag\n", "hello.txt", router.handlePut)
	if got := rec.Header().Get("ETag"); rec.Code != http.StatusOK || got != quotedMD5("hello etag\n") {
		t.Errorf("PutObject = %d ETag %q, want 200 %q", rec.Code, got, quotedMD5("hello etag\n"))
	}

	rec = call("POST", "/bucket/multi.bin?uploads", "", "multi.bin", router.handlePost)
	var initiated struct {
		UploadID string `xml:"UploadId"`
	}
	if err := xml.Unmarshal(rec.Body.Bytes(), &initiated); err != nil || initiated.UploadID == "" {
		t.Fatalf("initiate = %d %s", rec.Code, rec.Body.String())
	}
	part := strings.Repeat("a", 1024)
	rec = call("PUT", "/bucket/multi.bin?uploadId="+initiated.UploadID+"&partNumber=1", part, "multi.bin", router.handlePut)
	if got := rec.Header().Get("ETag"); got != quotedMD5(part) {
		t.Errorf("UploadPart ETag %q, want %q", got, quotedMD5(part))
	}

	complete := `<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>` + quotedMD5(part) + `</ETag></Part></CompleteMultipartUpload>`
	rec = call("POST", "/bucket/multi.bin?uploadId="+initiated.UploadID, complete, "multi.bin", router.handlePost)
	if rec.Code != http.StatusOK {
		t.Fatalf("complete = %d %s", rec.Code, rec.Body.String())
	}
	var result struct {
		ETag string `xml:"ETag"`
	}
	if err := xml.Unmarshal(rec.Body.Bytes(), &result); err != nil || result.ETag != quotedMD5(part) {
		t.Errorf("CompleteMultipartUpload ETag %q (err %v), want %q", result.ETag, err, quotedMD5(part))
	}
	if got := rec.Header().Get("ETag"); got != quotedMD5(part) {
		t.Errorf("CompleteMultipartUpload ETag header %q, want %q", got, quotedMD5(part))
	}
}
