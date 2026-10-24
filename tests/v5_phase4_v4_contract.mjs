import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';

// Smoke of the actual V4 endpoints still consumed by the ee6621a mobile baseline.
const base = process.env.V5_WEB, db = process.env.DB_NAME;
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !/^test_.*_test$/.test(db || '')) throw Error('Synthetic localhost only');
let passed = 0, failed = 0;
const ok = (v, s) => { console.log(`[${v ? 'lulus' : 'gagal'}] ${s}`); v ? passed++ : failed++; };
const sql = q => execFileSync('php', [fileURLToPath(new URL('./v5_phase4_sql.php', import.meta.url)), q]).toString().trim();
async function login(username) {
  const response = await fetch(base + '/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username, password: 'Sandbox#123', device_name: 'codex-v4-compatibility' }) });
  return (await response.json()).data.token;
}
const token = await login('sbx_t7_ortu');
const get = (p, t = token) => fetch(base + '/api/v1' + p, { headers: { Authorization: 'Bearer ' + t } });
let response = await get('/v4/capabilities');
const caps = await response.json();
ok(response.status === 200 && caps.success && Array.isArray(caps.data.list), 'Legacy capabilities envelope and list remain compatible');
ok(response.headers.get('cache-control')?.includes('no-store'), 'Legacy capabilities private response is no-store');
response = await get('/v4/rapor');
const list = (await response.json()).data;
ok(response.status === 200 && Array.isArray(list) && list.length > 0, 'Legacy parent report listing remains an array with data');
const report = list.find(r => r.status === 'Terbit');
if (!report) throw Error('Fixture needs a published report');
response = await get('/v4/rapor/' + report.id);
const detail = (await response.json()).data;
ok(response.status === 200 && Array.isArray(detail.versi) && Array.isArray(detail.riwayat), 'Legacy report detail shape remains compatible');
const published = detail.versi.find(v => v.snapshot);
ok(Boolean(published), 'Legacy parent receives published snapshot');
const snapshot = published.snapshot;
ok('rata_rata_tampil' in snapshot && !('rata_rata' in snapshot), 'V4 audit correction preserved: display average without internal decimal');
ok(!JSON.stringify(snapshot).includes('data:image') && !snapshot.penanda.some(p => 'gambar' in p || 'aset_id' in p), 'V4 parent projection still excludes signature image/assets');
response = await get(`/v4/rapor/${report.id}/pdf/${published.version}`);
const bytes = Buffer.from(await response.arrayBuffer());
ok(response.status === 200 && response.headers.get('content-type')?.includes('application/pdf') && bytes.subarray(0, 5).toString() === '%PDF-', 'Legacy authenticated PDF endpoint returns PDF bytes');
ok(response.headers.get('cache-control')?.includes('no-store'), 'Legacy private PDF remains no-store');
const hash = sql(`SELECT pdf_sha256 FROM v4_rapor_versi WHERE rapor_id=${Number(report.id)} AND version=${Number(published.version)}`);
ok(createHash('sha256').update(bytes).digest('hex') === hash, 'Legacy PDF bytes match the canonical stored SHA-256');
const other = await login('sbx_v5_wali_lain');
ok((await get('/v4/rapor/' + report.id, other)).status === 403, 'V4 detail still denies an unrelated parent');
ok((await get(`/v4/rapor/${report.id}/pdf/${published.version}`, other)).status === 403, 'V4 PDF still denies an unrelated parent');
console.log(`Hasil: ${passed} lulus, ${failed} gagal`);
process.exitCode = failed ? 1 : 0;
