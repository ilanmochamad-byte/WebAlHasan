#!/usr/bin/env bash
set -e
source /private/tmp/v4p5-env
node tests/browser/uji-v4-fase1.mjs > docs/phase-v4-5/regresi-fase1-http.txt
/opt/homebrew/bin/php tests/v4_phase2_integration.php > docs/phase-v4-5/regresi-fase2.txt
/opt/homebrew/bin/php tests/v4_phase2_web_seed.php
/opt/homebrew/bin/php tests/v4_phase2_concurrency.php > docs/phase-v4-5/regresi-fase2-konkurensi.txt
node tests/browser/uji-v4-fase2.mjs > docs/phase-v4-5/regresi-fase2-http.txt
node tests/browser/uji-v4-fase2-guru.mjs > docs/phase-v4-5/regresi-fase2-guru.txt
/opt/homebrew/bin/php tests/v4_phase3_seed.php
/opt/homebrew/bin/php tests/v4_phase3_integration.php > docs/phase-v4-5/regresi-fase3.txt
/opt/homebrew/bin/php tests/v4_phase3_concurrency.php > docs/phase-v4-5/regresi-fase3-konkurensi.txt
node tests/browser/uji-v4-fase3.mjs > docs/phase-v4-5/regresi-fase3-http.txt
/opt/homebrew/bin/php tests/v4_phase3_transaction.php > docs/phase-v4-5/regresi-fase3-transaksi.txt
/opt/homebrew/bin/php bin/v4_phase3_verify.php > docs/phase-v4-5/verifier-fase3.txt

php tests/v4_phase4_seed.php > docs/phase-v4-5/fixture-fase4.txt
php tests/v4_phase4_integration.php > docs/phase-v4-5/regresi-fase4-integrasi.txt
php tests/v4_phase4_push.php > docs/phase-v4-5/regresi-fase4-push.txt
${V4_PYTHON:-/Users/ilanmochamad/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3} tests/v4_phase4_pdf.py > docs/phase-v4-5/regresi-fase4-pdf.txt
node tests/browser/uji-v4-fase4.mjs > docs/phase-v4-5/regresi-fase4-http.txt
php tests/v4_phase4_concurrency.php > docs/phase-v4-5/regresi-fase4-konkurensi.txt
php tests/v4_phase4_lifecycle.php > docs/phase-v4-5/regresi-fase4-siklus.txt
php tests/v4_phase4_document.php > docs/phase-v4-5/regresi-fase4-dokumen.txt
php tests/v4_phase4_audit_claude.php > docs/phase-v4-5/regresi-probe-audit-fase4.txt
php bin/v4_phase1_verify.php > docs/phase-v4-5/verifier-fase1.txt
php bin/v4_phase3_verify.php > docs/phase-v4-5/verifier-fase3.txt
php bin/v4_phase4_verify.php > docs/phase-v4-5/verifier-fase4.txt
