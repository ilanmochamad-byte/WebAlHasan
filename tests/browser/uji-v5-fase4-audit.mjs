import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Independent regression: a retained draft must never adopt a newer optimistic
// version when AppState returns to active. Only synthetic localhost fixtures.
const web = process.env.V5_WEB, app = process.env.V5_APP, db = process.env.DB_NAME;
if (![web, app].every(x => /^http:\/\/127\.0\.0\.1:\d+$/.test(x || '')) || !/^test_.*_test$/.test(db || '')) throw Error('Local synthetic fixtures only');
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sql = q => execFileSync('php', [root + '/tests/v5_phase4_sql.php', q], { env: { ...process.env, DB_NAME: db } }).toString().trim();
const pass = 'Sandbox#123', marker = `CODEXF4-${Date.now()}`;
const tokens = {};
let passed = 0, failed = 0;
const ok = (v, label) => { console.log(`[${v ? 'lulus' : 'gagal'}] ${label}`); v ? passed++ : failed++; };
async function api(user, method, route, body) {
  if (!tokens[user]) {
    const r = await fetch(web + '/api/v1/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ username: user, password: pass, device_name: 'codex-independent' }) });
    tokens[user] = (await r.json()).data.token;
  }
  const response = await fetch(web + '/api/v1' + route, { method, headers: { Authorization: `Bearer ${tokens[user]}`, 'Content-Type': 'application/json' }, body: body && JSON.stringify(body) });
  return { status: response.status, data: (await response.json()).data, headers: response.headers };
}
const browser = await chromium.launch({ headless: true });
const contexts = [];
async function login(user) {
  const context = await browser.newContext({ viewport: { width: 375, height: 812 } }); contexts.push(context);
  const p = await context.newPage();
  await p.route('**/*', async r => {
    const url = new URL(r.request().url());
    if (url.hostname !== '127.0.0.1') return r.abort();
    if (url.origin !== web) return r.fallback();
    if (r.request().method() === 'OPTIONS') return r.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': app, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
    try {
      const response = await r.fetch();
      return await r.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': app } });
    } catch { if (!p.isClosed()) await r.abort().catch(() => {}); }
  });
  await p.goto(app + '/login');
  await p.getByPlaceholder('Masukkan username').fill(user);
  await p.getByPlaceholder('Masukkan password').fill(pass);
  await p.getByRole('button', { name: 'Masuk', exact: true }).click();
  await p.waitForURL(u => u.pathname !== '/login');
  return p;
}
async function visibility(p, state) {
  await p.evaluate(state => { Object.defineProperty(document, 'visibilityState', { configurable: true, value: state }); document.dispatchEvent(new Event('visibilitychange')); }, state);
  await p.waitForTimeout(350);
}
const state = k => ({ waktu_kunjungan: k.waktu_kunjungan, keluhan: k.keluhan, catatan_klinis: k.catatan_klinis, tindakan: k.tindakan, obat: k.obat, rujukan: k.rujukan, tindak_lanjut: k.tindak_lanjut });
try {
  const sid = Number(sql("SELECT id FROM santri WHERE nis='V5S0101'"));
  const body = { santri_id: sid, waktu_kunjungan: new Date().toISOString().slice(0, 10) + 'T08:00', keluhan: marker + '-INITIAL', catatan_klinis: marker + '-PRIVATE', tindakan: marker + '-INITIAL-ACTION', obat: [], rujukan: [{ tujuan: marker + '-REFERRAL' }], tindak_lanjut: [], status: 'Tercatat', idempotency_key: marker + '-create' };
  const created = await api('sbx_v5_petugas_d', 'POST', '/v5/kesehatan/kunjungan', body);
  ok(created.status === 201, 'Independent fixture: new visit created');
  const id = created.data.id, route = `/v5/kesehatan/kunjungan/${id}`;
  const p = await login('sbx_v5_petugas_d');
  await p.goto(app + `/kesehatan/catat?id=${id}`);
  await p.getByRole('textbox', { name: 'Keluhan (wajib)', exact: true }).fill(marker + '-DRAFT');
  await p.getByRole('textbox', { name: 'Alasan koreksi (wajib)', exact: true }).fill('Independent stale draft test');
  await visibility(p, 'hidden');
  const k = (await api('sbx_v5_petugas_e', 'GET', route)).data;
  const other = await api('sbx_v5_petugas_e', 'POST', route + '/koreksi', { ...state(k), tindakan: marker + '-OTHER-ACTION', versi: k.versi, alasan: 'Concurrent edit during background', idempotency_key: marker + '-other' });
  ok(other.status === 200 && other.data.versi === 2, 'Other staff commits v2 while app is in background');
  await visibility(p, 'visible');
  await p.getByRole('textbox', { name: 'Keluhan (wajib)', exact: true }).waitFor();
  ok((await p.getByRole('textbox', { name: 'Keluhan (wajib)', exact: true }).inputValue()) === marker + '-DRAFT', 'Unsaved draft retained only in memory');
  let submitted;
  p.on('request', r => { if (r.url().endsWith(route + '/koreksi')) submitted = r.postDataJSON(); });
  const reply = p.waitForResponse(r => r.url().endsWith(route + '/koreksi'));
  await p.getByRole('button', { name: 'Simpan koreksi', exact: true }).click();
  const r = await reply;
  ok(submitted.versi === 1, `Retained draft sends its original version (actual ${submitted.versi})`);
  ok(r.status() === 409, `Background concurrent edit returns conflict (actual ${r.status()})`);
  const final = (await api('sbx_v5_petugas_e', 'GET', route)).data;
  ok(final.versi === 2 && final.tindakan === marker + '-OTHER-ACTION', 'No concurrent clinical values overwritten after resume');
  if (r.status() === 409) {
    await p.getByRole('button', { name: 'Muat versi terbaru (isian ini dibuang)', exact: true }).click();
    await p.getByText('Mengoreksi versi 2', { exact: false }).waitFor();
    ok(await p.getByRole('textbox', { name: 'Tindakan', exact: true }).inputValue() === marker + '-OTHER-ACTION', 'Explicit reload resets draft and version together');
  }
  // Outcome form also retains its original version while revalidating access.
  await p.goto(app + `/kesehatan/hasil-rujukan?id=${id}&rujukan=${final.rujukan[0].id}`);
  await p.getByRole('textbox', { name: 'Hasil rujukan (wajib)', exact: true }).fill(marker + '-RESULT');
  await p.getByRole('textbox', { name: 'Alasan (wajib)', exact: true }).fill('Independent referral conflict');
  await visibility(p, 'hidden');
  let latest = (await api('sbx_v5_petugas_e', 'GET', route)).data;
  await api('sbx_v5_petugas_e', 'POST', route + '/koreksi', { ...state(latest), tindakan: marker + '-OTHER-RESULT-ACTION', versi: latest.versi, alasan: 'Concurrent outcome context change', idempotency_key: marker + '-other-result' });
  await visibility(p, 'visible');
  await p.getByRole('button', { name: 'Simpan hasil rujukan', exact: true }).waitFor();
  const outcome = p.waitForResponse(res => res.url().endsWith('/hasil') && res.request().method() === 'POST');
  await p.getByRole('button', { name: 'Simpan hasil rujukan', exact: true }).click();
  ok((await outcome).status() === 409, 'Referral outcome after background preserves optimistic conflict');
  latest = (await api('sbx_v5_petugas_e', 'GET', route)).data;
  ok(latest.rujukan[0].hasil === null && latest.tindakan === marker + '-OTHER-RESULT-ACTION', 'Stale referral form changes neither result nor newer clinical state');
  // Parent summary: another publication during background must not be adopted
  // as the baseline for an already composed replacement/withdrawal.
  const baseSummary = { kunjungan_versi: latest.versi, revisi_ringkasan: 0, ringkasan: marker + '-BASE-SUMMARY', tindakan_umum: '', tindak_lanjut_wali: '', alasan: '' };
  const basePreview = await api('sbx_v5_petugas_e', 'POST', route + '/ringkasan/pratinjau', baseSummary);
  await api('sbx_v5_petugas_e', 'POST', route + '/ringkasan', { ...baseSummary, konfirmasi: true, pratinjau_hash: basePreview.data.pratinjau_hash, idempotency_key: marker + '-base-summary' });
  await p.goto(app + `/kesehatan/ringkasan?id=${id}`);
  await p.getByRole('textbox', { name: 'Ringkasan (wajib, minimal 10 karakter)', exact: true }).fill(marker + '-LOCAL-SUMMARY');
  await visibility(p, 'hidden');
  let internal = (await api('sbx_v5_petugas_e', 'GET', route + '/ringkasan')).data;
  const summary = { kunjungan_versi: internal.kunjungan.versi, revisi_ringkasan: internal.ringkasan?.revisi ?? 0, ringkasan: marker + '-OTHER-SUMMARY', tindakan_umum: '', tindak_lanjut_wali: '', alasan: 'Concurrent summary replacement' };
  const preview = await api('sbx_v5_petugas_e', 'POST', route + '/ringkasan/pratinjau', summary);
  const publish = await api('sbx_v5_petugas_e', 'POST', route + '/ringkasan', { ...summary, konfirmasi: true, pratinjau_hash: preview.data.pratinjau_hash, idempotency_key: marker + '-summary' });
  ok(publish.status === 201 || publish.status === 200, 'Other staff publishes while local summary draft is retained');
  await visibility(p, 'visible');
  await p.getByRole('textbox', { name: 'Alasan penggantian (wajib)', exact: true }).fill('Independent replacement conflict');
  const previewResponse = p.waitForResponse(res => res.url().endsWith('/ringkasan/pratinjau'));
  await p.getByRole('button', { name: 'Lihat pratinjau', exact: true }).click();
  ok((await previewResponse).status() === 409, 'Retained summary does not silently adopt a concurrent publication revision');
  await p.getByRole('textbox', { name: 'Alasan penarikan (wajib)', exact: true }).fill('Independent withdrawal conflict');
  const withdrawn = p.waitForResponse(res => res.url().endsWith('/ringkasan/tarik'));
  await p.getByRole('button', { name: 'Tarik ringkasan', exact: true }).click();
  ok((await withdrawn).status() === 409, 'Retained withdrawal does not silently target a concurrent publication');
  internal = (await api('sbx_v5_petugas_e', 'GET', route + '/ringkasan')).data;
  ok(internal.berlaku?.ringkasan === marker + '-OTHER-SUMMARY' && internal.ringkasan.revisi === 2, 'Concurrent parent summary remains unchanged');
  // Positive reader coverage (the implementation suite only tests negative murobi).
  for (const [user, table, foreign, key] of [
    ['sbx_v5_murobi', 'murobi_assignments', 'guru_id', 'guru_id'],
    ['sbx_v5_pembimbing', 'pembimbing_assignments', 'pengurus_id', 'pengurus_id'],
  ]) {
    const reader = await login(user);
    await reader.goto(app + `/kesehatan/kunjungan/${id}`);
    await reader.getByText(marker + '-PRIVATE', { exact: true }).waitFor();
    ok(await reader.getByRole('button', { name: 'Koreksi catatan', exact: true }).count() === 0, `${user}: internal detail visible, no mutation controls`);
    const master = Number(sql(`SELECT ${key} FROM users WHERE username='${user}'`));
    sql(`UPDATE ${table} SET is_active=0 WHERE ${foreign}=${master}`);
    try {
      await visibility(reader, 'hidden'); await visibility(reader, 'visible');
      await reader.getByText('Informasi tidak dapat diakses', { exact: false }).waitFor();
      ok(!(await reader.locator('body').innerText()).includes(marker), `${user}: revoked assignment removes retained screen data on resume`);
      ok((await api(user, 'GET', route)).status === 403, `${user}: existing API token denied after revocation`);
    } finally { sql(`UPDATE ${table} SET is_active=1 WHERE ${foreign}=${master}`); }
  }
  const out = process.env.OUT_DIR || '/tmp/audit-v5-f4-codex/probe-shots'; fs.mkdirSync(out, { recursive: true });
  await p.screenshot({ path: out + '/background-conflict.png', fullPage: true });
} finally { await browser.close(); }
console.log(`Hasil: ${passed} lulus, ${failed} gagal`);
process.exitCode = failed ? 1 : 0;
