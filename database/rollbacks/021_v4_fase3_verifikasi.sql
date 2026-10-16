-- Hentikan penulis dan backup dahulu. Guard CHECK menolak penghapusan data bisnis.
CREATE TEMPORARY TABLE v4_fase3_rollback_guard (jumlah BIGINT NOT NULL CHECK (jumlah=0));
INSERT INTO v4_fase3_rollback_guard SELECT (SELECT COUNT(*) FROM v4_bahan)+(SELECT COUNT(*) FROM v4_bahan_riwayat);
DROP TABLE v4_bahan_riwayat;
DROP TABLE v4_bahan;
DROP TEMPORARY TABLE v4_fase3_rollback_guard;
