package s3

import (
	"crypto/md5"
	"crypto/rand"
	"encoding/hex"
	"encoding/xml"
	"fmt"
	"io"
	"net/http"
	"os"
	"sort"
	"strings"

	"github.com/fadlee/mini-s3/internal/auth"
	minihttp "github.com/fadlee/mini-s3/internal/http"
	"github.com/fadlee/mini-s3/internal/storage"
)

// S3Router dispatches S3-compatible HTTP requests, mirroring
// MiniS3\S3\S3Router from the PHP reference.
type S3Router struct {
	storage             *storage.FileStorage
	validator           *RequestValidator
	authenticator       *auth.SigV4Authenticator
	maxRequestSize      int64
	publicReadAllBuckets bool
}

// New creates an S3Router.
func New(st *storage.FileStorage, authenticator *auth.SigV4Authenticator, maxRequestSize int64, publicReadAllBuckets bool) *S3Router {
	return &S3Router{
		storage:             st,
		validator:           &RequestValidator{},
		authenticator:       authenticator,
		maxRequestSize:      maxRequestSize,
		publicReadAllBuckets: publicReadAllBuckets,
	}
}

// ServeHTTP implements http.Handler.
func (r *S3Router) ServeHTTP(w http.ResponseWriter, req *http.Request) {
	ctx := minihttp.NewRequestContext(req)
	resp := NewS3Response(w)

	r.sendCorsHeaders(w, ctx)

	if err := r.storage.EnsureDataDirExists(); err != nil {
		resp.XMLError(500, "InternalError", "Internal server error", "")
		return
	}

	bucket, key := r.extractBucketAndKey(ctx)
	method := ctx.Method()

	if method == "OPTIONS" {
		w.WriteHeader(http.StatusNoContent)
		return
	}

	if err := r.validateBucketAndKey(method, bucket, key, resp); err != nil {
		return
	}

	if r.isOversizedRequest(ctx) {
		resp.XMLError(413, "EntityTooLarge", "Request too large", "")
		return
	}

	if !r.isPublicRead(method) {
		if err := r.authenticator.Authenticate(ctx); err != nil {
			if ae, ok := err.(*auth.Exception); ok {
				resp.XMLError(ae.HTTPStatus, ae.S3Code, ae.Message, "")
			} else {
				resp.XMLError(500, "InternalError", "Internal server error", "")
			}
			return
		}
	}

	switch method {
	case "PUT":
		r.handlePut(ctx, resp, bucket, key)
	case "POST":
		r.handlePost(ctx, resp, bucket, key)
	case "GET":
		r.handleGet(ctx, resp, bucket, key)
	case "HEAD":
		r.handleHead(ctx, resp, bucket, key)
	case "DELETE":
		r.handleDelete(ctx, resp, bucket, key)
	default:
		resp.XMLError(405, "MethodNotAllowed", "Method not allowed", r.resource(bucket, key))
	}
}

func (r *S3Router) isPublicRead(method string) bool {
	return r.publicReadAllBuckets && (method == "GET" || method == "HEAD")
}

func (r *S3Router) sendCorsHeaders(w http.ResponseWriter, ctx *minihttp.RequestContext) {
	origin := ctx.GetHeader("origin")
	if origin == "" {
		return
	}
	w.Header().Set("Access-Control-Allow-Origin", origin)
	w.Header().Add("Vary", "Origin")
	w.Header().Set("Access-Control-Allow-Methods", "GET, HEAD, PUT, POST, DELETE, OPTIONS")
	w.Header().Set("Access-Control-Expose-Headers", "ETag, Content-Length, Content-Range")

	requestHeaders := ctx.GetHeader("access-control-request-headers")
	if requestHeaders != "" {
		w.Header().Set("Access-Control-Allow-Headers", requestHeaders)
	}
	w.Header().Set("Access-Control-Max-Age", "86400")
}

func (r *S3Router) handlePut(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	uploadID := ctx.GetQueryParam("uploadId")
	partNumber := ctx.GetQueryParam("partNumber")

	if uploadID != "" && partNumber != "" {
		if !r.validator.IsPositiveInteger(partNumber) {
			resp.XMLError(400, "InvalidPart", "partNumber must be a positive integer", r.resource(bucket, key))
			return
		}
		if !r.storage.MultipartDirExists(bucket, key, uploadID) {
			resp.XMLError(404, "NoSuchUpload", "Upload ID not found", r.resource(bucket, key))
			return
		}

		partNum := atoiSafe(partNumber)
		partPath, err := r.storage.PutMultipartPart(bucket, key, uploadID, partNum, ctx.Request().Body)
		if err != nil {
			resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
			return
		}

		etag := md5File(partPath)
		resp.Writer().Header().Set("ETag", etag)
		resp.Writer().WriteHeader(http.StatusOK)
		return
	}

	if err := r.storage.PutObject(bucket, key, ctx.Request().Body); err != nil {
		resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
		return
	}
	resp.Writer().WriteHeader(http.StatusOK)
}

func (r *S3Router) handlePost(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	if ctx.HasQueryParam("delete") {
		r.handleBulkDelete(ctx, resp, bucket, key)
		return
	}

	if ctx.HasQueryParam("uploads") {
		uploadID := randomHex(16)
		if err := r.storage.CreateMultipartUpload(bucket, key, uploadID); err != nil {
			resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
			return
		}
		resp.CreateMultipartUpload(bucket, key, uploadID)
		return
	}

	uploadID := ctx.GetQueryParam("uploadId")
	if uploadID != "" {
		r.handleCompleteMultipart(ctx, resp, bucket, key, uploadID)
		return
	}

	resp.XMLError(400, "InvalidRequest", "Invalid POST request: missing delete, uploads or uploadId parameter", r.resource(bucket, key))
}

func (r *S3Router) handleBulkDelete(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	body, err := io.ReadAll(ctx.Request().Body)
	if err != nil || strings.TrimSpace(string(body)) == "" {
		resp.XMLError(400, "MalformedXML", "The XML you provided was not well-formed or did not validate against our published schema.", r.resource(bucket, key))
		return
	}

	type object struct {
		Key string `xml:"Key"`
	}
	type deleteRequest struct {
		XMLName xml.Name `xml:"Delete"`
		Quiet   string   `xml:"Quiet"`
		Objects []object `xml:"Object"`
	}

	var req deleteRequest
	if err := xml.Unmarshal(body, &req); err != nil {
		resp.XMLError(400, "MalformedXML", "The XML you provided was not well-formed or did not validate against our published schema.", r.resource(bucket, key))
		return
	}

	quiet := strings.ToLower(req.Quiet) == "true"
	var deletedKeys []string
	var errors []DeleteError

	for _, obj := range req.Objects {
		objectKey := obj.Key
		if !r.validator.IsValidObjectKey(objectKey) {
			errors = append(errors, DeleteError{
				Key:     objectKey,
				Code:    "InvalidObjectKey",
				Message: "Invalid object key",
			})
			continue
		}
		r.storage.DeleteObject(bucket, objectKey)
		if !quiet {
			deletedKeys = append(deletedKeys, objectKey)
		}
	}

	resp.DeleteResult(deletedKeys, errors)
}

func (r *S3Router) handleCompleteMultipart(ctx *minihttp.RequestContext, resp *S3Response, bucket, key, uploadID string) {
	if !r.storage.MultipartDirExists(bucket, key, uploadID) {
		resp.XMLError(404, "NoSuchUpload", "Upload ID not found", r.resource(bucket, key))
		return
	}

	body, err := io.ReadAll(ctx.Request().Body)
	if err != nil || strings.TrimSpace(string(body)) == "" {
		resp.XMLError(400, "MalformedXML", "The XML you provided was not well-formed or did not validate against our published schema.", r.resource(bucket, key))
		return
	}

	type part struct {
		PartNumber string `xml:"PartNumber"`
	}
	type completeRequest struct {
		XMLName xml.Name `xml:"CompleteMultipartUpload"`
		Parts   []part   `xml:"Part"`
	}

	var req completeRequest
	if err := xml.Unmarshal(body, &req); err != nil {
		resp.XMLError(400, "MalformedXML", "The XML you provided was not well-formed or did not validate against our published schema.", r.resource(bucket, key))
		return
	}

	var parts []int
	for _, p := range req.Parts {
		if !r.validator.IsPositiveInteger(p.PartNumber) {
			resp.XMLError(400, "InvalidPart", "PartNumber must be a positive integer", r.resource(bucket, key))
			return
		}
		parts = append(parts, atoiSafe(p.PartNumber))
	}

	if len(parts) == 0 {
		resp.XMLError(400, "InvalidPart", "Multipart completion request has no parts", r.resource(bucket, key))
		return
	}

	parts = uniqueInts(parts)
	sort.Ints(parts)

	if err := r.storage.CompleteMultipartUpload(bucket, key, uploadID, parts); err != nil {
		if strings.HasPrefix(err.Error(), "Part file missing") {
			resp.XMLError(400, "InvalidPart", err.Error(), r.resource(bucket, key))
			return
		}
		resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
		return
	}

	r.storage.CleanupMultipartUpload(bucket, key, uploadID)

	scheme := ctx.GetScheme()
	resp.CompleteMultipartUpload(bucket, key, uploadID, ctx.GetHost(), scheme)
}

func (r *S3Router) handleGet(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	if key == "" {
		prefix := ctx.GetQueryParam("prefix")
		files, err := r.storage.ListFiles(bucket, prefix)
		if err != nil {
			resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
			return
		}
		resp.ListObjects(files, bucket, prefix)
		return
	}

	metadata, err := r.storage.ObjectMetadata(bucket, key)
	if err != nil {
		resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
		return
	}
	if metadata == nil {
		resp.XMLError(404, "NoSuchKey", "Object not found", r.resource(bucket, key))
		return
	}

	fileSize := metadata.Size
	f, err := r.storage.OpenObjectReadStream(bucket, key)
	if err != nil {
		resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
		return
	}
	defer f.Close()

	mimeType := metadata.MimeType
	rangeHeader := ctx.GetHeader("range")

	start := int64(0)
	end := fileSize - 1
	if end < 0 {
		end = 0
	}
	length := fileSize
	status := 200

	if rangeHeader != "" {
		result := r.validator.ParseRange(rangeHeader, fileSize)
		if !result.Valid {
			resp.SendInvalidRangeHeader(fileSize)
			resp.Writer().WriteHeader(416)
			return
		}
		status = 206
		start = result.Start
		end = result.End
		if fileSize == 0 {
			length = 0
		} else {
			length = end - start + 1
		}
		resp.SendRangeHeader(start, end, fileSize)
	}

	resp.SendObjectHeaders(status, length, mimeType, baseName(key), true)

	if length == 0 {
		return
	}

	f.Seek(start, io.SeekStart)
	io.CopyN(resp.Writer(), f, length)
}

func (r *S3Router) handleHead(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	if key == "" {
		resp.XMLError(400, "InvalidRequest", "Object key required for HEAD", r.resource(bucket, key))
		return
	}

	metadata, err := r.storage.ObjectMetadata(bucket, key)
	if err != nil {
		resp.XMLError(500, "InternalError", "Internal server error", r.resource(bucket, key))
		return
	}
	if metadata == nil {
		resp.XMLError(404, "NoSuchKey", "Resource not found", r.resource(bucket, key))
		return
	}

	resp.SendObjectHeaders(200, metadata.Size, metadata.MimeType, baseName(key), false)
}

func (r *S3Router) handleDelete(ctx *minihttp.RequestContext, resp *S3Response, bucket, key string) {
	uploadID := ctx.GetQueryParam("uploadId")
	if uploadID != "" {
		if !r.storage.MultipartDirExists(bucket, key, uploadID) {
			resp.XMLError(404, "NoSuchUpload", "Upload ID not found", r.resource(bucket, key))
			return
		}
		r.storage.AbortMultipartUpload(bucket, key, uploadID)
		resp.Writer().WriteHeader(http.StatusNoContent)
		return
	}

	r.storage.DeleteObject(bucket, key)
	resp.Writer().WriteHeader(http.StatusNoContent)
}

func (r *S3Router) validateBucketAndKey(method, bucket, key string, resp *S3Response) error {
	if method != "GET" && bucket == "" {
		resp.XMLError(400, "InvalidBucketName", "Bucket name not specified", "/")
		return fmt.Errorf("bucket name not specified")
	}
	if method == "GET" && bucket == "" {
		resp.XMLError(400, "InvalidBucketName", "Bucket name required", "/")
		return fmt.Errorf("bucket name required")
	}
	if bucket != "" && !r.validator.IsValidBucketName(bucket) {
		resp.XMLError(400, "InvalidBucketName", "Invalid bucket name", "/"+bucket)
		return fmt.Errorf("invalid bucket name")
	}
	if key != "" && !r.validator.IsValidObjectKey(key) {
		resp.XMLError(400, "InvalidObjectKey", "Invalid object key", r.resource(bucket, key))
		return fmt.Errorf("invalid object key")
	}
	return nil
}

func (r *S3Router) isOversizedRequest(ctx *minihttp.RequestContext) bool {
	contentLength := ctx.GetHeader("content-length")
	return r.validator.IsOversizedRequest(contentLength, r.maxRequestSize)
}

func (r *S3Router) extractBucketAndKey(ctx *minihttp.RequestContext) (string, string) {
	trimmedPath := strings.Trim(ctx.Path(), "/")
	if trimmedPath == "" {
		return "", ""
	}

	rawParts := strings.Split(trimmedPath, "/")
	decodedParts := make([]string, len(rawParts))
	for i, part := range rawParts {
		decodedParts[i] = decodeURIComponent(part)
	}

	bucket := decodedParts[0]
	key := strings.Join(decodedParts[1:], "/")
	return bucket, key
}

func (r *S3Router) resource(bucket, key string) string {
	if bucket == "" {
		return "/"
	}
	if key == "" {
		return "/" + bucket
	}
	return "/" + bucket + "/" + key
}

// --- helpers ---

func md5File(path string) string {
	f, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()
	h := md5.New()
	io.Copy(h, f)
	return hex.EncodeToString(h.Sum(nil))
}

func randomHex(n int) string {
	b := make([]byte, n)
	rand.Read(b)
	return hex.EncodeToString(b)
}

func baseName(key string) string {
	if idx := strings.LastIndex(key, "/"); idx != -1 {
		return key[idx+1:]
	}
	return key
}

func atoiSafe(s string) int {
	var n int
	for _, c := range s {
		if c < '0' || c > '9' {
			return 0
		}
		n = n*10 + int(c-'0')
	}
	return n
}

func uniqueInts(slice []int) []int {
	seen := make(map[int]bool)
	var result []int
	for _, v := range slice {
		if !seen[v] {
			seen[v] = true
			result = append(result, v)
		}
	}
	return result
}

func decodeURIComponent(s string) string {
	// Simple percent-decoding for path segments.
	var buf strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '%' && i+2 < len(s) {
			h := s[i+1 : i+3]
			b, err := hex.DecodeString(h)
			if err == nil && len(b) == 1 {
				buf.WriteByte(b[0])
				i += 2
				continue
			}
		}
		buf.WriteByte(s[i])
	}
	return buf.String()
}
