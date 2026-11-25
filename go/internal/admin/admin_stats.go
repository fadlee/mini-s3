package admin

import (
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

// storageCacheTTL is how long measured sizes are reused. Walking a big data
// directory takes seconds, so every page that shows sizes shares one result.
const storageCacheTTL = 60 * time.Second

// Stats is the cheap part of the dashboard: the data directory's status and
// its bucket names. Building it never walks the files; sizes come from
// MeasureStorage.
type Stats struct {
	DataDir     string       `json:"data_dir"`
	Status      string       `json:"status"`
	BucketCount int          `json:"bucket_count"`
	Buckets     []BucketInfo `json:"buckets"`
}

// BucketInfo is a bucket's name and modified time.
type BucketInfo struct {
	Name     string `json:"name"`
	Modified int64  `json:"modified"`
}

// StorageTotals holds object counts and sizes, in total and per bucket.
type StorageTotals struct {
	ObjectCount int           `json:"object_count"`
	TotalBytes  int64         `json:"total_bytes"`
	Buckets     []BucketStats `json:"buckets"`
}

// BucketStats holds the totals for one bucket.
type BucketStats struct {
	Name        string `json:"name"`
	ObjectCount int    `json:"object_count"`
	TotalBytes  int64  `json:"total_bytes"`
}

// ScanStats reports the status of the data directory and lists its buckets.
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
	stats.Buckets = bucketsIn(entries)
	stats.BucketCount = len(stats.Buckets)
	return stats
}

// ListBuckets returns the buckets in the data directory, sorted by name.
func ListBuckets(dataDir string) []BucketInfo {
	entries, err := os.ReadDir(dataDir)
	if err != nil {
		return nil
	}
	return bucketsIn(entries)
}

func bucketsIn(entries []os.DirEntry) []BucketInfo {
	var buckets []BucketInfo
	for _, entry := range entries {
		// Internal directories (.multipart, .etag-cache, .upgrade-cache) are not buckets.
		if !entry.IsDir() || strings.HasPrefix(entry.Name(), ".") {
			continue
		}

		var modified int64
		if info, err := entry.Info(); err == nil {
			modified = info.ModTime().Unix()
		}
		buckets = append(buckets, BucketInfo{Name: entry.Name(), Modified: modified})
	}

	sort.Slice(buckets, func(i, j int) bool {
		return buckets[i].Name < buckets[j].Name
	})
	return buckets
}

// MeasureStorage walks every bucket and returns object counts and sizes.
func MeasureStorage(dataDir string) StorageTotals {
	var totals StorageTotals
	for _, bucket := range ListBuckets(dataDir) {
		count, size := measureDirectory(filepath.Join(dataDir, bucket.Name))
		totals.ObjectCount += count
		totals.TotalBytes += size
		totals.Buckets = append(totals.Buckets, BucketStats{Name: bucket.Name, ObjectCount: count, TotalBytes: size})
	}
	return totals
}

// measureDirectory counts the objects under a directory and adds up their
// sizes. Hidden files are internal bookkeeping, not objects.
func measureDirectory(directory string) (int, int64) {
	count := 0
	var bytes int64
	filepath.WalkDir(directory, func(path string, d os.DirEntry, err error) error {
		if err != nil || d.IsDir() || strings.HasPrefix(d.Name(), ".") {
			return nil
		}
		if info, err := d.Info(); err == nil {
			count++
			bytes += info.Size()
		}
		return nil
	})
	return count, bytes
}

// storageCache keeps the last MeasureStorage result for storageCacheTTL.
// The lock is held while measuring, so requests that arrive meanwhile wait
// for that walk instead of starting their own.
type storageCache struct {
	mu      sync.Mutex
	dataDir string
	at      time.Time
	totals  StorageTotals
}

func (c *storageCache) get(dataDir string) StorageTotals {
	c.mu.Lock()
	defer c.mu.Unlock()
	if c.dataDir != dataDir || time.Since(c.at) >= storageCacheTTL {
		c.totals = MeasureStorage(dataDir)
		c.dataDir = dataDir
		c.at = time.Now()
	}
	return c.totals
}

// invalidate drops the cached sizes after the admin panel changes files.
func (c *storageCache) invalidate() {
	c.mu.Lock()
	defer c.mu.Unlock()
	c.at = time.Time{}
}
