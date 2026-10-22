<?php

declare(strict_types=1);

namespace App\Ui;

use App\Http\Csrf;
use Throwable;

/**
 * Halaman "akses ditolak" bersama (paket perapihan V1–V2; redesain Tahap 2, A-5).
 *
 * Satu tampilan untuk seluruh penolakan otorisasi web — guard V2, V3, dan
 * halaman V4 yang dulu menulis teks polos "403 — Akses ditolak." Bila akun
 * sudah masuk, penolakan tampil di dalam kerangka biasa (menu peran aktif
 * tetap ada) dan menyebut halaman yang dibuka, siapa yang berhak, serta siapa
 * yang dapat memberi akses. Tanpa sesi (atau bila kerangka gagal dimuat)
 * tampil halaman sederhana yang sama isinya.
 *
 * Halaman ini adalah RESPONS penolakan, bukan pengganti pemeriksaan otorisasi:
 * pemanggilnya sudah memutuskan bahwa akses ditolak sebelum memanggil kelas ini.
 * Peran aktif tidak pernah memperluas hak. Bila akun berperan ganda membuka
 * halaman milik perannya yang lain, `otherRole()` menawarkan "Buka sebagai …"
 * (A-5): formulir POST ber-CSRF ke `portal/peran.php` yang memeriksa ulang
 * hak nyata akun di server.
 */
final class Denial
{
    private const PEMBERI = 'Jika Anda seharusnya punya akses, minta admin pesantren menambahkan peran atau penugasan untuk akun Anda.';

    /**
     * Penolakan untuk satu halaman bernama: "<halaman> hanya untuk <peran>".
     */
    public static function page(string $halaman, string $untuk, string $alasan = '', int $status = 403): never
    {
        $penjelasan = $halaman . ' hanya untuk ' . $untuk . '.';
        // Pesan umum "Akses … ditolak." tidak menambah keterangan; alasan lain
        // (mis. santri di luar cakupan) tetap disebut.
        if ($alasan !== '' && preg_match('/^(Hanya admin|Akses\b.*\bditolak\.?$)/u', $alasan) !== 1) {
            $penjelasan .= ' ' . $alasan;
        }
        self::render('Halaman ini bukan untuk peran Anda', $penjelasan, $status);
    }

    /**
     * Halaman milik peran lain akun ini (A-2, A-5): tawarkan perpindahan
     * eksplisit, tanpa mengganti peran sesi.
     *
     * @param array<int, string> $peranCocok peran akun yang memuat halaman ini.
     * @param string|null $kembali alamat GET yang dibuka setelah berpindah.
     * @param string|null $lanjut alamat untuk tetap memakai peran aktif.
     */
    public static function otherRole(string $aktif, array $peranCocok, ?string $kembali, ?string $lanjut): never
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $nama = array_map([Peran::class, 'label'], $peranCocok);
        $tombol = '';
        foreach ($peranCocok as $peran) {
            $tombol .= '<form method="post" action="' . $e(app_url('/portal/peran.php')) . '" class="d-inline">'
                . Csrf::input()
                . '<input type="hidden" name="peran" value="' . $e($peran) . '">'
                . ($kembali !== null ? '<input type="hidden" name="kembali" value="' . $e($kembali) . '">' : '')
                . '<button class="btn btn-primary" type="submit" data-ah-buka-sebagai="' . $e($peran) . '">Buka sebagai ' . $e(Peran::label($peran)) . '</button></form>';
        }
        if ($lanjut !== null) {
            $tombol .= '<a class="btn btn-outline-primary" href="' . $e($lanjut) . '">Lanjutkan sebagai ' . $e(Peran::label($aktif)) . '</a>';
        }
        self::render(
            'Halaman ini untuk peran ' . implode(' atau ', $nama),
            'Anda sedang memakai peran ' . Peran::label($aktif) . '. Halaman ini termasuk menu peran '
                . implode(' atau ', $nama) . ' milik akun Anda. Peran tidak berganti sebelum Anda memilihnya.',
            403,
            $tombol,
            false
        );
    }

    public static function render(string $ringkas, string $penjelasan, int $status = 403, ?string $tindakan = null, bool $pemberi = true): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
        }

        $user = null;
        try {
            $user = authorization()->currentUser();
        } catch (Throwable) {
            $user = null;
        }
        if ($user !== null && !headers_sent() && empty($user['force_password_change'])) {
            try {
                ob_start();
                ah_page_open([
                    'title' => 'Akses ditolak',
                    'user' => $user,
                    'flash' => null,
                    'show_heading' => false,
                    'breadcrumbs' => [
                        ['label' => 'Beranda', 'url' => app_url('/portal/index.php')],
                        ['label' => 'Akses ditolak'],
                    ],
                ]);
                echo self::body($ringkas, $penjelasan, $status, $tindakan, $pemberi);
                ah_page_close();
                ob_end_flush();
                exit;
            } catch (Throwable $exception) {
                ob_end_clean();
                error_log('Halaman akses ditolak gagal memakai kerangka: ' . $exception->getMessage());
            }
        }

        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>Akses ditolak — Sistem Al Hasan</title>'
            . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
            . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">'
            . '<link rel="stylesheet" href="' . $e(app_url('/assets/ui/alhasan.css')) . '">'
            . '</head><body class="ah"><main class="container py-5" style="max-width:640px">'
            . self::body($ringkas, $penjelasan, $status, $tindakan, $pemberi)
            . '</main></body></html>';
        exit;
    }

    private static function body(string $ringkas, string $penjelasan, int $status, ?string $tindakan = null, bool $pemberi = true): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $logout = '';
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id'])) {
            $logout = '<form method="post" action="' . $e(app_url('/admin/logout.php')) . '" class="d-inline">'
                . Csrf::input()
                . '<button class="btn btn-outline-secondary" type="submit">Keluar dan masuk dengan akun lain</button></form>';
        }

        return '<section class="ah-card ah-denial" aria-labelledby="ah-denial-title"><div class="ah-card__body">'
            . '<p class="mb-2"><span class="ah-badge ah-badge--danger">' . $status . ' · Akses ditolak</span></p>'
            . '<h1 class="h4" id="ah-denial-title">' . $e($ringkas) . '</h1>'
            . '<p>' . $e($penjelasan) . '</p>'
            . ($pemberi ? '<p class="text-muted small">' . $e(self::PEMBERI) . ' Menu dan Beranda yang menjadi hak Anda tetap dapat dibuka.</p>' : '')
            . '<div class="d-flex flex-wrap gap-2 mt-3">'
            . ($tindakan ?? '')
            . '<a class="btn ' . ($tindakan === null ? 'btn-primary' : 'btn-outline-secondary') . '" href="' . $e(app_url('/portal/index.php')) . '">Kembali ke Beranda</a>'
            . $logout
            . '</div></div></section>';
    }
}
