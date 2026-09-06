<?php

declare(strict_types=1);

namespace App\MasterData;

use App\Audit\AuditLogger;
use Throwable;

/**
 * Layanan kelulusan/mutasi santri dan pengelolaan arsip alumni
 * (paket "Koreksi Pengelolaan Alumni", keputusan pengguna 6 September 2026).
 *
 * SATU pintu masuk untuk seluruh perubahan alumni: individual maupun massal,
 * koreksi, arsip, pemulihan, dan pembatalan. Halaman admin tidak lagi menulis
 * query `alumni` sendiri.
 *
 * MASALAH YANG DIPERBAIKI
 * -----------------------
 * `admin/proses_mutasi_alumni.php` lama:
 *
 *   - memproses massal dengan `INSERT IGNORE` di dalam perulangan TANPA
 *     transaksi, sehingga kegagalan di tengah meninggalkan sebagian santri
 *     sudah diarsipkan dan sebagian belum;
 *   - `INSERT IGNORE` menelan pelanggaran keunikan diam-diam: santri
 *     diarsipkan dan kelasnya ditutup meskipun catatan alumninya TIDAK pernah
 *     tersimpan;
 *   - tidak menutup penempatan KAMAR sama sekali, sehingga tempat tidur alumni
 *     tetap terpakai;
 *   - tidak mencatat pelaku, waktu, kelas/kamar terakhir, maupun catatan;
 *   - `admin/admin_alumni.php` menghapus catatan alumni PERMANEN lewat GET
 *     (`?hapus=ID`) sekaligus menghapus berkas fotonya.
 *
 * ATURAN YANG DIPEGANG
 * --------------------
 *   1. Setiap operasi berjalan dalam SATU transaksi. Operasi massal bersifat
 *      atomik: seluruh santri berhasil, atau tidak satu pun berubah.
 *   2. Urutan penguncian selalu sama — baris santri (ID menaik), lalu baris
 *      alumni (ID menaik) — dan sama dengan urutan `PenempatanService`,
 *      sehingga kedua modul tidak saling mengunci.
 *   3. Duplikasi dicegah pada DUA lapis: pemeriksaan aplikasi di dalam
 *      transaksi terkunci, dan kunci unik basis data
 *      `alumni_santri_aktif_unique` / `alumni_nis_aktif_unique` (migrasi 011).
 *      Identitas yang dipakai adalah ID santri — BUKAN kesamaan nama santri,
 *      nama ayah, atau nama ibu.
 *   4. Santri sumber TIDAK PERNAH dihapus. Ia diarsipkan lewat pola status
 *      yang sudah dipakai proyek (`is_active = 0`, `archived_at` terisi).
 *   5. Penempatan kelas aktif ditutup (status `Selesai`); barisnya tetap ada
 *      sebagai riwayat. Penempatan kamar tahun berjalan dilepas dengan cara
 *      yang sama seperti `PenempatanService`, dan nilai sebelumnya disimpan
 *      pada snapshot `alumni.kamar_terakhir` serta pada audit.
 *   6. Relasi wali, akun wali, absensi, perizinan, konseling, penilaian, dan
 *      pembiayaan TIDAK disentuh sama sekali.
 *   7. Audit ditulis di dalam transaksi yang sama. Bila audit gagal, seluruh
 *      perubahan di-rollback.
 *   8. Tidak ada penghapusan permanen catatan alumni dan tidak ada penghapusan
 *      berkas foto.
 *   9. Pemulihan catatan alumni TIDAK otomatis mengaktifkan kembali santri.
 *      Untuk itu tersedia tindakan terpisah "batalkan kelulusan/mutasi" yang
 *      beralasan wajib, dan yang TIDAK membuat penempatan kelas/kamar baru.
 *
 * Layanan ini TIDAK melakukan otorisasi: halaman pemanggil sudah melewati
 * `admin/_guard.php` (peran admin + CSRF).
 */
final class AlumniService
{
    /** Status keluar yang sah. Sama persis dengan ENUM kolom `alumni.status_keluar`. */
    public const STATUS = ['Lulus', 'Pindah', 'Berhenti'];

    /** Tingkat terakhir yang sah. Sama persis dengan ENUM kolom `alumni.tingkat`. */
    public const TINGKAT = ['Ibtida', 'Tsanawi'];

    /** Batas satu operasi massal. Melindungi transaksi dari kunci yang terlalu lama. */
    public const BATAS_MASSAL = 200;

    /** 1213 = deadlock, 1205 = lock wait timeout. */
    public const KODE_KONFLIK_KUNCI = [1205, 1213];

    /** 1062 = pelanggaran kunci unik (alumni aktif ganda). */
    public const KODE_KUNCI_GANDA = 1062;

    /** 1665 = ER_BINLOG_STMT_MODE_AND_ROW_ENGINE (binlog_format = STATEMENT). */
    public const KODE_BINLOG_STATEMENT = 1665;

    public function __construct(
        private AlumniRepository $repository,
        private AuditLogger $audit
    ) {
    }

    // -----------------------------------------------------------------------
    // Pembacaan untuk tampilan
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function activeYear(): ?array
    {
        return $this->repository->activeYear();
    }

    /** @return array<int, array<string, mixed>> */
    public function classOptions(int $yearId): array
    {
        return $yearId > 0 ? $this->repository->classOptions($yearId) : [];
    }

    /** @return array<int, string> */
    public function yearOptions(): array
    {
        return $this->repository->yearOptions();
    }

    /** @return array{aktif:int, arsip:int, tanpa_santri:int} */
    public function summary(): array
    {
        return $this->repository->summary();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $filters): array
    {
        $status = is_scalar($filters['status'] ?? null) ? (string) $filters['status'] : '';
        $tingkat = is_scalar($filters['tingkat'] ?? null) ? (string) $filters['tingkat'] : '';
        $state = is_scalar($filters['state'] ?? null) ? (string) $filters['state'] : 'active';
        $tautan = is_scalar($filters['tautan'] ?? null) ? (string) $filters['tautan'] : '';

        return [
            'q' => mb_substr(Normalizer::text($filters['q'] ?? ''), 0, 100),
            'status' => in_array($status, self::STATUS, true) ? $status : '',
            'tahun' => mb_substr(Normalizer::text($filters['tahun'] ?? ''), 0, 10),
            'tingkat' => in_array($tingkat, self::TINGKAT, true) ? $tingkat : '',
            'state' => in_array($state, ['active', 'archived', 'all'], true) ? $state : 'active',
            'tautan' => in_array($tautan, ['tanpa_santri', 'dengan_santri'], true) ? $tautan : '',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, perPage:int}
     */
    public function listPage(array $filters, int $page, int $perPage = 20): array
    {
        return $this->repository->listPage($this->normalizeFilters($filters), max(1, $page), $perPage);
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? $this->repository->find($id) : null;
    }

    // -----------------------------------------------------------------------
    // Tinjauan (read-only)
    // -----------------------------------------------------------------------

    /**
     * Menyusun rencana kelulusan/mutasi TANPA mengubah apa pun.
     *
     * Hasilnya hanya untuk layar konfirmasi. `terapkan()` selalu menghitung
     * ulang seluruhnya di dalam transaksi dan tidak pernah percaya pada hasil
     * tinjauan ini.
     *
     * @param array<int, mixed> $santriIds
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function tinjau(array $santriIds, array $options): array
    {
        $ids = $this->normalizeIds($santriIds);
        $year = $this->requireYear();
        $santri = $this->repository->santriByIds($ids);

        return $this->rencana($ids, $santri, $year, $options, false);
    }

    /**
     * Daftar santri aktif pada satu kelas, untuk tinjauan massal.
     *
     * @return array<int, int>
     */
    public function santriAktifPadaKelas(int $kelasId, int $yearId): array
    {
        $kelas = $this->repository->classFind($kelasId);
        if ($kelas === null) {
            throw new MasterDataException('Kelas tidak ditemukan.');
        }

        return $this->repository->activeSantriIdsInClass($kelasId, $yearId);
    }

    /** @return array<string, mixed>|null */
    public function kelas(int $kelasId): ?array
    {
        return $kelasId > 0 ? $this->repository->classFind($kelasId) : null;
    }

    /**
     * Keterangan setiap calon santri SEBELUM formulir diisi: kelas aktif,
     * kamar aktif, dan apakah ia masih layak diproses.
     *
     * Dipakai layar pemilihan — individual maupun massal — supaya santri yang
     * sudah menjadi alumni dikecualikan SECARA TERBUKA, bukan diam-diam
     * menghasilkan catatan ganda saat penerapan. Hasil di sini tidak pernah
     * dipercaya oleh `terapkan()`, yang selalu memeriksa ulang di dalam
     * transaksi terkunci.
     *
     * @param array<int, mixed> $santriIds
     * @return array<int, array<string, mixed>> berurutan menurut nama santri
     */
    public function kandidat(array $santriIds, int $yearId): array
    {
        $ids = [];
        foreach ($santriIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            return [];
        }

        $santri = $this->repository->santriByIds($ids);
        $alumni = $this->repository->activeBySantri($ids);
        $nisList = [];
        foreach ($ids as $id) {
            $nisList[] = (string) ($santri[$id]['nis'] ?? '');
        }
        $alumniNis = $this->repository->activeByNis($nisList);
        $kelas = $this->repository->activeClass($ids, $yearId);
        $kamar = $this->repository->activeRooms($ids, $yearId);

        $hasil = [];
        foreach ($ids as $id) {
            $data = $santri[$id] ?? null;
            if ($data === null) {
                continue;
            }
            $nis = (string) $data['nis'];
            $halangan = null;
            $alumniId = null;
            if ((int) $data['is_active'] !== 1 || $data['archived_at'] !== null) {
                $halangan = 'Santri sudah nonaktif atau diarsipkan pada master data.';
            } elseif (isset($alumni[$id])) {
                $alumniId = (int) $alumni[$id]['id'];
                $halangan = 'Sudah tercatat sebagai alumni (' . (string) $alumni[$id]['status_keluar']
                    . ', ' . (string) $alumni[$id]['tgl_keluar'] . ').';
            } elseif (isset($alumniNis[$nis])) {
                $alumniId = (int) $alumniNis[$nis]['id'];
                $halangan = 'NIS ini sudah dipakai catatan alumni aktif atas nama '
                    . (string) $alumniNis[$nis]['nama_santri'] . '.';
            }

            $namaKamar = [];
            foreach ($kamar[$id] ?? [] as $row) {
                $namaKamar[] = (string) ($row['nama_kamar'] ?? ('Kamar #' . (int) $row['id_kamar']));
            }

            $hasil[] = [
                'santri_id' => $id,
                'nis' => $nis,
                'nama_santri' => (string) $data['nama_santri'],
                'jenis_kelamin' => (string) $data['jenis_kelamin'],
                'unit_terakhir' => (string) ($data['sekolah_saat_ini'] ?? ''),
                'kelas_aktif' => isset($kelas[$id]) ? (string) ($kelas[$id]['nama_kelas'] ?? '') : null,
                'kamar_aktif' => $namaKamar === [] ? null : implode(', ', $namaKamar),
                'layak' => $halangan === null,
                'halangan' => $halangan,
                'alumni_id' => $alumniId,
            ];
        }

        usort($hasil, static fn (array $a, array $b): int => strcasecmp($a['nama_santri'], $b['nama_santri']));

        return $hasil;
    }

    // -----------------------------------------------------------------------
    // Penerapan (transaksional)
    // -----------------------------------------------------------------------

    /**
     * Memproses kelulusan/mutasi dalam SATU transaksi.
     *
     * Langkahnya, berurutan dan seluruhnya di dalam transaksi:
     *   1. kunci baris santri (ID menaik);
     *   2. pastikan setiap santri masih aktif;
     *   3. kunci dan pastikan belum ada catatan alumni aktif untuk santri itu
     *      maupun untuk NIS-nya;
     *   4. simpan snapshot alumni;
     *   5. tutup penempatan kelas aktif;
     *   6. lepas penempatan kamar tahun berjalan;
     *   7. arsipkan santri;
     *   8. catat audit per santri dan ringkasan massal;
     *   9. commit hanya bila seluruh langkah berhasil.
     *
     * @param array<int, mixed> $santriIds
     * @param array<string, mixed> $options
     * @return array<string, mixed> ringkasan hasil
     */
    public function terapkan(array $santriIds, array $options, int $actorId): array
    {
        $ids = $this->normalizeIds($santriIds);

        $db = $this->repository->db();
        // READ COMMITTED wajib, dengan alasan yang sama seperti
        // `PenempatanService::apply()`: setelah menunggu kunci baris santri,
        // pembacaan berikutnya harus melihat keadaan TERKINI — kalau tidak,
        // dua permintaan bersamaan dapat sama-sama menyimpulkan "belum ada
        // alumni" dari snapshot lama.
        //
        // Nilai balik kedua pernyataan di bawah DIPERIKSA: dengan
        // `mysqli_report(MYSQLI_REPORT_OFF)` keduanya hanya mengembalikan false
        // saat gagal, dan membiarkannya lolos berarti berjalan tanpa transaksi.
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
            error_log('Alumni: isolasi READ COMMITTED gagal disetel (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Proses dibatalkan: basis data tidak dapat menyiapkan transaksi yang aman. Tidak ada perubahan yang tersimpan.');
        }
        if ($db->begin_transaction() === false) {
            error_log('Alumni: begin_transaction gagal (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Proses dibatalkan: transaksi basis data tidak dapat dimulai. Tidak ada perubahan yang tersimpan.');
        }

        try {
            // Tahun ajaran dibaca ULANG di dalam transaksi: semester yang
            // berganti di tengah operasi tidak boleh menutup kelas tahun salah.
            $year = $this->requireYear();

            // 1. Kunci baris santri lebih dulu (ID menaik).
            $santri = $this->repository->lockSantri($ids);

            // 2 & 3. Rencana disusun ulang dari keadaan TERKUNCI.
            $rencana = $this->rencana($ids, $santri, $year, $options, true);
            if ($rencana['masalah'] !== []) {
                throw new MasterDataException(implode(' ', $rencana['masalah']), $rencana['masalah']);
            }

            $yearId = (int) $year['id'];
            $hasil = [];
            foreach ($rencana['baris'] as $baris) {
                $santriId = (int) $baris['santri_id'];
                $sumber = $santri[$santriId];

                // 4. Snapshot alumni.
                $alumniId = $this->repository->createAlumni($sumber, [
                    'unit_terakhir' => $baris['unit_terakhir'],
                    'kelas_terakhir' => $baris['kelas_terakhir'],
                    'kamar_terakhir' => $baris['kamar_terakhir'],
                    'tahun_angkatan' => $rencana['tahun_angkatan'],
                    'tingkat' => $rencana['tingkat'],
                    'status_keluar' => $rencana['status_keluar'],
                    'tgl_keluar' => $rencana['tgl_keluar'],
                    'catatan' => $rencana['catatan'] === '' ? null : $rencana['catatan'],
                ], $actorId);

                // 5. Tutup penempatan kelas aktif (baris tetap sebagai riwayat).
                if ($baris['kelas_id'] !== null) {
                    $this->repository->endActiveClass($santriId, $yearId, $rencana['tgl_keluar']);
                }

                // 6. Lepas penempatan kamar tahun berjalan.
                foreach ($baris['kamar_penempatan_id'] as $penempatanId) {
                    $this->repository->releaseRoom((int) $penempatanId, $yearId);
                }

                // 7. Arsipkan santri. Baris santri TIDAK dihapus.
                $this->repository->setSantriState($santriId, false, true);

                // 8. Audit per santri.
                $this->auditBaris($rencana, $baris, $sumber, $year, $alumniId, $actorId);

                $hasil[] = ['santri_id' => $santriId, 'alumni_id' => $alumniId, 'nama_santri' => $baris['nama_santri']];
            }

            $this->auditRingkasan($rencana, $year, $actorId, $hasil);

            $db->commit();

            return [
                'mode' => $rencana['mode'],
                'jumlah' => count($hasil),
                'status_keluar' => $rencana['status_keluar'],
                'tgl_keluar' => $rencana['tgl_keluar'],
                'baris' => $hasil,
                'alumni_id' => $hasil[0]['alumni_id'] ?? null,
            ];
        } catch (Throwable $exception) {
            // errno dibaca SEBELUM rollback: rollback dapat menimpanya.
            $errno = $db->errno;
            $db->rollback();

            throw $this->translateFailure($exception, $errno);
        }
    }

    // -----------------------------------------------------------------------
    // Koreksi, arsip, pemulihan, pembatalan
    // -----------------------------------------------------------------------

    /**
     * Mengoreksi isi catatan alumni. Identitas santri, NIS, dan foto TIDAK
     * ikut berubah: yang boleh dikoreksi hanya keterangan keluarnya.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> baris alumni setelah koreksi
     */
    public function koreksi(int $alumniId, array $input, int $actorId): array
    {
        return $this->transaksi(function () use ($alumniId, $input, $actorId): array {
            $sebelum = $this->mustLock($alumniId);
            if ($sebelum['archived_at'] !== null) {
                throw new MasterDataException('Catatan alumni yang sudah diarsipkan tidak dapat dikoreksi. Pulihkan lebih dahulu bila memang perlu diubah.');
            }

            $data = [
                'status_keluar' => $this->status($input['status_keluar'] ?? ''),
                'tgl_keluar' => $this->tanggal($input['tgl_keluar'] ?? ''),
                'tahun_angkatan' => $this->tahunAngkatan($input['tahun_angkatan'] ?? ''),
                'tingkat' => $this->tingkat($input['tingkat'] ?? ''),
                'unit_terakhir' => $this->teksWajib($input['unit_terakhir'] ?? '', 50, 'Unit atau sekolah terakhir'),
                'kelas_terakhir' => $this->teksOpsional($input['kelas_terakhir'] ?? '', 50),
                'kamar_terakhir' => $this->teksOpsional($input['kamar_terakhir'] ?? '', 50),
                'catatan' => $this->teksOpsional($input['catatan'] ?? '', 500),
            ];

            $this->repository->updateAlumni($alumniId, $data, $actorId);
            $sesudah = $this->repository->find($alumniId);
            $this->wajibTercatat('alumni.koreksi', $alumniId, $this->ringkas($sebelum), $this->ringkas($sesudah ?? []) + ['alasan' => $data['catatan']], $actorId);

            return $sesudah ?? [];
        });
    }

    /**
     * Mengarsipkan catatan alumni — pengganti penghapusan permanen.
     *
     * TIDAK menghapus baris, TIDAK menghapus berkas foto, dan TIDAK mengubah
     * status santri sumber.
     */
    public function arsipkan(int $alumniId, string $alasan, int $actorId): void
    {
        $alasan = $this->alasanWajib($alasan, 'mengarsipkan catatan alumni');
        $this->transaksi(function () use ($alumniId, $alasan, $actorId): bool {
            $sebelum = $this->mustLock($alumniId);
            if ($sebelum['archived_at'] !== null) {
                throw new MasterDataException('Catatan alumni ini sudah berstatus arsip.');
            }
            $this->repository->archive($alumniId, 'arsip', $alasan, $actorId);
            $this->wajibTercatat(
                'alumni.arsip',
                $alumniId,
                $this->ringkas($sebelum),
                $this->ringkas($this->repository->find($alumniId) ?? []) + ['alasan' => $alasan],
                $actorId
            );

            return true;
        });
    }

    /**
     * Memulihkan catatan alumni yang diarsipkan.
     *
     * SENGAJA tidak menyentuh santri, kelas, dan kamar: memulihkan catatan
     * arsip hanya berarti "catatan ini kembali dianggap sah", bukan "santrinya
     * kembali menjadi santri aktif". Untuk itu ada `batalkan()`.
     */
    public function pulihkan(int $alumniId, string $alasan, int $actorId): void
    {
        $alasan = $this->alasanWajib($alasan, 'memulihkan catatan alumni');
        $this->transaksi(function () use ($alumniId, $alasan, $actorId): bool {
            $sebelum = $this->mustLock($alumniId);
            if ($sebelum['archived_at'] === null) {
                throw new MasterDataException('Catatan alumni ini tidak sedang diarsipkan.');
            }
            $santriId = $sebelum['santri_id'] === null ? null : (int) $sebelum['santri_id'];
            if ($santriId !== null) {
                $bentrok = $this->repository->lockActiveBySantri([$santriId]);
                if (isset($bentrok[$santriId])) {
                    throw new MasterDataException(
                        'Catatan ini tidak dapat dipulihkan karena santri tersebut sudah memiliki catatan alumni aktif lain (ID #'
                        . (int) $bentrok[$santriId]['id'] . '). Arsipkan catatan itu lebih dahulu bila catatan ini yang benar.'
                    );
                }
            }
            $bentrokNis = $this->repository->lockActiveByNis([(string) $sebelum['nis']]);
            if (isset($bentrokNis[(string) $sebelum['nis']])) {
                throw new MasterDataException(
                    'Catatan ini tidak dapat dipulihkan karena NIS ' . (string) $sebelum['nis']
                    . ' sudah dipakai catatan alumni aktif lain (ID #' . (int) $bentrokNis[(string) $sebelum['nis']]['id'] . ').'
                );
            }

            $this->repository->restore($alumniId, $alasan, $actorId);
            $this->wajibTercatat(
                'alumni.pulihkan',
                $alumniId,
                $this->ringkas($sebelum),
                $this->ringkas($this->repository->find($alumniId) ?? []) + ['alasan' => $alasan],
                $actorId
            );

            return true;
        });
    }

    /**
     * Membatalkan kelulusan/mutasi: catatan alumni diarsipkan DAN santri
     * sumber diaktifkan kembali.
     *
     * Yang SENGAJA tidak dilakukan:
     *   - tidak membuat penempatan kelas baru;
     *   - tidak membuat penempatan kamar baru;
     *   - tidak menyentuh relasi wali, akun, absensi, atau perizinan.
     *
     * Penempatan baru adalah keputusan admin dan dikerjakan lewat halaman
     * Penempatan Kelas & Kamar.
     *
     * @return array<string, mixed> ringkasan untuk pesan hasil
     */
    public function batalkan(int $alumniId, string $alasan, int $actorId): array
    {
        $alasan = $this->alasanWajib($alasan, 'membatalkan kelulusan atau mutasi');

        return $this->transaksi(function () use ($alumniId, $alasan, $actorId): array {
            $sebelum = $this->mustLock($alumniId);
            if ($sebelum['archived_at'] !== null) {
                throw new MasterDataException('Catatan alumni ini sudah diarsipkan sehingga tidak ada kelulusan aktif yang dapat dibatalkan.');
            }
            if ($sebelum['santri_id'] === null) {
                throw new MasterDataException(
                    'Catatan alumni ini belum terhubung ke santri sumber, sehingga tidak ada santri yang dapat diaktifkan kembali. '
                    . 'Hubungkan catatan ini ke santri yang benar lebih dahulu, atau cukup arsipkan catatannya.'
                );
            }
            $santriId = (int) $sebelum['santri_id'];
            $santri = $this->repository->lockSantri([$santriId])[$santriId] ?? null;
            if ($santri === null) {
                throw new MasterDataException('Santri sumber tidak ditemukan. Tidak ada perubahan yang tersimpan.');
            }
            if ((int) $santri['is_active'] === 1 && $santri['archived_at'] === null) {
                throw new MasterDataException(
                    'Santri ' . (string) $santri['nama_santri'] . ' sudah berstatus aktif pada master data. '
                    . 'Bila catatan alumninya keliru, gunakan tindakan Arsipkan.'
                );
            }

            $this->repository->archive($alumniId, 'pembatalan', $alasan, $actorId);
            $this->repository->setSantriState($santriId, true, false);

            $sesudahSantri = $this->repository->santriFind($santriId) ?? [];
            $this->wajibTercatat(
                'alumni.batalkan',
                $alumniId,
                $this->ringkas($sebelum),
                $this->ringkas($this->repository->find($alumniId) ?? []) + [
                    'alasan' => $alasan,
                    'santri_id' => $santriId,
                    'santri_diaktifkan_kembali' => true,
                ],
                $actorId
            );
            $this->wajibTercatat(
                'alumni.batalkan.santri',
                $santriId,
                ['is_active' => (int) $santri['is_active'], 'archived_at' => $santri['archived_at']],
                [
                    'is_active' => (int) ($sesudahSantri['is_active'] ?? 1),
                    'archived_at' => $sesudahSantri['archived_at'] ?? null,
                    'alumni_id' => $alumniId,
                    'alasan' => $alasan,
                    'catatan' => 'Penempatan kelas dan kamar TIDAK dibuat otomatis.',
                ],
                $actorId,
                'santri'
            );

            return [
                'santri_id' => $santriId,
                'nama_santri' => (string) $santri['nama_santri'],
                'alumni_id' => $alumniId,
            ];
        });
    }

    /**
     * Menghubungkan catatan alumni warisan ke santri sumbernya.
     *
     * Hanya untuk data lama yang `santri_id`-nya masih kosong. Sistem TIDAK
     * PERNAH menebak pasangan ini sendiri dari kesamaan nama.
     */
    public function hubungkanSantri(int $alumniId, int $santriId, int $actorId): void
    {
        $this->transaksi(function () use ($alumniId, $santriId, $actorId): bool {
            $sebelum = $this->mustLock($alumniId);
            if ($sebelum['santri_id'] !== null) {
                throw new MasterDataException('Catatan alumni ini sudah terhubung ke santri #' . (int) $sebelum['santri_id'] . '.');
            }
            $santri = $this->repository->lockSantri([$santriId])[$santriId] ?? null;
            if ($santri === null) {
                throw new MasterDataException('Santri tujuan tidak ditemukan.');
            }
            if ($sebelum['archived_at'] === null) {
                $bentrok = $this->repository->lockActiveBySantri([$santriId]);
                if (isset($bentrok[$santriId])) {
                    throw new MasterDataException(
                        'Santri itu sudah memiliki catatan alumni aktif (ID #' . (int) $bentrok[$santriId]['id'] . '). '
                        . 'Satu santri hanya boleh memiliki satu catatan alumni aktif.'
                    );
                }
            }

            $this->repository->attachSantri($alumniId, $santriId, $actorId);
            $this->wajibTercatat(
                'alumni.hubungkan',
                $alumniId,
                $this->ringkas($sebelum),
                $this->ringkas($this->repository->find($alumniId) ?? []) + [
                    'santri_id' => $santriId,
                    'nis_santri' => (string) $santri['nis'],
                    'nama_santri_terkini' => (string) $santri['nama_santri'],
                ],
                $actorId
            );

            return true;
        });
    }

    // -----------------------------------------------------------------------
    // Penyusunan rencana
    // -----------------------------------------------------------------------

    /**
     * @param array<int, int> $ids
     * @param array<int, array<string, mixed>> $santri
     * @param array<string, mixed> $year
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function rencana(array $ids, array $santri, array $year, array $options, bool $terkunci): array
    {
        $yearId = (int) $year['id'];
        $masalah = [];

        // Nilai formulir divalidasi lebih dahulu supaya layar tinjauan
        // memperlihatkan persis apa yang akan tersimpan.
        $statusKeluar = $this->status($options['status_keluar'] ?? '');
        $tglKeluar = $this->tanggal($options['tgl_keluar'] ?? '');
        $tingkat = $this->tingkat($options['tingkat'] ?? '');
        $tahunAngkatan = $this->tahunAngkatan($options['tahun_angkatan'] ?? ($year['tahun'] ?? ''));
        $catatan = $this->teksOpsional($options['catatan'] ?? '', 500) ?? '';

        // Pada tahap tinjauan pembacaan dilakukan TANPA kunci: layar konfirmasi
        // tidak boleh memegang kunci baris sambil menunggu admin membaca.
        // Penerapan membaca ulang dengan `FOR UPDATE` sehingga keputusannya
        // tidak pernah bergantung pada hasil tinjauan ini.
        $alumniAktif = $terkunci
            ? $this->repository->lockActiveBySantri($ids)
            : $this->repository->activeBySantri($ids);

        $nisList = [];
        foreach ($ids as $id) {
            $nisList[] = (string) ($santri[$id]['nis'] ?? '');
        }
        $alumniNis = $terkunci
            ? $this->repository->lockActiveByNis($nisList)
            : $this->repository->activeByNis($nisList);

        $kelas = $this->repository->activeClass($ids, $yearId);
        $kamar = $this->repository->activeRooms($ids, $yearId);

        $baris = [];
        foreach ($ids as $id) {
            $data = $santri[$id] ?? null;
            if ($data === null) {
                $masalah[] = 'Santri dengan ID ' . $id . ' tidak ditemukan.';
                continue;
            }
            $nama = (string) $data['nama_santri'];
            if ((int) $data['is_active'] !== 1 || $data['archived_at'] !== null) {
                $masalah[] = 'Santri ' . $nama . ' tidak aktif atau sudah diarsipkan sehingga tidak dapat diproses lagi.';
                continue;
            }
            if (isset($alumniAktif[$id])) {
                $masalah[] = 'Santri ' . $nama . ' sudah tercatat sebagai alumni (catatan #'
                    . (int) $alumniAktif[$id]['id'] . ', ' . (string) $alumniAktif[$id]['status_keluar']
                    . '). Tidak diproses ulang dan tidak ada catatan ganda yang dibuat.';
                continue;
            }
            $nis = (string) $data['nis'];
            if (isset($alumniNis[$nis]) && (int) ($alumniNis[$nis]['santri_id'] ?? 0) !== $id) {
                $masalah[] = 'NIS ' . $nis . ' milik ' . $nama . ' sudah dipakai catatan alumni aktif #'
                    . (int) $alumniNis[$nis]['id'] . ' atas nama ' . (string) $alumniNis[$nis]['nama_santri']
                    . '. Periksa dan koreksi catatan itu lebih dahulu.';
                continue;
            }

            $kamarBaris = $kamar[$id] ?? [];
            $kamarNama = [];
            $kamarIds = [];
            foreach ($kamarBaris as $row) {
                $kamarNama[] = (string) ($row['nama_kamar'] ?? ('Kamar #' . (int) $row['id_kamar']));
                $kamarIds[] = (int) $row['id'];
            }

            $baris[] = [
                'santri_id' => $id,
                'nis' => $nis,
                'nama_santri' => $nama,
                'jenis_kelamin' => (string) $data['jenis_kelamin'],
                'unit_terakhir' => mb_substr(Normalizer::text($data['sekolah_saat_ini'] ?? ''), 0, 50),
                'kelas_id' => isset($kelas[$id]) ? (int) $kelas[$id]['id_kelas'] : null,
                'kelas_terakhir' => isset($kelas[$id]) ? mb_substr((string) ($kelas[$id]['nama_kelas'] ?? ''), 0, 50) : null,
                'kamar_terakhir' => $kamarNama === [] ? null : mb_substr(implode(', ', $kamarNama), 0, 50),
                'kamar_penempatan_id' => $kamarIds,
            ];
        }

        if ($baris === [] && $masalah === []) {
            $masalah[] = 'Tidak ada santri yang dapat diproses.';
        }

        return [
            'jumlah' => count($ids),
            'mode' => count($ids) > 1 ? 'massal' : 'individu',
            'tahun' => $year,
            'baris' => $baris,
            'masalah' => $masalah,
            'status_keluar' => $statusKeluar,
            'tgl_keluar' => $tglKeluar,
            'tingkat' => $tingkat,
            'tahun_angkatan' => $tahunAngkatan,
            'catatan' => $catatan,
        ];
    }

    // -----------------------------------------------------------------------
    // Audit (di dalam transaksi yang sama; gagal audit = rollback)
    // -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $rencana
     * @param array<string, mixed> $baris
     * @param array<string, mixed> $sumber
     * @param array<string, mixed> $year
     */
    private function auditBaris(array $rencana, array $baris, array $sumber, array $year, int $alumniId, int $actorId): void
    {
        $this->wajibTercatat(
            'alumni.proses',
            $alumniId,
            [
                'santri_id' => (int) $baris['santri_id'],
                'nis' => $baris['nis'],
                'nama_santri' => $baris['nama_santri'],
                'santri_is_active' => (int) $sumber['is_active'],
                'santri_archived_at' => $sumber['archived_at'],
                'kelas_terakhir' => $baris['kelas_terakhir'],
                'kamar_terakhir' => $baris['kamar_terakhir'],
            ],
            [
                'alumni_id' => $alumniId,
                'santri_id' => (int) $baris['santri_id'],
                'nis' => $baris['nis'],
                'nama_santri' => $baris['nama_santri'],
                'status_keluar' => $rencana['status_keluar'],
                'tgl_keluar' => $rencana['tgl_keluar'],
                'tahun_angkatan' => $rencana['tahun_angkatan'],
                'tingkat' => $rencana['tingkat'],
                'unit_terakhir' => $baris['unit_terakhir'],
                'kelas_terakhir' => $baris['kelas_terakhir'],
                'kamar_terakhir' => $baris['kamar_terakhir'],
                'kelas_ditutup' => $baris['kelas_id'] !== null,
                'kamar_dilepas' => count($baris['kamar_penempatan_id']),
                'santri_diarsipkan' => true,
                'tahun_ajaran_id' => (int) $year['id'],
                'tahun_ajaran' => trim(($year['tahun'] ?? '') . ' ' . ($year['semester'] ?? '')),
                'mode' => $rencana['mode'],
                'jumlah_santri' => $rencana['jumlah'],
                'alasan' => $rencana['catatan'] === '' ? null : $rencana['catatan'],
            ],
            $actorId
        );
    }

    /**
     * @param array<string, mixed> $rencana
     * @param array<string, mixed> $year
     * @param array<int, array<string, mixed>> $hasil
     */
    private function auditRingkasan(array $rencana, array $year, int $actorId, array $hasil): void
    {
        if ($rencana['mode'] !== 'massal' || $hasil === []) {
            return;
        }
        $this->wajibTercatat(
            'alumni.massal',
            null,
            null,
            [
                'status_keluar' => $rencana['status_keluar'],
                'tgl_keluar' => $rencana['tgl_keluar'],
                'tahun_angkatan' => $rencana['tahun_angkatan'],
                'tingkat' => $rencana['tingkat'],
                'tahun_ajaran_id' => (int) $year['id'],
                'tahun_ajaran' => trim(($year['tahun'] ?? '') . ' ' . ($year['semester'] ?? '')),
                'jumlah_santri' => count($hasil),
                'santri_id' => array_map(static fn (array $b): int => (int) $b['santri_id'], $hasil),
                'alumni_id' => array_map(static fn (array $b): int => (int) $b['alumni_id'], $hasil),
                'alasan' => $rencana['catatan'] === '' ? null : $rencana['catatan'],
            ],
            $actorId
        );
    }

    /**
     * @param array<string, mixed>|null $sebelum
     * @param array<string, mixed>|null $sesudah
     */
    private function wajibTercatat(string $aksi, ?int $entityId, ?array $sebelum, ?array $sesudah, int $actorId, string $entity = 'alumni'): void
    {
        if (!$this->audit->log($aksi, $entity, $entityId, $sebelum, $sesudah, $actorId)) {
            throw new MasterDataException('Proses dibatalkan karena catatan audit tidak dapat disimpan. Tidak ada perubahan yang tersimpan.');
        }
    }

    /**
     * Ringkasan baris alumni untuk audit. Menyalin seluruh baris apa adanya
     * akan menumpuk data pribadi berulang-ulang di `audit_logs`; yang disimpan
     * hanya kolom yang benar-benar menjelaskan perubahan.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function ringkas(array $row): array
    {
        return [
            'alumni_id' => isset($row['id']) ? (int) $row['id'] : null,
            'santri_id' => isset($row['santri_id']) && $row['santri_id'] !== null ? (int) $row['santri_id'] : null,
            'nis' => $row['nis'] ?? null,
            'nama_santri' => $row['nama_santri'] ?? null,
            'status_keluar' => $row['status_keluar'] ?? null,
            'tgl_keluar' => $row['tgl_keluar'] ?? null,
            'tahun_angkatan' => $row['tahun_angkatan'] ?? null,
            'tingkat' => $row['tingkat'] ?? null,
            'unit_terakhir' => $row['unit_terakhir'] ?? null,
            'kelas_terakhir' => $row['kelas_terakhir'] ?? null,
            'kamar_terakhir' => $row['kamar_terakhir'] ?? null,
            'catatan' => $row['catatan'] ?? null,
            'archived_at' => $row['archived_at'] ?? null,
            'jenis_arsip' => $row['jenis_arsip'] ?? null,
            'alasan_arsip' => $row['alasan_arsip'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------
    // Transaksi dan penerjemahan galat
    // -----------------------------------------------------------------------

    /**
     * Menjalankan satu tindakan koreksi/arsip/pemulihan/pembatalan di dalam
     * transaksi tunggal. Audit ikut di dalamnya: audit gagal = rollback.
     *
     * @template T
     * @param callable():T $aksi
     * @return T
     */
    private function transaksi(callable $aksi): mixed
    {
        $db = $this->repository->db();
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
            error_log('Alumni: isolasi READ COMMITTED gagal disetel (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Proses dibatalkan: basis data tidak dapat menyiapkan transaksi yang aman. Tidak ada perubahan yang tersimpan.');
        }
        if ($db->begin_transaction() === false) {
            error_log('Alumni: begin_transaction gagal (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Proses dibatalkan: transaksi basis data tidak dapat dimulai. Tidak ada perubahan yang tersimpan.');
        }
        try {
            $hasil = $aksi();
            $db->commit();

            return $hasil;
        } catch (Throwable $exception) {
            $errno = $db->errno;
            $db->rollback();

            throw $this->translateFailure($exception, $errno);
        }
    }

    /**
     * Menerjemahkan kegagalan basis data menjadi pesan yang dapat dimengerti
     * admin. Pesan MySQL mentah tidak pernah sampai ke layar karena dapat
     * memuat nilai kolom.
     */
    private function translateFailure(Throwable $exception, int $errno): Throwable
    {
        if ($exception instanceof MasterDataException || $exception instanceof AlumniConflictException) {
            return $exception;
        }
        if (in_array($errno, self::KODE_KONFLIK_KUNCI, true)) {
            return new AlumniConflictException(
                'Proses alumni dibatalkan karena ada perubahan bersamaan pada santri yang sama. '
                . 'Tidak ada satu pun perubahan yang tersimpan. Muat ulang halaman lalu coba lagi.'
            );
        }
        if ($errno === self::KODE_KUNCI_GANDA) {
            return new AlumniConflictException(
                'Proses alumni dibatalkan karena santri itu baru saja diproses oleh permintaan lain. '
                . 'Tidak ada catatan alumni ganda yang dibuat. Muat ulang halaman lalu periksa keadaannya.'
            );
        }
        if ($errno === self::KODE_BINLOG_STATEMENT) {
            error_log('Alumni gagal: binlog_format=STATEMENT tidak mendukung transaksi READ COMMITTED.');

            return new MasterDataException(
                'Proses dibatalkan karena konfigurasi basis data server. '
                . 'Setelan binlog_format harus ROW atau MIXED agar proses alumni dapat berjalan dengan aman. '
                . 'Hubungi pengelola server; tidak ada perubahan yang tersimpan.'
            );
        }

        return $exception;
    }

    // -----------------------------------------------------------------------
    // Validasi masukan (seluruhnya di server; nilai dropdown tidak dipercaya)
    // -----------------------------------------------------------------------

    /**
     * @param array<int, mixed> $santriIds
     * @return array<int, int> ID unik, positif, terurut menaik
     */
    private function normalizeIds(array $santriIds): array
    {
        $ids = [];
        foreach ($santriIds as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) {
                throw new MasterDataException('Daftar santri memuat ID yang tidak valid. Muat ulang halaman lalu pilih ulang santri.');
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        if ($ids === []) {
            throw new MasterDataException('Pilih minimal satu santri terlebih dahulu.');
        }
        if (count($ids) > self::BATAS_MASSAL) {
            throw new MasterDataException('Satu proses massal dibatasi ' . self::BATAS_MASSAL . ' santri. Bagi menjadi beberapa tahap.');
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function requireYear(): array
    {
        $year = $this->repository->activeYear();
        if ($year === null) {
            throw new MasterDataException('Belum ada tahun ajaran aktif. Aktifkan satu semester pada halaman Tahun Ajaran terlebih dahulu.');
        }

        return $year;
    }

    private function status(mixed $value): string
    {
        $value = Normalizer::text($value);
        if (!in_array($value, self::STATUS, true)) {
            throw new MasterDataException('Status keluar harus salah satu dari: ' . implode(', ', self::STATUS) . '.');
        }

        return $value;
    }

    private function tingkat(mixed $value): string
    {
        $value = Normalizer::text($value);
        if (!in_array($value, self::TINGKAT, true)) {
            throw new MasterDataException('Tingkat terakhir harus salah satu dari: ' . implode(', ', self::TINGKAT) . '.');
        }

        return $value;
    }

    private function tanggal(mixed $value): string
    {
        $tanggal = Normalizer::date($value, true);
        if ($tanggal === '' || $tanggal === null) {
            throw new MasterDataException('Tanggal keluar harus berformat YYYY-MM-DD dan merupakan tanggal yang benar.');
        }
        if ($tanggal > date('Y-m-d', strtotime('+1 year'))) {
            throw new MasterDataException('Tanggal keluar terlalu jauh di masa depan. Periksa kembali tanggalnya.');
        }

        return $tanggal;
    }

    private function tahunAngkatan(mixed $value): string
    {
        $tahun = Normalizer::text($value);
        if ($tahun === '') {
            throw new MasterDataException('Tahun angkatan atau tahun keluar wajib diisi.');
        }
        if (!preg_match('/^[0-9]{4}(\/[0-9]{4})?$/', $tahun)) {
            throw new MasterDataException('Tahun angkatan harus berupa "2026" atau "2025/2026".');
        }

        return $tahun;
    }

    private function teksWajib(mixed $value, int $maksimum, string $label): string
    {
        $teks = mb_substr(Normalizer::text($value), 0, $maksimum);
        if ($teks === '') {
            throw new MasterDataException($label . ' wajib diisi.');
        }

        return $teks;
    }

    private function teksOpsional(mixed $value, int $maksimum): ?string
    {
        $teks = mb_substr(Normalizer::text($value), 0, $maksimum);

        return $teks === '' ? null : $teks;
    }

    private function alasanWajib(mixed $value, string $tindakan): string
    {
        $alasan = mb_substr(Normalizer::text($value), 0, 500);
        if (mb_strlen($alasan) < 5) {
            throw new MasterDataException('Alasan wajib diisi (minimal 5 karakter) ketika ' . $tindakan . '. Alasan ikut tercatat pada audit.');
        }

        return $alasan;
    }

    /** @return array<string, mixed> */
    private function mustLock(int $alumniId): array
    {
        if ($alumniId < 1) {
            throw new MasterDataException('ID catatan alumni tidak valid.');
        }
        $row = $this->repository->lockAlumni($alumniId);
        if ($row === null) {
            throw new MasterDataException('Catatan alumni tidak ditemukan.');
        }

        return $row;
    }
}
