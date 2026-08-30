<?php

declare(strict_types=1);

/**
 * Fungsi bantu tampilan bersama Sistem Al Hasan (paket perapihan V1–V2).
 *
 * Dimuat oleh `app/bootstrap.php`. Seluruh halaman internal — admin maupun
 * portal — memakai fungsi yang sama sehingga tidak ada lagi dua gaya halaman
 * yang saling menyalin. Fungsi di sini TIDAK melakukan otorisasi apa pun;
 * halaman pemanggil sudah melewati guard-nya masing-masing.
 */

use App\Http\Csrf;
use App\Ui\Layout;

if (!function_exists('ah_e')) {
    function ah_e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ah_csrf')) {
    function ah_csrf(): string
    {
        return Csrf::input();
    }
}

if (!function_exists('ah_flash_set')) {
    /**
     * @param 'success'|'danger'|'warning'|'info' $type
     */
    function ah_flash_set(string $type, string $message, ?string $extraHtml = null): void
    {
        $_SESSION['_ah_flash'] = ['type' => $type, 'message' => $message, 'extra' => $extraHtml];
    }
}

if (!function_exists('ah_flash_take')) {
    /**
     * @return array{type:string, message:string, extra:?string}|null
     */
    function ah_flash_take(): ?array
    {
        $flash = $_SESSION['_ah_flash'] ?? null;
        unset($_SESSION['_ah_flash']);

        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('ah_redirect')) {
    function ah_redirect(string $path): never
    {
        header('Location: ' . (str_starts_with($path, 'http') || str_starts_with($path, '/') ? $path : app_url($path)));
        exit;
    }
}

if (!function_exists('ah_query')) {
    /**
     * Query string saat ini dengan sebagian nilai diganti.
     * Nilai null/'' dibuang agar filter yang dikosongkan benar-benar hilang.
     *
     * @param array<string, mixed> $replace
     */
    function ah_query(array $replace = [], ?array $base = null): string
    {
        $query = array_merge($base ?? $_GET, $replace);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return http_build_query($query);
    }
}

if (!function_exists('ah_page_open')) {
    /**
     * @param array<string, mixed> $options
     */
    function ah_page_open(array $options): void
    {
        if (!array_key_exists('flash', $options)) {
            $options['flash'] = ah_flash_take();
        }
        if (!isset($options['capabilities']) || !isset($options['unread'])) {
            $context = ui_context($options['user']);
            $options['capabilities'] ??= $context['capabilities'];
            $options['unread'] ??= $context['unread'];
        }
        Layout::open($options);
    }
}

if (!function_exists('ah_page_close')) {
    function ah_page_close(): void
    {
        Layout::close();
    }
}

if (!function_exists('ah_note')) {
    function ah_note(string $type, string $message, ?string $extraHtml = null): void
    {
        Layout::note($type, $message, $extraHtml);
    }
}

if (!function_exists('ah_empty')) {
    function ah_empty(string $title, string $message, ?string $actionHtml = null): string
    {
        return Layout::emptyState($title, $message, $actionHtml);
    }
}

if (!function_exists('ah_pagination')) {
    function ah_pagination(int $total, int $page, int $perPage): void
    {
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        if ($pages <= 1) {
            return;
        }
        $start = max(1, $page - 2);
        $end = min($pages, $page + 2);
        echo '<nav aria-label="Navigasi halaman" class="ah-no-print"><ul class="pagination justify-content-center mt-4">';
        echo '<li class="page-item ' . ($page <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?'
            . ah_e(ah_query(['page' => max(1, $page - 1)])) . '">Sebelumnya</a></li>';
        for ($i = $start; $i <= $end; $i++) {
            echo '<li class="page-item ' . ($i === $page ? 'active' : '') . '"><a class="page-link" href="?'
                . ah_e(ah_query(['page' => $i])) . '"'
                . ($i === $page ? ' aria-current="page"' : '') . '>' . $i . '</a></li>';
        }
        echo '<li class="page-item ' . ($page >= $pages ? 'disabled' : '') . '"><a class="page-link" href="?'
            . ah_e(ah_query(['page' => min($pages, $page + 1)])) . '">Berikutnya</a></li>';
        echo '</ul><p class="text-center text-muted small">Halaman ' . $page . ' dari ' . $pages
            . ' — total ' . $total . ' data.</p></nav>';
    }
}

if (!function_exists('ah_list_search')) {
    /** Form GET mereset halaman; konteks tetap hanya yang dipilih pemanggil. */
    function ah_list_search(string $q, string $label, array $context = []): void
    {
        echo '<form method="get" class="ah-card ah-card__body ah-no-print"><div class="row g-2 align-items-end">';
        foreach ($context as $name => $value) {
            echo '<input type="hidden" name="' . ah_e($name) . '" value="' . ah_e($value) . '">';
        }
        echo '<div class="col-sm-8"><label class="form-label" for="list-q">' . ah_e($label) . '</label>'
            . '<input class="form-control" type="search" id="list-q" name="q" maxlength="100" value="' . ah_e($q) . '"></div>'
            . '<div class="col-sm-4 d-flex gap-2"><button class="btn btn-primary" type="submit">Cari</button>'
            . '<a class="btn btn-outline-secondary" href="?' . ah_e(http_build_query($context)) . '">Bersihkan</a></div></div></form>';
    }
}

if (!function_exists('ah_badge')) {
    /**
     * Lencana status. Teks selalu ikut, sehingga makna tidak bergantung warna.
     */
    function ah_badge(string $text, string $tone = 'muted'): string
    {
        $tone = in_array($tone, ['ok', 'warn', 'danger', 'info', 'muted'], true) ? $tone : 'muted';

        return '<span class="ah-badge ah-badge--' . $tone . '">' . ah_e($text) . '</span>';
    }
}

if (!function_exists('ah_state_badge')) {
    /**
     * Lencana status data master (aktif / nonaktif / arsip).
     *
     * @param array<string, mixed> $row
     */
    function ah_state_badge(array $row): string
    {
        if (!empty($row['archived_at'])) {
            return ah_badge('Arsip', 'muted');
        }

        return (int) ($row['is_active'] ?? 0) === 1 ? ah_badge('Aktif', 'ok') : ah_badge('Nonaktif', 'warn');
    }
}

if (!function_exists('ah_old')) {
    /**
     * Nilai isian yang dipertahankan setelah validasi gagal.
     *
     * Nilai disimpan pada sesi oleh halaman pemanggil dan dibaca sekali.
     * Password dan token TIDAK pernah dikembalikan ke formulir.
     *
     * @param array<string, mixed>|null $record
     */
    function ah_old(string $field, ?array $record = null, string $bucket = '_ah_old'): string
    {
        $old = $_SESSION[$bucket] ?? null;
        if (is_array($old) && array_key_exists($field, $old)) {
            return (string) $old[$field];
        }

        return (string) ($record[$field] ?? '');
    }
}

if (!function_exists('ah_old_keep')) {
    /**
     * Menyimpan isian yang aman untuk ditampilkan kembali setelah validasi gagal.
     *
     * @param array<string, mixed> $input
     * @param array<int, string> $fields
     */
    function ah_old_keep(array $input, array $fields, string $bucket = '_ah_old'): void
    {
        $kept = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $input) && is_scalar($input[$field])) {
                $kept[$field] = (string) $input[$field];
            }
        }
        $_SESSION[$bucket] = $kept;
    }
}

if (!function_exists('ah_old_clear')) {
    function ah_old_clear(string $bucket = '_ah_old'): void
    {
        unset($_SESSION[$bucket], $_SESSION[$bucket . '_errors']);
    }
}

if (!function_exists('ah_form_token')) {
    /**
     * Token sekali pakai untuk melindungi formulir dari pengiriman ulang.
     *
     * Dibuat saat formulir dirender dan dikirim sebagai field tersembunyi.
     * Klik ganda, refresh POST, atau tombol "kembali lalu kirim lagi" memakai
     * token yang SAMA, dan token itu hanya berlaku sekali — sehingga tidak ada
     * santri, wali, atau relasi ganda yang tercipta.
     *
     * Ini melengkapi pola POST-redirect-GET, bukan menggantikan validasi
     * keunikan di basis data.
     */
    function ah_form_token(string $bucket): string
    {
        $token = bin2hex(random_bytes(16));
        $daftar = $_SESSION['_ah_form_tokens'][$bucket] ?? [];
        $daftar[] = $token;
        // Simpan paling banyak 20 token terakhir per formulir agar sesi tidak membengkak.
        $_SESSION['_ah_form_tokens'][$bucket] = array_slice($daftar, -20);

        return $token;
    }
}

if (!function_exists('ah_form_token_consume')) {
    /**
     * Memakai token sekali pakai. Mengembalikan false bila token tidak dikenal
     * atau sudah pernah dipakai (artinya: pengiriman ulang).
     */
    function ah_form_token_consume(string $bucket, mixed $token): bool
    {
        $token = is_string($token) ? $token : '';
        $daftar = $_SESSION['_ah_form_tokens'][$bucket] ?? [];
        $posisi = array_search($token, $daftar, true);
        if ($token === '' || $posisi === false) {
            return false;
        }
        unset($daftar[$posisi]);
        $_SESSION['_ah_form_tokens'][$bucket] = array_values($daftar);

        return true;
    }
}

/** A-15: retain only explicitly permitted fields; confirmations/secrets never return. */
function ah_validation_keep(array $input, array $fields, Throwable $error, string $bucket): void
{
    ah_old_keep($input, $fields, $bucket);
    $messages = method_exists($error, 'errors') ? $error->errors() : [];
    if ($messages === []) { $messages = [$error->getMessage()]; }
    $aliases = [
        'nama' => ['nama', 'identitas'], 'nama_guru' => ['nama guru'], 'nama_santri' => ['nama santri'],
        'nama_kamar' => ['nama kamar'], 'nama_kelas' => ['nama kelas'], 'name' => ['nama akun', 'nama'],
        'no_hp' => ['hp', 'telepon'], 'phone' => ['hp'], 'email' => ['email'], 'username' => ['username'],
        'tahun' => ['tahun'], 'tahun_ajaran_id' => ['tahun', 'semester'], 'id_tahun' => ['tahun', 'semester'],
        'guru_id' => ['guru'], 'id_guru' => ['guru'], 'pengurus_id' => ['pengurus'], 'wali_id' => ['wali'],
        'user_id' => ['akun'], 'santri_id' => ['santri'], 'kelas_id' => ['kelas'], 'id_kelas' => ['kelas'], 'kamar_id' => ['kamar'],
        'target_type' => ['jenis', 'target', 'kelompok'], 'tanggal_mulai' => ['tanggal mulai'],
        'tanggal_selesai' => ['tanggal selesai'], 'tanggal_pertemuan' => ['tanggal', 'hari'],
        'waktu_mulai' => ['waktu mulai'], 'waktu_selesai' => ['waktu selesai'], 'waktu_sholat' => ['waktu pelaksanaan'],
        'tgl_izin' => ['tanggal izin'], 'tgl_kembali' => ['tanggal kembali'], 'tgl_lahir' => ['lahir'], 'jenis_kelamin' => ['kelamin'], 'kapasitas' => ['kapasitas'],
    ];
    $errors = [];
    foreach ($fields as $field) {
        foreach ($messages as $message) {
            if (!is_string($message)) { continue; }
            foreach ($aliases[$field] ?? [str_replace('_', ' ', $field)] as $alias) {
                if (mb_stripos($message, $alias) !== false) { $errors[$field][] = $message; break; }
            }
        }
    }
    $_SESSION[$bucket . '_errors'] = array_map(static fn (array $v): string => implode(' ', array_unique($v)), $errors);
}

/** Visible server-rendered error; Layout associates it with the matching control. */
function ah_field_error(string $field, string $bucket): string
{
    $message = $_SESSION[$bucket . '_errors'][$field] ?? '';
    if (!is_string($message) || $message === '') { return ''; }
    static $number = 0;
    return '<span class="ah-field-error" id="ah-error-' . ++$number . '" data-error-for="' . ah_e($field) . '">' . ah_e($message) . '</span>';
}
