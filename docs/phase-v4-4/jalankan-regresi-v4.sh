set -e
source /private/tmp/v4-fase4-env
node tests/browser/uji-v4-fase1.mjs > docs/phase-v4-4/regresi-fase1-http.txt
/opt/homebrew/bin/php tests/v4_phase2_integration.php > docs/phase-v4-4/regresi-fase2.txt
/opt/homebrew/bin/php tests/v4_phase2_web_seed.php
/opt/homebrew/bin/php tests/v4_phase2_concurrency.php > docs/phase-v4-4/regresi-fase2-konkurensi.txt
node tests/browser/uji-v4-fase2.mjs > docs/phase-v4-4/regresi-fase2-http.txt
node tests/browser/uji-v4-fase2-guru.mjs > docs/phase-v4-4/regresi-fase2-guru.txt
/opt/homebrew/bin/php tests/v4_phase3_seed.php
/opt/homebrew/bin/php tests/v4_phase3_integration.php > docs/phase-v4-4/regresi-fase3.txt
/opt/homebrew/bin/php tests/v4_phase3_concurrency.php > docs/phase-v4-4/regresi-fase3-konkurensi.txt
node tests/browser/uji-v4-fase3.mjs > docs/phase-v4-4/regresi-fase3-http.txt
/opt/homebrew/bin/php tests/v4_phase3_transaction.php > docs/phase-v4-4/regresi-fase3-transaksi.txt
/opt/homebrew/bin/php bin/v4_phase3_verify.php > docs/phase-v4-4/verifier-fase3.txt
