<?php

declare(strict_types=1);

namespace App\Ui;

use App\Auth\Capabilities;
use App\Penugasan\PenugasanJenis;
use Throwable;

/**
 * Hak nyata akun yang dibaca peta menu. Hanya petunjuk tampilan; setiap
 * halaman tetap memeriksa haknya sendiri.
 *
 * @internal
 */
final class NavigationContext
{
    /**
     * @param array<string, mixed> $user
     * @param array<int, string> $izin
     * @param array<string, array<string, mixed>> $v3
     */
    public function __construct(public array $user, public array $izin, public array $v3)
    {
    }

    public function izin(string $capability): bool
    {
        return in_array($capability, $this->izin, true);
    }

    /** Hak fitur dari penugasan ATAU pengawasan admin. */
    public function feature(string $capability): bool
    {
        return capabilities()->hasFeature($this->user, $capability);
    }

    /** Hak fitur dari penugasan milik akun sendiri (bukan pengawasan admin). */
    public function own(string $capability): bool
    {
        return in_array(
            capabilities()->featureSource($this->user, $capability),
            [Capabilities::SUMBER_PENUGASAN, Capabilities::SUMBER_KEDUANYA],
            true
        );
    }

    /**
     * Guru mapel tanpa jadwal pengajian tidak melihat menu Pengajian (peta
     * menu guru). Guru tanpa penugasan mapel tetap melihatnya.
     */
    public function showPengajian(): bool
    {
        if (!$this->own(PenugasanJenis::CAP_NILAI_INPUT)) {
            return true;
        }
        try {
            return schedule_service()->activeScheduleOptions($this->user) !== [];
        } catch (Throwable) {
            return true;
        }
    }

    /** @var array<string, mixed>|false|null */
    private array|false|null $aksesKesehatan = null;

    /**
     * Menu Kesehatan (PRD V5): hanya bila migrasi 024 dan 025 terpasang.
     * `$milikSendiri` = penugasan kesehatan akun ini (petugas); selain itu
     * termasuk pengawasan admin.
     */
    public function kesehatan(bool $milikSendiri): bool
    {
        $akses = $this->aksesKesehatan();
        if ($akses === false) {
            return false;
        }

        return $milikSendiri ? $this->own(PenugasanJenis::CAP_KESEHATAN_CATAT) : $this->feature(PenugasanJenis::CAP_KESEHATAN_CATAT);
    }

    /**
     * Menu Kesehatan binaan (PRD V5 Fase 2): pembimbing (peran Pengurus) atau
     * murobi (peran Guru) dengan penugasan aktif, hanya baca. Barisnya tetap
     * disaring cakupan oleh `KesehatanService`.
     *
     * @param 'pembimbing'|'murobi' $jenis
     */
    public function kesehatanBinaan(string $jenis): bool
    {
        $akses = $this->aksesKesehatan();

        return $akses !== false && $akses['cakupan'] === \App\Kesehatan\KesehatanService::CAKUPAN_BINAAN && ($akses['binaan'][$jenis] ?? false);
    }

    /** @return array<string, mixed>|false false = modul belum dipasang/galat */
    private function aksesKesehatan(): array|false
    {
        if ($this->aksesKesehatan === null) {
            try {
                $this->aksesKesehatan = kesehatan_service()->akses($this->user);
            } catch (Throwable) {
                $this->aksesKesehatan = false;
            }
        }

        return $this->aksesKesehatan;
    }

    public function raporWali(): bool
    {
        try {
            return in_array('rapor.wali', capabilities()->v4Capabilities($this->user)['list'], true);
        } catch (Throwable) {
            return false;
        }
    }
}
