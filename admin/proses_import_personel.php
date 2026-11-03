<?php

declare(strict_types=1);

use App\Profil\ImporPersonelService;
use App\Profil\ProfilException;

/**
 * Impor identitas Guru/Pengurus dari Excel (PRD-PROFIL-IMPORT Fase 1).
 *
 *   GET  ?jenis=guru|pengurus&templat=1   unduh templat (sel teks, satu contoh fiktif)
 *   POST mode=periksa                     pratinjau per baris TANPA menyimpan
 *   POST mode=simpan                      analisis ulang di bawah kunci + simpan baris siap
 *
 * Berkas dibaca di peramban (SheetJS) dan dikirim sebagai `payload` JSON
 * `{rows: [...], pilihan: {baris: {...}}, berkas: {ukuran, sha256}}`. Isi
 * berkas mentah tidak pernah disimpan atau dicatat. Guard admin + CSRF di
 * `_guard.php`. Tugas/Kamar/Tugas Tambahan tidak pernah mengubah penugasan.
 */

require_once __DIR__ . '/_guard.php';

$jenis = (string) ($_GET['jenis'] ?? $_POST['jenis'] ?? '');
if (!in_array($jenis, ['guru', 'pengurus'], true)) {
    http_response_code(404);
    exit('Jenis impor tidak dikenal.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['templat'])) {
        ah_xlsx_download('templat-impor-' . $jenis . '.xlsx', ImporPersonelService::templat($jenis));
    }
    admin_require_post();
}

header('Cache-Control: private, no-store');
$mode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : '';
$payload = json_decode((string) ($_POST['payload'] ?? ''), true);
if (!in_array($mode, ['periksa', 'simpan'], true) || !is_array($payload) || !is_array($payload['rows'] ?? null)) {
    ah_json(['ok' => false, 'pesan' => 'Isi berkas bukan daftar baris yang valid.'], 422);
}
$pilihan = is_array($payload['pilihan'] ?? null) ? $payload['pilihan'] : [];
$berkas = is_array($payload['berkas'] ?? null) ? $payload['berkas'] : [];

try {
    $hasil = $mode === 'periksa'
        ? impor_personel_service()->periksa($jenis, $payload['rows'], $pilihan)
        : impor_personel_service()->simpan($jenis, $payload['rows'], $pilihan, (int) $currentUser['id'], $berkas);
} catch (ProfilException $exception) {
    ah_json(['ok' => false, 'pesan' => $exception->getMessage()], $exception->status() === 503 ? 503 : ($exception->status() === 409 ? 409 : 422));
} catch (Throwable $exception) {
    error_log('Impor personel gagal: ' . $exception->getMessage());
    ah_json(['ok' => false, 'pesan' => 'Impor gagal karena kesalahan server. Tidak ada data yang disimpan; silakan coba lagi.'], 500);
}
ah_json(['ok' => true, 'mode' => $mode, 'jenis' => $jenis] + $hasil);
