package admin

import (
	"os"
	"path/filepath"
	"sort"
)

// Stats holds filesystem statistics about the data directory.
type Stats struct {
	DataDir      string `json:"data_dir"`
	Status       string `json:"status"`
	BucketCount  int    `json:"bucket_count"`
	ObjectCount  int    `json:"object_count"`
	TotalBytes   int64  `json:"total_bytes"`
}

// ScanStats scans the data directory and returns statistics.
func ScanStats(dataDir string) Stats {
	stats := Stats{
		DataDir: dataDir,
		Status:  "ok",
	}

	info, err := os.Stat(dataDir)
	if err != nil {
		if os.IsNotExist(err) {
			stats.Status = "missing"
		} else {
			stats.Status = "unreadable"
		}
		return stats
	}
	if !info.IsDir() {
		stats.Status = "missing"
		return stats
	}

	// Check writability by trying to create a temp file.
	tmpFile := filepath.Join(dataDir, ".mini-s3-writable-check")
	f, err := os.Create(tmpFile)
	if err != nil {
		stats.Status = "not_writable"
	} else {
		f.Close()
		os.Remove(tmpFile)
	}

	entries, err := os.ReadDir(dataDir)
	if err != nil {
		stats.Status = "unreadable"
		return stats
	}

	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		if entry.Name() == ".multipart" {
			continue
		}

		stats.BucketCount++
		bucketDir := filepath.Join(dataDir, entry.Name())
		filepath.WalkDir(bucketDir, func(path string, d os.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if d.IsDir() {
				return nil
			}
			info, err := d.Info()
			if err != nil {
				return nil
			}
			stats.ObjectCount++
			stats.TotalBytes += info.Size()
			return nil
		})
	}

	return stats
}

// ListBuckets returns bucket info (name, object count, total bytes, modified time).
type BucketInfo struct {
	Name        string `json:"name"`
	ObjectCount int    `json:"object_count"`
	TotalBytes  int64  `json:"total_bytes"`
	Modified    int64  `json:"modified"`
}

func ListBuckets(dataDir string) []BucketInfo {
	entries, err := os.ReadDir(dataDir)
	if err != nil {
		return nil
	}

	var buckets []BucketInfo
	for _, entry := range entries {
		if !entry.IsDir() || entry.Name() == ".multipart" {
			continue
		}

		bucketDir := filepath.Join(dataDir, entry.Name())
		var objectCount int
		var totalBytes int64

		filepath.WalkDir(bucketDir, func(path string, d os.DirEntry, err error) error {
			if err != nil {
				return nil
			}
			if d.IsDir() {
				return nil
			}
			if len(d.Name()) > 0 && d.Name()[0] == '.' {
				return nil
			}
			info, err := d.Info()
			if err != nil {
				return nil
			}
			objectCount++
			totalBytes += info.Size()
			return nil
		})

		info, _ := entry.Info()
		var modified int64
		if info != nil {
			modified = info.ModTime().Unix()
		}

		buckets = append(buckets, BucketInfo{
			Name:        entry.Name(),
			ObjectCount: objectCount,
			TotalBytes:  totalBytes,
			Modified:    modified,
		})
	}

	sort.Slice(buckets, func(i, j int) bool {
		return buckets[i].Name < buckets[j].Name
	})
	return buckets
}
