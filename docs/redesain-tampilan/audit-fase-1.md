# Audit Codex — Fase 1 redesain tampilan

Tanggal: 15 Oktober 2026 (Asia/Jakarta). Status: **BELUM LULUS; bukan persetujuan merge atau mulai Fase 2**.

## Cakupan dan sumber

Kedua repositori berada pada branch `redesain-tampilan` dan bersih saat audit dimulai. Implementasi aplikasi yang diperiksa: `8313a41..0ba6e9e`, seluruh 14 berkas perubahan. Dokumen website: `0dd931e`, `0b185e5`, `e6eb6e6`. Acuan penerimaan: `rencana.md` (khusus Fase 1), mockup yang dirujuk rencana, dan AGENTS.md kedua repositori. Tidak ada perubahan API/server atau implementasi Fase 2–3.

## Temuan

1. **P2 — Tujuan ubin Absensi salah ketika semua sesi selesai (dikoreksi).** Beranda menggunakan `sorot`, yang jatuh ke sesi pertama hari ini walaupun berstatus Selesai. Akibatnya ubin membuka sesi selesai, bukan tab Jadwal sebagaimana rencana. Koreksi memisahkan pemilihan tujuan Absensi dari kartu sorot melalui `attendanceShortcut`; kartu sorot tidak diubah. Dua uji regresi mencakup hari kosong, semua selesai, sesi berlangsung, dan sesi belum dibuka.
2. **P2 — Fondasi komponen Fase 1 belum lengkap (terbuka).** Tidak ditemukan komponen deretan tanggal pekan. `ListRow` lama hanya memiliki judul dan `value` di kanan, belum mendukung keterangan di bawah judul sebagaimana baris menu berketerangan pada rencana. `Chip` yang sudah ada dapat menjadi pil filter; tidak perlu menduplikasi. Claude perlu melengkapi fondasi ini tanpa mengerjakan layar Jadwal/Profil fase berikutnya, atau memperoleh keputusan perubahan cakupan dari Human Developer dan mencatatnya.
3. **P2 — Label ubin belum memenuhi pengaturan ukuran teks sistem (terbuka).** `src/components/ui/menu-tile.tsx` memakai `numberOfLines={1}`, `maxFontSizeMultiplier={1.4}`, dan `minimumFontScale={0.7}`. Ini menghindari pemotongan dengan membatasi dan mengecilkan teks; ukuran dasar 14 dapat menyusut hingga 9,8 pt. Rencana meminta teks 13–28 pt mengikuti ukuran sistem. Sapaan juga dibatasi 1,4×. Catatan implementator bukan keputusan Human Developer yang mengubah persyaratan. Perlu tata letak yang mengakomodasi pembesaran sambil mempertahankan tiga kolom, lalu uji visual ukuran aksesibilitas besar.
4. **P2 — Pesan akun tanpa menu belum sesuai (terbuka).** Ketika tidak ada kemampuan jadwal, izin, maupun pembinaan, Beranda hanya menjelaskan tidak memiliki jadwal mengajar. Rencana meminta keterangan bahwa akun tidak memiliki menu sama sekali. Bedakan keadaan kemampuan masih dimuat/gagal dari hasil kosong sebelum menampilkan pesan tersebut.

## Verifikasi independen

- `npx tsc --noEmit`: lulus sebelum dan sesudah koreksi.
- `npm run lint`: lulus sebelum dan sesudah koreksi.
- `node --experimental-strip-types --test tests/*.test.ts`: awal 8/8; setelah koreksi 10/10 lulus. Peringatan Node tentang tipe modul package.json tidak menggagalkan pengujian.
- `git diff --check`: lulus pada koreksi aplikasi.
- Pemeriksaan kode: urutan enam ubin dan syarat peran/kemampuan sesuai tabel rencana; Laporan dipindah ke stack terlindungi autentikasi dengan rute `/reports`, tombol kembali dan fallback `/`; tab native/web menghapus Laporan dan mempertahankan syarat tampil Jadwal/Izin. Ini pemeriksaan statis, bukan bukti interaksi perangkat.
- Dokumen Expo SDK 57 dibaca sebelum koreksi sesuai AGENTS.md aplikasi.

## Batas bukti dan handoff

Uji interaktif akun guru/pengurus/orang tua, Laporan buka/kembali, mode gelap, ukuran teks besar, serta regresi formulir pembinaan **tidak dijalankan ulang oleh auditor pada sesi ini**. Klaim pengujian iOS/Android/web pada bagian implementasi `rencana.md` tetap merupakan bukti Claude, bukan hasil independen Codex. Pemeriksaan perangkat menemukan simulator iPhone 17 Pro iOS 26.5 aktif; `adb devices` tidak menemukan perangkat Android aktif. Tidak ada kegagalan uji aplikasi yang disimpulkan dari keadaan perangkat ini.

Catatan lama tentang bilah web menutupi sapaan dan kartu sorot sempit pada ukuran aksesibilitas terbesar tetap terbuka; keduanya belum diverifikasi visual dalam audit ini. Tidak ada koneksi/login pengujian ke API produksi.

Serahkan kembali kepada Claude untuk menutup temuan 2–4 dalam Fase 1 dan melampirkan bukti visual yang dapat diperiksa. Setelah itu audit ulang kriteria penerimaan yang belum diverifikasi. Jangan melanjutkan Fase 2 atau merge ke main.
