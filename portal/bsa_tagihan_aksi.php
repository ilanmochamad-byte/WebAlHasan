<?php

declare(strict_types=1);

use App\Bsa\BsaException;
use App\Bsa\BsaService;
use App\Ui\Denial;

/**
 * Tindakan POST atas tagihan dan pembayaran (PRD V6 Fase 2): revisi,
 * pembatalan tagihan, verifikasi transfer, dan pembatalan/penolakan
 * pembayaran. Hanya petugas BSA berpenugasan aktif atas santri dalam
 * cakupannya; admin dan orang tua ditolak `TagihanService` di server. GET
 * tidak memutasi (405). Setiap formulir membawa kunci idempotensi sehingga
 * klik ganda tidak mengulang tindakan.
 */

require_once __DIR__ . '/_bsa.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    Denial::render('Metode tidak diizinkan', 'Tindakan tagihan hanya melalui formulir.', 405, null, false);
}
bsa_wajib_fase2($tagihanBsa);
$tagihanId = max(0, (int) ($_POST['tagihan_id'] ?? 0));
$pembayaranId = max(0, (int) ($_POST['pembayaran_id'] ?? 0));
$kembali = bsa_url('bsa_tagihan.php', ['id' => $tagihanId]);
$aksi = is_string($_POST['aksi'] ?? null) ? $_POST['aksi'] : '';
try {
    $pesan = match ($aksi) {
        'revisi' => (static function () use ($tagihanBsa, $currentUser, $tagihanId): string {
            $h = $tagihanBsa->revisiTagihan($currentUser, $tagihanId, $_POST, BsaService::KANAL_WEB);

            return $h['replayed'] ? 'Revisi ini sudah tersimpan sebelumnya.' : 'Revisi tersimpan (versi ' . $h['versi'] . ').';
        })(),
        'batal' => (static function () use ($tagihanBsa, $currentUser, $tagihanId): string {
            $h = $tagihanBsa->batalkanTagihan($currentUser, $tagihanId, $_POST, BsaService::KANAL_WEB);

            return $h['replayed'] ? 'Tagihan ini sudah dibatalkan sebelumnya.' : 'Tagihan dibatalkan. Riwayat tetap tersimpan.';
        })(),
        'verifikasi' => (static function () use ($tagihanBsa, $currentUser, $pembayaranId): string {
            $h = $tagihanBsa->verifikasiPembayaran($currentUser, $pembayaranId, $_POST, BsaService::KANAL_WEB);

            return $h['replayed'] ? 'Transfer ini sudah diverifikasi sebelumnya.' : 'Transfer terverifikasi. Kuitansi ' . $h['nomor_kuitansi'] . ' tersedia; sisa ' . bsa_rp($h['sisa']) . '.';
        })(),
        'batal_bayar' => (static function () use ($tagihanBsa, $currentUser, $pembayaranId): string {
            $h = $tagihanBsa->batalkanPembayaran($currentUser, $pembayaranId, $_POST, BsaService::KANAL_WEB);

            return $h['replayed'] ? 'Pembayaran ini sudah dibatalkan sebelumnya.' : 'Pembayaran ' . $h['nomor_kuitansi'] . ' dibatalkan; sisa ' . bsa_rp($h['sisa']) . '.';
        })(),
        default => throw new BsaException('Tindakan tidak dikenal.'),
    };
    ah_flash_set('success', $pesan);
} catch (BsaException $exception) {
    if (in_array($exception->status, [403, 404], true)) {
        Denial::page('Tindakan tagihan', 'pengurus dengan penugasan BSA aktif untuk santri dalam cakupannya', $exception->getMessage(), $exception->status);
    }
    ah_flash_set('danger', 'Tidak ada yang diubah. ' . $exception->getMessage());
}
ah_redirect($kembali);
