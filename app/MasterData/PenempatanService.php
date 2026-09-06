<?php

declare(strict_types=1);

namespace App\MasterData;

use App\Audit\AuditLogger;
use Throwable;

/**
 * Layanan penempatan kelas dan kamar santri (keputusan pengguna 6 September 2026).
 *
 * Satu pintu masuk untuk seluruh perubahan penempatan yang berasal dari halaman
 * Penempatan Kelas & Kamar, individual maupun massal.
 *
 * CATATAN JUJUR: `admin/admin_kelas.php` masih memiliki formulir "Tempatkan
 * Santri pada Semester Aktif" lamanya yang menulis lewat
 * `MasterDataService::assignActiveClass()`. Formulir itu sengaja TIDAK dihapus
 * (fitur lama tidak boleh hilang), tetapi ia tidak mengunci baris santri dan
 * tidak meminta alasan. Bila dua jalur itu berjalan bersamaan, konflik kunci
 * yang muncul diterjemahkan menjadi pesan "coba lagi" oleh `translateFailure()`,
 * bukan galat mentah.
 *
 * Aturan yang dipegang:
 *
 *   1. Setiap operasi berjalan dalam SATU transaksi. Operasi massal bersifat
 *      atomik: seluruh santri berhasil, atau tidak satu pun berubah.
 *   2. Urutan penguncian selalu sama — baris santri (ID menaik) lalu baris
 *      kamar (ID menaik) — sehingga dua permintaan massal tidak saling
 *      mengunci. Bila tetap terjadi konflik, seluruh operasi dibatalkan dan
 *      admin diminta mencoba lagi.
 *   3. Kapasitas kamar dihitung ULANG di dalam transaksi setelah baris kamar
 *      dikunci. Santri yang sudah berada di kamar tujuan tidak dihitung sebagai
 *      tambahan.
 *   4. Penempatan ke kamar yang sama bersifat idempoten: tidak ada baris baru,
 *      tidak ada audit palsu.
 *   5. Perpindahan kamar MEMPERBARUI baris yang ada (ID dipertahankan), bukan
 *      menghapus lalu menyisipkan.
 *   6. Audit ditulis di dalam transaksi yang sama. Bila audit gagal, perubahan
 *      penempatan ikut di-rollback.
 *   7. Penempatan tahun ajaran sebelumnya tidak pernah disentuh.
 *   8. Konflik data warisan (santri dengan lebih dari satu kamar pada tahun
 *      yang sama) TIDAK dibersihkan otomatis: operasi ditolak dan admin
 *      diarahkan ke `bin/penempatan_preflight.php`.
 *
 * Riwayat kelas tetap memakai jalur terpusat `MasterDataRepository`
 * (`membershipAssign` / `membershipEnd`) sehingga model riwayat V1 — satu
 * penempatan aktif per tahun ajaran, penempatan lama diselesaikan dengan
 * tanggal selesai — tidak berubah sama sekali.
 *
 * Layanan ini TIDAK melakukan otorisasi: halaman pemanggil sudah melewati
 * `admin/_guard.php` (peran admin + CSRF).
 */
final class PenempatanService
{
    public const AKSI_KELAS_TETAPKAN = 'tempatkan_kelas';
    public const AKSI_KELAS_KELUARKAN = 'keluarkan_kelas';
    public const AKSI_KAMAR_TETAPKAN = 'tempatkan_kamar';
    public const AKSI_KAMAR_KELUARKAN = 'keluarkan_kamar';

    public const AKSI = [
        self::AKSI_KELAS_TETAPKAN,
        self::AKSI_KELAS_KELUARKAN,
        self::AKSI_KAMAR_TETAPKAN,
        self::AKSI_KAMAR_KELUARKAN,
    ];

    /** Batas satu operasi massal. Melindungi transaksi dari kunci yang terlalu lama. */
    public const BATAS_MASSAL = 200;

    /** 1213 = deadlock, 1205 = lock wait timeout. */
    public const KODE_KONFLIK_KUNCI = [1205, 1213];

    /** 1062 = pelanggaran kunci unik (mis. dua penempatan kelas aktif bersamaan). */
    public const KODE_KUNCI_GANDA = 1062;

    /**
     * 1665 = ER_BINLOG_STMT_MODE_AND_ROW_ENGINE.
     *
     * Muncul bila server memakai `binlog_format = STATEMENT`: MariaDB menolak
     * menulis ke tabel InnoDB di dalam transaksi READ COMMITTED. Kondisi ini
     * diperiksa `bin/penempatan_preflight.php` sebelum rilis.
     */
    public const KODE_BINLOG_STATEMENT = 1665;

    public function __construct(
        private PenempatanRepository $repository,
        private MasterDataRepository $master,
        private AuditLogger $audit
    ) {
    }

    // -----------------------------------------------------------------------
    // Pembacaan untuk tampilan
    // -----------------------------------------------------------------------

    public function activeYear(): ?array
    {
        return $this->repository->activeYear();
    }

    public function classOptions(): array
    {
        return $this->repository->classOptions();
    }

    public function roomOptions(int $yearId): array
    {
        return $this->repository->roomOptions($yearId);
    }

    public function schoolOptions(): array
    {
        return $this->repository->schoolOptions();
    }

    public function summary(int $yearId): array
    {
        return $this->repository->countWithoutPlacement($yearId);
    }

    /**
     * Daftar santri dengan kelas dan kamar aktifnya (filter + pagination server).
     *
     * @param array<string, mixed> $filters
     */
    public function listPage(array $filters, int $yearId, int $page, int $perPage = 20): array
    {
        return $this->repository->listPage($this->normalizeFilters($filters), $yearId, max(1, $page), $perPage);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function normalizeFilters(array $filters): array
    {
        return [
            'q' => mb_substr(Normalizer::text($filters['q'] ?? ''), 0, 100),
            'jk' => in_array($filters['jk'] ?? '', ['L', 'P'], true) ? (string) $filters['jk'] : '',
            'sekolah' => mb_substr(Normalizer::text($filters['sekolah'] ?? ''), 0, 50),
            'kelas_id' => max(0, (int) ($filters['kelas_id'] ?? 0)),
            'kamar_id' => max(0, (int) ($filters['kamar_id'] ?? 0)),
            'status' => in_array($filters['status'] ?? '', ['tanpa_kelas', 'tanpa_kamar', 'tanpa_keduanya', 'nonaktif_berkamar'], true)
                ? (string) $filters['status'] : '',
        ];
    }

    // -----------------------------------------------------------------------
    // Tinjauan (read-only) dan penerapan (transaksional)
    // -----------------------------------------------------------------------

    /**
     * Menyusun rencana perubahan TANPA mengubah apa pun.
     *
     * Hasilnya hanya untuk ditampilkan pada layar konfirmasi. `apply()` selalu
     * menghitung ulang seluruhnya di dalam transaksi dan tidak pernah percaya
     * pada hasil tinjauan ini.
     *
     * @param array<int, mixed> $santriIds
     * @param array<string, mixed> $options
     */
    public function preview(string $aksi, array $santriIds, array $options): array
    {
        $ids = $this->normalizeIds($santriIds);
        $aksi = $this->normalizeAction($aksi);
        $year = $this->requireYear();
        $target = $this->resolveTarget($aksi, $options);
        $santri = $this->repository->santriByIds($ids);

        return $this->rencana($aksi, $ids, $santri, $target, $year, $options, false);
    }

    /**
     * Menerapkan penempatan dalam satu transaksi.
     *
     * @param array<int, mixed> $santriIds
     * @param array<string, mixed> $options
     * @return array<string, mixed> ringkasan hasil
     */
    public function apply(string $aksi, array $santriIds, array $options, int $actorId): array
    {
        $ids = $this->normalizeIds($santriIds);
        $aksi = $this->normalizeAction($aksi);
        $alasan = $this->normalizeReason($aksi, $options);

        $db = $this->repository->db();
        // READ COMMITTED wajib untuk transaksi ini.
        //
        // Pada REPEATABLE READ (bawaan InnoDB) seluruh pembacaan biasa memakai
        // snapshot yang dibuat pada pembacaan PERTAMA transaksi. Akibatnya,
        // setelah menunggu kunci baris kamar, perhitungan penghuni tetap
        // membaca keadaan LAMA dan kapasitas dapat terlampaui — persis kasus
        // "dua admin mengisi tempat terakhir bersamaan". Dengan READ COMMITTED,
        // setiap pembacaan setelah kunci diperoleh melihat keadaan terkini,
        // sehingga kunci baris benar-benar menjadi penjaga kapasitas.
        //
        // Perintah ini hanya berlaku untuk SATU transaksi berikutnya, sehingga
        // tidak mengubah perilaku modul lain.
        //
        // Nilai balik kedua pernyataan di bawah DIPERIKSA: dengan
        // `mysqli_report(MYSQLI_REPORT_OFF)` keduanya hanya mengembalikan false
        // saat gagal. Membiarkannya lolos berarti transaksi berjalan pada
        // isolasi yang salah — atau tanpa transaksi sama sekali — sementara
        // seluruh jaminan kapasitas dan atomisitas di atas mengandaikan
        // sebaliknya.
        if ($db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
            error_log('Penempatan: isolasi READ COMMITTED gagal disetel (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Penempatan dibatalkan: basis data tidak dapat menyiapkan transaksi yang aman. Tidak ada perubahan yang tersimpan.');
        }
        if ($db->begin_transaction() === false) {
            error_log('Penempatan: begin_transaction gagal (' . $db->errno . '): ' . $db->error);
            throw new MasterDataException('Penempatan dibatalkan: transaksi basis data tidak dapat dimulai. Tidak ada perubahan yang tersimpan.');
        }
        try {
            // Tahun ajaran dibaca ULANG di dalam transaksi: semester yang
            // berganti di tengah operasi tidak boleh menghasilkan penempatan
            // pada tahun yang salah.
            $year = $this->requireYear();
            $target = $this->resolveTarget($aksi, $options);

            // 1. Kunci baris santri lebih dulu (ID menaik).
            $santri = $this->repository->lockSantri($ids);
            $this->assertSantriUsable($ids, $santri, $this->isRelease($aksi));

            // 2. Susun rencana dari keadaan TERKUNCI, lalu terapkan.
            $rencana = $this->rencana($aksi, $ids, $santri, $target, $year, $options, true);
            if ($rencana['masalah'] !== []) {
                throw new MasterDataException(implode(' ', $rencana['masalah']), $rencana['masalah']);
            }

            $hasil = $rencana['jenis'] === 'kelas'
                ? $this->terapkanKelas($rencana, $year, $actorId, $alasan)
                : $this->terapkanKamar($rencana, $year, $actorId, $alasan);

            $this->auditRingkasan($rencana, $year, $actorId, $alasan, $hasil);

            $db->commit();

            return $hasil + [
                'aksi' => $aksi,
                'mode' => $rencana['mode'],
                'jumlah' => $rencana['jumlah'],
                'target' => $rencana['target'],
            ];
        } catch (Throwable $exception) {
            // errno dibaca SEBELUM rollback: rollback dapat menimpanya.
            $errno = $db->errno;
            $db->rollback();

            throw $this->translateFailure($exception, $errno);
        }
    }

    /**
     * Menerjemahkan kegagalan basis data menjadi pesan yang dapat dimengerti admin.
     *
     * Penulisan kelas memakai `MasterDataRepository` (jalur terpusat V1) yang
     * melempar `RuntimeException` polos untuk galat basis data apa pun. Tanpa
     * penerjemahan ini, konflik kunci pada jalur kelas berakhir sebagai galat
     * 500 tanpa penjelasan, padahal artinya cuma "ada perubahan bersamaan,
     * silakan coba lagi".
     */
    private function translateFailure(Throwable $exception, int $errno): Throwable
    {
        if ($exception instanceof MasterDataException || $exception instanceof PenempatanConflictException) {
            return $exception;
        }
        if (in_array($errno, self::KODE_KONFLIK_KUNCI, true)) {
            return new PenempatanConflictException(
                'Penempatan dibatalkan karena ada perubahan bersamaan pada santri, kelas, atau kamar yang sama. '
                . 'Tidak ada satu pun perubahan yang tersimpan. Muat ulang halaman lalu coba lagi.'
            );
        }
        if ($errno === self::KODE_KUNCI_GANDA) {
            return new PenempatanConflictException(
                'Penempatan dibatalkan karena santri itu baru saja ditempatkan oleh permintaan lain. '
                . 'Tidak ada satu pun perubahan yang tersimpan. Muat ulang halaman lalu periksa keadaannya.'
            );
        }
        if ($errno === self::KODE_BINLOG_STATEMENT) {
            error_log('Penempatan gagal: binlog_format=STATEMENT tidak mendukung transaksi READ COMMITTED.');

            return new MasterDataException(
                'Penempatan dibatalkan karena konfigurasi basis data server. '
                . 'Setelan binlog_format harus ROW atau MIXED agar penempatan dapat berjalan dengan aman. '
                . 'Hubungi pengelola server; tidak ada perubahan yang tersimpan.'
            );
        }

        return $exception;
    }

    // -----------------------------------------------------------------------
    // Penyusunan rencana
    // -----------------------------------------------------------------------

    /**
     * @param array<int, int> $ids
     * @param array<int, array<string, mixed>> $santri
     * @param array<string, mixed>|null $target
     * @param array<string, mixed> $year
     * @param array<string, mixed> $options
     */
    private function rencana(string $aksi, array $ids, array $santri, ?array $target, array $year, array $options, bool $terkunci): array
    {
        $yearId = (int) $year['id'];
        $adalahKamar = in_array($aksi, [self::AKSI_KAMAR_TETAPKAN, self::AKSI_KAMAR_KELUARKAN], true);
        $masalah = [];
        $baris = [];

        // Tanggal mulai hanya relevan untuk kelas; kosong berarti hari ini.
        // Formulir massal adalah satu formulir, sehingga tindakan KAMAR pun ikut
        // mengirim `tanggal_mulai`. Nilai itu sengaja diabaikan di sana: tanggal
        // yang tidak dipakai tidak boleh memblokir perpindahan kamar.
        $tanggal = Normalizer::date($options['tanggal_mulai'] ?? '', true);
        if (!$adalahKamar && $tanggal === '' && Normalizer::text($options['tanggal_mulai'] ?? '') !== '') {
            $masalah[] = 'Tanggal mulai harus berformat YYYY-MM-DD dan merupakan tanggal yang benar.';
        }
        $tanggal = ($tanggal === '' || $tanggal === null) ? date('Y-m-d') : $tanggal;

        // Santri nonaktif/arsip TIDAK boleh ditempatkan, tetapi HARUS boleh
        // dikeluarkan: kalau tidak, tempat tidur yang ditinggalkannya terkunci
        // selamanya dan hanya dapat dibebaskan lewat SQL manual.
        $keluarkan = $this->isRelease($aksi);
        foreach ($ids as $id) {
            if (!isset($santri[$id])) {
                $masalah[] = 'Santri dengan ID ' . $id . ' tidak ditemukan.';
            } elseif (!$keluarkan && ((int) $santri[$id]['is_active'] !== 1 || $santri[$id]['archived_at'] !== null)) {
                $masalah[] = 'Santri ' . $santri[$id]['nama_santri'] . ' tidak aktif atau sudah diarsipkan.';
            }
        }

        if ($adalahKamar) {
            $sekarang = $this->repository->roomAssignments($ids, $yearId);
            $kamarTerkait = $target === null ? [] : [(int) $target['id']];
            foreach ($sekarang as $rows) {
                foreach ($rows as $row) {
                    $kamarTerkait[] = (int) $row['id_kamar'];
                }
            }
            $kamarTerkait = array_values(array_unique($kamarTerkait));
            sort($kamarTerkait);
            if ($terkunci && $kamarTerkait !== []) {
                // 3. Kunci baris kamar SETELAH santri, tetap menurut ID menaik.
                $this->repository->lockRooms($kamarTerkait);
            }

            $tambahan = 0;
            foreach ($ids as $id) {
                $rows = $sekarang[$id] ?? [];
                if (count($rows) > 1) {
                    $masalah[] = 'Konflik data: ' . ($santri[$id]['nama_santri'] ?? ('santri #' . $id))
                        . ' memiliki ' . count($rows) . ' penempatan kamar pada tahun ajaran ini. '
                        . 'Jalankan "php bin/penempatan_preflight.php" lalu selesaikan konflik itu lebih dahulu; '
                        . 'sistem tidak memperbaiki data produksi secara otomatis.';
                    continue;
                }
                $row = $rows[0] ?? null;
                $sebelumId = $row === null ? null : (int) $row['id_kamar'];
                $perubahan = $this->klasifikasi($sebelumId, $target === null ? null : (int) $target['id'], $aksi === self::AKSI_KAMAR_KELUARKAN);
                if ($perubahan === 'masuk' || $perubahan === 'pindah') {
                    $tambahan++;
                }
                $baris[] = [
                    'santri_id' => $id,
                    'nis' => (string) ($santri[$id]['nis'] ?? ''),
                    'nama_santri' => (string) ($santri[$id]['nama_santri'] ?? ''),
                    'penempatan_id' => $row === null ? null : (int) $row['id'],
                    'sebelum_id' => $sebelumId,
                    'sebelum' => $row === null ? null : (string) ($row['nama_kamar'] ?? ('Kamar #' . $sebelumId)),
                    'sesudah_id' => $aksi === self::AKSI_KAMAR_KELUARKAN ? null : ($target === null ? null : (int) $target['id']),
                    'sesudah' => $aksi === self::AKSI_KAMAR_KELUARKAN ? null : ($target['nama'] ?? null),
                    'perubahan' => $perubahan,
                ];
            }

            $kapasitas = null;
            if ($target !== null) {
                $terisi = $this->repository->roomOccupancy([(int) $target['id']], $yearId)[(int) $target['id']] ?? 0;
                $sisa = (int) $target['kapasitas'] - $terisi;
                $kapasitas = [
                    'kamar_id' => (int) $target['id'],
                    'nama_kamar' => (string) $target['nama'],
                    'kapasitas' => (int) $target['kapasitas'],
                    'terisi' => $terisi,
                    'sisa' => $sisa,
                    'tambahan' => $tambahan,
                    'cukup' => $terisi + $tambahan <= (int) $target['kapasitas'],
                ];
                if (!$kapasitas['cukup']) {
                    $masalah[] = 'Kapasitas kamar ' . $target['nama'] . ' tidak mencukupi: terisi ' . $terisi
                        . ' dari ' . (int) $target['kapasitas'] . ', sisa ' . max(0, $sisa)
                        . ' tempat, sedangkan yang perlu ditambahkan ' . $tambahan . ' santri. '
                        . 'Tidak ada satu pun santri yang dipindahkan.';
                }
            }

            return [
                'aksi' => $aksi, 'jenis' => 'kamar', 'jumlah' => count($ids),
                'mode' => count($ids) > 1 ? 'massal' : 'individu',
                'target' => $target, 'tahun' => $year, 'baris' => $baris,
                'kapasitas' => $kapasitas, 'masalah' => $masalah,
                'tanggal_mulai' => $tanggal,
            ];
        }

        $sekarang = $this->repository->activeClassAssignments($ids, $yearId);
        foreach ($ids as $id) {
            $row = $sekarang[$id] ?? null;
            $sebelumId = $row === null ? null : (int) $row['id_kelas'];
            $baris[] = [
                'santri_id' => $id,
                'nis' => (string) ($santri[$id]['nis'] ?? ''),
                'nama_santri' => (string) ($santri[$id]['nama_santri'] ?? ''),
                'penempatan_id' => $row === null ? null : (int) $row['id'],
                'sebelum_id' => $sebelumId,
                'sebelum' => $row === null ? null : (string) ($row['nama_kelas'] ?? ('Kelas #' . $sebelumId)),
                'sesudah_id' => $aksi === self::AKSI_KELAS_KELUARKAN ? null : ($target === null ? null : (int) $target['id']),
                'sesudah' => $aksi === self::AKSI_KELAS_KELUARKAN ? null : ($target['nama'] ?? null),
                'perubahan' => $this->klasifikasi($sebelumId, $target === null ? null : (int) $target['id'], $aksi === self::AKSI_KELAS_KELUARKAN),
            ];
        }

        return [
            'aksi' => $aksi, 'jenis' => 'kelas', 'jumlah' => count($ids),
            'mode' => count($ids) > 1 ? 'massal' : 'individu',
            'target' => $target, 'tahun' => $year, 'baris' => $baris,
            'kapasitas' => null, 'masalah' => $masalah,
            'tanggal_mulai' => $tanggal,
        ];
    }

    private function klasifikasi(?int $sebelum, ?int $sesudah, bool $keluar): string
    {
        if ($keluar) {
            return $sebelum === null ? 'tidak_ada' : 'keluar';
        }
        if ($sebelum === null) {
            return 'masuk';
        }

        return $sebelum === $sesudah ? 'tetap' : 'pindah';
    }

    // -----------------------------------------------------------------------
    // Penerapan
    // -----------------------------------------------------------------------

    private function terapkanKamar(array $rencana, array $year, int $actorId, string $alasan): array
    {
        $yearId = (int) $year['id'];
        $diterapkan = 0;
        $tetap = 0;

        foreach ($rencana['baris'] as $baris) {
            if ($baris['perubahan'] === 'tetap' || $baris['perubahan'] === 'tidak_ada') {
                $tetap++;
                continue;
            }
            if ($baris['perubahan'] === 'keluar') {
                $this->repository->releaseRoomAssignment((int) $baris['penempatan_id'], $yearId);
                $entityId = (int) $baris['penempatan_id'];
            } elseif ($baris['perubahan'] === 'pindah') {
                // ID penempatan dipertahankan.
                $this->repository->moveRoomAssignment((int) $baris['penempatan_id'], (int) $baris['sesudah_id'], $yearId);
                $entityId = (int) $baris['penempatan_id'];
            } else {
                $entityId = $this->repository->createRoomAssignment((int) $baris['santri_id'], (int) $baris['sesudah_id'], $yearId);
            }
            $this->auditBaris('kamar', $rencana, $baris, $year, $entityId, $actorId, $alasan);
            $diterapkan++;
        }

        return ['diterapkan' => $diterapkan, 'tidak_berubah' => $tetap];
    }

    private function terapkanKelas(array $rencana, array $year, int $actorId, string $alasan): array
    {
        $yearId = (int) $year['id'];
        $tanggal = $this->tanggal($rencana);
        $diterapkan = 0;
        $tetap = 0;

        foreach ($rencana['baris'] as $baris) {
            if ($baris['perubahan'] === 'tetap' || $baris['perubahan'] === 'tidak_ada') {
                $tetap++;
                continue;
            }
            if ($baris['perubahan'] === 'keluar') {
                // Jalur terpusat V1: penempatan aktif diselesaikan, barisnya
                // tetap ada sebagai riwayat.
                $this->master->membershipEnd((int) $baris['santri_id'], $yearId, $tanggal);
                $entityId = (int) $baris['penempatan_id'];
            } else {
                // membershipAssign menyelesaikan penempatan aktif sebelumnya
                // lalu membuat penempatan baru — model riwayat tidak berubah.
                $entityId = $this->master->membershipAssign((int) $baris['santri_id'], (int) $baris['sesudah_id'], $yearId, $tanggal, $actorId);
            }
            $this->auditBaris('kelas', $rencana, $baris, $year, $entityId, $actorId, $alasan, $tanggal);
            $diterapkan++;
        }

        return ['diterapkan' => $diterapkan, 'tidak_berubah' => $tetap];
    }

    // -----------------------------------------------------------------------
    // Audit (di dalam transaksi yang sama; gagal audit = rollback)
    // -----------------------------------------------------------------------

    private function auditBaris(string $jenis, array $rencana, array $baris, array $year, int $entityId, int $actorId, string $alasan, ?string $tanggal = null): void
    {
        $keluar = $baris['perubahan'] === 'keluar';
        $aksi = 'penempatan.' . $jenis . '.' . ($keluar ? 'keluarkan' : 'tetapkan');
        $entity = $jenis === 'kamar' ? 'plotting_kamar' : 'plotting_kelas';

        $sebelum = [
            'santri_id' => (int) $baris['santri_id'],
            'nis' => $baris['nis'],
            'nama_santri' => $baris['nama_santri'],
            $jenis . '_id' => $baris['sebelum_id'],
            $jenis => $baris['sebelum'],
        ];
        $sesudah = [
            'santri_id' => (int) $baris['santri_id'],
            'nis' => $baris['nis'],
            'nama_santri' => $baris['nama_santri'],
            $jenis . '_id' => $baris['sesudah_id'],
            $jenis => $baris['sesudah'],
            'tahun_ajaran_id' => (int) $year['id'],
            'tahun_ajaran' => trim(($year['tahun'] ?? '') . ' ' . ($year['semester'] ?? '')),
            'perubahan' => $baris['perubahan'],
            'mode' => $rencana['mode'],
            'jumlah_santri' => $rencana['jumlah'],
            'alasan' => $alasan === '' ? null : $alasan,
        ];
        if ($tanggal !== null) {
            $sesudah['tanggal'] = $tanggal;
        }

        if (!$this->audit->log($aksi, $entity, $entityId, $sebelum, $sesudah, $actorId)) {
            throw new MasterDataException('Penempatan dibatalkan karena catatan audit tidak dapat disimpan. Tidak ada perubahan yang tersimpan.');
        }
    }

    private function auditRingkasan(array $rencana, array $year, int $actorId, string $alasan, array $hasil): void
    {
        // Tindakan yang tidak mengubah apa pun tidak meninggalkan jejak audit —
        // termasuk ringkasan massalnya.
        if ($rencana['mode'] !== 'massal' || (int) $hasil['diterapkan'] === 0) {
            return;
        }
        $tercatat = $this->audit->log(
            'penempatan.' . $rencana['jenis'] . '.massal',
            $rencana['jenis'] === 'kamar' ? 'plotting_kamar' : 'plotting_kelas',
            null,
            null,
            [
                'aksi' => $rencana['aksi'],
                'tahun_ajaran_id' => (int) $year['id'],
                'tahun_ajaran' => trim(($year['tahun'] ?? '') . ' ' . ($year['semester'] ?? '')),
                'target_id' => $rencana['target']['id'] ?? null,
                'target' => $rencana['target']['nama'] ?? null,
                'jumlah_santri' => $rencana['jumlah'],
                'diterapkan' => $hasil['diterapkan'],
                'tidak_berubah' => $hasil['tidak_berubah'],
                'santri_id' => array_map(static fn (array $b): int => (int) $b['santri_id'], $rencana['baris']),
                'alasan' => $alasan === '' ? null : $alasan,
            ],
            $actorId
        );
        if (!$tercatat) {
            throw new MasterDataException('Penempatan massal dibatalkan karena catatan audit tidak dapat disimpan. Tidak ada perubahan yang tersimpan.');
        }
    }

    // -----------------------------------------------------------------------
    // Validasi masukan
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
            throw new MasterDataException('Satu operasi massal dibatasi ' . self::BATAS_MASSAL . ' santri. Bagi menjadi beberapa tahap.');
        }

        return $ids;
    }

    private function normalizeAction(string $aksi): string
    {
        if (!in_array($aksi, self::AKSI, true)) {
            throw new MasterDataException('Tindakan penempatan tidak dikenal.');
        }

        return $aksi;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function normalizeReason(string $aksi, array $options): string
    {
        $alasan = mb_substr(Normalizer::text($options['alasan'] ?? ''), 0, 500);
        $keluar = in_array($aksi, [self::AKSI_KELAS_KELUARKAN, self::AKSI_KAMAR_KELUARKAN], true);
        if ($keluar && $alasan === '') {
            throw new MasterDataException('Alasan wajib diisi ketika mengeluarkan santri dari kelas atau kamar.');
        }

        return $alasan;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireYear(): array
    {
        $year = $this->repository->activeYear();
        if ($year === null) {
            throw new MasterDataException('Belum ada tahun ajaran aktif. Aktifkan satu semester pada halaman Tahun Ajaran terlebih dahulu.');
        }

        return $year;
    }

    /**
     * Memvalidasi kelas/kamar tujuan di server. Nilai dropdown TIDAK pernah
     * dianggap sudah benar: entitas dibaca ulang dari basis data.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null null untuk tindakan mengeluarkan
     */
    private function resolveTarget(string $aksi, array $options): ?array
    {
        if ($aksi === self::AKSI_KELAS_KELUARKAN || $aksi === self::AKSI_KAMAR_KELUARKAN) {
            return null;
        }
        if ($aksi === self::AKSI_KELAS_TETAPKAN) {
            $id = $this->positiveId($options['kelas_id'] ?? null, 'kelas');
            $kelas = $this->repository->assignableClass($id);
            if ($kelas === null) {
                throw new MasterDataException('Kelas tujuan tidak ditemukan, tidak aktif, atau sudah diarsipkan.');
            }

            return ['id' => (int) $kelas['id'], 'nama' => (string) $kelas['nama_kelas'], 'jenjang' => (string) $kelas['jenjang']];
        }

        $id = $this->positiveId($options['kamar_id'] ?? null, 'kamar');
        $kamar = $this->repository->assignableRoom($id);
        if ($kamar === null) {
            throw new MasterDataException('Kamar tujuan tidak ditemukan atau kapasitasnya belum diatur.');
        }

        return ['id' => (int) $kamar['id'], 'nama' => (string) $kamar['nama_kamar'], 'kapasitas' => (int) $kamar['kapasitas']];
    }

    private function positiveId(mixed $value, string $label): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new MasterDataException('Pilih ' . $label . ' tujuan terlebih dahulu.');
        }

        return $id;
    }

    private function isRelease(string $aksi): bool
    {
        return in_array($aksi, [self::AKSI_KELAS_KELUARKAN, self::AKSI_KAMAR_KELUARKAN], true);
    }

    /**
     * @param array<int, int> $ids
     * @param array<int, array<string, mixed>> $santri
     * @param bool $keluarkan tindakan mengeluarkan boleh dijalankan atas santri
     *        nonaktif/arsip; menempatkan tidak boleh
     */
    private function assertSantriUsable(array $ids, array $santri, bool $keluarkan): void
    {
        foreach ($ids as $id) {
            if (!isset($santri[$id])) {
                throw new MasterDataException('Santri dengan ID ' . $id . ' tidak ditemukan. Tidak ada perubahan yang tersimpan.');
            }
            if (!$keluarkan && ((int) $santri[$id]['is_active'] !== 1 || $santri[$id]['archived_at'] !== null)) {
                throw new MasterDataException('Santri ' . $santri[$id]['nama_santri'] . ' tidak aktif atau sudah diarsipkan sehingga tidak dapat ditempatkan.');
            }
        }
    }

    private function tanggal(array $rencana): string
    {
        $tanggal = (string) ($rencana['tanggal_mulai'] ?? '');

        return $tanggal === '' ? date('Y-m-d') : $tanggal;
    }
}
