# Validasi lanjutan Claude Code — PRD V6 Fase 4 (26 Oktober 2026)

Tindak lanjut atas audit Codex (`../AUDIT-CODEX.md`: **belum lulus seluruh validasi lintas platform** karena runtime iOS tidak dapat diuji; F-1 dikoreksi di mobile `87d1c10`). Pemeriksa: Claude Code (implementator Fase 4) atas permintaan Human Developer. Ini **bukan** audit independen pengganti Codex, dan bukan izin rilis.

## Keadaan yang diverifikasi

| Butir | Nilai |
| --- | --- |
| Web `main` | `f1a948d` (merge PR #90); `git diff 08aa5fc origin/main` kosong (= commit audit Codex) |
| Mobile `main` | `6842f13` (merge PR #17); `git diff 87d1c10 origin/main` kosong (= koreksi F-1 Codex) |
| Agen lain | Audit Codex sudah di-commit dan di-merge; tidak ada perubahan belum di-commit selain `package.json`/`package-lock.json` mobile milik pekerjaan lain (tidak disentuh). |
| Lingkungan | DB `test_*_test` berfixture sintetis dari `tests/v6_phase4_db.sh`; salinan mobile tanpa `.env*`, `npm ci` versi ter-commit (Expo 57.0.15); bundel diperiksa 0 host produksi. |

## Hasil

| Pemeriksaan | Hasil | Berkas |
| --- | --- | --- |
| Statis Fase 4 / HTTP web+API (fixture baru) | 22/0 · 63/0 | `statis.txt`, `http.txt` |
| Tinjauan kode koreksi F-1 (`usePrivateResource.reject`, `useKeuanganMutation`) | Benar dan sempit: generasi pembacaan dinaikkan, data/draf dibuang, 401/403/404 mutasi menutup layar induk; retry, 409, 422, dan keputusan server tidak berubah | — |
| Probe F-1 Codex, **diulang independen**: kode implementator `12fb04a` / koreksi `87d1c10` | **8/8 / 16/0** (sama dengan audit) | `probe-f1-kontrol-12fb04a.txt`, `probe-f1-koreksi-87d1c10.txt` |
| Expo web asli pada `87d1c10` | 45/0 | `expo-web-87d1c10.txt` |
| Mobile tsc / lint / unit pada `87d1c10` | exit 0 / exit 0 (0 peringatan) / 36/0 | `mobile-tsc-lint-unit.txt` |
| **iOS simulator (baru)** — build **Release** iPhone 17 Pro iOS 26.2, Xcode 27.1, tanda tangan lokal ad-hoc | Lulus: login + SecureStore, pintu Keuangan dengan perlindungan layar siap, tautan skema ke formulir, ketukan ganda → 1 ledger/1 klaim/2 InApp wali, F-1 (403 menutup layar, tanpa mutasi), kontainer 52 berkas dan 7.583 baris log proses tanpa data keuangan/kredensial | `ios-simulator-release.txt`, `ios-*.png` |

### Temuan V-1 (P3, dikoreksi di `claude/prd-v6-fase-4-validasi`) — pesan penolakan basi sesudah akses dipulihkan

Sesudah POST ditolak 403, F-1 menutup layar dengan benar. Tetapi `useMutationGuard` menyimpan galatnya sendiri sesudah `reject()`; ketika hak dipulihkan dan pengguna menekan "Coba lagi", formulir yang sudah termuat ulang masih menampilkan "Informasi tidak dapat diakses…" di atas tombol kirim (terlihat di iOS: `ios-pesan-basi-sesudah-coba-lagi.png`). Kartu setoran transfer tidak terkena karena kartunya dipasang ulang.

Koreksi satu berkas mobile `src/hooks/use-keuangan-mutation.ts`: bila mutasi ditolak 401/403/404, sesudah `guard.run` selesai panggil `guard.reset()` (pesan penolakan sudah tampil di layar induk; kunci memang sudah dibuang penjaga). Tidak mengubah server, retry, 409/422, atau F-1.

| Uji | Sebelum (`87d1c10`) | Sesudah koreksi |
| --- | --- | --- |
| Probe baru `uji-pesan-basi.mjs` (formulir setoran + kartu antrean, dengan cabut/pulihkan penugasan) | 4/1 (gagal tepat jalur formulir) | **5/0** |
| Probe F-1 Codex | 16/0 | **16/0** |
| Expo web asli | 45/0 | **45/0** |
| tsc / lint / unit | lulus / lulus / 36/0 | lulus / lulus / **36/0** |
| iOS Release (build ulang) | pesan basi tampil | formulir bersih (`ios-koreksi-coba-lagi-bersih.png`) |

## Status kriteria Fase 4 sesudah validasi

- Kode web/API dan aplikasi lulus pada Expo web, Android emulator (bukti implementator dan Codex), dan kini **iOS simulator build Release**.
- **Masih terbuka (tidak diklaim):** build Debug/dev client iOS tetap gagal di linker Xcode 27.1; perangkat fisik Android/iOS; Push provider nyata dan ketukan Push; cold start dari Push pada build rilis; tampilan cuplikan pengalih aplikasi iOS tidak diverifikasi visual (hanya keberhasilan panggilan native `enableAppSwitcherProtectionAsync`); alur wali tidak diulang di iOS (sudah di Expo web dan Android); MySQL/cPanel.
- Catatan cara build iOS yang berhasil: salinan tanpa `.env*`, `npm ci` dari lock ter-commit, lalu `xcodebuild -configuration Release -sdk iphonesimulator CODE_SIGN_IDENTITY=- CODE_SIGNING_ALLOWED=YES CODE_SIGNING_REQUIRED=YES DEVELOPMENT_TEAM=` dengan `EXPO_PUBLIC_API_BASE_URL` lokal. Tanpa tanda tangan (`CODE_SIGNING_ALLOWED=NO`) SecureStore gagal karena entitlement keychain tidak ada.
- Pengamatan tanpa koreksi: pintu Keuangan iOS (layar pertama navigator bersarang) tidak menampilkan tombol kembali; gestur geser dari tepi kiri kembali ke Beranda. Pola sama dengan navigator kesehatan V5.

## Cara mengulang

```bash
MOBILE_COPY=/private/tmp/<salinan alhasanApps tanpa .env*, npm ci> bash docs/phase-v6-4/validasi-claude/ulang.sh
```

Probe pesan basi: jalankan Metro dari salinan dengan sumber yang diuji, DB `test_*_test` dari fixture Fase 4, lalu `node docs/phase-v6-4/validasi-claude/uji-pesan-basi.mjs` (variabel `DB_NAME`, `V6_WEB`, `V6_APP`).

Koreksi V-1 belum di-merge. Tidak merge, deploy, migrasi produksi, rilis aplikasi, atau memulai Fase 5.
