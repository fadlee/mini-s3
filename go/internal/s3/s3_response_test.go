package s3

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/fadlee/mini-s3/internal/storage"
)

func TestListObjectsEncodingAndOptionalFields(t *testing.T) {
	page := storage.ListPage{Entries: []storage.ListEntry{{File: &storage.FileInfo{Key: "a&<.txt"}}, {Prefix: "dir&</"}, {File: &storage.FileInfo{Key: "z(1).txt"}}}, Truncated: true}
	recorder := httptest.NewRecorder()
	NewS3Response(recorder).ListObjects(page, "bucket", ListingOptions{Version: 2, Prefix: new(""), Delimiter: new("/"), MaxKeys: 3, EncodingType: "url", StartAfter: new(""), NextContinuationToken: "opaque"})
	body := recorder.Body.String()
	for _, want := range []string{"<Prefix></Prefix>", "<Key>a%26%3C.txt</Key>", "<Prefix>dir%26%3C/</Prefix>", "<Key>z%281%29.txt</Key>", "<KeyCount>3</KeyCount>", "<StartAfter></StartAfter>", "<NextContinuationToken>opaque</NextContinuationToken>"} {
		if !strings.Contains(body, want) {
			t.Fatalf("response lacks %q: %s", want, body)
		}
	}
	recorder = httptest.NewRecorder()
	NewS3Response(recorder).ListObjects(page, "bucket", ListingOptions{Version: 1, MaxKeys: 3})
	if !strings.Contains(recorder.Body.String(), "a&amp;&lt;.txt") || strings.Contains(recorder.Body.String(), "<Prefix></Prefix>") {
		t.Fatalf("normal XML or absent field incorrect: %s", recorder.Body.String())
	}
}
