set -e
source /private/tmp/v4-fase4-env
/opt/homebrew/bin/mariadb-dump --socket=/private/tmp/webalhasan-v4-fase4.sock -u root webalhasan_v4_fase2_test > /private/tmp/v4-fase4-before-final.sql
/opt/homebrew/bin/mariadb --socket=/private/tmp/webalhasan-v4-fase4.sock -u root -e 'DROP DATABASE webalhasan_v4_fase2_test; CREATE DATABASE webalhasan_v4_fase2_test'
/opt/homebrew/bin/mariadb --socket=/private/tmp/webalhasan-v4-fase4.sock -u root webalhasan_v4_fase2_test < k1807225_webalhasan.sql
mkdir -p /private/tmp/v4-fase4-baseline-migrations
python3 - <<'PY'
from pathlib import Path
import shutil
for p in Path('database/migrations').glob('*.sql'):
 if p.name<'022':shutil.copy(p,'/private/tmp/v4-fase4-baseline-migrations/'+p.name)
PY
/opt/homebrew/bin/php -r 'require "app/bootstrap.php";$m=new App\Database\Migrator(app_db(),"/private/tmp/v4-fase4-baseline-migrations",APP_ROOT."/database/rollbacks");foreach($m->up() as $n)echo $n,PHP_EOL;' > docs/phase-v4-4/migrasi-baseline.txt
/opt/homebrew/bin/php bin/v4_phase1_preflight.php /private/tmp/v4-fase4-baseline.json > docs/phase-v4-4/preflight.txt
/opt/homebrew/bin/php bin/migrate.php up > docs/phase-v4-4/migrasi.txt
/opt/homebrew/bin/php bin/migrate.php up >> docs/phase-v4-4/migrasi.txt
/opt/homebrew/bin/php bin/v4_phase1_verify.php /private/tmp/v4-fase4-baseline.json > docs/phase-v4-4/verifier-fase1.txt
/opt/homebrew/bin/php tests/v4_phase1_seed.php /private/tmp/v4-fase4-tokens.json
/opt/homebrew/bin/php tests/v4_phase1_integration.php > docs/phase-v4-4/regresi-fase1.txt
bash /private/tmp/run-v4-fase4-regression.sh
/opt/homebrew/bin/php tests/v4_phase4_seed.php > docs/phase-v4-4/fixture.txt
/opt/homebrew/bin/php tests/v4_phase4_integration.php > docs/phase-v4-4/integrasi.txt
/Users/ilanmochamad/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/bin/python3 tests/v4_phase4_pdf.py > docs/phase-v4-4/pdf-hash.txt
/opt/homebrew/bin/php tests/v4_phase4_push.php > docs/phase-v4-4/push.txt
node tests/browser/uji-v4-fase4.mjs > docs/phase-v4-4/http-browser.txt
/opt/homebrew/bin/php tests/v4_phase4_concurrency.php > docs/phase-v4-4/konkurensi.txt
/opt/homebrew/bin/php tests/v4_phase4_lifecycle.php > docs/phase-v4-4/siklus.txt
/opt/homebrew/bin/php tests/v4_phase4_document.php > docs/phase-v4-4/pdf-banyakhal.txt
/opt/homebrew/bin/php bin/v4_phase4_verify.php > docs/phase-v4-4/verifier-fase4.txt
/opt/homebrew/bin/php bin/v4_phase3_verify.php > docs/phase-v4-4/verifier-fase3-final.txt
/opt/homebrew/bin/php bin/v4_phase4_reconcile.php > docs/phase-v4-4/rekonsiliasi.txt
