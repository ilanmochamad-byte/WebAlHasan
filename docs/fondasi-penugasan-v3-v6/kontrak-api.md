# Kontrak kompatibilitas API dan aplikasi perangkat

Keputusan pengguna 7 September 2026. Repositori `alhasanApps` **tidak diubah**
oleh paket ini, dan aplikasi yang sudah terpasang **tidak perlu diperbarui**.

## 1. Yang tidak berubah

| Hal | Status |
| --- | --- |
| Envelope JSON `success/data/error`, status HTTP, bearer token, TTL | tidak berubah |
| `POST /auth/login` → `token`, `token_type`, `expires_at`, `profile` | tidak berubah |
| `profile.id/name/username/guru/roles` | tidak berubah, urutan sama |
| `profile.capabilities` = `{list, default_mode, konteks, menus, aksi}` | **identik** bentuk dan maknanya |
| `capabilities.list` hanya berisi `admin`, `pengurus`, `murobi`, `orang_tua` | tidak berubah |
| `default_mode` dipilih dari empat mode lama; pengguna lama tetap sama | tidak berubah |
| `menus` (`jadwal`, `laporan`, `izin_admin`, `izin_pengurus`, `izin_murobi`, `izin_orang_tua`) | tidak berubah; **tidak ada** menu nilai/rapor/tagihan/PSB |
| `GET /me/capabilities` = `{list, default_mode, konteks, label}` | tidak berubah |
| Endpoint jadwal, pertemuan, absensi, laporan V1 dan perizinan/notifikasi V2 | tidak berubah |
| Kelayakan login API (admin; guru/pengurus/orang tua dengan master aktif) | tidak berubah |

Tidak ada mode utama `pendidikan`, `bendahara`, `panitia_psb`, atau
`bendahara_psb`. Kemampuan tersebut berada di bawah mode dasar `pengurus`;
guru pengampu dan murobi tetap di bawah identitas/mode guru.

## 2. Yang ditambahkan (aditif)

`GET /profile` dan `profile` pada respons login memuat **satu field baru**:

```json
"feature_capabilities": {
  "list": ["pembimbing.binaan", "rapor.verifikasi", "rapor.finalisasi", "rapor.buka_koreksi", "rapor.cetak"],
  "sumber": {"pembimbing.binaan": "penugasan", "rapor.verifikasi": "penugasan", "...": "..."},
  "cakupan": {
    "rapor.finalisasi": [
      {"jenis": "pendidikan", "id": 3, "tahun_ajaran_id": 4, "kelas_id": null, "kamar_id": null,
       "mata_pelajaran_id": null, "jenjang": "Tsanawi", "gelombang": null,
       "tanggal_mulai": "2026-09-01", "tanggal_selesai": null}
    ]
  }
}
```

| Kunci | Arti |
| --- | --- |
| `list` | capability fondasi yang efektif saat ini (urutan katalog) |
| `sumber` | per capability: `penugasan` (operasional), `admin` (pengawasan, cakupan kosong), `keduanya` |
| `cakupan` | per capability: daftar cakupan penugasan aktif yang mendasarinya |

Aturan:

- field ini **selalu dihitung ulang di server** dari basis data pada setiap
  permintaan; klien tidak dapat mengirimkannya untuk memperoleh hak;
- klien yang tidak mengenal field ini **mengabaikannya** tanpa efek — dibuktikan
  `tests/penugasan_web_smoke.php` FW-10 (profil guru dan pengurus identik pada
  seluruh field lama sebelum dan sesudah penugasan);
- field ini **bukan** menu dan **bukan** mode; aplikasi tidak boleh membangun
  menu dari `list` sampai modul V3–V6 benar-benar tersedia;
- bila skema 012 belum terpasang pada server, field tetap ada dengan
  `list = []` dan profil lama terlayani normal.

## 3. Yang harus dilakukan modul V3–V6 kelak (bukan sekarang)

- Endpoint baru berada di bawah `/api/v1` secara aditif dan memeriksa
  `Capabilities::hasFeature()` + `featureAppliesTo*()` di server.
- Tindakan admin sebagai pengganti membaca `featureSource()`; bila `admin`,
  tindakan dicatat sebagai pengawasan/pengganti pada audit — bukan sebagai
  pelaku operasional.
- Menu aplikasi untuk modul itu ditambahkan pada versi aplikasi yang mendukung
  fiturnya, dengan pengecekan `feature_capabilities.list` **dan** respons server.

## 4. Pembuktian

| Pemeriksaan | Bukti |
| --- | --- |
| struktur `capabilities` lama utuh (kunci, urutan, nilai) | `tests/penugasan_integration.php` FI-19/FI-20; `tests/penugasan_web_smoke.php` FW-10 |
| `default_mode` dan `menus` guru/pengurus tidak berubah setelah penugasan | FI-20, FW-10g/10i/10j |
| `/me/capabilities` tidak berubah bentuk | FW-10k |
| endpoint jadwal V1 tetap 200 untuk guru, 403 untuk pengurus | FW-10l/10m |
| respons login memuat field lama + aditif | FW-10d |
| kontrak laporan V1 tidak berubah (snapshot) | rangkaian regresi `tests/perapihan_audit_api_compat.php` |
