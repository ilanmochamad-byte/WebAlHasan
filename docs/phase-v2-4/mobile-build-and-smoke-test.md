# V2 Fase 4 — Build Aplikasi dan Smoke Test Push (Android & iOS)

Dokumen ini adalah **prosedur yang harus dijalankan manusia** untuk membuktikan
kriteria penerimaan Fase 4 nomor 3: *"Push tiba pada perangkat uji Android dan
iOS tanpa memuat alasan izin lengkap."*

Kedatangan push telah dibuktikan pada perangkat fisik Android dan iOS pada
24 Agustus 2026. Kriteria kedatangan push berstatus **TERPENUHI BERDASARKAN
UJI PERANGKAT FISIK**. Checklist lengkap tetap dipertahankan untuk pengujian
operasional dan deep-link yang belum seluruhnya dibuktikan; status kriteria
tidak boleh dipakai untuk menyembunyikan temuan terbuka pada §8.

> Keputusan penerimaan Fase 3 menerima simulator Android sebagai bukti
> pengganti. Pengecualian itu **tidak berlaku** di sini: PRD Fase 4 secara
> khusus mensyaratkan push benar-benar tiba pada perangkat Android dan iOS.

## 1. Mengapa perangkat nyata dan development build wajib

Berdasarkan dokumentasi Expo SDK 57:

- Push jarak jauh **tidak tersedia di Expo Go pada Android sejak SDK 53**;
  diperlukan development build. Notifikasi lokal tetap bekerja di Expo Go,
  tetapi itu bukan yang diuji di sini.
- `getExpoPushTokenAsync()` mewajibkan `projectId`, yang berasal dari
  `extra.eas.projectId` setelah `eas init`.
- Kredensial push (FCM untuk Android, APNs untuk iOS) dikelola EAS dan tidak
  pernah masuk repositori.
- Aplikasi menolak mendaftar pada emulator/simulator
  (`Device.isDevice === false`) dengan pesan yang jelas, sehingga hasil "push
  tidak tiba" pada simulator bukan bug melainkan perilaku yang disengaja.

## 2. Prasyarat

- [ ] Akun Expo dan akses ke organisasi proyek.
- [ ] `eas-cli` terpasang: `npm install -g eas-cli`, lalu `eas login`.
- [ ] Satu perangkat **Android fisik** (Android 8+), USB debugging aktif.
- [ ] Satu perangkat **iOS fisik** (iOS 16+), terdaftar pada profil provisioning.
- [ ] macOS + Xcode untuk build iOS (atau EAS Build cloud).
- [ ] Backend Fase 4 sudah ter-deploy pada staging/produksi dengan
      `PUSH_TOKEN_KEY` terisi.
- [ ] `EXPO_PUBLIC_API_BASE_URL` menunjuk ke backend tersebut, memakai **HTTPS**.

## 3. Menyiapkan proyek Expo

```bash
cd /path/ke/alhasanApps
npm ci

# 1. Inisialisasi proyek EAS (menulis extra.eas.projectId ke app.json).
eas init

# 2. Verifikasi projectId terbaca aplikasi.
npx expo config --type public | grep -A3 '"eas"'

# 3. Siapkan credential push.
eas credentials        # Android: FCM V1 service account
                       # iOS: APNs key (p8)
```

`app.json` sudah memuat plugin dan kanal default:

```json
["expo-notifications", {
  "color": "#ffffff",
  "defaultChannel": "perizinan",
  "enableBackgroundRemoteNotifications": false
}]
```

Nilai `perizinan` **harus sama** dengan `ANDROID_CHANNEL_ID` di
`src/notifications/push-registration.ts` dan
`NotificationDispatcher::ANDROID_CHANNEL_ID` di backend. Jangan mengubah salah
satunya saja.

Bila tidak ingin menyunting `app.json`, `projectId` juga dapat diberikan lewat
`EXPO_PUBLIC_EAS_PROJECT_ID` (nilainya bukan secret).

## 4. Membuat development build

```bash
# Android
eas build --profile development --platform android
# lalu pasang .apk pada perangkat, atau:
npx expo run:android --device

# iOS
eas build --profile development --platform ios
# lalu pasang lewat TestFlight/ad-hoc, atau:
npx expo run:ios --device
```

Jalankan server pengembangan: `npx expo start --dev-client`.

## 5. Checklist smoke test — Android

Isi kolom hasil dan tanda tangani.

| # | Langkah | Diharapkan | Hasil | Catatan |
| --- | --- | --- | --- | --- |
| A1 | Buka aplikasi (development build) pada perangkat fisik, masuk sebagai **murobi** | Masuk berhasil, tab **Notifikasi** tampil | ☐ | |
| A2 | Buka **Notifikasi → Perangkat & push** | Status perangkat ini tampil | ☐ | |
| A3 | Tekan **Nyalakan push** | Dialog izin Android muncul **sekali** | ☐ | |
| A4 | Setujui izin | Status berubah menjadi "Push aktif" | ☐ | |
| A5 | Periksa **Admin → Kanal Notifikasi → Push** | Jumlah perangkat aktif bertambah 1 | ☐ | |
| A6 | Admin menyalakan kanal **Push** | Sakelar tersimpan, audit tercatat | ☐ | |
| A7 | Admin menekan **Kirim pesan uji** (kanal Push) | Notifikasi tiba di perangkat dalam < 60 detik | ☐ | |
| A8 | Pengurus membuat pengajuan untuk santri di bawah murobi ini | Notifikasi push tiba di perangkat murobi | ☐ | |
| A9 | **Periksa isi notifikasi di layar kunci** | Hanya "Ada pengajuan izin #N menunggu keputusan Anda…" — **tanpa nama santri dan tanpa alasan izin** | ☐ | **kriteria wajib** |
| A10 | Ketuk notifikasi saat aplikasi **tertutup** | Aplikasi terbuka langsung pada detail izin #N | ☐ | |
| A11 | Ketuk notifikasi saat aplikasi di **background** | Idem | ☐ | |
| A12 | Terima notifikasi saat aplikasi di **foreground** | Banner muncul; lencana tab bertambah | ☐ | |
| A13 | Murobi memutuskan izin; periksa perangkat **pengurus** dan **orang tua** | Keduanya menerima push keputusan tanpa alasan keputusan | ☐ | |
| A14 | Admin **mematikan** kanal Push, lalu buat pengajuan baru | Tidak ada push; notifikasi in-app tetap muncul di aplikasi | ☐ | **kriteria wajib** |
| A15 | Admin menyalakan kembali Push | Push berfungsi lagi | ☐ | |
| A16 | Pada perangkat: **Matikan push di perangkat ini** | Tidak ada push baru; daftar notifikasi in-app tetap lengkap | ☐ | |
| A17 | Logout dari aplikasi | Perangkat tercabut (`alasan_pencabutan = logout`) dan tidak menerima push lagi | ☐ | |
| A18 | Login kembali dan nyalakan push | Perangkat terdaftar ulang, **tanpa** baris perangkat baru bertumpuk | ☐ | |

Verifikasi basis data setelah A17/A18:

```sql
SELECT id, platform, device_label, push_aktif, dicabut_pada, alasan_pencabutan
  FROM perangkat_push WHERE user_id = <id murobi>;
-- Harapan: satu baris per perangkat fisik, bukan satu baris per login.
```

## 6. Checklist smoke test — iOS

| # | Langkah | Diharapkan | Hasil | Catatan |
| --- | --- | --- | --- | --- |
| I1 | Buka development build pada iPhone fisik, masuk sebagai **orang tua** | Masuk berhasil, tab **Notifikasi** tampil | ☐ | |
| I2 | **Notifikasi → Perangkat & push → Nyalakan push** | Dialog izin iOS muncul **sekali** | ☐ | |
| I3 | Setujui izin | Status "Push aktif" | ☐ | |
| I4 | Admin **Kirim pesan uji** (kanal Push) | Notifikasi tiba < 60 detik | ☐ | |
| I5 | Murobi menyetujui izin anak yang terhubung | Push keputusan tiba di iPhone | ☐ | |
| I6 | **Periksa isi di layar kunci** | Hanya "Pengajuan #N sudah diputus…" — **tanpa nama santri, tanpa alasan keputusan** | ☐ | **kriteria wajib** |
| I7 | Ketuk notifikasi saat aplikasi tertutup | Terbuka pada detail izin yang benar | ☐ | |
| I8 | Ketuk notifikasi saat aplikasi di background | Idem | ☐ | |
| I9 | Notifikasi diterima saat foreground | Banner muncul; lencana bertambah | ☐ | |
| I10 | Buka detail izin milik santri **lain** lewat URL manual | Ditolak `403` oleh server | ☐ | **kriteria wajib** |
| I11 | Tolak izin notifikasi (Pengaturan iOS), buka aplikasi | Aplikasi tidak menanyakan izin berulang; layar perangkat menjelaskan keadaan | ☐ | |
| I12 | Dengan izin ditolak, buat peristiwa baru | Notifikasi in-app tetap tersedia lengkap | ☐ | |
| I13 | Logout | Perangkat tercabut | ☐ | |

## 7. Pemeriksaan sisi server setelah smoke test

```bash
# Ringkasan antrean.
php bin/notifikasi_worker.php --status

# Kegagalan (harus kosong, atau berisi alasan yang dapat dijelaskan).
# Buka: admin/admin_notifikasi.php  → "Pengiriman gagal"
```

```sql
-- Push yang benar-benar terkirim selama smoke test.
SELECT kanal, status, COUNT(*) FROM notifikasi_outbox
 WHERE created_at >= '<mulai smoke test>' GROUP BY kanal, status;

-- Tidak boleh ada satu pun token terbaca.
SELECT COUNT(*) FROM perangkat_push WHERE token_terlindungi LIKE '%ExponentPushToken%';  -- harus 0

-- Tidak boleh ada alasan izin pada isi notifikasi.
SELECT COUNT(*) FROM notifikasi_outbox o JOIN izin_pengajuan p ON p.id = o.pengajuan_id
 WHERE o.kanal <> 'InApp' AND o.isi LIKE CONCAT('%', LEFT(p.alasan, 20), '%');           -- harus 0
```

## 8. Hasil smoke test perangkat — 24 Agustus 2026

| Aspek | Hasil yang dikonfirmasi |
| --- | --- |
| Android | Xiaomi 2409BRN2CY, akun murobi, development build EAS terpasang, Firebase berhasil diinisialisasi, perangkat terdaftar dan Push aktif. |
| Push Android | Push pengajuan `#2` tiba dengan isi “Ada pengajuan izin #2 menunggu keputusan Anda. Buka aplikasi untuk melihat detail.” |
| Privasi Android | Isi yang diamati tidak memuat nama santri, alasan izin, nomor telepon, token, atau data sensitif. |
| iOS | iPhone 17 Pro, akun orang tua, development build EAS dengan entitlement APNs terpasang, perangkat terdaftar dan Push aktif. |
| Push iOS | Push keputusan pengajuan `#2` tiba setelah worker dijalankan; kedatangan dikonfirmasi manusia. |
| Bukti iOS | Screenshot isi layar kunci belum dimasukkan ke repository. Jangan mengklaim bukti visual tersebut tersedia. |
| Panel admin | Push aktif, pemeriksaan konfigurasi `Lulus`, dua perangkat terdaftar dan aktif. |
| Kesimpulan | Kriteria penerimaan Fase 4 nomor 3 **TERPENUHI BERDASARKAN UJI PERANGKAT FISIK**. |

Temuan terbuka:

1. Cron produksi belum berjalan otomatis. Push iPhone sempat menunggu dan baru
   tiba setelah tombol **Jalankan worker sekali** ditekan. Cron cPanel setiap
   menit masih harus dipasang atau diverifikasi.
2. Backend baru memeriksa tiket awal Expo dan belum mengambil push receipt
   akhir FCM/APNs. Status pengiriman belum boleh diklaim akurat end-to-end.
3. Deep-link Android sempat gagal karena `adb reverse` ke Metro terputus.
   Aplikasi normal kembali setelah koneksi dipulihkan, tetapi A10–A12 dan
   skenario cold-start/background lengkap perlu diuji ulang dan dicatat.
4. Commit mobile `da04c3a` masih lokal dan belum didorong ke origin saat hasil
   ini dicatat.

## 8.1 Format pencatatan pengujian lanjutan

Untuk melengkapi butir checklist yang belum memiliki bukti, tambahkan blok
berikut ke dokumen ini dan perbarui `acceptance-status.md` §2:

```
### Hasil smoke test perangkat — <tanggal>

Penguji           : <nama>
Perangkat Android : <merek/model>, Android <versi>, build <hash/nomor>
Perangkat iOS     : <model>, iOS <versi>, build <hash/nomor>
Backend           : <staging|produksi>, commit <hash>
Hasil Android     : A1–A18 <lulus/gagal + catatan>
Hasil iOS         : I1–I13 <lulus/gagal + catatan>
Bukti             : <tangkapan layar layar kunci Android & iOS, dilampirkan di luar repo>
Kesimpulan        : Kriteria penerimaan Fase 4 nomor 3 <TERPENUHI / BELUM TERPENUHI>
```

Simpan tangkapan layar **di luar repositori** bila memuat nama santri nyata.

## 9. Bila push tidak tiba

Urutan pemeriksaan, dari yang paling sering:

1. Aplikasi berjalan di Expo Go, bukan development build → push jarak jauh
   tidak didukung sejak SDK 53.
2. Emulator/simulator, bukan perangkat fisik → registrasi memang ditolak.
3. `projectId` kosong → layar perangkat menampilkan
   "Project ID Expo belum tersedia".
4. `PUSH_TOKEN_KEY` belum diisi di server → registrasi dijawab `503`.
5. Kanal Push mati di panel admin → worker berhenti sebelum mengirim; halaman
   status menampilkannya.
6. Cron belum berjalan → jalankan `php bin/notifikasi_worker.php --kanal=push`
   secara manual.
7. Credential FCM/APNs salah → tiket Expo `MismatchSenderId` atau
   `InvalidCredentials` muncul pada **Pengiriman gagal** dengan pesan aman.
8. Token sudah dicabut → periksa `alasan_pencabutan` pada `perangkat_push`.
