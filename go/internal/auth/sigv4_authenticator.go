package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"

	minihttp "github.com/fadlee/mini-s3/internal/http"
)

const algorithm = "AWS4-HMAC-SHA256"

// SigV4Authenticator verifies AWS Signature V4 requests, mirroring
// MiniS3\Auth\SigV4Authenticator from the PHP reference.
type SigV4Authenticator struct {
	credentials                 map[string]string
	allowedAccessKeys           map[string]bool
	allowLegacyAccessKeyOnly    bool
	clockSkewSeconds            int
	maxPresignExpires           int
	authDebugLogPath            string
	allowHostCandidateFallbacks bool
}

// New creates a SigV4Authenticator.
func New(credentials map[string]string, allowedAccessKeys []string, allowLegacyAccessKeyOnly bool, clockSkewSeconds, maxPresignExpires int, authDebugLogPath string, allowHostCandidateFallbacks bool) *SigV4Authenticator {
	creds := make(map[string]string, len(credentials))
	for k, v := range credentials {
		creds[k] = v
	}
	allowed := make(map[string]bool, len(allowedAccessKeys))
	for _, k := range allowedAccessKeys {
		allowed[k] = true
	}
	return &SigV4Authenticator{
		credentials:                 creds,
		allowedAccessKeys:           allowed,
		allowLegacyAccessKeyOnly:    allowLegacyAccessKeyOnly,
		clockSkewSeconds:            clockSkewSeconds,
		maxPresignExpires:           maxPresignExpires,
		authDebugLogPath:            authDebugLogPath,
		allowHostCandidateFallbacks: allowHostCandidateFallbacks,
	}
}

// Authenticate verifies the request's SigV4 signature (header or presigned).
func (a *SigV4Authenticator) Authenticate(req *minihttp.RequestContext) error {
	if a.isPresignedRequest(req) {
		return a.authenticatePresignedRequest(req)
	}

	authorization := req.GetHeader("authorization")
	if authorization != "" && strings.HasPrefix(authorization, algorithm) {
		return a.authenticateAuthorizationHeaderRequest(req, authorization)
	}

	if a.allowLegacyAccessKeyOnly {
		legacyAccessKey := a.extractAccessKeyID(req)
		if legacyAccessKey != "" && a.allowedAccessKeys[legacyAccessKey] {
			return nil
		}
	}

	return NewException("AccessDenied", "Missing or unsupported authentication credentials")
}

func (a *SigV4Authenticator) authenticateAuthorizationHeaderRequest(req *minihttp.RequestContext, authorization string) error {
	authParams, err := a.parseAuthorizationHeader(authorization)
	if err != nil {
		return err
	}

	credentialScope, err := a.parseCredential(authParams["Credential"])
	if err != nil {
		return err
	}

	accessKeyID := credentialScope.accessKeyID
	secretKey, ok := a.credentials[accessKeyID]
	if !ok {
		return NewException("InvalidAccessKeyId", "The AWS Access Key Id you provided does not exist in our records.")
	}

	amzDate := req.GetHeader("x-amz-date")
	if amzDate == "" {
		return NewException("AccessDenied", "Missing required header x-amz-date")
	}

	requestTime, err := parseAmzDate(amzDate)
	if err != nil {
		return err
	}
	if err := a.validateHeaderTimestamp(requestTime); err != nil {
		return err
	}

	payloadHash := req.GetHeader("x-amz-content-sha256")
	if payloadHash == "" {
		return NewException("AccessDenied", "Missing required header x-amz-content-sha256")
	}

	signedHeaders, err := a.parseSignedHeaders(authParams["SignedHeaders"])
	if err != nil {
		return err
	}

	signature := strings.ToLower(authParams["Signature"])
	if signature == "" {
		return NewException("AccessDenied", "Missing Signature in Authorization header")
	}

	scope := fmt.Sprintf("%s/%s/%s/aws4_request", credentialScope.date, credentialScope.region, credentialScope.service)

	attempts := []signatureAttempt{}
	matched, err := a.signatureMatchesAnyHostCandidate(req, signedHeaders, payloadHash, true, amzDate, scope, credentialScope, secretKey, signature, &attempts)
	if err != nil {
		return err
	}
	if !matched {
		a.logSignatureMismatch("authorization", req, signedHeaders, signature, attempts)
		return NewException("SignatureDoesNotMatch", "The request signature we calculated does not match the signature you provided.")
	}
	return nil
}

func (a *SigV4Authenticator) authenticatePresignedRequest(req *minihttp.RequestContext) error {
	algo := req.GetQueryParam("X-Amz-Algorithm")
	if algo != algorithm {
		return NewException("AuthorizationQueryParametersError", "Unsupported X-Amz-Algorithm in query string")
	}

	credentialScope, err := a.parseCredential(req.GetQueryParam("X-Amz-Credential"))
	if err != nil {
		return err
	}

	accessKeyID := credentialScope.accessKeyID
	secretKey, ok := a.credentials[accessKeyID]
	if !ok {
		return NewException("InvalidAccessKeyId", "The AWS Access Key Id you provided does not exist in our records.")
	}

	amzDate := req.GetQueryParam("X-Amz-Date")
	if amzDate == "" {
		return NewException("AuthorizationQueryParametersError", "Missing X-Amz-Date query parameter")
	}

	requestTime, err := parseAmzDate(amzDate)
	if err != nil {
		return err
	}

	expiresRaw := req.GetQueryParam("X-Amz-Expires")
	if expiresRaw == "" || !isAllDigits(expiresRaw) {
		return NewException("AuthorizationQueryParametersError", "Invalid X-Amz-Expires query parameter")
	}

	expires, _ := atoi(expiresRaw)
	if expires < 1 || expires > a.maxPresignExpires {
		return NewException("AuthorizationQueryParametersError", "X-Amz-Expires out of allowed range")
	}

	if err := a.validatePresignedTimestamp(requestTime, expires); err != nil {
		return err
	}

	signedHeaders, err := a.parseSignedHeaders(req.GetQueryParam("X-Amz-SignedHeaders"))
	if err != nil {
		return err
	}

	providedSignature := strings.ToLower(req.GetQueryParam("X-Amz-Signature"))
	if providedSignature == "" {
		return NewException("AuthorizationQueryParametersError", "Missing X-Amz-Signature query parameter")
	}

	scope := fmt.Sprintf("%s/%s/%s/aws4_request", credentialScope.date, credentialScope.region, credentialScope.service)

	attempts := []signatureAttempt{}
	matched, err := a.signatureMatchesAnyHostCandidate(req, signedHeaders, "UNSIGNED-PAYLOAD", false, amzDate, scope, credentialScope, secretKey, providedSignature, &attempts)
	if err != nil {
		return err
	}
	if !matched {
		a.logSignatureMismatch("presign", req, signedHeaders, providedSignature, attempts)
		return NewException("SignatureDoesNotMatch", "The request signature we calculated does not match the signature you provided.")
	}
	return nil
}

func (a *SigV4Authenticator) parseAuthorizationHeader(authorization string) (map[string]string, error) {
	if !strings.HasPrefix(authorization, algorithm+" ") {
		return nil, NewException("AccessDenied", "Authorization algorithm is not supported")
	}

	paramsRaw := authorization[len(algorithm)+1:]
	parts := strings.Split(paramsRaw, ",")
	params := make(map[string]string)
	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed == "" {
			continue
		}
		pair := strings.SplitN(trimmed, "=", 2)
		if len(pair) != 2 {
			return nil, NewException("AccessDenied", "Malformed Authorization header")
		}
		params[pair[0]] = pair[1]
	}

	for _, required := range []string{"Credential", "SignedHeaders", "Signature"} {
		if v, ok := params[required]; !ok || strings.TrimSpace(v) == "" {
			return nil, NewException("AccessDenied", "Malformed Authorization header: missing "+required)
		}
	}
	return params, nil
}

type credentialScope struct {
	accessKeyID string
	date        string
	region      string
	service     string
}

func (a *SigV4Authenticator) parseCredential(credential string) (credentialScope, error) {
	if credential == "" {
		return credentialScope{}, NewException("AuthorizationQueryParametersError", "Missing Credential scope")
	}

	parts := strings.Split(credential, "/")
	if len(parts) != 5 {
		return credentialScope{}, NewException("AuthorizationQueryParametersError", "Credential scope format is invalid")
	}

	accessKeyID, date, region, service, terminal := parts[0], parts[1], parts[2], parts[3], parts[4]
	if accessKeyID == "" || date == "" || region == "" || service == "" || terminal == "" {
		return credentialScope{}, NewException("AuthorizationQueryParametersError", "Credential scope is incomplete")
	}

	if !dateRe.MatchString(date) {
		return credentialScope{}, NewException("AuthorizationQueryParametersError", "Credential date is invalid")
	}

	if service != "s3" || terminal != "aws4_request" {
		return credentialScope{}, NewException("AuthorizationQueryParametersError", "Credential scope service must be s3/aws4_request")
	}

	return credentialScope{accessKeyID: accessKeyID, date: date, region: region, service: service}, nil
}

var dateRe = regexp.MustCompile(`^\d{8}$`)
var signedHeaderRe = regexp.MustCompile(`^[a-z0-9-]+$`)

func (a *SigV4Authenticator) parseSignedHeaders(signedHeaders string) ([]string, error) {
	items := strings.Split(strings.ToLower(strings.TrimSpace(signedHeaders)), ";")
	var normalized []string
	for _, item := range items {
		if item == "" {
			continue
		}
		if !signedHeaderRe.MatchString(item) {
			return nil, NewException("AuthorizationQueryParametersError", "SignedHeaders contains invalid header names")
		}
		normalized = append(normalized, item)
	}
	if len(normalized) == 0 {
		return nil, NewException("AuthorizationQueryParametersError", "SignedHeaders must not be empty")
	}

	unique := uniqueStrings(normalized)
	sortedCopy := make([]string, len(unique))
	copy(sortedCopy, unique)
	sort.Strings(sortedCopy)

	if !sliceEqual(sortedCopy, normalized) {
		return nil, NewException("AuthorizationQueryParametersError", "SignedHeaders must be lowercase, unique, and sorted")
	}
	return unique, nil
}

func parseAmzDate(amzDate string) (time.Time, error) {
	t, err := time.Parse("20060102T150405Z", amzDate)
	if err != nil {
		return time.Time{}, NewException("AccessDenied", "Invalid x-amz-date format")
	}
	return t, nil
}

func (a *SigV4Authenticator) validateHeaderTimestamp(requestTime time.Time) error {
	now := time.Now().UTC()
	diff := now.Sub(requestTime)
	if diff < 0 {
		diff = -diff
	}
	if diff > time.Duration(a.clockSkewSeconds)*time.Second {
		return NewException("RequestTimeTooSkewed", "Request timestamp is outside allowed clock skew")
	}
	return nil
}

func (a *SigV4Authenticator) validatePresignedTimestamp(requestTime time.Time, expires int) error {
	now := time.Now().UTC()
	if requestTime.After(now.Add(time.Duration(a.clockSkewSeconds) * time.Second)) {
		return NewException("RequestTimeTooSkewed", "Request timestamp is too far in the future")
	}
	if now.After(requestTime.Add(time.Duration(expires) * time.Second)) {
		return NewException("ExpiredToken", "Request has expired")
	}
	return nil
}

func (a *SigV4Authenticator) buildCanonicalRequest(req *minihttp.RequestContext, signedHeaders []string, payloadHash string, includeAllQueryParams bool, hostOverride string) (string, error) {
	canonicalURI := buildCanonicalURI(req.Path())
	var excludedKeys []string
	if !includeAllQueryParams {
		excludedKeys = []string{"X-Amz-Signature"}
	}
	canonicalQuery := buildCanonicalQueryString(req.RawQueryString(), excludedKeys)
	canonicalHeaders, signedHeadersLine, err := a.buildCanonicalHeaders(req, signedHeaders, hostOverride)
	if err != nil {
		return "", err
	}

	return strings.Join([]string{
		req.Method(),
		canonicalURI,
		canonicalQuery,
		canonicalHeaders,
		signedHeadersLine,
		payloadHash,
	}, "\n"), nil
}

func (a *SigV4Authenticator) buildCanonicalHeaders(req *minihttp.RequestContext, signedHeaders []string, hostOverride string) (string, string, error) {
	headers := make(map[string]string)
	hdrs := req.GetHeaders()
	for _, name := range signedHeaders {
		var value string
		if name == "host" {
			if hostOverride != "" {
				value = hostOverride
			} else {
				value = req.GetHost()
			}
		} else {
			value = req.GetHeader(name)
			if value == "" {
				if _, exists := hdrs[name]; !exists {
					return "", "", NewException("AccessDenied", "Signed header is missing: "+name)
				}
			}
		}
		headers[name] = normalizeHeaderValue(value)
	}

	keys := make([]string, 0, len(headers))
	for k := range headers {
		keys = append(keys, k)
	}
	sort.Strings(keys)

	var canonicalHeaders strings.Builder
	for _, k := range keys {
		canonicalHeaders.WriteString(k)
		canonicalHeaders.WriteString(":")
		canonicalHeaders.WriteString(headers[k])
		canonicalHeaders.WriteString("\n")
	}

	return canonicalHeaders.String(), strings.Join(keys, ";"), nil
}

func buildCanonicalURI(path string) string {
	segments := strings.Split(path, "/")
	encodedSegments := make([]string, len(segments))
	for i, segment := range segments {
		decoded := awsPercentDecode(segment)
		encodedSegments[i] = awsPercentEncode(decoded)
	}
	canonicalURI := strings.Join(encodedSegments, "/")
	if canonicalURI == "" {
		return "/"
	}
	if canonicalURI[0] != '/' {
		canonicalURI = "/" + canonicalURI
	}
	return canonicalURI
}

func buildCanonicalQueryString(rawQuery string, excludedKeys []string) string {
	if rawQuery == "" {
		return ""
	}

	excludeMap := make(map[string]bool)
	for _, k := range excludedKeys {
		excludeMap[k] = true
	}

	type pair struct{ key, value string }
	var pairs []pair

	for _, p := range strings.Split(rawQuery, "&") {
		if p == "" {
			continue
		}
		parts := strings.SplitN(p, "=", 2)
		rawKey := parts[0]
		rawValue := ""
		if len(parts) == 2 {
			rawValue = parts[1]
		}

		decodedKey := awsPercentDecode(rawKey)
		decodedValue := awsPercentDecode(rawValue)

		if excludeMap[decodedKey] {
			continue
		}

		pairs = append(pairs, pair{
			key:   awsPercentEncode(decodedKey),
			value: awsPercentEncode(decodedValue),
		})
	}

	sort.Slice(pairs, func(i, j int) bool {
		if pairs[i].key == pairs[j].key {
			return pairs[i].value < pairs[j].value
		}
		return pairs[i].key < pairs[j].key
	})

	var parts []string
	for _, p := range pairs {
		parts = append(parts, p.key+"="+p.value)
	}
	return strings.Join(parts, "&")
}

func buildStringToSign(amzDate, scope, canonicalRequest string) string {
	h := sha256.Sum256([]byte(canonicalRequest))
	return strings.Join([]string{
		algorithm,
		amzDate,
		scope,
		hex.EncodeToString(h[:]),
	}, "\n")
}

func calculateSignature(secretKey string, scope credentialScope, stringToSign string) string {
	kDate := hmacSHA256([]byte("AWS4"+secretKey), scope.date)
	kRegion := hmacSHA256(kDate, scope.region)
	kService := hmacSHA256(kRegion, scope.service)
	kSigning := hmacSHA256(kService, "aws4_request")
	return hex.EncodeToString(hmacSHA256(kSigning, stringToSign))
}

func hmacSHA256(key []byte, data string) []byte {
	h := hmac.New(sha256.New, key)
	h.Write([]byte(data))
	return h.Sum(nil)
}

func normalizeHeaderValue(value string) string {
	return strings.TrimSpace(regexp.MustCompile(`\s+`).ReplaceAllString(value, " "))
}

// awsPercentEncode encodes a string using AWS-compatible percent-encoding:
// rawurlencode then unescape %7E -> ~.
func awsPercentEncode(value string) string {
	// Go's url.QueryEscape uses + for spaces, but we need %20.
	// Use url.PathEscape for path-style encoding, but it doesn't encode
	// everything AWS requires. Let's do it manually.
	var buf strings.Builder
	for _, r := range value {
		if shouldEscape(r) {
			for _, b := range []byte(string(r)) {
				fmt.Fprintf(&buf, "%%%02X", b)
			}
		} else {
			buf.WriteRune(r)
		}
	}
	return buf.String()
}

func shouldEscape(r rune) bool {
	// Unreserved characters per RFC 3986: A-Z a-z 0-9 - _ . ~
	switch {
	case r >= 'A' && r <= 'Z':
		return false
	case r >= 'a' && r <= 'z':
		return false
	case r >= '0' && r <= '9':
		return false
	case r == '-' || r == '_' || r == '.' || r == '~':
		return false
	default:
		return true
	}
}

// awsPercentDecode decodes percent-encoded sequences (like rawurldecode in PHP).
func awsPercentDecode(s string) string {
	// Use net/url.QueryUnescape but it converts + to space, which we don't want.
	// Manual decode:
	var buf strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '%' && i+2 < len(s) {
			hexVal := s[i+1 : i+3]
			b, err := hexDecode(hexVal)
			if err == nil {
				buf.WriteByte(b)
				i += 2
				continue
			}
		}
		buf.WriteByte(s[i])
	}
	return buf.String()
}

func hexDecode(s string) (byte, error) {
	var b byte
	for _, c := range s {
		b <<= 4
		switch {
		case c >= '0' && c <= '9':
			b |= byte(c - '0')
		case c >= 'a' && c <= 'f':
			b |= byte(c - 'a' + 10)
		case c >= 'A' && c <= 'F':
			b |= byte(c - 'A' + 10)
		default:
			return 0, fmt.Errorf("invalid hex")
		}
	}
	return b, nil
}

func (a *SigV4Authenticator) isPresignedRequest(req *minihttp.RequestContext) bool {
	return req.HasQueryParam("X-Amz-Algorithm") ||
		req.HasQueryParam("X-Amz-Credential") ||
		req.HasQueryParam("X-Amz-Signature")
}

type signatureAttempt struct {
	Host                 string `json:"host"`
	ExpectedSignature    string `json:"expected_signature"`
	CanonicalRequestSHA  string `json:"canonical_request_sha256"`
	CanonicalRequest     string `json:"canonical_request"`
	StringToSign         string `json:"string_to_sign"`
}

func (a *SigV4Authenticator) signatureMatchesAnyHostCandidate(
	req *minihttp.RequestContext,
	signedHeaders []string,
	payloadHash string,
	includeAllQueryParams bool,
	amzDate, scope string,
	credScope credentialScope,
	secretKey, providedSignature string,
	attempts *[]signatureAttempt,
) (bool, error) {
	*attempts = (*attempts)[:0]
	hostSigned := contains(signedHeaders, "host")
	hostCandidates := a.hostCandidates(req, hostSigned)

	for _, hostCandidate := range hostCandidates {
		canonicalRequest, err := a.buildCanonicalRequest(req, signedHeaders, payloadHash, includeAllQueryParams, hostCandidate)
		if err != nil {
			return false, err
		}
		stringToSign := buildStringToSign(amzDate, scope, canonicalRequest)
		expectedSignature := calculateSignature(secretKey, credScope, stringToSign)

		crHash := sha256.Sum256([]byte(canonicalRequest))
		attempt := signatureAttempt{
			Host:                hostCandidate,
			ExpectedSignature:   expectedSignature,
			CanonicalRequestSHA: hex.EncodeToString(crHash[:]),
			CanonicalRequest:    canonicalRequest,
			StringToSign:        stringToSign,
		}
		*attempts = append(*attempts, attempt)

		if hmac.Equal([]byte(expectedSignature), []byte(providedSignature)) {
			return true, nil
		}
	}
	return false, nil
}

func (a *SigV4Authenticator) hostCandidates(req *minihttp.RequestContext, hostSigned bool) []string {
	if !hostSigned {
		return []string{""}
	}

	primaryHost := strings.TrimSpace(req.GetHost())
	if !a.allowHostCandidateFallbacks {
		return []string{primaryHost}
	}

	var rawHosts []string
	rawHosts = append(rawHosts, primaryHost)

	forwardedHost := req.GetHeader("x-forwarded-host")
	if forwardedHost != "" {
		parts := strings.Split(forwardedHost, ",")
		if len(parts) > 0 {
			rawHosts = append(rawHosts, strings.TrimSpace(parts[0]))
		}
	}

	serverName := strings.TrimSpace(req.GetServerName())
	if serverName != "" {
		rawHosts = append(rawHosts, serverName)
		rawHosts = append(rawHosts, fmt.Sprintf("%s:%d", serverName, req.GetServerPort()))
	}

	scheme := req.GetScheme()
	defaultPort := 80
	if scheme == "https" {
		defaultPort = 443
	}

	candidates := make(map[string]bool)
	for _, rawHost := range rawHosts {
		host := strings.ToLower(strings.TrimSpace(rawHost))
		if host == "" {
			continue
		}
		candidates[host] = true

		if strings.Contains(host, ":") {
			parts := strings.SplitN(host, ":", 2)
			baseHost, port := parts[0], parts[1]
			if baseHost != "" && isAllDigits(port) {
				portNum, _ := atoi(port)
				if portNum == defaultPort {
					candidates[baseHost] = true
				}
			}
		} else {
			candidates[fmt.Sprintf("%s:%d", host, defaultPort)] = true
		}
	}

	keys := make([]string, 0, len(candidates))
	for k := range candidates {
		keys = append(keys, k)
	}
	return keys
}

type logRecord struct {
	Timestamp        string            `json:"timestamp"`
	Mode             string            `json:"mode"`
	Method           string            `json:"method"`
	URI              string            `json:"uri"`
	Host             string            `json:"host"`
	ServerName       string            `json:"server_name"`
	ServerPort       int               `json:"server_port"`
	SignedHeaders    []string          `json:"signed_headers"`
	ProvidedSignature string           `json:"provided_signature"`
	RequestHeaders   map[string]string `json:"request_headers"`
	Attempts         []signatureAttempt `json:"attempts"`
}

func (a *SigV4Authenticator) logSignatureMismatch(mode string, req *minihttp.RequestContext, signedHeaders []string, providedSignature string, attempts []signatureAttempt) {
	if a.authDebugLogPath == "" {
		return
	}

	dir := filepath.Dir(a.authDebugLogPath)
	os.MkdirAll(dir, 0777)

	record := logRecord{
		Timestamp:        time.Now().UTC().Format(time.RFC3339),
		Mode:             mode,
		Method:           req.Method(),
		URI:              req.RequestURI(),
		Host:             req.GetHost(),
		ServerName:       req.GetServerName(),
		ServerPort:       req.GetServerPort(),
		SignedHeaders:    signedHeaders,
		ProvidedSignature: providedSignature,
		RequestHeaders:   req.GetHeaders(),
		Attempts:         attempts,
	}

	data, err := json.Marshal(record)
	if err != nil {
		return
	}
	data = append(data, '\n')

	f, err := os.OpenFile(a.authDebugLogPath, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0666)
	if err != nil {
		return
	}
	defer f.Close()
	f.Write(data)
}

func (a *SigV4Authenticator) extractAccessKeyID(req *minihttp.RequestContext) string {
	authorization := req.GetHeader("authorization")
	if m := credentialRe.FindStringSubmatch(authorization); m != nil {
		return m[1]
	}

	credential := req.GetQueryParam("X-Amz-Credential")
	if credential != "" {
		parts := strings.Split(credential, "/")
		if parts[0] != "" {
			return parts[0]
		}
	}
	return ""
}

var credentialRe = regexp.MustCompile(`Credential=([^/]+)/`)

// --- helpers ---

func contains(slice []string, s string) bool {
	for _, v := range slice {
		if v == s {
			return true
		}
	}
	return false
}

func uniqueStrings(slice []string) []string {
	seen := make(map[string]bool)
	var result []string
	for _, s := range slice {
		if !seen[s] {
			seen[s] = true
			result = append(result, s)
		}
	}
	return result
}

func sliceEqual(a, b []string) bool {
	if len(a) != len(b) {
		return false
	}
	for i := range a {
		if a[i] != b[i] {
			return false
		}
	}
	return true
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

func atoi(s string) (int, error) {
	var n int
	for _, c := range s {
		if c < '0' || c > '9' {
			return 0, fmt.Errorf("not a number")
		}
		n = n*10 + int(c-'0')
	}
	return n, nil
}
