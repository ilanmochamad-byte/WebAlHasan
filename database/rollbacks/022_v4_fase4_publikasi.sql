CREATE TABLE v4_fase4_rollback_guard (jumlah BIGINT NOT NULL CHECK (jumlah=0));
INSERT INTO v4_fase4_rollback_guard SELECT (SELECT COUNT(*) FROM v4_rapor)+(SELECT COUNT(*) FROM v4_rapor_operasi)+(SELECT COUNT(*) FROM v4_rencana_penanda_riwayat);
DROP TABLE v4_verifikasi_laju;
DROP TABLE v4_rencana_penanda_riwayat;
DROP TABLE v4_rapor_operasi;
DROP TABLE v4_fase4_rollback_guard;
