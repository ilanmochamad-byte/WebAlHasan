<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;
use Throwable;

/**
 * Masuk dan sesi Portal Pendaftar PSB (workstream keamanan PSB, keputusan AP).
 *
 * Login tetap NISN + No. HP wali. Yang ditambahkan dibanding `auth_pendaftar.php`
 * lama: query terikat, No. HP kosong tidak pernah cocok, pembatasan percobaan
 * gagal (dihitung dari `audit_logs`, tanpa tabel baru), regenerasi ID sesi,
 * dan pemeriksaan ulang setiap permintaan. Sesi menyimpan sidik
 * (id + NISN + No. HP) saat masuk; bila pendaftar dihapus atau NISN/No. HP
 * berubah dari sesi lain, sesi lama berakhir pada permintaan berikutnya.
 *
 * Kunci sesi lama (`status_pendaftar`, `id_pendaftar`, `nama_pendaftar`)
 * dipertahankan agar halaman yang membacanya tetap kompatibel; hak akses
 * hanya diberikan lewat `aktif()`.
 */
final class PendaftarSesi
{
    public const JENDELA_MENIT = 15;
    public const BATAS_NISN = 8;
    public const BATAS_IP = 20;
    /** Umur maksimum sesi pendaftar sejak masuk. */
    public const UMUR_DETIK = 12 * 3600;
    /** Hak cetak bukti bagi peramban yang baru saja mendaftar. */
    public const UMUR_BUKTI_DETIK = 6 * 3600;

    private const KUNCI = ['status_pendaftar', 'id_pendaftar', 'nama_pendaftar', '_psb_sidik', '_psb_masuk'];

    public function __construct(private mysqli $db)
    {
    }

    /**
     * @return 'masuk'|'gagal'|'terkunci'
     */
    public function masuk(string $nisn, string $hp, ?string $ip): string
    {
        $nisn = trim($nisn);
        $hp = trim($hp);
        $kunci = self::kunciNisn($nisn);
        if ($this->terkunci($kunci, $ip)) {
            $this->audit('psb.login_throttled', null, ['kunci' => $kunci]);
            return 'terkunci';
        }

        $row = null;
        if ($nisn !== '' && mb_strlen($nisn) <= 20 && $hp !== '' && mb_strlen($hp) <= 15) {
            $row = $this->cari('SELECT id, nisn, no_hp_wali, nama_lengkap FROM psb_pendaftar WHERE nisn = ? LIMIT 1', 's', $nisn);
        }
        // No. HP kosong di basis data (tambah manual/impor) tidak pernah cocok.
        if ($row === null || trim((string) $row['no_hp_wali']) === '' || !hash_equals(trim((string) $row['no_hp_wali']), $hp)) {
            $this->audit('psb.login_failed', null, ['kunci' => $kunci]);
            return 'gagal';
        }

        session_regenerate_id(true);
        $this->bersihkan();
        $_SESSION['status_pendaftar'] = 'login';
        $_SESSION['id_pendaftar'] = (int) $row['id'];
        $_SESSION['nama_pendaftar'] = (string) $row['nama_lengkap'];
        $_SESSION['_psb_sidik'] = self::sidik($row);
        $_SESSION['_psb_masuk'] = time();
        $this->audit('psb.login_succeeded', (int) $row['id'], ['kunci' => $kunci]);

        return 'masuk';
    }

    /**
     * Pendaftar yang sah untuk permintaan ini, atau null. Sesi yang tidak lagi
     * cocok dengan basis data dibersihkan (hanya kunci pendaftar; sesi staf
     * pada peramban yang sama tidak disentuh).
     *
     * @return array<string, mixed>|null baris lengkap `psb_pendaftar`
     */
    public function aktif(): ?array
    {
        if (($_SESSION['status_pendaftar'] ?? null) !== 'login') {
            return null;
        }
        $id = (int) ($_SESSION['id_pendaftar'] ?? 0);
        $masuk = (int) ($_SESSION['_psb_masuk'] ?? 0);
        $row = $id > 0 ? $this->cari('SELECT * FROM psb_pendaftar WHERE id = ? LIMIT 1', 'i', $id) : null;
        if ($row === null
            || $masuk <= 0 || time() - $masuk > self::UMUR_DETIK
            || !is_string($_SESSION['_psb_sidik'] ?? null)
            || !hash_equals(self::sidik($row), (string) $_SESSION['_psb_sidik'])
            || trim((string) $row['no_hp_wali']) === '') {
            $this->bersihkan();
            $_SESSION['_psb_sesi_berakhir'] = true;
            return null;
        }

        return $row;
    }

    /**
     * Halaman portal: tanpa sesi sah diarahkan ke halaman masuk.
     *
     * @return array<string, mixed>
     */
    public function wajib(string $loginUrl): array
    {
        $row = $this->aktif();
        if ($row === null) {
            $berakhir = !empty($_SESSION['_psb_sesi_berakhir']);
            unset($_SESSION['_psb_sesi_berakhir']);
            header('Location: ' . $loginUrl . ($berakhir ? '?pesan=sesi' : ''));
            exit;
        }

        return $row;
    }

    /** Pendaftar mengganti No. HP-nya sendiri: sesi ini tetap, sesi lain berakhir. */
    public function perbarui(array $row): void
    {
        $_SESSION['nama_pendaftar'] = (string) $row['nama_lengkap'];
        $_SESSION['_psb_sidik'] = self::sidik($row);
    }

    public function keluar(): void
    {
        $id = (int) ($_SESSION['id_pendaftar'] ?? 0);
        $this->bersihkan();
        unset($_SESSION['_psb_bukti']);
        if ($id > 0) {
            $this->audit('psb.logout', $id, null);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** Peramban yang baru mendaftar boleh mencetak bukti pendaftaran itu saja. */
    public function izinkanBukti(int $id, string $noPendaftaran): void
    {
        $_SESSION['_psb_bukti'] = ['id' => $id, 'no' => $noPendaftaran, 'waktu' => time()];
    }

    public function bolehBukti(array $row): bool
    {
        $bukti = $_SESSION['_psb_bukti'] ?? null;

        return is_array($bukti)
            && (int) ($bukti['id'] ?? 0) === (int) $row['id']
            && hash_equals((string) $row['no_pendaftaran'], (string) ($bukti['no'] ?? ''))
            && time() - (int) ($bukti['waktu'] ?? 0) <= self::UMUR_BUKTI_DETIK;
    }

    public static function sidik(array $row): string
    {
        return hash('sha256', 'psb-sesi|' . (int) $row['id'] . '|' . trim((string) $row['nisn']) . '|' . trim((string) $row['no_hp_wali']));
    }

    /** NISN tidak disimpan mentah di audit; cukup kunci hitungnya. */
    public static function kunciNisn(string $nisn): string
    {
        return hash('sha256', 'psb-login|' . strtolower(trim($nisn)));
    }

    public function pesanTerkunci(): string
    {
        return 'Terlalu banyak percobaan masuk yang gagal. Tunggu sekitar ' . self::JENDELA_MENIT
            . ' menit lalu coba lagi, atau hubungi panitia PSB.';
    }

    private function terkunci(string $kunci, ?string $ip): bool
    {
        try {
            if ($this->hitung('after_json LIKE ?', '%"kunci":"' . $kunci . '"%') >= self::BATAS_NISN) {
                return true;
            }
            if ($ip !== null && $ip !== '' && $this->hitung('ip_address = ?', $ip) >= self::BATAS_IP) {
                return true;
            }
        } catch (Throwable $exception) {
            // Seperti LoginThrottle staf: kegagalan hitung tidak mengunci semua orang.
            error_log('Pembatasan masuk pendaftar dilewati: ' . $exception->getMessage());
        }

        return false;
    }

    private function hitung(string $syarat, string $nilai): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) AS jumlah FROM audit_logs
            WHERE action = 'psb.login_failed' AND $syarat
              AND created_at >= (NOW() - INTERVAL " . self::JENDELA_MENIT . ' MINUTE)');
        if ($stmt === false) {
            return 0;
        }
        $stmt->bind_param('s', $nilai);
        $result = $stmt->execute() ? $stmt->get_result() : false;
        $stmt->close();

        return $result === false ? 0 : (int) ($result->fetch_assoc()['jumlah'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function cari(string $sql, string $type, int|string $value): ?array
    {
        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new PsbException('Data pendaftar tidak dapat dibaca.', [], 500);
        }
        $stmt->bind_param($type, $value);
        $result = $stmt->execute() ? $stmt->get_result() : false;
        $stmt->close();
        if ($result === false) {
            throw new PsbException('Data pendaftar tidak dapat dibaca.', [], 500);
        }

        return $result->fetch_assoc() ?: null;
    }

    private function bersihkan(): void
    {
        foreach (self::KUNCI as $kunci) {
            unset($_SESSION[$kunci]);
        }
    }

    private function audit(string $action, ?int $id, ?array $after): void
    {
        // Pendaftar bukan akun `users`: aktor kosong (kecuali staf sedang masuk
        // di peramban yang sama; AuditLogger lalu mencatat akun staf itu).
        audit_logger()->log($action, 'psb_pendaftar', $id, null, $after);
    }
}
