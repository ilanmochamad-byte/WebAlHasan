<?php

declare(strict_types=1);

/**
 * Adaptor tampilan halaman admin.
 *
 * Sejak paket perapihan V1–V2 (30 Agustus 2026) berkas ini TIDAK lagi menggambar
 * kerangka halamannya sendiri. Ia hanya menjaga nama fungsi lama (`master_*`)
 * agar halaman admin yang sudah ada tidak perlu ditulis ulang seluruhnya,
 * sementara kerangka sebenarnya berasal dari `App\Ui\Layout` yang dipakai
 * bersama oleh admin dan portal.
 *
 * Guard admin tetap berada di `admin/_guard.php` dan tetap dipanggil di sini:
 * halaman yang memuat berkas ini memang halaman khusus admin.
 */

require_once __DIR__ . '/_guard.php';

function master_e(mixed $value): string
{
    return ah_e($value);
}

function master_flash(string $type, string $message, ?string $extraHtml = null): void
{
    ah_flash_set($type, $message, $extraHtml);
}

function master_redirect(string $path): never
{
    header('Location: ' . app_url('/admin/' . ltrim($path, '/')));
    exit;
}

/**
 * @param array<string, mixed> $replace
 */
function master_query(array $replace = []): string
{
    return ah_query($replace);
}

/**
 * @param array<string, mixed> $options Opsi tambahan kerangka: description,
 *        breadcrumbs, actions, tabs, active, heading.
 */
function master_header(string $title, array $options = []): void
{
    $options['title'] = $title;
    $options['user'] = $GLOBALS['currentUser'];
    ah_page_open($options);
}

function master_footer(): void
{
    ah_page_close();
}

function master_csrf(): string
{
    return ah_csrf();
}

function master_pagination(int $total, int $page, int $perPage): void
{
    ah_pagination($total, $page, $perPage);
}
