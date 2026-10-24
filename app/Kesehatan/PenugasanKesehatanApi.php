<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Penugasan\PenugasanException;
use App\Penugasan\PenugasanJenis;
use App\Penugasan\PenugasanService;

/**
 * Pengelolaan penugasan petugas kesehatan lewat REST API (PRD V5 Fase 4).
 *
 * Aditif untuk aplikasi: hanya jenis `kesehatan`, dan setiap mutasi tetap
 * lewat `PenugasanService` yang sama dengan Pusat Penugasan web (transaksi,
 * pemeriksaan tumpang tindih, audit, penolakan admin di layanan). Kelas ini
 * hanya menambah gerbang baca admin dan bentuk DTO tanpa kolom sensitif.
 * Tidak ada penghapusan: penugasan diakhiri atau dinonaktifkan beralasan.
 */
final class PenugasanKesehatanApi
{
    public function __construct(
        private PenugasanService $penugasan,
        private KesehatanService $kesehatan
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input q, status, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, opsi:array<string, mixed>}
     */
    public function daftar(array $user, array $input): array
    {
        $this->wajibAdmin($user);
        $status = in_array($input['status'] ?? null, ['aktif', 'akan_datang', 'berakhir', 'nonaktif'], true) ? (string) $input['status'] : '';
        $halaman = is_scalar($input['page'] ?? null) && preg_match('/^[1-9][0-9]{0,6}$/D', (string) $input['page']) ? (int) $input['page'] : 1;
        $hasil = $this->penugasan->daftar(PenugasanJenis::KESEHATAN, ['q' => is_string($input['q'] ?? null) ? $input['q'] : '', 'status' => $status], $halaman);
        $opsi = $this->penugasan->opsi(PenugasanJenis::KESEHATAN);

        return [
            'rows' => array_map(fn (array $r): array => $this->dto($r), $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
            'opsi' => [
                'pengurus' => array_map(static fn (array $p): array => ['id' => (int) $p['id'], 'nama' => (string) $p['nama'], 'jabatan' => $p['keterangan'] === null ? null : (string) $p['keterangan']], $opsi['subjek']),
                'tahun' => array_map(static fn (array $t): array => ['id' => (int) $t['id'], 'tahun' => (string) $t['tahun'], 'semester' => (string) $t['semester'], 'status' => (string) $t['status']], $opsi['tahun']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input pengurus_id, tahun_ajaran_id, tanggal_mulai, tanggal_selesai, catatan
     * @return array{id:int, penugasan:array<string, mixed>, hak:?string}
     */
    public function buat(array $user, array $input): array
    {
        $this->wajibAdmin($user);
        $data = [];
        foreach (['pengurus_id', 'tahun_ajaran_id', 'tanggal_mulai', 'tanggal_selesai', 'catatan'] as $kolom) {
            $nilai = $input[$kolom] ?? '';
            $data[$kolom] = is_scalar($nilai) ? (string) $nilai : '';
        }
        $id = $this->penugasan->buat(PenugasanJenis::KESEHATAN, $data, (int) $user['id']);
        $baris = $this->penugasan->detail(PenugasanJenis::KESEHATAN, $id) ?? throw PenugasanException::notFound();

        return ['id' => $id, 'penugasan' => $this->dto($baris), 'hak' => $this->penugasan->capabilityEfektif(PenugasanJenis::KESEHATAN, (int) $baris['subjek_id'])['pesan']];
    }

    /**
     * Akhiri (tanggal_selesai + alasan), nonaktifkan, atau aktifkan kembali (alasan).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input
     * @return array{penugasan:array<string, mixed>}
     */
    public function ubahStatus(array $user, int $id, string $aksi, array $input): array
    {
        $this->wajibAdmin($user);
        $alasan = is_string($input['alasan'] ?? null) ? $input['alasan'] : '';
        $aktor = (int) $user['id'];
        match ($aksi) {
            'akhiri' => $this->penugasan->akhiri(PenugasanJenis::KESEHATAN, $id, is_string($input['tanggal_selesai'] ?? null) ? $input['tanggal_selesai'] : '', $alasan, $aktor),
            'nonaktifkan' => $this->penugasan->nonaktifkan(PenugasanJenis::KESEHATAN, $id, $alasan, $aktor),
            'aktifkan' => $this->penugasan->aktifkan(PenugasanJenis::KESEHATAN, $id, $alasan, $aktor),
            default => throw PenugasanException::invalid('Aksi penugasan tidak dikenal.'),
        };
        $baris = $this->penugasan->detail(PenugasanJenis::KESEHATAN, $id) ?? throw PenugasanException::notFound();

        return ['penugasan' => $this->dto($baris)];
    }

    /** Admin = sumber hak baca pengawasan (sama dengan riwayat akses). */
    private function wajibAdmin(array $user): void
    {
        if (!$this->penugasan->skemaTersedia(PenugasanJenis::KESEHATAN)) {
            throw new KesehatanException('Modul kesehatan belum dipasang pada basis data ini.', 503);
        }
        if (!$this->kesehatan->akses($user)['riwayat_akses']) {
            throw new KesehatanException('Pengelolaan penugasan kesehatan hanya untuk admin.', 403);
        }
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function dto(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'pengurus' => ['id' => (int) $r['subjek_id'], 'nama' => (string) $r['subjek_nama'], 'jabatan' => $r['subjek_keterangan'] === null ? null : (string) $r['subjek_keterangan']],
            'tahun' => ['id' => (int) $r['tahun_ajaran_id'], 'tahun' => (string) $r['tahun'], 'semester' => (string) $r['semester']],
            'tanggal_mulai' => (string) $r['tanggal_mulai'],
            'tanggal_selesai' => $r['tanggal_selesai'] === null ? null : (string) $r['tanggal_selesai'],
            'aktif' => (int) $r['is_active'] === 1,
            'status' => (string) $r['status_label'],
            'catatan' => $r['catatan'] === null ? null : (string) $r['catatan'],
            'alasan_pengakhiran' => $r['alasan_pengakhiran'] === null ? null : (string) $r['alasan_pengakhiran'],
            'akun' => $r['user_id'] === null ? null : ['username' => (string) $r['username'], 'aktif' => (int) $r['user_aktif'] === 1],
            'diperbarui_pada' => (string) $r['updated_at'],
        ];
    }
}
