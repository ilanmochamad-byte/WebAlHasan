<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;
use App\MasterData\MasterDataService;

/**
 * Impor santri dari Excel (redesain website Tahap 6, D-4, keputusan AC).
 *
 *   GET  ?templat=1          unduh templat Excel dengan urutan kolom yang benar
 *   POST mode=periksa        periksa baris di server TANPA menyimpan apa pun
 *   POST mode=impor          periksa ulang setiap baris, simpan yang siap
 *   POST (tanpa mode)        kompatibilitas pemanggil lama: sama dengan impor,
 *                            jawaban teks polos seperti sebelumnya
 *
 * Berkas dibaca di peramban (SheetJS) dan dikirim sebagai `payload` JSON
 * daftar baris. Seluruh validasi berada di `MasterDataService::periksaImpor()`
 * — aturan yang sama dengan formulir santri, ditambah NIS yang sudah ada dan
 * NIS ganda di dalam berkas. Guard admin + CSRF di `_guard.php`.
 */

require_once __DIR__ . '/_guard.php';

$service = master_data_service();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isset($_GET['templat'])) {
        ah_xlsx_download('templat-impor-santri.xlsx', [
            MasterDataService::JUDUL_IMPOR,
            ['2410', 'Contoh Nama Santri', 'L', 'Ciamis', '2014-05-04', 'Jl. Contoh No. 1', 'Desa', 'Kecamatan', 'Ciamis', 'Jawa Barat', 'Nama Ayah', '081200000000', 'Nama Ibu', '081300000000', 'SD Contoh', 'SMP Terpadu'],
        ]);
    }
    admin_require_post();
}

$mode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : '';
$payload = json_decode((string) ($_POST['payload'] ?? ''), true);

if (in_array($mode, ['periksa', 'impor'], true)) {
    if (!is_array($payload)) {
        ah_json(['ok' => false, 'pesan' => 'Isi berkas bukan daftar baris yang valid.'], 422);
    }
    try {
        $hasil = $mode === 'periksa' ? $service->periksaImpor($payload) : $service->imporSantri($payload);
    } catch (MasterDataException $exception) {
        ah_json(['ok' => false, 'pesan' => $exception->getMessage()], 422);
    }
    // `input` hanya dipakai di server; jawaban berisi nomor baris, NIS, nama, dan masalah.
    $hasil['hasil'] = array_map(static function (array $baris): array {
        unset($baris['input']);
        return $baris;
    }, $hasil['hasil']);
    ah_json(['ok' => true, 'mode' => $mode] + $hasil);
}

header('Content-Type: text/plain; charset=utf-8');
if (!is_array($payload)) {
    http_response_code(422);
    exit("IMPORT DITOLAK\nPayload bukan daftar baris yang valid.");
}
try {
    $hasil = $service->imporSantri($payload);
} catch (MasterDataException $exception) {
    http_response_code(422);
    exit("IMPORT DITOLAK\n" . $exception->getMessage());
}
$gagal = array_values(array_filter($hasil['hasil'], static fn (array $b): bool => !$b['siap']));
echo "IMPORT SELESAI\n- Berhasil: {$hasil['disimpan']} santri\n- Gagal: " . count($gagal) . " baris\n";
if ($gagal !== []) {
    echo "\nRINCIAN BARIS GAGAL\n- " . implode("\n- ", array_map(
        static fn (array $b): string => 'Baris ' . $b['baris'] . ' (' . ($b['nis'] !== '' ? $b['nis'] : 'tanpa NIS') . '): ' . implode(' ', $b['masalah']),
        $gagal
    ));
}
