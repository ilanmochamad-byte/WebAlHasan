<?php

declare(strict_types=1);

namespace App\Psb;

use mysqli;

/**
 * Rincian biaya awal PSB (`biaya_psb.php`) — workstream keamanan PSB.
 *
 * Katalog di bawah DISALIN apa adanya dari `biaya_psb.php` lama: kategori,
 * nominal bawaan, dan komponen wajib per unit/jenis kelamin/alumni tidak
 * berubah, termasuk kategori "Tsanawi Non-SMK" untuk unit lain (biaya MI/RA
 * belum ditetapkan, keputusan AN). Yang berubah: harga dan total dihitung
 * ulang di server dari katalog ini, bukan dipercaya dari isian peramban;
 * pendaftar tetap memilih komponen wajib yang dibayar (keputusan AJ).
 */
final class BiayaPsb
{
    /** Batas wajar satu isian nominal pilihan (Rp). */
    public const MAKS_NOMINAL = 100_000_000;

    /**
     * @param array<string, mixed> $pendaftar baris `psb_pendaftar`
     * @return array{kategori:string, syahriyyah:int, infaq:int, psas:int, pramuka:int, seragam:bool, wajib:array<string,int>}
     */
    public static function katalog(array $pendaftar): array
    {
        $isAlumni = (stripos(strtolower((string) $pendaftar['sekolah_asal']), 'smp terpadu al hasan') !== false);
        $unit = (string) $pendaftar['jenjang_tujuan'];
        $jk = (string) $pendaftar['jenis_kelamin'];
        $s = 0;
        $i = 0;
        $psas = 0;
        $pramuka = 0;

        if ($unit === 'SMP Terpadu Al Hasan') {
            $kategori = 'SMP Terpadu Al Hasan';
            $s = 800000; $i = 1000000;
            if ($jk === 'L') { $psas = 190000; $pramuka = 215000; } else { $psas = 225000; $pramuka = 230000; }
            $wajib = ['Formulir Pendaftaran' => 150000, 'Administrasi Santri Baru' => 250000, 'Lemari' => 600000, 'Kitab' => 230000, 'Laundry (20 Kg)' => 100000, 'Biaya Ujian Tahunan' => 250000];
            if ($jk === 'L') {
                $wajib += ['Seragam Yayasan' => 165000, 'Batik SMP' => 85000, 'Seragam Olahraga' => 155000, 'JAS Almamater' => 250000, 'Seragam Muslim' => 150000, 'Sabuk PSAS & Pramuka' => 35000, 'Dasi' => 17500];
            } else {
                $wajib += ['Seragam Yayasan' => 170000, 'Batik SMP' => 85000, 'Seragam Olahraga' => 160000, 'JAS Almamater' => 250000, 'Seragam Muslimah' => 150000, 'Sabuk PSAS & Pramuka' => 35000, 'Kerudung' => 100000];
            }
        } elseif ($unit === 'SMK Terpadu Al Hasan') {
            if ($jk === 'L') { $psas = 200000; $pramuka = 200000; } else { $psas = 230000; $pramuka = 230000; }
            if ($isAlumni) {
                $kategori = 'SMK Terpadu Al Hasan (Alumni)';
                $s = 600000; $i = 0;
                $wajib = ['Administrasi Santri Baru' => 250000, 'Kitab' => 200000, 'Laundry' => 100000, 'Biaya Ujian Tahunan' => 250000, 'Seragam PDH SMK' => 231000, 'Seragam Olahraga' => 190000];
            } else {
                $kategori = 'SMK Terpadu Al Hasan (Baru)';
                $s = 800000; $i = 1000000;
                $wajib = ['Formulir Pendaftaran' => 150000, 'Administrasi Santri Baru' => 250000, 'Lemari' => 600000, 'Kitab' => 270000, 'Laundry (20 Kg)' => 100000, 'Biaya Ujian Tahunan' => 250000];
                if ($jk === 'L') {
                    $wajib += ['Seragam PDH SMK' => 231000, 'Seragam Olahraga' => 190000, 'JAS Almamater' => 250000, 'Seragam Muslim' => 150000];
                } else {
                    $wajib += ['Seragam PDH SMK' => 231000, 'Seragam Olahraga' => 190000, 'JAS Almamater' => 250000, 'Seragam Muslimah' => 150000];
                }
            }
        } else {
            if ($isAlumni) {
                $kategori = 'Tsanawi Non-SMK (Alumni)';
                $s = 600000; $i = 0;
                $wajib = ['Administrasi Santri Baru' => 250000, 'Kitab' => 200000, 'Laundry' => 100000, 'Biaya Ujian Tahunan' => 150000];
            } else {
                $kategori = 'Tsanawi Non-SMK (Baru)';
                $s = 800000; $i = 1000000;
                $wajib = ['Formulir Pendaftaran' => 150000, 'Administrasi Santri Baru' => 250000, 'Lemari' => 600000, 'Kitab' => 270000, 'Laundry (20 Kg)' => 100000, 'Biaya Ujian Tahunan' => 150000];
                if ($jk === 'L') {
                    $wajib += ['JAS Almamater' => 250000, 'Seragam Muslim' => 150000];
                } else {
                    $wajib += ['JAS Almamater' => 250000, 'Seragam Muslimah' => 150000];
                }
            }
        }

        return [
            'kategori' => $kategori, 'syahriyyah' => $s, 'infaq' => $i, 'psas' => $psas, 'pramuka' => $pramuka,
            // Isian seragam PSAS/Pramuka hanya tampil untuk SMP dan SMK Terpadu.
            'seragam' => $unit === 'SMP Terpadu Al Hasan' || $unit === 'SMK Terpadu Al Hasan',
            'wajib' => $wajib,
        ];
    }

    /**
     * Menyusun baris tagihan dari kiriman formulir. Komponen wajib hanya yang
     * ada di katalog, dengan harga katalog; total dihitung di server.
     *
     * @param array<string, mixed> $pendaftar
     * @param array<string, mixed> $post
     * @return array{kategori:string, syahriyyah:int, infaq:int, psas:int, pramuka:int, rincian:array<string,int>, total_wajib:int, total:int, metode:string}
     */
    public static function dariKiriman(array $pendaftar, array $post): array
    {
        $katalog = self::katalog($pendaftar);
        $nominal = static function (mixed $nilai, string $label): int {
            $teks = is_scalar($nilai) ? trim((string) $nilai) : '';
            if ($teks === '' || !preg_match('/^\d{1,9}$/', $teks) || (int) $teks > self::MAKS_NOMINAL) {
                throw new PsbException($label . ' harus berupa angka rupiah 0 sampai ' . number_format(self::MAKS_NOMINAL, 0, ',', '.') . '.');
            }

            return (int) $teks;
        };
        $s = $nominal($post['syahriyyah'] ?? null, 'Syahriyyah');
        $i = $nominal($post['infaq'] ?? null, 'Infaq bangunan');
        $psas = $katalog['seragam'] ? $nominal($post['seragam_psas'] ?? null, 'Seragam PSAS') : 0;
        $pramuka = $katalog['seragam'] ? $nominal($post['seragam_pramuka'] ?? null, 'Seragam Pramuka') : 0;
        $metode = (string) ($post['metode_pembayaran'] ?? '');
        if (!in_array($metode, ['Cash', 'Transfer'], true)) {
            throw new PsbException('Metode pembayaran tidak dikenal.');
        }

        // Formulir baru mengirim `wajib[]`; formulir lama (halaman tersimpan di
        // peramban) hanya mengirim JSON pilihan. Keduanya dicocokkan ke katalog.
        $pilihan = [];
        if (isset($post['wajib']) && is_array($post['wajib'])) {
            $pilihan = array_values(array_filter($post['wajib'], 'is_string'));
        } elseif (is_string($post['rincian_wajib_json'] ?? null)) {
            $json = json_decode((string) $post['rincian_wajib_json'], true);
            $pilihan = is_array($json) ? array_map('strval', array_keys($json)) : [];
        }
        $rincian = [];
        foreach ($katalog['wajib'] as $item => $harga) {
            if (in_array($item, $pilihan, true)) {
                $rincian[$item] = $harga;
            }
        }
        $totalWajib = array_sum($rincian);

        return [
            'kategori' => $katalog['kategori'], 'syahriyyah' => $s, 'infaq' => $i, 'psas' => $psas, 'pramuka' => $pramuka,
            'rincian' => $rincian, 'total_wajib' => $totalWajib, 'total' => $s + $i + $psas + $pramuka + $totalWajib, 'metode' => $metode,
        ];
    }

    /**
     * Simpan atau perbarui tagihan. Tagihan berstatus Lunas tidak diubah dari
     * halaman ini; bendahara membatalkan lunas (beralasan) lebih dahulu.
     *
     * @param array<string, mixed> $tagihan hasil `dariKiriman()`
     */
    public static function simpan(mysqli $db, string $noPendaftaran, array $tagihan): void
    {
        $stmt = $db->prepare('SELECT status_pembayaran FROM psb_pembayaran WHERE no_pendaftaran = ?');
        $stmt->bind_param('s', $noPendaftaran);
        $stmt->execute();
        $lama = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (is_array($lama) && $lama['status_pembayaran'] === 'Lunas') {
            throw new PsbException('Pembayaran sudah ditandai lunas oleh bendahara, sehingga rincian tidak dapat diubah lagi. Hubungi panitia PSB bila perlu koreksi.', [], 409);
        }

        $rincian = json_encode($tagihan['rincian'], JSON_UNESCAPED_UNICODE);
        $stmt = $db->prepare(
            'INSERT INTO psb_pembayaran (no_pendaftaran, kategori_biaya, syahriyyah, infaq, seragam_psas, seragam_pramuka, rincian_wajib, total_wajib, total_keseluruhan, metode_pembayaran)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE syahriyyah = VALUES(syahriyyah), infaq = VALUES(infaq), seragam_psas = VALUES(seragam_psas),
                seragam_pramuka = VALUES(seragam_pramuka), rincian_wajib = VALUES(rincian_wajib), total_wajib = VALUES(total_wajib),
                total_keseluruhan = VALUES(total_keseluruhan), metode_pembayaran = VALUES(metode_pembayaran)'
        );
        if ($stmt === false) {
            throw new PsbException('Rincian biaya tidak dapat disimpan. Coba lagi.', [], 500);
        }
        $stmt->bind_param('ssiiiisiis', $noPendaftaran, $tagihan['kategori'], $tagihan['syahriyyah'], $tagihan['infaq'],
            $tagihan['psas'], $tagihan['pramuka'], $rincian, $tagihan['total_wajib'], $tagihan['total'], $tagihan['metode']);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            throw new PsbException('Rincian biaya tidak dapat disimpan. Coba lagi.', [], 500);
        }
    }
}
