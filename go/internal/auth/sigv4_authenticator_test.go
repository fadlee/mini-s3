package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"testing"
	"time"

	minihttp "github.com/fadlee/mini-s3/internal/http"
)

// ---------------------------------------------------------------------------
// Signing helpers — independent reimplementation of AWS4-HMAC-SHA256,
// mirroring tests/integration/sigv4.php and the PHP test's helpers.
// ---------------------------------------------------------------------------

func testAwsPercentEncode(value string) string {
	var buf strings.Builder
	for _, r := range value {
		switch {
		case r >= 'A' && r <= 'Z', r >= 'a' && r <= 'z', r >= '0' && r <= '9':
			buf.WriteRune(r)
		case r == '-' || r == '_' || r == '.' || r == '~':
			buf.WriteRune(r)
		default:
			for _, b := range []byte(string(r)) {
				fmt.Fprintf(&buf, "%%%02X", b)
			}
		}
	}
	return buf.String()
}

func testAwsPercentDecode(s string) string {
	var buf strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '%' && i+2 < len(s) {
			b, err := hex.DecodeString(s[i+1 : i+3])
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

func testNormalizeHeaderValue(value string) string {
	return strings.TrimSpace(regexp.MustCompile(`\s+`).ReplaceAllString(value, " "))
}

func canonicalURI(path string) string {
	if path == "" {
		path = "/"
	}
	segments := strings.Split(path, "/")
	encoded := make([]string, len(segments))
	for i, seg := range segments {
		encoded[i] = testAwsPercentEncode(testAwsPercentDecode(seg))
	}
	uri := strings.Join(encoded, "/")
	if uri == "" {
		return "/"
	}
	if uri[0] != '/' {
		uri = "/" + uri
	}
	return uri
}

func canonicalQueryString(rawQuery string, excludedKeys []string) string {
	if rawQuery == "" {
		return ""
	}
	exclude := make(map[string]bool)
	for _, k := range excludedKeys {
		exclude[k] = true
	}
	type pair struct{ k, v string }
	var pairs []pair
	for _, p := range strings.Split(rawQuery, "&") {
		if p == "" {
			continue
		}
		parts := strings.SplitN(p, "=", 2)
		dk := testAwsPercentDecode(parts[0])
		dv := ""
		if len(parts) == 2 {
			dv = testAwsPercentDecode(parts[1])
		}
		if exclude[dk] {
			continue
		}
		pairs = append(pairs, pair{testAwsPercentEncode(dk), testAwsPercentEncode(dv)})
	}
	sort.Slice(pairs, func(i, j int) bool {
		if pairs[i].k == pairs[j].k {
			return pairs[i].v < pairs[j].v
		}
		return pairs[i].k < pairs[j].k
	})
	var out []string
	for _, p := range pairs {
		out = append(out, p.k+"="+p.v)
	}
	return strings.Join(out, "&")
}

func calcSignature(secretKey, date, region, service, stringToSign string) string {
	kDate := hmacSHA256Key([]byte("AWS4"+secretKey), date)
	kRegion := hmacSHA256Key(kDate, region)
	kService := hmacSHA256Key(kRegion, service)
	kSigning := hmacSHA256Key(kService, "aws4_request")
	return hex.EncodeToString(hmacSHA256Key(kSigning, stringToSign))
}

func hmacSHA256Key(key []byte, data string) []byte {
	h := hmac.New(sha256.New, key)
	h.Write([]byte(data))
	return h.Sum(nil)
}

func sha256Hex(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

// signHeaderAuth builds a valid Authorization header for a header-auth request.
func signHeaderAuth(method, host, path, query, accessKey, secretKey, payloadHash, amzDate string, region string, signedHeaders []string) string {
	if region == "" {
		region = "us-east-1"
	}
	if signedHeaders == nil {
		signedHeaders = []string{"host", "x-amz-content-sha256", "x-amz-date"}
	}
	date := amzDate[:8]
	scope := date + "/" + region + "/s3/aws4_request"

	headerValues := map[string]string{
		"host":               host,
		"x-amz-content-sha256": payloadHash,
		"x-amz-date":         amzDate,
	}

	sorted := make([]string, len(signedHeaders))
	copy(sorted, signedHeaders)
	sort.Strings(sorted)

	var canonicalHeaders strings.Builder
	for _, name := range sorted {
		canonicalHeaders.WriteString(name)
		canonicalHeaders.WriteString(":")
		canonicalHeaders.WriteString(testNormalizeHeaderValue(headerValues[name]))
		canonicalHeaders.WriteString("\n")
	}
	signedHeadersLine := strings.Join(sorted, ";")

	canonicalRequest := strings.Join([]string{
		strings.ToUpper(method),
		canonicalURI(path),
		canonicalQueryString(query, nil),
		canonicalHeaders.String(),
		signedHeadersLine,
		payloadHash,
	}, "\n")

	stringToSign := strings.Join([]string{
		"AWS4-HMAC-SHA256",
		amzDate,
		scope,
		sha256Hex(canonicalRequest),
	}, "\n")

	signature := calcSignature(secretKey, date, region, "s3", stringToSign)

	return "AWS4-HMAC-SHA256 Credential=" + accessKey + "/" + scope +
		", SignedHeaders=" + signedHeadersLine +
		", Signature=" + signature
}

// signPresigned builds a presigned URL query string including X-Amz-Signature.
func signPresigned(method, host, path, existingQuery, accessKey, secretKey string, expires, offsetSeconds int, region string) string {
	if region == "" {
		region = "us-east-1"
	}
	amzDate := time.Now().UTC().Add(time.Duration(offsetSeconds) * time.Second).Format("20060102T150405Z")
	date := amzDate[:8]
	scope := date + "/" + region + "/s3/aws4_request"

	type pair struct{ k, v string }
	var pairs []pair
	if existingQuery != "" {
		for _, p := range strings.Split(existingQuery, "&") {
			if p == "" {
				continue
			}
			parts := strings.SplitN(p, "=", 2)
			dv := ""
			if len(parts) == 2 {
				dv = testAwsPercentDecode(parts[1])
			}
			pairs = append(pairs, pair{testAwsPercentDecode(parts[0]), dv})
		}
	}
	pairs = append(pairs, pair{"X-Amz-Algorithm", "AWS4-HMAC-SHA256"})
	pairs = append(pairs, pair{"X-Amz-Credential", accessKey + "/" + scope})
	pairs = append(pairs, pair{"X-Amz-Date", amzDate})
	pairs = append(pairs, pair{"X-Amz-Expires", fmt.Sprintf("%d", expires)})
	pairs = append(pairs, pair{"X-Amz-SignedHeaders", "host"})

	// Build canonical query excluding X-Amz-Signature (not yet present).
	encoded := make([]pair, len(pairs))
	for i, p := range pairs {
		encoded[i] = pair{testAwsPercentEncode(p.k), testAwsPercentEncode(p.v)}
	}
	sort.Slice(encoded, func(i, j int) bool {
		if encoded[i].k == encoded[j].k {
			return encoded[i].v < encoded[j].v
		}
		return encoded[i].k < encoded[j].k
	})
	var cqParts []string
	for _, p := range encoded {
		cqParts = append(cqParts, p.k+"="+p.v)
	}
	canonicalQuery := strings.Join(cqParts, "&")

	canonicalRequest := strings.Join([]string{
		strings.ToUpper(method),
		canonicalURI(path),
		canonicalQuery,
		"host:" + testNormalizeHeaderValue(host) + "\n",
		"host",
		"UNSIGNED-PAYLOAD",
	}, "\n")

	stringToSign := strings.Join([]string{
		"AWS4-HMAC-SHA256",
		amzDate,
		scope,
		sha256Hex(canonicalRequest),
	}, "\n")

	signature := calcSignature(secretKey, date, region, "s3", stringToSign)
	pairs = append(pairs, pair{"X-Amz-Signature", signature})

	var final []string
	for _, p := range pairs {
		final = append(final, testAwsPercentEncode(p.k)+"="+testAwsPercentEncode(p.v))
	}
	return strings.Join(final, "&")
}

// makeRequest builds a RequestContext from method, path, query, and headers.
func makeRequest(method, path, query string, headers map[string]string) *minihttp.RequestContext {
	u := &url.URL{
		Path:     path,
		RawQuery: query,
	}
	req := &http.Request{
		Method: method,
		URL:    u,
		Header: http.Header{},
	}
	for k, v := range headers {
		req.Header.Set(k, v)
	}
	return minihttp.NewRequestContext(req)
}

func makeAuth(creds map[string]string, allowedKeys []string, legacy bool, clockSkew, maxPresign int, debugLog string, fallbacks bool) *SigV4Authenticator {
	return New(creds, allowedKeys, legacy, clockSkew, maxPresign, debugLog, fallbacks)
}

func expectAuthException(t *testing.T, fn func() error, expectedS3Code, msg string) {
	t.Helper()
	err := fn()
	if err == nil {
		t.Errorf("[FAIL] %s: expected Exception %s but none returned", msg, expectedS3Code)
		return
	}
	ae, ok := err.(*Exception)
	if !ok {
		t.Errorf("[FAIL] %s: expected *Exception, got %T: %v", msg, err, err)
		return
	}
	if ae.S3Code != expectedS3Code {
		t.Errorf("[FAIL] %s: expected s3code=%s got=%s msg=%s", msg, expectedS3Code, ae.S3Code, ae.Message)
	}
}

func expectNoException(t *testing.T, fn func() error, msg string) {
	t.Helper()
	if err := fn(); err != nil {
		t.Errorf("[FAIL] %s: unexpected error: %v", msg, err)
	}
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

const (
	testAccessKey = "AKIAIOSFODNN7EXAMPLE"
	testSecretKey = "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY"
	testHost      = "example.com"
	testPath      = "/bucket/object.txt"
)

var (
	testCredentials  = map[string]string{testAccessKey: testSecretKey}
	testPayloadHash  = sha256Hex("hello world")
)

func amzDateNow() string {
	return time.Now().UTC().Format("20060102T150405Z")
}

func amzDateOffset(seconds int) string {
	return time.Now().UTC().Add(time.Duration(seconds) * time.Second).Format("20060102T150405Z")
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

func TestHeaderAuthHappyPath(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "valid header auth succeeds")
}

func TestHeaderAuthWithSpaceInPath(t *testing.T) {
	amzDate := amzDateNow()
	path := "/bucket/my file.txt"
	authz := signHeaderAuth("PUT", testHost, path, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("PUT", path, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "valid header auth with space in path succeeds")
}

func TestHeaderAuthWithQueryString(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "prefix=foo&max-keys=10", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, "prefix=foo&max-keys=10", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "valid header auth with query string succeeds")
}

func TestHeaderAuthTamperedSignature(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil) + "deadbeef"
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "SignatureDoesNotMatch", "tampered signature rejected")
}

func TestHeaderAuthWrongHost(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", "other-host.com", testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "SignatureDoesNotMatch", "signature for wrong host rejected without fallback")
}

func TestHeaderAuthUnknownKey(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", "UNKNOWNKEY", testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "InvalidAccessKeyId", "unknown access key id rejected")
}

func TestHeaderAuthMissingAmzDate(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "missing x-amz-date rejected")
}

func TestHeaderAuthMissingPayloadHash(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":          testHost,
			"authorization": authz,
			"x-amz-date":    amzDate,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "missing x-amz-content-sha256 rejected")
}

func TestHeaderAuthClockSkewOld(t *testing.T) {
	oldDate := amzDateOffset(-10000)
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, oldDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           oldDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "RequestTimeTooSkewed", "old timestamp rejected for clock skew")
}

func TestHeaderAuthClockSkewFuture(t *testing.T) {
	futureDate := amzDateOffset(10000)
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, futureDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           futureDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "RequestTimeTooSkewed", "future timestamp rejected for clock skew")
}

func TestHeaderAuthInvalidDateFormat(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           "not-a-date",
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "invalid x-amz-date format rejected")
}

func TestHeaderAuthNonSigV4(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "Bearer some.token",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "non-SigV4 authorization header rejected (no legacy)")
}

func TestHeaderAuthMissingCredential(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 SignedHeaders=host;x-amz-date, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "authorization missing Credential rejected")
}

func TestHeaderAuthMissingSignature(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/s3/aws4_request, SignedHeaders=host",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "authorization missing Signature rejected")
}

func TestCredentialScopeWrongParts(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1, SignedHeaders=host, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "credential scope wrong parts count rejected")
}

func TestCredentialScopeBadDate(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/2026010/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "credential scope bad date rejected")
}

func TestCredentialScopeWrongService(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/ec2/aws4_request, SignedHeaders=host, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "credential scope wrong service rejected")
}

func TestSignedHeadersEmpty(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/s3/aws4_request, SignedHeaders=, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "empty SignedHeaders rejected")
}

func TestSignedHeadersNotSorted(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/s3/aws4_request, SignedHeaders=x-amz-date;host, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "unsorted SignedHeaders rejected")
}

func TestSignedHeadersDuplicate(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/s3/aws4_request, SignedHeaders=host;host, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "duplicate SignedHeaders rejected")
}

func TestSignedHeadersInvalidChars(t *testing.T) {
	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        "AWS4-HMAC-SHA256 Credential=" + testAccessKey + "/20260101/us-east-1/s3/aws4_request, SignedHeaders=host;inval id, Signature=abc",
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "SignedHeaders with invalid chars rejected")
}

func TestMissingSignedHeaderInRequest(t *testing.T) {
	amzDate := amzDateNow()
	authz := signHeaderAuth("GET", testHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-content-sha256": testPayloadHash,
			// x-amz-date deliberately omitted
		})
		return auth.Authenticate(req)
	}, "AccessDenied", "missing signed header in request rejected")
}

// ---------------------------------------------------------------------------
// Presigned URL tests
// ---------------------------------------------------------------------------

func TestPresignedHappyPath(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 0, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "valid presigned URL succeeds")
}

func TestPresignedWithExistingQuery(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "prefix=foo", testAccessKey, testSecretKey, 3600, 0, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "valid presigned URL with existing query succeeds")
}

func TestPresignedTamperedSignature(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 0, "")
	q = regexp.MustCompile(`X-Amz-Signature=([0-9a-f]+)`).ReplaceAllString(q, "X-Amz-Signature=deadbeef")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "SignatureDoesNotMatch", "tampered presigned signature rejected")
}

func TestPresignedExpired(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, -7200, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "ExpiredToken", "expired presigned URL rejected")
}

func TestPresignedFutureDated(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 10000, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "RequestTimeTooSkewed", "future-dated presigned URL rejected")
}

func TestPresignedExpiresZero(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 0, 0, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "presigned expires=0 rejected")
}

func TestPresignedExpiresOverMax(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 604801, 0, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "presigned expires > max rejected")
}

func TestPresignedMissingDate(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 0, "")
	q = regexp.MustCompile(`&X-Amz-Date=[^&]+`).ReplaceAllString(q, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "presigned missing X-Amz-Date rejected")
}

func TestPresignedMissingSignature(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 0, "")
	q = regexp.MustCompile(`&X-Amz-Signature=[^&]+`).ReplaceAllString(q, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "presigned missing X-Amz-Signature rejected")
}

func TestPresignedWrongAlgorithm(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, testSecretKey, 3600, 0, "")
	q = strings.Replace(q, "X-Amz-Algorithm=AWS4-HMAC-SHA256", "X-Amz-Algorithm=AWS4-HMAC-SHA1", 1)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AuthorizationQueryParametersError", "presigned wrong algorithm rejected")
}

func TestPresignedUnknownKey(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", "UNKNOWNKEY", testSecretKey, 3600, 0, "")
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "InvalidAccessKeyId", "presigned unknown access key id rejected")
}

// ---------------------------------------------------------------------------
// Legacy access-key-only mode
// ---------------------------------------------------------------------------

func TestLegacyModeAcceptsWhitelistedKey(t *testing.T) {
	auth := makeAuth(testCredentials, []string{testAccessKey}, true, 900, 604800, "", false)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":          testHost,
			"authorization": "CustomAuth Credential=" + testAccessKey + "/scope",
		})
		return auth.Authenticate(req)
	}, "legacy access-key-only mode accepts whitelisted key")
}

func TestLegacyModeRejectsNonWhitelistedKey(t *testing.T) {
	amzDate := amzDateNow()
	authz := "AWS4-HMAC-SHA256 Credential=OTHERKEY/20260101/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=abc"
	auth := makeAuth(testCredentials, []string{testAccessKey}, true, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
		})
		return auth.Authenticate(req)
	}, "InvalidAccessKeyId", "legacy mode rejects key not in whitelist and not in credentials")
}

func TestNoAuthWhenLegacyDisabled(t *testing.T) {
	auth := makeAuth(testCredentials, []string{testAccessKey}, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AccessDenied", "no auth at all rejected when legacy disabled")
}

func TestNoAuthEvenWhenLegacyEnabled(t *testing.T) {
	auth := makeAuth(testCredentials, []string{testAccessKey}, true, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "AccessDenied", "no auth at all rejected even when legacy enabled")
}

func TestLegacyDoesNotBypassPresigned(t *testing.T) {
	q := signPresigned("GET", testHost, testPath, "", testAccessKey, "wrong-secret", 3600, 0, "")
	auth := makeAuth(testCredentials, []string{testAccessKey}, true, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, q, map[string]string{"host": testHost})
		return auth.Authenticate(req)
	}, "SignatureDoesNotMatch", "legacy mode does not bypass presigned signature verification")
}

// ---------------------------------------------------------------------------
// Host candidate fallbacks
// ---------------------------------------------------------------------------

func TestHostFallbackViaForwardedHost(t *testing.T) {
	amzDate := amzDateNow()
	forwardedHost := "public.example.com"
	authz := signHeaderAuth("GET", forwardedHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", true)

	expectNoException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
			"x-forwarded-host":     forwardedHost,
		})
		return auth.Authenticate(req)
	}, "host candidate fallback authenticates via X-Forwarded-Host")
}

func TestHostFallbackDisabled(t *testing.T) {
	amzDate := amzDateNow()
	forwardedHost := "public.example.com"
	authz := signHeaderAuth("GET", forwardedHost, testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)
	auth := makeAuth(testCredentials, nil, false, 900, 604800, "", false)

	expectAuthException(t, func() error {
		req := makeRequest("GET", testPath, "", map[string]string{
			"host":                 testHost,
			"authorization":        authz,
			"x-amz-date":           amzDate,
			"x-amz-content-sha256": testPayloadHash,
			"x-forwarded-host":     forwardedHost,
		})
		return auth.Authenticate(req)
	}, "SignatureDoesNotMatch", "host candidate fallback disabled rejects forwarded-host signature")
}

// ---------------------------------------------------------------------------
// Auth debug log
// ---------------------------------------------------------------------------

func TestAuthDebugLog(t *testing.T) {
	tmpDir := t.TempDir()
	logPath := filepath.Join(tmpDir, "auth-debug.jsonl")

	amzDate := amzDateNow()
	auth := makeAuth(testCredentials, nil, false, 900, 604800, logPath, false)
	badAuthz := signHeaderAuth("GET", "wrong-host.com", testPath, "", testAccessKey, testSecretKey, testPayloadHash, amzDate, "", nil)

	req := makeRequest("GET", testPath, "", map[string]string{
		"host":                 testHost,
		"authorization":        badAuthz,
		"x-amz-date":           amzDate,
		"x-amz-content-sha256": testPayloadHash,
	})
	_ = auth.Authenticate(req) // expected to fail

	data, err := os.ReadFile(logPath)
	if err != nil {
		t.Fatalf("auth debug log not written: %v", err)
	}
	if len(data) == 0 {
		t.Fatal("auth debug log is empty")
	}
	// Verify it's valid JSON with mode=authorization
	// (just check it contains "authorization" — full JSON parse omitted for simplicity)
	if !strings.Contains(string(data), `"authorization"`) {
		t.Error("auth debug log record does not have correct mode")
	}
	if !strings.Contains(string(data), `"attempts"`) {
		t.Error("auth debug log does not record attempts")
	}
}


