package storage

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"
)

func TestListPage(t *testing.T) {
	base := t.TempDir()
	for _, key := range []string{"z.txt", "a.txt", "dir/x.txt", "dir/y.txt"} {
		path := filepath.Join(base, "bucket", filepath.FromSlash(key))
		if err := os.MkdirAll(filepath.Dir(path), 0777); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, nil, 0666); err != nil {
			t.Fatal(err)
		}
	}
	st := New(base)
	tests := []struct {
		name, prefix, delimiter string
		max                     int
		after                   *string
		want                    []string
		truncated               bool
		last                    string
	}{
		{"ordered page", "", "", 2, nil, []string{"a.txt", "dir/x.txt"}, true, "dir/x.txt"},
		{"grouped", "", "/", 10, nil, []string{"a.txt", "dir/", "z.txt"}, false, "z.txt"},
		{"after group", "", "/", 10, ptr("dir/"), []string{"z.txt"}, false, "z.txt"},
		{"prefix boundary", "dir/", "", 10, ptr("dir/"), []string{"dir/x.txt", "dir/y.txt"}, false, "dir/y.txt"},
		{"zero", "", "", 0, nil, nil, true, ""},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			page, err := st.ListPage("bucket", tt.prefix, tt.delimiter, tt.max, tt.after)
			if err != nil {
				t.Fatal(err)
			}
			var got []string
			for _, entry := range page.Entries {
				if entry.File != nil {
					got = append(got, entry.File.Key)
				} else {
					got = append(got, entry.Prefix)
				}
			}
			if !reflect.DeepEqual(got, tt.want) || page.Truncated != tt.truncated || page.Last != tt.last {
				t.Fatalf("got (%v, %v, %q), want (%v, %v, %q)", got, page.Truncated, page.Last, tt.want, tt.truncated, tt.last)
			}
		})
	}
}

func ptr(s string) *string { return &s }
