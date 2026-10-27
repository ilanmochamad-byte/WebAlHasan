<?php

declare(strict_types=1);

use App\Api\ApiException;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Report\PrintRenderer;

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$requestPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/api/v1'), PHP_URL_PATH));
$prefixPosition = strpos($requestPath, '/api/v1');
$path = $prefixPosition === false ? '/' : substr($requestPath, $prefixPosition + strlen('/api/v1'));
$path = preg_replace('#^/index\.php#', '', $path) ?? $path;
$path = '/' . trim($path, '/');
if ($path === '//') {
    $path = '/';
}

/**
 * Konteks request untuk riwayat status perizinan (tanpa credential).
 *
 * @return array{ip:?string, user_agent:?string}
 */
$requestMeta = static fn (): array => [
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : null,
];

/**
 * Cakupan yang DIMINTA klien (V2 Fase 5).
 *
 * Ini hanya preferensi tampilan, bukan hak akses: `IzinService::scopeFor()`
 * memilih di antara kemampuan yang benar-benar dimiliki akun dan mengabaikan
 * nilai yang tidak dimiliki. Mengirim `mode=admin` tidak memberi hak admin.
 */
$modePreferensi = static fn (): ?string => isset($_GET['mode']) && trim((string) $_GET['mode']) !== ''
    ? (string) $_GET['mode']
    : null;

try {
    if ($method === 'GET' && $path === '/') {
        JsonResponse::success(['name' => 'Al Hasan API', 'version' => 'v1']);
    }

    if ($method === 'POST' && $path === '/auth/login') {
        JsonResponse::success(api_auth_service()->login(Request::json()));
    }

    // Autentikasi sekali; penjaga hak akses diterapkan per endpoint di bawah.
    // Endpoint jadwal/laporan V1 tetap memakai penjaga admin/guru yang sama
    // seperti sebelumnya, sehingga kontrak aplikasi guru tidak berubah.
    if (str_starts_with($path, '/v4/')) header('Cache-Control: private, no-store');
    $user = api_authenticator()->authenticate();
    if (str_starts_with($path, '/v3/')) header('Cache-Control: private, no-store');

    if (str_starts_with($path, '/v3/publikasi')) {
        header('Cache-Control: private, no-store');
        $pub = v3_publikasi_service();
        if ($method === 'GET' && $path === '/v3/publikasi') JsonResponse::success($pub->page($user,$_GET));
        if ($method === 'GET' && preg_match('#^/v3/publikasi/sumber/(kasus|sesi|pelanggaran)/(\d+)$#',$path,$m)) JsonResponse::success($pub->options($user,$m[1],(int)$m[2]));
        if ($method === 'GET' && preg_match('#^/v3/publikasi/(\d+)/kelola$#',$path,$m)) JsonResponse::success($pub->manage($user,(int)$m[1]));
        if ($method === 'GET' && preg_match('#^/v3/publikasi/(\d+)$#',$path,$m)) JsonResponse::success($pub->show($user,(int)$m[1]));
        if ($method === 'POST') {
            $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;
            if ($path === '/v3/publikasi/pratinjau') JsonResponse::success($pub->preview($user,$body));
            if ($path === '/v3/publikasi/terbit') {$result=$pub->publish($user,$body);JsonResponse::success($result['data'],$result['status']);}
            if (preg_match('#^/v3/publikasi/(\d+)/tarik$#',$path,$m)) {$result=$pub->withdraw($user,(int)$m[1],$body);JsonResponse::success($result['data'],$result['status']);}
            if (preg_match('#^/v3/publikasi/(\d+)/dibaca$#',$path,$m)) JsonResponse::success($pub->markRead($user,(int)$m[1],$body));
        }
    }

    // V4 Fase 1–4: endpoint aditif; klien lama tidak perlu mengubah payload.
    if (str_starts_with($path, '/v4/')) {
        if ($method === 'GET' && $path === '/v4/capabilities') JsonResponse::success(capabilities()->v4Capabilities($user));
        header('Cache-Control: private, no-store');
        $rapor = new \App\V4\RaporService(app_db(), audit_logger());
        if ($method === 'GET' && $path === '/v4/rapor') JsonResponse::success($rapor->listing($user));
        if ($method === 'GET' && $path === '/v4/rapor/pratinjau') JsonResponse::success($rapor->preview($user,(int)($_GET['rencana_id']??0),(int)($_GET['santri_id']??0)));
        if ($method === 'GET' && preg_match('#^/v4/rapor/(\d+)$#',$path,$m)) JsonResponse::success(($_GET['petugas']??'0')==='1' ? $rapor->show($user,(int)$m[1],true) : $rapor->parentView($user,(int)$m[1]));
        if ($method === 'GET' && preg_match('#^/v4/rapor/(\d+)/pdf/(\d+)$#',$path,$m)) \App\V4\RaporService::stream($rapor->pdf($user,(int)$m[1],(int)$m[2],($_GET['petugas']??'0')==='1'),(int)$m[1],(int)$m[2]);
        if ($method === 'POST' && $path === '/v4/rapor/pratinjau') {$body=Request::json();if(isset($body['catatan_umum'])&&!is_string($body['catatan_umum']))throw new \App\V4\V4Exception('Catatan umum tidak valid.');JsonResponse::success($rapor->preview($user,(int)($body['rencana_id']??0),(int)($body['santri_id']??0),$body['catatan_umum']??''));}
        if ($method === 'POST' && $path === '/v4/rapor') {$body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;JsonResponse::success($rapor->act($user,$body));}
        if ($method === 'POST' && $path === '/v4/rencana/penanda') JsonResponse::success((new \App\V4\RencanaService(app_db(),capabilities(),audit_logger()))->changeSigners($user,Request::json()));
        $sikap = new \App\V4\SikapService(app_db(), audit_logger());
        $verifikasi = new \App\V4\VerifikasiService(app_db(), audit_logger());
        if ($method === 'GET' && $path === '/v4/sikap/options') JsonResponse::success($sikap->options($user, (string)($_GET['mode']??'pembimbing')));
        if ($method === 'GET' && $path === '/v4/sikap') JsonResponse::success($sikap->read($user, (int)($_GET['rencana_id']??0), (int)($_GET['santri_id']??0), (string)($_GET['mode']??'pembimbing')));
        if ($method === 'POST' && $path === '/v4/sikap') JsonResponse::success($sikap->save($user, Request::json()));
        if ($method === 'POST' && $path === '/v4/sikap/murobi') JsonResponse::success($sikap->save($user, Request::json(), 'murobi'));
        if ($method === 'GET' && $path === '/v4/verifikasi/options') JsonResponse::success($verifikasi->options($user));
        if ($method === 'GET' && $path === '/v4/verifikasi') JsonResponse::success($verifikasi->read($user, (int)($_GET['rencana_id']??0), (int)($_GET['santri_id']??0)));
        if ($method === 'POST' && $path === '/v4/verifikasi') JsonResponse::success($verifikasi->act($user, Request::json()));
        $nilai = new \App\V4\NilaiService(app_db(), capabilities(), audit_logger());
        if ($method === 'GET' && $path === '/v4/nilai/options') JsonResponse::success($nilai->options($user, ($_GET['pengganti']??'0')==='1'));
        if ($method === 'GET' && $path === '/v4/nilai') JsonResponse::success($nilai->read($user, (int)($_GET['rencana_mapel_id']??0), (int)($_GET['santri_id']??0), ($_GET['pengganti']??'0')==='1'));
        if ($method === 'POST' && $path === '/v4/nilai') JsonResponse::success($nilai->save($user, Request::json()));
        if ($method === 'POST' && $path === '/v4/nilai/pengganti') JsonResponse::success($nilai->save($user, Request::json(), true));
        $rencana = new \App\V4\RencanaService(app_db(), capabilities(), audit_logger());
        if ($method === 'GET' && $path === '/v4/rencana/options') JsonResponse::success($rencana->options($user));
        if ($method === 'GET' && $path === '/v4/rencana') JsonResponse::success($rencana->read($user, (int)($_GET['tahun_ajaran_id']??0), (int)($_GET['kelas_id']??0)));
        if ($method === 'POST' && $path === '/v4/rencana') JsonResponse::success($rencana->save($user, Request::json()));
    }

    // V5 Fase 1–2 (kesehatan santri): aditif. Hak dihitung ulang layanan yang
    // sama dengan halaman web; admin, pembimbing, dan murobi hanya membaca.
    if (str_starts_with($path, '/v5/kesehatan')) {
        header('Cache-Control: private, no-store');
        $kesehatan = kesehatan_service();
        if ($method === 'GET' && $path === '/v5/kesehatan/akses') JsonResponse::success($kesehatan->akses($user));
        if ($method === 'GET' && $path === '/v5/kesehatan/santri') JsonResponse::success($kesehatan->santriPilihan($user, $_GET['q'] ?? '', $_GET['kelas_id'] ?? null));
        if ($method === 'GET' && $path === '/v5/kesehatan/santri/kelas') JsonResponse::success($kesehatan->kelasPilihan($user));
        if ($method === 'GET' && $path === '/v5/kesehatan/kunjungan') JsonResponse::success($kesehatan->daftar($user, $_GET));
        // Fase 5: laporan layanan klinik — agregat tanpa isi klinis, hak per baris sama dengan daftar.
        if ($method === 'GET' && $path === '/v5/kesehatan/laporan') JsonResponse::success(kesehatan_laporan_service()->laporan($user, $_GET));
        if ($method === 'POST' && $path === '/v5/kesehatan/kunjungan') {
            $body = Request::json();
            $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $hasil = $kesehatan->buat($user, $body, \App\Kesehatan\KesehatanService::KANAL_API);
            // Respons tanpa isi klinis; rincian dibaca lewat GET (tercatat di log akses).
            JsonResponse::success(['id' => $hasil['id'], 'replayed' => $hasil['replayed']], $hasil['status']);
        }
        if ($method === 'GET' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)$#', $path, $m)) JsonResponse::success($kesehatan->detail($user, (int) $m[1], \App\Kesehatan\KesehatanService::KANAL_API));
        if ($method === 'GET' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/akses$#', $path, $m)) JsonResponse::success($kesehatan->riwayatAkses($user, (int) $m[1], $_GET['page'] ?? 1));
        if ($method === 'GET' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/versi$#', $path, $m)) JsonResponse::success($kesehatan->riwayatVersi($user, (int) $m[1], \App\Kesehatan\KesehatanService::KANAL_API));
        // Koreksi berversi (Fase 2): badan membawa `versi` yang dilihat, `alasan`, dan keadaan lengkap
        // sesudah koreksi; respons tanpa isi klinis.
        if ($method === 'POST' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/koreksi$#', $path, $m)) {
            $body = Request::json();
            $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $hasil = $kesehatan->koreksi($user, (int) $m[1], $body, \App\Kesehatan\KesehatanService::KANAL_API);
            JsonResponse::success(['id' => $hasil['id'], 'versi' => $hasil['versi'], 'replayed' => $hasil['replayed']], $hasil['status']);
        }
        if ($method === 'POST' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/rujukan/(\d+)/hasil$#', $path, $m)) {
            $body = Request::json();
            $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $hasil = $kesehatan->hasilRujukan($user, (int) $m[1], (int) $m[2], $body, \App\Kesehatan\KesehatanService::KANAL_API);
            JsonResponse::success(['id' => $hasil['id'], 'versi' => $hasil['versi'], 'replayed' => $hasil['replayed']], $hasil['status']);
        }
        // Fase 4: pengelolaan penugasan petugas kesehatan dari aplikasi (admin saja), lewat
        // PenugasanService yang sama dengan Pusat Penugasan web; tanpa penghapusan.
        if (str_starts_with($path, '/v5/kesehatan/penugasan')) {
            $penugasanApi = kesehatan_penugasan_api();
            if ($method === 'GET' && $path === '/v5/kesehatan/penugasan') JsonResponse::success($penugasanApi->daftar($user, $_GET));
            if ($method === 'POST' && $path === '/v5/kesehatan/penugasan') JsonResponse::success($penugasanApi->buat($user, Request::json()), 201);
            if ($method === 'POST' && preg_match('#^/v5/kesehatan/penugasan/(\d+)/(akhiri|nonaktifkan|aktifkan)$#', $path, $m)) JsonResponse::success($penugasanApi->ubahStatus($user, (int) $m[1], $m[2], Request::json()));
            throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
        }
        // Fase 3: ringkasan wali. Petugas menyusun, mempratinjau, menerbitkan/mengganti, dan menarik;
        // pembaca internal sesuai haknya; wali hanya proyeksi khusus dengan relasi aktif diperiksa per permintaan.
        $ringkasan = kesehatan_ringkasan_service();
        if ($method === 'GET' && $path === '/v5/kesehatan/wali/akses') JsonResponse::success($ringkasan->aksesWali($user));
        if ($method === 'GET' && $path === '/v5/kesehatan/wali/ringkasan') JsonResponse::success($ringkasan->daftarWali($user, $_GET));
        if ($method === 'GET' && preg_match('#^/v5/kesehatan/wali/ringkasan/(\d+)$#', $path, $m)) JsonResponse::success($ringkasan->detailWali($user, (int) $m[1], \App\Kesehatan\KesehatanService::KANAL_API));
        if ($method === 'GET' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/ringkasan$#', $path, $m)) JsonResponse::success($ringkasan->internal($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/ringkasan/pratinjau$#', $path, $m)) JsonResponse::success($ringkasan->pratinjau($user, (int) $m[1], Request::json()));
        if ($method === 'POST' && preg_match('#^/v5/kesehatan/kunjungan/(\d+)/ringkasan(/tarik)?$#', $path, $m)) {
            $body = Request::json();
            $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $hasil = ($m[2] ?? '') === '/tarik'
                ? $ringkasan->tarik($user, (int) $m[1], $body, \App\Kesehatan\KesehatanService::KANAL_API)
                : $ringkasan->terbitkan($user, (int) $m[1], $body, \App\Kesehatan\KesehatanService::KANAL_API);
            $status = $hasil['status'];
            unset($hasil['status']);
            JsonResponse::success($hasil, $status);
        }
        throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
    }

    // V7 Fase 1–2 (pengajuan izin wali melalui pembimbing): aditif. Hak dihitung
    // ulang `IzinWaliService` yang sama dengan halaman web; murobi tidak
    // menerima apa pun sebelum pembimbing meneruskan.
    if (str_starts_with($path, '/v7/izin')) {
        header('Cache-Control: private, no-store');
        $wali = izin_wali_service();
        $kunci = static function (array $body): ?string {
            $nilai = $body['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null);

            return is_string($nilai) ? $nilai : null;
        };
        $hasilMutasi = static function (array $hasil): never {
            $status = (int) $hasil['status_http'];
            unset($hasil['status_http']);
            JsonResponse::success($hasil, $status);
        };
        if ($method === 'GET' && $path === '/v7/izin/akses') JsonResponse::success($wali->akses($user));
        if ($method === 'GET' && $path === '/v7/izin/wali/anak') JsonResponse::success(['items' => $wali->anak($user)]);
        if ($method === 'GET' && $path === '/v7/izin/wali/pengajuan') JsonResponse::success($wali->daftarWali($user, $_GET['santri_id'] ?? null, $_GET['page'] ?? 1));
        if ($method === 'POST' && $path === '/v7/izin/wali/pengajuan') {
            $body = Request::json();
            $hasilMutasi($wali->buat($user, $body, $kunci($body), $requestMeta()));
        }
        if ($method === 'GET' && $path === '/v7/izin/pembimbing/antrean') JsonResponse::success($wali->antreanPembimbing($user, $_GET['page'] ?? 1));
        if ($method === 'GET' && $path === '/v7/izin/admin/pengajuan') JsonResponse::success($wali->daftarAdmin($user, $_GET));
        if ($method === 'GET' && preg_match('#^/v7/izin/pengajuan/(\d+)$#', $path, $m)) JsonResponse::success($wali->detail($user, (int) $m[1]));
        if ($method === 'GET' && preg_match('#^/v7/izin/pengajuan/(\d+)/kandidat-pembimbing$#', $path, $m)) {
            JsonResponse::success(['items' => $wali->kandidatPembimbing($user, (int) $m[1])]);
        }
        if ($method === 'POST' && preg_match('#^/v7/izin/pengajuan/(\d+)/penetapan-pembimbing$#', $path, $m)) {
            $body = Request::json();
            $hasilMutasi($wali->tetapkanPembimbing($user, (int) $m[1], $body['pengurus_id'] ?? null, $body['alasan'] ?? null,
                $body['version'] ?? null, $kunci($body), $requestMeta()));
        }
        // V7 Fase 2: tinjauan pembimbing, perbaikan dan pembatalan wali pengaju.
        // Keputusan murobi/admin pengganti tetap endpoint V2 `/izin/pengajuan/{id}/keputusan`.
        if ($method === 'GET' && $path === '/v7/izin/pembimbing/riwayat') JsonResponse::success($wali->riwayatPembimbing($user, $_GET['page'] ?? 1));
        if ($method === 'POST' && preg_match('#^/v7/izin/pengajuan/(\d+)/tinjauan$#', $path, $m)) {
            $body = Request::json();
            $hasilMutasi($wali->tinjau($user, (int) $m[1], $body['tindakan'] ?? null, $body['catatan'] ?? null,
                $body['version'] ?? null, $kunci($body), $requestMeta()));
        }
        if ($method === 'POST' && preg_match('#^/v7/izin/pengajuan/(\d+)/perbaikan$#', $path, $m)) {
            $body = Request::json();
            $hasilMutasi($wali->perbaiki($user, (int) $m[1], $body, $body['version'] ?? null, $kunci($body), $requestMeta()));
        }
        if ($method === 'POST' && preg_match('#^/v7/izin/pengajuan/(\d+)/pembatalan$#', $path, $m)) {
            $body = Request::json();
            $hasilMutasi($wali->batalkan($user, (int) $m[1], $body['alasan'] ?? null, $body['version'] ?? null, $kunci($body), $requestMeta()));
        }
        throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
    }

    // V6 Fase 1 (keuangan santri/BSA): aditif. Hak dihitung ulang layanan yang
    // sama dengan halaman web; admin dan wali hanya membaca, tidak pernah memutasi.
    if (str_starts_with($path, '/v6/bsa')) {
        header('Cache-Control: private, no-store');
        $bsa = bsa_service();
        $kanal = \App\Bsa\BsaService::KANAL_API;
        $mutasi = static function (callable $aksi): never {
            $body = Request::json();
            $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
            $hasil = $aksi($body);
            $status = $hasil['status'];
            unset($hasil['status']);
            JsonResponse::success($hasil, $status);
        };
        // V6 Fase 3 (tabungan lengkap): setoran/penarikan lewat `TabunganService`
        // (transfer tanpa pernyataan diperiksa → 202 menunggu verifikasi;
        // penarikan boleh `jenis` Belanja). Sebelum migrasi 029 perilaku Fase 1–2.
        $tabungan = tabungan_service();
        if ($method === 'GET' && $path === '/v6/bsa/akses') JsonResponse::success($bsa->akses($user));
        if ($method === 'GET' && $path === '/v6/bsa/santri') JsonResponse::success($bsa->daftarSantri($user, $_GET));
        if ($method === 'GET' && preg_match('#^/v6/bsa/santri/(\d+)$#', $path, $m)) JsonResponse::success($tabungan->santri($user, (int) $m[1], $_GET['page'] ?? 1));
        if ($method === 'GET' && preg_match('#^/v6/bsa/santri/(\d+)/saldo$#', $path, $m)) JsonResponse::success($bsa->saldo($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v6/bsa/santri/(\d+)/(setoran|penarikan)$#', $path, $m)) {
            $mutasi(static fn (array $body): array => $m[2] === 'setoran'
                ? $tabungan->setoran($user, (int) $m[1], $body, $kanal)
                : $tabungan->penarikan($user, (int) $m[1], $body, $kanal));
        }
        if ($method === 'GET' && $path === '/v6/bsa/tabungan') JsonResponse::success($tabungan->daftarMutasi($user, $_GET));
        if ($method === 'GET' && $path === '/v6/bsa/setoran-transfer') JsonResponse::success($tabungan->daftarSetoranTransfer($user, $_GET));
        if ($method === 'POST' && preg_match('#^/v6/bsa/setoran-transfer/(\d+)/(verifikasi|tolak)$#', $path, $m)) {
            $mutasi(static fn (array $body): array => $m[2] === 'verifikasi'
                ? $tabungan->verifikasiSetoran($user, (int) $m[1], $body, $kanal)
                : $tabungan->tolakSetoran($user, (int) $m[1], $body, $kanal));
        }
        if ($method === 'GET' && preg_match('#^/v6/bsa/tabungan/(\d+)$#', $path, $m)) JsonResponse::success($tabungan->transaksi($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v6/bsa/tabungan/(\d+)/koreksi$#', $path, $m)) $mutasi(static fn (array $body): array => $tabungan->koreksi($user, (int) $m[1], $body, $kanal));
        if ($method === 'GET' && $path === '/v6/bsa/rekonsiliasi') JsonResponse::success($tabungan->rekonsiliasi($user, $_GET));
        if ($method === 'POST' && $path === '/v6/bsa/tagihan') $mutasi(static fn (array $body): array => $bsa->terbitkanTagihan($user, $body, $kanal));
        // V6 Fase 2 (tagihan bulanan lengkap): aditif. Rincian tagihan = kontrak Fase 1
        // ditambah penyesuaian, pembayaran, dan riwayat; wali tetap hanya membaca anaknya.
        $tagihan = tagihan_service();
        if ($method === 'GET' && preg_match('#^/v6/bsa/tagihan/(\d+)$#', $path, $m)) JsonResponse::success($tagihan->rincianTagihan($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v6/bsa/tagihan/(\d+)/(revisi|batal|pembayaran|bayar-tabungan)$#', $path, $m)) {
            $mutasi(static fn (array $body): array => match ($m[2]) {
                'revisi' => $tagihan->revisiTagihan($user, (int) $m[1], $body, $kanal),
                'batal' => $tagihan->batalkanTagihan($user, (int) $m[1], $body, $kanal),
                // V6 Fase 3: tindakan eksplisit, wajib `setuju`; debit + pembayaran satu transaksi.
                'bayar-tabungan' => $tagihan->bayarDariTabungan($user, (int) $m[1], $body, $kanal),
                default => $tagihan->catatPembayaran($user, (int) $m[1], $body, $kanal),
            });
        }
        if ($method === 'GET' && $path === '/v6/bsa/tarif') JsonResponse::success($tagihan->daftarTarif($user));
        if ($method === 'POST' && $path === '/v6/bsa/tarif') $mutasi(static fn (array $body): array => $tagihan->buatTarif($user, $body, $kanal));
        if ($method === 'GET' && preg_match('#^/v6/bsa/santri/(\d+)/penyesuaian$#', $path, $m)) JsonResponse::success($tagihan->penyesuaianSantri($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v6/bsa/santri/(\d+)/penyesuaian$#', $path, $m)) $mutasi(static fn (array $body): array => $tagihan->buatPenyesuaian($user, (int) $m[1], $body, $kanal));
        if ($method === 'POST' && preg_match('#^/v6/bsa/penyesuaian/(\d+)/cabut$#', $path, $m)) $mutasi(static fn (array $body): array => $tagihan->cabutPenyesuaian($user, (int) $m[1], $body, $kanal));
        if ($method === 'POST' && $path === '/v6/bsa/penerbitan/pratinjau') JsonResponse::success($tagihan->pratinjau($user, Request::json()));
        if ($method === 'POST' && $path === '/v6/bsa/penerbitan') $mutasi(static fn (array $body): array => $tagihan->terbitkanMassal($user, $body, $kanal));
        if ($method === 'GET' && $path === '/v6/bsa/penerbitan') JsonResponse::success($tagihan->daftarPenerbitan($user, $_GET['page'] ?? 1));
        if ($method === 'GET' && preg_match('#^/v6/bsa/penerbitan/(\d+)$#', $path, $m)) JsonResponse::success($tagihan->penerbitan($user, (int) $m[1]));
        if ($method === 'GET' && $path === '/v6/bsa/pembayaran') JsonResponse::success($tagihan->daftarPembayaran($user, $_GET));
        if ($method === 'GET' && preg_match('#^/v6/bsa/pembayaran/(\d+)$#', $path, $m)) JsonResponse::success($tagihan->pembayaran($user, (int) $m[1]));
        if ($method === 'POST' && preg_match('#^/v6/bsa/pembayaran/(\d+)/(verifikasi|batal)$#', $path, $m)) {
            $mutasi(static fn (array $body): array => $m[2] === 'verifikasi'
                ? $tagihan->verifikasiPembayaran($user, (int) $m[1], $body, $kanal)
                : $tagihan->batalkanPembayaran($user, (int) $m[1], $body, $kanal));
        }
        if ($method === 'GET' && $path === '/v6/bsa/tunggakan') JsonResponse::success($tagihan->tunggakan($user, $_GET));
        // V6 Fase 5: laporan per periode/per santri dan ekspor CSV, hak sama dengan web.
        if ($method === 'GET' && $path === '/v6/bsa/laporan') JsonResponse::success(laporan_bsa_service()->laporan($user, $_GET));
        if ($method === 'GET' && $path === '/v6/bsa/laporan/ekspor') {
            $ekspor = laporan_bsa_service()->ekspor($user, $_GET, $kanal);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $ekspor['nama_berkas'] . '"');
            header('X-Content-Type-Options: nosniff');
            $out = fopen('php://output', 'w');
            \App\Bsa\LaporanService::tulisCsv($out, $ekspor['kolom'], $ekspor['baris']);
            fclose($out);
            exit;
        }
        throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
    }

    // V3 aditif; kontrak capability/mode/menu mobile lama tidak disentuh.
    if ($method === 'GET' && $path === '/v3/capabilities') {
        $v3 = (new \App\Auth\Capabilities(app_db()))->v3Capabilities($user);
        $operational = array_intersect(
            ['v3.pelanggaran.kelola','v3.binaan.baca','v3.murobi.mengetahui','v3.pengawasan','v3.koreksi'],
            array_keys($v3)
        ) !== [];
        JsonResponse::success(['capabilities'=>$v3,'operasional_tersedia'=>$operational]);
    }
    if ($method === 'GET' && $path === '/v3/laporan') {
        header('Cache-Control: private, no-store');
        JsonResponse::success((new \App\V3\LaporanService(new \App\V3\PelanggaranRepository(app_db()),capabilities()))->read($user,$_GET));
    }
    if ($method === 'GET' && $path === '/v3/mobile/options') {
        if(isset($_GET['page'])&&(!is_scalar($_GET['page'])||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$_GET['page'])))throw new \App\V3\V3Exception('Halaman tidak valid.');
        header('Cache-Control: private, no-store');
        JsonResponse::success(v3_pelanggaran_service()->options($user,['page'=>$_GET['page']??1]));
    }
    if ($method === 'GET' && $path === '/v3/mobile/rekomendasi') {
        $caps=capabilities()->v3Capabilities($user);
        if(!isset($caps['v3.konseling.kelola'])&&!isset($caps['v3.pengawasan']))throw new \App\V3\V3Exception('Data tidak dapat diakses.',403);
        if(isset($_GET['page'])&&(!is_scalar($_GET['page'])||!preg_match('/^[1-9][0-9]{0,6}$/D',(string)$_GET['page'])))throw new \App\V3\V3Exception('Halaman tidak valid.');
        $page=max(1,min(1000000,(int)($_GET['page']??1)));
        $rows=(new \App\V3\KonselingRepository(app_db()))->pendingRecommendations((int)$user['id'],isset($caps['v3.pengawasan'])?'admin':'pembimbing',$page);
        header('Cache-Control: private, no-store');
        JsonResponse::success(['rows'=>$rows,'page'=>$page,'per_page'=>25]);
    }
    if ($method === 'GET' && in_array($path, ['/v3/katalog', '/v3/ambang'], true)) {
        JsonResponse::success(v3_katalog_service()->active($path === '/v3/katalog' ? 'katalog' : 'ambang', $_GET, $user));
    }
    if ($method === 'GET' && $path === '/v3/pelanggaran/options') {
        JsonResponse::success(v3_pelanggaran_service()->options($user));
    }
    if ($method === 'GET' && $path === '/v3/pelanggaran') {
        JsonResponse::success(v3_pelanggaran_service()->page($user,$_GET));
    }
    if ($method === 'POST' && $path === '/v3/pelanggaran') {
        $body=Request::json();
        $body['idempotency_key'] ??= $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
        $attachment=(new \App\V3\AttachmentStorage(APP_ROOT.'/storage/private/v3'))->stageBase64(isset($body['lampiran'])&&is_array($body['lampiran'])?$body['lampiran']:null);
        $result=v3_pelanggaran_service()->create($user,$body,$attachment);
        JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'GET' && preg_match('#^/v3/pelanggaran/(\d+)/riwayat$#',$path,$matches)) {
        JsonResponse::success(v3_pelanggaran_service()->history($user,(int)$matches[1]));
    }
    if ($method === 'GET' && preg_match('#^/v3/pelanggaran/(\d+)$#',$path,$matches)) {
        JsonResponse::success(v3_pelanggaran_service()->show($user,(int)$matches[1]));
    }
    if ($method === 'PATCH' && preg_match('#^/v3/pelanggaran/(\d+)$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;
        $attachment=(new \App\V3\AttachmentStorage(APP_ROOT.'/storage/private/v3'))->stageBase64(isset($body['lampiran'])&&is_array($body['lampiran'])?$body['lampiran']:null);
        $result=v3_pelanggaran_service()->correct($user,(int)$matches[1],$body,$attachment);
        JsonResponse::success($result['data'],$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/pelanggaran/(\d+)/pembatalan$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;
        $result=v3_pelanggaran_service()->cancel($user,(int)$matches[1],$body);
        JsonResponse::success($result['data'],$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/pelanggaran/(\d+)/diketahui$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;
        $result=v3_pelanggaran_service()->acknowledge($user,(int)$matches[1],$body);
        JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'GET' && preg_match('#^/v3/lampiran/(\d+)$#',$path,$matches)) {
        $file=v3_pelanggaran_service()->attachment($user,(int)$matches[1]);
        header('Content-Type: '.$file['mime']);header('Content-Length: '.(string)$file['size']);
        header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($file['name']));
        header('Cache-Control: private, no-store, max-age=0');readfile($file['path']);exit;
    }

    // V3 Fase 3: kasus, sesi, tautan, timeline, dan tanda mengetahui murobi.
    if ($method === 'GET' && $path === '/v3/konseling/options') {
        $santri=isset($_GET['santri_id'])?(int)$_GET['santri_id']:null;$tahun=isset($_GET['tahun_ajaran_id'])?(int)$_GET['tahun_ajaran_id']:null;
        JsonResponse::success(v3_konseling_service()->options($user,$santri,$tahun));
    }
    if ($method === 'GET' && $path === '/v3/konseling/kasus') {
        JsonResponse::success(v3_konseling_service()->page($user,$_GET));
    }
    if ($method === 'POST' && $path === '/v3/konseling/kasus') {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->createCase($user,$body);JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'GET' && preg_match('#^/v3/konseling/kasus/(\d+)/timeline$#',$path,$matches)) {
        JsonResponse::success(v3_konseling_service()->timeline($user,(int)$matches[1]));
    }
    if ($method === 'GET' && preg_match('#^/v3/konseling/kasus/(\d+)$#',$path,$matches)) {
        JsonResponse::success(v3_konseling_service()->show($user,(int)$matches[1]));
    }
    if ($method === 'PATCH' && preg_match('#^/v3/konseling/kasus/(\d+)$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->correctCase($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/kasus/(\d+)/status$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->transitionCase($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/kasus/(\d+)/tautan$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->addLinks($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/kasus/(\d+)/sesi$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->createSession($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/kasus/(\d+)/diketahui$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->acknowledge($user,'kasus',(int)$matches[1],$body);JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/sesi/(\d+)/status$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->transitionSession($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['status']);
    }
    if ($method === 'POST' && preg_match('#^/v3/konseling/sesi/(\d+)/diketahui$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->acknowledge($user,'sesi',(int)$matches[1],$body);JsonResponse::success($result['data'],$result['replayed']?200:$result['status']);
    }
    if ($method === 'PATCH' && preg_match('#^/v3/konseling/sesi/(\d+)$#',$path,$matches)) {
        $body=Request::json();$body['idempotency_key']??=$_SERVER['HTTP_IDEMPOTENCY_KEY']??null;$result=v3_konseling_service()->correctSession($user,(int)$matches[1],$body);JsonResponse::success($result['data'],$result['status']);
    }

    if ($method === 'GET' && $path === '/profile') {
        JsonResponse::success(api_auth_service()->profile($user));
    }
    if ($method === 'POST' && $path === '/auth/logout') {
        // V2 Fase 4: logout mencabut registrasi perangkat push agar sesi lama
        // tidak meninggalkan perangkat yang masih menerima push. Aplikasi
        // mengirim `push_token` miliknya; bila tidak dikirim, seluruh perangkat
        // akun ini dicabut. Pencabutan tidak pernah menggagalkan logout.
        $logoutBody = Request::json();
        $pushToken = isset($logoutBody['push_token']) ? (string) $logoutBody['push_token'] : null;
        $perangkatDicabut = notification_api_service()->revokeOnLogout($user, $pushToken);
        api_auth_service()->logout($user);
        JsonResponse::success([
            'message' => 'Logout berhasil.',
            'perangkat_push_dicabut' => $perangkatDicabut,
        ]);
    }
    if ($method === 'GET' && $path === '/me/capabilities') {
        JsonResponse::success(izin_api_service()->capabilities($user));
    }

    // ---------------------------------------------------------------------
    // V2 Fase 3: perizinan multi-peran (aditif; tidak mengubah endpoint V1).
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/izin/santri') {
        JsonResponse::success(izin_api_service()->santri($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/anak') {
        JsonResponse::success(izin_api_service()->anak($user));
    }
    if ($method === 'GET' && $path === '/izin/filters') {
        JsonResponse::success(izin_api_service()->filters($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/antrean') {
        JsonResponse::success(izin_api_service()->index($user, $_GET, true));
    }
    if ($method === 'GET' && $path === '/izin/admin/monitor') {
        JsonResponse::success(izin_api_service()->adminMonitor($user, $_GET));
    }
    if ($method === 'GET' && $path === '/izin/pengajuan') {
        JsonResponse::success(izin_api_service()->index($user, $_GET));
    }
    if ($method === 'POST' && $path === '/izin/pengajuan') {
        $result = izin_api_service()->create($user, Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->show($user, (int) $matches[1], $_GET));
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)/riwayat$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->history($user, (int) $matches[1], $_GET));
    }
    if ($method === 'GET' && preg_match('#^/izin/pengajuan/(\d+)/routing$#', $path, $matches)) {
        JsonResponse::success(izin_api_service()->routing($user, (int) $matches[1]));
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/penetapan-murobi$#', $path, $matches)) {
        $result = izin_api_service()->assign($user, (int) $matches[1], Request::json(), $requestMeta());
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/keputusan$#', $path, $matches)) {
        $result = izin_api_service()->decide($user, (int) $matches[1], Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/pembatalan$#', $path, $matches)) {
        $result = izin_api_service()->cancel($user, (int) $matches[1], Request::json(), $requestMeta(), $_GET);
        JsonResponse::success($result['data'], $result['status']);
    }
    if ($method === 'POST' && preg_match('#^/izin/pengajuan/(\d+)/koreksi$#', $path, $matches)) {
        $result = izin_api_service()->correct($user, (int) $matches[1], Request::json(), $requestMeta());
        JsonResponse::success($result['data'], $result['status']);
    }

    // ---------------------------------------------------------------------
    // V2 Fase 5: laporan perizinan, cetak/PDF, dan ekspor CSV.
    //
    // Aditif; tidak mengubah endpoint laporan absensi V1 (`/reports*`) yang
    // dipakai aplikasi guru. Aplikasi mobile memakai endpoint DI BAWAH INI —
    // aturan cakupannya tidak diduplikasi di sisi aplikasi. Cakupan dihitung
    // ulang server pada setiap permintaan oleh `izin_report_service()`.
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/izin/laporan') {
        JsonResponse::success(izin_report_service()->report($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/filters') {
        JsonResponse::success(izin_report_service()->options($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/cetak') {
        // HTML ramah cetak; aplikasi mengubahnya menjadi PDF dengan `expo-print`
        // sehingga dokumen web dan dokumen aplikasi identik.
        JsonResponse::success(izin_report_service()->printHtml($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/csv') {
        // Envelope JSON dipertahankan (konvensi API V1). Isi CSV dikirim sebagai
        // string agar aplikasi dapat menyimpannya sendiri; jumlah baris disertakan
        // supaya klien dapat memverifikasi bahwa ia menerima SELURUH hasil filter.
        JsonResponse::success(izin_report_service()->csv($user, $_GET, $modePreferensi()));
    }
    if ($method === 'GET' && $path === '/izin/laporan/explain') {
        // Hanya admin (dijaga di dalam layanan, bukan oleh urutan rute).
        JsonResponse::success(izin_report_service()->explain($user, $_GET, $modePreferensi()));
    }

    // ---------------------------------------------------------------------
    // V2 Fase 4: notifikasi in-app, perangkat push, dan panel kanal admin.
    //
    // Aditif; tidak mengubah satu pun endpoint V1 atau Fase 3. Rute admin
    // berada di bawah `/notifikasi/admin/...` dan penjaga aksesnya ada di
    // `NotificationAdminService` (capability admin dihitung ulang di server),
    // bukan pada urutan rute di berkas ini.
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/notifikasi') {
        JsonResponse::success(notification_api_service()->index($user, $_GET));
    }
    if ($method === 'GET' && $path === '/notifikasi/belum-dibaca') {
        JsonResponse::success(notification_api_service()->unreadCount($user));
    }
    if ($method === 'POST' && $path === '/notifikasi/dibaca-semua') {
        JsonResponse::success(notification_api_service()->markAllRead($user));
    }

    // Perangkat push (registrasi, daftar, pencabutan, sakelar per perangkat).
    if ($method === 'GET' && $path === '/notifikasi/perangkat') {
        JsonResponse::success(notification_api_service()->devices($user));
    }
    if ($method === 'POST' && $path === '/notifikasi/perangkat') {
        JsonResponse::success(notification_api_service()->registerDevice($user, Request::json()), 201);
    }
    if ($method === 'POST' && $path === '/notifikasi/perangkat/pencabutan') {
        JsonResponse::success(notification_api_service()->revokeDevice($user, Request::json()));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/perangkat/(\d+)/push$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->setDevicePush($user, (int) $matches[1], Request::json()));
    }

    // Panel kanal admin.
    if ($method === 'GET' && $path === '/notifikasi/admin/status') {
        JsonResponse::success(notification_api_service()->adminStatus($user));
    }
    if ($method === 'GET' && $path === '/notifikasi/admin/kegagalan') {
        JsonResponse::success(notification_api_service()->adminFailures($user, $_GET));
    }
    if ($method === 'GET' && $path === '/notifikasi/admin/audit') {
        JsonResponse::success(notification_api_service()->adminAudit($user, $_GET));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/pemeriksaan') {
        JsonResponse::success(notification_api_service()->adminCheck($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/sakelar') {
        JsonResponse::success(notification_api_service()->adminToggle($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/pesan-uji') {
        JsonResponse::success(notification_api_service()->adminTestMessage($user, Request::json(), $requestMeta()));
    }
    if ($method === 'POST' && $path === '/notifikasi/admin/worker') {
        JsonResponse::success(notification_api_service()->adminRunWorker($user, Request::json()));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/admin/kegagalan/(\d+)/coba-ulang$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->adminRetry($user, (int) $matches[1], $requestMeta()));
    }

    // Detail dan penandaan baca satu notifikasi. Diletakkan SETELAH rute
    // literal di atas agar `/notifikasi/perangkat` tidak pernah tertangkap
    // sebagai id. Cakupan tetap dijaga server: id yang bukan milik pengguna
    // dijawab 403, bukan isi notifikasi orang lain.
    if ($method === 'GET' && preg_match('#^/notifikasi/(\d+)$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->show($user, (int) $matches[1]));
    }
    if ($method === 'POST' && preg_match('#^/notifikasi/(\d+)/dibaca$#', $path, $matches)) {
        JsonResponse::success(notification_api_service()->markRead($user, (int) $matches[1]));
    }

    // ---------------------------------------------------------------------
    // V1: jadwal, pertemuan, absensi, dan laporan (kontrak tidak berubah).
    // ---------------------------------------------------------------------
    if ($method === 'GET' && $path === '/schedules/today') {
        JsonResponse::success(teacher_api_service()->today(api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/schedules') {
        JsonResponse::success(teacher_api_service()->schedules(api_authenticator()->assertScheduleAccess($user), $_GET));
    }
    if ($method === 'GET' && $path === '/meetings') {
        JsonResponse::success(teacher_api_service()->meetings(api_authenticator()->assertScheduleAccess($user), $_GET));
    }
    if ($method === 'GET' && $path === '/reports') {
        JsonResponse::success(report_service()->apiReport($_GET, api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/reports/filters') {
        JsonResponse::success(report_service()->apiOptions(api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && $path === '/reports/print') {
        JsonResponse::success([
            'html' => PrintRenderer::report(report_service()->apiPrintRows($_GET, api_authenticator()->assertScheduleAccess($user))),
        ]);
    }
    if ($method === 'GET' && preg_match('#^/reports/meetings/(\d+)$#', $path, $matches)) {
        JsonResponse::success(report_service()->meeting((int) $matches[1], api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'GET' && preg_match('#^/schedules/(\d+)$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->schedule((int) $matches[1], isset($_GET['date']) ? (string) $_GET['date'] : null, api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'POST' && preg_match('#^/schedules/(\d+)/meetings$#', $path, $matches)) {
        $result = teacher_api_service()->openMeeting((int) $matches[1], Request::json(), api_authenticator()->assertScheduleAccess($user));
        JsonResponse::success($result['data'], $result['replayed'] ? 200 : 201);
    }
    if ($method === 'GET' && preg_match('#^/meetings/(\d+)(?:/attendance)?$#', $path, $matches)) {
        JsonResponse::success(teacher_api_service()->meeting((int) $matches[1], api_authenticator()->assertScheduleAccess($user)));
    }
    if ($method === 'PUT' && preg_match('#^/meetings/(\d+)/attendance$#', $path, $matches)) {
        $result = teacher_api_service()->saveAttendance((int) $matches[1], Request::json(), api_authenticator()->assertScheduleAccess($user));
        JsonResponse::success($result['data']);
    }

    throw new ApiException('NOT_FOUND', 'Endpoint tidak ditemukan.', 404);
} catch (\App\Izin\IzinException $exception) {
    // V7: `IzinWaliService` melempar IzinException langsung (V2 menerjemahkannya
    // sendiri di IzinApiService, sehingga jalur V2 tidak berubah).
    $status = $exception->status();
    JsonResponse::error(match($status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',503=>'SERVICE_UNAVAILABLE',default=>'VALIDATION_FAILED'},$exception->getMessage(),$status);
} catch (\App\Bsa\BsaException $exception) {
    JsonResponse::error(match($exception->status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',503=>'SERVICE_UNAVAILABLE',default=>'VALIDATION_FAILED'},$exception->getMessage(),$exception->status);
} catch (\App\Kesehatan\KesehatanException $exception) {
    JsonResponse::error(match($exception->status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',503=>'SERVICE_UNAVAILABLE',default=>'VALIDATION_FAILED'},$exception->getMessage(),$exception->status);
} catch (\App\Penugasan\PenugasanException $exception) {
    $status = $exception->status();
    JsonResponse::error(match($status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',default=>'VALIDATION_FAILED'},$exception->getMessage(),$status,$exception->errors());
} catch (\App\V4\V4Exception $exception) {
    JsonResponse::error(match($exception->status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',503=>'SERVICE_UNAVAILABLE',default=>'VALIDATION_FAILED'},$exception->getMessage(),$exception->status);
} catch (\App\V3\V3Exception $exception) {
    $code=match($exception->status){403=>'FORBIDDEN',404=>'NOT_FOUND',409=>'CONFLICT',503=>'SERVICE_UNAVAILABLE',default=>'VALIDATION_FAILED'};
    JsonResponse::error($code,$exception->getMessage(),$exception->status);
} catch (ApiException $exception) {
    JsonResponse::error($exception->errorCode(), $exception->getMessage(), $exception->status(), $exception->details());
} catch (Throwable $exception) {
    error_log((string) $exception);
    JsonResponse::error(
        'SERVER_ERROR',
        app_config('debug') ? $exception->getMessage() : 'Terjadi kesalahan pada server.',
        500
    );
}
