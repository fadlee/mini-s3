package admin

import (
	"fmt"
	"io"
	"mime/multipart"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// AdminFileExplorer provides file management operations with path traversal
// containment, mirroring MiniS3\Admin\AdminFileExplorer.
type AdminFileExplorer struct {
	dataDir string
}

// NewAdminFileExplorer creates an explorer for the given data directory.
func NewAdminFileExplorer(dataDir string) *AdminFileExplorer {
	return &AdminFileExplorer{dataDir: dataDir}
}

// FolderInfo holds metadata about a folder in the file explorer.
type FolderInfo struct {
	Name        string `json:"name"`
	Path        string `json:"path"`
	ObjectCount int    `json:"object_count"`
	Modified    int64  `json:"modified"`
}

// FileInfo holds metadata about a file in the file explorer.
type FileInfo struct {
	Name     string `json:"name"`
	Path     string `json:"path"`
	Size     int64  `json:"size"`
	Modified int64  `json:"modified"`
	Mime     string `json:"mime"`
	IsImage  bool   `json:"is_image"`
}

// ListObjectsResult holds the result of listing objects in a bucket/prefix.
type ListObjectsResult struct {
	Folders    []FolderInfo `json:"folders"`
	Files      []FileInfo   `json:"files"`
	BucketRoot string       `json:"bucket_root"`
}

// ListObjects lists folders and files in a bucket under the given prefix.
func (e *AdminFileExplorer) ListObjects(bucket, prefix string) (*ListObjectsResult, error) {
	bucketDir := e.bucketPath(bucket)
	prefix, err := e.normalizeRelativePath(prefix)
	if err != nil {
		return nil, err
	}
	scanDir, err := e.resolveInsideBucket(bucket, prefix, true)
	if err != nil {
		return nil, err
	}

	entries, err := os.ReadDir(scanDir)
	if err != nil {
		return nil, err
	}

	var folders []FolderInfo
	var files []FileInfo

	for _, entry := range entries {
		name := entry.Name()
		if strings.HasPrefix(name, ".") {
			continue
		}

		relativePath := name
		if prefix != "" {
			relativePath = prefix + "/" + name
		}

		fullPath := filepath.Join(scanDir, name)
		info, err := entry.Info()
		if err != nil {
			continue
		}

		if entry.IsDir() {
			folders = append(folders, FolderInfo{
				Name:        name,
				Path:        relativePath,
				ObjectCount: e.countObjects(fullPath),
				Modified:    info.ModTime().Unix(),
			})
		} else {
			mime := detectMimeType(fullPath)
			files = append(files, FileInfo{
				Name:     name,
				Path:     relativePath,
				Size:     info.Size(),
				Modified: info.ModTime().Unix(),
				Mime:     mime,
				IsImage:  isImageMime(mime),
			})
		}
	}

	sort.Slice(folders, func(i, j int) bool { return folders[i].Name < folders[j].Name })
	sort.Slice(files, func(i, j int) bool { return files[i].Name < files[j].Name })

	return &ListObjectsResult{
		Folders:    folders,
		Files:      files,
		BucketRoot: bucketDir,
	}, nil
}

// CreateBucket creates a new bucket directory.
func (e *AdminFileExplorer) CreateBucket(name string) error {
	if err := e.validateSegmentName(name); err != nil {
		return err
	}
	path := e.bucketPath(name)
	if _, err := os.Stat(path); err == nil {
		return fmt.Errorf("bucket already exists: %s", name)
	}
	return os.MkdirAll(path, 0777)
}

// CreateFolder creates a new folder inside a bucket.
func (e *AdminFileExplorer) CreateFolder(bucket, folderPath string) error {
	var err error
	folderPath, err = e.normalizeRelativePath(folderPath)
	if err != nil {
		return err
	}
	if folderPath == "" {
		return fmt.Errorf("folder name is required")
	}
	fullPath, err := e.resolveInsideBucket(bucket, folderPath, false)
	if err != nil {
		return err
	}
	if _, err := os.Stat(fullPath); err == nil {
		return fmt.Errorf("folder already exists")
	}
	return os.MkdirAll(fullPath, 0777)
}

// DeleteObject deletes a file or directory (recursively) inside a bucket.
func (e *AdminFileExplorer) DeleteObject(bucket, objectPath string) error {
	var err error
	objectPath, err = e.normalizeRelativePath(objectPath)
	if err != nil {
		return err
	}
	if objectPath == "" {
		return fmt.Errorf("item path is required")
	}
	fullPath, err := e.resolveInsideBucket(bucket, objectPath, false)
	if err != nil {
		return err
	}
	bucketPath := e.bucketPath(bucket)

	info, err := os.Stat(fullPath)
	if err != nil {
		return fmt.Errorf("object not found")
	}

	if !info.IsDir() {
		if err := os.Remove(fullPath); err != nil {
			return fmt.Errorf("failed to delete file")
		}
		e.cleanupEmptyParents(filepath.Dir(fullPath), bucketPath)
		return nil
	}

	if err := deleteDirectoryRecursive(fullPath); err != nil {
		return fmt.Errorf("failed to delete directory")
	}
	e.cleanupEmptyParents(filepath.Dir(fullPath), bucketPath)
	return nil
}

// DeleteBucket deletes a bucket and all its contents.
func (e *AdminFileExplorer) DeleteBucket(bucket string) error {
	path := e.bucketPath(bucket)
	if _, err := os.Stat(path); os.IsNotExist(err) {
		return fmt.Errorf("bucket not found: %s", bucket)
	}
	return deleteDirectoryRecursive(path)
}

// RenameResult holds the result of a rename operation.
type RenameResult struct {
	Path string `json:"path"`
	Name string `json:"name"`
}

// Rename renames a file or folder within the same directory.
func (e *AdminFileExplorer) Rename(bucket, oldPath, newName string) (*RenameResult, error) {
	var err error
	oldPath, err = e.normalizeRelativePath(oldPath)
	if err != nil {
		return nil, err
	}
	if oldPath == "" {
		return nil, fmt.Errorf("item path is required")
	}
	if err := e.validateSegmentName(newName); err != nil {
		return nil, err
	}

	oldFullPath, err := e.resolveInsideBucket(bucket, oldPath, false)
	if err != nil {
		return nil, err
	}
	if _, err := os.Stat(oldFullPath); err != nil {
		return nil, fmt.Errorf("object not found")
	}

	newFullPath := filepath.Join(filepath.Dir(oldFullPath), newName)
	if _, err := os.Stat(newFullPath); err == nil {
		return nil, fmt.Errorf("a file or folder with that name already exists")
	}

	if err := os.Rename(oldFullPath, newFullPath); err != nil {
		return nil, fmt.Errorf("failed to rename")
	}

	newPath := newName
	if idx := strings.LastIndex(oldPath, "/"); idx != -1 {
		newPath = oldPath[:idx] + "/" + newName
	}

	return &RenameResult{Path: newPath, Name: newName}, nil
}

// RenameBucket renames a bucket.
func (e *AdminFileExplorer) RenameBucket(oldName, newName string) error {
	if err := e.validateSegmentName(newName); err != nil {
		return err
	}
	oldPath := e.bucketPath(oldName)
	newPath := e.bucketPath(newName)

	if _, err := os.Stat(oldPath); os.IsNotExist(err) {
		return fmt.Errorf("bucket not found: %s", oldName)
	}
	if _, err := os.Stat(newPath); err == nil {
		return fmt.Errorf("a bucket with that name already exists")
	}
	return os.Rename(oldPath, newPath)
}

// UploadFile saves an uploaded file to the bucket under the given prefix.
func (e *AdminFileExplorer) UploadFile(bucket, prefix string, fileHeader *multipart.FileHeader) (string, error) {
	var err error
	prefix, err = e.normalizeRelativePath(prefix)
	if err != nil {
		return "", err
	}

	name := filepath.Base(fileHeader.Filename)
	if err := e.validateSegmentName(name); err != nil {
		return "", err
	}

	targetDir, err := e.resolveInsideBucket(bucket, prefix, true)
	if err != nil {
		return "", err
	}

	src, err := fileHeader.Open()
	if err != nil {
		return "", fmt.Errorf("failed to open uploaded file: %w", err)
	}
	defer src.Close()

	targetPath := filepath.Join(targetDir, name)
	dst, err := os.Create(targetPath)
	if err != nil {
		return "", fmt.Errorf("failed to create file: %w", err)
	}
	defer dst.Close()

	if _, err := io.Copy(dst, src); err != nil {
		return "", fmt.Errorf("failed to write file: %w", err)
	}

	if prefix == "" {
		return name, nil
	}
	return prefix + "/" + name, nil
}

// ObjectInfo returns metadata about a specific file.
type ObjectInfo struct {
	Name     string `json:"name"`
	Path     string `json:"path"`
	Size     int64  `json:"size"`
	Modified int64  `json:"modified"`
	Mime     string `json:"mime"`
	IsImage  bool   `json:"is_image"`
}

// ObjectInfo returns metadata about a specific file.
func (e *AdminFileExplorer) ObjectInfo(bucket, objectPath string) (*ObjectInfo, error) {
	var err error
	objectPath, err = e.normalizeRelativePath(objectPath)
	if err != nil {
		return nil, err
	}
	if objectPath == "" {
		return nil, fmt.Errorf("file path is required")
	}

	fullPath, err := e.resolveInsideBucket(bucket, objectPath, false)
	if err != nil {
		return nil, err
	}
	info, err := os.Stat(fullPath)
	if err != nil || info.IsDir() {
		return nil, fmt.Errorf("file not found")
	}

	mime := detectMimeType(fullPath)
	name := objectPath
	if idx := strings.LastIndex(objectPath, "/"); idx != -1 {
		name = objectPath[idx+1:]
	}

	return &ObjectInfo{
		Name:     name,
		Path:     objectPath,
		Size:     info.Size(),
		Modified: info.ModTime().Unix(),
		Mime:     mime,
		IsImage:  isImageMime(mime),
	}, nil
}

// ObjectFullPath returns the full filesystem path for a file, after
// verifying it's contained within the bucket.
func (e *AdminFileExplorer) ObjectFullPath(bucket, objectPath string) (string, error) {
	normalized, err := e.normalizeRelativePath(objectPath)
	if err != nil {
		return "", err
	}
	fullPath, err := e.resolveInsideBucket(bucket, normalized, false)
	if err != nil {
		return "", err
	}
	info, err := os.Stat(fullPath)
	if err != nil || info.IsDir() {
		return "", fmt.Errorf("file not found")
	}
	return fullPath, nil
}

// --- internal helpers ---

func (e *AdminFileExplorer) bucketPath(bucket string) string {
	if err := e.validateSegmentName(bucket); err != nil {
		return filepath.Join(e.dataDir, "_invalid")
	}
	return filepath.Join(strings.TrimRight(e.dataDir, "/"), bucket)
}

func (e *AdminFileExplorer) validateSegmentName(name string) error {
	if name == "" || name == "." || name == ".." {
		return fmt.Errorf("invalid name")
	}
	if strings.Contains(name, "/") || strings.Contains(name, "\\") || strings.ContainsRune(name, 0) {
		return fmt.Errorf("name contains invalid characters")
	}
	if strings.HasPrefix(name, ".") {
		return fmt.Errorf("name cannot start with a dot")
	}
	return nil
}

func (e *AdminFileExplorer) normalizeRelativePath(path string) (string, error) {
	path = strings.TrimSpace(strings.ReplaceAll(path, "\\", "/"))
	path = strings.Trim(path, "/")
	if path == "" {
		return "", nil
	}

	segments := strings.Split(path, "/")
	var clean []string
	for _, segment := range segments {
		if err := e.validateSegmentName(segment); err != nil {
			return "", err
		}
		clean = append(clean, segment)
	}
	return strings.Join(clean, "/"), nil
}

// resolveInsideBucket resolves a path inside a bucket and verifies it's
// contained within the bucket root (path traversal containment).
func (e *AdminFileExplorer) resolveInsideBucket(bucket, relativePath string, allowMissing bool) (string, error) {
	bucketPath := e.bucketPath(bucket)
	if err := e.validateSegmentName(bucket); err != nil {
		return "", err
	}
	if _, err := os.Stat(bucketPath); os.IsNotExist(err) {
		return "", fmt.Errorf("bucket not found: %s", bucket)
	}

	if relativePath == "" {
		return bucketPath, nil
	}

	fullPath := filepath.Join(bucketPath, relativePath)
	parent := filepath.Dir(fullPath)

	if _, err := os.Stat(parent); os.IsNotExist(err) {
		if allowMissing {
			if err := os.MkdirAll(parent, 0777); err != nil {
				return "", fmt.Errorf("failed to create target directory")
			}
		} else {
			return "", fmt.Errorf("object not found")
		}
	}

	// Path traversal containment: resolve symlinks and verify the parent
	// is inside the bucket root.
	resolvedParent, err := filepath.EvalSymlinks(parent)
	if err != nil {
		resolvedParent = filepath.Clean(parent)
	}
	resolvedBucket, err := filepath.EvalSymlinks(bucketPath)
	if err != nil {
		resolvedBucket = filepath.Clean(bucketPath)
	}

	if !strings.HasPrefix(resolvedParent+string(filepath.Separator), resolvedBucket+string(filepath.Separator)) &&
		resolvedParent != resolvedBucket {
		return "", fmt.Errorf("invalid path")
	}

	return fullPath, nil
}

func (e *AdminFileExplorer) countObjects(directory string) int {
	count := 0
	filepath.WalkDir(directory, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.IsDir() {
			return nil
		}
		if !strings.HasPrefix(d.Name(), ".") {
			count++
		}
		return nil
	})
	return count
}

func (e *AdminFileExplorer) cleanupEmptyParents(startDir, stopAt string) {
	current := startDir
	for current != stopAt && current != "." && current != string(filepath.Separator) {
		info, err := os.Stat(current)
		if err != nil || !info.IsDir() {
			break
		}
		entries, err := os.ReadDir(current)
		if err != nil || len(entries) > 0 {
			break
		}
		os.Remove(current)
		current = filepath.Dir(current)
	}
}

// --- package-level helpers ---

func deleteDirectoryRecursive(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
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
		if err := deleteDirectoryRecursive(filepath.Join(path, entry.Name())); err != nil {
			return err
		}
	}
	return os.Remove(path)
}

func detectMimeType(path string) string {
	f, err := os.Open(path)
	if err != nil {
		return "application/octet-stream"
	}
	defer f.Close()

	buf := make([]byte, 512)
	n, _ := f.Read(buf)
	mime := http.DetectContentType(buf[:n])
	if i := strings.Index(mime, ";"); i != -1 {
		mime = strings.TrimSpace(mime[:i])
	}
	if mime == "" {
		return "application/octet-stream"
	}
	return mime
}

func isImageMime(mime string) bool {
	switch mime {
	case "image/jpeg", "image/png", "image/gif", "image/webp", "image/svg+xml":
		return true
	default:
		return false
	}
}
