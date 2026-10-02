package s3

import (
	"encoding/xml"
	"github.com/fadlee/mini-s3/internal/storage"
	"io"
	"net/http/httptest"
	"reflect"
	"testing"
)

func TestListObjectsEncodingAndOptionalFields(t *testing.T) {
	page := storage.ListPage{Entries: []storage.ListEntry{{File: &storage.FileInfo{Key: "dir/a&<(1).txt"}}, {Prefix: "dir/"}}, Truncated: true, Last: "dir/"}
	type result struct {
		Prefix     *string `xml:"Prefix"`
		Delimiter  string  `xml:"Delimiter"`
		Marker     *string `xml:"Marker"`
		NextMarker string  `xml:"NextMarker"`
		Key        string  `xml:"Contents>Key"`
		Group      string  `xml:"CommonPrefixes>Prefix"`
		StartAfter string  `xml:"StartAfter"`
		KeyCount   int     `xml:"KeyCount"`
	}
	render := func(options ListingOptions) result {
		rec := httptest.NewRecorder()
		NewS3Response(rec).ListObjects(page, "bucket", options)
		var v result
		if err := xml.Unmarshal(rec.Body.Bytes(), &v); err != nil {
			t.Fatal(err)
		}
		return v
	}
	v := render(ListingOptions{Version: 1, Prefix: new("dir/"), Delimiter: new("/"), Marker: new(""), MaxKeys: 2, EncodingType: "url"})
	if v.Prefix == nil || *v.Prefix != "dir%2F" || v.Delimiter != "%2F" || v.Key != "dir%2Fa%26%3C%281%29.txt" || v.Group != "dir%2F" || v.NextMarker != "dir%2F" || v.Marker == nil || *v.Marker != "" {
		t.Fatalf("encoded listing: %+v", v)
	}
	v = render(ListingOptions{Version: 2, MaxKeys: 2, StartAfter: new("dir/"), EncodingType: "url"})
	if v.StartAfter != "dir%2F" || v.KeyCount != 2 {
		t.Fatalf("V2 listing: %+v", v)
	}
	v = render(ListingOptions{Version: 1, MaxKeys: 2})
	if v.Prefix != nil || v.Key != "dir/a&<(1).txt" {
		t.Fatalf("normal listing: %+v", v)
	}
}

func TestListObjectsMixedOrder(t *testing.T) {
	page := storage.ListPage{Entries: []storage.ListEntry{{File: &storage.FileInfo{Key: "a.txt"}}, {Prefix: "dir/"}, {File: &storage.FileInfo{Key: "z.txt"}}}}
	rec := httptest.NewRecorder()
	NewS3Response(rec).ListObjects(page, "bucket", ListingOptions{Version: 2, MaxKeys: 3})
	decoder := xml.NewDecoder(rec.Body)
	var got []string
	for {
		token, err := decoder.Token()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatal(err)
		}
		if start, ok := token.(xml.StartElement); ok && (start.Name.Local == "Contents" || start.Name.Local == "CommonPrefixes") {
			var entry struct {
				Key    string `xml:"Key"`
				Prefix string `xml:"Prefix"`
			}
			if err := decoder.DecodeElement(&entry, &start); err != nil {
				t.Fatal(err)
			}
			got = append(got, entry.Key+entry.Prefix)
		}
	}
	if !reflect.DeepEqual(got, []string{"a.txt", "dir/", "z.txt"}) {
		t.Fatalf("mixed order: %v", got)
	}
}
