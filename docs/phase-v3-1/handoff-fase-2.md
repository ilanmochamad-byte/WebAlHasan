# Prompt handoff Fase 2 PRD V3 (untuk Codex)

Disiapkan auditor Claude Code, 10 September 2026, setelah Fase 1 diaudit dan di-merge (PR #22, `e685b57`).

**Gerbang deploy Fase 1 sudah selesai (10 September 2026).** Migrasi 013 terpasang pada MariaDB hosting dengan post-check 273 pemeriksaan LULUS, dan smoke fungsional pada situs live sudah dijalankan Human Developer. Temuan T-4 (default tahun ajaran) dikoreksi pada branch `perbaikan-default-tahun-ajaran-v3`; pastikan koreksi itu sudah masuk `main` sebelum Fase 2 dimulai.

---

## Prompt yang dikirim ke Codex

> Implementasikan **hanya Fase 2 PRD V3** pada branch baru `prd-v3-fase-2` yang dicabang dari `main`.
>
> Baca lebih dahulu: `AGENTS.md`, seluruh `PRD-V3.md`, `docs/phase-v3-1/` (terutama `hasil-audit-claude-code.md`, `matriks-capability.md`, `kontrak-api.md`, `desain-dan-aturan.md`), dan commit Fase 1 `b711ec8..7c74a28`. Anda implementator; Claude Code auditor. Jangan mengerjakan Fase 3.
>
> ### Bawaan wajib dari audit Fase 1
>
> 1. **T-2 harus ditangani sebelum menulis mutasi operasional apa pun.** `Capabilities::v3Capabilities()` menuliskan `sumber => SUMBER_PENUGASAN` secara harfiah untuk setiap capability operasional. Resolver fondasi membedakan `penugasan`, `admin`, dan `keduanya`, dan docblock fondasi mewajibkan modul baru membedakan pelaku admin dari pelaku operasional lewat `featureSource()`. Akibatnya admin yang merangkap penugasan pembimbing tercatat sebagai murni `penugasan`. Fase 2 mewajibkan koreksi admin beralasan dan dibedakan dari tindakan pembimbing (PRD §5.3), sehingga pembedaan itu harus dipulihkan lebih dahulu, beserta pengujiannya.
> 2. **Serialisasi konfigurasi V3 saat ini memakai kunci satu baris `schema_migrations`.** Itu memadai untuk katalog bervolume rendah, tetapi **tidak boleh** dipakai untuk alur operasional pelanggaran. Rancang penguncian pada tingkat baris santri/tahun ajaran, bukan satu gerbang global. Jangan menambah penulisan konfigurasi yang melewati service.
> 3. **Ambang tahun non-aktif tetap boleh dibuat, tetapi tidak boleh menjadi default** (temuan T-4, sudah dikoreksi). `save()` sengaja tidak mewajibkan tahun aktif agar konfigurasi tahun depan tetap mungkin, sedangkan `active()` menolak tahun non-aktif dengan `403`. Ketika Fase 2 memakai ambang untuk memicu rekomendasi, pastikan ambang yang tidak pernah terbaca tidak gagal secara diam-diam — beri sinyal yang terlihat operator.
> 4. **Santri tanpa penempatan aktif tidak pernah masuk cakupan** (`v3AppliesToSantri` mensyaratkan sedikitnya satu `plotting_kelas`/`plotting_kamar` aktif). Ini perilaku aman yang disengaja, bukan bug. Bila Fase 2 perlu menangani santri tanpa penempatan, minta keputusan pengguna lebih dahulu; jangan melonggarkan sendiri.
> 5. **Jangan melonggarkan `bin/v3_verify.php`.** Yatim pada tabel `v3_*` adalah blocker; yatim tabel warisan dilaporkan terpisah bertanda `[warisan]`. Keduanya tetap exit nonzero.
>
> ### Ruang lingkup Fase 2 (PRD §6, Fase 2)
>
> Kerjakan kedua belas persyaratan Fase 2 apa adanya. Titik yang paling mudah salah:
>
> - Snapshot kategori/tingkat/poin disimpan **dalam transaksi yang sama** dengan ledger; poin catatan lama tidak boleh berubah ketika poin default katalog diubah.
> - Idempotency key, fingerprint bisnis, dan optimistic version wajib ada sejak awal — tabel `v3_idempotency`, kolom `fingerprint`, `idempotency_key`, dan `version` sudah tersedia dari Fase 1 dan masih kosong.
> - Akumulasi poin wajib dapat direkonsiliasi ulang dari `v3_poin_ledger`; agregat bukan sumber kebenaran.
> - Pembatalan membuat **pembalik poin** (`pembalik_dari_id`), bukan menghapus baris atau mengedit total.
> - Ambang yang tercapai menghasilkan **tepat satu** rekomendasi, dan **nol** hukuman, konseling, atau perubahan akademik otomatis.
> - Murobi terkait boleh menandai mengetahui; murobi lain, orang tua, dan pengurus di luar cakupan mendapat `403`.
> - Outbox/push tidak boleh memuat nama santri, kategori, uraian, poin, nomor telepon, atau catatan rahasia.
> - Seluruh mutasi lewat POST/PUT/PATCH dengan CSRF di web, token di aplikasi, transaksi, prepared statement, dan audit. Tidak ada perubahan status lewat GET.
>
> ### Yang tidak boleh disentuh
>
> - Kontrak V1/V2: `default_mode`, struktur `capabilities` lama, menu aplikasi dari `ApiAuthService::menus()`, dan endpoint lama. `App\Ui\Navigation` adalah menu **web**; jangan menjadikannya sumber menu aplikasi.
> - Serializer baca V3 Fase 1 (`KatalogService::active()`) memakai allowlist field. Pertahankan pola allowlist untuk seluruh serializer baru; jangan mengembalikan baris database apa adanya.
> - Halaman `admin/admin_pelanggaran.php` tetap **baca-saja berlabel Data warisan** (PRD §5.2a). Jangan membuka kembali pencatatan atau penghapusan lama, dan jangan mem-backfill data warisan ke ledger V3.
> - Migrasi 001–013 tidak boleh diubah. Tambahkan migrasi 014 bila perlu perubahan skema, dengan rollback berpasangan.
>
> ### Pengujian yang wajib dijalankan
>
> Gunakan database uji terpisah dan fixture fiktif `sbx_*`; jangan memakai data produksi.
>
> ```sh
> # Fixture
> V2_PHASE3_SEED=1 php bin/v2_phase3_sandbox_seed.php
> # Paket Fase 1 harus tetap lulus (regresi V3 internal)
> DB_NAME=webalhasan_v3_phase1_test bash bin/v3_phase1_run_tests.sh
> # Regresi V1/V2 + fondasi
> bash bin/penugasan_run_all_tests.sh
> ```
>
> Acuan yang harus tetap terpenuhi: paket Fase 1 **71 pemeriksaan, 0 gagal**; regresi **49 suite, ±4.011 pemeriksaan, 0 gagal, 0 dilewati**. Tambahkan suite Fase 2 sendiri, termasuk uji konkurensi nyata (dua proses), uji privasi payload outbox, dan uji akses silang pembimbing/murobi/orang tua.
>
> Jalankan drill migrasi **sebelum** suite dan browser bila Anda menambah migrasi, karena drill menghapus data V3.
>
> ### Penyelesaian
>
> Buat commit, push branch `prd-v3-fase-2`, perbarui dokumen status fase beserta batas buktinya secara jujur, lalu **berhenti untuk audit Claude Code**. Jangan merge ke `main`, jangan deploy, dan jangan melanjutkan ke Fase 3.

---

## Untuk auditor berikutnya

Verifikasi khusus yang disarankan untuk Fase 2, di luar kriteria PRD:

- T-2 benar-benar diperbaiki, dibuktikan dengan akun admin-merangkap-pembimbing.
- Rekonsiliasi ledger setelah pembatalan berantai dan koreksi berulang.
- Idempotency di bawah konkurensi nyata, bukan hanya dua request berurutan.
- Payload outbox diperiksa isinya, bukan hanya keberadaannya.
- Serializer orang tua diuji dengan pencarian field internal, bukan hanya membandingkan field yang diharapkan.
