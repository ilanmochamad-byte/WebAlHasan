#!/bin/bash
set -euo pipefail

SOURCE="/DATA/k1807225/repositories/WebAlHasan"
TARGET="/DATA/k1807225/public_html"

cd "$SOURCE"

# Produksi hanya mengambil branch main yang bersih.
test "$(git branch --show-current)" = "main"
test -z "$(git status --porcelain)"
test -d "$TARGET"
test -f "$TARGET/.env"

rsync -rci "$@" \
  --exclude='.git/' \
  --exclude='.env*' \
  --exclude='error_log' \
  --exclude='*.log' \
  --exclude='file_download/' \
  --exclude='uploads/' \
  --include='/admin/***' \
  --include='/api/***' \
  --include='/app/***' \
  --include='/assets/***' \
  --include='/bin/***' \
  --include='/database/***' \
  --include='/portal/***' \
  --include='/*.php' \
  --include='/composer.json' \
  --include='/composer.lock' \
  --exclude='*' \
  "$SOURCE/" "$TARGET/"
