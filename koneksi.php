<?php

// Jembatan kompatibilitas untuk modul lama. Konfigurasi sebenarnya dibaca dari
// environment oleh bootstrap; variabel $koneksi tetap tersedia seperti dahulu.
require_once __DIR__ . '/app/bootstrap.php';

$koneksi = app_db();
