package admin

import (
	"crypto/sha256"
	"hash"
	"sync"

	"golang.org/x/crypto/bcrypt"
)

// installerSecret is a stable random secret generated once per process
// lifetime, used to sign the installer's CSRF/session cookie before the
// real config (and its session_secret) exists. Without a stable secret,
// each request would generate a new tempAuth and the signed cookie from
// the GET would never verify on the POST — causing "CSRF token is invalid".
var (
	installerSecret     string
	installerSecretOnce sync.Once
)

func getInstallerSecret() string {
	installerSecretOnce.Do(func() {
		installerSecret = randomToken(32)
	})
	return installerSecret
}

// sha256Hash returns a new sha256 hasher (used by HMAC for cookie signing).
func sha256Hash() hash.Hash {
	return sha256.New()
}

// hashPassword generates a bcrypt hash of the password.
func hashPassword(password string) (string, error) {
	h, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return "", err
	}
	return string(h), nil
}

// verifyPassword checks a bcrypt hash against the password.
func verifyPassword(hash, password string) bool {
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(password)) == nil
}
