<?php

declare(strict_types=1);

namespace App\Audit;

use mysqli;
use Throwable;

final class AuditLogger
{
    public function __construct(private mysqli $db)
    {
    }

    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?int $actorUserId = null
    ): bool {
        try {
            // Katalog/kategori/ambang adalah data master admin tanpa tabel revisi;
            // nilai sebelum/sesudahnya harus tetap terbaca di audit.
            if (str_starts_with($action, 'v3.') && !in_array($entityType, ['v3_katalog', 'v3_kategori', 'v3_ambang'], true)) {
                $before = $before === null ? null : $this->v3Metadata($before);
                $after = $after === null ? null : $this->v3Metadata($after);
            }
            // Kesehatan santri (PRD V5): isi klinis tidak pernah masuk log umum,
            // sekalipun pemanggil keliru mengirimkannya.
            if (str_starts_with($action, 'kesehatan.')) {
                $before = $before === null ? null : $this->tanpaIsiKlinis($before);
                $after = $after === null ? null : $this->tanpaIsiKlinis($after);
            }
            // Keuangan santri/BSA (PRD V6 §5.4): nominal, saldo, referensi,
            // tujuan, dan catatan tidak masuk log umum. Hanya kunci metadata
            // yang lolos; jejak uang lengkap berada di ledger BSA sendiri.
            if (str_starts_with($action, 'bsa.')) {
                $before = $before === null ? null : $this->metadataBsa($before);
                $after = $after === null ? null : $this->metadataBsa($after);
            }
            // Pantauan Keamanan (penugasan lintas guru/pengurus Fase 3): jejak
            // akses baca hanya memuat ID, status, asal, dan jumlah — tidak pernah
            // alasan izin, uraian pelanggaran, atau isi konseling.
            if (str_starts_with($action, 'keamanan.')) {
                $before = $before === null ? null : $this->metadataKeamanan($before);
                $after = $after === null ? null : $this->metadataKeamanan($after);
            }
            $actorUserId ??= isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            $beforeJson = $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $afterJson = $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $statement = $this->db->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            if ($statement === false) {
                return false;
            }
            $statement->bind_param('ississss', $actorUserId, $action, $entityType, $entityId, $beforeJson, $afterJson, $ip, $userAgent);
            $saved = $statement->execute();
            $statement->close();

            return $saved;
        } catch (Throwable $exception) {
            error_log('Audit log gagal disimpan.');
            return false;
        }
    }

    private function metadataBsa(array $data): array
    {
        $boleh = ['tagihan_id', 'transaksi_id', 'santri_id', 'jenis', 'metode', 'status', 'periode', 'penugasan_id', 'sumber', 'kanal', 'versi', 'jumlah_komponen',
            // Fase 2: ID dan jumlah saja (tarif, penyesuaian, penerbitan massal, pembayaran).
            'tarif_id', 'tarif_diganti_id', 'jenjang', 'berlaku_mulai', 'penyesuaian_id', 'penerbitan_id', 'pembayaran_id',
            'jumlah_sasaran', 'jumlah_terbit', 'jumlah_sudah_ada', 'jumlah_dilewati',
            // Fase 3: ID saja (setoran transfer menunggu, transaksi pembalik).
            'setoran_transfer_id', 'pembalik_dari_id'];

        return array_intersect_key($data, array_flip($boleh));
    }

    private function metadataKeamanan(array $data): array
    {
        $boleh = ['pengajuan_id', 'pelanggaran_id', 'lampiran_id', 'kasus_id', 'santri_id', 'pertemuan_id', 'asal', 'status', 'jumlah', 'filter'];
        $hasil = array_intersect_key($data, array_flip($boleh));
        if (isset($hasil['filter'])) {
            // Saringan cetak izin: kunci terkendali layanan, nilai skalar saja.
            $hasil['filter'] = is_array($hasil['filter'])
                ? array_filter($hasil['filter'], static fn ($v): bool => is_scalar($v))
                : null;
        }

        return $hasil;
    }

    private function tanpaIsiKlinis(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (in_array($key, ['keluhan', 'catatan_klinis', 'tindakan', 'alasan', 'catatan'], true)) {
                continue;
            }
            $result[$key] = is_array($value) ? $this->tanpaIsiKlinis($value) : $value;
        }
        return $result;
    }

    /** Riwayat isi tetap di tabel bisnis privat; audit V3 menyimpan jejak hash. */
    private function v3Metadata(array $data): array
    {
        $sensitive = ['tujuan','ringkasan','ringkasan_internal','ringkasan_penutupan','hasil','tindak_lanjut','catatan','uraian','saksi','santri_nama','nama_santri','alasan','alasan_revisi','alasan_revisi_terakhir','alasan_pembatalan','alasan_penjadwalan_ulang','tempat'];
        $result = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitive, true)) {
                $result[$key.'_sha256'] = $value === null ? null : hash('sha256', (string)$value);
            } else {
                $result[$key] = is_array($value) ? $this->v3Metadata($value) : $value;
            }
        }
        return $result;
    }
}
