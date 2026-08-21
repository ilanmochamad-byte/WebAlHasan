# Inventaris Skema — V2 Fase 1

Dokumen ini merangkum kondisi skema yang menjadi titik awal V2 Fase 1 dan apa yang
ditambahkan oleh migrasi `006_v2_phase1_perizinan_foundation.sql`. Angka jumlah baris
tidak dicantumkan di sini: angka aktual dihasilkan oleh
`php bin/v2_phase1_preflight.php` ke `storage/backups/v2-phase1/<timestamp>/`
(`manifest.json`, `inventory.json`, `report.md`).

## 1. Tabel V1 yang dipakai kembali (tidak diubah nilainya)

| Tabel | Peran pada V2 | Perubahan pada Fase 1 |
|---|---|---|
| `perizinan` | Sumber migrasi izin lama | **Tidak disentuh sama sekali** |
| `users` | Akun untuk seluruh peran | +`pengurus_id`, +`wali_id` (nullable, unik, FK) |
| `roles`, `user_roles` | Otorisasi V1 | +2 baris role: `pengurus`, `orang_tua` |
| `pengurus` | Master pengurus | Tidak diubah |
| `wali`, `santri_wali` | Master wali & relasi santri | Tidak diubah |
| `murobi_assignments` | Sumber hak murobi | Tidak diubah |
| `plotting_kamar`, `plotting_kelas` | Penentu cakupan pembimbing | Tidak diubah |
| `santri`, `guru`, `kamar`, `kelas`, `tahun_ajaran` | Master data | Tidak diubah |
| `audit_logs` | Jejak audit | Dipakai untuk peristiwa akun & pembimbing |
| `api_tokens`, `api_idempotency_keys` | Kontrak API V1 | **Tidak diubah** (V2 memakai tabel sendiri) |

## 2. Tabel baru V2

| Tabel | Isi |
|---|---|
| `pembimbing_assignments` | Penugasan pembimbing pengurus per kamar/kelas dan tahun ajaran |
| `izin_pengajuan` | Pengajuan izin V2 **dan** izin warisan V1 (ID dipertahankan) |
| `izin_keputusan` | Satu keputusan final per pengajuan, termasuk kapasitas pemberi keputusan |
| `izin_riwayat_status` | Riwayat peristiwa yang tidak pernah ditimpa (pelaku, alasan, IP, user agent) |
| `izin_idempotency_keys` | Idempotensi mutasi perizinan (create/decision/cancel/reassign) |
| `notifikasi_outbox` | Outbox notifikasi dengan kunci unik peristiwa/kanal/penerima |
| `perangkat_push` | Token perangkat push per pengguna/perangkat, dapat dicabut |
| `pengaturan_notifikasi` | Sakelar kanal in-app/push/WhatsApp + hasil pemeriksaan konfigurasi |

## 3. Model identitas & kemampuan

Kemampuan dihitung ulang di server oleh `App\Auth\Capabilities`:

| Kemampuan | Syarat |
|---|---|
| `admin` | Role `admin` |
| `pengurus` | Role `pengurus` **dan** `users.pengurus_id` → pengurus aktif & belum diarsipkan |
| `murobi` | Role `guru` **dan** ada `murobi_assignments` aktif pada tanggal berjalan di tahun ajaran aktif |
| `orang_tua` | Role `orang_tua` **dan** `users.wali_id` → wali aktif & belum diarsipkan |

Tidak ada role `murobi`. Guru tanpa penugasan murobi aktif tidak memperoleh
kemampuan keputusan izin, sesuai PRD 5.2 dan keputusan implementasi butir 2.

## 4. Cakupan data per kemampuan

| Kemampuan | Pengajuan yang terlihat |
|---|---|
| `admin` | Seluruh pengajuan |
| `pengurus` | `izin_pengajuan.pengurus_id` = pengurus yang terhubung dengan akun |
| `murobi` | `izin_pengajuan.murobi_guru_id` = guru yang terhubung dengan akun |
| `orang_tua` | Santri dengan baris `santri_wali` **aktif** untuk wali yang terhubung |

Cakupan ditambahkan di dalam `IzinRepository::conditions()` sehingga tidak ada jalur
pemanggilan yang dapat melewatinya lewat parameter request. Cakupan yang tidak
dikenal menghasilkan kondisi `1 = 0`.

## 5. Data warisan

Pengajuan hasil migrasi ditandai `is_legacy = 1` dan `legacy_perizinan_id` = ID lama.
Kolom `pengurus_id`, `murobi_guru_id`, `diajukan_oleh_user_id`, `pembimbing_assignment_id`,
`tahun_ajaran_id`, dan `diajukan_pada` bernilai `NULL` karena sistem V1 tidak
mencatatnya. UI menampilkannya sebagai **Data warisan**; tidak ada akun admin palsu
yang dipakai sebagai pengganti pelaku.
