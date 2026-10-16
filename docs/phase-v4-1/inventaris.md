# Inventaris sebelum Fase 1 V4

Baseline web: `origin/prd-v4-spec` = `be11e2eeccda8c6f0449f3432c9940e5a24518a0`, bersih sebelum pekerjaan. Baseline mobile: `main` = `dfae26d`, bersih. Migrasi tertinggi yang benar-benar ada adalah `019_v3_fase5_laporan_retensi.sql`; karena itu Fase 1 memakai `020`.

| Domain | Sumber yang sudah ada | Keputusan Fase 1 |
| --- | --- | --- |
| Semester | `tahun_ajaran.id`, pasangan `tahun` + `semester` | Semua referensi V4 memakai ID ini. |
| Kelas dan penempatan | `kelas`, `plotting_kelas(id_santri,id_kelas,id_tahun)` | Rencana merujuk `kelas`; penempatan tidak diubah. Ambiguitas pindah kelas ditangani pada fase finalisasi, belum ditebak. |
| Mata pelajaran | `mata_pelajaran` (migrasi 012), tabel warisan `mapel` | V4 hanya memakai `mata_pelajaran`; `mapel` pada salinan uji berisi 0 baris, tidak di-backfill. |
| Guru/Pendidikan | `guru_mapel_assignments`, `pendidikan_assignments`, `App\Auth\Capabilities` | Capability baru tetap berasal dari penugasan aktif dan cakupan tahun/kelas/mapel atau jenjang. |
| Sikap | `pembimbing_assignments`, `murobi_assignments` | Fondasi tabel sikap dibuat; alur pengisian belum dibuka. |
| Kehadiran | `pertemuan_peserta`, `absensi_santri` | Tidak diubah; snapshot/rekap kehadiran dilakukan fase rapor. |
| Audit | `audit_logs`, `AuditLogger` | Mutasi rencana/penanda tangan dicatat transaksional dengan ID/hash saja. |
| Notifikasi | `notifikasi_outbox`, perangkat push/receipt | Tidak disentuh pada Fase 1; event rapor terbit adalah fase lanjut. |
| Nilai/rapor lama | Tidak ada tabel nilai/rapor pada salinan V1–V3 sebelum 020 | Tidak ada backfill; 11 tabel V4 baru dimulai kosong. |

Salinan uji dibuat dari `k1807225_webalhasan.sql` lalu migrasi 001–019. `plotting_kelas` berisi 355 baris pada salinan itu. Inventaris produksi wajib dicek ulang melalui preflight operator; angka salinan uji bukan klaim produksi.
