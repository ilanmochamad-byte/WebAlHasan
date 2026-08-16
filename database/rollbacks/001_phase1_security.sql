DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS api_tokens;
ALTER TABLE users DROP INDEX users_guru_unique, DROP COLUMN force_password_change;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS roles;

