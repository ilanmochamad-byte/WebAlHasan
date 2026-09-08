# Bukti ringkas smoke produksi — 8 September 2026

Pengujian dilakukan pada hasil deploy `main` merge `735dc6d` di
`alhasan.co.id`. Mutasi dibatasi pada akun dan data fiktif berawalan
`SMOKE AUDIT`; data lama tidak diubah. Dokumen ini tidak menyimpan password,
token sesi, dump basis data, alamat IP, atau URL sesi cPanel.

## Migrasi cPanel

- MariaDB: `10.6.27-MariaDB-cll-lve`.
- Migrasi 001–012 berstatus diterapkan; migrasi 012 tercatat pada
  7 September 2026 pukul 11:01:28.
- `penugasan_preflight.php`: tidak ada penghalang; role dasar tepat empat;
  manifest saat verifikasi memuat 1 murobi dan 9 pembimbing.
- `penugasan_verify.php --murobi=1 --pembimbing=9`: seluruh 154 pemeriksaan
  lulus, exit 0. Kolom, FK, indeks, CHECK, kolom lama, jumlah data lama, tabel
  baru yang semula kosong, audit, empat role, dan resolver capability lolos.
- Percobaan verify sebelumnya memakai ekspektasi murobi 12 yang salah dan
  menghasilkan satu kegagalan pembandingan. Rerun dengan manifest aktual 1/9
  lulus; kegagalan awal bukan kegagalan skema.
- `system_time_zone=WIB`, sesi `SYSTEM`; `CURDATE()` menghasilkan
  8 September 2026 pada waktu pemeriksaan.

Rollback tidak dijalankan pada produksi. Siklus rollback/migrasi ulang telah
lulus pada database uji terpisah sebagaimana `migration-drill.txt`.

## Interaksi Pusat Penugasan

Master uji: Guru, Pengurus, Orang Tua, Kelas, Kamar, dan mata pelajaran
`SMOKE AUDIT MATEMATIKA` (`SMK-AUD-0908`). Penugasan uji yang diperiksa:

| Jenis | ID | Hasil akhir |
| --- | ---: | --- |
| Guru mata pelajaran | 1 | berakhir dan nonaktif |
| Murobi | 3 | diarsipkan |
| Pembimbing | 11 | diarsipkan |
| Bagian Pendidikan | 1 | berakhir dan nonaktif |
| Bendahara Pembiayaan Bulanan | 1 | berakhir dan nonaktif |
| Panitia PSB | 1 | berakhir dan nonaktif |
| Bendahara PSB | 1 | berakhir dan nonaktif |
| Mata pelajaran | 1 | diarsipkan/nonaktif |

Pembuatan, perubahan catatan, nonaktif/aktif, pengakhiran, dan arsip yang
tersedia di UI berhasil. Pembuatan duplikat/overlap guru mapel, murobi, dan
pembimbing ditolak dengan pesan domain. Mutasi halaman lama murobi/pembimbing
terlihat konsisten di Pusat Penugasan. Tidak ada hard delete.

## Hak akses dan kompatibilitas

- Halaman akun menampilkan tepat empat role dasar.
- Sesi Orang Tua, Guru, dan Pengurus memuat dashboard/modul lama yang sesuai.
- Menu bisnis V3–V6 tidak muncul pada ketiga sesi.
- Akses langsung ketiga sesi ke halaman admin menghasilkan HTTP 403.
- Jadwal, laporan pengajian, dan ringkasan perizinan dapat dibuka sesuai role.

## Audit basis data

Kueri baca-saja terhadap `audit_logs` menemukan 34 mutasi untuk delapan entitas
uji di atas, ID audit 431–497 (tidak kontigu), seluruhnya oleh Administrator.
Urutannya mencakup buat, ubah, aktif/nonaktif, akhiri, dan arsip/status.
`before_json`/`after_json` terisi pada perubahan status dan pengakhiran.

Terdapat 20 catatan `penugasan.capability_berubah` (ID 433–484, tidak kontigu)
dengan pemicu entitas/ID yang tepat. Capability guru mapel, murobi, pembimbing,
pendidikan, pembiayaan bulanan, panitia PSB, dan bendahara PSB tercatat saat
bertambah maupun berkurang.

Tiga penolakan tumpang tindih tercatat:

| ID audit | Entitas | Pesan |
| ---: | --- | --- |
| 434 | `guru_mapel_assignment` | Penugasan bertumpang tindih dengan penugasan aktif |
| 443 | `murobi_assignment` | Penugasan bertumpang tindih dengan penugasan aktif |
| 451 | `pembimbing_assignment` | Penugasan bertumpang tindih dengan penugasan aktif |

Pesan tidak memuat galat database mentah.

## Safari dan aksesibilitas dasar

Smoke memakai Safari produksi pada desktop serta Responsive Design Mode 768 ×
1024 dan 390 × 844. Menu navigasi dapat dibuka/ditutup, tab penugasan dapat
dipindah, label kontrol muncul pada accessibility tree, dan fokus keyboard
mencapai kontrol formulir. Pada kedua ukuran responsif hanya scrollbar vertikal
yang muncul; formulir utama tidak memerlukan scroll horizontal.

VoiceOver fisik, Safari iOS, dan aplikasi Android/iOS terpasang tidak diuji.
Kompatibilitas mobile tetap didukung oleh lint/typecheck, tes client/kontrak,
dan smoke sesi role produksi tanpa perubahan kode mobile.
