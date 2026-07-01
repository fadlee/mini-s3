package s3

import (
	"encoding/xml"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/fadlee/mini-s3/internal/storage"
)

// S3Response builds and sends S3-compatible XML and object responses,
// mirroring MiniS3\S3\S3Response from the PHP reference.
type S3Response struct {
	w http.ResponseWriter
}

// NewS3Response creates an S3Response wrapping the given ResponseWriter.
func NewS3Response(w http.ResponseWriter) *S3Response {
	return &S3Response{w: w}
}

// XMLError sends an S3 error response with the given HTTP status and S3 code.
func (r *S3Response) XMLError(httpStatus int, s3Code, message, resource string) {
	type errorXML struct {
		XMLName xml.Name `xml:"Error"`
		Code    string   `xml:"Code"`
		Message string   `xml:"Message"`
		Resource string  `xml:"Resource,omitempty"`
	}
	resp := errorXML{
		Code:    s3Code,
		Message: message,
		Resource: resource,
	}
	r.sendXML(resp, httpStatus)
}

// ListObjects sends a ListBucketResult XML response.
func (r *S3Response) ListObjects(files []storage.FileInfo, bucket, prefix string) {
	type contents struct {
		Key          string `xml:"Key"`
		LastModified string `xml:"LastModified"`
		Size         int64  `xml:"Size"`
		StorageClass string `xml:"StorageClass"`
	}
	type listResult struct {
		XMLName     xml.Name   `xml:"ListBucketResult"`
		Name        string     `xml:"Name"`
		Prefix      string     `xml:"Prefix"`
		MaxKeys     string     `xml:"MaxKeys"`
		IsTruncated string     `xml:"IsTruncated"`
		Contents    []contents `xml:"Contents"`
	}

	resp := listResult{
		Name:        bucket,
		Prefix:      prefix,
		MaxKeys:     "1000",
		IsTruncated: "false",
	}
	for _, f := range files {
		resp.Contents = append(resp.Contents, contents{
			Key:          f.Key,
			LastModified: formatLastModified(f.Timestamp),
			Size:         f.Size,
			StorageClass: "STANDARD",
		})
	}
	r.sendXML(resp, http.StatusOK)
}

// CreateMultipartUpload sends an InitiateMultipartUploadResult XML response.
func (r *S3Response) CreateMultipartUpload(bucket, key, uploadID string) {
	type result struct {
		XMLName  xml.Name `xml:"InitiateMultipartUploadResult"`
		Bucket   string   `xml:"Bucket"`
		Key      string   `xml:"Key"`
		UploadID string   `xml:"UploadId"`
	}
	r.sendXML(result{Bucket: bucket, Key: key, UploadID: uploadID}, http.StatusOK)
}

// CompleteMultipartUpload sends a CompleteMultipartUploadResult XML response.
func (r *S3Response) CompleteMultipartUpload(bucket, key, uploadID, host, scheme string) {
	type result struct {
		XMLName  xml.Name `xml:"CompleteMultipartUploadResult"`
		Location string   `xml:"Location"`
		Bucket   string   `xml:"Bucket"`
		Key      string   `xml:"Key"`
		UploadID string   `xml:"UploadId"`
	}
	r.sendXML(result{
		Location: fmt.Sprintf("%s://%s/%s/%s", scheme, host, bucket, key),
		Bucket:   bucket,
		Key:      key,
		UploadID: uploadID,
	}, http.StatusOK)
}

// DeleteResultEntry holds a single deleted key or error for bulk delete.
type DeletedKey struct {
	Key string `xml:"Key"`
}

type DeleteError struct {
	Key     string `xml:"Key"`
	Code    string `xml:"Code"`
	Message string `xml:"Message"`
}

// DeleteResult sends a DeleteResult XML response.
func (r *S3Response) DeleteResult(deletedKeys []string, errors []DeleteError) {
	type deletedEntry struct {
		Key string `xml:"Key"`
	}
	type errorEntry struct {
		Key     string `xml:"Key"`
		Code    string `xml:"Code"`
		Message string `xml:"Message"`
	}
	type deleteResult struct {
		XMLName  xml.Name       `xml:"DeleteResult"`
		Deleted  []deletedEntry `xml:"Deleted"`
		Errors   []errorEntry   `xml:"Error"`
	}

	resp := deleteResult{}
	for _, k := range deletedKeys {
		resp.Deleted = append(resp.Deleted, deletedEntry{Key: k})
	}
	for _, e := range errors {
		resp.Errors = append(resp.Errors, errorEntry{Key: e.Key, Code: e.Code, Message: e.Message})
	}
	r.sendXML(resp, http.StatusOK)
}

// SendObjectHeaders sends standard object download headers.
func (r *S3Response) SendObjectHeaders(status int, length int64, mimeType, filename string, attachment bool) {
	r.w.Header().Set("Accept-Ranges", "bytes")
	r.w.Header().Set("Content-Type", mimeType)
	r.w.Header().Set("Content-Length", strconv.FormatInt(length, 10))
	if attachment {
		r.w.Header().Set("Content-Disposition", fmt.Sprintf(`attachment; filename="%s"`, escapeFilename(filename)))
	}
	r.w.Header().Set("Cache-Control", "private")
	r.w.Header().Set("Pragma", "public")
	r.w.WriteHeader(status)
}

// SendRangeHeader sends the Content-Range header for a partial response.
func (r *S3Response) SendRangeHeader(start, end, fileSize int64) {
	r.w.Header().Set("Content-Range", fmt.Sprintf("bytes %d-%d/%d", start, end, fileSize))
}

// SendInvalidRangeHeader sends the Content-Range header for a 416 response.
func (r *S3Response) SendInvalidRangeHeader(fileSize int64) {
	r.w.Header().Set("Content-Range", fmt.Sprintf("bytes */%d", fileSize))
}

// Writer returns the underlying ResponseWriter.
func (r *S3Response) Writer() http.ResponseWriter {
	return r.w
}

func (r *S3Response) sendXML(v interface{}, httpStatus int) {
	r.w.Header().Set("Content-Type", "application/xml")
	r.w.WriteHeader(httpStatus)
	r.w.Write([]byte(xml.Header))
	enc := xml.NewEncoder(r.w)
	enc.Encode(v)
}

// formatLastModified formats a Unix timestamp as S3's LastModified format:
// "2006-01-02T15:04:05.000Z" (always .000 ms since mtime has no sub-second
// precision, matching the PHP format string Y-m-d\TH:i:s.000\Z).
func formatLastModified(timestamp int64) string {
	t := time.Unix(timestamp, 0).UTC()
	return t.Format("2006-01-02T15:04:05.000Z")
}

// escapeFilename escapes backslashes and double quotes for use in a
// Content-Disposition header, matching PHP's addcslashes($filename, "\\\"").
func escapeFilename(filename string) string {
	filename = strings.ReplaceAll(filename, "\\", "\\\\")
	filename = strings.ReplaceAll(filename, "\"", "\\\"")
	return filename
}
