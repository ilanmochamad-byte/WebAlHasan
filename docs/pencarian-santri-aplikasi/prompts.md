# Prompt satu fase — pencarian santri, IBTIDA PA/PI, dan versi aplikasi

Jalankan prompt implementasi terlebih dahulu. Setelah Claude Code berhenti dan menyerahkan commit, jalankan prompt audit Codex. Keduanya membaca `AGENTS.md` dan `docs/pencarian-santri-aplikasi/rencana.md`; satu fase ini tidak dipecah lagi.

## Claude Code — implementator

```text
Implementasikan satu fase penambahan pencarian santri, pemisahan IBTIDA PA/PI, dan aturan versi aplikasi. Anda adalah implementator; Codex auditor berikutnya.

1. Baca AGENTS.md di kedua repositori, docs/pencarian-santri-aplikasi/rencana.md, dokumen/audit jadwal kelas gabungan Fase 2 dan profil-impor Fase 2, lalu git status/log dan commit terbaru kedua repositori. Bila dokumen rencana belum ada di main, ambil dari branch web codex/rencana-pencarian-santri-aplikasi (commit 1fb9ea5) dan sertakan commit dokumentasi itu dalam branch implementasi web. Baca dokumentasi Expo SDK yang sesuai sebelum mengubah kode. Verifikasi versi app.json pada GitHub main terbaru (tercatat 1.2.0 pada 5 November 2026), lalu buat branch fitur claude/pencarian-santri-absensi-ibtida dari baseline yang tepat. Jangan bekerja pada main atau membuat worktree. Pertahankan perubahan package.json/package-lock.json lokal dan berkas lain milik pekerjaan lain.
2. Reproduksi perbedaan tampilan PA/PI Android dan iOS pada akun, pertemuan, respons API, serta versi/build yang sama. Catat apa yang benar-benar teruji; jangan menyebut penyebab platform sebelum bukti cocok. Periksa task.classes dan students[].class dari server serta snapshot pertemuan.
3. Tambahkan pencarian nama/NIS di absensi dan pilihan Semua/PA/PI yang jelas untuk pertemuan gabungan IBTIDA. Pastikan pencarian/tab hanya menyaring tampilan; draf status/catatan, ringkasan, dan payload simpan tetap mencakup seluruh peserta unik. Jelaskan jangkauan aksi Semua hadir. Pertahankan perilaku satu kelas, gabungan non-IBTIDA, serta kompatibilitas server lama.
4. Inventaris layar lain yang memilih atau menampilkan banyak santri. Gunakan pencarian yang sudah ada dan tambahkan pada celah yang relevan, terutama rincian kehadiran Keamanan dan daftar panjang pembinaan/perizinan. Untuk daftar berhalaman, gunakan filter server dalam cakupan hak; jika perlu perubahan API, buat branch web bernama sama dan uji kontraknya dalam fase tunggal ini. Jangan memperlebar hak akses atau mencatat data pribadi nyata dalam bukti.
5. Terapkan aturan major/minor/patch dengan app.json sebagai sumber versi pengguna. Tambahkan perintah bump dan pemeriksaan yang mendeteksi pekerjaan aplikasi tanpa kenaikan versi. Untuk paket fitur ini, naikkan minor dari versi main terbaru; bila tetap 1.2.0 maka target 1.3.0. Jangan menurunkan versi atau menganggap EAS autoIncrement menaikkan expo.version.
6. Uji TypeScript/lint, fungsi pencarian/tab/draf/payload, kontrak API jika berubah, regresi absensi, serta Android dan iOS dengan bukti jenis/hash build, versi, akun dan fixture yang sama. Catat batas bila fisik/Release tidak tersedia. Simpan laporan dan bukti di docs/pencarian-santri-aplikasi/implementasi/ pada repo yang berubah. Commit dan push branch fitur, lalu berhenti untuk audit Codex. Jangan merge, deploy, migrasi produksi, atau rilis aplikasi.
```

## Codex — auditor

```text
Audit hanya satu fase pencarian santri, pemisahan IBTIDA PA/PI, dan aturan versi aplikasi buatan Claude Code pada branch claude/pencarian-santri-absensi-ibtida. Anda adalah auditor.

1. Baca AGENTS.md kedua repo, docs/pencarian-santri-aplikasi/rencana.md (branch web codex/rencana-pencarian-santri-aplikasi, commit 1fb9ea5, bila belum ada pada branch implementasi), handoff implementator, baseline, git status/log, dan seluruh commit fase pada mobile serta web bila API berubah. Bandingkan dengan kriteria penerimaan, bukan hanya dengan tes implementator.
2. Uji independen pencarian nama/NIS, huruf besar/kecil, hapus pencarian, tab Semua/PA/PI, jumlah dan kelas asal peserta, peserta tanpa kelas, nama sama/NIS berbeda, satu kelas, gabungan non-IBTIDA, serta kontrak server lama. Isi status/catatan berbeda pada PA dan PI, pindah tab/cari, simpan, muat ulang, lalu cocokkan tiap student_id dan satu absensi guru. Pastikan Semua hadir benar-benar sesuai label dan tidak mengubah subset tersembunyi secara mengejutkan.
3. Periksa inventaris pencarian pada pemilih/daftar santri lain; untuk daftar berhalaman, buktikan pencarian menjangkau halaman lain tanpa memperlebar akses. Uji kegagalan jaringan, data kosong, dan penugasan yang dicabut bila terkait.
4. Verifikasi app.json terhadap main GitHub terbaru, aturan bump major/minor/patch, target minor paket ini, dan pemeriksaan yang gagal bila perubahan aplikasi tanpa bump/versi turun. Bedakan expo.version dari versionCode/buildNumber EAS. Pastikan perubahan package lokal milik pekerjaan lain tidak ikut commit tanpa alasan.
5. Jalankan TypeScript/lint, tes relevan, serta uji Android/iOS independen pada akun/pertemuan/API/versi build yang sama. Periksa bukti screenshot, respons dan data tersimpan. Jika perangkat fisik atau build rilis tidak diuji, tulis batasnya; jangan menyatakan paritas produksi lulus hanya dari web/emulator/simulator.
6. Koreksi temuan terarah beserta regresinya bila perlu. Catat LULUS/BELUM LULUS dan temuan terbuka dalam docs/pencarian-santri-aplikasi/AUDIT-CODEX.md pada repo yang berubah. Commit dan push hasil audit pada branch fitur yang sama, lalu berhenti. Jangan merge, deploy, migrasi produksi, atau merilis aplikasi.
```
