<?php
declare(strict_types=1);
namespace App\V4;

use App\Audit\AuditLogger;
use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use mysqli;
use Throwable;

/** Satu transaksi per isian mapel/santri; kunci rencana juga menyelaraskan perubahan target. */
final class NilaiService
{
    public function __construct(private mysqli $db, private Capabilities $caps, private AuditLogger $audit) {}

    private function query(string $sql, string $types = '', array $args = []): array
    {
        $s = $this->db->prepare($sql);
        if (!$s) throw new V4Exception('Basis data tidak tersedia.', 503);
        if ($types !== '') $s->bind_param($types, ...$args);
        if (!$s->execute()) throw new V4Exception('Perubahan gagal disimpan.', 503);
        $result = $s->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $s->close();
        return $rows;
    }

    private function capability(bool $pengganti): string
    {
        return $pengganti ? PenugasanJenis::CAP_NILAI_PENGGANTI : PenugasanJenis::CAP_NILAI_INPUT;
    }

    private function allowed(array $user, array $plan, bool $pengganti): bool
    {
        return $this->caps->featureAppliesTo($user, $this->capability($pengganti), [
            'tahun_ajaran_id' => (int)$plan['tahun_ajaran_id'], 'kelas_id' => (int)$plan['kelas_id'],
            'mata_pelajaran_id' => (int)$plan['mata_pelajaran_id'],
        ]);
    }

    public function options(array $user, bool $pengganti = false): array
    {
        if (!$this->caps->hasFeature($user, $this->capability($pengganti))) throw new V4Exception('Akses nilai ditolak.', 403);
        $rows = $this->query('SELECT rm.id,rm.mata_pelajaran_id,r.tahun_ajaran_id,r.kelas_id,k.nama_kelas,t.tahun,t.semester,mp.nama AS mata_pelajaran FROM v4_rencana_mapel rm JOIN v4_rencana r ON r.id=rm.rencana_id JOIN kelas k ON k.id=r.kelas_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id ORDER BY t.id DESC,k.nama_kelas,mp.nama');
        return array_values(array_filter($rows, fn($r) => $this->allowed($user, $r, $pengganti)));
    }

    private function plan(array $user, int $id, bool $pengganti): array
    {
        if (!$this->caps->hasFeature($user, $this->capability($pengganti))) throw new V4Exception('Akses nilai ditolak.', 403);
        $p = $this->query('SELECT rm.*,r.tahun_ajaran_id,r.kelas_id,k.nama_kelas,t.tahun,t.semester,mp.nama AS mata_pelajaran FROM v4_rencana_mapel rm JOIN v4_rencana r ON r.id=rm.rencana_id JOIN kelas k ON k.id=r.kelas_id JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id WHERE rm.id=?', 'i', [$id])[0] ?? null;
        if (!$p || !$this->allowed($user, $p, $pengganti)) throw new V4Exception('Mapel, kelas, atau semester di luar cakupan.', 403);
        return $p;
    }

    private function students(array $p): array
    {
        // Penempatan semester asal (termasuk riwayat selesai), bukan kelas aktif saat ini.
        return $this->query('SELECT s.id,s.nama_santri FROM santri s WHERE EXISTS (SELECT 1 FROM plotting_kelas pk WHERE pk.id_santri=s.id AND pk.id_kelas=? AND pk.id_tahun=?) OR EXISTS (SELECT 1 FROM v4_nilai n WHERE n.santri_id=s.id AND n.rencana_mapel_id=?) ORDER BY s.nama_santri,s.id', 'iii', [(int)$p['kelas_id'], (int)$p['tahun_ajaran_id'], (int)$p['id']]);
    }

    public static function calculate(array $rows, float $target = 70): array
    {
        $harian = []; $tengah = null; $akhir = null;
        foreach ($rows as $r) {
            if ($r['nilai'] === null || $r['nilai'] === '') continue;
            if ($r['komponen'] === 'Harian') $harian[] = (float)$r['nilai'];
            elseif ($r['komponen'] === 'Tengah') $tengah = (float)$r['nilai'];
            elseif ($r['komponen'] === 'Akhir') $akhir = (float)$r['nilai'];
        }
        $h = $harian ? array_sum($harian) / count($harian) : null;
        $n = $h !== null && $tengah !== null && $akhir !== null ? $h * .4 + $tengah * .3 + $akhir * .3 : null;
        $display = $n === null ? null : (int)round($n, 0, PHP_ROUND_HALF_UP);
        return ['kelengkapan' => $n === null ? 'Belum lengkap' : 'Lengkap', 'rata_harian' => $h,
            'nilai_internal' => $n === null ? null : round($n, 2, PHP_ROUND_HALF_UP),
            'nilai_tampil' => $display, 'predikat' => $display === null ? null : ($display >= 90 ? 'A' : ($display >= 80 ? 'B' : ($display >= 70 ? 'C' : 'D'))),
            'target_ketuntasan' => $target, 'tuntas' => $n === null ? null : $n >= $target];
    }

    public function read(array $user, int $mapel, int $santri = 0, bool $pengganti = false): array
    {
        $p = $this->plan($user, $mapel, $pengganti);
        $students = $this->students($p);
        if ($santri && !in_array($santri, array_map('intval', array_column($students, 'id')), true)) throw new V4Exception('Santri di luar kelas dan semester.', 403);
        $rows = $santri ? $this->query('SELECT * FROM v4_nilai WHERE rencana_mapel_id=? AND santri_id=? ORDER BY komponen,nomor_harian', 'ii', [$mapel, $santri]) : [];
        $history = $santri ? $this->query('SELECT h.* FROM v4_nilai_riwayat h JOIN v4_nilai n ON n.id=h.nilai_id WHERE n.rencana_mapel_id=? AND n.santri_id=? ORDER BY h.id DESC', 'ii', [$mapel, $santri]) : [];
        return ['rencana_mapel' => $p, 'santri' => $students, 'nilai' => $rows, 'riwayat' => $history, 'hasil' => self::calculate($rows, (float)$p['target_ketuntasan'])];
    }

    /** Payload mencakup seluruh komponen tersimpan; versi 0 hanya untuk komponen baru. */
    public function save(array $user, array $input, bool $pengganti = false): array
    {
        $mapel = (int)($input['rencana_mapel_id'] ?? 0); $santri = (int)($input['santri_id'] ?? 0);
        $p = $this->plan($user, $mapel, $pengganti);
        $reason = $input['alasan'] ?? '';
        if (!is_string($reason) || mb_strlen($reason) > 1000) throw new V4Exception('Alasan tidak valid.');
        $capacity = (new V4Access($this->caps))->nilaiInput($user, (int)$p['tahun_ajaran_id'], (int)$p['kelas_id'], (int)$p['mata_pelajaran_id'], $pengganti, $reason);
        $action = $input['action'] ?? 'draf';
        if (!in_array($action, ['draf', 'serahkan', 'koreksi'], true)) throw new V4Exception('Tindakan tidak valid.');
        if ($action === 'koreksi' && trim($reason) === '') throw new V4Exception('Alasan koreksi wajib diisi.');
        $items = $input['komponen'] ?? null; $data = [];
        if (!is_array($items) || !$items || count($items) > 102) throw new V4Exception('Isi paling sedikit satu komponen, maksimal 100 harian.');
        foreach ($items as $item) {
            if (!is_array($item)) throw new V4Exception('Komponen tidak valid.');
            $kind = $item['komponen'] ?? ''; $num = filter_var($item['nomor_harian'] ?? 0, FILTER_VALIDATE_INT);
            $version = filter_var($item['version'] ?? null, FILTER_VALIDATE_INT);
            $value = $item['nilai'] ?? null; $note = $item['catatan'] ?? '';
            if (!in_array($kind, ['Harian', 'Tengah', 'Akhir'], true) || $num === false || ($kind === 'Harian' ? $num < 1 || $num > 100 : $num !== 0) || $version === false || $version < 0) throw new V4Exception('Jenis, urutan, atau versi komponen tidak valid.');
            if (!is_string($note) || mb_strlen($note) > 2000) throw new V4Exception('Catatan maksimal 2000 karakter.');
            // Tidak mengubah nilai kosong menjadi nol. Input dibatasi tepat dua desimal.
            if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', (string)$value) || (float)$value > 100) throw new V4Exception('Nilai wajib berupa angka 0–100, maksimal dua desimal.');
            $key = $kind . ':' . $num;
            if (isset($data[$key])) throw new V4Exception('Komponen ganda.', 409);
            $data[$key] = ['komponen' => $kind, 'nomor_harian' => $num, 'version' => $version, 'nilai' => (float)$value, 'catatan' => $note];
        }
        $this->db->begin_transaction();
        try {
            // Selalu kunci parent dahulu, sama dengan RencanaService. Serialisasi juga mencakup insert awal.
            $this->query('SELECT id FROM v4_rencana WHERE id=? FOR UPDATE', 'i', [(int)$p['rencana_id']]);
            $this->query('SELECT id FROM v4_rencana_mapel WHERE id=? FOR UPDATE', 'i', [$mapel]);
            $p = $this->plan($user, $mapel, $pengganti);
            if (!$this->query("SELECT r.id FROM v4_rencana r JOIN kelas k ON k.id=r.kelas_id AND k.is_active=1 AND k.archived_at IS NULL JOIN tahun_ajaran t ON t.id=r.tahun_ajaran_id AND t.status='Aktif' AND t.archived_at IS NULL JOIN v4_rencana_mapel rm ON rm.rencana_id=r.id JOIN mata_pelajaran mp ON mp.id=rm.mata_pelajaran_id AND mp.is_active=1 AND mp.archived_at IS NULL WHERE rm.id=?", 'i', [$mapel])) throw new V4Exception('Rencana kelas, semester, atau mata pelajaran tidak aktif.', 409);
            if (!$santri || !in_array($santri, array_map('intval', array_column($this->students($p), 'id')), true)) throw new V4Exception('Santri di luar kelas dan semester.', 403);
            $old = $this->query('SELECT * FROM v4_nilai WHERE rencana_mapel_id=? AND santri_id=? FOR UPDATE', 'ii', [$mapel, $santri]);
            $indexed = [];
            foreach ($old as $r) $indexed[$r['komponen'] . ':' . $r['nomor_harian']] = $r;
            foreach ($indexed as $key => $r) if (!isset($data[$key])) throw new V4Exception('Muat ulang seluruh komponen sebelum menyimpan; nilai lama tidak boleh dihapus.', 409);
            foreach ($data as $key => $r) {
                $previous = $indexed[$key] ?? null;
                if ($r['version'] !== (int)($previous['version'] ?? 0)) throw new V4Exception('Versi usang. Muat ulang nilai sebelum melanjutkan.', 409);
                if ($previous && !in_array($previous['status'], ['Draf', 'Dikirim'], true)) throw new V4Exception('Status nilai belum mengizinkan perubahan pada fase ini.', 409);
                if ($previous && $previous['status'] === 'Dikirim' && $action !== 'koreksi') throw new V4Exception('Nilai diserahkan hanya dapat dikoreksi dengan alasan.', 409);
                if ($previous && ((float)$previous['nilai'] !== $r['nilai'] || (string)$previous['catatan'] !== $r['catatan']) && $action !== 'koreksi') throw new V4Exception('Perubahan nilai tersimpan memerlukan tindakan koreksi dan alasan.');
            }
            if ($action === 'serahkan' && self::calculate(array_values($data))['kelengkapan'] !== 'Lengkap') throw new V4Exception('Nilai Belum lengkap; lengkapi harian, tengah, dan akhir sebelum menyerahkan.');
            $uid = (int)$user['id']; $status = $action === 'serahkan' ? 'Dikirim' : 'Draf';
            foreach ($data as $key => $r) {
                $previous = $indexed[$key] ?? null; $version = $r['version'] + 1;
                if ($previous) {
                    $id = (int)$previous['id'];
                    $this->query('UPDATE v4_nilai SET nilai=?,catatan=?,status=?,version=?,pelaku_user_id=?,kapasitas=?,alasan=? WHERE id=?', 'dssiissi', [$r['nilai'], $r['catatan'], $status, $version, $uid, $capacity, $reason, $id]);
                } else {
                    $this->query('INSERT INTO v4_nilai (rencana_mapel_id,santri_id,komponen,nomor_harian,nilai,catatan,status,version,pelaku_user_id,kapasitas,alasan) VALUES (?,?,?,?,?,?,?,?,?,?,?)', 'iisidssiiss', [$mapel, $santri, $r['komponen'], $r['nomor_harian'], $r['nilai'], $r['catatan'], $status, $version, $uid, $capacity, $reason]);
                    $id = (int)$this->db->insert_id;
                }
                $snapshot = $this->query('SELECT * FROM v4_nilai WHERE id=?', 'i', [$id])[0];
                $json = json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $this->query('INSERT INTO v4_nilai_riwayat (nilai_id,version,snapshot_json,pelaku_user_id,kapasitas,alasan) VALUES (?,?,?,?,?,?)', 'iisiss', [$id, $version, $json, $uid, $capacity, $reason]);
                // Isi nilai/catatan/alasan hanya di riwayat privat, audit umum hanya metadata.
                if (!$this->audit->log('v4.nilai.' . $action, 'v4_nilai', $id, $previous ? ['version' => (int)$previous['version']] : null, ['version' => $version, 'kapasitas' => $capacity, 'status' => $status, 'alasan_tercatat' => trim($reason) !== ''], $uid)) throw new V4Exception('Audit gagal; perubahan dibatalkan.', 503);
            }
            $result = $this->read($user, $mapel, $santri, $pengganti);
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollback();
            if ($e instanceof V4Exception) throw $e;
            if ($e instanceof \mysqli_sql_exception && in_array((int)$e->getCode(), [1062, 1213, 1205], true)) throw new V4Exception('Konflik penyimpanan. Muat ulang nilai.', 409);
            throw new V4Exception('Penyimpanan gagal; perubahan dibatalkan.', 503);
        }
    }
}
