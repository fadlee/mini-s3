package storage

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

const multipartRoot = ".multipart"

// FileStorage implements filesystem-backed object storage, mirroring
// MiniS3\Storage\FileStorage from the PHP reference.
type FileStorage struct {
	dataDir string
}

// New creates a FileStorage rooted at dataDir.
func New(dataDir string) *FileStorage {
	return &FileStorage{dataDir: dataDir}
}

// DataDir returns the configured data directory.
func (s *FileStorage) DataDir() string {
	return s.dataDir
}

// EnsureDataDirExists creates the data directory if it doesn't exist.
func (s *FileStorage) EnsureDataDirExists() error {
	return os.MkdirAll(s.dataDir, 0777)
}

// FileInfo holds metadata about a stored object.
type FileInfo struct {
	Key       string
	Size      int64
	Timestamp int64
}

// ListFiles recursively lists files in a bucket, optionally filtered by prefix.
// Hidden files (starting with ".") are excluded, matching PHP behavior.
func (s *FileStorage) ListFiles(bucket, prefix string) ([]FileInfo, error) {
	dir := filepath.Join(s.dataDir, bucket)
	entries, err := os.ReadDir(dir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, err
	}

	var files []FileInfo
	err = filepath.WalkDir(dir, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			return nil
		}
		name := d.Name()
		if strings.HasPrefix(name, ".") {
			return nil
		}

		rel, err := filepath.Rel(dir, path)
		if err != nil {
			return nil
		}
		// Use forward slashes for S3 key compatibility.
		rel = filepath.ToSlash(rel)

		if prefix != "" && !strings.HasPrefix(rel, prefix) {
			return nil
		}

		info, err := d.Info()
		if err != nil {
			return nil
		}
		files = append(files, FileInfo{
			Key:       rel,
			Size:      info.Size(),
			Timestamp: info.ModTime().Unix(),
		})
		return nil
	})
	// We already read the dir; if WalkDir fails because the dir doesn't
	// exist, that's fine (already handled above). But WalkDir may re-stat.
	if err != nil && !os.IsNotExist(err) {
		return nil, err
	}
	_ = entries
	return files, nil
}

// ObjectPath returns the full filesystem path for a bucket/key pair.
func (s *FileStorage) ObjectPath(bucket, key string) string {
	return filepath.Join(s.dataDir, bucket, key)
}

// ObjectExists reports whether the object exists as a regular file.
func (s *FileStorage) ObjectExists(bucket, key string) bool {
	info, err := os.Stat(s.ObjectPath(bucket, key))
	return err == nil && !info.IsDir()
}

// ObjectMetadata returns size and MIME type for an object, or nil if not found.
type ObjectMetadata struct {
	Size     int64
	MimeType string
}

func (s *FileStorage) ObjectMetadata(bucket, key string) (*ObjectMetadata, error) {
	path := s.ObjectPath(bucket, key)
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}
		return nil, err
	}
	return &ObjectMetadata{
		Size:     info.Size(),
		MimeType: s.detectMimeType(path),
	}, nil
}

// OpenObjectReadStream opens the object for reading.
func (s *FileStorage) OpenObjectReadStream(bucket, key string) (*os.File, error) {
	return os.Open(s.ObjectPath(bucket, key))
}

// PutObject writes the object from the provided reader (atomic: temp + rename).
func (s *FileStorage) PutObject(bucket, key string, body io.Reader) error {
	path := s.ObjectPath(bucket, key)
	if err := s.ensureDir(filepath.Dir(path)); err != nil {
		return err
	}
	return s.atomicWrite(path, body)
}

// DeleteObject removes the object file if it exists.
func (s *FileStorage) DeleteObject(bucket, key string) error {
	path := s.ObjectPath(bucket, key)
	err := os.Remove(path)
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	return nil
}

// CreateMultipartUpload creates the multipart staging directory.
func (s *FileStorage) CreateMultipartUpload(bucket, key, uploadID string) error {
	uploadDir := s.MultipartDir(bucket, key, uploadID)
	return s.ensureDir(uploadDir)
}

// MultipartDir returns the directory path for a multipart upload.
func (s *FileStorage) MultipartDir(bucket, key, uploadID string) string {
	return filepath.Join(s.dataDir, multipartRoot, bucket, s.keyNamespace(key), uploadID)
}

// MultipartDirExists reports whether the multipart upload directory exists.
func (s *FileStorage) MultipartDirExists(bucket, key, uploadID string) bool {
	info, err := os.Stat(s.MultipartDir(bucket, key, uploadID))
	return err == nil && info.IsDir()
}

// PutMultipartPart writes a multipart part from the reader and returns its path.
func (s *FileStorage) PutMultipartPart(bucket, key, uploadID string, partNumber int, body io.Reader) (string, error) {
	uploadDir := s.MultipartDir(bucket, key, uploadID)
	if _, err := os.Stat(uploadDir); err != nil {
		return "", fmt.Errorf("upload ID not found")
	}
	partPath := filepath.Join(uploadDir, fmt.Sprintf("%d", partNumber))
	if err := s.atomicWrite(partPath, body); err != nil {
		return "", err
	}
	return partPath, nil
}

// CompleteMultipartUpload assembles parts (in order) into the final object.
func (s *FileStorage) CompleteMultipartUpload(bucket, key, uploadID string, partNumbers []int) error {
	uploadDir := s.MultipartDir(bucket, key, uploadID)
	if _, err := os.Stat(uploadDir); err != nil {
		return fmt.Errorf("upload ID not found")
	}

	filePath := s.ObjectPath(bucket, key)
	if err := s.ensureDir(filepath.Dir(filePath)); err != nil {
		return err
	}

	tmpPath := s.createTempPath(filepath.Dir(filePath), ".obj-")
	out, err := os.Create(tmpPath)
	if err != nil {
		return fmt.Errorf("failed to open destination file: %w", err)
	}

	for _, partNumber := range partNumbers {
		partPath := filepath.Join(uploadDir, fmt.Sprintf("%d", partNumber))
		in, err := os.Open(partPath)
		if err != nil {
			out.Close()
			os.Remove(tmpPath)
			return fmt.Errorf("part file missing: %d", partNumber)
		}
		_, err = io.Copy(out, in)
		in.Close()
		if err != nil {
			out.Close()
			os.Remove(tmpPath)
			return fmt.Errorf("failed to merge multipart part: %d", partNumber)
		}
	}
	out.Close()

	if err := os.Rename(tmpPath, filePath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("failed to finalize destination file: %w", err)
	}
	return nil
}

// CleanupMultipartUpload removes the multipart upload directory and cleans
// up empty parent directories.
func (s *FileStorage) CleanupMultipartUpload(bucket, key, uploadID string) error {
	uploadDir := s.MultipartDir(bucket, key, uploadID)
	if err := s.deleteDirectory(uploadDir); err != nil && !os.IsNotExist(err) {
		return err
	}

	keyRoot := filepath.Dir(uploadDir)
	bucketRoot := filepath.Dir(keyRoot)
	multipartRootPath := filepath.Join(s.dataDir, multipartRoot)

	s.removeIfEmpty(keyRoot)
	s.removeIfEmpty(bucketRoot)
	s.removeIfEmpty(multipartRootPath)
	return nil
}

// AbortMultipartUpload is an alias for CleanupMultipartUpload.
func (s *FileStorage) AbortMultipartUpload(bucket, key, uploadID string) error {
	return s.CleanupMultipartUpload(bucket, key, uploadID)
}

func (s *FileStorage) atomicWrite(targetPath string, body io.Reader) error {
	tmpPath := s.createTempPath(filepath.Dir(targetPath), ".upload-")
	out, err := os.Create(tmpPath)
	if err != nil {
		return fmt.Errorf("failed to write file: %w", err)
	}

	_, err = io.Copy(out, body)
	closeErr := out.Close()
	if err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("failed to write file: %w", err)
	}
	if closeErr != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("failed to write file: %w", closeErr)
	}

	if err := os.Rename(tmpPath, targetPath); err != nil {
		os.Remove(tmpPath)
		return fmt.Errorf("failed to finalize file: %w", err)
	}
	return nil
}

func (s *FileStorage) ensureDir(dir string) error {
	return os.MkdirAll(dir, 0777)
}

func (s *FileStorage) deleteDirectory(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if !info.IsDir() {
		return os.Remove(path)
	}
	entries, err := os.ReadDir(path)
	if err != nil {
		return err
	}
	for _, entry := range entries {
		if err := s.deleteDirectory(filepath.Join(path, entry.Name())); err != nil {
			return err
		}
	}
	return os.Remove(path)
}

func (s *FileStorage) keyNamespace(key string) string {
	if key == "" {
		return "_root"
	}
	h := sha256.Sum256([]byte(key))
	return hex.EncodeToString(h[:])
}

func (s *FileStorage) createTempPath(directory, prefix string) string {
	f, err := os.CreateTemp(directory, prefix)
	if err != nil {
		// Fall back to a random name if CreateTemp fails.
		return filepath.Join(directory, prefix+randomSuffix())
	}
	name := f.Name()
	f.Close()
	return name
}

func (s *FileStorage) removeIfEmpty(dir string) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	if len(entries) == 0 {
		os.Remove(dir)
	}
}

func (s *FileStorage) detectMimeType(path string) string {
	// Open the file and sniff the first 512 bytes, similar to PHP's
	// mime_content_type but using Go's mime detection.
	f, err := os.Open(path)
	if err != nil {
		return "application/octet-stream"
	}
	defer f.Close()

	buf := make([]byte, 512)
	n, err := f.Read(buf)
	if err != nil && err != io.EOF {
		return "application/octet-stream"
	}

	mimeType := http.DetectContentType(buf[:n])
	if mimeType == "" {
		return "application/octet-stream"
	}
	// http.DetectContentType may return a charset suffix (e.g.
	// "text/plain; charset=utf-8"). Strip it to match PHP's
	// mime_content_type which returns only the MIME type.
	if i := strings.Index(mimeType, ";"); i != -1 {
		mimeType = strings.TrimSpace(mimeType[:i])
	}
	return mimeType
}

func randomSuffix() string {
	return fmt.Sprintf("%d", os.Getpid())
}
