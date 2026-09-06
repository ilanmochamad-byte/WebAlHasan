<?php

declare(strict_types=1);

namespace App\Ui;

use App\Account\CredentialMessage;

/**
 * Panel sukses "Pesan Kredensial Akun Siap Salin".
 *
 * Ditampilkan SATU KALI setelah akun guru, pengurus, atau orang tua berhasil
 * dibuat. Panel memuat ringkasan akun, teks pesan baku siap salin, tombol
 * salin, dan peringatan keamanan. Sistem tidak mengirim email: admin menyalin
 * pesan lalu menempelkannya sendiri.
 *
 * Aturan yang dijaga berkas ini:
 *
 *   - SELURUH nilai dari data pengguna di-escape. Tidak ada HTML mentah yang
 *     dibangun dari nama, username, atau email;
 *   - password sementara hanya muncul sebagai TEKS di dalam elemen, tidak
 *     pernah sebagai atribut HTML, nilai data-*, atau parameter skrip;
 *   - teks yang disalin diambil dari `textContent` elemen yang terlihat,
 *     sehingga isi salinan pasti sama dengan isi di layar dan pasti teks biasa;
 *   - jika Clipboard API tidak ada atau ditolak, teks disorot agar dapat
 *     disalin manual; kegagalan menyalin tidak menyentuh akun atau password;
 *   - tidak ada tombol kirim email, `mailto:`, SMTP, atau permintaan keluar.
 */
final class CredentialPanel
{
    /**
     * Melarang penyimpanan respons yang memuat password sementara, termasuk
     * pada riwayat tombol kembali peramban.
     */
    public static function noStore(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    /**
     * @param array<string, mixed> $payload Muatan dari CredentialMessage::forSavedAccount().
     */
    public static function render(array $payload): string
    {
        $e = static fn (mixed $value): string => Layout::escape($value);
        $teks = CredentialMessage::text($payload);
        $email = trim((string) ($payload['email'] ?? ''));
        $jenis = CredentialMessage::kindLabel((string) ($payload['kind'] ?? ''));

        $html = '<section class="ah-card ah-kredensial ah-no-print" aria-labelledby="ah-kredensial-judul">'
            . '<div class="ah-card__head"><span id="ah-kredensial-judul">Pesan informasi login akun '
            . $e($jenis) . '</span><span class="ah-badge ah-badge--warn">Tampil satu kali</span></div>'
            . '<div class="ah-card__body">'
            . '<p>Akun berhasil dibuat. Salin pesan di bawah ini, lalu tempelkan sendiri ke email pengguna. '
            . 'Sistem tidak mengirim email otomatis. Setelah halaman ini ditutup, dimuat ulang, atau '
            . 'ditinggalkan, pesan dan password sementara di bawah tidak dapat ditampilkan kembali.</p>';

        $html .= '<dl class="ah-kredensial__data">'
            . '<div><dt>Nama pengguna</dt><dd>' . $e($payload['name'] ?? '') . '</dd></div>'
            . '<div><dt>Alamat email tujuan</dt><dd>'
            . ($email === ''
                ? '<span class="text-muted">Akun ini tidak memiliki email. Sampaikan pesan melalui saluran lain yang aman.</span>'
                : $e($email))
            . '</dd></div>'
            . '<div><dt>Username</dt><dd><code class="user-select-all">' . $e($payload['username'] ?? '') . '</code></dd></div>'
            . '<div><dt>Password sementara</dt><dd><code class="user-select-all ah-kredensial__sandi">'
            . $e($payload['password'] ?? '') . '</code></dd></div>'
            . '<div><dt>Alamat masuk</dt><dd><code class="user-select-all">'
            . $e($payload['portal_url'] ?? CredentialMessage::PORTAL_URL) . '</code></dd></div>'
            . '</dl>';

        $html .= '<div class="ah-note ah-note--warn" role="note">'
            . '<i class="fas fa-triangle-exclamation mt-1" aria-hidden="true"></i><div>'
            . '<strong class="ah-note__title">Perhatian</strong>'
            . '<span>Pengguna wajib mengganti password pada login pertama; setelah itu password sementara '
            . 'di atas tidak berlaku lagi. Username dan password ini bersifat rahasia dan tidak boleh '
            . 'dibagikan kepada pihak lain.</span>'
            . '<p class="small mb-0 mt-2">Menekan tombol salin menaruh informasi rahasia pada papan klip '
            . 'perangkat. Bersihkan papan klip setelah email terkirim, misalnya dengan menyalin teks lain.</p>'
            . '</div></div>';

        $html .= '<p class="ah-kredensial__label" id="ah-kredensial-label">Pesan siap salin</p>'
            . '<pre class="ah-kredensial__teks user-select-all" id="ah-kredensial-teks" tabindex="0"'
            . ' role="region" aria-labelledby="ah-kredensial-label">' . $e($teks) . '</pre>';

        $html .= '<div class="ah-actions">'
            . '<button type="button" class="btn btn-primary" id="ah-kredensial-salin" hidden>'
            . '<i class="fas fa-copy" aria-hidden="true"></i> Salin pesan</button>'
            . '</div>'
            . '<p class="ah-kredensial__status" id="ah-kredensial-status" role="status" aria-live="polite"></p>'
            . '<p class="small text-muted mb-0">Tanpa tombol salin, pesan tetap dapat disalin manual: '
            . 'pilih seluruh teks di dalam kotak di atas, lalu tekan Ctrl+C (Cmd+C pada Mac).</p>'
            . '</div></section>';

        return $html . self::script();
    }

    private static function script(): string
    {
        return <<<'HTML'
<script>
(function () {
    var teks = document.getElementById('ah-kredensial-teks');
    var tombol = document.getElementById('ah-kredensial-salin');
    var status = document.getElementById('ah-kredensial-status');
    if (!teks || !tombol || !status) { return; }

    // Tombol baru muncul bila skrip benar-benar berjalan. Tanpa skrip, petunjuk
    // penyalinan manual di bawah kotak pesan tetap berlaku.
    tombol.hidden = false;

    // Pengosongan sesaat memastikan pembaca layar mengumumkan ulang pesan yang
    // sama bila admin menekan tombol dua kali.
    function umumkan(pesan) {
        status.textContent = '';
        window.setTimeout(function () { status.textContent = pesan; }, 30);
    }

    function sorotManual() {
        try {
            var pilihan = window.getSelection();
            var jangkauan = document.createRange();
            jangkauan.selectNodeContents(teks);
            pilihan.removeAllRanges();
            pilihan.addRange(jangkauan);
            teks.focus();
            return true;
        } catch (galat) {
            return false;
        }
    }

    function pesanManual(tersorot) {
        return tersorot
            ? 'Penyalinan otomatis tidak tersedia. Teks pesan sudah disorot — tekan Ctrl+C, atau Cmd+C pada Mac, untuk menyalin.'
            : 'Penyalinan otomatis tidak tersedia. Pilih seluruh teks pesan di atas, lalu tekan Ctrl+C, atau Cmd+C pada Mac.';
    }

    tombol.addEventListener('click', function () {
        // Isi salinan diambil dari elemen yang terlihat: teks biasa, tanpa
        // markup, dan persis sama dengan yang dibaca admin di layar.
        var isi = teks.textContent;
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(isi).then(function () {
                umumkan('Pesan berhasil disalin');
            }, function () {
                // Kegagalan menyalin TIDAK menyentuh akun maupun password.
                umumkan(pesanManual(sorotManual()));
            });
            return;
        }
        umumkan(pesanManual(sorotManual()));
    });
})();
</script>
HTML;
    }
}
