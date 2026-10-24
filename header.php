<?php
/*
 * Kerangka situs publik — redesain website Tahap 8 (U-1).
 *
 * Halaman lama tetap memanggil `include 'header.php'` sesudah koneksi.php.
 * Pengaturan halaman dibaca dari variabel sebelum include:
 *   $situs      = ['judul' => …, 'aktif' => …, 'deskripsi' => …, …] (lihat App\Situs\Kerangka::buka)
 *   $page_title = judul lama (tetap didukung)
 * `style.css` yang dulu dirujuk tetapi tidak ada di repositori diganti
 * token desain bersama (`assets/ui/alhasan.css` + `assets/ui/situs.css`).
 */
require_once __DIR__ . '/app/bootstrap.php';

App\Situs\Kerangka::buka(($situs ?? []) + ['judul' => $page_title ?? null]);
