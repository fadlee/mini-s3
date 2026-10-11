package storage

import (
	"bytes"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestObjectMetadata(t *testing.T) {
	base := t.TempDir()
	bucketDir := filepath.Join(base, "bucket")
	os.MkdirAll(bucketDir, 0777)
	os.WriteFile(filepath.Join(bucketDir, "object.bin"), []byte("hello"), 0666)

	st := New(base)
	meta, err := st.ObjectMetadata("bucket", "object.bin")
	if err != nil {
		t.Fatalf("ObjectMetadata error: %v", err)
	}
	if meta == nil {
		t.Fatal("ObjectMetadata returned nil for existing file")
	}
	if meta.Size != 5 {
		t.Errorf("size = %d, want 5", meta.Size)
	}
	if meta.MimeType == "" {
		t.Error("mimeType should not be empty")
	}
}

func TestPutAndDeleteObject(t *testing.T) {
	base := t.TempDir()
	st := New(base)
	st.EnsureDataDirExists()

	body := strings.NewReader("test content")
	if err := st.PutObject("bucket", "file.txt", body); err != nil {
		t.Fatalf("PutObject: %v", err)
	}

	if !st.ObjectExists("bucket", "file.txt") {
		t.Fatal("object should exist after put")
	}

	meta, _ := st.ObjectMetadata("bucket", "file.txt")
	if meta.Size != 12 {
		t.Errorf("size = %d, want 12", meta.Size)
	}

	if err := st.DeleteObject("bucket", "file.txt"); err != nil {
		t.Fatalf("DeleteObject: %v", err)
	}
	if st.ObjectExists("bucket", "file.txt") {
		t.Error("object should not exist after delete")
	}
}

func TestListFiles(t *testing.T) {
	base := t.TempDir()
	bucketDir := filepath.Join(base, "mybucket")
	os.MkdirAll(filepath.Join(bucketDir, "sub"), 0777)
	os.WriteFile(filepath.Join(bucketDir, "a.txt"), []byte("a"), 0666)
	os.WriteFile(filepath.Join(bucketDir, "sub", "b.txt"), []byte("b"), 0666)
	os.WriteFile(filepath.Join(bucketDir, ".hidden"), []byte("h"), 0666)

	st := New(base)
	files, err := st.ListFiles("mybucket", "")
	if err != nil {
		t.Fatalf("ListFiles: %v", err)
	}
	if len(files) != 2 {
		t.Fatalf("expected 2 files (hidden excluded), got %d", len(files))
	}

	// Test prefix filter
	files, _ = st.ListFiles("mybucket", "sub/")
	if len(files) != 1 {
		t.Fatalf("prefix filter: expected 1 file, got %d", len(files))
	}
	if files[0].Key != "sub/b.txt" {
		t.Errorf("key = %q, want 'sub/b.txt'", files[0].Key)
	}
}

func TestIsValidUploadID(t *testing.T) {
	valid := hex.EncodeToString(bytes.Repeat([]byte{0xab}, 16))
	tests := []struct {
		id   string
		want bool
	}{
		{valid, true},
		{"", false},
		{"../x", false},
		{"ABABABABABABABABABABABABABABABAB", false},
		{strings.Repeat("a", 31), false},
		{strings.Repeat("a", 33), false},
		{"abc/def", false},
	}
	for _, tt := range tests {
		if got := IsValidUploadID(tt.id); got != tt.want {
			t.Errorf("IsValidUploadID(%q) = %v, want %v", tt.id, got, tt.want)
		}
	}
}

func TestMultipartTraversalUploadIDRejected(t *testing.T) {
	root := t.TempDir()
	dataDir := filepath.Join(root, "data")
	victimDir := filepath.Join(root, "victim")
	os.MkdirAll(victimDir, 0777)
	os.WriteFile(filepath.Join(victimDir, "secret.txt"), []byte("keep"), 0666)

	st := New(dataDir)
	st.EnsureDataDirExists()
	validID := hex.EncodeToString(bytes.Repeat([]byte{0x01}, 16))
	if err := st.CreateMultipartUpload("bucket", "k", validID); err != nil {
		t.Fatalf("CreateMultipartUpload: %v", err)
	}

	traversal := "../../../../victim"
	if err := st.AbortMultipartUpload("bucket", "k", traversal); err != ErrInvalidUploadID {
		t.Errorf("AbortMultipartUpload error = %v, want ErrInvalidUploadID", err)
	}
	if st.MultipartDirExists("bucket", "k", traversal) {
		t.Error("MultipartDirExists should be false for traversal upload ID")
	}
	if _, err := st.PutMultipartPart("bucket", "k", traversal, 1, strings.NewReader("x")); err != ErrInvalidUploadID {
		t.Errorf("PutMultipartPart error = %v, want ErrInvalidUploadID", err)
	}
	if err := st.CompleteMultipartUpload("bucket", "k", traversal, []int{1}); err != ErrInvalidUploadID {
		t.Errorf("CompleteMultipartUpload error = %v, want ErrInvalidUploadID", err)
	}
	if _, err := os.Stat(filepath.Join(victimDir, "secret.txt")); err != nil {
		t.Errorf("victim file must survive traversal abort: %v", err)
	}
	if !st.MultipartDirExists("bucket", "k", validID) {
		t.Error("legit multipart dir should be untouched")
	}
}

func TestMultipartUpload(t *testing.T) {
	base := t.TempDir()
	st := New(base)
	st.EnsureDataDirExists()

	bucket, key, uploadID := "bucket", "file.bin", "0123456789abcdef0123456789abcdef"

	// Create multipart upload
	if err := st.CreateMultipartUpload(bucket, key, uploadID); err != nil {
		t.Fatalf("CreateMultipartUpload: %v", err)
	}
	if !st.MultipartDirExists(bucket, key, uploadID) {
		t.Fatal("multipart dir should exist")
	}

	// Upload parts
	part1 := strings.NewReader("part1-")
	part2 := strings.NewReader("part2-")
	st.PutMultipartPart(bucket, key, uploadID, 1, part1)
	st.PutMultipartPart(bucket, key, uploadID, 2, part2)

	// Complete
	if err := st.CompleteMultipartUpload(bucket, key, uploadID, []int{1, 2}); err != nil {
		t.Fatalf("CompleteMultipartUpload: %v", err)
	}

	// Verify final object
	meta, _ := st.ObjectMetadata(bucket, key)
	if meta == nil {
		t.Fatal("completed object should exist")
	}
	if meta.Size != 12 {
		t.Errorf("size = %d, want 12", meta.Size)
	}

	// Cleanup
	st.CleanupMultipartUpload(bucket, key, uploadID)
	if st.MultipartDirExists(bucket, key, uploadID) {
		t.Error("multipart dir should be cleaned up")
	}
}
