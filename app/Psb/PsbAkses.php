<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;

/**
 * Siapa yang boleh membuka data satu pendaftar di halaman publik PSB
 * (rincian biaya, kwitansi, bukti pendaftaran) — workstream keamanan PSB.
 *
 * Sebelumnya cukup mengetahui No. Pendaftaran/NIK. Sekarang hanya:
 * admin (peran aktif, tanpa kewajiban ganti password), pendaftar pemilik yang
 * sedang masuk Portal Pendaftar, dan — khusus bukti pendaftaran — peramban
 * yang baru saja mengirim pendaftaran itu. Diperiksa setiap permintaan.
 */
final class PsbAkses
{
    private ?array $admin;
    private ?array $pendaftar;

    public function __construct(private mysqli $db, private PendaftarSesi $sesi)
    {
        $user = authorization()->currentUser();
        $this->admin = $user !== null && in_array('admin', $user['roles'], true) && empty($user['force_password_change']) ? $user : null;
        $this->pendaftar = $sesi->aktif();
    }

    public function admin(): bool
    {
        return $this->admin !== null;
    }

    /** @return array<string, mixed>|null */
    public function pendaftar(): ?array
    {
        return $this->pendaftar;
    }

    /**
     * Baris pendaftar menurut No. Pendaftaran (atau NIK bila `$nik`), tanpa
     * pemeriksaan hak. Pemanggil wajib memanggil `boleh()` sesudahnya.
     *
     * @return array<string, mixed>|null
     */
    public function cari(string $kunci, bool $nik = false): ?array
    {
        $kunci = trim($kunci);
        if ($kunci === '' || mb_strlen($kunci) > 30) {
            return null;
        }
        $stmt = $this->db->prepare($nik
            ? 'SELECT * FROM psb_pendaftar WHERE no_pendaftaran = ? OR nik = ? ORDER BY id LIMIT 1'
            : 'SELECT * FROM psb_pendaftar WHERE no_pendaftaran = ? LIMIT 1');
        if ($nik) {
            $stmt->bind_param('ss', $kunci, $kunci);
        } else {
            $stmt->bind_param('s', $kunci);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return is_array($row) ? $row : null;
    }

    public function boleh(array $row, bool $bukti = false): bool
    {
        if ($this->admin !== null) {
            return true;
        }
        if ($this->pendaftar !== null && (int) $this->pendaftar['id'] === (int) $row['id']) {
            return true;
        }

        return $bukti && $this->sesi->bolehBukti($row);
    }

    /** Halaman sederhana untuk akses yang ditolak (tanpa data pendaftar). */
    public static function tolak(int $status): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        $pesan = $status === 404
            ? 'Data tidak ditemukan.'
            : 'Halaman ini hanya dapat dibuka oleh pendaftar yang bersangkutan setelah masuk Portal Pendaftar, atau oleh panitia.';
        echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Akses ditolak</title></head><body style="font-family:sans-serif;max-width:560px;margin:48px auto;padding:0 16px">'
            . '<p>' . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="login_pendaftar.php">Masuk Portal Pendaftar</a></p></body></html>';
        exit;
    }
}
