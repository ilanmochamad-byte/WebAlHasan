#!/usr/bin/env bash
# Server uji workstream keamanan PSB: Apache + php-fpm lokal di atas SALINAN
# sumber (bukan checkout Git), agar aturan `.htaccess` akar dan
# `berkas_psb/.htaccess` ikut teruji seperti di hosting, dan berkas unggahan
# sintetis tidak pernah masuk checkout.
#
#   KPSB_DIR=/private/tmp/<privat> bash tests/keamanan_psb_server.sh sync
#   KPSB_DIR=... KPSB_PORT=8971 bash tests/keamanan_psb_server.sh start
#   KPSB_DIR=... bash tests/keamanan_psb_server.sh stop
#
# Salinan memakai `.env` sendiri dengan DB_NAME=${KPSB_DB:-test_keamanan_psb_test}
# (wajib test_*_test). Folder berkas_psb/ salinan TIDAK membawa berkas
# terlacak repositori; fixture sintetis dibuat suite uji. Modul Apache diambil
# dari ${APACHE_MODULES:-/usr/libexec/apache2} (macOS).
set -euo pipefail
cd "$(dirname "$0")/.."

DIR="${KPSB_DIR:?KPSB_DIR wajib diisi (folder privat di luar checkout)}"
PORT="${KPSB_PORT:-8971}"
FPM_PORT="${KPSB_FPM_PORT:-$((PORT + 1000))}"
DB="${KPSB_DB:-test_keamanan_psb_test}"
MOD="${APACHE_MODULES:-/usr/libexec/apache2}"
case "$DB" in test_*_test) ;; *) echo "Nama DB harus test_*_test" >&2; exit 2;; esac
case "$DIR/" in "$PWD/"*) echo "KPSB_DIR tidak boleh di dalam checkout" >&2; exit 2;; esac
WEB="$DIR/web"

sync_web() {
  mkdir -p "$WEB"
  chmod 700 "$DIR"
  rsync -a --delete \
    --exclude '/.git' --exclude '/.env*' --exclude '/.claude' --exclude '/docs' --exclude '/output' \
    --exclude '/tests/browser/node_modules' --exclude '/tests/browser/tangkapan*' \
    --exclude '/berkas_psb/*' --include '/berkas_psb/.htaccess' \
    --exclude '/storage/private/psb/*' --exclude '/k1807225_webalhasan.sql' \
    --filter 'protect /berkas_psb/*' --filter 'protect /storage/private/psb/*' \
    --filter 'protect /.env' \
    ./ "$WEB/"
  cp berkas_psb/.htaccess "$WEB/berkas_psb/.htaccess"
  sed -e "s/^DB_NAME=.*/DB_NAME=$DB/" -e "s#^APP_URL=.*#APP_URL=http://127.0.0.1:$PORT#" -e 's/^APP_ENV=.*/APP_ENV=testing/' .env > "$WEB/.env"
  chmod 600 "$WEB/.env"
}

start() {
  sync_web
  mkdir -p "$DIR/run"
  cat > "$DIR/run/php-fpm.conf" <<EOF
[global]
pid = $DIR/run/php-fpm.pid
error_log = $DIR/run/php-fpm.log
daemonize = yes
[www]
listen = 127.0.0.1:$FPM_PORT
listen.allowed_clients = 127.0.0.1
pm = static
pm.max_children = 6
clear_env = no
catch_workers_output = yes
php_admin_value[error_log] = $DIR/run/php-error.log
php_admin_flag[log_errors] = on
php_admin_value[upload_max_filesize] = 8M
php_admin_value[post_max_size] = 10M
EOF
  cat > "$DIR/run/httpd.conf" <<EOF
ServerRoot "/usr"
Listen 127.0.0.1:$PORT
ServerName 127.0.0.1
TraceEnable off
DefaultRuntimeDir "$DIR/run"
Mutex file:$DIR/run default
PidFile "$DIR/run/httpd.pid"
ErrorLog "$DIR/run/httpd-error.log"
LoadModule mpm_prefork_module $MOD/mod_mpm_prefork.so
LoadModule authz_core_module $MOD/mod_authz_core.so
LoadModule authz_host_module $MOD/mod_authz_host.so
LoadModule access_compat_module $MOD/mod_access_compat.so
LoadModule unixd_module $MOD/mod_unixd.so
LoadModule dir_module $MOD/mod_dir.so
LoadModule mime_module $MOD/mod_mime.so
LoadModule log_config_module $MOD/mod_log_config.so
LoadModule rewrite_module $MOD/mod_rewrite.so
LoadModule setenvif_module $MOD/mod_setenvif.so
LoadModule headers_module $MOD/mod_headers.so
LoadModule proxy_module $MOD/mod_proxy.so
LoadModule proxy_fcgi_module $MOD/mod_proxy_fcgi.so
TypesConfig /private/etc/apache2/mime.types
LogFormat "%h %r %>s" kpsb
CustomLog "$DIR/run/httpd-access.log" kpsb
DocumentRoot "$WEB"
DirectoryIndex index.php index.html
<Directory "$WEB">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
# admin/.htaccess memetakan PHP ke handler cPanel (x-httpd-alt-php83) yang
# tidak ada di Apache lokal; di server uji folder itu memakai handler fpm di
# bawah dengan perlindungan yang sama (tanpa daftar isi, .ht* ditolak).
<Directory "$WEB/admin">
    AllowOverride None
    Options -Indexes
</Directory>
<FilesMatch "^\.ht">
    Require all denied
</FilesMatch>
<FilesMatch "\.php$">
    SetHandler "proxy:fcgi://127.0.0.1:$FPM_PORT"
</FilesMatch>
EOF
  php-fpm -y "$DIR/run/php-fpm.conf"
  httpd -f "$DIR/run/httpd.conf" -k start
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break
    sleep 0.1
  done
  echo "Server uji keamanan PSB: http://127.0.0.1:$PORT (DB $DB)"
}

stop() {
  [ -f "$DIR/run/httpd.pid" ] && httpd -f "$DIR/run/httpd.conf" -k stop || true
  [ -f "$DIR/run/php-fpm.pid" ] && kill "$(cat "$DIR/run/php-fpm.pid")" 2>/dev/null || true
  rm -f "$DIR/run/httpd.pid" "$DIR/run/php-fpm.pid"
}

case "${1:-}" in
  sync) sync_web ;;
  start) start ;;
  stop) stop ;;
  *) echo "Pakai: sync | start | stop" >&2; exit 2 ;;
esac
