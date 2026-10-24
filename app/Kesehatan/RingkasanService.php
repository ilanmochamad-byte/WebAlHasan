<?php

declare(strict_types=1);

namespace App\Kesehatan;

use App\Audit\AuditLogger;
use App\Notification\DeviceRepository;
use App\Notification\NotificationChannel;
use App\Notification\NotificationRepository;
use App\Notification\SettingsRepository;

/**
 * Ringkasan kesehatan untuk orang tua/wali — PRD V5 Fase 3. Dipakai halaman
 * web (`portal/kesehatan_ringkasan.php`, `portal/kesehatan_wali.php`) dan API
 * `/api/v1/v5/kesehatan/...` sehingga keputusan akses keduanya identik.
 *
 * Pemisahan dari catatan klinis:
 *
 *   - Petugas MENYUSUN sendiri teks aman (ringkasan, tindakan umum, tindak
 *     lanjut untuk orang tua). Tidak ada salinan otomatis keluhan, catatan
 *     klinis, tindakan, obat, rujukan, atau tindak lanjut internal.
 *   - Pratinjau (`pratinjau()`) menampilkan proyeksi yang PERSIS sama dengan
 *     tampilan wali dan menghasilkan `pratinjau_hash`; penerbitan wajib
 *     membawa hash itu dan konfirmasi eksplisit, sehingga yang terbit adalah
 *     yang dipratinjau.
 *   - Setiap publikasi adalah snapshot versi TETAP. Penggantian membuat versi
 *     baru beralasan (versi lama menjadi Diganti), penarikan beralasan tidak
 *     menghapus apa pun, dan koreksi klinis tidak menyentuh snapshot terbit.
 *   - Wali membaca proyeksi khusus (`RingkasanRepository::wali*`) yang tidak
 *     pernah membaca tabel klinis; relasi wali aktif diperiksa per baris pada
 *     setiap daftar/detail.
 *
 * Hak: menerbitkan/mengganti/menarik hanya petugas kesehatan berpenugasan
 * aktif (`KesehatanService::akses()['catat']`, diperiksa ulang dengan kunci
 * baca di dalam transaksi; admin dan admin+petugas ditolak). Admin membaca
 * seluruh riwayat; pembimbing/murobi terkait membaca versi yang berlaku saja.
 *
 * Notifikasi: satu peristiwa Terbit/Ganti → tepat satu baris InApp per wali
 * berakun yang berhak dan paling banyak satu baris Push (hanya bila sakelar
 * Push menyala dan wali memiliki perangkat aktif), ditulis ke
 * `notifikasi_outbox` di DALAM transaksi yang sama; kegagalan antrean
 * membatalkan publikasi. WhatsApp tidak pernah diantrekan. Judul/isi generik
 * tanpa nama santri, keluhan, obat, rujukan, nomor telepon, atau isi
 * ringkasan; data hanya penunjuk `ringkasan_id`.
 */
final class RingkasanService
{
    public const EVENT_TERBIT = 'v5_kesehatan_ringkasan_terbit';
    public const EVENT_DIGANTI = 'v5_kesehatan_ringkasan_diganti';
    public const EVENT_TINDAK_LANJUT = 'v5_kesehatan_tindak_lanjut';
    public const EVENTS = [self::EVENT_TERBIT, self::EVENT_DIGANTI, self::EVENT_TINDAK_LANJUT];
    public const TIPE_TAUTAN = 'kesehatan_ringkasan';
    public const SUMBER_WALI = 'wali';

    private const OPERASI_TERBIT = 'ringkasan.terbit';
    private const OPERASI_TARIK = 'ringkasan.tarik';
    private const BATAS_RINGKASAN = 2000;
    private const BATAS_TINDAKAN = 1000;
    private const BATAS_TINDAK_LANJUT = 1000;
    private const BATAS_ALASAN = 1000;
    private const MIN_ALASAN = 5;
    private const MIN_RINGKASAN = 10;

    public function __construct(
        private KesehatanRepository $klinik,
        private RingkasanRepository $repo,
        private KesehatanService $kesehatan,
        private AuditLogger $audit,
        private SettingsRepository $settings,
        private DeviceRepository $devices,
        private NotificationRepository $notifikasi
    ) {
    }

    /**
     * Judul dan isi notifikasi — satu-satunya tempat teksnya dirakit, dipakai
     * saat antre dan ulang oleh worker Push. Generik, tanpa data pribadi.
     *
     * @return array{judul:string, isi:string}
     */
    public static function teksNotifikasi(string $eventType): array
    {
        return match ($eventType) {
            self::EVENT_DIGANTI => ['judul' => 'Informasi kesehatan diperbarui', 'isi' => 'Ada pembaruan informasi kesehatan untuk anak Anda. Masuk untuk melihatnya.'],
            self::EVENT_TINDAK_LANJUT => ['judul' => 'Informasi kesehatan', 'isi' => 'Ada informasi kesehatan yang memerlukan perhatian Anda. Masuk untuk melihatnya.'],
            default => ['judul' => 'Informasi kesehatan', 'isi' => 'Ada informasi kesehatan baru untuk anak Anda. Masuk untuk melihatnya.'],
        };
    }

    public static function eventKey(int $ringkasanId, int $versi): string
    {
        return 'v5:kesehatan:ringkasan:' . $ringkasanId . ':v' . $versi;
    }

    // =======================================================================
    // Internal (petugas, admin, pembimbing/murobi terkait)
    // =======================================================================

    /**
     * Keadaan ringkasan wali satu kunjungan untuk tampilan internal.
     * Petugas/admin: seluruh versi dan peristiwa (alasan, pelaku, waktu,
     * jumlah penerima). Pembimbing/murobi: hanya versi yang berlaku untuk
     * kunjungan Tercatat dalam cakupannya.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function internal(array $user, int $kunjunganId): array
    {
        [$akses, $row] = $this->barisInternal($user, $kunjunganId);
        $kepala = $this->repo->kepala((int) $row['id']);
        $pengawas = (bool) $akses['riwayat_versi'];
        $versi = $kepala === null ? [] : $this->repo->versiInternal((int) $kepala['id']);
        $berlaku = null;
        foreach ($versi as $v) {
            if ($v['status'] === 'Terbit') {
                $berlaku = $v;
                break;
            }
        }

        return [
            'kunjungan' => [
                'id' => (int) $row['id'],
                'versi' => (int) $row['versi'],
                'status' => (string) $row['status'],
                'tanggal' => substr((string) $row['waktu_kunjungan'], 0, 10),
                'santri' => ['id' => (int) $row['santri_id'], 'nama' => (string) $row['nama_santri']],
            ],
            'ringkasan' => $kepala === null ? null : $this->kepalaDto($kepala),
            'berlaku' => $berlaku === null || $kepala === null ? null : $this->proyeksi(
                ['id' => $kepala['id'], 'santri_id' => $row['santri_id'], 'nama_santri' => $row['nama_santri'], 'kepala_status' => 'Terbit'] + $berlaku,
                (int) $berlaku['versi']
            ),
            'versi' => $pengawas ? array_map(fn (array $v): array => [
                'versi' => (int) $v['versi'],
                'status' => (string) $v['status'],
                'kunjungan_versi' => (int) $v['kunjungan_versi'],
                'ringkasan' => (string) $v['ringkasan'],
                'tindakan_umum' => $v['tindakan_umum'] === null ? null : (string) $v['tindakan_umum'],
                'tindak_lanjut_wali' => $v['tindak_lanjut_wali'] === null ? null : (string) $v['tindak_lanjut_wali'],
                'diterbitkan_pada' => (string) $v['diterbitkan_pada'],
                'berakhir_pada' => $v['berakhir_pada'] === null ? null : (string) $v['berakhir_pada'],
                'oleh' => ['id' => (int) $v['diterbitkan_oleh'], 'nama' => (string) $v['penerbit_nama']],
            ], $versi) : [],
            'peristiwa' => $pengawas && $kepala !== null ? array_map(static fn (array $e): array => [
                'jenis' => (string) $e['jenis'],
                'versi' => (int) $e['versi'],
                'alasan' => $e['alasan'] === null ? null : (string) $e['alasan'],
                'jumlah_penerima' => (int) $e['jumlah_penerima'],
                'kanal' => (string) $e['kanal'],
                'waktu' => (string) $e['dibuat_pada'],
                'oleh' => ['id' => (int) $e['dibuat_oleh'], 'nama' => (string) $e['pelaku_nama']],
            ], $this->repo->peristiwaInternal((int) $kepala['id'])) : [],
            'penerima' => $pengawas ? count($this->repo->penerimaBerhak((int) $row['santri_id'])) : null,
            'boleh_terbit' => $akses['catat'] && $row['status'] === KesehatanService::STATUS_TERCATAT,
            'akses' => ['catat' => (bool) $akses['catat'], 'riwayat' => $pengawas, 'cakupan' => $akses['cakupan']],
        ];
    }

    /**
     * Pratinjau persis seperti dilihat wali, tanpa menulis apa pun. Hash yang
     * dikembalikan wajib dibawa `terbitkan()`.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input kunjungan_versi, revisi_ringkasan, ringkasan, tindakan_umum, tindak_lanjut_wali, alasan
     * @return array{pratinjau:array<string, mixed>, pratinjau_hash:string, kunjungan_versi:int, revisi_ringkasan:int, penggantian:bool, penerima:int}
     */
    public function pratinjau(array $user, int $kunjunganId, array $input): array
    {
        $this->wajibCatat($user);
        $data = $this->normalisasi($input);
        $row = $this->klinik->kunjungan($kunjunganId) ?? throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
        $this->wajibTercatat($row);
        if ((int) $row['versi'] !== $data['kunjungan_versi']) {
            throw new KesehatanException('Catatan kunjungan sudah berubah menjadi versi ' . (int) $row['versi'] . '. Muat ulang lalu susun pratinjau lagi.', 409);
        }
        if ($this->klinik->santriAktif((int) $row['santri_id']) === null) {
            throw new KesehatanException('Santri sudah tidak aktif. Ringkasan tidak dapat diterbitkan.', 422);
        }
        $kepala = $this->repo->kepala($kunjunganId);
        $this->wajibRevisi($kepala, $data['revisi_ringkasan']);
        $this->wajibAlasanPenggantian($kepala, $data['alasan']);
        $versi = $kepala === null ? 1 : (int) $kepala['versi_terakhir'] + 1;

        return [
            'pratinjau' => $this->proyeksi([
                'id' => $kepala['id'] ?? 0,
                'santri_id' => $row['santri_id'],
                'nama_santri' => $row['nama_santri'],
                'kepala_status' => 'Terbit',
                'tanggal_kunjungan' => substr((string) $row['waktu_kunjungan'], 0, 10),
                'diterbitkan_pada' => date('Y-m-d H:i:s'),
            ] + $data['isi'], $versi),
            'pratinjau_hash' => $this->hash($kunjunganId, $data),
            'kunjungan_versi' => $data['kunjungan_versi'],
            'revisi_ringkasan' => $data['revisi_ringkasan'],
            'penggantian' => $kepala !== null,
            'penerima' => count($this->repo->penerimaBerhak((int) $row['santri_id'])),
        ];
    }

    /**
     * Menerbitkan ringkasan pertama, mengganti versi yang berlaku, atau
     * menerbitkan ulang sesudah penarikan (dua yang terakhir wajib alasan).
     *
     * Urutan transaksi: penugasan (kunci baca, admin ditolak) → klaim
     * idempotensi → replay → kunci kunjungan (Tercatat, versi sumber sama) →
     * kunci kepala (revisi sama) → snapshot versi baru → peristiwa + penerima
     * → outbox InApp/Push → audit metadata. Kegagalan di langkah mana pun
     * membatalkan seluruhnya, termasuk notifikasi.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, konfirmasi, pratinjau_hash, kunjungan_versi, revisi_ringkasan,
     *     ringkasan, tindakan_umum, tindak_lanjut_wali, alasan
     * @return array{ringkasan_id:int, versi:int, revisi:int, status:int, replayed:bool, notifikasi:array{in_app:int, push:int}}
     */
    public function terbitkan(array $user, int $kunjunganId, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $kunci = $this->kunci($input['idempotency_key'] ?? null);
        if (!in_array($input['konfirmasi'] ?? null, [true, 1, '1', 'on'], true)) {
            throw new KesehatanException('Konfirmasi eksplisit wajib diberikan setelah memeriksa pratinjau.');
        }
        $data = $this->normalisasi($input);
        $hash = $this->hash($kunjunganId, $data);
        $dariPratinjau = $input['pratinjau_hash'] ?? null;
        if (!is_string($dariPratinjau) || !hash_equals($hash, $dariPratinjau)) {
            throw new KesehatanException('Isi yang diterbitkan berbeda dari pratinjau. Lihat pratinjau ulang sebelum menerbitkan.');
        }
        $userId = (int) $user['id'];
        $kanal = $kanal === KesehatanService::KANAL_API ? KesehatanService::KANAL_API : KesehatanService::KANAL_WEB;

        return $this->klinik->transaction(function () use ($userId, $kunjunganId, $data, $kunci, $hash, $kanal): array {
            $penugasan = $this->klinik->kunciPenugasanAktif($userId)
                ?? throw new KesehatanException('Penugasan kesehatan Anda sudah tidak aktif. Ringkasan tidak diterbitkan.', 403);
            $klaim = $this->klinik->klaimIdempotensi($userId, self::OPERASI_TERBIT, $kunci, $hash);
            if (!hash_equals($klaim['request_hash'], $hash)) {
                throw new KesehatanException('Kunci idempotensi sudah dipakai untuk penerbitan lain. Tidak ada data yang diubah.', 409);
            }
            if ($klaim['kunjungan_id'] !== null) {
                $lama = $this->repo->peristiwaDariIdempotensi($klaim['id']) ?? throw new KesehatanException('Hasil penerbitan sebelumnya tidak ditemukan.', 503);

                return ['ringkasan_id' => $lama['ringkasan_id'], 'versi' => $lama['versi'], 'revisi' => $lama['revisi'], 'status' => 200, 'replayed' => true, 'notifikasi' => ['in_app' => 0, 'push' => 0]];
            }
            $baris = $this->klinik->kunciKunjungan($kunjunganId) ?? throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
            $this->wajibTercatat($baris);
            if ((int) $baris['versi'] !== $data['kunjungan_versi']) {
                throw new KesehatanException('Catatan kunjungan sudah berubah menjadi versi ' . (int) $baris['versi'] . ' sejak pratinjau. Muat ulang lalu susun pratinjau lagi.', 409);
            }
            $santriId = (int) $baris['santri_id'];
            if ($this->klinik->santriAktif($santriId) === null) {
                throw new KesehatanException('Santri sudah tidak aktif. Ringkasan tidak dapat diterbitkan.', 422);
            }
            $kepala = $this->repo->kunciKepala($kunjunganId);
            $this->wajibRevisi($kepala, $data['revisi_ringkasan']);
            $this->wajibAlasanPenggantian($kepala, $data['alasan']);

            if ($kepala === null) {
                $ringkasanId = $this->repo->simpanKepala($kunjunganId, $santriId, $userId);
                $versi = 1;
                $revisi = 1;
            } else {
                $ringkasanId = (int) $kepala['id'];
                $versi = (int) $kepala['versi_terakhir'] + 1;
                $revisi = (int) $kepala['revisi'] + 1;
                if ($kepala['status'] === 'Terbit') {
                    $this->repo->akhiriVersi($ringkasanId, (int) $kepala['versi_berlaku'], 'Diganti');
                }
                if (!$this->repo->perbaruiKepala($ringkasanId, 'Terbit', $versi, $versi, (int) $kepala['revisi'], $userId)) {
                    throw new KesehatanException('Ringkasan sudah diubah permintaan lain. Muat ulang lalu ulangi.', 409);
                }
            }
            $versiId = $this->repo->simpanVersi($ringkasanId, $versi, $kunjunganId, $data['kunjungan_versi'], substr((string) $baris['waktu_kunjungan'], 0, 10), $data['isi'], $userId);
            $penerima = $this->repo->penerimaBerhak($santriId);
            $jenis = $kepala === null ? 'Terbit' : 'Ganti';
            $peristiwaId = $this->repo->simpanPeristiwa($ringkasanId, $versiId, $jenis, $data['alasan'], count($penerima), $kanal, $klaim['id'], $userId);
            $this->repo->simpanPenerima($peristiwaId, $penerima);
            $eventType = $data['isi']['tindak_lanjut_wali'] !== null ? self::EVENT_TINDAK_LANJUT : ($kepala === null ? self::EVENT_TERBIT : self::EVENT_DIGANTI);
            $notifikasi = $this->antrekan($ringkasanId, $versi, $eventType, array_column($penerima, 'user_id'));
            $this->klinik->selesaikanIdempotensi($klaim['id'], $kunjunganId, 201);
            if (!$this->audit->log($jenis === 'Terbit' ? 'kesehatan.ringkasan.terbit' : 'kesehatan.ringkasan.diganti', 'kesehatan_ringkasan', $ringkasanId,
                $kepala === null ? null : ['revisi' => (int) $kepala['revisi'], 'status' => (string) $kepala['status'], 'versi_berlaku' => $kepala['versi_berlaku'] === null ? null : (int) $kepala['versi_berlaku']],
                [
                    'ringkasan_id' => $ringkasanId,
                    'kunjungan_id' => $kunjunganId,
                    'santri_id' => $santriId,
                    'versi' => $versi,
                    'revisi' => $revisi,
                    'kunjungan_versi' => $data['kunjungan_versi'],
                    'alasan_tercatat' => $data['alasan'] !== null,
                    'ada_tindak_lanjut_wali' => $data['isi']['tindak_lanjut_wali'] !== null,
                    'jumlah_penerima' => count($penerima),
                    'notifikasi' => $notifikasi,
                    'penugasan_id' => $penugasan['id'],
                    'sumber' => KesehatanService::SUMBER_PETUGAS,
                    'kanal' => $kanal,
                ], $userId)) {
                throw new KesehatanException('Audit wajib tidak dapat disimpan. Ringkasan tidak diterbitkan.', 503);
            }

            return ['ringkasan_id' => $ringkasanId, 'versi' => $versi, 'revisi' => $revisi, 'status' => 201, 'replayed' => false, 'notifikasi' => $notifikasi];
        });
    }

    /**
     * Menarik ringkasan yang berlaku dengan alasan. Snapshot tetap tersimpan;
     * wali hanya melihat keterangan bahwa informasi ditarik. Tidak ada
     * notifikasi baru.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input idempotency_key, revisi_ringkasan, alasan
     * @return array{ringkasan_id:int, versi:int, revisi:int, status:int, replayed:bool}
     */
    public function tarik(array $user, int $kunjunganId, array $input, string $kanal): array
    {
        $this->wajibCatat($user);
        $kunci = $this->kunci($input['idempotency_key'] ?? null);
        $revisi = $this->angka($input['revisi_ringkasan'] ?? null, 'Revisi ringkasan', 1);
        $alasan = $this->teks($input['alasan'] ?? null, self::BATAS_ALASAN, 'Alasan penarikan', true);
        if (mb_strlen((string) $alasan) < self::MIN_ALASAN) {
            throw new KesehatanException('Alasan penarikan minimal ' . self::MIN_ALASAN . ' karakter.');
        }
        $hash = hash('sha256', (string) json_encode([self::OPERASI_TARIK, $kunjunganId, $revisi, $alasan], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $userId = (int) $user['id'];
        $kanal = $kanal === KesehatanService::KANAL_API ? KesehatanService::KANAL_API : KesehatanService::KANAL_WEB;

        return $this->klinik->transaction(function () use ($userId, $kunjunganId, $revisi, $alasan, $kunci, $hash, $kanal): array {
            $penugasan = $this->klinik->kunciPenugasanAktif($userId)
                ?? throw new KesehatanException('Penugasan kesehatan Anda sudah tidak aktif. Ringkasan tidak ditarik.', 403);
            $klaim = $this->klinik->klaimIdempotensi($userId, self::OPERASI_TARIK, $kunci, $hash);
            if (!hash_equals($klaim['request_hash'], $hash)) {
                throw new KesehatanException('Kunci idempotensi sudah dipakai untuk perubahan lain. Tidak ada data yang diubah.', 409);
            }
            if ($klaim['kunjungan_id'] !== null) {
                $lama = $this->repo->peristiwaDariIdempotensi($klaim['id']) ?? throw new KesehatanException('Hasil penarikan sebelumnya tidak ditemukan.', 503);

                return ['ringkasan_id' => $lama['ringkasan_id'], 'versi' => $lama['versi'], 'revisi' => $lama['revisi'], 'status' => 200, 'replayed' => true];
            }
            $this->klinik->kunciKunjungan($kunjunganId) ?? throw new KesehatanException('Kunjungan tidak ditemukan.', 404);
            $kepala = $this->repo->kunciKepala($kunjunganId) ?? throw new KesehatanException('Kunjungan ini belum memiliki ringkasan untuk wali.', 404);
            if ($kepala['status'] !== 'Terbit') {
                throw new KesehatanException('Ringkasan ini sudah ditarik.', 409);
            }
            $this->wajibRevisi($kepala, $revisi);
            $ringkasanId = (int) $kepala['id'];
            $versi = (int) $kepala['versi_berlaku'];
            $this->repo->akhiriVersi($ringkasanId, $versi, 'Ditarik');
            if (!$this->repo->perbaruiKepala($ringkasanId, 'Ditarik', null, (int) $kepala['versi_terakhir'], $revisi, $userId)) {
                throw new KesehatanException('Ringkasan sudah diubah permintaan lain. Muat ulang lalu ulangi.', 409);
            }
            $versiId = $this->repo->versiId($ringkasanId, $versi) ?? throw new KesehatanException('Versi ringkasan tidak ditemukan.', 503);
            $this->repo->simpanPeristiwa($ringkasanId, $versiId, 'Tarik', $alasan, 0, $kanal, $klaim['id'], $userId);
            $this->klinik->selesaikanIdempotensi($klaim['id'], $kunjunganId, 200);
            if (!$this->audit->log('kesehatan.ringkasan.ditarik', 'kesehatan_ringkasan', $ringkasanId,
                ['revisi' => $revisi, 'status' => 'Terbit', 'versi_berlaku' => $versi],
                ['ringkasan_id' => $ringkasanId, 'kunjungan_id' => $kunjunganId, 'santri_id' => (int) $kepala['santri_id'], 'versi' => $versi, 'revisi' => $revisi + 1,
                    'status' => 'Ditarik', 'alasan_tercatat' => true, 'penugasan_id' => $penugasan['id'], 'sumber' => KesehatanService::SUMBER_PETUGAS, 'kanal' => $kanal], $userId)) {
                throw new KesehatanException('Audit wajib tidak dapat disimpan. Ringkasan tidak ditarik.', 503);
            }

            return ['ringkasan_id' => $ringkasanId, 'versi' => $versi, 'revisi' => $revisi + 1, 'status' => 200, 'replayed' => false];
        });
    }

    // =======================================================================
    // Wali (proyeksi khusus, tanpa tabel klinis)
    // =======================================================================

    /**
     * @param array<string, mixed> $user
     * @return array{wali:bool, anak:array<int, array{id:int, nama:string}>}
     */
    public function aksesWali(array $user): array
    {
        $this->siap();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || !$this->repo->waliAktif($userId)) {
            return ['wali' => false, 'anak' => []];
        }

        return ['wali' => true, 'anak' => $this->repo->anakWali($userId)];
    }

    /**
     * Daftar ringkasan anak dengan relasi wali aktif. Sebelum ada yang terbit
     * hasilnya kosong; ringkasan anak lain tidak pernah masuk SQL.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input santri_id, page
     * @return array{rows:array<int, array<string, mixed>>, total:int, page:int, per_page:int, anak:array<int, array{id:int, nama:string}>}
     */
    public function daftarWali(array $user, array $input): array
    {
        $akses = $this->wajibWali($user);
        $santri = $this->idOpsional($input['santri_id'] ?? null);
        $hasil = $this->repo->waliDaftar((int) $user['id'], $santri, $this->halaman($input['page'] ?? 1));

        return [
            'rows' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'santri' => ['id' => (int) $r['santri_id'], 'nama' => (string) $r['nama_santri']],
                'tanggal_kunjungan' => (string) $r['tanggal_kunjungan'],
                'diterbitkan_pada' => (string) $r['diterbitkan_pada'],
                'status' => (string) $r['status'],
                'versi' => (int) $r['versi'],
                'diperbarui' => (int) $r['versi'] > 1,
                'ada_tindak_lanjut' => (int) $r['ada_tindak_lanjut'] === 1,
            ], $hasil['rows']),
            'total' => (int) $hasil['total'],
            'page' => (int) $hasil['page'],
            'per_page' => (int) $hasil['perPage'],
            'anak' => $akses['anak'],
        ];
    }

    /**
     * Satu ringkasan untuk wali. Tidak ada, milik anak lain, relasi dicabut,
     * akun/wali/santri nonaktif → sama-sama 403. Pembukaan tercatat di log
     * akses dengan metadata saja.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function detailWali(array $user, int $id, string $kanal): array
    {
        $this->wajibWali($user);
        $row = $id > 0 ? $this->repo->waliDetail((int) $user['id'], $id) : null;
        if ($row === null) {
            throw new KesehatanException('Informasi kesehatan tidak dapat diakses. Akses memerlukan relasi wali aktif dengan santri.', 403);
        }
        $this->klinik->catatAkses((int) $row['kunjungan_id'], (int) $row['santri_id'], (int) $user['id'], self::SUMBER_WALI, 'baca_ringkasan',
            $kanal === KesehatanService::KANAL_API ? KesehatanService::KANAL_API : KesehatanService::KANAL_WEB);

        return $this->proyeksi($row + ['kepala_status' => (string) $row['status']], (int) $row['versi']);
    }

    // =======================================================================
    // Notifikasi
    // =======================================================================

    /**
     * Baris outbox satu peristiwa. Kunci unik (event_key, kanal, penerima)
     * menjamin tepat satu baris; baris yang tidak dapat ditulis dan tidak ada
     * membatalkan transaksi publikasi.
     *
     * @param array<int, int> $userIds
     * @return array{in_app:int, push:int}
     */
    private function antrekan(int $ringkasanId, int $versi, string $eventType, array $userIds): array
    {
        $hasil = ['in_app' => 0, 'push' => 0];
        if ($userIds === []) {
            return $hasil;
        }
        $pengaturan = $this->settings->current();
        $berperangkat = $pengaturan['push_enabled'] === true ? $this->devices->usersWithActiveDevice($userIds) : [];
        $teks = self::teksNotifikasi($eventType);
        $key = self::eventKey($ringkasanId, $versi);
        $data = (string) json_encode(['tipe' => self::TIPE_TAUTAN, 'ringkasan_id' => $ringkasanId], JSON_UNESCAPED_SLASHES);
        foreach ($userIds as $userId) {
            $kanal = [NotificationChannel::IN_APP];
            if (in_array($userId, $berperangkat, true)) {
                $kanal[] = NotificationChannel::PUSH;
            }
            foreach ($kanal as $k) {
                $id = $this->notifikasi->enqueue([
                    'event_key' => $key, 'event_type' => $eventType, 'kanal' => $k, 'penerima_user_id' => $userId,
                    'pengajuan_id' => null, 'judul' => $teks['judul'], 'isi' => $teks['isi'], 'data_json' => $data,
                ]);
                if ($id === null && !$this->notifikasi->exists($key, $k, $userId)) {
                    throw new KesehatanException('Notifikasi tidak dapat diantrekan. Ringkasan tidak diterbitkan.', 503);
                }
                $hasil[$k === NotificationChannel::IN_APP ? 'in_app' : 'push'] += $id === null ? 0 : 1;
            }
        }

        return $hasil;
    }

    // =======================================================================
    // Hak, validasi, bentuk
    // =======================================================================

    private function siap(): void
    {
        if (!$this->klinik->skemaSiap() || !$this->repo->skemaSiap()) {
            throw new KesehatanException('Ringkasan kesehatan untuk wali belum dipasang pada basis data ini.', 503);
        }
    }

    /**
     * Hak baca + baris kunjungan sesuai cakupan (pembaca binaan: Tercatat
     * dalam cakupan; di luar cakupan dan tidak ada sama-sama 403).
     *
     * @return array{0:array<string, mixed>, 1:array<string, mixed>}
     */
    private function barisInternal(array $user, int $kunjunganId): array
    {
        $this->siap();
        $akses = $this->kesehatan->akses($user);
        if (!$akses['baca']) {
            throw new KesehatanException('Ringkasan wali hanya untuk petugas kesehatan berpenugasan aktif, admin, serta pembimbing/murobi santri terkait.', 403);
        }
        $saring = $akses['cakupan'] === KesehatanService::CAKUPAN_BINAAN
            ? $this->klinik->saringBinaan(array_keys(array_filter($akses['binaan'])), (int) $user['id'])
            : null;
        $row = $kunjunganId > 0 ? $this->klinik->kunjungan($kunjunganId, $saring) : null;
        if ($row === null) {
            throw $saring !== null
                ? new KesehatanException('Kunjungan tidak ditemukan atau di luar cakupan binaan Anda.', 403)
                : new KesehatanException('Kunjungan tidak ditemukan.', 404);
        }

        return [$akses, $row];
    }

    private function wajibCatat(array $user): void
    {
        $this->siap();
        $akses = $this->kesehatan->akses($user);
        if ($akses['catat']) {
            return;
        }
        throw new KesehatanException(match ($akses['cakupan']) {
            KesehatanService::CAKUPAN_SELURUH => 'Admin hanya membaca ringkasan wali dan tidak menerbitkannya.',
            KesehatanService::CAKUPAN_BINAAN => 'Pembimbing dan murobi hanya membaca ringkasan wali santri binaan.',
            default => 'Penerbitan ringkasan wali hanya untuk pengurus dengan penugasan kesehatan aktif.',
        }, 403);
    }

    /** @return array{wali:bool, anak:array<int, array{id:int, nama:string}>} */
    private function wajibWali(array $user): array
    {
        $akses = $this->aksesWali($user);
        if (!$akses['wali']) {
            throw new KesehatanException('Informasi kesehatan anak hanya untuk akun orang tua/wali yang aktif.', 403);
        }

        return $akses;
    }

    /** @param array<string, mixed> $row */
    private function wajibTercatat(array $row): void
    {
        if ($row['status'] !== KesehatanService::STATUS_TERCATAT) {
            throw new KesehatanException('Ringkasan wali hanya dapat diterbitkan untuk kunjungan yang sudah tercatat, bukan draf.', 422);
        }
    }

    /** @param array<string, mixed>|null $kepala */
    private function wajibRevisi(?array $kepala, int $revisi): void
    {
        $kini = $kepala === null ? 0 : (int) $kepala['revisi'];
        if ($kini !== $revisi) {
            throw new KesehatanException('Ringkasan wali kunjungan ini sudah berubah sejak Anda membukanya. Muat ulang lalu ulangi.', 409);
        }
    }

    /** @param array<string, mixed>|null $kepala */
    private function wajibAlasanPenggantian(?array $kepala, ?string $alasan): void
    {
        if ($kepala !== null && ($alasan === null || mb_strlen($alasan) < self::MIN_ALASAN)) {
            throw new KesehatanException('Alasan penggantian wajib diisi (minimal ' . self::MIN_ALASAN . ' karakter) karena ringkasan pernah terbit.');
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{kunjungan_versi:int, revisi_ringkasan:int, alasan:?string, isi:array{ringkasan:string, tindakan_umum:?string, tindak_lanjut_wali:?string}}
     */
    private function normalisasi(array $input): array
    {
        $ringkasan = (string) $this->teks($input['ringkasan'] ?? null, self::BATAS_RINGKASAN, 'Ringkasan untuk wali', true);
        if (mb_strlen($ringkasan) < self::MIN_RINGKASAN) {
            throw new KesehatanException('Ringkasan untuk wali minimal ' . self::MIN_RINGKASAN . ' karakter.');
        }

        return [
            'kunjungan_versi' => $this->angka($input['kunjungan_versi'] ?? null, 'Versi kunjungan', 1),
            'revisi_ringkasan' => $this->angka($input['revisi_ringkasan'] ?? 0, 'Revisi ringkasan', 0),
            'alasan' => $this->teks($input['alasan'] ?? null, self::BATAS_ALASAN, 'Alasan penggantian', false),
            'isi' => [
                'ringkasan' => $ringkasan,
                'tindakan_umum' => $this->teks($input['tindakan_umum'] ?? null, self::BATAS_TINDAKAN, 'Tindakan umum', false),
                'tindak_lanjut_wali' => $this->teks($input['tindak_lanjut_wali'] ?? null, self::BATAS_TINDAK_LANJUT, 'Tindak lanjut untuk orang tua', false),
            ],
        ];
    }

    /** @param array{kunjungan_versi:int, revisi_ringkasan:int, alasan:?string, isi:array<string, ?string>} $data */
    private function hash(int $kunjunganId, array $data): string
    {
        return hash('sha256', (string) json_encode([self::OPERASI_TERBIT, $kunjunganId, $data['kunjungan_versi'], $data['revisi_ringkasan'], $data['alasan'], $data['isi']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Bentuk yang dilihat wali — dipakai pratinjau, tampilan internal versi
     * berlaku, dan detail wali sehingga ketiganya identik. Tanpa nama petugas,
     * tanpa kolom klinis; ringkasan ditarik tidak membawa isi.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function proyeksi(array $r, int $versi): array
    {
        $terbit = ($r['kepala_status'] ?? 'Terbit') === 'Terbit';
        $teks = static fn (mixed $v): ?string => !$terbit || $v === null ? null : (string) $v;

        return [
            'id' => (int) ($r['id'] ?? 0),
            'santri' => ['id' => (int) $r['santri_id'], 'nama' => (string) $r['nama_santri']],
            'tanggal_kunjungan' => (string) $r['tanggal_kunjungan'],
            'diterbitkan_pada' => (string) $r['diterbitkan_pada'],
            'status' => $terbit ? 'Terbit' : 'Ditarik',
            'versi' => $versi,
            'diperbarui' => $versi > 1,
            'ringkasan' => $teks($r['ringkasan'] ?? null),
            'tindakan_umum' => $teks($r['tindakan_umum'] ?? null),
            'tindak_lanjut_wali' => $teks($r['tindak_lanjut_wali'] ?? null),
        ];
    }

    /** @param array<string, mixed> $k */
    private function kepalaDto(array $k): array
    {
        return [
            'id' => (int) $k['id'],
            'status' => (string) $k['status'],
            'versi_berlaku' => $k['versi_berlaku'] === null ? null : (int) $k['versi_berlaku'],
            'versi_terakhir' => (int) $k['versi_terakhir'],
            'revisi' => (int) $k['revisi'],
        ];
    }

    private function kunci(mixed $kunci): string
    {
        if (!is_string($kunci) || !preg_match('/^[A-Za-z0-9._:-]{8,100}$/D', $kunci)) {
            throw new KesehatanException('Kunci idempotensi wajib 8–100 karakter aman (huruf, angka, . _ : -).');
        }

        return $kunci;
    }

    private function angka(mixed $value, string $label, int $min): int
    {
        if (is_int($value) && $value >= $min && $value <= 999999999) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,8})$/D', $value) || (int) $value < $min) {
            throw new KesehatanException($label . ' tidak valid.');
        }

        return (int) $value;
    }

    private function teks(mixed $value, int $maks, string $label, bool $wajib): ?string
    {
        if ($value === null || $value === '') {
            if ($wajib) {
                throw new KesehatanException($label . ' wajib diisi.');
            }

            return null;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new KesehatanException($label . ' wajib berupa teks.');
        }
        $teks = trim(str_replace(["\r\n", "\r"], "\n", $value));
        if (mb_strlen($teks) > $maks) {
            throw new KesehatanException($label . ' maksimal ' . $maks . ' karakter.');
        }
        if ($teks === '' && $wajib) {
            throw new KesehatanException($label . ' wajib diisi.');
        }

        return $teks === '' ? null : $teks;
    }

    private function idOpsional(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value) && (int) $value <= 2147483647 ? (int) $value : 0;
    }

    private function halaman(mixed $value): int
    {
        return is_scalar($value) && preg_match('/^[1-9][0-9]{0,6}$/D', (string) $value) ? (int) $value : 1;
    }
}
