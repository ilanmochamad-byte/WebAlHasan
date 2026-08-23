<?php

declare(strict_types=1);

namespace App\Notification\Push;

use App\Notification\SafeError;

/**
 * Klien Expo Push Service.
 *
 * Mengikuti kontrak yang didokumentasikan Expo untuk SDK 57:
 *   - endpoint kirim  : POST https://exp.host/--/api/v2/push/send
 *   - maksimum 100 pesan per permintaan
 *   - respons berupa "ticket" per pesan: {status: ok|error, id, details.error}
 *   - `details.error = DeviceNotRegistered` berarti token WAJIB berhenti dipakai
 *   - `MessageRateExceeded` bersifat sementara dan layak backoff
 *
 * Token perangkat hanya lewat di memori proses ini. Ia tidak pernah masuk log,
 * pesan galat, audit, atau respons API — seluruh pesan galat melewati
 * `SafeError`, yang juga menyamarkan pola `ExponentPushToken[...]`.
 */
final class ExpoPushClient implements PushClient
{
    public const ENDPOINT = 'https://exp.host/--/api/v2/push/send';
    public const MAX_BATCH = 100;

    /** Galat tiket yang berarti token tidak boleh dipakai lagi. */
    public const ERROR_TOKEN_MATI = 'DeviceNotRegistered';

    public function __construct(
        private ?string $accessToken = null,
        private int $timeoutSeconds = 10
    ) {
    }

    public static function looksLikeExpoToken(string $token): bool
    {
        return preg_match('/^Expo(?:nent)?PushToken\[[A-Za-z0-9._\-]+\]$/', $token) === 1;
    }

    /**
     * Mengirim satu batch pesan.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array{ok:bool, tickets:array<int, array<string, mixed>>, kode:string, pesan:string, permanen:bool}
     */
    public function send(array $messages): array
    {
        if ($messages === []) {
            return ['ok' => true, 'tickets' => [], 'kode' => 'OK', 'pesan' => 'Tidak ada pesan.', 'permanen' => false];
        }
        if (count($messages) > self::MAX_BATCH) {
            $messages = array_slice($messages, 0, self::MAX_BATCH);
        }

        $headers = [
            'Accept: application/json',
            'Accept-Encoding: gzip, deflate',
            'Content-Type: application/json',
        ];
        if ($this->accessToken !== null && trim($this->accessToken) !== '') {
            $headers[] = 'Authorization: Bearer ' . trim($this->accessToken);
        }

        $body = (string) json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        [$status, $response, $error] = $this->post(self::ENDPOINT, $headers, $body);

        if ($error !== null) {
            return ['ok' => false, 'tickets' => [], 'kode' => 'JARINGAN', 'pesan' => $error, 'permanen' => false];
        }
        if ($status === 400 || $status === 413) {
            return [
                'ok' => false,
                'tickets' => [],
                'kode' => 'PERMINTAAN_DITOLAK_' . $status,
                'pesan' => 'Expo menolak permintaan (HTTP ' . $status . ').',
                'permanen' => true,
            ];
        }
        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'tickets' => [],
                'kode' => 'EXPO_STATUS_' . $status,
                'pesan' => 'Expo menjawab HTTP ' . $status . '.',
                'permanen' => false,
            ];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'tickets' => [],
                'kode' => 'RESPONS_TIDAK_VALID',
                'pesan' => 'Respons Expo bukan JSON yang dapat dibaca.',
                'permanen' => false,
            ];
        }
        if (isset($decoded['errors']) && is_array($decoded['errors']) && $decoded['errors'] !== []) {
            $first = $decoded['errors'][0];
            $kode = is_array($first) ? (string) ($first['code'] ?? 'EXPO_ERROR') : 'EXPO_ERROR';
            $pesan = is_array($first) ? (string) ($first['message'] ?? 'Expo mengembalikan galat.') : 'Expo mengembalikan galat.';

            return [
                'ok' => false,
                'tickets' => [],
                'kode' => $kode,
                'pesan' => SafeError::message($pesan),
                // TOO_MANY_REQUESTS bersifat sementara; sisanya konfigurasi.
                'permanen' => strtoupper($kode) !== 'TOO_MANY_REQUESTS',
            ];
        }

        $tickets = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        return ['ok' => true, 'tickets' => $tickets, 'kode' => 'OK', 'pesan' => 'Batch diterima Expo.', 'permanen' => false];
    }

    /**
     * Membangun satu pesan push.
     *
     * Isi sudah berupa varian kanal eksternal: tanpa alasan izin, catatan, atau
     * data pribadi. `data` hanya berisi penunjuk sumber daya untuk deep link;
     * server tetap memverifikasi hak akses ketika detail dibuka.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function message(string $token, string $title, string $body, array $data, string $channelId): array
    {
        return [
            'to' => $token,
            'title' => mb_substr($title, 0, 120),
            'body' => mb_substr($body, 0, 300),
            'data' => $data,
            'sound' => 'default',
            'priority' => 'high',
            // Kanal Android WAJIB sudah dibuat aplikasi (expo-notifications SDK 57).
            'channelId' => $channelId,
            'ttl' => 3600,
        ];
    }

    /**
     * @param array<int, string> $headers
     * @return array{0:int, 1:string, 2:?string}
     */
    private function post(string $url, array $headers, string $body): array
    {
        $timeout = max(3, min(30, $this->timeoutSeconds));

        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return [0, '', 'Klien HTTP tidak dapat dibuat.'];
            }
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);
            curl_close($handle);

            if ($response === false) {
                return [0, '', SafeError::message($curlError, 'Koneksi ke Expo gagal.')];
            }

            return [$status, (string) $response, null];
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return [0, '', 'Koneksi ke Expo gagal.'];
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return [$status, (string) $response, null];
    }
}
