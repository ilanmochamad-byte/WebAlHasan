# Status penerimaan, risiko, dan pekerjaan lanjutan

Keputusan pengguna 7 September 2026. Branch `feat/fondasi-penugasan-v3-v6`.
Status paket: **implementasi selesai, menunggu audit Codex**. Belum di-merge,
belum di-deploy, migrasi produksi belum dijalankan.

## 1. Kriteria penerimaan

| # | Kriteria | Status | Bukti |
| --- | --- | --- | --- |
| 1 | Role dasar tidak berubah | **Terpenuhi** | FS-2/FS-3; FI-1; `roles` tetap 4 (verify) |
| 2 | Penugasan fungsional tidak dibuat sebagai role login | **Terpenuhi** | FS-2; migrasi tanpa `INSERT INTO roles`; katalog hanya role dasar guru/pengurus |
| 3 | Seluruh jenis penugasan V3–V6 dapat dikelola admin | **Terpenuhi** | FI-4; FW-5 (tujuh jenis + master mata pelajaran lewat formulir) |
| 4 | Capability dihitung server-side dari penugasan aktif dan cakupannya | **Terpenuhi** | FI-7/12; FS-10 (role dibaca ulang dari basis data) |
| 5 | Masa berlaku dan tahun ajaran diperhitungkan | **Terpenuhi** | FI-6/8/26 |
| 6 | Duplikat/tumpang tindih dicegah | **Terpenuhi** | FI-13/14; FW-6; kunci unik basis data |
| 7 | Satu akun dapat memiliki beberapa penugasan | **Terpenuhi** | FI-9/10/11 |
| 8 | Capability berakhir ketika penugasan berakhir | **Terpenuhi** | FI-8 |
| 9 | Seluruh mutasi memakai POST, CSRF, transaksi, audit | **Terpenuhi** | FS-7/8; FI-15/24; FW-3/4/7g |
| 10 | Tidak ada hard delete | **Terpenuhi** | FS-7/8; UI tanpa tombol hapus |
| 11 | Halaman admin konsisten dengan navigasi portal | **Terpenuhi** | Layout/Navigation bersama; FW-1 |
| 12 | API lama tetap kompatibel | **Terpenuhi** | FI-19/20; FW-10; snapshot laporan V1 pada regresi |
| 13 | Aplikasi perangkat tidak perlu diperbarui | **Terpenuhi** (kontrak) / **MEMERLUKAN SMOKE TEST** (aplikasi terpasang) | `alhasanApps` tidak diubah; field aditif; lihat test-results §5 |
| 14 | Fitur V1–V2 tidak mengalami regresi | **Terpenuhi** (otomatis) | seluruh rangkaian integrasi/smoke V1–V2 lulus; 3 statis gagal karena lingkungan (lihat test-results §4) |
| 15 | Tidak ada fitur bisnis V3–V6 yang dikerjakan | **Terpenuhi** | FS-15; migrasi tanpa tabel bisnis |
| 16 | Branch di-push tanpa merge dan tanpa deployment | lihat laporan akhir | — |

## 2. Risiko terbuka

| Risiko | Dampak | Mitigasi / tindak lanjut |
| --- | --- | --- |
| **Dua jalur mutasi murobi/pembimbing.** Halaman lama memakai `MasterDataService::saveMurobi()` / `PembimbingService`, pusat memakai `PenugasanService`. Jalur lama tidak menjalankan pemeriksaan tumpang tindih periode (hanya kunci unik tanggal mulai). | Penugasan bertumpang tindih masih dapat dibuat lewat halaman lama. | Setelah audit, arahkan POST halaman lama ke `PenugasanService` (URL tetap) atau alihkan halaman lama ke pusat. Dicatat sebagai pekerjaan lanjutan; sengaja tidak dilakukan agar fungsi lama dan pengujian lama tetap utuh. |
| **Gelombang PSB tanpa master.** Label bebas divalidasi tetapi tidak ber-FK. | Ejaan berbeda ("1" vs "I") dianggap gelombang berbeda. | PRD V6 menentukan master gelombang; migrasi lanjutan menambah `gelombang_id` dan memasangkan dari label. |
| **Jenjang mengikuti `kelas.jenjang` bebas.** | Konsistensi bergantung pada disiplin pengisian kelas. | Opsi formulir hanya menawarkan jenjang yang benar-benar dipakai kelas. |
| **Tanggal berjalan.** Resolver memakai `CURDATE()` MySQL (konsisten dengan murobi V2); label status halaman memakai tanggal PHP (`Asia/Jakarta`). | Bila zona waktu server MySQL berbeda, sekitar tengah malam status dan capability dapat berbeda beberapa jam. | Samakan zona waktu MySQL dan PHP di hosting (sudah menjadi asumsi V2). |
| **Beban halaman akun.** Ringkasan penugasan efektif menjalankan resolver per baris akun (≤ 7 query per baris, 20 baris). | Halaman akun sedikit lebih lambat. | Terukur kecil pada data uji; bila perlu, tambahkan query ringkasan tunggal. |
| **`mapel` warisan dan `mengajar` warisan** tidak disentuh. | Tidak ada; keduanya kosong dan tidak dipakai kode. | Bila V4 memutuskan memakainya, migrasi terpisah. |
| **Uji peramban visual, Safari fisik, pembaca layar** belum dijalankan. | Tampilan pada 390 px dan `<details>` di Safari belum dibuktikan. | Smoke test manusia (`cpanel-deployment.md` §9). |
| **Migrasi pada data produksi** belum dijalankan (hanya pada database uji lokal). | — | Panduan `cpanel-deployment.md`. |

## 3. Pekerjaan lanjutan (bukan bagian paket ini)

1. Konsolidasi jalur lama murobi/pembimbing ke `PenugasanService` setelah audit.
2. PRD V3: modul konseling/pelanggaran memakai `murobi.binaan` / `pembimbing.binaan` + `featureAppliesToKamar/Kelas`.
3. PRD V4: tabel nilai/rapor memakai `nilai.*` (guru mapel, cakupan kelas+mapel+tahun) dan `rapor.*` (Bagian Pendidikan, cakupan jenjang); aksi admin pengganti membaca `featureSource()`.
4. PRD V5: tagihan/pembayaran memakai `pembiayaan_bulanan.*`.
5. PRD V6: master gelombang, `psb.*` dan `psb_keuangan.*` terpisah.
6. Menu aplikasi perangkat untuk modul itu dibuat pada versi aplikasi yang mendukungnya, berbasis respons server.

## 4. Penegasan

Fitur bisnis PRD V3–V6 **belum diimplementasikan** pada paket ini. Yang ada
hanyalah fondasi penugasan, capability, administrasi, audit, dan kontrak akses.
