<?php

declare(strict_types=1);

/**
 * Diagnosa "kenapa peran X tidak menerima notifikasi".
 *
 * HANYA MEMBACA. Skrip ini tidak pernah menulis, mengubah, atau menghapus apa
 * pun — aman dijalankan pada produksi.
 *
 * Yang dilaporkan:
 *
 *  1. Sebaran notifikasi in-app yang sudah ada, per peran.
 *  2. Kesiapan relasi tiap peran — akun yang benar-benar DAPAT menjadi
 *     penerima. Penerima ditentukan dari relasi nyata (penugasan murobi aktif,
 *     tautan pengurus, relasi wali–santri), bukan dari nama role, sehingga
 *     akun tanpa relasi tidak akan pernah menerima notifikasi.
 *  3. Untuk satu pengajuan (opsional): penerima yang dihitung untuk SETIAP
 *     peristiwa, sehingga terlihat peristiwa mana yang memang tidak ditujukan
 *     kepada peran tertentu.
 *
 * Pemakaian:
 *
 *   php bin/v2_phase4_diagnose_notifikasi.php
 *   php bin/v2_phase4_diagnose_notifikasi.php --pengajuan=123
 */

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Notification\NotificationEvent;
use App\Notification\RecipientResolver;

$argumen = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--pengajuan=(\d+)$/', $arg, $m) === 1) {
        $argumen['pengajuan'] = (int) $m[1];
        continue;
    }
    fwrite(STDERR, "Argumen tidak dikenal: {$arg}" . PHP_EOL);
    exit(2);
}

$db = app_db();
$baris = static function (string $sql, array $params = []) use ($db): array {
    if ($params === []) {
        $result = $db->query($sql);

        return $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
    }
    $statement = $db->prepare($sql);
    if ($statement === false) {
        return [];
    }
    $statement->bind_param(str_repeat('i', count($params)), ...$params);
    $statement->execute();
    $rows = $statement->get_result()?->fetch_all(MYSQLI_ASSOC) ?? [];
    $statement->close();

    return $rows;
};

$judul = static function (string $teks): void {
    echo PHP_EOL . $teks . PHP_EOL . str_repeat('-', strlen($teks)) . PHP_EOL;
};

// ---------------------------------------------------------------------------
$judul('1. Notifikasi in-app yang sudah ada, per peran');

$sebaran = $baris(
    "SELECT r.slug AS peran,
            COUNT(DISTINCT u.id) AS akun_aktif,
            COUNT(o.id) AS notifikasi,
            COUNT(DISTINCT o.penerima_user_id) AS akun_punya_notifikasi
       FROM roles r
       JOIN user_roles ur ON ur.role_id = r.id
       JOIN users u ON u.id = ur.user_id AND u.is_active = 1
       LEFT JOIN notifikasi_outbox o
              ON o.penerima_user_id = u.id AND o.kanal = 'InApp'
      GROUP BY r.slug
      ORDER BY r.slug"
);

printf("%-14s %12s %14s %22s\n", 'peran', 'akun aktif', 'notifikasi', 'akun punya notifikasi');
foreach ($sebaran as $row) {
    printf(
        "%-14s %12d %14d %22d\n",
        $row['peran'],
        (int) $row['akun_aktif'],
        (int) $row['notifikasi'],
        (int) $row['akun_punya_notifikasi']
    );
}

$peristiwa = $baris(
    "SELECT event_key, COUNT(*) AS jumlah
       FROM notifikasi_outbox
      WHERE kanal = 'InApp'
      GROUP BY event_key
      ORDER BY jumlah DESC
      LIMIT 20"
);
if ($peristiwa !== []) {
    echo PHP_EOL . 'Peristiwa yang sudah pernah menghasilkan notifikasi:' . PHP_EOL;
    foreach ($peristiwa as $row) {
        printf("  %-52s %5d\n", (string) $row['event_key'], (int) $row['jumlah']);
    }
} else {
    echo PHP_EOL . 'Belum ada notifikasi in-app sama sekali.' . PHP_EOL;
}

// ---------------------------------------------------------------------------
$judul('2. Kesiapan relasi — akun yang DAPAT menjadi penerima');

$hitung = static function (string $sql) use ($baris): int {
    $rows = $baris($sql);

    return (int) ($rows[0]['jumlah'] ?? 0);
};

$murobiSiap = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN guru g ON g.id = u.guru_id
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'guru'
       JOIN murobi_assignments ma ON ma.guru_id = g.id
       JOIN tahun_ajaran ta ON ta.id = ma.tahun_ajaran_id
       LEFT JOIN kelas kl ON kl.id = ma.kelas_id AND kl.is_active = 1 AND kl.archived_at IS NULL
      WHERE u.is_active = 1
        AND g.is_active = 1 AND g.archived_at IS NULL
        AND ma.is_active = 1 AND ma.archived_at IS NULL
        AND ma.tanggal_mulai <= CURDATE()
        AND (ma.tanggal_selesai IS NULL OR ma.tanggal_selesai >= CURDATE())
        AND ta.status = 'Aktif' AND ta.archived_at IS NULL
        AND (ma.target_type = 'Kamar' OR (ma.target_type = 'Kelas' AND kl.id IS NOT NULL))"
);
$guruBerakun = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'guru'
      WHERE u.is_active = 1 AND u.guru_id IS NOT NULL"
);

$pengurusSiap = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN pengurus p ON p.id = u.pengurus_id
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
      WHERE u.is_active = 1 AND p.is_active = 1 AND p.archived_at IS NULL"
);
$pengurusBerakun = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'pengurus'
      WHERE u.is_active = 1"
);

$waliSiap = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN wali w ON w.id = u.wali_id
       JOIN santri_wali sw ON sw.wali_id = w.id AND sw.archived_at IS NULL
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
      WHERE u.is_active = 1 AND w.is_active = 1 AND w.archived_at IS NULL"
);
$waliBerakun = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'orang_tua'
      WHERE u.is_active = 1"
);

$adminSiap = $hitung(
    "SELECT COUNT(DISTINCT u.id) AS jumlah
       FROM users u
       JOIN user_roles ur ON ur.user_id = u.id
       JOIN roles r ON r.id = ur.role_id AND r.slug = 'admin'
      WHERE u.is_active = 1"
);

printf("%-34s %10s %10s\n", 'syarat penerima', 'akun', 'siap');
printf("%-34s %10d %10d\n", 'admin (role admin aktif)', $adminSiap, $adminSiap);
printf("%-34s %10d %10d\n", 'murobi (penugasan aktif)', $guruBerakun, $murobiSiap);
printf("%-34s %10d %10d\n", 'pengurus (tertaut baris pengurus)', $pengurusBerakun, $pengurusSiap);
printf("%-34s %10d %10d\n", 'orang tua (relasi santri_wali)', $waliBerakun, $waliSiap);

foreach ([
    ['murobi', $guruBerakun, $murobiSiap, 'Guru punya akun tetapi tidak punya penugasan murobi AKTIF pada tahun ajaran berstatus Aktif. Periksa murobi_assignments dan tahun_ajaran.'],
    ['pengurus', $pengurusBerakun, $pengurusSiap, 'Akun ber-role pengurus belum tertaut ke baris pengurus aktif (users.pengurus_id kosong atau pengurus nonaktif).'],
    ['orang tua', $waliBerakun, $waliSiap, 'Akun ber-role orang_tua belum tertaut ke wali aktif dengan relasi santri_wali (users.wali_id kosong atau relasi diarsipkan).'],
] as [$nama, $adaAkun, $siap, $pesan]) {
    if ($adaAkun > 0 && $siap === 0) {
        echo PHP_EOL . "PERHATIAN ({$nama}): {$pesan}" . PHP_EOL;
    }
}

// ---------------------------------------------------------------------------
$judul('3. Peristiwa mana yang memang tidak ditujukan kepada peran tertentu');

echo <<<'TEKS'
Penerima ditentukan per peristiwa, dan pelaku peristiwa tidak pernah diberi
notifikasi atas tindakannya sendiri:

  pengajuan_dibuat        -> murobi tujuan saja
                             (pengurus pengaju TIDAK, karena dialah pelakunya;
                              orang tua TIDAK, karena keputusan belum ada)
  routing_perlu_admin     -> admin saja
  murobi_ditetapkan       -> murobi + pengurus
  keputusan_disetujui     -> pengurus + pengaju + orang tua
  keputusan_ditolak       -> pengurus + pengaju + orang tua
  pembatalan              -> murobi + pengurus + pengaju (admin bila belum ada murobi)
  koreksi                 -> pengurus + pengaju + orang tua + murobi

Artinya: setelah HANYA membuat satu pengajuan uji, wajar bila hanya satu peran
yang menerima notifikasi. Orang tua baru menerima setelah ada KEPUTUSAN.

TEKS;

// ---------------------------------------------------------------------------
if (isset($argumen['pengajuan'])) {
    $id = $argumen['pengajuan'];
    $judul("4. Penelusuran pengajuan #{$id}");

    $rows = $baris(
        'SELECT id, santri_id, pengurus_id, murobi_guru_id, diajukan_oleh_user_id, status
           FROM izin_pengajuan WHERE id = ?',
        [$id]
    );
    if ($rows === []) {
        echo "Pengajuan #{$id} tidak ditemukan." . PHP_EOL;
        exit(1);
    }
    $pengajuan = $rows[0];

    foreach (['santri_id', 'pengurus_id', 'murobi_guru_id', 'diajukan_oleh_user_id', 'status'] as $kolom) {
        printf("  %-24s %s\n", $kolom, (string) ($pengajuan[$kolom] ?? 'NULL'));
    }

    if (($pengajuan['murobi_guru_id'] ?? null) === null) {
        echo PHP_EOL
            . "  murobi_guru_id kosong: pengajuan ini TIDAK dirutekan ke murobi mana pun,"
            . PHP_EOL
            . "  sehingga peristiwanya adalah routing_perlu_admin dan HANYA admin yang"
            . PHP_EOL
            . "  diberi tahu. Inilah sebab paling umum notifikasi 'hanya muncul di admin'."
            . PHP_EOL;
    }

    $resolver = new RecipientResolver($db);
    echo PHP_EOL . '  Penerima yang dihitung untuk tiap peristiwa:' . PHP_EOL;
    foreach (NotificationEvent::PERISTIWA_IZIN as $event) {
        $penerima = $resolver->forEvent($event, $pengajuan);
        printf(
            "    %-32s %s\n",
            $event,
            $penerima === [] ? '(kosong)' : 'user_id ' . implode(', ', $penerima)
        );
    }

    $terkirim = $baris(
        "SELECT event_key, kanal, penerima_user_id, status
           FROM notifikasi_outbox
          WHERE event_key LIKE CONCAT('izin:', ?, ':%')
          ORDER BY id",
        [$id]
    );
    echo PHP_EOL . '  Notifikasi yang BENAR-BENAR tercatat untuk pengajuan ini:' . PHP_EOL;
    if ($terkirim === []) {
        echo '    (belum ada)' . PHP_EOL;
    }
    foreach ($terkirim as $row) {
        printf(
            "    %-46s %-8s user_id %-6s %s\n",
            (string) $row['event_key'],
            (string) $row['kanal'],
            (string) $row['penerima_user_id'],
            (string) $row['status']
        );
    }
}

echo PHP_EOL . 'Selesai. Skrip ini tidak mengubah apa pun.' . PHP_EOL;
