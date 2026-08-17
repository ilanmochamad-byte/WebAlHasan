<?php

declare(strict_types=1);

use App\MasterData\MasterDataException;

require_once __DIR__ . '/_guard.php';

header('Content-Type: text/plain; charset=utf-8');
$payload = json_decode((string) ($_POST['payload'] ?? ''), true);
if (!is_array($payload)) {
    http_response_code(422);
    exit("IMPORT DITOLAK\nPayload bukan daftar baris yang valid.");
}

$success = 0;
$failures = [];
$service = master_data_service();
foreach ($payload as $index => $row) {
    $rowNumber = $index + 1;
    if (!is_array($row)) {
        $failures[] = "Baris {$rowNumber}: format baris tidak valid.";
        continue;
    }
    if ($index === 0 && strtoupper(trim((string) ($row[0] ?? ''))) === 'NIS') {
        continue;
    }
    $input = [
        'nis' => $row[0] ?? '',
        'nama_santri' => $row[1] ?? '',
        'jenis_kelamin' => $row[2] ?? '',
        'tempat_lahir' => $row[3] ?? '',
        'tgl_lahir' => $row[4] ?? '',
        'alamat' => $row[5] ?? '',
        'desa' => $row[6] ?? '',
        'kecamatan' => $row[7] ?? '',
        'kab_kota' => $row[8] ?? '',
        'provinsi' => $row[9] ?? '',
        'nama_ayah' => $row[10] ?? '',
        'no_hp_ayah' => $row[11] ?? '',
        'nama_ibu' => $row[12] ?? '',
        'no_hp_ibu' => $row[13] ?? '',
        'asal_sekolah' => $row[14] ?? '',
        'sekolah_saat_ini' => $row[15] ?? '',
        'foto' => 'default.jpg',
    ];
    try {
        $service->saveSantri($input);
        $success++;
    } catch (MasterDataException $exception) {
        $nis = trim((string) ($row[0] ?? 'tanpa NIS'));
        $failures[] = "Baris {$rowNumber} ({$nis}): " . $exception->getMessage();
    } catch (Throwable) {
        $failures[] = "Baris {$rowNumber}: gagal disimpan karena kesalahan basis data.";
    }
}

echo "IMPORT SELESAI\n- Berhasil: {$success} santri\n- Gagal: " . count($failures) . " baris\n";
if ($failures !== []) {
    echo "\nRINCIAN BARIS GAGAL\n- " . implode("\n- ", $failures);
}
