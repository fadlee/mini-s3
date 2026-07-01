package s3

import (
	"testing"
)

func TestIsValidBucketName(t *testing.T) {
	v := &RequestValidator{}

	tests := []struct {
		bucket string
		want   bool
	}{
		{"valid-bucket", true},
		{"ab", false},
		{"Invalid", false},
		{"192.168.0.1", false},
		{"a", false},
		{"a-b", true},
		{"bucket.name", true},
		{"bucket..name", false},
		{"bucket.-name", false},
		{"bucket-.name", false},
		{"-bucket", false},
		{"bucket-", false},
	}

	for _, tt := range tests {
		got := v.IsValidBucketName(tt.bucket)
		if got != tt.want {
			t.Errorf("IsValidBucketName(%q) = %v, want %v", tt.bucket, got, tt.want)
		}
	}
}

func TestIsValidObjectKey(t *testing.T) {
	v := &RequestValidator{}

	tests := []struct {
		key  string
		want bool
	}{
		{"", true},
		{"path/file.txt", true},
		{"bad\x00key", false},
		{"../secret", false},
		{"./secret", false},
		{"normal-key", true},
		{"dir/sub/file.bin", true},
	}

	for _, tt := range tests {
		got := v.IsValidObjectKey(tt.key)
		if got != tt.want {
			t.Errorf("IsValidObjectKey(%q) = %v, want %v", tt.key, got, tt.want)
		}
	}
}

func TestIsPositiveInteger(t *testing.T) {
	v := &RequestValidator{}

	tests := []struct {
		value string
		want  bool
	}{
		{"1", true},
		{"0", false},
		{"abc", false},
		{"", false},
		{"123", true},
		{"-1", false},
	}
	for _, tt := range tests {
		got := v.IsPositiveInteger(tt.value)
		if got != tt.want {
			t.Errorf("IsPositiveInteger(%q) = %v, want %v", tt.value, got, tt.want)
		}
	}
}

func TestParseRange(t *testing.T) {
	v := &RequestValidator{}

	// Normal range
	r := v.ParseRange("bytes=0-3", 10)
	if !r.Valid || r.Start != 0 || r.End != 3 {
		t.Errorf("ParseRange(bytes=0-3, 10) = %+v, want valid 0-3", r)
	}

	// Suffix range
	r = v.ParseRange("bytes=-4", 10)
	if !r.Valid || r.Start != 6 || r.End != 9 {
		t.Errorf("ParseRange(bytes=-4, 10) = %+v, want valid 6-9", r)
	}

	// Out of range
	r = v.ParseRange("bytes=999-1000", 10)
	if r.Valid {
		t.Errorf("ParseRange(bytes=999-1000, 10) should be invalid")
	}

	// Wrong unit
	r = v.ParseRange("items=0-1", 10)
	if r.Valid {
		t.Errorf("ParseRange(items=0-1, 10) should be invalid (wrong unit)")
	}

	// Open-ended range
	r = v.ParseRange("bytes=5-", 10)
	if !r.Valid || r.Start != 5 || r.End != 9 {
		t.Errorf("ParseRange(bytes=5-, 10) = %+v, want valid 5-9", r)
	}

	// Empty file
	r = v.ParseRange("bytes=0-3", 0)
	if r.Valid {
		t.Errorf("ParseRange on 0-size file should be invalid")
	}
}

func TestIsOversizedRequest(t *testing.T) {
	v := &RequestValidator{}

	if !v.IsOversizedRequest("104857601", 104857600) {
		t.Error("oversized request should be detected")
	}
	if v.IsOversizedRequest("104857600", 104857600) {
		t.Error("max-sized request should be accepted")
	}
	if v.IsOversizedRequest("abc", 104857600) {
		t.Error("invalid content length should be ignored")
	}
	if v.IsOversizedRequest("", 104857600) {
		t.Error("empty content length should be ignored")
	}
}
