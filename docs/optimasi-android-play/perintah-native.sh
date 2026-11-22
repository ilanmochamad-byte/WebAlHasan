#!/usr/bin/env bash
# Matriks native Android untuk optimasi Google Play (GP-1 R8 / GP-2 API tampilan),
# 21 November 2026. BERURUTAN; hanya data sintetis `test_*_test` dan 127.0.0.1.
#
# Prasyarat:
#  - Satu emulator berjalan (adb devices) dengan APK UJI Release R8 1.9.1 terpasang:
#    salinan `uji` dari sumber handoff + `network_security_config_uji.xml`
#    (cleartext HANYA 127.0.0.1) + EXPO_PUBLIC_API_BASE_URL=http://127.0.0.1:8971/api/v1.
#    Bundel JavaScript tertanam; tanpa Metro/dev client. Lihat IMPLEMENTASI.md §5.
#  - adb reverse tcp:8971 tcp:8971 (dibuat skrip ini).
#
#   bash docs/optimasi-android-play/perintah-native.sh <keluaran> [bagian…]
#
# Bagian: f1 profil izin pengajian keuangan t7 v5 pk
# Suite dijalankan dengan RILIS=1 (peluncuran lewat LAUNCHER, bukan URL dev client).
set -u
cd "$(dirname "$0")/../.."
OUT="${1:?folder keluaran}"; shift
WEB=http://127.0.0.1:8971
BAGIAN="${*:-f1 profil izin pengajian keuangan t7 v5 pk}"
ADB="${ADB:-$HOME/Library/Android/sdk/platform-tools/adb}"
mkdir -p "$OUT"; OUT="$(cd "$OUT" && pwd)"
q() { mysql -N -B "$1" -e "$2"; }

api() { # api <DB>
  pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null; sleep 1
  DB_NAME="$1" PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8971 tests/redesain_web_tahap1_router.php >> "$OUT/api.log" 2>&1 &
  for i in $(seq 1 20); do curl -s -o /dev/null "$WEB/api/v1/profile" && break; sleep 0.5; done
  "$ADB" reverse tcp:8971 tcp:8971 > /dev/null
}
jalan() { # jalan <nama> <perintah…>
  local nama="$1"; shift
  (cd tests/browser && env RILIS=1 METRO= "$@") > "$OUT/$nama.txt" 2>&1
  echo "$nama exit=$? :: $(grep -E '^\[lulus\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') lulus / $(grep -E '^\[gagal\]' "$OUT/$nama.txt" | wc -l | tr -d ' ') gagal :: $(grep -E 'Ringkasan' "$OUT/$nama.txt" | tail -1)"
}
f5fixture() { # fixture laporan V6 Fase 5 (worker resmi), sama dengan fase-3/perintah-uji.sh
  local d="$1"; touch "$OUT/aba"
  DB_NAME="$d" php tests/v6_phase5_worker.php "$OUT/aba" "$(q "$d" "SELECT id FROM users WHERE username='sbx_v6_bsa_b'")" fixture \
    "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0001'")" "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0002'")" "$(q "$d" "SELECT id FROM santri WHERE nis='V6S0004'")" > /dev/null
}

for b in $BAGIAN; do case "$b" in
  f1)
    D=test_oap_app_test
    PIF1_DB=$D bash tests/profil_impor_f1_db.sh > /dev/null
    PIF2_DB=$D php tests/profil_impor_f2_app_seed.php > "$OUT/ids-profil.json"
    RTV2_DB=$D php tests/redesain_v2_fase1_seed.php > /dev/null; api $D
    jalan f1 env OUT_DIR="$OUT/tangkapan-f1" node uji-redesain-v2-fase1-android.mjs ;;
  profil)
    D=test_oap_pif2_test
    PIF1_DB=$D bash tests/profil_impor_f1_db.sh > /dev/null
    PIF2_DB=$D php tests/profil_impor_f2_app_seed.php > "$OUT/ids-pif2.json"; api $D
    # Foto sintetis untuk Photo Picker sistem (bukan foto orang).
    sips -s format jpeg -Z 512 docs/redesain-tampilan-v2/aset/Logo_alhasanApps.png --out "$OUT/foto-uji.jpg" > /dev/null
    "$ADB" push "$OUT/foto-uji.jpg" /sdcard/Pictures/foto-uji.jpg > /dev/null
    "$ADB" shell am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Pictures/foto-uji.jpg > /dev/null
    jalan profil env DB_NAME=$D SEED_JSON="$OUT/ids-pif2.json" OUT_DIR="$OUT/tangkapan-profil" node uji-profil-impor-f2-android.mjs ;;
  izin)
    D=test_oap_v7_test; V7F4_DB=$D bash tests/v7_phase4_db.sh > /dev/null; api $D
    jalan izin env DB_NAME=$D OUT_DIR="$OUT/tangkapan-izin" node uji-redesain-v2-fase2-android.mjs izin ;;
  pengajian)
    D=test_oap_jkg_test
    JKG_DB=$D bash tests/jadwal_kelas_gabungan_f1_db.sh > /dev/null
    JKG_DB=$D php tests/jadwal_kelas_gabungan_f2_seed.php > "$OUT/ids-jkg.json"; api $D
    jalan pengajian env DB_NAME=$D SEED_JSON="$OUT/ids-jkg.json" OUT_DIR="$OUT/tangkapan-pengajian" node uji-redesain-v2-fase2-android.mjs pengajian ;;
  keuangan)
    D=test_oap_v6_test
    V6F5_DB=$D bash tests/v6_phase5_db.sh > /dev/null && f5fixture $D && DB_NAME=$D php tests/v6_tagihan_satu_santri_seed.php > /dev/null; api $D
    jalan keuangan env DB_NAME=$D OUT_DIR="$OUT/tangkapan-keuangan" node uji-redesain-v2-fase3-android.mjs ;;
  t7)
    D=test_oap_t7_test; TAHAP7_DB=$D bash tests/redesain_web_tahap7_db.sh > /dev/null; api $D
    jalan t7 env DB_NAME=$D OUT_DIR="$OUT/tangkapan-t7" node uji-redesain-v2-fase4-android.mjs t7 ;;
  v5)
    D=test_oap_v5_test; V5F3_DB=$D bash tests/v5_phase3_db.sh > /dev/null; api $D
    # Fixture V5 Fase 3 tidak memuat kunjungan; buat satu kunjungan SINTETIS lewat REST
    # resmi sebagai petugas (sama dengan catatan TL-30 tindak lanjut redesain).
    TOK=$(curl -s -X POST "$WEB/api/v1/auth/login" -H 'Content-Type: application/json' \
      -d '{"username":"sbx_v5_petugas_f","password":"Sandbox#123","device_name":"oap-fixture"}' | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["data"]["token"] ?? "";')
    SID=$(q $D "SELECT id FROM santri WHERE is_active=1 AND archived_at IS NULL ORDER BY id LIMIT 1")
    curl -s -o /dev/null -w "fixture kunjungan HTTP %{http_code}\n" -X POST "$WEB/api/v1/v5/kesehatan/kunjungan" \
      -H "Authorization: Bearer $TOK" -H 'Content-Type: application/json' -H "Idempotency-Key: oap-$(date +%s)" \
      -d "{\"santri_id\":$SID,\"waktu_kunjungan\":\"$(date -v-20M '+%Y-%m-%d %H:%M:%S')\",\"keluhan\":\"uji sintetis pusing\",\"catatan_klinis\":\"uji sintetis tensi normal\",\"tindakan\":\"uji sintetis istirahat\"}"
    jalan v5 env DB_NAME=$D OUT_DIR="$OUT/tangkapan-v5" node uji-redesain-v2-fase4-android.mjs v5 ;;
  pk)
    D=test_oap_pk_test
    PKF3_DB=$D bash tests/penugasan_keamanan_f4_db.sh > /dev/null
    PKF3_DB=$D php tests/penugasan_keamanan_f3_seed.php > "$OUT/ids-pk.json"; api $D
    jalan pk env DB_NAME=$D SEED_JSON="$OUT/ids-pk.json" OUT_DIR="$OUT/tangkapan-pk" node uji-redesain-v2-fase4-android.mjs pk ;;
  *) echo "bagian tidak dikenal: $b" ;;
esac; done
pkill -f "php -S 127.0.0.1:8971 " 2>/dev/null
echo "galat PHP server API: $(grep -cE 'PHP (Warning|Notice|Fatal|Deprecated)|Uncaught' "$OUT/api.log" 2>/dev/null || echo 0)"
