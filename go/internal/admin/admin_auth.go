package admin

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/subtle"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

const (
	sessionCookieName = "mini_s3_admin"
	sessionExpiry     = 24 * time.Hour
)

// sessionPayload is the data stored in the signed cookie.
type sessionPayload struct {
	Authenticated bool   `json:"auth"`
	CSRFToken     string `json:"csrf"`
	Flash         string `json:"flash,omitempty"`
	Expires       int64  `json:"exp"`
}

// AdminAuth manages admin authentication via stateless HMAC-signed cookies.
// This replaces PHP's $_SESSION-based auth so sessions survive the Go
// binary's self-upgrade restart.
type AdminAuth struct {
	username     string
	passwordHash string
	secret       []byte
}

// NewAdminAuth creates an AdminAuth with the given credentials and signing secret.
func NewAdminAuth(username, passwordHash, sessionSecret string) *AdminAuth {
	return &AdminAuth{
		username:     username,
		passwordHash: passwordHash,
		secret:       []byte(sessionSecret),
	}
}

// IsConfigured reports whether the admin password has been set.
func (a *AdminAuth) IsConfigured() bool {
	return a.passwordHash != ""
}

// SessionData holds the parsed session state for the current request.
type SessionData struct {
	Authenticated bool
	CSRFToken     string
	Flash         string
}

// GetSession reads and validates the session cookie from the request.
func (a *AdminAuth) GetSession(r *http.Request) SessionData {
	cookie, err := r.Cookie(sessionCookieName)
	if err != nil {
		return SessionData{}
	}
	payload, ok := a.verifyCookie(cookie.Value)
	if !ok {
		return SessionData{}
	}
	return SessionData{
		Authenticated: payload.Authenticated,
		CSRFToken:     payload.CSRFToken,
		Flash:         payload.Flash,
	}
}

// IsAuthenticated checks if the current request has a valid authenticated session.
func (a *AdminAuth) IsAuthenticated(r *http.Request) bool {
	return a.GetSession(r).Authenticated
}

// Login attempts to authenticate with username/password. On success, sets
// the session cookie on the response.
func (a *AdminAuth) Login(w http.ResponseWriter, r *http.Request, username, password string) bool {
	if !a.IsConfigured() {
		return false
	}
	if subtle.ConstantTimeCompare([]byte(a.username), []byte(strings.TrimSpace(username))) != 1 {
		return false
	}
	// passwordHash is a bcrypt hash. Use bcrypt.CompareHashAndPassword.
	if !verifyPassword(a.passwordHash, password) {
		return false
	}

	session := a.GetSession(r)
	if session.CSRFToken == "" {
		session.CSRFToken = randomToken(32)
	}
	session.Authenticated = true
	session.Flash = "" // clear flash on login

	a.setSessionCookie(w, session)
	return true
}

// Logout clears the session cookie.
func (a *AdminAuth) Logout(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookieName,
		Value:    "",
		Path:     "/",
		MaxAge:   -1,
		HttpOnly: true,
		SameSite: http.SameSiteStrictMode,
	})
}

// SetFlash stores a flash message in the session cookie.
func (a *AdminAuth) SetFlash(w http.ResponseWriter, r *http.Request, message string) {
	session := a.GetSession(r)
	session.Flash = message
	a.setSessionCookie(w, session)
}

// ConsumeFlash reads and clears the flash message.
func (a *AdminAuth) ConsumeFlash(w http.ResponseWriter, r *http.Request) string {
	session := a.GetSession(r)
	flash := session.Flash
	session.Flash = ""
	a.setSessionCookie(w, session)
	return flash
}

// CSRFToken returns the CSRF token from the session, generating one if needed.
func (a *AdminAuth) CSRFToken(r *http.Request) string {
	session := a.GetSession(r)
	if session.CSRFToken != "" {
		return session.CSRFToken
	}
	return randomToken(32)
}

// VerifyCSRFToken checks the provided token against the session's CSRF token.
func (a *AdminAuth) VerifyCSRFToken(r *http.Request, token string) bool {
	session := a.GetSession(r)
	if session.CSRFToken == "" {
		return false
	}
	return subtle.ConstantTimeCompare([]byte(session.CSRFToken), []byte(token)) == 1
}

// EnsureCSRFToken ensures the session has a CSRF token, returning it.
// If the session doesn't have one, a new cookie is set with the generated token.
func (a *AdminAuth) EnsureCSRFToken(w http.ResponseWriter, r *http.Request) string {
	session := a.GetSession(r)
	if session.CSRFToken == "" {
		session.CSRFToken = randomToken(32)
		a.setSessionCookie(w, session)
	}
	return session.CSRFToken
}

func (a *AdminAuth) setSessionCookie(w http.ResponseWriter, session SessionData) {
	payload := sessionPayload{
		Authenticated: session.Authenticated,
		CSRFToken:     session.CSRFToken,
		Flash:         session.Flash,
		Expires:       time.Now().Add(sessionExpiry).Unix(),
	}
	a.writeSignedCookie(w, payload)
}

func (a *AdminAuth) writeSignedCookie(w http.ResponseWriter, payload sessionPayload) {
	data, _ := json.Marshal(payload)
	encoded := base64.RawURLEncoding.EncodeToString(data)

	mac := hmac.New(sha256Hash, a.secret)
	mac.Write([]byte(encoded))
	sig := base64.RawURLEncoding.EncodeToString(mac.Sum(nil))

	cookieValue := encoded + "." + sig
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookieName,
		Value:    cookieValue,
		Path:     "/",
		MaxAge:   int(sessionExpiry.Seconds()),
		HttpOnly: true,
		SameSite: http.SameSiteStrictMode,
	})
}

func (a *AdminAuth) verifyCookie(value string) (*sessionPayload, bool) {
	parts := strings.SplitN(value, ".", 2)
	if len(parts) != 2 {
		return nil, false
	}
	encoded, sig := parts[0], parts[1]

	mac := hmac.New(sha256Hash, a.secret)
	mac.Write([]byte(encoded))
	expectedSig := base64.RawURLEncoding.EncodeToString(mac.Sum(nil))

	if !hmac.Equal([]byte(sig), []byte(expectedSig)) {
		return nil, false
	}

	data, err := base64.RawURLEncoding.DecodeString(encoded)
	if err != nil {
		return nil, false
	}

	var payload sessionPayload
	if err := json.Unmarshal(data, &payload); err != nil {
		return nil, false
	}

	if time.Now().Unix() > payload.Expires {
		return nil, false
	}

	return &payload, true
}

func randomToken(n int) string {
	b := make([]byte, n)
	rand.Read(b)
	return fmt.Sprintf("%x", b)
}
