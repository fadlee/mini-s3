package s3

import (
	"encoding/base64"
	"encoding/xml"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"github.com/fadlee/mini-s3/internal/auth"
	"github.com/fadlee/mini-s3/internal/storage"
)

func TestS3RouterListing(t *testing.T) {
	base := t.TempDir()
	for _, key := range []string{"a.txt", "dir/x.txt", "dir/y.txt", "z(1).txt"} {
		p := filepath.Join(base, "bucket", filepath.FromSlash(key))
		if err := os.MkdirAll(filepath.Dir(p), 0777); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(p, nil, 0666); err != nil {
			t.Fatal(err)
		}
	}
	router := New(storage.New(base), auth.New(nil, nil, false, 0, 0, "", false), 0, true)
	request := func(query string) (int, string) {
		req := httptest.NewRequest("GET", "/bucket?"+query, nil)
		rec := httptest.NewRecorder()
		router.ServeHTTP(rec, req)
		return rec.Code, rec.Body.String()
	}
	type result struct {
		Truncated  string   `xml:"IsTruncated"`
		KeyCount   int      `xml:"KeyCount"`
		Next       string   `xml:"NextContinuationToken"`
		Contents   []string `xml:"Contents>Key"`
		Prefixes   []string `xml:"CommonPrefixes>Prefix"`
		NextMarker string   `xml:"NextMarker"`
	}
	parse := func(body string) result {
		var v result
		if err := xml.Unmarshal([]byte(body), &v); err != nil {
			t.Fatal(err)
		}
		return v
	}
	code, body := request("list-type=2&delimiter=%2F&max-keys=1")
	if code != 200 {
		t.Fatalf("status %d: %s", code, body)
	}
	page := parse(body)
	if page.Contents[0] != "a.txt" || page.Next == "" || page.KeyCount != 1 || page.Truncated != "true" {
		t.Fatalf("first page: %+v", page)
	}
	var got []string
	for page.Next != "" {
		code, body = request("list-type=2&delimiter=%2F&max-keys=1&continuation-token=" + page.Next)
		if code != 200 {
			t.Fatalf("status %d: %s", code, body)
		}
		page = parse(body)
		got = append(got, page.Contents...)
		got = append(got, page.Prefixes...)
	}
	if strings.Join(got, ",") != "dir/,z(1).txt" || page.Truncated != "false" {
		t.Fatalf("continuation entries %v final %+v", got, page)
	}
	code, body = request("list-type=1&max-keys=1")
	if code != 400 {
		t.Fatalf("explicit list type 1 status %d", code)
	}
	code, body = request("max-keys=1&max-keys=2")
	if code != 400 {
		t.Fatalf("duplicate parameter status %d", code)
	}
	code, body = request("encoding-type=url")
	if code != 200 || !strings.Contains(body, "z%281%29.txt") {
		t.Fatalf("URL encoding: %d %s", code, body)
	}
	code, body = request("max-keys=0&list-type=2")
	if code != 200 || strings.Contains(body, "<Contents>") || strings.Contains(body, "<NextContinuationToken>") {
		t.Fatalf("zero page: %d %s", code, body)
	}
}

func TestS3RouterListingRawByteCursor(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Windows filenames cannot contain invalid UTF-8")
	}
	base := t.TempDir()
	st := storage.New(base)
	for _, key := range []string{"a\xff.txt", "z.txt"} {
		if err := st.PutObject("bucket", key, strings.NewReader("data")); err != nil {
			t.Fatal(err)
		}
	}
	router := New(st, nil, 0, true)
	var token string
	for i, want := range []string{"a%FF.txt", "z.txt"} {
		rec := httptest.NewRecorder()
		query := "/bucket?list-type=2&encoding-type=url&max-keys=1"
		if token != "" {
			query += "&continuation-token=" + token
		}
		router.ServeHTTP(rec, httptest.NewRequest("GET", query, nil))
		var page struct {
			Key       string `xml:"Contents>Key"`
			Next      string `xml:"NextContinuationToken"`
			Truncated bool   `xml:"IsTruncated"`
		}
		if rec.Code != 200 {
			t.Fatalf("status %d: %s", rec.Code, rec.Body.String())
		}
		if err := xml.Unmarshal(rec.Body.Bytes(), &page); err != nil {
			t.Fatal(err)
		}
		if page.Key != want || page.Truncated != (i == 0) {
			t.Fatalf("page %d: %+v, want %q", i, page, want)
		}
		token = page.Next
	}
}

func TestListingTokenRawContext(t *testing.T) {
	for _, context := range []struct{ prefix, delimiter, after string }{
		{"\xff", "", "\xffx"}, {"", "\xfe", "a\xfe"},
	} {
		token := encodeListingToken(listingToken{Version: 2, Bucket: "bucket", Prefix: context.prefix, Delimiter: context.delimiter, After: context.after})
		after, ok := decodeListingToken(token, "bucket", context.prefix, context.delimiter)
		if !ok || after != context.after {
			t.Fatalf("cursor lost bytes: %q, valid=%v", after, ok)
		}
	}
}

func TestS3RouterListingRejectsNonStringAfter(t *testing.T) {
	router := New(storage.New(t.TempDir()), nil, 0, true)
	for _, after := range []string{"null", "42", "false", "[]", "{}"} {
		t.Run(after, func(t *testing.T) {
			token := base64.RawURLEncoding.EncodeToString([]byte(`{"version":2,"bucket":"bucket","prefix":"","delimiter":"","after":` + after + `}`))
			rec := httptest.NewRecorder()
			router.ServeHTTP(rec, httptest.NewRequest("GET", "/bucket?list-type=2&continuation-token="+token, nil))
			var response struct {
				Code string `xml:"Code"`
			}
			if err := xml.Unmarshal(rec.Body.Bytes(), &response); err != nil {
				t.Fatal(err)
			}
			if rec.Code != 400 || response.Code != "InvalidArgument" {
				t.Fatalf("status %d: %s", rec.Code, rec.Body.String())
			}
		})
	}
}
