# Manifest bukti — optimasi Android Google Play (implementasi Claude Code)

21 November 2026 · Implementator Claude Code · Belum diaudit Codex

Binary, mapping, dan dump DEX **tidak** dimasukkan Git (ukuran dan privasi). Semuanya berada di penyimpanan lokal privat mesin Human Developer, `~/optimasi-android-build/` (di luar kedua repositori), dengan hash di bawah. Auditor sebaiknya membangun ulang sendiri dari SHA handoff, bukan memakai berkas ini.

## Sumber

| Item | Nilai |
| --- | --- |
| Mobile baseline (`origin/main`, memuat dokumen PR #35) | `82f156ab98cb62fd5a97259be944f4cfcf75ffd0` |
| Mobile commit handoff | kode `747e55d` (artefak), dokumen `4b20d25`, branch `claude/optimasi-android-play` |
| Web baseline (`origin/main`, memuat dokumen PR #124) | `e6f57b86a1d965664f95c7fc9d10ffa6991839ab` |
| Rilis 19 (EAS) | build `24ad663e-8f31-444c-b593-9dced22e89bf`, profile `production`, distribution STORE, commit `84ddebbbdedc022dae42f323cd37ae9fcc471cc2`, selesai 2026-11-13T14:52:06Z |

## Artefak

| Artefak | Lokasi privat | SHA-256 | Keterangan |
| --- | --- | --- | --- |
| AAB rilis 19 (asli EAS, diunduh dari akun EAS pemilik) | `rilis19/app-rilis19.aab` | `015f4ddd6045c7666cf60a6cf0d3ec7f2761782c379d7bd519b0ad1ad553c908` | 1.9.0 (19), d8 saja, tanpa R8 |
| AAB final R8 (AGP 8.12.3) | `artefak/final/app-release.aab` | `df4467910bf1bf20f79361a4c6b4693a17b3bf606b28a1504adf3a40c44bf915` | 1.9.1, versionCode lokal 1, kunci debug template, API placeholder `https://api-uji.invalid/api/v1`. **Bukan untuk distribusi.** |
| APK final R8 (konfigurasi sama) | `artefak/final/app-release.apk` | `eccc483731c17150fefe2829e4d91f32c192c4b3900699b9c45829d810ba50ab` | universal, kunci debug |
| `mapping.txt` final | `artefak/final/mapping/mapping.txt` | `43799e8cf9bbbe33e6b300b4fba791ccbaf078f9da0db8491d799cb8e5d8ed82` | identik dengan `BUNDLE-METADATA/com.android.tools.build.obfuscation/proguard.map` di AAB final |
| `classes.dex` APK final | dalam APK | `37b3ec6404b41e6ac7f6568cce1444006336a8ce6fb615d7f23c4d50a9914082` | identik byte dengan APK uji |
| APK UJI R8 (cleartext hanya 127.0.0.1) | `artefak/uji/app-release-uji.apk` | `0a97ed654afe14a8c61052dff0cb624170974aa440d012f44f476f5aa0da6f1e` | salinan uji, API `http://127.0.0.1:8971/api/v1`; DEX dan mapping identik dengan final |
| AAB R8 percobaan AGP 8.12.0 | `artefak/r8-agp8120/app-release.aab` | `56ae1f2f52155b889d3043d8ba56e8edacaaf143ee5b36689d9f18ee2f8dbd57` | pembanding sebelum patch AGP; DEX dan skor sama |
| APK UJI baseline 1.9.0 tanpa R8 | `artefak/uji/app-release-uji-baseline-1.9.0.apk` | `d1ae867d2e705a984edceb1a6bcc53209db1c76804fbce824d54fafa6f715595` | sumber `82f156a`, 5 DEX, cleartext hanya 127.0.0.1; untuk uji upgrade dan kontrol Android 12 |

Semua APK/AAB lokal ditandatangani kunci debug template RN (SHA-256 sertifikat `fac61745dc0903786fb9ede62a962b399f7348f0bb6f899b8332667591033b9c`), bukan kunci upload/Play.

Keluaran uji native (privat): `native/api36`, `api36-ulang`, `api36-profil`, `api36-3tombol-cutout`, `api35`, `api31`, `api31-kontrol-baseline`, `api31-crop`, `upgrade` — berkas `.txt` per suite, tangkapan, `*.anr`.

## Log dan keluaran yang disimpan privat

- `build-impl-release.log` (AGP 8.12.0), `build-impl-release2.log` (final, AGP 8.12.3), `build-uji-release.log` — memuat `> Task :app:minifyReleaseWithR8`.
- `artefak/final/mapping/{configuration,seeds,usage,resources}.txt` — konfigurasi R8 gabungan dan laporan shrink.
- `rilis19/dexdump.txt`, `artefak/final/dexdump.txt`, skrip `gp2scan.py` — pemindaian simbol GP-2.
- `native/<api>/…` — keluaran suite native, tangkapan layar, logcat, `*.anr`.

Tidak ada credential, token, `.env`, keystore produksi, atau data santri di bukti. Fixture memakai akun sintetis `sbx_*` pada DB `test_*_test` lokal.
