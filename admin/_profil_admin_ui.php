<?php

declare(strict_types=1);

use App\Profil\ProfilData;

/**
 * Isian identitas pribadi tambahan pada formulir/detail Data Guru dan Data
 * Pengurus (PRD-PROFIL-IMPORT Fase 1). Hanya tampil bila migrasi 035 sudah
 * terpasang. Foto tidak diunggah admin (PI-5): pemilik akun yang menggantinya.
 */

// Potongan halaman khusus admin; permintaan langsung tetap melewati guard admin.
require_once __DIR__ . '/_guard.php';

/** @param array<string, mixed> $profil baris `profil_pribadi` (boleh kosong) */
function profil_admin_fields(array $profil, string $bucket): void
{
    $isian = [
        'nik' => ['NIK', 'text', 16, 'numeric', '16 digit angka. Boleh kosong bila NIP terisi.'],
        'tempat_lahir' => ['Tempat lahir', 'text', 100, null, null],
        'tanggal_lahir' => ['Tanggal lahir', 'date', null, null, null],
        'alamat_rumah' => ['Alamat rumah', 'text', 255, null, null],
        'kelurahan' => ['Kelurahan/Desa', 'text', 100, null, null],
        'kecamatan' => ['Kecamatan', 'text', 100, null, null],
        'kabupaten' => ['Kabupaten/Kota', 'text', 100, null, null],
        'provinsi' => ['Provinsi', 'text', 100, null, null],
        'email' => ['Email', 'email', 191, null, 'Ikut disamakan ke akun login yang terhubung.'],
    ];
    echo '<fieldset class="mb-2"><legend class="h6">Identitas pribadi</legend>';
    foreach ($isian as $field => [$label, $type, $max, $mode, $bantuan]) {
        $id = 'profil_' . $field;
        echo '<div class="mb-3"><label class="form-label" for="' . $id . '">' . ah_e($label) . '</label>'
            . '<input class="form-control" id="' . $id . '" name="' . $field . '" type="' . $type . '"'
            . ($max !== null ? ' maxlength="' . $max . '"' : '') . ($mode !== null ? ' inputmode="' . $mode . '"' : '')
            . ($type === 'date' ? ' max="' . date('Y-m-d') . '"' : '')
            . ($bantuan !== null ? ' aria-describedby="' . $id . '_bantuan"' : '')
            . ' value="' . ah_e(ah_old($field, $profil, $bucket)) . '">' . ah_field_error($field, $bucket)
            . ($bantuan !== null ? '<div class="form-text" id="' . $id . '_bantuan">' . ah_e($bantuan) . '</div>' : '') . '</div>';
    }
    echo '</fieldset>';
}

/** Isian yang dikembalikan ke formulir saat validasi gagal. */
function profil_admin_field_names(): array
{
    return array_merge(['nik'], ProfilData::TAMBAHAN);
}

/**
 * @param array<string, mixed> $profil
 * @param array<int, array<string, mixed>> $akun akun terhubung (untuk email cadangan)
 */
function profil_admin_detail(array $profil, string $jenis, int $id, array $akun = []): void
{
    $email = $profil['email'] ?? null;
    foreach ($akun as $a) {
        $email ??= $a['email'] ?? null;
    }
    $baris = [
        'NIK' => $profil['nik'] ?? null,
        'Tempat, tanggal lahir' => trim(implode(', ', array_filter([$profil['tempat_lahir'] ?? null, isset($profil['tanggal_lahir']) ? ah_tanggal((string) $profil['tanggal_lahir'], false) : null]))) ?: null,
        'Alamat' => ProfilData::alamatLengkap($profil),
        'Email' => $email,
    ];
    foreach ($baris as $label => $nilai) {
        echo '<div><dt>' . ah_e($label) . '</dt><dd>' . ah_e($nilai === null || $nilai === '' ? '—' : (string) $nilai) . '</dd></div>';
    }
    if (!empty($profil['foto'])) {
        echo '<div><dt>Foto</dt><dd><img src="foto_profil.php?jenis=' . ah_e($jenis) . '&amp;id=' . $id . '&amp;v=' . (int) ($profil['foto_versi'] ?? 0)
            . '" alt="Foto profil" width="72" height="72" style="object-fit:cover;border-radius:50%"></dd></div>';
    }
}
