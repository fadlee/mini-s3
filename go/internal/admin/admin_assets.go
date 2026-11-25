package admin

import (
	"crypto/sha256"
	_ "embed"
	"encoding/hex"
	"net/http"
)

// adminJS is the admin UI script. The PHP edition serves the same file,
// embedded in src/Admin/AdminAssets.php by scripts/build-admin-assets.php.
//
// The admin UI must not load script from a CDN: its pages show S3 secrets.
//
//go:embed assets/admin.js
var adminJS []byte

// adminScriptPath is the URL the admin pages load the script from. The hash in
// the path lets browsers cache it forever; the PHP edition uses the same path.
var adminScriptPath = "/_/assets/admin-" + scriptHash(adminJS) + ".js"

func scriptHash(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])[:12]
}

func serveAdminScript(w http.ResponseWriter) {
	h := w.Header()
	h.Set("Content-Type", "text/javascript; charset=UTF-8")
	h.Set("Cache-Control", "public, max-age=31536000, immutable")
	h.Set("X-Content-Type-Options", "nosniff")
	w.WriteHeader(http.StatusOK)
	w.Write(adminJS)
}
