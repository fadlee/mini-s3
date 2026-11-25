# Mini S3 (PHP) — Security, Access Keys & UX Plan

**Scope:** PHP edition saja. Port ke Go dikerjakan terpisah setelah ini selesai (catat di `go-port/PARITY.md`).

**Goal:**
1. Tutup celah keamanan yang ditemukan saat audit.
2. Access key bisa dibuat otomatis, diberi label, dibuat read-only, dilihat lagi, dan dicabut dari UI.
3. Alur installer dan admin lebih sederhana, setiap setting punya penjelasan inline.

**Keputusan yang sudah diambil:**
- Izin key global per key: `read` atau `readwrite` (belum per bucket).
- Public read tetap satu saklar global.
- Secret boleh dilihat lagi kapan saja dari UI.

**Aturan kerja (dari AGENTS.md):**
- File `src/**/*.php` baru wajib ditambahkan ke `public/index.php` **dan** `$sourceFiles` di `scripts/build-release.php`.
- Setiap tahap diakhiri dengan `for f in tests/unit/*.php; do php "$f"; done`, `php tests/lint.php`, dan integration test bila menyentuh S3.
- Sebelum release: build zip `v0.0.0-test` dan cek class baru ikut ter-bundle.

---

## Tahap 1 — Quick security fixes

Perubahan kecil yang berdiri sendiri. Bisa di-release sendiri.

- [x] **1.1 Hapus `config/config.bak.php` dari repo.** Tambahkan `/config/*.bak.php` dan `/config/*.tmp.*` ke `.gitignore`.
  Catatan: file tetap ada di riwayat git. Kredensial `minioadmin` di dalamnya dianggap bocor. Ganti di server mana pun yang memakainya.
- [x] **1.2 Public read default OFF.**
  - `ConfigLoader.php` default `PUBLIC_READ_ALL_BUCKETS => false`.
  - `AdminRouter::defaultValues()` → `false` (checkbox installer tidak tercentang).
  - `AdminConfigWriter::buildConfig()` default checkbox → `false` bila tidak ada nilai lama.
  - Update test `admin-config-writer.php` ("public read defaults to true") dan `config-loader.php`.
  - **Breaking change** untuk instalasi yang config-nya tidak menulis key ini (env-only / config manual). Instalasi lewat installer tidak terdampak karena nilainya selalu ditulis. Tulis di release notes.
- [x] **1.3 Cookie session aman.** Sebelum `session_start()` di `AdminAuth`: `session_set_cookie_params` dengan `httponly=true`, `samesite=Strict`, `secure` bila request HTTPS (cek `HTTPS` / `X-Forwarded-Proto`), `path=/_`; nama session khusus (`mini_s3_admin`); `session.use_strict_mode=1`.
- [x] **1.4 Header keamanan admin.** Di `AdminRouter::html()` dan `json()`:
  - `Cache-Control: no-store`
  - `X-Frame-Options: DENY`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - CSP: `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'`. Alpine butuh `unsafe-eval` kecuali pakai build CSP-nya. Ketatkan setelah 1.5.
- [x] **1.5 Vendor Alpine.js (tanpa CDN).**
  - Audit pemakaian plugin `@alpinejs/ui` dan `@alpinejs/focus`. Buang yang tidak dipakai.
  - Pin satu versi Alpine. Simpan minified JS di class baru `src/Admin/AdminAssets.php` (string nowdoc) supaya tetap ikut bundle single-file.
  - Sajikan lewat route `/_/assets/alpine.js` dengan `Cache-Control: public, max-age=31536000, immutable` dan query versi.
  - Daftarkan file di `public/index.php` + `build-release.php`.
- [x] **1.6 `AUTH_DEBUG_LOG` tidak bisa diisi dari UI.** Hapus field dari form. Nilainya hanya dari config file / env. `buildConfig()` mempertahankan nilai lama.
  Alasan: path bebas + isi dari header request = admin bisa menulis file `.php` ke web root.
- [x] **1.7 Logout lewat POST + CSRF.** Ubah link nav jadi form kecil. GET `/_/logout` → redirect ke `/_` tanpa logout.
- [x] **1.8 Batasi percobaan login.**
  - Counter per IP di file JSON `config/.login-throttle.json` (dengan `flock`).
  - Setelah 5 gagal dalam 15 menit → tolak 15 menit dengan pesan jelas.
  - Reset bila login berhasil.
- [x] **1.9 Permission file.** `mkdir` config dir `0700`. File config `chmod 0600` setelah ditulis (abaikan error di hosting yang tidak mengizinkan).
- [x] **1.10 Error admin generik.** Tampilkan pesan umum + log detail via `error_log()`. Pengecualian: error validasi form (`RuntimeException` dari writer) tetap ditampilkan seperti sekarang.
- [x] **1.11 Cek risiko Nginx + release zip.** Bila root = folder ekstrak, pastikan `data/` dan `config/` tidak tersaji langsung.
  - Tambahkan blok `location ~ ^/(data|config)/ { deny all; }` ke contoh Nginx di README.
  - Dashboard menampilkan peringatan bila `DATA_DIR` berada di dalam document root.

**Test:** update/tambah di `admin-auth.php`, `admin-config-writer.php`, `admin-renderer.php`, `config-loader.php`. Tambah test throttle login.

---

## Tahap 2 — Model access key + izin read-only

- [x] **2.1 Format kredensial baru, kompatibel dengan yang lama.**
  ```php
  'CREDENTIALS' => [
      'AKOLDKEY'  => 'secret',                               // format lama → readwrite
      'AK3F9...'  => [
          'secret'  => '...',
          'access'  => 'read',                               // read | readwrite
          'label'   => 'CDN',
          'created' => 1732500000,
      ],
  ],
  ```
  Normalisasi di `ConfigLoader::load()` jadi bentuk seragam `[key => ['secret','access','label','created']]`.
  - Validasi `access` ∈ {read, readwrite}. Nilai lain → error konfigurasi.
  - Env `MINI_S3_CREDENTIALS_JSON` menerima kedua format.
  - Writer menulis format baru. Config lama dibaca tanpa migrasi paksa.
- [x] **2.2 `SigV4Authenticator::authenticate()` mengembalikan access key** yang terverifikasi (`string`), bukan `void`. Constructor menerima map `key => secret` hasil normalisasi. Jalur legacy `ALLOW_LEGACY_ACCESS_KEY_ONLY` dianggap `readwrite` (perilaku lama).
- [x] **2.3 Cek izin di satu tempat di `S3Router`.**
  - Fungsi `requiredAccess(method)`: GET/HEAD → read; PUT/POST/DELETE (termasuk multipart & bulk delete) → write.
  - Key `read` + operasi write → `403 AccessDenied`.
  - Public read tetap melewati auth untuk GET/HEAD.
- [x] **2.4 Generator key** (`AdminKeyGenerator` atau method statis di writer):
  - Access key: `AK` + 18 karakter `[A-Z0-9]` dari `random_bytes`.
  - Secret: 40 karakter base64url dari `random_bytes(30)`.
  - Cek tidak bentrok dengan key yang ada.
- [x] **2.5 Operasi key di `AdminConfigWriter`:** `addKey(label, access)`, `updateKey(key, label, access)`, `deleteKey(key)`.
  - Tolak hapus key terakhir.
  - Bila `CREDENTIALS` datang dari env, halaman key read-only + pesan "dikelola lewat environment".

**Test:**
- `sigv4-authenticator.php`: nilai kembalian.
- `config-loader.php`: format lama/baru/campur/invalid.
- `admin-config-writer.php`: add/update/delete/generator.
- Integration (`tests/integration/run.php`): key read bisa GET/HEAD/list, ditolak PUT/DELETE/multipart/bulk delete.

---

## Tahap 3 — Halaman Access Keys

- [x] **3.1 Route `/_/keys` + nav "Access Keys".**
  - Tabel kolom: label, access key (monospace + copy), izin (badge Read / Read & write), dibuat.
  - Aksi per baris: Show secret, Copy secret, Edit, Delete.
- [x] **3.2 Buat key:** dialog berisi label + pilihan izin (default Read & write). Setelah dibuat, panel sukses menampilkan access key + secret + snippet koneksi untuk key tersebut.
- [x] **3.3 Show secret** diambil lewat POST + CSRF (JSON), bukan ditanam di HTML. Halaman tidak berisi secret sampai diminta.
- [x] **3.4 Hapus key:** konfirmasi dengan mengetik access key / label, plus peringatan "klien yang memakai key ini akan langsung ditolak".
- [x] **3.5 Snippet koneksi pindah ke sini, per key:** generic env, Laravel, aws-cli, rclone, s5cmd, JS SDK v3. Hapus panel snippet dari dashboard dan ganti dengan link "Connect an app →". Secret di dashboard tidak lagi ditanam di `data-full`.

---

## Tahap 4 — Sederhanakan installer & halaman Settings

- [x] **4.1 Installer minimal:** hanya username admin (default `admin`) + password + konfirmasi.
  - Data dir terisi otomatis dan disembunyikan di "Advanced".
  - Public read off.
  - Key pertama (readwrite, label "Default") dibuat otomatis.
- [x] **4.2 Halaman sukses setelah install:** tampilkan endpoint, access key, secret, dan snippet. Tombol "Saya sudah menyimpan, lanjut ke dashboard". Secret tetap bisa dilihat lagi di Access Keys.
- [x] **4.3 `/_/config` → "Settings", tanpa field kredensial.**
  - Grup **Storage:** data dir, max request size.
  - Grup **Access:** public read.
  - Grup **Advanced:** clock skew, presign expires, host fallbacks.
  - Hapus `mergeCredentials`/`credentials()` lama dari alur config.
- [x] **4.4 Field yang di-set lewat env** tampil disabled + badge "set by MINI_S3_…". Sekarang override env tidak terlihat sama sekali.
- [x] **4.5 Flash message** setelah save Settings ("Settings saved."), juga untuk aksi key.

---

## Tahap 5 — Inline docs

- [x] **5.1 Teks bantuan di bawah setiap field.** Isinya: apa artinya, nilai aman/default, dan risiko. Contoh:
  - *Public read*: "Siapa pun yang tahu URL bisa mengunduh semua objek di semua bucket tanpa key. Biarkan mati kecuali server ini hanya untuk file publik."
  - *Clock skew*: "Selisih waktu maksimal antara jam klien dan server (detik). Naikkan hanya bila klien sering error RequestTimeTooSkewed."
  - *Host fallbacks*: "Hanya untuk proxy yang mengubah header Host. Melemahkan verifikasi signature."
  - *Max request size*: hubungan dengan `upload_max_filesize` / `post_max_size` / `client_max_body_size`. Tampilkan nilai PHP aktual bila lebih kecil.
- [x] **5.2 Banner peringatan di dashboard** (bisa ditutup):
  - Public read aktif.
  - Akses tanpa HTTPS.
  - Data dir di dalam document root.
  - `AUTH_DEBUG_LOG` aktif.
- [x] **5.3 Empty state yang membantu:** belum ada bucket → tombol buat bucket + contoh perintah `aws s3 mb`; halaman Keys selalu punya penjelasan singkat read vs read & write.
- [x] **5.4 Update README:** bagian Access Keys, read-only, perubahan default public read, contoh Nginx dengan `deny`, dan screenshot baru.

---

## Tahap 6 — Penutup

- [x] Jalankan semua unit + integration test dan `test-s5cmd.sh` (dengan key read & readwrite).
- [x] Build release test, cek semua class baru ter-bundle, coba installer dari zip bersih.
- [x] Update `go-port/PARITY.md`: tandai fitur baru `[P]` (PHP only, pending Go).
- [x] Release notes: breaking change public read default, logout POST, `AUTH_DEBUG_LOG` tidak lagi dari UI.

## Urutan & release

| Release | Isi | Alasan |
|---|---|---|
| A | Tahap 1 | Fix keamanan kecil, cepat keluar |
| B | Tahap 2 + 3 | Model key dan UI-nya harus keluar bersama |
| C | Tahap 4 + 5 (+6) | Polish alur dan docs di atas model baru |

## Di luar scope (sengaja ditunda)

- Izin per bucket dan public read per bucket.
- Port ke Go.
- Enkripsi secret di config (SigV4 butuh plaintext; manfaatnya kecil tanpa key management terpisah).
- Pencatatan "last used" per key (butuh tulis file per request).

## Hasil implementasi (2026-11-25)

Semua tahap selesai untuk edisi PHP. Penyimpangan dari rencana di atas:

- **Cookie session `SameSite=Lax`, bukan `Strict`.** Dengan Strict, membuka `/_` dari link di situs/email lain selalu memunculkan halaman login. Semua aksi yang mengubah data tetap dilindungi token CSRF.
- **Secret 40 karakter alfanumerik `[A-Za-z0-9]`, bukan base64url.** Base64url bisa diawali `-`, yang dibaca sebagai flag oleh `aws configure set`.
- **Banner dashboard bisa ditutup per browser** (localStorage), tidak disimpan di server.
- **Peringatan "data dir di dalam web root"** hanya muncul bila server bukan Apache (Apache sudah memblokir lewat `.htaccess`).
- **Delete key** memakai konfirmasi ketik access key (form biasa, tanpa JS), bukan dialog.
- **Bonus:** `opcache_invalidate()` dipanggil setelah menulis `config/config.php`, supaya perubahan key/setting langsung berlaku walau OPcache aktif.

Verifikasi:
- `for f in tests/unit/*.php; do php "$f"; done`, `php tests/lint.php`, `php tests/integration/run.php` (termasuk skenario key read-only), `php tests/release-archive.php` (termasuk aset Alpine di bundle).
- End-to-end pada release zip dengan `php -S`, `curl --aws-sigv4`, dan Chromium headless: installer → key pertama → buat key read-only → PUT/DELETE ditolak 403 → ubah izin → hapus key → Settings → logout POST → throttle login 429; dialog Files dan reveal secret berjalan dengan Alpine yang di-host sendiri, tanpa error JS.
