<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

/**
 * Kontrak penyedia WhatsApp.
 *
 * PRD Fase 4 §6.1 melarang sistem terkunci pada satu vendor. Seluruh kode di
 * luar folder ini hanya mengenal antarmuka ini; menambah vendor baru berarti
 * menulis satu kelas baru dan mendaftarkannya pada `ProviderFactory`, tanpa
 * menyentuh outbox, worker, API, atau UI.
 *
 * Kewajiban setiap implementasi:
 *   1. Membaca credential HANYA dari environment server. Tidak boleh menulis
 *      credential ke basis data, log, audit, atau respons.
 *   2. Tidak melakukan panggilan jaringan apa pun ketika `readiness()`
 *      melaporkan belum siap.
 *   3. Mengembalikan `ProviderResult` dengan pesan yang sudah aman.
 */
interface WhatsAppProvider
{
    /**
     * Nama penyedia untuk ditampilkan dan disimpan pada pengaturan.
     * Bukan credential dan aman ditampilkan.
     */
    public function name(): string;

    /**
     * Apakah penyedia ini benar-benar mengirim pesan keluar.
     *
     * Adapter uji mengembalikan false sehingga sistem tidak pernah mengklaim
     * pengiriman nyata berhasil ketika penyedia sungguhan belum tersedia
     * (PRD Fase 4 §6.8).
     */
    public function mengirimNyata(): bool;

    /**
     * Kesiapan konfigurasi TANPA memanggil jaringan.
     *
     * @return array{siap:bool, pesan:string, detail:array<int, string>}
     */
    public function readiness(): array;

    /**
     * Pemeriksaan konfigurasi. Boleh memanggil endpoint verifikasi penyedia,
     * tetapi TIDAK BOLEH mengirim pesan kepada penerima nyata.
     */
    public function verify(): ProviderResult;

    public function send(WhatsAppMessage $message): ProviderResult;
}
