<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Tindakan POST atas tabungan (PRD V6 Fase 3): verifikasi atau penolakan
 * setoran transfer yang menunggu, dan koreksi pembalik beralasan atas
 * setoran/penarikan/belanja. Hanya petugas BSA berpenugasan aktif atas
 * santri dalam cakupannya; admin dan orang tua ditolak `TabunganService` di
 * server. GET tidak memutasi (405). Setiap formulir membawa kunci
 * idempotensi sehingga klik ganda tidak mengulang tindakan.
 */

require_once __DIR__ . '/_bsa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    Denial::render('Metode tidak diizinkan', 'Tindakan tabungan hanya melalui formulir.', 405, null, false);
}
bsa_wajib_fase3($tabunganBsa);
$santriId = max(0, (int) ($_POST['santri_id'] ?? 0));
$kembali = bsa_url('bsa_santri.php', ['id' => $santriId]);
$aksi = is_string($_POST['aksi'] ?? null) ? $_POST['aksi'] : '';
$kuitansi = null;
try {
    $pesan = match ($aksi) {
        'verifikasi' => (static function () use ($tabunganBsa, $currentUser, &$kuitansi): string {
            $h = $tabunganBsa->verifikasiSetoran($currentUser, max(0, (int) ($_POST['setoran_transfer_id'] ?? 0)), $_POST, BsaService::KANAL_WEB);
            if ($h['transaksi_id'] !== null) {
                $kuitansi = '<a href="' . ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $h['transaksi_id']])) . '">Buka kuitansi ' . ah_e((string) $h['nomor_kuitansi']) . '</a>';
            }

            return $h['replayed'] ? 'Setoran transfer ini sudah diputus sebelumnya.' : 'Setoran transfer terverifikasi dan masuk saldo. Saldo sekarang ' . bsa_rp($h['saldo_sesudah']) . '.';
        })(),
        'tolak' => (static function () use ($tabunganBsa, $currentUser): string {
            $h = $tabunganBsa->tolakSetoran($currentUser, max(0, (int) ($_POST['setoran_transfer_id'] ?? 0)), $_POST, BsaService::KANAL_WEB);

            return $h['replayed'] ? 'Setoran transfer ini sudah diputus sebelumnya.' : 'Setoran transfer ditolak. Saldo tidak berubah.';
        })(),
        'koreksi' => (static function () use ($tabunganBsa, $currentUser, &$kuitansi): string {
            $h = $tabunganBsa->koreksi($currentUser, max(0, (int) ($_POST['transaksi_id'] ?? 0)), $_POST, BsaService::KANAL_WEB);
            $kuitansi = '<a href="' . ah_e(bsa_url('bsa_tabungan_kuitansi.php', ['id' => $h['id']])) . '">Buka kuitansi ' . ah_e((string) $h['nomor_kuitansi']) . '</a>';

            return $h['replayed'] ? 'Koreksi ini sudah tersimpan sebelumnya.' : 'Koreksi tercatat sebagai transaksi pembalik. Saldo sekarang ' . bsa_rp($h['saldo']) . '.';
        })(),
        default => throw new BsaException('Tindakan tidak dikenal.'),
    };
    ah_flash_set('success', $pesan, $kuitansi);
} catch (BsaException $exception) {
    if (in_array($exception->status, [403, 404], true)) {
        Denial::page('Tindakan tabungan', 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
    }
    ah_flash_set('danger', 'Tidak ada yang diubah. ' . $exception->getMessage());
}
ah_redirect($kembali);
