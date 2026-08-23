<?php

declare(strict_types=1);

namespace App\Notification\WhatsApp;

use App\Notification\SafeError;

/**
 * Adapter HTTP generik yang netral vendor.
 *
 * Alih-alih mengikat sistem pada satu penyedia berbayar, adapter ini memetakan
 * kontrak `WhatsAppProvider` ke satu endpoint HTTP yang seluruhnya dijelaskan
 * lewat environment server. Menambah vendor baru cukup dengan mengisi
 * environment; tidak ada kode yang perlu diubah dan tidak ada akun yang dibuat
 * oleh sistem.
 *
 * Environment yang dibaca (NILAI TIDAK PERNAH DICATAT DI MANA PUN):
 *   WHATSAPP_API_URL            URL endpoint kirim pesan (wajib, HTTPS).
 *   WHATSAPP_API_TOKEN          credential bearer/api key (wajib).
 *   WHATSAPP_AUTH_HEADER        nama header auth; default `Authorization`.
 *   WHATSAPP_AUTH_PREFIX        awalan nilai header; default `Bearer `.
 *   WHATSAPP_SENDER_ID          pengenal pengirim milik vendor (opsional).
 *   WHATSAPP_TEMPLATE_NAME      nama template resmi (opsional).
 *   WHATSAPP_FIELD_TO           nama field tujuan pada body; default `to`.
 *   WHATSAPP_FIELD_TEXT         nama field teks pada body; default `text`.
 *   WHATSAPP_VERIFY_URL         URL pemeriksaan konfigurasi (opsional; bila
 *                               kosong, verifikasi hanya memeriksa kelengkapan
 *                               environment tanpa memanggil jaringan).
 *   WHATSAPP_TIMEOUT_SECONDS    batas waktu; default 10, maksimum 30.
 *
 * Adapter ini TIDAK dipakai kecuali admin sudah menyalakan WhatsApp setelah
 * pemeriksaan konfigurasi lulus. Ketika environment belum lengkap,
 * `readiness()` melaporkan belum siap dan `send()` berhenti sebelum membuka
 * koneksi apa pun.
 */
final class HttpProvider implements WhatsAppProvider
{
    /**
     * @param array<string, string> $env
     */
    public function __construct(private array $env, private string $label = 'http')
    {
    }

    public function name(): string
    {
        return $this->label;
    }

    public function mengirimNyata(): bool
    {
        return true;
    }

    public function readiness(): array
    {
        $detail = [];
        $url = trim($this->env['WHATSAPP_API_URL'] ?? '');
        $token = trim($this->env['WHATSAPP_API_TOKEN'] ?? '');

        if ($url === '') {
            $detail[] = 'WHATSAPP_API_URL belum diisi.';
        } elseif (!str_starts_with(strtolower($url), 'https://')) {
            $detail[] = 'WHATSAPP_API_URL wajib memakai HTTPS.';
        } elseif (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $detail[] = 'WHATSAPP_API_URL bukan URL yang valid.';
        }
        if ($token === '') {
            $detail[] = 'WHATSAPP_API_TOKEN belum diisi.';
        }
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            $detail[] = 'Server tidak memiliki cURL maupun allow_url_fopen untuk memanggil penyedia.';
        }

        return [
            'siap' => $detail === [],
            'pesan' => $detail === []
                ? 'Konfigurasi environment WhatsApp lengkap.'
                : 'Konfigurasi WhatsApp belum lengkap.',
            // `detail` hanya menyebut NAMA environment yang kurang, tidak pernah nilainya.
            'detail' => $detail,
        ];
    }

    public function verify(): ProviderResult
    {
        $readiness = $this->readiness();
        if ($readiness['siap'] !== true) {
            return ProviderResult::permanen('KONFIGURASI_TIDAK_LENGKAP', $readiness['pesan']
                . ' ' . implode(' ', $readiness['detail']));
        }

        $verifyUrl = trim($this->env['WHATSAPP_VERIFY_URL'] ?? '');
        if ($verifyUrl === '') {
            // Tanpa endpoint verifikasi, sistem TIDAK boleh mengklaim penyedia
            // sudah terbukti bekerja. Ia hanya memastikan environment lengkap.
            return ProviderResult::ok(
                'Environment lengkap. Endpoint verifikasi tidak disetel, sehingga koneksi ke penyedia belum diuji.'
            );
        }

        [$status, $body, $error] = $this->httpRequest($verifyUrl, null);
        if ($error !== null) {
            return ProviderResult::gagal('VERIFIKASI_GAGAL', $error);
        }
        if ($status >= 200 && $status < 300) {
            return ProviderResult::ok('Penyedia menjawab pemeriksaan konfigurasi dengan status ' . $status . '.');
        }
        if ($status === 401 || $status === 403) {
            return ProviderResult::permanen('CREDENTIAL_DITOLAK', 'Penyedia menolak credential (HTTP ' . $status . ').');
        }

        return ProviderResult::gagal('VERIFIKASI_STATUS_' . $status, 'Penyedia menjawab HTTP ' . $status . ' pada pemeriksaan konfigurasi.');
    }

    public function send(WhatsAppMessage $message): ProviderResult
    {
        $readiness = $this->readiness();
        if ($readiness['siap'] !== true) {
            // Berhenti SEBELUM membuka koneksi.
            return ProviderResult::permanen('KONFIGURASI_TIDAK_LENGKAP', $readiness['pesan']);
        }

        $tujuan = $this->normalisasiNomor($message->tujuan);
        if ($tujuan === null) {
            return ProviderResult::permanen('NOMOR_TIDAK_VALID', 'Nomor tujuan tidak dapat dinormalisasi.');
        }

        $fieldTo = trim($this->env['WHATSAPP_FIELD_TO'] ?? '') ?: 'to';
        $fieldText = trim($this->env['WHATSAPP_FIELD_TEXT'] ?? '') ?: 'text';
        $payload = [
            $fieldTo => $tujuan,
            $fieldText => $message->teks(),
        ];
        $sender = trim($this->env['WHATSAPP_SENDER_ID'] ?? '');
        if ($sender !== '') {
            $payload['from'] = $sender;
        }
        $template = trim($this->env['WHATSAPP_TEMPLATE_NAME'] ?? '');
        if ($template !== '') {
            $payload['template'] = $template;
        }

        [$status, $body, $error] = $this->httpRequest(trim($this->env['WHATSAPP_API_URL'] ?? ''), $payload);
        if ($error !== null) {
            return ProviderResult::gagal('JARINGAN', $error);
        }
        if ($status >= 200 && $status < 300) {
            return ProviderResult::ok('Penyedia menerima pesan (HTTP ' . $status . ').', $this->referensi($body));
        }
        if (in_array($status, [400, 401, 403, 404, 422], true)) {
            return ProviderResult::permanen(
                'PENYEDIA_MENOLAK_' . $status,
                'Penyedia menolak permintaan dengan HTTP ' . $status . '. ' . SafeError::message($body, '')
            );
        }

        return ProviderResult::gagal(
            'PENYEDIA_STATUS_' . $status,
            'Penyedia menjawab HTTP ' . $status . '. ' . SafeError::message($body, '')
        );
    }

    /**
     * @param array<string, mixed>|null $payload null berarti GET
     * @return array{0:int, 1:string, 2:?string} status, body, error aman
     */
    private function httpRequest(string $url, ?array $payload): array
    {
        $timeout = (int) ($this->env['WHATSAPP_TIMEOUT_SECONDS'] ?? '10');
        $timeout = max(3, min(30, $timeout));

        $authHeader = trim($this->env['WHATSAPP_AUTH_HEADER'] ?? '') ?: 'Authorization';
        $authPrefix = $this->env['WHATSAPP_AUTH_PREFIX'] ?? 'Bearer ';
        $token = trim($this->env['WHATSAPP_API_TOKEN'] ?? '');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            $authHeader . ': ' . $authPrefix . $token,
        ];
        $body = $payload === null
            ? null
            : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return [0, '', 'Klien HTTP tidak dapat dibuat.'];
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== null) {
                curl_setopt($handle, CURLOPT_POST, true);
                curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);

            if ($response === false) {
                return [0, '', SafeError::message($curlError, 'Koneksi ke penyedia gagal.')];
            }

            return [$status, (string) $response, null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => $body === null ? 'GET' : 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [0, '', 'Koneksi ke penyedia gagal.'];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $response, null];
    }

    /**
     * Referensi pesan dari penyedia, bila ada. Dibersihkan dan dipendekkan.
     */
    private function referensi(string $body): ?string
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }
        foreach (['id', 'message_id', 'messageId', 'sid', 'reference'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                return substr(preg_replace('/[^A-Za-z0-9._\-]/', '', (string) $data[$key]) ?? '', 0, 60);
            }
        }

        return null;
    }

    /**
     * Normalisasi nomor Indonesia ke bentuk E.164 tanpa tanda plus.
     */
    private function normalisasiNomor(string $nomor): ?string
    {
        $digits = preg_replace('/\D+/', '', $nomor) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '62' . substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62' . $digits;
        }
        if (strlen($digits) < 10 || strlen($digits) > 15) {
            return null;
        }

        return $digits;
    }
}
