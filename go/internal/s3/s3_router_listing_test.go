package s3

import (
	"encoding/xml"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/fadlee/mini-s3/internal/auth"
	"github.com/fadlee/mini-s3/internal/storage"
)

func TestS3RouterListing(t *testing.T) {
	base := t.TempDir()
	for _, key := range []string{"a.txt", "dir/x.txt", "dir/y.txt", "z(1).txt"} {
		p := filepath.Join(base,"bucket",filepath.FromSlash(key)); if err := os.MkdirAll(filepath.Dir(p),0777); err != nil { t.Fatal(err) }; if err := os.WriteFile(p,nil,0666); err != nil { t.Fatal(err) }
	}
	router := New(storage.New(base),auth.New(nil,nil,false,0,0,"",false),0,true)
	request := func(query string) (int,string) { req := httptest.NewRequest("GET","/bucket?"+query,nil); rec := httptest.NewRecorder(); router.ServeHTTP(rec,req); return rec.Code,rec.Body.String() }
	type result struct { Truncated string `xml:"IsTruncated"`; KeyCount int `xml:"KeyCount"`; Next string `xml:"NextContinuationToken"`; Contents []string `xml:"Contents>Key"`; Prefixes []string `xml:"CommonPrefixes>Prefix"`; NextMarker string `xml:"NextMarker"` }
	parse := func(body string) result { var v result; if err := xml.Unmarshal([]byte(body),&v); err != nil { t.Fatal(err) }; return v }
	code, body := request("list-type=2&delimiter=%2F&max-keys=1"); if code != 200 { t.Fatalf("status %d: %s",code,body) }
	page := parse(body); if page.Contents[0] != "a.txt" || page.Next == "" || page.KeyCount != 1 || page.Truncated != "true" { t.Fatalf("first page: %+v",page) }
	var got []string
	for page.Next != "" { code,body=request("list-type=2&delimiter=%2F&max-keys=1&continuation-token="+page.Next); if code != 200 { t.Fatalf("status %d: %s",code,body) }; page=parse(body); got=append(got,page.Contents...); got=append(got,page.Prefixes...) }
	if strings.Join(got,",") != "dir/,z(1).txt" || page.Truncated != "false" { t.Fatalf("continuation entries %v final %+v",got,page) }
	code,body=request("list-type=1&max-keys=1"); if code != 400 { t.Fatalf("explicit list type 1 status %d",code) }
	code,body=request("max-keys=1&max-keys=2"); if code != 400 { t.Fatalf("duplicate parameter status %d",code) }
	code,body=request("encoding-type=url"); if code != 200 || !strings.Contains(body,"z%281%29.txt") { t.Fatalf("URL encoding: %d %s",code,body) }
	code,body=request("max-keys=0&list-type=2"); if code != 200 || strings.Contains(body,"<Contents>") || strings.Contains(body,"<NextContinuationToken>") { t.Fatalf("zero page: %d %s",code,body) }
}
