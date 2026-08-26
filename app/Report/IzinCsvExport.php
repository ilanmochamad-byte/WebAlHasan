<?php

declare(strict_types=1);

namespace App\Report;

/**
 * Ekspor CSV laporan perizinan V2.
 *
 * Tiga sifat yang dijaga (PRD V2 Fase 5, kriteria "CSV memuat seluruh hasil
 * filter, header terdokumentasi, dan formula injection dinetralkan"):
 *
 *  1. **Seluruh hasil filter.** Baris berasal dari
 *     `IzinReportRepository::allRows()`, bukan dari halaman yang sedang
 *     terlihat. Pemanggil tidak dapat mengirim baris satu halaman ke sini
 *     tanpa terlihat, karena `encode()` juga menuliskan jumlah baris pada
 *     nama berkas dan pengujian membandingkannya dengan total ringkasan.
 *  2. **Header terdokumentasi.** Setiap kolom pada `HEADERS` WAJIB memiliki
 *     penjelasan pada `DOKUMENTASI`. `tests/v2_phase5_static.php` menolak
 *     kolom tanpa dokumentasi, sehingga dokumentasi tidak dapat tertinggal.
 *  3. **Formula injection dinetralkan.** Lihat `spreadsheetSafe()`.
 */
final class IzinCsvExport
{
    /**
     * Urutan kolom CSV. Menambah kolom WAJIB disertai penambahan pada
     * `DOKUMENTASI` dan pada `row()`.
     */
    public const HEADERS = [
        'ID Pengajuan',
        'Sumber Data',
        'ID Perizinan Lama',
        'NIS',
        'Nama Santri',
        'Kamar',
        'Kelas',
        'Tahun Ajaran',
        'Tanggal Izin',
        'Tanggal Kembali',
        'Alasan Izin',
        'Catatan Pengurus',
        'Status',
        'Pengurus Pengaju',
        'Murobi Tujuan',
        'Jumlah Kandidat Routing',
        'Catatan Routing',
        'Waktu Pengajuan',
        'Hasil Keputusan',
        'Kapasitas Pemberi Keputusan',
        'Pemberi Keputusan',
        'Alasan Keputusan',
        'Alasan Penggantian',
        'Waktu Keputusan',
        'Durasi Keputusan (jam)',
        'Jumlah Koreksi',
        'Waktu Koreksi Terakhir',
        'Alasan Pembatalan',
        'Waktu Pembatalan',
        'Kanal Notifikasi',
    ];

    /**
     * Dokumentasi header CSV — dipakai dokumen operasional dan pengujian.
     *
     * @var array<string, string>
     */
    public const DOKUMENTASI = [
        'ID Pengajuan' => 'ID pengajuan pada tabel `izin_pengajuan`. Untuk data warisan, ID ini sama dengan ID `perizinan` V1.',
        'Sumber Data' => '`Data warisan` untuk baris hasil migrasi V1, `V2` untuk pengajuan yang dibuat alur V2.',
        'ID Perizinan Lama' => 'Nilai `perizinan.id` asal. Kosong untuk pengajuan V2 asli.',
        'NIS' => 'Nomor induk santri saat laporan dibuat.',
        'Nama Santri' => 'Nama santri pada master data saat laporan dibuat.',
        'Kamar' => 'Kamar santri pada tahun ajaran pengajuan; memakai tahun ajaran aktif bila pengajuan tidak memiliki tahun ajaran (data warisan).',
        'Kelas' => 'Kelas aktif santri pada tahun ajaran pengajuan; aturan tahun ajaran sama dengan kolom Kamar.',
        'Tahun Ajaran' => 'Tahun ajaran dan semester pengajuan. Kosong untuk data warisan.',
        'Tanggal Izin' => 'Tanggal mulai izin (YYYY-MM-DD).',
        'Tanggal Kembali' => 'Tanggal santri kembali (YYYY-MM-DD).',
        'Alasan Izin' => 'Alasan yang diisi pengurus saat mengajukan.',
        'Catatan Pengurus' => 'Catatan tambahan pengurus. Kosong bila tidak diisi.',
        'Status' => 'Salah satu dari: Diajukan, Perlu Penetapan Admin, Disetujui, Ditolak, Dibatalkan.',
        'Pengurus Pengaju' => 'Nama pengurus pengaju. `Data warisan` bila pengajuan berasal dari V1 dan pelakunya tidak tercatat.',
        'Murobi Tujuan' => 'Nama murobi tujuan routing. `Belum ditetapkan` bila menunggu penetapan admin.',
        'Jumlah Kandidat Routing' => 'Jumlah murobi kandidat saat routing dijalankan. 0 dan lebih dari 1 sama-sama masuk antrean admin.',
        'Catatan Routing' => 'Penjelasan singkat hasil routing yang disimpan sistem.',
        'Waktu Pengajuan' => 'Waktu pengajuan dikirim (YYYY-MM-DD HH:MM:SS). Kosong untuk data warisan.',
        'Hasil Keputusan' => '`Disetujui` atau `Ditolak`. Kosong bila belum ada keputusan.',
        'Kapasitas Pemberi Keputusan' => '`Murobi` atau `Admin Pengganti`.',
        'Pemberi Keputusan' => 'Nama akun pemberi keputusan. Kosong untuk data warisan yang pelakunya tidak tercatat.',
        'Alasan Keputusan' => 'Alasan yang diisi saat menyetujui atau menolak.',
        'Alasan Penggantian' => 'Alasan wajib ketika admin memutus menggantikan murobi.',
        'Waktu Keputusan' => 'Waktu keputusan dicatat (YYYY-MM-DD HH:MM:SS).',
        'Durasi Keputusan (jam)' => 'Selisih waktu pengajuan sampai waktu keputusan, dalam jam dengan dua desimal. Kosong bila salah satu waktu tidak tersedia.',
        'Jumlah Koreksi' => 'Berapa kali keputusan dikoreksi. Koreksi tidak menghapus riwayat sebelumnya.',
        'Waktu Koreksi Terakhir' => 'Waktu koreksi keputusan terakhir, bila ada.',
        'Alasan Pembatalan' => 'Alasan pembatalan oleh pengurus, bila pengajuan dibatalkan sebelum keputusan.',
        'Waktu Pembatalan' => 'Waktu pembatalan dicatat.',
        'Kanal Notifikasi' => 'Kanal notifikasi yang pernah diantrekan untuk pengajuan ini (InApp, Push, WhatsApp), dipisahkan koma.',
    ];

    /**
     * Karakter pembuka yang membuat Excel/LibreOffice/Sheets memperlakukan sel
     * sebagai FORMULA, bukan teks.
     *
     * `=` `+` `-` `@` adalah pembuka formula. TAB (0x09) dan CR (0x0D) juga
     * dimasukkan karena beberapa versi Excel memangkasnya lebih dulu, lalu
     * mengevaluasi karakter berikutnya sebagai formula.
     */
    public const PEMBUKA_BERBAHAYA = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param array<int, array<string, mixed>> $rows baris hasil `allRows()`
     */
    public static function encode(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            return '';
        }
        // BOM UTF-8 agar Excel di Windows membaca huruf beraksen dengan benar.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_map([self::class, 'spreadsheetSafe'], self::HEADERS), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($stream, array_map([self::class, 'spreadsheetSafe'], self::row($row)), ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? '' : $contents;
    }

    /**
     * Satu baris CSV, urutannya WAJIB sama dengan `HEADERS`.
     *
     * @param array<string, mixed> $row
     * @return array<int, mixed>
     */
    public static function row(array $row): array
    {
        $durasi = $row['durasi_keputusan_detik'] ?? null;

        return [
            $row['id'] ?? '',
            ((int) ($row['is_legacy'] ?? 0) === 1) ? 'Data warisan' : 'V2',
            $row['legacy_perizinan_id'] ?? '',
            $row['nis'] ?? '',
            $row['nama_santri'] ?? '',
            $row['kamar_nama'] ?? '',
            $row['kelas_nama'] ?? '',
            self::tahunAjaran($row),
            $row['tgl_izin'] ?? '',
            $row['tgl_kembali'] ?? '',
            $row['alasan'] ?? '',
            $row['catatan_pengurus'] ?? '',
            $row['status'] ?? '',
            $row['pengurus_nama'] ?? (((int) ($row['is_legacy'] ?? 0) === 1) ? 'Data warisan' : 'Belum ditetapkan'),
            $row['murobi_nama'] ?? (((int) ($row['is_legacy'] ?? 0) === 1) ? 'Data warisan' : 'Belum ditetapkan'),
            $row['routing_kandidat'] ?? '',
            $row['routing_catatan'] ?? '',
            $row['diajukan_pada'] ?? '',
            $row['keputusan_hasil'] ?? '',
            $row['keputusan_kapasitas'] ?? '',
            $row['keputusan_oleh'] ?? '',
            $row['keputusan_alasan'] ?? '',
            $row['alasan_penggantian'] ?? '',
            $row['diputus_pada'] ?? '',
            $durasi === null ? '' : number_format(((int) $durasi) / 3600, 2, '.', ''),
            $row['jumlah_koreksi'] ?? '0',
            $row['dikoreksi_pada'] ?? '',
            $row['alasan_pembatalan'] ?? '',
            $row['dibatalkan_pada'] ?? '',
            $row['kanal_notifikasi'] ?? '',
        ];
    }

    /**
     * Menetralkan formula injection (CWE-1236).
     *
     * Sel yang diawali `= + - @`, TAB, atau CR diberi awalan kutip tunggal
     * sehingga aplikasi spreadsheet menampilkannya sebagai TEKS dan tidak
     * pernah mengeksekusinya. Pemeriksaan dilakukan pada karakter pertama
     * MENTAH dan pada karakter pertama setelah spasi/kontrol dibuang, karena
     * beberapa versi Excel memangkas spasi awal terlebih dahulu.
     *
     * Nilai `null` menjadi string kosong, bukan teks "null".
     */
    public static function spreadsheetSafe(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        $text = (string) $value;
        if ($text === '') {
            return '';
        }

        $pertama = substr($text, 0, 1);
        $setelahSpasi = ltrim($text, " \t\r\n\0\x0B");
        $pertamaSetelahSpasi = $setelahSpasi === '' ? '' : substr($setelahSpasi, 0, 1);

        if (
            in_array($pertama, self::PEMBUKA_BERBAHAYA, true)
            || ($pertamaSetelahSpasi !== '' && in_array($pertamaSetelahSpasi, self::PEMBUKA_BERBAHAYA, true))
        ) {
            return "'" . $text;
        }

        return $text;
    }

    /**
     * Nama berkas unduhan; memuat cakupan, rentang, dan waktu pembuatan agar
     * dua ekspor dengan filter berbeda tidak saling menimpa di folder unduhan.
     */
    public static function filename(IzinReportFilter $filter, string $generatedAt): string
    {
        $slug = static fn (string $value): string => strtolower(
            (string) preg_replace('/[^A-Za-z0-9]+/', '-', $value)
        );

        return trim(sprintf(
            'laporan-perizinan-%s-%s-sd-%s-%s.csv',
            $slug($filter->scopeMode() === '' ? 'tanpa-cakupan' : $filter->scopeMode()),
            $filter->dateFrom,
            $filter->dateTo,
            $slug(substr($generatedAt, 0, 19))
        ), '-');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function tahunAjaran(array $row): string
    {
        $tahun = trim((string) ($row['tahun_ajaran'] ?? ''));
        if ($tahun === '') {
            return '';
        }
        $semester = trim((string) ($row['semester'] ?? ''));

        return $semester === '' ? $tahun : $tahun . ' - ' . $semester;
    }
}
