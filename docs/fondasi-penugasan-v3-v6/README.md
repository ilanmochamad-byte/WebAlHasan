# Fondasi Penugasan dan Hak Akses Lintas PRD V3–V6

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.

Paket ini **hanya membangun fondasi**: model penugasan, resolver capability,
administrasi penugasan, audit, dan kontrak akses yang akan dipakai oleh:

| PRD | Modul mendatang | Penugasan yang disiapkan |
| --- | --- | --- |
| V3 | konseling dan pelanggaran santri | cakupan binaan **murobi** (guru) dan **pembimbing** (pengurus) |
| V4 | penilaian semester dan rapor | **guru mata pelajaran** (guru), **Bagian Pendidikan** (pengurus) |
| V5 | tagihan pembiayaan bulanan | **bendahara pembiayaan bulanan** (pengurus) |
| V6 | penerimaan santri baru dan pembiayaan PSB | **panitia PSB** dan **bendahara PSB** (pengurus, terpisah) |

> **Fitur bisnis V3–V6 belum diimplementasikan.** Tidak ada catatan konseling,
> catatan/poin pelanggaran, tindakan pembinaan, input nilai, nilai semester,
> finalisasi/cetak rapor, tagihan/pembayaran bulanan, kuitansi, jurnal keuangan,
> proses seleksi PSB baru, tagihan/pembayaran PSB, menu mobile, maupun push
> notification baru pada paket ini. Yang ada hanyalah **siapa yang akan berhak**
> melakukan tindakan-tindakan itu ketika PRD terkait dikerjakan.

## Prinsip yang dipertahankan

- Role dasar tetap **`admin`, `guru`, `pengurus`, `orang_tua`**. Tidak ada role
  login `murobi`, `pembimbing`, `pendidikan`, `bendahara`, `panitia_psb`,
  `bendahara_psb`, atau `guru_mapel`. Semua istilah itu adalah **penugasan** yang
  menghasilkan **capability**.
- Murobi = guru dengan penugasan murobi aktif; pembimbing = pengurus dengan
  penugasan pembimbing aktif — tidak berubah.
- Capability dihitung ulang server dari: akun aktif → role dasar (dibaca ulang
  dari basis data) → relasi master aktif → penugasan aktif → masa berlaku pada
  tanggal berjalan → tahun ajaran → cakupan. Sesi dan menu bukan sumber otorisasi.
- Satu akun boleh memegang beberapa penugasan sekaligus. Berakhirnya penugasan
  tidak menonaktifkan akun dan tidak mencabut role dasar.
- Admin memperoleh seluruh capability fondasi sebagai **pengawasan**
  (`sumber = admin`), dan resolver membedakannya dari pelaku operasional
  (`sumber = penugasan`). Seluruh tindakan admin pada penugasan diaudit.
- Tidak ada penghapusan permanen penugasan: **akhiri** (tanggal selesai) atau
  **nonaktifkan** (`is_active = 0`).

## Dokumen

| Berkas | Isi |
| --- | --- |
| [`ringkasan-desain.md`](ringkasan-desain.md) | desain, hubungan akun–master–penugasan–capability, aturan masa berlaku, duplikasi, dan tumpang tindih |
| [`matriks-capability.md`](matriks-capability.md) | matriks role dasar × penugasan × capability × cakupan; metode resolver |
| [`migrasi-dan-rollback.md`](migrasi-dan-rollback.md) | migrasi 012, pre-check, post-check, rollback |
| [`kontrak-api.md`](kontrak-api.md) | kontrak kompatibilitas API dan aplikasi perangkat |
| [`panduan-admin.md`](panduan-admin.md) | cara memakai Pusat Penugasan dan halaman Akun |
| [`test-results.md`](test-results.md) | hasil pengujian secara jujur: LULUS / BELUM DIJALANKAN / MEMERLUKAN UJI MYSQL / MEMERLUKAN SMOKE TEST |
| [`cpanel-deployment.md`](cpanel-deployment.md) | panduan migrasi cPanel dan ceklis smoke test |
| [`acceptance-status.md`](acceptance-status.md) | status kriteria penerimaan, risiko terbuka, pekerjaan lanjutan |

## Berkas yang dibuat paket ini

```
database/migrations/012_fondasi_penugasan_v3_v6.sql   migrasi aditif dan idempoten
database/rollbacks/012_fondasi_penugasan_v3_v6.sql    rollback berpasangan
app/Penugasan/PenugasanJenis.php                      katalog tujuh jenis penugasan + capability
app/Penugasan/PenugasanException.php                  kesalahan aman (403/404/409/422)
app/Penugasan/PenugasanRepository.php                 seluruh query penugasan, prepared statement
app/Penugasan/PenugasanService.php                    satu-satunya pintu mutasi (transaksi + audit)
admin/admin_penugasan.php                             Pusat Penugasan (7 tab + master mata pelajaran)
bin/penugasan_preflight.php                           pre-check migrasi (hanya membaca)
bin/penugasan_verify.php                              post-check migrasi (hanya membaca)
bin/penugasan_run_all_tests.sh                        penjalan seluruh pengujian
tests/penugasan_static.php                            pemeriksaan statis
tests/penugasan_integration.php                       pengujian integrasi basis data
tests/penugasan_web_smoke.php                         smoke test HTTP + kontrak API
docs/fondasi-penugasan-v3-v6/                         dokumentasi ini
```

## Berkas yang diubah

```
app/Auth/Capabilities.php      + feature capability (metode feature*), forUser() tidak berubah
app/Api/ApiAuthService.php     + field aditif feature_capabilities pada profil dan login
app/bootstrap.php              + penugasan_service()
app/Ui/Navigation.php          + menu Pusat Penugasan; menu murobi/pembimbing lama tetap
admin/admin_akun.php           + ringkasan penugasan efektif, penjelasan role vs penugasan, tautan
admin/admin_murobi.php         + tautan ke Pusat Penugasan (fungsi lama utuh)
admin/admin_pembimbing.php     + tautan ke Pusat Penugasan (fungsi lama utuh)
design.md                      + bagian fondasi penugasan V3–V6
docs/perapihan-v1-v2/matriks-hak-akses.md   + bagian 9 (Pusat Penugasan)
docs/api-v1.md                 + catatan field aditif feature_capabilities
PRD-V2.md                      + addendum 6c (paket fondasi, bukan fase baru)
```

Repositori `alhasanApps` **tidak diubah**.

## Menjalankan pengujian

```bash
php bin/migrate.php up
bash bin/penugasan_run_all_tests.sh
```

Seluruh rangkaian ber-basis-data menolak berjalan bila `DB_NAME` tidak
berakhiran `_test`.
