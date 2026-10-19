#!/bin/bash
set -euo pipefail

SOURCE="/DATA/k1807225/repositories/WebAlHasan"
TARGET="/DATA/k1807225/public_html"

fail() {
  printf 'Deployment dihentikan: %s\n' "$*" >&2
  exit 1
}

DRY_RUN=0
case "${1:-}" in
  '') test "$#" -eq 0 || fail 'Argumen tidak dikenal.' ;;
  --dry-run) test "$#" -eq 1 || fail 'Gunakan hanya --dry-run.'; DRY_RUN=1 ;;
  *) fail 'Gunakan tanpa argumen untuk deploy, atau --dry-run untuk simulasi.' ;;
esac

# Utilitas dasar shell; rsync, PHP CLI, dan Python tidak diperlukan.
for tool in git cmp cp mkdir mktemp rm; do
  command -v "$tool" >/dev/null || fail "Program $tool tidak tersedia."
done

cd "$SOURCE"
SOURCE="$(pwd -P)"
test "$(git rev-parse --show-toplevel)" = "$SOURCE" || fail 'Sumber bukan akar repositori.'
test "$(git branch --show-current)" = main || fail 'Branch sumber harus main.'
test -z "$(git status --porcelain --untracked-files=all)" || fail 'Repositori sumber belum bersih.'
test -d "$TARGET" || fail 'Direktori tujuan tidak ditemukan.'
TARGET="$(cd "$TARGET" && pwd -P)"
case "$TARGET/" in "$SOURCE/"*) fail 'Tujuan tidak boleh berada di dalam sumber.' ;; esac
case "$SOURCE/" in "$TARGET/"*) fail 'Sumber tidak boleh berada di dalam tujuan.' ;; esac
test -f "$TARGET/.env" || fail '.env produksi tidak ditemukan.'

# Tolak symlink dan konflik tipe agar penyalinan tidak keluar dari pohon tujuan.
check_path() {
  local cursor="$1" rest="$2" part
  while [[ "$rest" == */* ]]; do
    part="${rest%%/*}"
    rest="${rest#*/}"
    cursor="$cursor/$part"
    test ! -L "$cursor" || fail "Symlink direktori: $2"
    if test -e "$cursor" && test ! -d "$cursor"; then
      fail "Induk bukan direktori: $2"
    fi
  done
  cursor="$cursor/$rest"
  test ! -L "$cursor" || fail "Symlink file: $2"
  if test -e "$cursor" && test ! -f "$cursor"; then
    fail "Tujuan/sumber bukan file biasa: $2"
  fi
}

# Manifest NUL menangani spasi/newline; kegagalan git tidak tersembunyi di pipe.
MANIFEST="$(mktemp "${TMPDIR:-/tmp}/webalhasan-deploy.XXXXXX")"
PLAN=''
trap 'rm -f "$MANIFEST"; if test -n "$PLAN"; then rm -f "$PLAN"; fi' EXIT
PLAN="$(mktemp "${TMPDIR:-/tmp}/webalhasan-deploy-plan.XXXXXX")"
git ls-files -z > "$MANIFEST"

# Validasi seluruh rencana sebelum menulis apa pun ke public_html.
COUNT=0
while IFS= read -r -d '' file; do
  case "/$file/" in
    */.git/*|*/.env*/*|*/error_log/*|*/*.log/*|*/file_download/*|*/uploads/*) continue ;;
  esac
  case "$file" in
    admin/*|api/*|app/*|assets/*|bin/*|database/*|portal/*|composer.json|composer.lock) ;;
    *.php) [[ "$file" != */* ]] || continue ;;
    *) continue ;;
  esac

  check_path "$SOURCE" "$file"
  check_path "$TARGET" "$file"
  test -f "$SOURCE/$file" || fail "File sumber tidak ditemukan: $file"
  if test -f "$TARGET/$file"; then
    if cmp -s "$SOURCE/$file" "$TARGET/$file"; then
      continue
    else
      result=$?
      test "$result" -eq 1 || fail "Tidak dapat membandingkan file: $file"
    fi
  fi
  printf '%s\0' "$file" >> "$PLAN"
  COUNT=$((COUNT + 1))
done < "$MANIFEST"

if test "$DRY_RUN" -eq 1; then
  printf 'SIMULASI: tidak menulis ke public_html.\n'
else
  printf 'DEPLOY: menyalin kode; gunakan maintenance dan backup sebelum menjalankan.\n'
fi

umask 022
while IFS= read -r -d '' file; do
  printf 'UPDATE %q\n' "$file"
  if test "$DRY_RUN" -eq 0; then
    check_path "$SOURCE" "$file"
    check_path "$TARGET" "$file"
    parent="${file%/*}"
    if [[ "$file" == */* ]]; then
      mkdir -p "$TARGET/$parent"
    fi
    cp "$SOURCE/$file" "$TARGET/$file"
  fi
done < "$PLAN"

printf 'Selesai: %s file berbeda; tidak ada penghapusan file.\n' "$COUNT"
