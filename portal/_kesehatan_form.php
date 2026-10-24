<?php

declare(strict_types=1);

/**
 * Bagian formulir bersama "Catat kunjungan" dan "Koreksi" (PRD V5 Fase 2):
 * obat yang diberikan, rujukan, dan tindak lanjut.
 *
 * Berjalan tanpa JavaScript: setiap bagian menampilkan baris yang sudah ada
 * ditambah beberapa baris kosong. Baris kosong diabaikan server; baris lama
 * yang dicentang "Hapus" dibatalkan pada versi baru (tidak dihapus dari
 * riwayat). Isi formulir yang gagal divalidasi dirender ulang dari POST,
 * tidak disimpan ke sesi. Tidak ada saran obat/dosis: semua isian ditulis
 * petugas.
 */

/** @param mixed $baris */
function kesehatan_form_baris(mixed $baris): array
{
    if (!is_array($baris)) {
        return [];
    }
    $hasil = [];
    foreach ($baris as $r) {
        if (is_array($r)) {
            $hasil[] = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $r);
        }
    }

    return $hasil;
}

/**
 * @param array<int, array<string, string>> $baris baris terisi (dari POST atau rincian tersimpan)
 * @param array<string, int> $kosong jumlah baris kosong tambahan per bagian
 */
function kesehatan_form_rincian(array $obat, array $rujukan, array $tindakLanjut, array $kosong): void
{
    $nilai = static fn (array $r, string $k): string => ah_e($r[$k] ?? '');
    $hapus = static function (string $bagian, int $i, array $r): string {
        if (($r['id'] ?? '') === '') {
            return '';
        }

        return '<input type="hidden" name="' . $bagian . '[' . $i . '][id]" value="' . ah_e($r['id']) . '">'
            . '<div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="' . $bagian . '-' . $i . '-hapus" name="' . $bagian . '[' . $i . '][hapus]" value="1"'
            . (($r['hapus'] ?? '') === '1' ? ' checked' : '') . '><label class="form-check-label" for="' . $bagian . '-' . $i . '-hapus">Hapus baris ini pada versi baru</label></div>';
    };
    for ($n = 0; $n < ($kosong['obat'] ?? 0); $n++) {
        $obat[] = [];
    }
    for ($n = 0; $n < ($kosong['rujukan'] ?? 0); $n++) {
        $rujukan[] = [];
    }
    for ($n = 0; $n < ($kosong['tindak_lanjut'] ?? 0); $n++) {
        $tindakLanjut[] = [];
    }
    ?>
    <fieldset class="mb-4" aria-describedby="obat-bantu">
        <legend class="h6">Obat yang diberikan</legend>
        <p class="form-text mt-0" id="obat-bantu">Catat hanya obat yang benar-benar diberikan. Baris kosong diabaikan. Tidak ada perhitungan stok.</p>
        <?php foreach ($obat as $i => $r): ?>
            <div class="border rounded p-2 mb-2">
                <div class="row g-2">
                    <div class="col-12 col-md-4"><label class="form-label small" for="obat-<?= (int) $i ?>-nama">Nama obat</label>
                        <input class="form-control" type="text" id="obat-<?= (int) $i ?>-nama" name="obat[<?= (int) $i ?>][nama_obat]" maxlength="200" value="<?= $nilai($r, 'nama_obat') ?>"></div>
                    <div class="col-5 col-md-2"><label class="form-label small" for="obat-<?= (int) $i ?>-jumlah">Jumlah</label>
                        <input class="form-control" type="text" inputmode="decimal" id="obat-<?= (int) $i ?>-jumlah" name="obat[<?= (int) $i ?>][jumlah]" maxlength="10" value="<?= $nilai($r, 'jumlah') ?>"></div>
                    <div class="col-7 col-md-2"><label class="form-label small" for="obat-<?= (int) $i ?>-satuan">Satuan</label>
                        <input class="form-control" type="text" id="obat-<?= (int) $i ?>-satuan" name="obat[<?= (int) $i ?>][satuan]" maxlength="40" placeholder="tablet, ml, …" value="<?= $nilai($r, 'satuan') ?>"></div>
                    <div class="col-12 col-md-4"><label class="form-label small" for="obat-<?= (int) $i ?>-waktu">Waktu pemberian</label>
                        <input class="form-control" type="datetime-local" id="obat-<?= (int) $i ?>-waktu" name="obat[<?= (int) $i ?>][waktu_pemberian]" value="<?= ah_e(kesehatan_input_waktu($r['waktu_pemberian'] ?? '')) ?>"></div>
                    <div class="col-12"><label class="form-label small" for="obat-<?= (int) $i ?>-petunjuk">Petunjuk yang ditulis petugas</label>
                        <input class="form-control" type="text" id="obat-<?= (int) $i ?>-petunjuk" name="obat[<?= (int) $i ?>][petunjuk]" maxlength="500" value="<?= $nilai($r, 'petunjuk') ?>"></div>
                </div>
                <?= $hapus('obat', $i, $r) ?>
            </div>
        <?php endforeach; ?>
        <p class="form-text">Waktu pemberian kosong = waktu kunjungan. Butuh baris lebih banyak? Simpan dahulu, lalu tambahkan lewat koreksi.</p>
    </fieldset>

    <fieldset class="mb-4">
        <legend class="h6">Rujukan ke luar klinik</legend>
        <?php foreach ($rujukan as $i => $r): ?>
            <div class="border rounded p-2 mb-2">
                <div class="row g-2">
                    <div class="col-12 col-md-8"><label class="form-label small" for="rujukan-<?= (int) $i ?>-tujuan">Tujuan rujukan</label>
                        <input class="form-control" type="text" id="rujukan-<?= (int) $i ?>-tujuan" name="rujukan[<?= (int) $i ?>][tujuan]" maxlength="200" value="<?= $nilai($r, 'tujuan') ?>"></div>
                    <div class="col-12 col-md-4"><label class="form-label small" for="rujukan-<?= (int) $i ?>-tanggal">Tanggal rujukan</label>
                        <input class="form-control" type="date" id="rujukan-<?= (int) $i ?>-tanggal" name="rujukan[<?= (int) $i ?>][tanggal_rujukan]" value="<?= $nilai($r, 'tanggal_rujukan') ?>"></div>
                    <div class="col-12"><label class="form-label small" for="rujukan-<?= (int) $i ?>-alasan">Alasan/catatan petugas</label>
                        <textarea class="form-control" id="rujukan-<?= (int) $i ?>-alasan" name="rujukan[<?= (int) $i ?>][alasan]" rows="2" maxlength="2000"><?= $nilai($r, 'alasan') ?></textarea></div>
                    <div class="col-12 col-md-8"><label class="form-label small" for="rujukan-<?= (int) $i ?>-hasil">Hasil (bila sudah diketahui)</label>
                        <textarea class="form-control" id="rujukan-<?= (int) $i ?>-hasil" name="rujukan[<?= (int) $i ?>][hasil]" rows="2" maxlength="4000"><?= $nilai($r, 'hasil') ?></textarea></div>
                    <div class="col-12 col-md-4"><label class="form-label small" for="rujukan-<?= (int) $i ?>-tanggal-hasil">Tanggal hasil</label>
                        <input class="form-control" type="date" id="rujukan-<?= (int) $i ?>-tanggal-hasil" name="rujukan[<?= (int) $i ?>][tanggal_hasil]" value="<?= $nilai($r, 'tanggal_hasil') ?>"></div>
                </div>
                <?= $hapus('rujukan', $i, $r) ?>
            </div>
        <?php endforeach; ?>
        <p class="form-text">Tanggal rujukan kosong = tanggal kunjungan. Hasil boleh dikosongkan dan dicatat kemudian.</p>
    </fieldset>

    <fieldset class="mb-4">
        <legend class="h6">Tindak lanjut</legend>
        <?php foreach ($tindakLanjut as $i => $r): $status = $r['status'] ?? 'Terbuka'; ?>
            <div class="border rounded p-2 mb-2">
                <div class="row g-2">
                    <div class="col-12"><label class="form-label small" for="tl-<?= (int) $i ?>-uraian">Uraian tindak lanjut</label>
                        <textarea class="form-control" id="tl-<?= (int) $i ?>-uraian" name="tindak_lanjut[<?= (int) $i ?>][uraian]" rows="2" maxlength="1000"><?= $nilai($r, 'uraian') ?></textarea></div>
                    <div class="col-12 col-md-4"><label class="form-label small" for="tl-<?= (int) $i ?>-target">Target tanggal</label>
                        <input class="form-control" type="date" id="tl-<?= (int) $i ?>-target" name="tindak_lanjut[<?= (int) $i ?>][tanggal_target]" value="<?= $nilai($r, 'tanggal_target') ?>"></div>
                    <div class="col-6 col-md-4"><label class="form-label small" for="tl-<?= (int) $i ?>-status">Status</label>
                        <select class="form-select" id="tl-<?= (int) $i ?>-status" name="tindak_lanjut[<?= (int) $i ?>][status]">
                            <option value="Terbuka"<?= $status !== 'Selesai' ? ' selected' : '' ?>>Terbuka</option>
                            <option value="Selesai"<?= $status === 'Selesai' ? ' selected' : '' ?>>Selesai</option>
                        </select></div>
                    <div class="col-6 col-md-4"><label class="form-label small" for="tl-<?= (int) $i ?>-selesai">Tanggal selesai</label>
                        <input class="form-control" type="date" id="tl-<?= (int) $i ?>-selesai" name="tindak_lanjut[<?= (int) $i ?>][tanggal_selesai]" value="<?= $nilai($r, 'tanggal_selesai') ?>"></div>
                </div>
                <?= $hapus('tindak_lanjut', $i, $r) ?>
            </div>
        <?php endforeach; ?>
        <p class="form-text">Status Selesai tanpa tanggal = hari ini.</p>
    </fieldset>
    <?php
}
