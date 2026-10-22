package s3

import (
	"net"
	"net/http"
	"regexp"
	"strconv"
	"strings"

	"github.com/fadlee/mini-s3/internal/storage"
)

// RequestValidator provides validation functions for bucket names, object
// keys, and request parameters, mirroring MiniS3\S3\RequestValidator.
type RequestValidator struct{}

var (
	bucketNameRe = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]*[a-z0-9]$`)
	rangeRe      = regexp.MustCompile(`^bytes=(\d*)-(\d*)$`)
)

// IsValidBucketName checks S3 bucket naming rules.
func (v *RequestValidator) IsValidBucketName(bucket string) bool {
	l := len(bucket)
	if l < 3 || l > 63 {
		return false
	}
	if !bucketNameRe.MatchString(bucket) {
		return false
	}
	if strings.Contains(bucket, "..") || strings.Contains(bucket, ".-") || strings.Contains(bucket, "-.") {
		return false
	}
	if net.ParseIP(bucket) != nil {
		return false
	}
	return true
}

// IsValidObjectKey checks that the key has no null bytes or traversal segments.
func (v *RequestValidator) IsValidObjectKey(key string) bool {
	if key == "" {
		return true
	}
	if strings.ContainsRune(key, 0) {
		return false
	}
	for _, segment := range strings.Split(key, "/") {
		if segment == "." || segment == ".." {
			return false
		}
		switch strings.ToLower(segment) {
		case ".htaccess", ".htpasswd", ".user.ini":
			return false
		}
	}
	return true
}

// IsValidUploadID checks that the upload ID is 32 lowercase hex characters.
func (v *RequestValidator) IsValidUploadID(uploadID string) bool {
	return storage.IsValidUploadID(uploadID)
}

// IsPositiveInteger returns true if value is a string of digits > 0.
func (v *RequestValidator) IsPositiveInteger(value string) bool {
	if value == "" {
		return false
	}
	for _, c := range value {
		if c < '0' || c > '9' {
			return false
		}
	}
	n, err := strconv.Atoi(value)
	if err != nil {
		return false
	}
	return n > 0
}

// IsOversizedRequest checks if the Content-Length exceeds the max request size.
func (v *RequestValidator) IsOversizedRequest(contentLength string, maxRequestSize int64) bool {
	if contentLength == "" {
		return false
	}
	if !isAllDigits(contentLength) {
		return false
	}
	n, err := strconv.ParseInt(contentLength, 10, 64)
	if err != nil {
		return false
	}
	return n > maxRequestSize
}

// RangeResult holds the parsed range request.
type RangeResult struct {
	Valid bool
	Start int64
	End   int64
}

// ObjectETag derives an ETag from modification time and size. It is not an
// MD5: the "-" keeps clients from verifying it as a content hash.
func (v *RequestValidator) ObjectETag(modTime, size int64) string {
	return `"` + strconv.FormatInt(modTime, 16) + "-" + strconv.FormatInt(size, 16) + `"`
}

// IsNotModified evaluates If-None-Match (which takes precedence) and
// If-Modified-Since against the object's ETag and modification time.
func (v *RequestValidator) IsNotModified(ifNoneMatch, ifModifiedSince, etag string, modTime int64) bool {
	if strings.TrimSpace(ifNoneMatch) != "" {
		for _, candidate := range strings.Split(ifNoneMatch, ",") {
			candidate = strings.TrimSpace(candidate)
			if candidate == "*" || strings.TrimPrefix(candidate, "W/") == etag {
				return true
			}
		}
		return false
	}

	if strings.TrimSpace(ifModifiedSince) != "" {
		since, err := http.ParseTime(strings.TrimSpace(ifModifiedSince))
		return err == nil && modTime <= since.Unix()
	}

	return false
}

// IsRangeApplicable reports whether a Range header should be honored given
// If-Range: it must strongly match the ETag or exactly match the date.
func (v *RequestValidator) IsRangeApplicable(ifRange, etag string, modTime int64) bool {
	ifRange = strings.TrimSpace(ifRange)
	if ifRange == "" {
		return true
	}
	if strings.HasPrefix(ifRange, `"`) || strings.HasPrefix(ifRange, "W/") {
		return ifRange == etag
	}
	date, err := http.ParseTime(ifRange)
	return err == nil && date.Unix() == modTime
}

// ParseRange parses an HTTP Range header value against the given file size.
func (v *RequestValidator) ParseRange(rangeVal string, fileSize int64) RangeResult {
	matches := rangeRe.FindStringSubmatch(strings.TrimSpace(rangeVal))
	if matches == nil {
		return RangeResult{Valid: false}
	}

	if fileSize <= 0 {
		return RangeResult{Valid: false}
	}

	startRaw := matches[1]
	endRaw := matches[2]

	if startRaw == "" && endRaw == "" {
		return RangeResult{Valid: false}
	}

	if startRaw == "" {
		if !isAllDigits(endRaw) {
			return RangeResult{Valid: false}
		}
		suffixLength, err := strconv.ParseInt(endRaw, 10, 64)
		if err != nil || suffixLength <= 0 {
			return RangeResult{Valid: false}
		}
		start := fileSize - suffixLength
		if start < 0 {
			start = 0
		}
		return RangeResult{Valid: true, Start: start, End: fileSize - 1}
	}

	if !isAllDigits(startRaw) {
		return RangeResult{Valid: false}
	}

	start, err := strconv.ParseInt(startRaw, 10, 64)
	if err != nil {
		return RangeResult{Valid: false}
	}
	if start >= fileSize {
		return RangeResult{Valid: false}
	}

	if endRaw == "" {
		return RangeResult{Valid: true, Start: start, End: fileSize - 1}
	}

	if !isAllDigits(endRaw) {
		return RangeResult{Valid: false}
	}

	end, err := strconv.ParseInt(endRaw, 10, 64)
	if err != nil {
		return RangeResult{Valid: false}
	}
	if end > fileSize-1 {
		end = fileSize - 1
	}
	if start > end {
		return RangeResult{Valid: false}
	}
	return RangeResult{Valid: true, Start: start, End: end}
}

func isAllDigits(s string) bool {
	if s == "" {
		return false
	}
	for _, c := range s {
		if c < '0' || c > '9' {
			return false
		}
	}
	return true
}
