package http

import (
	"net/http"
	"net/url"
	"strings"
)

// RequestContext wraps a net/http.Request and provides PHP-compatible
// accessor methods that the S3 router and SigV4 authenticator depend on.
// It mirrors MiniS3\Http\RequestContext from the PHP reference.
type RequestContext struct {
	method      string
	requestURI  string
	path        string
	rawQuery    string
	query       url.Values
	headers     map[string]string
	r           *http.Request
}

// NewRequestContext creates a RequestContext from a standard http.Request.
func NewRequestContext(r *http.Request) *RequestContext {
	rc := &RequestContext{
		method:     strings.ToUpper(r.Method),
		requestURI: r.URL.RequestURI(),
		r:          r,
	}
	rc.path = rc.buildPath()
	rc.rawQuery = r.URL.RawQuery
	rc.query = r.URL.Query()
	rc.headers = rc.buildHeaders(r)
	return rc
}

// Method returns the uppercase HTTP method.
func (rc *RequestContext) Method() string {
	return rc.method
}

// RequestURI returns the full request URI (path + query string).
func (rc *RequestContext) RequestURI() string {
	return rc.requestURI
}

// Path returns the URL path component, defaulting to "/" if empty.
func (rc *RequestContext) Path() string {
	return rc.path
}

// RawQueryString returns the raw (unparsed) query string.
func (rc *RequestContext) RawQueryString() string {
	return rc.rawQuery
}

// GetQueryParam returns the first value for the given query parameter, or ""
// if the parameter is not present.
func (rc *RequestContext) GetQueryParam(name string) string {
	return rc.query.Get(name)
}

// HasQueryParam reports whether the query parameter exists.
func (rc *RequestContext) HasQueryParam(name string) bool {
	_, ok := rc.query[name]
	return ok
}

// GetHeader returns the header value (case-insensitive lookup), or "" if
// the header is not present.
func (rc *RequestContext) GetHeader(name string) string {
	return rc.headers[strings.ToLower(name)]
}

// GetHeaders returns all headers as a map with lowercase keys.
func (rc *RequestContext) GetHeaders() map[string]string {
	return rc.headers
}

// GetHost returns the request host. It checks the Host header first, then
// falls back to SERVER_NAME:SERVER_PORT semantics for reverse-proxy setups.
func (rc *RequestContext) GetHost() string {
	host := rc.GetHeader("host")
	if host != "" {
		return host
	}
	// Fall back to r.Host which net/http populates from the Host header or
	// the request target.
	if rc.r != nil && rc.r.Host != "" {
		return rc.r.Host
	}
	return "localhost"
}

// GetScheme returns "https" or "http" based on X-Forwarded-Proto, TLS state,
// or the bind port.
func (rc *RequestContext) GetScheme() string {
	forwardedProto := strings.ToLower(rc.GetHeader("x-forwarded-proto"))
	if forwardedProto == "https" {
		return "https"
	}
	if rc.r != nil && rc.r.TLS != nil {
		return "https"
	}
	return "http"
}

// GetServerName returns the server name component (without port) from the
// Host header, or "localhost" as fallback.
func (rc *RequestContext) GetServerName() string {
	host := rc.GetHost()
	if i := strings.LastIndex(host, ":"); i != -1 {
		return host[:i]
	}
	return host
}

// GetServerPort returns the port from the Host header, or 80/443 based on
// scheme as fallback.
func (rc *RequestContext) GetServerPort() int {
	host := rc.GetHost()
	if i := strings.LastIndex(host, ":"); i != -1 {
		portStr := host[i+1:]
		var port int
		for _, c := range portStr {
			if c < '0' || c > '9' {
				return defaultPort(rc.GetScheme())
			}
			port = port*10 + int(c-'0')
		}
		if port > 0 {
			return port
		}
	}
	return defaultPort(rc.GetScheme())
}

// Request returns the underlying *http.Request.
func (rc *RequestContext) Request() *http.Request {
	return rc.r
}

func (rc *RequestContext) buildPath() string {
	if rc.r != nil && rc.r.URL.Path != "" {
		return rc.r.URL.Path
	}
	return "/"
}

func (rc *RequestContext) buildHeaders(r *http.Request) map[string]string {
	headers := make(map[string]string)
	for name, values := range r.Header {
		if len(values) > 0 {
			headers[strings.ToLower(name)] = values[0]
		}
	}
	return headers
}

func defaultPort(scheme string) int {
	if scheme == "https" {
		return 443
	}
	return 80
}
