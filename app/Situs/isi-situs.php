<?php

/*
 * Isi resmi situs publik Pesantren Al Hasan (redesain website Tahap 8).
 *
 * Keputusan Human Developer 24 Oktober 2026: bahan resmi (isi Profil, tahun
 * berdiri, nomor WhatsApp/telepon, logo, foto, angka statistik, keterangan
 * unit) DIKOSONGKAN dan diisi manual oleh pesantren di berkas ini.
 *
 * Aturan:
 *   - Isian kosong ('' atau []) TIDAK ditampilkan. Tidak ada teks contoh,
 *     nomor contoh, foto stok, atau tautan "#".
 *   - Semua teks ditampilkan sebagai teks biasa (di-escape). Paragraf
 *     dipisah dengan baris kosong.
 *   - Gambar berupa alamat relatif dari akar situs, mis. 'assets/situs/logo.png'.
 *     Simpan berkasnya di folder itu lebih dulu.
 *   - Isian bertanda "dibawa dari situs lama" sudah tampil di situs/kebijakan
 *     privasi sebelum Tahap 8; mohon diperiksa kembali.
 *
 * Folder `app/` ditolak web oleh `.htaccess`, sehingga berkas ini tidak dapat
 * diunduh langsung.
 */

return [
    // Identitas -------------------------------------------------------------
    'nama' => 'Pesantren Al Hasan Ciamis',                       // dibawa dari situs lama
    'nama_resmi' => 'Pondok Pesantren Al Hasan Ciamis',           // dibawa dari kebijakan privasi
    'logo' => '',                                                 // mis. 'assets/situs/logo.png'
    'tahun_berdiri' => '',                                        // mis. '1990'

    // Beranda ---------------------------------------------------------------
    'slogan' => 'Membangun generasi berakhlak dan berteknologi',   // dibawa dari situs lama
    'ringkasan' => '',                                            // satu kalimat di bawah slogan
    'semboyan_kaki' => 'Mencetak generasi yang berilmu amaliah, beramal ilmiah, dan berakhlakul karimah berlandaskan Ahlussunnah wal Jamaah.', // dibawa dari situs lama
    'tentang' => '',                                              // paragraf "Tentang pesantren"
    'foto_tentang' => '',                                         // foto di samping "Tentang pesantren"
    'pengasuh' => [
        'nama' => 'KH. Muhammad Syarif Hidayat',                  // dibawa dari situs lama
        'jabatan' => 'Pengasuh Pesantren',                        // dibawa dari situs lama
        'foto' => '',
    ],
    // Angka statistik, mis. [['label' => 'Santri aktif', 'nilai' => '…'], …]
    'statistik' => [],

    // Kontak ----------------------------------------------------------------
    'alamat' => 'Jalan Jenderal Ahmad Yani No. 120, Ciamis, Jawa Barat 46213', // dibawa dari kebijakan privasi
    'email' => 'alhasanpesantren@gmail.com',                      // dibawa dari kebijakan privasi
    'telepon' => '',                                              // mis. '(0265) …'
    'whatsapp' => '',                                             // angka saja dengan kode negara, mis. '62812…'
    // Jam layanan, mis. ['Senin–Kamis' => '08.00–16.00 WIB']
    'jam_layanan' => ['Senin–Kamis' => '08.00–16.00 WIB'],       // dibawa dari kebijakan privasi

    // Profil (6 tab) --------------------------------------------------------
    'profil' => [
        'sejarah' => '',                                          // teks; paragraf dipisah baris kosong
        'visi' => '',
        'misi' => [],                                             // daftar butir misi
        // Struktur bertingkat: [['jabatan' => '…', 'nama' => '…', 'bawahan' => [ …sama… ]], …]
        'struktur_yayasan' => [],
        'pengurus_pesantren' => [],
        'pengurus_santri' => [],
        // Dewan pengajar: [['nama' => '…', 'bidang' => '…', 'foto' => ''], …]
        'dewan_pengajar' => [],
    ],

    // Unit pendidikan ------------------------------------------------------
    // Kunci tetap; 'jenjang_psb' menghubungkan ke pilihan formulir PSB.
    'unit' => [
        'ra' => [
            'nama' => 'RA Terpadu Al Hasan',
            'jenjang_psb' => 'RA Terpadu Al Hasan',
            'keterangan' => '',                                   // usia/kelas dan keunggulan
            'situs' => '',
        ],
        'mi' => [
            'nama' => 'MI Daar el-Qolam',
            'jenjang_psb' => 'MI Daar el-Qolam',
            'keterangan' => '',
            'situs' => '',
        ],
        'smp' => [
            'nama' => 'SMP Terpadu Al Hasan',
            'jenjang_psb' => 'SMP Terpadu Al Hasan',
            'keterangan' => '',
            'situs' => 'https://smpt.alhasan.co.id',              // dibawa dari situs lama
        ],
        'smk' => [
            'nama' => 'SMK Terpadu Al Hasan',
            'jenjang_psb' => 'SMK Terpadu Al Hasan',
            'keterangan' => '',
            'situs' => 'https://smkt.alhasan.co.id',              // dibawa dari situs lama
        ],
    ],

    // PSB -------------------------------------------------------------------
    'psb' => [
        'tahun_ajaran' => '2026/2027',                            // dibawa dari situs lama
        'dibuka' => true,
    ],
];
