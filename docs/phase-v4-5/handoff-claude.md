# Handoff ke Claude Code — hanya audit PRD V4 Fase 5

Audit secara independen sesudah Codex berhenti. Jangan menjalankan dua agen pada checkout yang sama secara bersamaan. Kedua branch `codex/prd-v4-fase-5`; tidak ada worktree.

| Repo | Baseline audit/remote | Commit implementasi |
| --- | --- | --- |
| WebAlHasan | 831017ac37a09d4465e9f64131f212f040410420 | 2deb8f93b8404200aa12ae5a218cddb96e7b1b98 |
| alhasanApps | dfae26d69fe776492cfda274d10fddf79a1590f2 | c969374eb24d9d12bb065f03d0f5516f91b1a30f |

HEAD akhir termasuk commit bukti/dokumen diumumkan pada chat dan dapat diperiksa `git rev-parse HEAD`/remote branch. Audit seluruh diff terhadap baseline, bukan hanya commit dokumentasi terakhir. Manifest mencatat hash kode dan lock masing-masing repo.

## Perubahan utama

Web menambah capability aditif untuk menu aplikasi, mempersempit respons API wali melalui allowlist setelah otorisasi yang sama, serta mengoreksi uji statis path laporan/validator notifikasi. Snapshot/PDF lama dan semua keputusan [JANGAN DIUBAH] tetap. Mobile menambah hub/menu server, nilai guru/pengganti, sikap pembimbing/murobi, verifikasi/riwayat/gate pratinjau/penerbitan, rapor wali dan byte PDF kanonis. Konflik 409 memuat ulang server, tujuan notifikasi/deep-link bertahan login, file sementara dibersihkan setelah dialog/startup/logout.

## Bukti dan reproduksi

Baca AGENTS.md kedua repo, PRD-V4.md Fase 5/keputusan tetap, dokumen audit/risiko/pemulihan Fase 4 dan seluruh docs/phase-v4-5. `status-penerimaan.md` memetakan tiap kriteria; `hasil-pengujian.md` membedakan web/emulator/bundel/mock/fisik. `perintah-uji.md`, kedua skrip regresi dan tests/v4_phase5_* memberi perintah serta guard DB. Gunakan DB/storage salinan sendiri; jangan menimpa temp/storage sesi implementator atau menghapus residu historis agar verifier hijau.

Hasil: V1–V3 14 integrasi +15 statis lulus; V4 Fase 1–4 dan 83 probe audit lulus ulang; 37 pemeriksaan Expo web lulus; 21 unit mobile, tsc, lint, ekspor iOS/Android lulus. Android emulator smoke guru/wali dan PDF/cache lulus. Backup–restore bisnis/PDF di salinan, rollback berisi ditolak, empty down/up/up dan Apache FilesMatch lokal lulus. Log/payload/kanal OFF lulus lokal/mock.

## Fokus audit

- Pastikan capability hanya petunjuk menu, seluruh request tetap scope server. Admin tidak memperoleh jalur wali/guru diam-diam; alasan/capacity tetap wajib.
- Periksa proyeksi wali tidak membocorkan ID petugas/aset/gambar/sumber, tidak mengubah snapshot/PDF historis, dan mempertahankan fallback rata-rata lama.
- Ulang nilai/sikap/web parity, pengganti/koreksi/serahkan/verifikasi, scope/revocation, 409 termasuk retry setelah jawaban hilang, generation/background/logout, ketukan ganda dan gate sebelum penerbitan.
- Pointer baseline **tipe=rapor, rapor_id**; jangan memakai v4_rapor. Uji pending Push/URI saat belum login/401/wrong account, dan pemuatan ulang relasi saat detail/PDF/fokus.
- PDF harus byte asli, bearer header, no-store server, tidak diregenerasi; uji cleanup native pada sukses/batal/gagal/crash. Riwayat/input hanya memori, tanpa analytics/clipboard/log.
- Uji `phase5_static` terhadap src/app/reports.tsx; validator V2/V3 kini helper bersama plus unit regresi. Pertahankan asal kegagalan awal dan exit77, jangan mengklaim skip sebagai lulus.
- Verifikasi manifest, backup/hash/restore point, aturan FilesMatch pada Apache sebenarnya, guard rollback, serta status checklist produksi. Dokumentasi bukan bukti deployment.

## Masih memerlukan Human Developer/lingkungan lain

iOS runtime, iOS/Android fisik, semua peran native lengkap, jaringan/timeout/keyboard/fontScale besar native, Push nyata dan receipt/cold-start, cPanel/FastCGI/DB hosting/cron/logWAF, performa/UAT dan restore produksi belum selesai. Putus jaringan nyata COMMIT/power loss dan penarikan semester nonaktif tetap risiko Fase 4. Human Developer harus menentukan pelaksanaan fisik/staging/cPanel dan keputusan historis, kemudian keputusan merge/rilis sesudah audit. Jangan menyatakan siap produksi berdasarkan bukti lokal ini.

Lakukan koreksi terarah dan regresinya bila diperlukan, commit/push hasil audit, kemudian berhenti. Jangan merge/deploy/migrasi produksi, mengirim rilis aplikasi atau melanjutkan PRD berikutnya tanpa instruksi terpisah.
