<?php

declare(strict_types=1);

/**
 * Bantuan tampilan Penilaian & rapor (redesain website Tahap 7, R-1 … R-8).
 *
 * Hanya tampilan: konteks semester/kelas yang diingat untuk sesi, label
 * status, dan simbol matriks. Hak akses tetap ditegakkan layanan V4 di setiap
 * permintaan; konteks sesi tidak pernah dipakai sebagai pemberi akses — ID
 * dari sesi selalu dicocokkan dengan daftar pilihan yang dikembalikan layanan.
 */

if (!function_exists('rapor_status_label')) {
    /** Status bahan/nilai V4 dalam kata biasa (Dikirim → Diserahkan). */
    function rapor_status_label(?string $status): string
    {
        return match ((string) $status) {
            'Dikirim' => 'Diserahkan',
            'Dikembalikan' => 'Perlu perbaikan',
            'Terverifikasi' => 'Terverifikasi',
            'Draf' => 'Draf',
            '' => 'Belum diisi',
            default => (string) $status,
        };
    }

    function rapor_status_badge(?string $status): string
    {
        return ah_status(rapor_status_label($status));
    }

    /** Angka desimal basis data tanpa nol di belakang koma ("72.50" → "72.5", "70" tetap). */
    function rapor_angka(mixed $nilai): string
    {
        $v = (string) $nilai;

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    /** "Ganjil 2026/2027" dari baris yang memuat `tahun` dan `semester`. */
    function rapor_semester(array $row): string
    {
        return trim((string) ($row['semester'] ?? '') . ' ' . (string) ($row['tahun'] ?? ''));
    }

    function rapor_kapasitas(?string $kapasitas): string
    {
        return match ((string) $kapasitas) {
            'guru' => 'guru',
            'admin' => 'admin',
            'pendidikan', 'pendidikan_pengganti' => 'Bagian Pendidikan',
            'pembimbing' => 'pembimbing',
            'murobi' => 'murobi',
            default => (string) $kapasitas,
        };
    }

    /** Label jabatan penanda tangan dalam kata biasa. */
    function rapor_jabatan(?string $jabatan): string
    {
        return match ((string) $jabatan) {
            'Pendidikan' => 'Bagian Pendidikan',
            'Pimpinan' => 'Pimpinan pesantren',
            default => (string) $jabatan,
        };
    }

    /**
     * Simbol sel matriks kelengkapan (R-5). Makna selalu ikut sebagai teks
     * tersembunyi/legenda; warna hanya penguat.
     *
     * @return array{simbol:string, label:string, kelas:string}
     */
    function rapor_sel(string $status, bool $adaSumber): array
    {
        return match (true) {
            $status === 'Terverifikasi' => ['simbol' => '✓', 'label' => 'Terverifikasi', 'kelas' => 'ok'],
            $status === 'Dikirim' => ['simbol' => '●', 'label' => 'Diserahkan', 'kelas' => 'info'],
            $status === 'Dikembalikan' => ['simbol' => '!', 'label' => 'Perlu perbaikan', 'kelas' => 'warn'],
            $adaSumber => ['simbol' => '◐', 'label' => 'Draf', 'kelas' => 'muted'],
            default => ['simbol' => '–', 'label' => 'Belum ada', 'kelas' => 'empty'],
        };
    }

    /** @return array{tahun:int, kelas:int} */
    function rapor_konteks(): array
    {
        $k = $_SESSION['ah_rapor_konteks'] ?? [];

        return ['tahun' => (int) ($k['tahun'] ?? 0), 'kelas' => (int) ($k['kelas'] ?? 0)];
    }

    function rapor_konteks_simpan(int $tahun, int $kelas): void
    {
        if ($tahun > 0 && $kelas > 0) {
            $_SESSION['ah_rapor_konteks'] = ['tahun' => $tahun, 'kelas' => $kelas];
        }
    }

    /**
     * Rencana yang dibuka halaman Kelengkapan/Penerbitan/Penanda tangan.
     * Urutan: `rencana_id` (tautan lama, Beranda) → `tahun_ajaran_id` +
     * `kelas_id` dari bilah konteks → konteks sesi → rencana pertama. Hasil
     * selalu salah satu pilihan dari layanan; 0 bila tidak ada pilihan.
     *
     * @param array<int, array<string, mixed>> $options baris rencana (id, tahun_ajaran_id, kelas_id)
     * @return array{id:int, cocok:bool} cocok=false bila pasangan semester–kelas yang diminta tidak punya rencana
     */
    function rapor_pilih_rencana(array $options, int $rencanaId, int $tahun, int $kelas): array
    {
        if ($options === []) {
            return ['id' => 0, 'cocok' => true];
        }
        foreach ($options as $o) {
            if ($rencanaId > 0 && (int) $o['id'] === $rencanaId) {
                rapor_konteks_simpan((int) $o['tahun_ajaran_id'], (int) $o['kelas_id']);
                return ['id' => $rencanaId, 'cocok' => true];
            }
        }
        if ($rencanaId > 0) {
            // ID di luar pilihan: biarkan layanan menolak dengan alasannya.
            return ['id' => $rencanaId, 'cocok' => true];
        }
        $diminta = $tahun > 0 && $kelas > 0;
        if (!$diminta) {
            $k = rapor_konteks();
            [$tahun, $kelas] = [$k['tahun'], $k['kelas']];
        }
        foreach ($options as $o) {
            if ((int) $o['tahun_ajaran_id'] === $tahun && (int) $o['kelas_id'] === $kelas) {
                rapor_konteks_simpan($tahun, $kelas);
                return ['id' => (int) $o['id'], 'cocok' => true];
            }
        }
        if ($diminta) {
            rapor_konteks_simpan($tahun, $kelas);
            return ['id' => 0, 'cocok' => false];
        }
        $o = $options[0];
        rapor_konteks_simpan((int) $o['tahun_ajaran_id'], (int) $o['kelas_id']);

        return ['id' => (int) $o['id'], 'cocok' => true];
    }

    /**
     * Bilah konteks "Konteks: semester ▾ kelas ▾ · Tersimpan untuk sesi ini".
     * GET biasa; dengan JavaScript berganti saat pilihan berubah.
     *
     * @param array<int, array{id:int|string, label:string}> $semester
     * @param array<int, array{id:int|string, label:string}> $kelas
     * @param array<string, scalar> $hidden
     */
    function rapor_konteks_bar(array $semester, int $tahunAktif, array $kelas, int $kelasAktif, array $hidden = [], string $action = ''): string
    {
        $opsi = static function (array $items, int $aktif): string {
            $html = '';
            foreach ($items as $item) {
                $html .= '<option value="' . (int) $item['id'] . '"' . ((int) $item['id'] === $aktif ? ' selected' : '') . '>' . ah_e($item['label']) . '</option>';
            }
            return $html;
        };
        $html = '<form method="get" class="ah-ctx ah-no-print" aria-label="Konteks semester dan kelas"' . ($action !== '' ? ' action="' . ah_e($action) . '"' : '') . '>'
            . '<b class="ah-ctx__lead">Konteks:</b>';
        foreach ($hidden as $name => $value) {
            $html .= '<input type="hidden" name="' . ah_e($name) . '" value="' . ah_e($value) . '">';
        }
        $html .= '<label class="ah-ctx__field"><span class="ah-visually-hidden">Semester</span><select class="form-select form-select-sm" name="tahun_ajaran_id" data-ah-autosubmit>'
            . ($tahunAktif > 0 ? '' : '<option value="">Pilih semester</option>') . $opsi($semester, $tahunAktif) . '</select></label>'
            . '<label class="ah-ctx__field"><span class="ah-visually-hidden">Kelas</span><select class="form-select form-select-sm" name="kelas_id" data-ah-autosubmit>'
            . ($kelasAktif > 0 ? '' : '<option value="">Pilih kelas</option>') . $opsi($kelas, $kelasAktif) . '</select></label>'
            . '<button class="btn btn-sm btn-outline-primary ah-ctx__go" type="submit">Buka</button>'
            . '<span class="ah-ctx__note">Tersimpan untuk sesi ini</span></form>';

        return $html;
    }

    /**
     * Pilihan semester & kelas bilah konteks dari daftar rencana layanan.
     *
     * @param array<int, array<string, mixed>> $options
     * @return array{0:array<int, array{id:int,label:string}>, 1:array<int, array{id:int,label:string}>}
     */
    function rapor_konteks_opsi(array $options): array
    {
        $semester = [];
        $kelas = [];
        foreach ($options as $o) {
            $semester[(int) $o['tahun_ajaran_id']] = ['id' => (int) $o['tahun_ajaran_id'], 'label' => rapor_semester($o)];
            $kelas[(int) $o['kelas_id']] = ['id' => (int) $o['kelas_id'], 'label' => (string) $o['nama_kelas']];
        }
        uasort($kelas, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return [array_values($semester), array_values($kelas)];
    }

    /** Waktu singkat "2 Okt 2026 · 07.40" dari datetime basis data. */
    function rapor_waktu(?string $waktu): string
    {
        if ($waktu === null || $waktu === '') {
            return '—';
        }
        $jam = strlen($waktu) > 10 ? ' · ' . str_replace(':', '.', substr($waktu, 11, 5)) : '';

        return ah_tanggal(substr($waktu, 0, 10), false) . $jam;
    }

    /**
     * Isi rapor (`RaporDocument::body`, sama dengan PDF) untuk ditampilkan di
     * dalam halaman: tingkat judul diturunkan satu agar halaman tetap punya
     * satu h1. Dokumen PDF tidak berubah.
     */
    function rapor_dokumen(array $snapshot): string
    {
        $html = \App\V4\RaporDocument::body($snapshot);

        return str_replace(['<h2>', '</h2>', '<h1>', '</h1>'], ['<h3>', '</h3>', '<h2 class="ah-rapor-doc__judul">', '</h2>'], $html);
    }

    /** URL gambar tanda tangan berpenjaga (R-1, R-7). */
    function rapor_ttd_url(int $asetId): string
    {
        return app_url('/portal/rapor_ttd.php') . '?aset=' . $asetId;
    }

    /**
     * Alasan sesi (R-3): diisi sekali di awal sesi pengganti/admin dan dipakai
     * pada setiap perubahan mapel yang sama sampai keluar atau diganti.
     */
    function rapor_alasan_sesi(string $jenis, int $kunci): string
    {
        $v = $_SESSION['ah_rapor_alasan'][$jenis][$kunci] ?? '';

        return is_string($v) ? $v : '';
    }

    function rapor_alasan_sesi_simpan(string $jenis, int $kunci, string $alasan): void
    {
        $alasan = trim($alasan);
        if ($alasan === '') {
            unset($_SESSION['ah_rapor_alasan'][$jenis][$kunci]);
            return;
        }
        $_SESSION['ah_rapor_alasan'][$jenis][$kunci] = mb_substr($alasan, 0, 1000);
    }
}
