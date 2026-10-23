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
use App\Ui\Status;

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
        if (!isset($options['capabilities']) || !isset($options['v3_capabilities']) || !isset($options['unread'])) {
            $context = ui_context($options['user']);
            $options['capabilities'] ??= $context['capabilities'];
            $options['v3_capabilities'] ??= $context['v3_capabilities'];
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

if (!function_exists('ah_stats')) {
    /**
     * Ringkasan angka (redesain Tahap 3). Butir bertautan menjadi filter.
     *
     * @param array<int, array{label:string, value:string, url?:?string, warn?:bool, hint?:string}> $items
     */
    function ah_stats(array $items, string $label = 'Ringkasan'): string
    {
        $html = '<section class="ah-stats" aria-label="' . ah_e($label) . '">';
        foreach ($items as $item) {
            $class = 'ah-stat' . (!empty($item['warn']) ? ' ah-stat--warn' : '');
            $isi = '<p class="ah-stat__label">' . ah_e($item['label']) . '</p><p class="ah-stat__value">' . ah_e($item['value']) . '</p>'
                . (!empty($item['hint']) ? '<p class="ah-stat__hint">' . ah_e($item['hint']) . '</p>' : '');
            $html .= !empty($item['url'])
                ? '<a class="' . $class . ' ah-stat--link" href="' . ah_e($item['url']) . '">' . $isi . '</a>'
                : '<div class="' . $class . '">' . $isi . '</div>';
        }

        return $html . '</section>';
    }
}

if (!function_exists('ah_chips')) {
    /**
     * Chip status = filter sekaligus ringkasan angka (K-5, K-6, N-2).
     *
     * @param array<int, array{label:string, url:string, active?:bool, count?:int|null}> $chips
     */
    function ah_chips(array $chips, string $label): string
    {
        $html = '<ul class="ah-tabs ah-chips" aria-label="' . ah_e($label) . '">';
        foreach ($chips as $chip) {
            $html .= '<li><a href="' . ah_e($chip['url']) . '"' . (!empty($chip['active']) ? ' aria-current="page"' : '') . '>'
                . '<span>' . ah_e($chip['label']) . '</span>'
                . (isset($chip['count']) ? ' <span class="ah-chips__count">' . number_format((int) $chip['count'], 0, ',', '.') . '</span>' : '')
                . '</a></li>';
        }

        return $html . '</ul>';
    }
}

if (!function_exists('ah_filter_open')) {
    /**
     * Saringan daftar. Di desktop/tablet tampil sebaris; di HP terlipat di
     * balik tombol "Saring" kecuali ada saringan aktif. Tanpa JavaScript
     * saringan selalu terbuka. Formulir GET, sehingga alamat dapat dibagikan.
     *
     * @param array<string, scalar> $hidden nilai tetap (mis. status chip)
     */
    function ah_filter_open(int $active = 0, array $hidden = [], string $action = ''): void
    {
        echo '<details class="ah-filter ah-no-print" open data-ah-filter' . ($active > 0 ? ' data-ah-filter-active' : '') . '>'
            . '<summary class="btn btn-outline-secondary"><i class="fas fa-sliders" aria-hidden="true"></i> Saring'
            . ($active > 0 ? ' <span class="ah-chips__count">' . $active . '</span>' : '') . '</summary>'
            . '<form method="get" class="ah-filter__form"' . ($action !== '' ? ' action="' . ah_e($action) . '"' : '') . '>';
        foreach ($hidden as $name => $value) {
            if ($value !== '' && $value !== null) {
                echo '<input type="hidden" name="' . ah_e($name) . '" value="' . ah_e($value) . '">';
            }
        }
    }

    /**
     * @param array<string, scalar> $reset alamat "Bersihkan" mempertahankan nilai ini
     */
    function ah_filter_close(array $reset = []): void
    {
        echo '<div class="ah-filter__actions"><button class="btn btn-primary" type="submit">Terapkan</button>'
            . '<a class="btn btn-outline-secondary" href="?' . ah_e(http_build_query(array_filter($reset, static fn ($v): bool => $v !== '' && $v !== null))) . '">Bersihkan</a></div>'
            . '</form></details>';
    }
}

if (!function_exists('ah_wants_json')) {
    /** Permintaan dari `AlHasan.post` (proses massal) mengharapkan JSON. */
    function ah_wants_json(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /**
     * @param array<string, mixed> $payload
     */
    function ah_json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('ah_xlsx_download')) {
    /**
     * Unduhan Excel yang dibuat di server (pengganti ekspor SheetJS + kotak alert peramban).
     * Baris pertama adalah judul kolom (tebal). Seluruh teks lain ditulis
     * mentah (awalan \0 SimpleXLSXGen) sehingga isian pengguna tidak pernah
     * dibaca sebagai rumus, tag gaya, atau angka; NISN/NIK/HP tetap utuh.
     *
     * @param array<int, array<int, scalar|null>> $rows
     */
    function ah_xlsx_download(string $fileName, array $rows): never
    {
        require_once APP_ROOT . '/admin/SimpleXLSXGen.php';
        $safe = [];
        foreach ($rows as $index => $row) {
            $safe[] = array_map(static function ($cell) use ($index) {
                if ($index === 0) {
                    return '<b>' . str_replace(['<', '>'], '', (string) $cell) . '</b>';
                }
                if (is_int($cell) || is_float($cell)) {
                    return $cell;
                }

                return "\0" . (string) $cell;
            }, $row);
        }
        header('Cache-Control: no-store');
        \Shuchkin\SimpleXLSXGen::fromArray($safe)->downloadAs($fileName);
        exit;
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

if (!function_exists('ah_status')) {
    /**
     * Label status memakai kamus warna bersama (`App\Ui\Status`): hijau
     * selesai, biru diajukan, kuning menunggu, merah ditolak, abu draf/arsip.
     */
    function ah_status(string $status, ?string $label = null): string
    {
        return ah_badge($label ?? $status, Status::tone($status));
    }
}

if (!function_exists('ah_menu')) {
    /**
     * Menu aksi ⋯ (redesain Tahap 1, K-3). Tindakan lain selain tindakan
     * utama dikumpulkan di sini; tindakan berbahaya selalu di bawah dan merah.
     * Memakai <details>, sehingga tetap dapat dibuka tanpa JavaScript.
     *
     * Butir tautan:  ['label' => 'Ubah', 'url' => '...', 'icon' => 'fa-pen']
     * Butir POST:    ['label' => 'Nonaktifkan', 'action' => '...', 'fields' => ['id' => 5],
     *                 'danger' => true, 'confirm' => ['title' => 'Nonaktifkan Ust. Mahmud?',
     *                 'text' => 'Akibatnya …', 'ok' => 'Nonaktifkan', 'reason' => 'alasan',
     *                 'reason_label' => 'Alasan']]
     *
     * Menu ini hanya tampilan; setiap tindakan POST tetap diperiksa CSRF dan
     * hak aksesnya oleh halaman tujuan.
     *
     * @param array<int, array<string, mixed>> $items
     */
    function ah_menu(array $items, string $label = 'Tindakan lain', bool $row = true): string
    {
        $safe = [];
        $danger = [];
        foreach ($items as $item) {
            if (!empty($item['danger'])) {
                $danger[] = $item;
            } else {
                $safe[] = $item;
            }
        }
        $html = '';
        foreach ($safe as $item) {
            $html .= ah_menu_item($item);
        }
        if ($safe !== [] && $danger !== []) {
            $html .= '<hr class="ah-menu__sep">';
        }
        foreach ($danger as $item) {
            $html .= ah_menu_item($item);
        }

        return '<details class="ah-menu' . ($row ? ' ah-menu--row' : '') . '">'
            . '<summary class="ah-menu__btn"><i class="fas fa-ellipsis" aria-hidden="true"></i>'
            . '<span class="ah-visually-hidden">' . ah_e($label) . '</span></summary>'
            . '<div class="ah-menu__list">' . $html . '</div></details>';
    }

    /**
     * @param array<string, mixed> $item
     */
    function ah_menu_item(array $item): string
    {
        $class = 'ah-menu__item' . (!empty($item['danger']) ? ' ah-menu__item--danger' : '');
        $icon = !empty($item['icon']) ? '<i class="fas ' . ah_e($item['icon']) . '" aria-hidden="true"></i>' : '';
        $text = $icon . '<span>' . ah_e($item['label'] ?? '') . '</span>';
        if (!isset($item['action'])) {
            return '<a class="' . $class . '" href="' . ah_e($item['url'] ?? '#') . '">' . $text . '</a>';
        }
        $attributes = '';
        $confirm = $item['confirm'] ?? null;
        if (is_array($confirm)) {
            $attributes .= ' data-confirm="' . ah_e($confirm['text'] ?? '') . '"';
            foreach (['title' => 'data-confirm-title', 'ok' => 'data-confirm-ok', 'reason' => 'data-confirm-reason', 'reason_label' => 'data-confirm-reason-label',
                'match' => 'data-confirm-match', 'match_label' => 'data-confirm-match-label'] as $key => $attribute) {
                if (!empty($confirm[$key])) {
                    $attributes .= ' ' . $attribute . '="' . ah_e($confirm[$key]) . '"';
                }
            }
            if (!empty($item['danger'])) {
                $attributes .= ' data-confirm-tone="danger"';
            }
        }
        $fields = Csrf::input();
        foreach ((array) ($item['fields'] ?? []) as $name => $value) {
            $fields .= '<input type="hidden" name="' . ah_e($name) . '" value="' . ah_e($value) . '">';
        }

        return '<form method="post" action="' . ah_e($item['action']) . '"' . $attributes . '>' . $fields
            . '<button class="' . $class . '" type="submit">' . $text . '</button></form>';
    }
}

if (!function_exists('ah_panel_open')) {
    /**
     * Panel samping untuk tambah/ubah/detail (redesain Tahap 1, K-3).
     *
     * Halaman merender panel ini saat alamatnya memuat ?action=create/edit,
     * bersama daftar di bawahnya. Tanpa JavaScript panel tampil sebagai bagian
     * halaman biasa; dengan JavaScript ia menjadi panel kanan (desktop),
     * 70% lebar (tablet), atau layar penuh (HP). Tombol Tutup dan tombol Esc
     * kembali ke `close_url`, sehingga tidak ada keadaan yang hanya ada di JS.
     *
     * @param array{title:string, close_url:string, description?:string} $options
     */
    function ah_panel_open(array $options): void
    {
        echo '<section class="ah-panel" id="ah-panel" aria-labelledby="ah-panel-title" data-ah-close="'
            . ah_e($options['close_url']) . '"><div class="ah-panel__head"><div>'
            . '<h2 id="ah-panel-title">' . ah_e($options['title']) . '</h2>'
            . (!empty($options['description']) ? '<p class="small text-muted mb-0">' . ah_e($options['description']) . '</p>' : '')
            . '</div><a class="btn btn-sm btn-outline-secondary" href="' . ah_e($options['close_url']) . '">'
            . '<i class="fas fa-xmark" aria-hidden="true"></i> Tutup</a></div><div class="ah-panel__body">';
    }

    function ah_panel_close(): void
    {
        echo '</div></section>';
    }
}

if (!function_exists('ah_selection_bar')) {
    /**
     * Bilah "12 santri dipilih" + tindakan massal. Letakkan di dalam wadah
     * `data-ah-selection="santri"` yang memuat kotak centang `data-ah-select`.
     */
    function ah_selection_bar(string $actionsHtml): string
    {
        return '<div class="ah-selbar" hidden><span class="ah-selbar__count" data-ah-selcount></span>'
            . $actionsHtml
            . '<button class="btn btn-link btn-sm" type="button" data-ah-select-clear>Batalkan pilihan</button></div>';
    }
}

if (!function_exists('ah_error_summary')) {
    /**
     * Ringkasan galat di atas formulir; pesan rinci tetap di bawah tiap isian
     * (`ah_field_error`). Kosong bila tidak ada galat.
     */
    function ah_error_summary(string $bucket, string $lead = 'Data belum tersimpan.'): string
    {
        $errors = $_SESSION[$bucket . '_errors'] ?? [];
        $count = is_array($errors) ? count(array_filter($errors, static fn ($m): bool => is_string($m) && $m !== '')) : 0;
        if ($count === 0) {
            return '';
        }

        return '<div class="ah-note ah-note--danger" role="alert"><i class="fas fa-circle-exclamation mt-1" aria-hidden="true"></i><div>'
            . '<strong class="ah-note__title">' . ah_e($lead) . '</strong>'
            . '<span>Perbaiki ' . $count . ' isian bertanda merah.</span></div></div>';
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

// ---------------------------------------------------------------------------
// Redesain website Tahap 4: tanggal, rentang, hari, periode, segmen.
// ---------------------------------------------------------------------------

if (!function_exists('ah_hari')) {
    /**
     * Nama hari untuk tampilan. Nilai "Minggu" di basis data ditulis "Ahad"
     * (keputusan T); nilai yang disimpan tidak berubah.
     */
    function ah_hari(?string $hari): string
    {
        $hari = (string) $hari;

        return $hari === 'Minggu' ? 'Ahad' : $hari;
    }
}

if (!function_exists('ah_tanggal')) {
    /**
     * Tanggal Y-m-d (atau Y-m-d H:i:s) menjadi "Ahad 12 Okt 2026".
     * Nilai yang tidak dapat dibaca dikembalikan apa adanya.
     */
    function ah_tanggal(?string $tanggal, bool $hari = true, bool $tahun = true): string
    {
        $time = $tanggal === null || $tanggal === '' ? false : strtotime($tanggal);
        if ($time === false) {
            return (string) $tanggal;
        }

        return ($hari ? Layout::dayName($time) . ' ' : '') . Layout::date($time, $tahun);
    }

    /**
     * Rentang tanggal ringkas: "Ahad 12 – Selasa 14 Okt 2026"; satu hari
     * cukup ditulis sekali.
     */
    function ah_rentang(?string $dari, ?string $sampai, bool $hari = true): string
    {
        $a = $dari === null || $dari === '' ? false : strtotime($dari);
        $b = $sampai === null || $sampai === '' ? false : strtotime($sampai);
        if ($a === false || $b === false) {
            return trim((string) $dari . ' – ' . (string) $sampai, ' –');
        }
        if (date('Y-m-d', $a) === date('Y-m-d', $b)) {
            return ah_tanggal(date('Y-m-d', $a), $hari);
        }
        $kiri = ($hari ? Layout::dayName($a) . ' ' : '') . (int) date('j', $a);
        if (date('Y-m', $a) !== date('Y-m', $b)) {
            $kiri = ($hari ? Layout::dayName($a) . ' ' : '') . Layout::date($a, date('Y', $a) !== date('Y', $b));
        }

        return $kiri . ' – ' . ah_tanggal(date('Y-m-d', $b), $hari);
    }

    /** Waktu Y-m-d H:i:s menjadi "12 Okt 2026 08.10". */
    function ah_waktu(?string $waktu): string
    {
        $time = $waktu === null || $waktu === '' ? false : strtotime($waktu);

        return $time === false ? (string) $waktu : Layout::date($time) . ' ' . date('H.i', $time);
    }
}

if (!function_exists('ah_seg')) {
    /**
     * Kontrol segmen berupa tautan (mis. Mingguan/Daftar, Santri/Guru/Gabungan).
     * Tetap berfungsi tanpa JavaScript.
     *
     * @param array<int, array{label:string, url:string, active?:bool}> $items
     */
    function ah_seg(array $items, string $label): string
    {
        $html = '<nav class="ah-seg ah-no-print" aria-label="' . ah_e($label) . '">';
        foreach ($items as $item) {
            $html .= '<a href="' . ah_e($item['url']) . '"' . (!empty($item['active']) ? ' aria-current="page"' : '') . '>' . ah_e($item['label']) . '</a>';
        }

        return $html . '</nav>';
    }
}

if (!function_exists('ah_timeline')) {
    /**
     * Linimasa riwayat (I-5): butir terlama di atas.
     *
     * @param array<int, array{waktu:string, pelaku:string, judul:string, isi?:string, tone?:string}> $items
     */
    function ah_timeline(array $items, string $label = 'Riwayat'): string
    {
        if ($items === []) {
            return '<p class="text-muted mb-0">Belum ada riwayat.</p>';
        }
        $html = '<ol class="ah-tl" aria-label="' . ah_e($label) . '">';
        foreach ($items as $item) {
            $html .= '<li class="ah-tl__item' . (!empty($item['tone']) ? ' ah-tl__item--' . ah_e($item['tone']) : '') . '">'
                . '<span class="ah-tl__meta">' . ah_e($item['waktu']) . ' · ' . ah_e($item['pelaku']) . '</span>'
                . '<b class="ah-tl__title">' . ah_e($item['judul']) . '</b>'
                . (!empty($item['isi']) ? '<span class="ah-tl__text">' . nl2br(ah_e($item['isi'])) . '</span>' : '')
                . '</li>';
        }

        return $html . '</ol>';
    }
}

if (!function_exists('ah_action_dialog_open')) {
    /**
     * Tindakan yang membuka formulir dalam dialog (I-5, J-1).
     *
     * Tanpa JavaScript berupa <details> yang terbuka di tempat; dengan
     * JavaScript formulirnya tampil di dialog modal. Setelah validasi server
     * gagal, panggil dengan $open = true agar isian dan pesan langsung terlihat.
     * Formulir di dalamnya tetap POST biasa yang diperiksa server.
     */
    function ah_action_dialog_open(string $id, string $label, string $title, string $tone = 'outline-primary', bool $open = false, ?string $icon = null): void
    {
        echo '<details class="ah-act" id="' . ah_e($id) . '" data-ah-dialog' . ($open ? ' open' : '') . '>'
            . '<summary class="btn btn-' . ah_e($tone) . '">'
            . ($icon !== null ? '<i class="fas ' . ah_e($icon) . '" aria-hidden="true"></i> ' : '')
            . ah_e($label) . '</summary>'
            . '<div class="ah-act__body" role="group" aria-labelledby="' . ah_e($id) . '-judul">'
            . '<h2 class="ah-act__title" id="' . ah_e($id) . '-judul">' . ah_e($title) . '</h2>';
    }

    function ah_action_dialog_close(): void
    {
        echo '</div></details>';
    }
}
