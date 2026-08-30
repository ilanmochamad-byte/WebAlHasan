# Panduan audit untuk Codex

Paket "Koreksi dan Modernisasi UI/UX V1–V2" — keputusan pengguna 30 Agustus 2026.
Implementer: Claude. Auditor: Codex.

---

## 0. Sebelum mulai

- Branch: **`codex/perapihan-v1-v2-ui`**, dicabangkan dari `main` `c65390d`.
- **Jangan** menjalankan Claude dan Codex bersamaan pada folder/branch yang sama.
- **Jangan** merge, push, atau deploy tanpa instruksi terpisah pengguna.
- Paket ini **belum dinyatakan lulus** oleh siapa pun.

Baca lebih dulu: `rencana.md` (ruang lingkup dan batas), lalu
`status-penerimaan.md` (klaim + buktinya), lalu `perubahan-pengujian.md`
(pengujian lama yang disesuaikan dan alasannya).

---

## 1. Menyiapkan lingkungan uji

Prosedurnya sama dengan sandbox Fase 5 (`docs/phase-v2-5/testing-sandbox.md`),
ditambah migrasi 010.

```bash
# 1. Struktur V1 tanpa satu pun INSERT
python3 - <<'PY'
lines = open('k1807225_webalhasan.sql', encoding='utf-8', errors='replace').read().split('\n')
out, skip = [], False
for ln in lines:
    if ln.upper().startswith('INSERT INTO'):
        skip = True
    if skip:
        if ln.rstrip().endswith(';'):
            skip = False
        continue
    out.append(ln)
ddl = '\n'.join(out)
assert 'INSERT INTO' not in ddl.upper()
open('/tmp/legacy_ddl.sql', 'w', encoding='utf-8').write(ddl)
PY

# 2. Database uji (WAJIB berakhiran _test)
mariadb -uroot -e "DROP DATABASE IF EXISTS webalhasan_test;
  CREATE DATABASE webalhasan_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER IF NOT EXISTS 'wah_test'@'127.0.0.1' IDENTIFIED BY '<password-lokal>';
  CREATE USER IF NOT EXISTS 'wah_test'@'localhost'  IDENTIFIED BY '<password-lokal>';
  GRANT ALL PRIVILEGES ON \`%\_test\`.*          TO 'wah_test'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON \`%\_test\_restore\`.* TO 'wah_test'@'127.0.0.1';
  GRANT ALL PRIVILEGES ON \`%\_test\`.*          TO 'wah_test'@'localhost';
  GRANT ALL PRIVILEGES ON \`%\_test\_restore\`.* TO 'wah_test'@'localhost';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'127.0.0.1';
  GRANT CREATE, DROP ON *.* TO 'wah_test'@'localhost';
  FLUSH PRIVILEGES;"
mariadb -uroot webalhasan_test < /tmp/legacy_ddl.sql

# 3. .env sandbox (JANGAN di-commit)
cat > .env <<'EOF'
APP_ENV=testing
APP_DEBUG=true
APP_URL=http://127.0.0.1:8123
APP_BASE_PATH=
APP_TIMEZONE=Asia/Jakarta
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=webalhasan_test
DB_USER=wah_test
DB_PASSWORD=<password-lokal>
DB_CHARSET=utf8mb4
API_TOKEN_HASH_SECRET=<acak khusus sandbox>
API_TOKEN_TTL_DAYS=30
IZIN_LEGACY_ENABLED=false
SESSION_SECURE_COOKIE=false
EOF

# 4. Migrasi 001–010 dan fixture
php bin/migrate.php up
php bin/migrate.php status | tail -3        # 010 harus [diterapkan]
V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
V2_PHASE5_FIXTURE=1 php bin/v2_phase5_fixture.php --jumlah=1000
```

Akun fixture: `sbx_admin`, `sbx_pengurus_a/b`, `sbx_murobi_a/b/c`,
`sbx_guru_biasa`, `sbx_ortu_a/b` — password `Sandbox#123` (bukan credential produksi).

> **Penting:** `sbx_ortu_b` harus terhubung ke `wali` id 2. Bila kontrak API
> mengeluhkan "Login fixture sbx_ortu_b berhasil", periksa
> `SELECT username, wali_id FROM users WHERE username LIKE 'sbx_ortu%'`.

---

## 2. Menjalankan seluruh pengujian

```bash
MOBILE_APP_ROOT=/path/ke/alhasanApps \
CHROMIUM_PATH=/path/ke/chromium \
bash bin/perapihan_run_all_tests.sh
```

`CHROMIUM_PATH` hanya perlu bila revisi Chromium bawaan Playwright tidak dapat
diunduh. Tanpa Playwright, bagian PDF Fase 5 akan menandai dirinya MENUNGGU
VERIFIKASI (bukan gagal).

Uji browser dijalankan terpisah:

```bash
php -S 127.0.0.1:8940 -t . &
BASE_URL=http://127.0.0.1:8940 CHROMIUM_PATH=/path/ke/chromium \
  node tests/browser/uji-perapihan.mjs
```

Bila ingin membandingkan dengan keadaan sebelum:

```bash
git clone . /tmp/baseline && (cd /tmp/baseline && git checkout c65390d)
cp .env /tmp/baseline/.env
php -S 127.0.0.1:8941 -t /tmp/baseline &
BASE_URL=http://127.0.0.1:8941 node tests/browser/tangkap-sebelum.mjs
```

**Angka acuan yang diperoleh implementer:** regresi 2.174 lulus + paket perapihan
248 lulus + browser 56 lulus, dengan satu berkas gagal yang **sudah gagal pada
baseline** (`v2_phase4_static.php`, lihat `risiko-dan-uji-tertunda.md` B-1).

---

## 3. Yang paling perlu diaudit

Diurut berdasarkan risiko, bukan berdasarkan ukuran perubahan.

### 3.1 Otorisasi (risiko tertinggi)

Paket ini **memperluas** satu halaman (`/portal/index.php` kini terbuka bagi
seluruh akun yang masuk) dan **mengubah** jalur masuk. Yang wajib diperiksa:

1. Apakah ada halaman lain yang ikut kehilangan guard tanpa disengaja?
   Bandingkan daftar guard `main` vs branch ini:
   ```bash
   git diff c65390d..HEAD -- 'admin/*.php' 'portal/*.php' | grep -nE '^\-.*(_guard|requireWeb|PortalGuard|Csrf::requireValid)'
   ```
   Setiap baris guard yang **dihapus** harus punya penggantinya pada berkas tujuan.
2. Apakah `App\Ui\Navigation` benar-benar bebas guard **dan** tidak dipakai sebagai
   dasar otorisasi di mana pun?
3. Apakah `SafeRedirect` dapat ditembus? Coba tujuan lain: `/admin/../etc/passwd`,
   `%2f%2fjahat`, `/admin/admin_akun.php%00`, URL sangat panjang, `\\\\host\\share`.
4. Apakah `admin/get_wali_json.php` membocorkan data ke non-admin? (harus 403)
5. Apakah potongan tampilan `_*.php` benar-benar 404 saat diakses langsung?

### 3.2 Perlindungan admin terakhir

Uji ulang `perapihan_akun_concurrency.php` beberapa kali. Coba juga variasi:
lima proses, atau campuran `revoke` dan `nonaktif` bersamaan. Yang harus selalu
benar: **jumlah admin aktif tidak pernah 0**.

### 3.3 Data wali

Yang paling mungkin salah:

1. `mergeWali` pada kasus relasi yang bertabrakan (santri sama, hubungan sama).
2. `mirrorParent` ketika wali tidak punya nomor HP, atau nama mengandung spasi ganda.
3. `saveSantri` dengan `wali` yang memuat kunci tak dikenal.
4. Perilaku `waliSearch` setelah beberapa penggabungan (wali yang sudah
   digabungkan tidak boleh muncul lagi sebagai kandidat).
5. Apakah `santri_wali.active_guard` (kolom generated V1) masih konsisten setelah
   `relationRepoint`.

### 3.4 Laporan kehadiran

1. Apakah `subject_scope` benar-benar tidak bocor ke kontrak API? Panggil
   `/api/v1/...laporan absensi...` tanpa parameter dan bandingkan jumlah dengan
   `main`.
2. Apakah cakupan guru tetap tidak dapat ditembus dengan `teacher_id` orang lain
   **digabung** dengan `subject_scope=guru`?
3. Apakah ekspor CSV di atas batas 20.000 baris masih ditolak `422 EXPORT_TOO_LARGE`?

### 3.5 Modul Pengajian

1. POST tab Jadwal oleh akun guru (termasuk dengan formulir yang dipalsukan) →
   harus 403 dan **tidak** ada baris tercipta.
2. Guru membuka `?tab=jadwal&action=detail&id=<jadwal guru lain>` → harus 403.
3. Alamat lama dengan POST (bukan GET) → harus diproses, bukan dialihkan.

### 3.6 Tampilan

Prioritaskan halaman kelompok **B** pada `inventaris-halaman.md` — halaman yang
memperoleh kerangka baru tanpa ditulis ulang. Di situlah cacat tampilan sisa
paling mungkin ada.

---

## 4. Yang TIDAK perlu diaudit ulang

Tidak disentuh paket ini:

- alur perizinan V2 (pengajuan, routing, keputusan, pembatalan, koreksi);
- notifikasi in-app, push, outbox, dan WhatsApp (tetap `OFF` dan DITANGGUHKAN);
- laporan perizinan V2, CSV-nya, dan halaman cetaknya;
- REST API dan aplikasi mobile;
- alur PSB, keuangan, alumni, dan konten website.

---

## 5. Bila menemukan masalah

1. Catat pada dokumen audit tersendiri di `docs/perapihan-v1-v2/`.
2. Lakukan koreksi terarah pada branch yang sama, dengan commit terpisah.
3. Jangan melemahkan pengujian untuk membuat koreksi terlihat lulus.
4. Bila masalahnya menuntut keputusan pengguna (mis. B-1 s.d. B-4 pada
   `risiko-dan-uji-tertunda.md`), **hentikan** dan tanyakan.

---

## 6. Daftar commit paket ini

```
ce359b5  feat(ui)        lapisan desain dan navigasi bersama lintas peran      (koreksi 6)
1498a2e  feat(auth)      satu pintu masuk /portal/ untuk seluruh peran          (koreksi 7)
66dbbc4  feat(pengajian) satukan jadwal dan pertemuan dalam satu menu bertab    (koreksi 4)
e489528  feat(guru)      hapus pilihan tugas lama, tampilkan penugasan nyata    (koreksi 3)
bc30564  feat(akun)      satu pusat Akun & Hak Akses dengan role eksplisit      (koreksi 1)
3e19493  feat(wali)      pencocokan wali + rekonsiliasi data lama               (koreksi 2)
374f495  feat(laporan)   pisahkan penyajian kehadiran santri/guru/gabungan      (koreksi 5)
7f3af68  test            selaraskan pengujian lama dengan keputusan 30 Agt 2026
cd23989  test            pengujian baru untuk ketujuh koreksi
```

(Hash commit dokumentasi dan koreksi lanjutan ada di `git log` branch ini.)
