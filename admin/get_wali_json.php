<?php

declare(strict_types=1);

/**
 * Pencarian kandidat wali untuk formulir santri (koreksi ke-2).
 *
 * Hanya membaca. Hasilnya adalah KANDIDAT yang harus dipilih admin: sistem
 * tidak pernah menggabungkan identitas berdasarkan kemiripan nama atau nomor
 * HP. Nomor HP boleh dipakai bersama beberapa wali.
 *
 * Dijaga `admin/_guard.php` (role admin + CSRF untuk POST). Endpoint ini hanya
 * melayani GET.
 */

require_once __DIR__ . '/_guard.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'pesan' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$kandidat = master_data_service()->waliCandidates($q, 20);

echo json_encode([
    'ok' => true,
    'q' => $q,
    'jumlah' => count($kandidat),
    'data' => array_map(static fn (array $row): array => [
        'id' => (int) $row['id'],
        'nama' => (string) $row['nama'],
        'no_hp' => (string) ($row['no_hp'] ?? ''),
        'jumlah_santri' => (int) $row['jumlah_santri'],
        'santri' => (string) ($row['santri'] ?? ''),
    ], $kandidat),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
