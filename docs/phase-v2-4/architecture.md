# V2 Fase 4 — Arsitektur Notifikasi

Dokumen ini menjelaskan bagaimana notifikasi perizinan dibangun: dari peristiwa
bisnis, penentuan penerima, penulisan outbox, sampai pengiriman oleh worker.

## 1. Prinsip yang menentukan seluruh desain

1. **Notifikasi in-app adalah sumber status utama.** Ia tidak memanggil penyedia
   eksternal, tidak dapat dimatikan admin (ditegakkan `CHECK (inapp_enabled = 1)`
   pada migrasi 008), dan tetap tersedia walaupun push serta WhatsApp gagal
   total.
2. **Transaksi bisnis tidak pernah menunggu penyedia eksternal.** Yang ditulis
   di dalam transaksi pengajuan/keputusan hanyalah baris outbox lokal. Panggilan
   jaringan terjadi kemudian, di worker.
3. **Kegagalan notifikasi tidak pernah membatalkan perizinan.**
   `NotificationService::emit()` menangkap seluruh galatnya sendiri dan hanya
   mengembalikan ringkasan; pemanggil di `IzinWorkflowService` mengabaikan
   nilainya.
4. **Tidak ada pesan ganda.** Kunci peristiwa deterministik + kunci unik
   `(event_key, kanal, penerima_user_id)` + klaim baris atomik + sewa proses.
5. **Isi notifikasi aman.** Alasan izin, catatan pengurus, alasan keputusan,
   alasan penggantian, alasan koreksi, nomor telepon, token, dan credential
   tidak pernah masuk ke kanal mana pun.

## 2. Alur satu peristiwa

```
Pengurus menekan "Kirim pengajuan"
        │
        ▼
IzinWorkflowService::create()            ← satu transaksi basis data
   ├─ validasi cakupan, tanggal, tumpang tindih
   ├─ INSERT izin_pengajuan
   ├─ routing murobi
   ├─ INSERT izin_riwayat_status
   ├─ INSERT audit_logs
   └─ NotificationService::emit()        ← masih di dalam transaksi
         ├─ RecipientResolver  → daftar user_id yang berhak
         ├─ NotificationEvent  → judul/isi aman per kanal
         └─ INSERT notifikasi_outbox (InApp = Sent, Push/WA = Queued)
        │
        ▼  COMMIT — pengguna menerima respons di sini
        ┆
        ┆  (kemudian, terpisah)
        ▼
cron cPanel → bin/notifikasi_worker.php
   ├─ WorkerLock::acquire()              ← lapisan 1: satu proses per kanal
   ├─ OutboxRepository::claim()          ← lapisan 2: klaim baris atomik
   ├─ PushClient / WhatsAppProvider      ← satu-satunya titik panggilan keluar
   └─ markSent() / markFailed() + notifikasi_percobaan
```

Enqueue berada **di dalam** transaksi dengan sengaja (pola outbox): tidak ada
peristiwa yang commit tanpa notifikasi, dan tidak ada notifikasi tanpa
peristiwa. Karena yang ditulis hanya baris lokal, tidak ada penyedia eksternal
yang ikut menahan transaksi.

## 3. Matriks peristiwa dan penerima

Penerima ditentukan dari **kemampuan dan relasi nyata**, bukan nama role
(`app/Notification/RecipientResolver.php`). Pelaku peristiwa tidak pernah
diberi tahu tentang tindakannya sendiri.

| Peristiwa | Kunci peristiwa | Penerima |
| --- | --- | --- |
| `izin.pengajuan_dibuat` | `izin:{id}:pengajuan_dibuat` | Murobi tujuan (guru dengan `murobi_assignments` aktif) |
| `izin.routing_perlu_admin` | `izin:{id}:routing_perlu_admin` | Seluruh admin aktif |
| `izin.murobi_ditetapkan` | `izin:{id}:murobi_ditetapkan:v{versi}` | Murobi yang ditetapkan + pengurus pengaju |
| `izin.murobi_ditetapkan_ulang` | `izin:{id}:murobi_ditetapkan_ulang:v{versi}` | Murobi baru + **murobi lama** + pengurus pengaju |
| `izin.keputusan_disetujui` | `izin:{id}:keputusan_disetujui:v{versi}` | Pengurus pengaju + orang tua dengan relasi wali aktif |
| `izin.keputusan_ditolak` | `izin:{id}:keputusan_ditolak:v{versi}` | Pengurus pengaju + orang tua dengan relasi wali aktif |
| `izin.keputusan_admin_pengganti` | `izin:{id}:keputusan_admin_pengganti:v{versi}` | Pengurus pengaju + orang tua + murobi yang digantikan |
| `izin.pembatalan` | `izin:{id}:pembatalan:v{versi}` | Murobi tujuan (bila ada) + pengurus pemilik; admin bila belum ada murobi |
| `izin.koreksi` | `izin:{id}:koreksi:v{versi}` | Pengurus pengaju + orang tua + murobi tujuan |
| `sistem.pesan_uji` | `sistem:pesan_uji:{kanal}:{nonce}` | Admin yang menekan tombol uji |

Aturan penentuan penerima:

- **Murobi**: akun `guru` yang terhubung ke `guru.id` tujuan **dan** memiliki
  `murobi_assignments` aktif pada tahun ajaran aktif. Target kamar berlaku
  langsung; target kelas hanya berlaku bila kelas masih aktif dan belum
  diarsipkan. Syaratnya sengaja identik dengan `Capabilities::MUROBI`, sehingga
  penerima tidak pernah lebih luas daripada pemegang hak keputusan.
- **Pengurus**: akun ber-role `pengurus` yang terhubung ke baris `pengurus`
  aktif milik pengajuan.
- **Admin**: akun aktif ber-role `admin`.
- **Orang tua**: akun ber-role `orang_tua` yang terhubung ke `wali` aktif dengan
  relasi `santri_wali` **belum diarsipkan**. Wali yang relasinya sudah dicabut
  tidak pernah menerima kabar izin anak.

Mengapa pengaju tidak diberi notifikasi atas pengajuannya sendiri: statusnya
sudah tampak pada daftar miliknya, dan ia tetap menerima notifikasi begitu ada
penetapan murobi, keputusan, atau koreksi.

## 4. Isi notifikasi (payload aman)

`NotificationEvent::render($event, $kanal, $context)` menghasilkan dua varian.

| | In-app | Push & WhatsApp |
| --- | --- | --- |
| Nomor pengajuan | ya | ya |
| Nama santri | ya (di balik autentikasi) | **tidak** |
| Rentang tanggal | ya | tidak |
| Status akhir keputusan | ya | tidak (hanya "sudah diputus") |
| Alasan izin | **tidak pernah** | **tidak pernah** |
| Catatan pengurus | **tidak pernah** | **tidak pernah** |
| Alasan keputusan/penggantian/koreksi | **tidak pernah** | **tidak pernah** |
| Nomor telepon, token, credential | **tidak pernah** | **tidak pernah** |

Contoh isi push: *"Ada pengajuan izin #128 menunggu keputusan Anda. Buka
aplikasi untuk melihat detail."*

`NotificationService::context()` sengaja hanya membaca nama santri dan rentang
tanggal dari basis data. Kolom `alasan` dan `catatan_pengurus` tidak pernah
diambil, sehingga tidak mungkin bocor karena kesalahan penulisan template.

## 5. Deep link

Payload `data` sebuah notifikasi hanya memuat penunjuk sumber daya:

```json
{ "tipe": "izin", "event": "izin.keputusan_disetujui", "pengajuan_id": 128, "url": "/izin/128" }
```

Worker push membatasi payload ke daftar kunci itu saja
(`array_intersect_key`), sehingga kunci tambahan apa pun tidak akan terkirim.

Alur pembukaan di aplikasi:

1. Pengguna mengetuk notifikasi → `addNotificationResponseReceivedListener`
   (atau `useLastNotificationResponse` untuk cold start).
2. Bila belum masuk, tujuan **ditunda** dan dibuka setelah autentikasi berhasil.
3. Aplikasi memanggil `GET /api/v1/izin/pengajuan/{id}`.
4. **Server memverifikasi cakupan** dan menjawab `403` bila pengguna tidak
   berhak. ID pada payload tidak pernah dipercaya sebagai bukti otorisasi.

Uji KA-5 pada `tests/v2_phase4_api_contract.php` membuktikan langkah 4: orang
tua yang tidak terhubung dan murobi lain menerima `403` walaupun mengetahui ID.

## 6. Perangkat push

```
Aplikasi (development build, perangkat nyata)
   ├─ setNotificationChannelAsync('perizinan')      ← Android, sebelum minta izin
   ├─ getPermissionsAsync / requestPermissionsAsync ← tidak diulang tanpa perlu
   ├─ getExpoPushTokenAsync({ projectId })
   └─ POST /api/v1/notifikasi/perangkat  { token, platform, device_id, ... }
             │
             ▼
       DeviceService::register()
          ├─ validasi bentuk ExponentPushToken[...]
          ├─ token_hash        = HMAC-SHA256 (subkunci HKDF dari PUSH_TOKEN_KEY)
          └─ token_terlindungi = AES-256-GCM, disimpan base64
```

`device_id` adalah identitas instalasi acak yang disimpan stabil di
`expo-secure-store`; ia bukan `Constants.sessionId` yang berubah setiap sesi.
Registrasi otomatis hanya memakai izin yang sudah diberikan dan tidak pernah
memunculkan dialog tanpa tindakan pengguna.

Pencabutan tersedia untuk empat keadaan yang diwajibkan PRD:

| Keadaan | Jalur | `alasan_pencabutan` |
| --- | --- | --- |
| Logout | `POST /auth/logout` dengan `push_token` | `logout` |
| Akun dinonaktifkan admin | `AccountService::setActive(false)` | `akun_dinonaktifkan` |
| Pengguna mematikan push | `POST /notifikasi/perangkat/pencabutan` | `dinonaktifkan_pengguna` |
| Token ditolak penyedia | worker, tiket `DeviceNotRegistered` | `token_invalid` |
| Perangkat dihapus | `POST /notifikasi/perangkat/pencabutan` | `perangkat_dihapus` |

Selain pencabutan, tersedia sakelar per perangkat
(`POST /notifikasi/perangkat/{id}/push`) yang menghentikan push **tanpa**
mencabut registrasi dan **tanpa** mempengaruhi notifikasi in-app.

Token mentah tidak pernah: dikembalikan API, ditulis ke audit (audit hanya
menyimpan 12 karakter pertama HMAC sebagai sidik), atau dicetak worker.
Query pengiriman juga selalu bergabung ke akun `users.is_active = 1`, sehingga
antrean lama tidak dapat mengirim push setelah akun dinonaktifkan.

## 7. Pengaturan kanal

Baris tunggal `pengaturan_notifikasi`.

| Kanal | Default | Syarat menyala |
| --- | --- | --- |
| In-app | **selalu aktif** | — (tidak dapat dimatikan) |
| Push | mati | `PUSH_TOKEN_KEY` terisi dan ekstensi `openssl` aktif |
| WhatsApp | **mati** | pemeriksaan konfigurasi terakhir berstatus `Lulus` untuk penyedia aktif dan konfigurasi saat ini tetap lengkap |

Syarat WhatsApp ditegakkan tiga lapis:

1. `NotificationAdminService::ubahSakelar()` menolak dengan `409`;
2. `SettingsRepository::setWhatsappEnabled()` menambahkan
   `AND whatsapp_check_status = 'Lulus' AND whatsapp_provider = ?` pada klausa
   WHERE;
3. `CHECK (whatsapp_enabled = 0 OR whatsapp_check_status = 'Lulus')` dari
   migrasi 006.

Pemeriksaan yang **gagal** otomatis mematikan sakelar: sistem tidak pernah
meninggalkan WhatsApp menyala dengan konfigurasi yang sudah rusak.

Mematikan sebuah kanal juga menandai baris antrean yang belum terkirim sebagai
gagal permanen dengan kode `KANAL_NONAKTIF`, sehingga worker tidak
mengirimkannya belakangan dan admin tetap dapat melihat apa yang batal dikirim.
Notifikasi in-app tidak tersentuh.

## 8. Adapter WhatsApp (netral vendor)

```
app/Notification/WhatsApp/
├── WhatsAppProvider.php    antarmuka: name, mengirimNyata, readiness, verify, send
├── NullProvider.php        DEFAULT — tanpa vendor, tanpa koneksi jaringan
├── FakeProvider.php        adapter uji — memverifikasi kontrak, tidak mengirim nyata
├── HttpProvider.php        adapter HTTP generik, seluruhnya dari environment
└── ProviderFactory.php     memilih adapter dari WHATSAPP_PROVIDER
```

- Sistem **tidak pernah** memilih vendor berbayar, membuat akun, atau mengirim
  pesan tanpa admin menyalakannya lebih dahulu.
- `NullProvider` dan `FakeProvider` tidak memiliki satu baris pun kode jaringan
  (diperiksa `tests/v2_phase4_static.php`).
- `FakeProvider::mengirimNyata()` mengembalikan `false`; panel admin memakai
  nilai itu untuk menuliskan "adapter uji — BUKAN bukti pengiriman nyata" pada
  status dan hasil pemeriksaan. Adapter uji juga menolak berjalan ketika
  `APP_ENV=production`.
- Menambah vendor baru = menulis satu kelas dan mendaftarkannya pada factory.
  Outbox, worker, API, dan UI tidak berubah.

## 9. Outbox, retry, dan worker

Status baris: `Queued` → `Sent` atau `Failed`.

Untuk kanal Push, status `Sent` pada implementasi saat ini berarti tiket awal
diterima Expo Push Service. Server belum mengambil push receipt akhir dari
FCM/APNs, sehingga `Sent` **bukan** jaminan delivery end-to-end ke perangkat.
Keterbatasan ini dicatat sebagai temuan terbuka 26 Agustus 2026 dan harus
ditangani sebelum statistik delivery diklaim sepenuhnya akurat.

| Kolom | Arti |
| --- | --- |
| `percobaan` | jumlah percobaan yang sudah dilakukan |
| `percobaan_terakhir_pada` | waktu percobaan terakhir |
| `dikirim_pada` | waktu berhasil dikirim |
| `error_kode` / `error_terakhir` | galat AMAN (sudah melewati `SafeError`) |
| `tersedia_pada` | waktu paling awal percobaan berikutnya (backoff) |
| `gagal_permanen` | 1 = retry dihentikan; tidak diambil worker lagi |
| `locked_by` / `locked_until` | sewa klaim per baris |

Backoff: `60 × 2^(percobaan-1)` detik, dibatasi 3600 detik, berhenti pada
percobaan ke-5 (`OutboxRepository::MAX_PERCOBAAN`).

Riwayat setiap percobaan disimpan terpisah pada `notifikasi_percobaan`
(unik per `(outbox_id, percobaan_ke)`), sehingga admin melihat pola kegagalan
tanpa menimpa galat terakhir.

**Dua lapis pengaman concurrency**

1. `WorkerLock` — sewa berbasis baris per kanal, satu UPDATE bersyarat.
   Proses kedua berhenti dengan tenang (bukan error) dan cron tetap exit 0.
   Sewa punya kedaluwarsa sehingga proses yang mati tidak mengunci antrean.
2. `OutboxRepository::claim()` — satu `UPDATE ... ORDER BY id LIMIT n` yang
   menandai `locked_by`/`locked_until`. Penyelesaian baris memakai
   `WHERE id = ? AND locked_by = ?`, sehingga worker lain tidak dapat
   menyelesaikan baris yang bukan klaimnya.

Sebelum setiap panggilan penyedia, dispatcher memperpanjang kedua sewa melalui
`WorkerLock::renew()` dan `OutboxRepository::renewClaims()`. Batch yang lebih
lama dari masa sewa awal tidak dapat direbut worker kedua di tengah putaran.

`tests/v2_phase4_concurrency.php` membuktikan keduanya dengan dua proses PHP
nyata: 12 baris antrean menghasilkan tepat 12 pesan pada jurnal adapter uji.

## 10. Berkas utama

| Berkas | Peran |
| --- | --- |
| `app/Notification/NotificationEvent.php` | katalog peristiwa, kunci dedup, isi aman per kanal |
| `app/Notification/RecipientResolver.php` | penerima berbasis capability dan relasi |
| `app/Notification/NotificationService.php` | produsen outbox (dipanggil dalam transaksi bisnis) |
| `app/Notification/NotificationRepository.php` | enqueue berdedup, pusat notifikasi pengguna, pemantauan admin |
| `app/Notification/OutboxRepository.php` | klaim, penyelesaian, backoff, riwayat percobaan |
| `app/Notification/WorkerLock.php` | sewa proses per kanal |
| `app/Notification/NotificationDispatcher.php` | worker: outbox → penyedia |
| `app/Notification/DeviceRepository.php` / `DeviceService.php` | perangkat push |
| `app/Notification/PushTokenProtector.php` | hash + sandi token perangkat |
| `app/Notification/SettingsRepository.php` | sakelar kanal, hasil pemeriksaan, audit kanal |
| `app/Notification/NotificationAdminService.php` | panel kanal admin |
| `app/Notification/SafeError.php` | pembersih galat penyedia |
| `app/Notification/Push/PushClient.php` | kontrak klien push (dapat ditiru saat uji) |
| `app/Notification/Push/ExpoPushClient.php` | implementasi Expo Push Service |
| `app/Api/NotificationApiService.php` | penerjemah REST |
| `bin/notifikasi_worker.php` | worker CLI untuk cron cPanel |
| `portal/notifikasi.php` | pusat notifikasi web semua peran |
| `admin/admin_notifikasi.php` | panel kanal admin |
