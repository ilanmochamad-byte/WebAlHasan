source /private/tmp/v4p5-env
export DB_NAME=webalhasan_v3_phase1_test V3_RUN_TESTS=1 PHASE2_RUN_INTEGRATION=1 PHASE3_RUN_INTEGRATION=1 PHASE4_RUN_INTEGRATION=1 PHASE5_RUN_INTEGRATION=1 PENUGASAN_RUN_INTEGRATION=1 V2_PHASE1_RUN_INTEGRATION=1 V2_PHASE2_RUN_INTEGRATION=1 V2_PHASE4_RUN_INTEGRATION=1 V2_PHASE5_RUN_INTEGRATION=1
/opt/homebrew/bin/mariadb --socket=/private/tmp/v4p5.sock -u root -e 'CREATE DATABASE webalhasan_v3_phase1_test'
/opt/homebrew/bin/mariadb --socket=/private/tmp/v4p5.sock -u root webalhasan_v3_phase1_test < /private/tmp/v4-regression-schema.sql
/opt/homebrew/bin/php bin/migrate.php up > docs/phase-v4-5/regresi-migrasi.txt 2>&1
V2_PHASE3_SEED=1 /opt/homebrew/bin/php bin/v2_phase3_sandbox_seed.php > /private/tmp/v4-fase4-regression-seed-private.txt 2>&1
/opt/homebrew/bin/mariadb --socket=/private/tmp/v4p5.sock -u root webalhasan_v3_phase1_test -e "INSERT INTO tahun_ajaran (tahun,semester,status) VALUES ('2026/2027','Genap','Non-Aktif')"
: > docs/phase-v4-5/regresi-ringkasan.txt
for suite in phase1_static phase2_static phase3_static phase4_static phase5_static penugasan_static v2_phase1_static v2_phase2_static v2_phase3_static v2_phase4_static v2_phase5_static v3_phase1_static v3_phase2_static v3_phase3_static v3_phase4_static phase2_integration phase3_integration phase4_integration phase5_integration penugasan_integration v2_phase1_integration v2_phase2_integration v2_phase4_integration v2_phase5_integration v3_phase1_integration v3_phase2_integration v3_phase3_integration v3_phase4_integration v3_phase5_integration; do
 /opt/homebrew/bin/php "tests/$suite.php" > "docs/phase-v4-5/regresi-$suite.txt" 2>&1
 code=$?
 echo "$suite exit=$code" >> docs/phase-v4-5/regresi-ringkasan.txt
done
