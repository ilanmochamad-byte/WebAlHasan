# V2 Fase 4 — Checklist Penyedia WhatsApp

Dokumen ini adalah **prosedur yang harus dijalankan manusia** untuk membuktikan
kriteria penerimaan Fase 4 nomor 6: *"Saat WhatsApp aktif dan provider siap,
pesan uji serta satu notifikasi keputusan berhasil dikirim."*

Berdasarkan keputusan produk 26 Agustus 2026, kemampuan ini berstatus
**DITANGGUHKAN/NON-BLOCKING — TIDAK DIUJI DAN TIDAK DINYATAKAN LULUS**.
Checklist tetap dipertahankan sebagai gerbang wajib apabila WhatsApp akan
diaktifkan pada masa depan.

## 1. Apa yang TIDAK dilakukan sistem

Sesuai instruksi implementasi:

- Sistem **tidak memilih** vendor berbayar.
- Sistem **tidak membuat** akun dan **tidak membeli** layanan.
- Sistem **tidak mengirim** pesan eksternal apa pun tanpa admin menyalakan
  kanal terlebih dahulu.
- Credential **tidak pernah** diminta lewat percakapan dan **tidak pernah**
  masuk repositori.

Keadaan default sistem: `WHATSAPP_PROVIDER` kosong → `NullProvider` → WhatsApp
mati, dan tidak ada satu baris pun kode jaringan yang dapat dijalankan.

## 2. Keputusan produk — 26 Agustus 2026

WhatsApp ditunda sampai batas waktu yang tidak ditentukan karena penyedia
resmi, WhatsApp Business Account, nomor bisnis, template utility, opt-in
pengguna, dan credential produksi belum tersedia. Kanal tetap `OFF`, hasil
pemeriksaan konfigurasi belum `Lulus`, dan tidak ada request kepada penyedia.
Adapter uji bukan bukti pengiriman nyata.

Butir berikut bukan pekerjaan Fase 4 yang masih memblokir. Semuanya menjadi
prasyarat aktivasi WhatsApp pada masa depan:

| # | Keputusan | Status |
| --- | --- | --- |
| 1 | Penyedia resmi yang disetujui (Meta Cloud API, penyedia BSP resmi, atau layanan lain yang disetujui) | ☐ ditangguhkan |
| 2 | WhatsApp Business Account dan nomor pengirim resmi pesantren | ☐ ditangguhkan |
| 3 | Template utility yang disetujui penyedia | ☐ ditangguhkan |
| 4 | Pemegang credential dan penyimpanannya hanya pada environment | ☐ ditangguhkan |
| 5 | Anggaran dan batas kirim per bulan | ☐ ditangguhkan |
| 6 | Mekanisme opt-in dan opt-out pengguna/wali | ☐ ditangguhkan |

Selama seluruh prasyarat belum dipenuhi, kanal WhatsApp tetap mati dan sistem
berjalan normal: notifikasi in-app tetap menjadi sumber status utama dan push
berjalan sesuai pengaturan.

## 3. Kontrak yang harus dipenuhi vendor

Adapter HTTP generik (`app/Notification/WhatsApp/HttpProvider.php`) dapat
memakai vendor mana pun yang menyediakan:

- endpoint kirim pesan berbasis **HTTPS** yang menerima JSON;
- autentikasi lewat satu header (nama dan awalannya dapat dikonfigurasi);
- respons dengan kode status HTTP standar
  (2xx = diterima; 400/401/403/404/422 = permanen; 429/5xx = sementara).

Bila vendor tidak cocok dengan bentuk itu, tulis satu kelas baru yang
mengimplementasikan `App\Notification\WhatsApp\WhatsAppProvider` dan daftarkan
pada `ProviderFactory`. **Tidak ada** bagian lain sistem yang perlu diubah:
outbox, worker, API, panel admin, dan UI tetap sama.

Isi pesan sudah disiapkan sistem dalam bentuk aman:

> Judul: `Keputusan izin`
> Isi: `Pengajuan #128 sudah diputus. Buka aplikasi untuk melihat detail.`

Template vendor harus dapat menampung teks generik seperti itu. **Jangan**
mendaftarkan template yang meminta alasan izin, nama santri, atau catatan
pengurus sebagai variabel — data itu memang tidak pernah dikirim.

## 4. Environment yang perlu diisi

Diisi di server (cPanel → Setup PHP Environment atau `.env` di luar document
root). Nilai **tidak pernah** di-commit.

```
WHATSAPP_PROVIDER=<nama-vendor>        # nilai apa pun selain kosong/fake -> adapter HTTP
WHATSAPP_API_URL=https://…             # WAJIB HTTPS
WHATSAPP_API_TOKEN=…                   # credential
WHATSAPP_AUTH_HEADER=Authorization     # opsional, default Authorization
WHATSAPP_AUTH_PREFIX=Bearer            # opsional, default "Bearer "
WHATSAPP_SENDER_ID=…                   # opsional
WHATSAPP_TEMPLATE_NAME=…               # opsional
WHATSAPP_FIELD_TO=to                   # opsional
WHATSAPP_FIELD_TEXT=text               # opsional
WHATSAPP_VERIFY_URL=https://…          # opsional; bila kosong, verifikasi hanya memeriksa kelengkapan environment
WHATSAPP_TIMEOUT_SECONDS=10            # opsional
```

Panel admin menampilkan **nama** environment yang dibutuhkan, tidak pernah
nilainya.

## 5. Checklist aktivasi

Jalankan berurutan. Berhenti dan laporkan bila ada langkah yang gagal.

| # | Langkah | Diharapkan | Hasil |
| --- | --- | --- | --- |
| W1 | Pemilik produk menyetujui vendor, template, credential, dan anggaran | Persetujuan tertulis | ☐ |
| W2 | Isi environment §4 pada **staging** lebih dulu | PHP dimuat ulang | ☐ |
| W3 | Buka **Admin → Kanal Notifikasi** | Penyedia tampil sebagai nama vendor, bukan `belum-dipilih` | ☐ |
| W4 | Tekan **Periksa konfigurasi** pada kanal WhatsApp | Status `Lulus`; bila `Gagal`, pesan menyebut environment yang kurang | ☐ |
| W5 | Tekan **Nyalakan kanal** | Sakelar tersimpan; audit `kanal_diubah` tercatat | ☐ |
| W6 | Tekan **Kirim pesan uji** | Pesan tiba di nomor **admin sendiri** | ☐ |
| W7 | Buat satu pengajuan uji dengan santri sintetis, lalu putuskan | Wali uji menerima satu pesan keputusan | ☐ |
| W8 | **Periksa isi pesan yang diterima** | Hanya nomor pengajuan dan ajakan membuka aplikasi — **tanpa nama santri, tanpa alasan izin, tanpa alasan keputusan** | ☐ **wajib** |
| W9 | Jalankan worker dua kali berturut-turut | Tidak ada pesan kedua untuk peristiwa yang sama | ☐ **wajib** |
| W10 | Matikan kanal WhatsApp, lalu buat pengajuan baru | Tidak ada permintaan ke vendor; pengajuan tetap berhasil; in-app tetap muncul | ☐ **wajib** |
| W11 | Isi `WHATSAPP_API_TOKEN` dengan nilai salah, jalankan **Periksa konfigurasi** | Status `Gagal`; sakelar otomatis mati | ☐ |
| W12 | Periksa **Pengiriman gagal** | Pesan galat aman; **tanpa** credential dan **tanpa** nomor tujuan | ☐ **wajib** |
| W13 | Kembalikan credential yang benar, tekan **Coba ulang** pada satu baris gagal | Baris yang SAMA terkirim; tidak ada baris outbox baru | ☐ |
| W14 | Periksa `notifikasi_pengaturan_audit` dan `audit_logs` | Seluruh perubahan sakelar dan pemeriksaan tercatat; **tanpa** credential | ☐ **wajib** |
| W15 | Ulangi W3–W14 pada **produksi** setelah staging bersih | Sama | ☐ |

Query verifikasi kebocoran:

```sql
-- Tidak boleh ada nomor tujuan atau credential pada audit dan outbox.
SELECT COUNT(*) FROM notifikasi_pengaturan_audit WHERE COALESCE(pesan,'') REGEXP '[0-9]{8,}';   -- 0
SELECT COUNT(*) FROM notifikasi_outbox WHERE COALESCE(error_terakhir,'') REGEXP '[0-9]{8,}';    -- 0
SELECT COUNT(*) FROM notifikasi_outbox WHERE COALESCE(error_terakhir,'') LIKE '%Bearer %';      -- 0
```

## 6. Menguji tanpa vendor (yang sudah dilakukan)

Adapter uji sudah membuktikan seluruh kontrak tanpa mengirim pesan nyata:

```bash
WHATSAPP_PROVIDER=fake WHATSAPP_FAKE_MODE=ok             php bin/notifikasi_worker.php --kanal=whatsapp
WHATSAPP_PROVIDER=fake WHATSAPP_FAKE_MODE=gagal          php bin/notifikasi_worker.php --kanal=whatsapp
WHATSAPP_PROVIDER=fake WHATSAPP_FAKE_MODE=gagal_permanen php bin/notifikasi_worker.php --kanal=whatsapp
WHATSAPP_PROVIDER=fake WHATSAPP_FAKE_MODE=verify_gagal   php bin/notifikasi_worker.php --kanal=whatsapp
```

Hasil terverifikasi otomatis: `test-results.md` §4 poin 7 dan
`acceptance-status.md` §3.

Adapter uji **menolak berjalan** ketika `APP_ENV=production`, dan panel admin
menandai hasilnya sebagai "BUKAN bukti pengiriman WhatsApp nyata".

## 7. Format pencatatan hasil

```
### Hasil aktivasi WhatsApp — <tanggal>

Vendor        : <nama>
Nomor pengirim: <nomor resmi>
Template      : <nama template>
Lingkungan    : <staging|produksi>
Hasil W1–W15  : <lulus/gagal + catatan per langkah>
Bukti         : <tangkapan layar pesan yang diterima, disimpan DI LUAR repo>
Kesimpulan    : Kriteria penerimaan Fase 4 nomor 6 <TERPENUHI / BELUM TERPENUHI>
```

## 8. Status penangguhan saat ini

Tidak ada yang perlu dihapus. Biarkan `WHATSAPP_PROVIDER` kosong: kanal tetap
mati, tidak ada koneksi keluar, dan seluruh alur perizinan berjalan penuh
dengan notifikasi in-app dan push. Keputusan penangguhan sudah dicatat pada
`PRD-V2.md` dan tidak dihitung sebagai blocker Fase 4.

Sebelum WhatsApp dapat diaktifkan pada masa depan, wajib dipastikan kembali:

1. penyedia resmi disetujui;
2. opt-in dan opt-out pengguna tersedia;
3. template utility disetujui;
4. credential hanya berada di environment;
5. ketiga lapisan pemeriksaan konfigurasi lulus;
6. pesan uji dan satu notifikasi keputusan tiba melalui penyedia nyata; dan
7. pengujian keamanan, privasi, deduplikasi, retry, serta nol-request-saat-mati
   diulang.

Sampai seluruhnya selesai, WhatsApp tetap **DITANGGUHKAN/NON-BLOCKING** dan
**tidak boleh dinyatakan lulus**.
