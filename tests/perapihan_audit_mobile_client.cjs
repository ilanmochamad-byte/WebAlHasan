// Runs the actual mobile API client with only native transport/storage adapted
// to Node. No mobile source changes, push provider calls, or production access.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = process.env.MOBILE_APP_ROOT;
if (process.env.PERAPIHAN_AUDIT_DB !== '1' || !root) process.exit(77);
const base = process.env.EXPO_PUBLIC_API_BASE_URL;
if (!/^http:\/\/127\.0\.0\.1:\d+\/api\/v1$/.test(base || '')) process.exit(2);
const fixture = JSON.parse(fs.readFileSync(process.env.AUDIT_NOTIFICATION_MANIFEST, 'utf8'));
const ts = require(path.join(root, 'node_modules/typescript'));
let token = null, fail = 0, checks = 0, unauthorized = 0;
const check = (ok, label) => { checks++; fail += !ok; console.log(`${ok ? '[lulus]' : '[gagal]'} B-1 client mobile: ${label}`); };
const storage = { get: async () => token, clear: async () => { token = null; } };
const exportsClient = {};
const sandbox = {
  exports: exportsClient, process: { env: { EXPO_PUBLIC_API_BASE_URL: base } },
  URLSearchParams, AbortController, setTimeout, clearTimeout,
  require(name) {
    if (name === 'expo/fetch') return { fetch };
    if (name === 'expo-constants') return { default: { expoConfig: {} } };
    if (name === '@/auth/token-storage') return { tokenStorage: storage };
    throw new Error(`Unexpected runtime dependency: ${name}`);
  },
};
const source = fs.readFileSync(path.join(root, 'src/api/client.ts'), 'utf8');
vm.runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, sandbox);
const { api, ApiError, setUnauthorizedHandler } = exportsClient;
(async () => {
  const sessions = [];
  try {
    for (const user of ['sbx_murobi_a', 'sbx_murobi_b']) {
      const login = await api.login(user, 'Sandbox#123', 'audit-client-mobile');
      sessions.push(login.token);
      check(Boolean(login.token) && login.profile.username === user, `login ${user}`);
    }
    token = sessions[0];
    const all = await api.notifikasiList({ page: 1, per_page: 20 });
    check(all.items.length === 20 && all.pagination.total_pages >= 2, 'daftar dan pagination 20 baris');
    const second = await api.notifikasiList({ page: 2, per_page: 20 });
    check(second.items.length > 0 && !second.items.some(x => all.items.some(y => x.id === y.id)), 'halaman kedua berbeda dari pertama');
    const before = await api.notifikasiBelumDibaca();
    check(before.jumlah >= fixture.mine.length, 'jumlah belum dibaca untuk lencana tersedia');
    const detail = await api.notifikasiDetail(fixture.mine[0]);
    check(detail.notifikasi.id === fixture.mine[0] && !detail.notifikasi.dibaca, 'detail milik sendiri');
    const read = await api.notifikasiTandaiDibaca(fixture.mine[0]);
    check(read.notifikasi.dibaca && read.jumlah_belum_dibaca === before.jumlah - 1, 'tandai satu dan jumlah turun tepat satu');
    const again = await api.notifikasiTandaiDibaca(fixture.mine[0]);
    check(again.jumlah_belum_dibaca === read.jumlah_belum_dibaca, 'tandai satu idempoten');
    const readOnly = await api.notifikasiList({ status: 'sudah_dibaca' });
    check(readOnly.items.every(x => x.dibaca), 'filter sudah dibaca');
    await api.notifikasiTandaiSemua();
    const empty = await api.notifikasiList({ status: 'belum_dibaca' });
    check(empty.items.length === 0 && empty.jumlah_belum_dibaca === 0, 'tandai semua dan keadaan kosong');
    for (const method of ['notifikasiDetail', 'notifikasiTandaiDibaca']) {
      let denied = false;
      try { await api[method](fixture.foreign); } catch (e) { denied = e instanceof ApiError && e.status === 403; }
      check(denied, `${method} milik akun lain 403`);
    }
    token = sessions[1];
    const own = await api.notifikasiDetail(fixture.foreign);
    check(own.notifikasi.id === fixture.foreign && !own.notifikasi.dibaca, 'ganti akun: notifikasi akun lain tidak ikut ditandai');
    const fallback = await api.notifikasiList({ status: 'tidak-valid' });
    check(fallback.filters.status === 'semua', 'filter tak dikenal mengikuti fallback semua pada kontrak server');
    token = 'invalid-sandbox-token';
    setUnauthorizedHandler(() => { unauthorized++; });
    let unauth = false;
    try { await api.notifikasiBelumDibaca(); } catch (e) { unauth = e instanceof ApiError && e.status === 401; }
    check(unauth && token === null && unauthorized === 1, '401 membersihkan token dan memanggil penangan sesi');
  } finally {
    for (const session of sessions) {
      token = session;
      await api.logout();
    }
    token = null;
  }
  console.log(`Total client mobile: ${checks}, gagal: ${fail}`);
  process.exitCode = fail ? 1 : 0;
})().catch(e => { console.error(e.message); process.exitCode = 1; });
