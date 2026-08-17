<?php

declare(strict_types=1);

require_once __DIR__ . '/_guard.php';

$entity = (string) ($_GET['entity'] ?? '');
$filters = ['q' => $_GET['q'] ?? '', 'state' => $_GET['state'] ?? 'active', 'gender' => $_GET['gender'] ?? '', 'kelas_id' => $_GET['kelas_id'] ?? ''];
$service = master_data_service();

if ($entity === 'guru') {
    $rows = $service->exportGuru($filters);
    $headers = ['ID', 'NIP', 'Nama Guru', 'Nomor HP', 'Jenis Tugas', 'Aktif', 'Diarsipkan Pada'];
    $keys = ['id', 'nip', 'nama_guru', 'no_hp', 'status', 'is_active', 'archived_at'];
} elseif ($entity === 'santri') {
    $rows = $service->exportSantri($filters);
    $headers = ['ID', 'NIS', 'Nama Santri', 'Jenis Kelamin', 'Tempat Lahir', 'Tanggal Lahir', 'Alamat', 'Desa', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi', 'Sekolah Asal', 'Sekolah Saat Ini', 'Aktif', 'Diarsipkan Pada', 'Kelas Aktif'];
    $keys = ['id', 'nis', 'nama_santri', 'jenis_kelamin', 'tempat_lahir', 'tgl_lahir', 'alamat', 'desa', 'kecamatan', 'kab_kota', 'provinsi', 'asal_sekolah', 'sekolah_saat_ini', 'is_active', 'archived_at', 'nama_kelas'];
} else {
    http_response_code(404);
    exit('Jenis ekspor tidak ditemukan.');
}

$safe = static function (mixed $value): string {
    $value = (string) $value;
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="master-' . $entity . '-' . date('Ymd-His') . '.csv"');
$output = fopen('php://output', 'wb');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, $headers);
foreach ($rows as $row) {
    fputcsv($output, array_map(static fn (string $key): string => $safe($row[$key] ?? ''), $keys));
}
fclose($output);
exit;

