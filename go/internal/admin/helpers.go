package admin

import (
	"crypto/sha256"
	"hash"

	"golang.org/x/crypto/bcrypt"
)

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
