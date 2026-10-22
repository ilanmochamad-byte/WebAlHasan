/**
 * Regresi independen Codex untuk kondisi tepi komponen Tahap 1.
 * Semua mutasi aplikasi asli dicegat; POST galeri hanya memantulkan fixture.
 * PERAPIHAN_AUDIT_DB=1 BASE_URL=http://127.0.0.1:8931 node tests/browser/uji-redesain-web-tahap1-audit.mjs
 */
import { launchAudit, login, base } from './audit-runtime.mjs';
import { mkdirSync } from 'node:fs';
if (process.env.OUT_DIR) mkdirSync(process.env.OUT_DIR, { recursive: true });
const { browser, context, page } = await launchAudit();
let pass = 0, fail = 0;
function check(ok, label) {
  ok ? pass++ : fail++;
  console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}`);
}
const gallery = '/__uji/komponen.php';
try {
  await login(page);
  await page.goto(base + gallery);
  let posts = 0;
  page.on('request', r => { if (r.method() === 'POST') posts++; });
  await page.route('**/admin/**', route => route.request().method() === 'POST'
    ? route.fulfill({ status: 422, body: 'Mutasi dicegat audit.' }) : route.continue());
  // Batal wajib tetap dapat dipilih sebelum pengguna mengisi alasan.
  for (const width of [375, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    const menuTrigger = page.locator('tbody tr').first().locator('summary');
    await menuTrigger.click();
    const opener = page.locator('tbody tr').first().locator('button.ah-menu__item--danger');
    await opener.click();
    await page.locator('[data-ah-cancel]').click();
    // Event close dijalankan setelah tugas klik selesai di peramban.
    const restored = await page.waitForFunction(n => {
        const menu = n.closest('details');
        return !document.querySelector('dialog').open && !n.disabled
          && document.activeElement === (menu.open ? n : menu.querySelector('summary'));
      }, await opener.elementHandle(), { timeout: 1500 }).then(() => true).catch(() => false);
    check(restored && posts === 0,
    `Batal dengan alasan kosong menutup dialog tanpa POST dan mengembalikan fokus ${width}px`);
    if (await page.locator('dialog').evaluate(n => n.open)) await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
  }

  // Mutasi halaman lama juga harus menggunakan dialog baru tanpa mengubah payload.
  for (const path of ['admin_guru.php', 'admin_pengurus.php', 'admin_kelas.php', 'admin_tahun.php', 'admin_master_santri.php']) {
    await page.goto(base + '/admin/' + path);
    const form = page.locator('form[data-confirm]').filter({ has: page.locator('button') }).last();
    if (!await form.count()) {
      console.log(`[catatan] Tidak ada fixture tindakan data-confirm pada ${path}`);
      continue;
    }
    await form.locator('button').first().click();
    check(await page.locator('dialog[open]').count() === 1 && posts === 0, `Konfirmasi halaman lama membuka dialog tanpa POST: ${path}`);
    await page.locator('[data-ah-cancel]').click();
    check(await page.locator('dialog[open]').count() === 0 && posts === 0 && !await form.locator('button').first().isDisabled(), `Batal halaman lama tetap dapat dipakai: ${path}`);
  }

  await page.goto(base + gallery);
  for (const width of [375, 768, 1280]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(base + gallery + '?action=create');
    const footer = page.locator('.ah-panel__foot');
    const rect = await footer.boundingBox();
    check(Math.abs(rect.y + rect.height - 900) <= 2, `Formulir pendek: tombol simpan menempel di dasar panel ${width}px`);
    if (process.env.OUT_DIR) await page.screenshot({ path: `${process.env.OUT_DIR}/audit-panel-${width}.jpg`, type: 'jpeg', quality: 70 });
    await page.locator('#uji-form-panel').evaluate(form => {
      const extra = document.createElement('div');
      extra.style.height = '1200px';
      extra.style.flexShrink = '0';
      form.insertBefore(extra, form.querySelector('.ah-panel__foot'));
    });
    const tall = await footer.boundingBox();
    check(Math.abs(tall.y + tall.height - 900) <= 2 && await page.locator('.ah-panel__body').evaluate(n => n.scrollHeight > n.clientHeight), `Formulir panjang: tombol tetap terlihat dan isian dapat digulir ${width}px`);
  }
  await page.goto(base + gallery);
  await page.route('**/__audit/json', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<html>Silakan masuk kembali</html>' }));
  const badJson = await page.evaluate(async () => {
    try { await AlHasan.post('/__audit/json', {}); return false; } catch { return true; }
  });
  check(badJson, 'POST menolak HTML 200 (misalnya sesi habis), bukan dianggap berhasil');
  const bulk = await page.evaluate(async () => {
    const run = AlHasan.bulk({ container: '#uji-massal-hasil', items: [{ id: 1, label: 'Contoh' }], run: () => AlHasan.post('/__audit/json', {}) });
    const r = await run.done;
    return { ok: r.ok.length, failed: r.failed.length, reason: r.failed[0]?.reason };
  });
  check(bulk.ok === 0 && bulk.failed === 1 && !!bulk.reason, 'Proses massal mencatat respons bukan JSON sebagai gagal beserta sebab');
  await page.unroute('**/__audit/json');

  await page.route('**/__audit/json', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'Data belum lengkap.' }) }));
  check(await page.evaluate(async () => {
    try { await AlHasan.post('/__audit/json', {}); return false; } catch (e) { return e.message === 'Data belum lengkap.'; }
  }), 'POST mempertahankan sebab penolakan JSON dari server');
  await page.unroute('**/__audit/json');
  await page.route('**/__audit/json', async route => {
    const body = new URLSearchParams(route.request().postData());
    check(body.get('_csrf') === await page.evaluate(() => ALHASAN_CSRF)
      && !!route.request().headers()['x-csrf-token'], 'POST mengirim token CSRF pada body dan header');
    return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"id":1}' });
  });
  check(await page.evaluate(async () => (await AlHasan.post('/__audit/json', {})).id === 1), 'POST JSON berhasil tetap mengembalikan payload');

  // Penghentian tidak memulai data berikutnya dan pengulangan hanya sisanya.
  const stopped = await page.evaluate(async () => {
    const visited = [];
    const run = AlHasan.bulk({ container: '#uji-massal-hasil', items: [1, 2, 3].map(id => ({ id })), run: async item => {
      visited.push(item.id);
      document.querySelector('.ah-bulk__actions button').click();
    } });
    const result = await run.done;
    return { visited, ok: result.ok.length, stopped: result.stopped.map(x => x.id), retry: document.querySelector('.ah-bulk__actions').textContent };
  });
  check(stopped.ok === 1 && stopped.visited.join() === '1' && stopped.stopped.join() === '2,3' && stopped.retry.includes('2'), 'Hentikan menyelesaikan data berjalan dan menawarkan ulangi dua sisanya');

  // Tanpa JS: gunakan cookie sintetis yang sama, lalu benar-benar kirim formulir.
  const noJs = await browser.newContext({ javaScriptEnabled: false, storageState: await context.storageState() });
  const formPage = await noJs.newPage();
  await formPage.route('https://**/*', route => route.abort());
  await formPage.goto(base + gallery + '?action=create');
  check(await formPage.locator('#uji-form-panel input[name="_csrf"]').count() === 1, 'Panel tanpa JavaScript memiliki token CSRF dari server');
  await formPage.locator('#nama').fill('Guru Sintetis Audit');
  await Promise.all([formPage.waitForNavigation(), formPage.getByRole('button', { name: 'Simpan guru', exact: true }).click()]);
  check(formPage.url() === base + gallery && (await formPage.locator('body').innerText()).includes('Diterima: simpan Guru Sintetis Audit'), 'Panel tanpa JavaScript benar-benar menyimpan fixture dan kembali ke daftar');
  await noJs.close();
} finally {
  await browser.close();
}
console.log(`${pass} lulus, ${fail} gagal.`);
process.exitCode = fail ? 1 : 0;
