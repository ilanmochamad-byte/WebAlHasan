# Rancangan — Penugasan lintas guru/pengurus dan penugasan Keamanan

Status: **disetujui Human Developer 30 Oktober 2026** (PK-1…PK-7, PK-T1…PK-T8). Fase 1 lulus audit Codex dan digabung ke `main` (PR #103); Fase 2
lulus audit Codex dan digabung ke `main` (PR #104, `fase-2/AUDIT-CODEX.md`); Fase 3 (Keamanan di website)
diimplementasikan Claude Code pada `claude/penugasan-keamanan-fase-3` dan **lulus audit teknis lokal Codex
31 Oktober 2026 setelah tiga koreksi terarah** (`fase-3/AUDIT-CODEX.md`) dan digabung ke `main` (PR #105). Fase 4 (aplikasi)
diimplementasikan Claude Code pada `claude/penugasan-keamanan-fase-4` di kedua repositori dan **lulus audit teknis lokal Codex
31 Oktober 2026 setelah satu koreksi P3** (`fase-4/AUDIT-CODEX.md`; iOS/fisik/produksi belum diverifikasi) dan digabung ke `main` (PR #106). Fase 5 (kesiapan rilis)
diimplementasikan Claude Code pada `claude/penugasan-keamanan-fase-5` 31 Oktober 2026 dan menunggu audit Codex (`fase-5/README.md`). Branch rancangan
`claude/rancangan-penugasan-keamanan` dari `origin/main` `cbb5aa1` hanya berisi
dokumen. Belum ada deploy atau migrasi produksi.

## 1. Latar belakang (keterangan Human Developer, 30 Oktober 2026)

Fakta di lapangan:

- **Murobi** dan **Guru Mata Pelajaran** hanya dipegang guru.
- **Pembimbing, Bagian Pendidikan, Petugas Kesehatan, Petugas BSA, Panitia PSB,
  dan Bendahara PSB** dipegang guru maupun pengurus.
- Dibutuhkan penugasan baru **Keamanan** yang hanya **melihat** aktivitas santri:
  kehadiran pengajian, perizinan, serta pembinaan (pelanggaran dan pembinaan).

Sistem saat ini belum mendukung fakta tersebut:

| Lapisan | Aturan sekarang | Akibat |
| --- | --- | --- |
| `App\Penugasan\PenugasanJenis` | Enam jenis di atas berole dasar `pengurus`, subjek `pengurus_id` | Guru tidak dapat dipilih sebagai subjek |
| `App\Account\PerizinanAccountService::link()` | Akun guru ditolak dihubungkan ke master pengurus | Guru tidak dapat memperoleh identitas pengurus |
| `PRD-V2.md` §5.1 | "Pembimbing adalah tugas inti/tambahan pengurus, bukan guru atau murobi" **[JANGAN DIUBAH]** | Keputusan terkunci yang harus dibuka Human Developer |

Lebih dari 60 berkas PHP (izin V2/V7, pembinaan V3, rapor V4, kesehatan V5,
BSA V6, notifikasi, laporan) dan beberapa berkas aplikasi bergantung pada
`pengurus_id` sebagai pemegang penugasan.

## 2. Keputusan Human Developer (30 Oktober 2026)

| Kode | Keputusan |
| --- | --- |
| **PK-1** | Pendekatan **A — satu akun, dua identitas**: akun guru dapat memegang identitas pengurus yang dibentuk dari data gurunya sendiri. Penugasan jenis pengurus tetap merujuk `pengurus_id`, sehingga modul V2–V7 yang sudah diaudit tidak ditulis ulang. Pendekatan B (subjek penugasan = akun) ditolak karena risikonya besar. |
| **PK-2** | Murobi dan Guru Mata Pelajaran **tetap khusus guru**. |
| **PK-3** | Pembimbing, Bagian Pendidikan, Petugas Kesehatan, Petugas BSA, Panitia PSB, Bendahara PSB, dan Keamanan dapat ditugaskan kepada **guru atau pengurus** yang terdaftar di database. |
| **PK-4** | Keputusan ini **membuka dan menggantikan** butir Pembimbing pada `PRD-V2.md` §5.1. Inti yang dipertahankan: pembimbing tetap penugasan berbasis identitas pengurus, dan murobi tetap guru. Yang berubah: identitas pengurus itu boleh milik seorang guru. |
| **PK-5** | Jenis penugasan baru **Keamanan**: cakupan **seluruh santri aktif**, tidak gugur saat semester berganti (seperti Petugas Kesehatan), **hanya baca**. |
| **PK-6** | Keamanan melihat **pelanggaran dan kasus pembinaan berkerahasiaan Internal**. Kasus **Rahasia tetap tertutup**, sama dengan aturan murobi saat ini. |
| **PK-7** | Implementator **Claude Code**, auditor **Codex**, bergantian satu fase per giliran. |

## 3. Rancangan

### 3.1 Identitas pengurus milik guru (PK-1, PK-3)

- Migrasi aditif berikutnya (nomor setelah 031, misal
  `032_penugasan_identitas_guru_keamanan.sql`) + rollback berpasangan
  berpenjaga data: kolom `pengurus.guru_id` NULL, UNIQUE, FK ke `guru`.
  Baris pengurus biasa tetap `guru_id = NULL`; satu guru paling banyak satu
  identitas pengurus.
- Akun guru boleh memegang `pengurus_id` **hanya** bila baris pengurus itu
  memiliki `guru_id` yang sama dengan `users.guru_id` akun tersebut. Akun guru
  tetap tidak dapat dihubungkan ke pengurus milik orang lain atau ke wali.
- Pembentukan identitas dilakukan **dalam satu transaksi** saat admin menugaskan
  seorang guru ke jenis berbasis pengurus: buat atau pakai ulang baris pengurus
  (nama dan nomor HP disalin dari data guru, jabatan "Guru"), hubungkan ke akun,
  dan berikan role `pengurus`. Seluruh langkah diaudit. Admin tidak perlu
  mengisi data ganda secara manual.
- **Status mengikuti guru:** identitas pengurus milik guru hanya sah bila data
  guru juga aktif dan tidak diarsipkan. Pemeriksaan ini ditambahkan di
  `Capabilities` (sumber kebenaran hak), sehingga menonaktifkan guru langsung
  menggugurkan seluruh penugasan jenis pengurus miliknya pada permintaan
  berikutnya, termasuk sesi dan token lama.
- **Halaman Penugasan:** pemilih subjek untuk jenis berbasis pengurus
  menampilkan dua kelompok, "Pengurus" dan "Guru". Murobi dan Guru Mapel
  tetap hanya menampilkan guru.
- **Peran aktif:** akun guru yang memegang penugasan jenis pengurus melihat
  pemilih peran Guru/Pengurus yang sudah ada (redesain Tahap 2). Tidak ada role
  login baru.

### 3.2 Pemisahan tugas (wajib, akibat PK-1)

Karena satu orang kini dapat menjadi murobi (sebagai guru) **dan** pembimbing
(sebagai pengurus) atas santri yang sama, aturan "pengaju bukan pemutus" harus
ditegakkan di server pada level **akun**, bukan identitas master:

- **Izin V2 dan V7:** routing murobi mengecualikan akun pengaju/peninjau. Bila
  satu-satunya murobi sah adalah orang yang sama, pengajuan masuk
  `Perlu Penetapan Admin`. Keputusan oleh akun yang sama dengan pengaju ditolak
  walau dikirim langsung ke API.
- **Sikap V4:** murobi tidak dapat "mengetahui" sikap yang ia isi sendiri
  sebagai pembimbing.
- **Rapor V4:** lihat PK-T1 dan PK-T5.
- **Tanda "mengetahui" murobi pada pembinaan V3** (pelanggaran, kasus, sesi):
  ditambahkan saat Fase 2 dengan prinsip yang sama dengan sikap (PK-T7), lalu
  **disetujui eksplisit Human Developer saat audit 30 Oktober 2026** (PK-T8).
  Pengisi/pemilik tidak mengetahui catatan sendiri; riwayat revisi tetap dihitung.
- Kesehatan dan BSA tidak memiliki alur dua pihak, sehingga tidak terdampak.

### 3.3 Penugasan Keamanan (PK-5, PK-6)

Definisi di `PenugasanJenis`:

| Atribut | Nilai |
| --- | --- |
| Jenis / label | `keamanan` / "Keamanan" |
| Subjek / role dasar | pengurus (termasuk identitas pengurus milik guru) / `pengurus` |
| Cakupan | seluruh santri aktif (`CAKUPAN_SELURUH`) |
| Tahun ajaran aktif wajib | tidak |
| Capability | `keamanan.pantau` (hanya baca) |

Yang dapat dibaca (semuanya GET; seluruh POST/PUT/DELETE ditolak):

1. **Kehadiran pengajian:** laporan kehadiran seluruh jadwal, dengan pola yang sama
   seperti hak baca Bagian Pendidikan (keputusan H) di `admin/_laporan_guard.php`.
2. **Perizinan:** daftar dan rincian izin seluruh santri aktif, asal Pengurus (V2)
   maupun Wali (V7), termasuk status, tanggal keluar/kembali, dan riwayat.
   Tidak ada tombol ajukan, putus, tinjau, koreksi, atau batal.
3. **Pembinaan:** pelanggaran dan kasus/sesi pembinaan Internal. Kasus Rahasia
   tidak muncul di daftar, angka ringkasan, rincian, maupun pencarian, dengan
   penyaring kerahasiaan yang sama dengan murobi.

Setiap pembukaan rincian dicatat di audit tanpa isi. Menu Keamanan berada pada
peran Pengurus (grup "Keamanan"). Peta menu admin tidak berubah.

## 4. Fase

| Fase | Isi | Repositori |
| --- | --- | --- |
| **1** | Identitas pengurus milik guru: migrasi + rollback, aturan akun, `Capabilities` (status mengikuti guru), pemilih subjek halaman Penugasan, audit. Belum ada perubahan modul. | web |
| **2** | Pemisahan tugas: routing/keputusan izin V2 dan V7, sikap V4, serta rapor sesuai PK-T1. Regresi V2/V3/V4/V7. | web |
| **3** | Keamanan di website: jenis + capability, jalur baca kehadiran/izin/pembinaan, menu, audit akses, uji penolakan mutasi dan kerahasiaan. | web |
| **4** | Aplikasi: tampilan akun dua identitas, layar baca Keamanan (sesuai PK-T3), endpoint REST baca-saja. | web + mobile |
| **5** | Rilis: preflight/verifier/manifest/latihan pemulihan, runbook cPanel, checklist smoke. | web (+ mobile) |

Satu fase per giliran. Implementator berhenti sesudah commit/push untuk audit
Codex; fase berikutnya hanya atas instruksi Human Developer.

## 5. Keputusan lanjutan Human Developer (30 Oktober 2026)

| Kode | Pertanyaan | Keputusan | Berlaku untuk |
| --- | --- | --- | --- |
| **PK-T1** | Bolehkah Bagian Pendidikan memverifikasi rapor yang memuat nilai yang ia isi sendiri sebagai Guru Mapel? | **Tidak boleh**; verifikasi itu dilakukan petugas Pendidikan lain atau admin | Fase 2 |
| **PK-T2** | Bolehkah Keamanan mencetak atau mengekspor laporan? | **Cetak PDF boleh.** Ekspor CSV tidak disebut, sehingga tidak disediakan (mengikuti rekomendasi) sampai Human Developer memutuskan lain | Fase 3 |
| **PK-T3** | Apakah Keamanan juga tersedia di aplikasi? | **Ya, hanya baca** | Fase 4 |
| **PK-T4** | Apakah Keamanan melihat alasan izin dan catatan pelanggaran secara lengkap? | **Ya**; hanya kasus Rahasia yang tertutup (PK-6) | Fase 3 |
| **PK-T5** | Seberapa luas larangan PK-T1? | **Per bahan nilai**: tidak memverifikasi/mengembalikan nilai mapel yang ia isi sendiri; mapel lain, sikap, kehadiran, finalisasi, dan penerbitan tetap boleh setelah nilainya diverifikasi pihak lain | Fase 2 |
| **PK-T6** | Apakah "pengaju bukan pemutus" pada izin juga berlaku bagi admin pengganti? | **Ya, berlaku juga untuk admin**: akun yang mengajukan/meninjau tidak memutus atau mengoreksi; pengajuan buatan admin diputus murobi atau admin lain | Fase 2 |
| **PK-T7** | Bila satu-satunya murobi sah juga pengisi sikap (sebagai pembimbing)? | **Tertahan, tanpa jalur baru**: murobi lain yang sah yang mengetahui; bila tidak ada, rapor menampilkan penghalang sampai admin mengubah penugasan | Fase 2 |
| **PK-T8** | Pertahankan larangan murobi mengetahui pelanggaran/kasus/sesi V3 miliknya sendiri (PF2-6), walau daftar awal Fase 2 hanya menyebut izin dan rapor? | **Ya, pertahankan larangan V3**; jawaban eksplisit Human Developer saat audit Codex 30 Oktober 2026 | Fase 2 |

PK-T5…PK-T7 dijawab Human Developer pada 30 Oktober 2026 saat implementasi Fase 2.
PK-T8 dijawab saat audit Fase 2; PF2-6 tidak lagi menunggu keputusan kebijakan.

## 6. Batas

- Tidak mengubah role login (`admin`, `guru`, `pengurus`, `orang_tua`) dan tidak
  membuat sistem akun kedua.
- Tidak mengubah data historis. Penugasan lama tetap merujuk `pengurus_id` yang sama.
- Modul V2–V7 hanya berubah pada pemisahan tugas (Fase 2) dan jalur baca Keamanan
  (Fase 3).
- Gerbang produksi V5/V6/V7 yang masih terbuka tidak tertutup oleh pekerjaan ini.
- Tidak merge, deploy, migrasi produksi, atau rilis aplikasi tanpa keputusan
  Human Developer.

## 7. Prompt handoff

**Implementasi oleh Claude Code:**
> *"Implementasikan hanya Fase [X] penugasan lintas guru/pengurus dan Keamanan pada branch claude/penugasan-keamanan-fase-[X]. Baca AGENTS.md, docs/penugasan-keamanan/rancangan.md, dan hasil audit fase sebelumnya. Verifikasi baseline, uji dan catat bukti nyata, commit serta push branch fitur, lalu berhenti untuk audit Codex. Jangan melanjutkan ke fase berikutnya, merge, atau deploy."*

**Audit oleh Codex:**
> *"Audit hanya Fase [X] penugasan lintas guru/pengurus dan Keamanan buatan Claude Code pada branch claude/penugasan-keamanan-fase-[X]. Baca AGENTS.md, docs/penugasan-keamanan/rancangan.md, baseline, dan seluruh commit fase. Uji independen, koreksi terarah bila perlu, catat temuan dan batas uji secara jujur, commit dan push hasil audit, lalu berhenti. Jangan memulai fase berikutnya, merge, atau deploy."*
