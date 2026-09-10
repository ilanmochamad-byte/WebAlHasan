# Status penerimaan dan handoff Fase 1

Branch: `prd-v3-fase-1`. Baseline `14548541000e0df523343d82b28650061364bb65`. Pekerjaan 8–9 September 2026.

**Implementasi dan pengujian lokal selesai. Belum dinyatakan memenuhi seluruh penerimaan PRD atau lulus audit akhir.** Pemeriksaan pada salinan MySQL/MariaDB produksi representatif masih diperlukan. Claude Code adalah auditor yang ditetapkan; Codex berhenti setelah commit/push dan tidak melanjutkan Fase 2.

| Kriteria | Status dan bukti |
| --- | --- |
| Fondasi 012 tersedia, baseline terbaru dipakai | LULUS — fetch origin, ancestry dan HEAD baseline terverifikasi |
| Skema tambahan 013, runner ulang, rollback, ulang setelah rollback | LULUS pada MariaDB 12.3.2 uji — `bukti/migrasi.txt` |
| Tabel/data lama tetap tersedia | LULUS lokal — hash seluruh isi tabel non-V3 identik selama drill, termasuk fixture warisan |
| FK/index/CHECK/unique, tidak ada referensi yatim | LULUS lokal — post-check dan SQL invalid nyata |
| Admin membuat/membaca kategori, jenis, ambang via web | LULUS — integrasi dan browser |
| Duplikasi kode/overlap/tanggal/poin tidak valid ditolak | LULUS — integrasi, konkurensi, SQL constraint |
| Admin guard, CSRF, IDOR, XSS, versi, audit wajib | LULUS lokal — paket dan HTTP browser |
| Pembimbing/murobi aktif, hak hilang ketika penugasan berakhir | LULUS — integrasi; cakupan santri A/B juga diuji |
| Orang tua tanpa relasi aktif tidak mendapat capability publikasi | LULUS — integrasi |
| Role dasar, mode/default_mode/menu lama kompatibel | LULUS — regresi V1/V2 dan fondasi |
| Formulir desktop/tablet/375 px | LULUS Chromium — bukti browser dan tinjauan screenshot |
| Data warisan hanya baca | LULUS — keputusan pengguna PRD §5.2a; GET hapus/POST pencatatan 405 |
| Tidak membuka fitur Fase 2–5 atau mengubah mobile | LULUS pemeriksaan perubahan; endpoint mutasi V3 tidak ada |
| Versi database cPanel | **LULUS 10 September 2026** — migrasi 013 diterapkan pada MariaDB hosting pukul 10:20:23; `bin/v3_verify.php` 273 pemeriksaan LULUS, nol blocker, nol referensi yatim. Dijalankan langsung pada basis data produksi, bukan pada salinan uji. Lihat [uji-salinan-hosting.md](uji-salinan-hosting.md) |
| Smoke fungsional situs live | **LULUS 10 September 2026** — Human Developer: buat/baca kategori+jenis+ambang, overlap ditolak, nonaktif beralasan tercatat audit, non-admin 403, Data warisan baca-saja, 375 px, aplikasi lama normal tanpa fitur operasional V3 |
| Smoke Safari/perangkat fisik | LULUS sebagian — Safari desktop/iOS dan Android fisik diuji Human Developer. Konkurensi dua admin, CSRF, dan kanal WhatsApp OFF belum diuji di produksi (sudah diuji lokal) |
| Regresi V1/V2 **sesudah** migrasi 013 | LULUS — dibuktikan auditor; 49 suite/4.011 pemeriksaan identik sebelum dan sesudah 013 |
| Audit akhir Claude Code | **SELESAI 9 September 2026** — seluruh suite direproduksi independen; 1 koreksi terarah (T-1). Lihat [hasil-audit-claude-code.md](hasil-audit-claude-code.md) |

## Risiko dan pekerjaan terbuka

1. MariaDB lokal 12.3.2 bukan MariaDB hosting 10.6.27. Operator/auditor perlu menjalankan pre-check, migrasi, constraint/integrasi dan post-check pada salinan representatif. Tidak ada izin deploy produksi dalam tugas ini; produksi tidak disentuh.
2. Penguncian satu baris migrasi menyerialkan administrasi V3. Cocok untuk volume konfigurasi rendah; evaluasi ulang ketika alur operasional Fase 2 ditulis. Jangan menambahkan penulisan konfigurasi yang melewati service.
3. Rollback skema menghapus data V3 dan mempertahankan audit lama; backup harus mencakup keduanya. Kode lama dapat berjalan dengan tabel tambahan dibiarkan.
4. Tabel operasional baru belum boleh dipakai langsung oleh klien. Validasi lintas sumber/santri, transisi, ledger pembalik, publikasi, lampiran, dan audit/outbox operasional merupakan pekerjaan fase terkait.
5. Fixture regresi lama dapat meninggalkan audit/outbox yatim setelah cleanup. Diagnostik tidak menyembunyikannya; hasil sehat diuji pada fixture yang sudah dibersihkan secara terarah, bukan dengan mematikan pemeriksaan FK. **Koreksi audit T-1:** yatim pada tabel warisan kini dilaporkan terpisah dengan penanda `[warisan]` agar tidak disalahartikan sebagai kerusakan migrasi 013; exit code tetap nonzero (tidak dilonggarkan).

## Instruksi audit Claude Code

Audit **hanya PRD V3 Fase 1** pada branch `prd-v3-fase-1`, mulai setelah implementator berhenti. Baca AGENTS.md, seluruh PRD-V3.md, dokumen ini dan test-results, lalu periksa seluruh rentang commit sejak `14548541000e0df523343d82b28650061364bb65`. Jangan mengandalkan jumlah assertion sebagai pengganti penerimaan produk.

Verifikasi khusus:

- keamanan halaman data warisan dan keputusan §5.2a;
- peta capability kesiapan vs fitur tersedia, relasi wali, penempatan nyata dan akun aktif;
- serialisasi overlap, perubahan tahun/masa berlaku, optimistis, gagal audit, timeout/deadlock;
- serializer API baca, pagination, kompatibilitas login/profile/mode/menu lama;
- kesiapan struktur fase berikutnya tanpa endpoint operasional;
- migrasi/rollback dengan preservasi data lama dan kompatibilitas versi hosting;
- responsivitas 375 px dan pengakhiran/nonaktif katalog dengan audit.

Gunakan database uji terpisah dan fixture fiktif. Jalankan drill **sebelum** suite/browser, karena drill menghapus data V3. Jalankan seluruh regresi yang tercantum dalam laporan, lakukan koreksi terarah bila perlu, dokumentasikan bukti dan batasnya. Commit/push hasil audit, lalu berhenti. Jangan merge main, deploy, atau mengerjakan Fase 2 sebelum keputusan penerimaan yang sah.
