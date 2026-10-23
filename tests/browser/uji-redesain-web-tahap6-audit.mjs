// Regresi auditor D-3: dua konflik melalui UI sungguhan, dengan/tanpa JS.
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
const base = process.env.BASE_URL || 'http://127.0.0.1:8981';
const db = process.env.DB_NAME || 'test_redesain_t6_test';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || !/^test_.*_test$/.test(db)) throw Error('Local test DB required');
const out = process.env.OUT_DIR || '/tmp/t6-audit-browser';
mkdirSync(out, { recursive: true });
let pass = 0, fail = 0;
const check = (ok, label) => { ok ? pass++ : fail++; console.log(`${ok ? '[lulus]' : '[gagal]'} ${label}`); };
const browser = await chromium.launch();
try {
  for (const js of [true, false]) for (const width of [375, 768, 1280]) {
    const id = execFileSync('php', ['-r', `putenv('DB_NAME=${db}'); require 'app/bootstrap.php'; echo master_data_service()->saveSantri(['nis'=>'AU6-'.bin2hex(random_bytes(5)), 'nama_santri'=>'Audit Browser Dua Wali', 'jenis_kelamin'=>'P', 'tgl_lahir'=>'2013-02-02', 'nama_ayah'=>'Ayah lama', 'nama_ibu'=>'Ibu lama']);`], { encoding: 'utf8' }).trim();
    const context = await browser.newContext({ viewport: { width, height: 900 }, javaScriptEnabled: js });
    await context.route('**/*', route => {
      const url = new URL(route.request().url());
      if (url.origin === base) return route.continue();
      for (const [re, file, contentType] of [[/bootstrap.*\.min\.css$/, 'bootstrap/dist/css/bootstrap.min.css', 'text/css'], [/bootstrap.*bundle.*\.js$/, 'bootstrap/dist/js/bootstrap.bundle.min.js', 'application/javascript'], [/font-awesome.*all\.min\.css$/, '@fortawesome/fontawesome-free/css/all.min.css', 'text/css']]) {
        if (re.test(url.href)) return route.fulfill({ contentType, body: readFileSync(new URL('./node_modules/' + file, import.meta.url)) });
      }
      return route.abort();
    });
    const page = await context.newPage();
    const errors = []; page.on('pageerror', e => errors.push(e.message));
    await page.goto(base + '/portal/index.php');
    await page.fill('#username', 'sbx_admin'); await page.fill('#password', 'Sandbox#123');
    await Promise.all([page.waitForURL('**/portal/index.php'), page.getByRole('button', { name: 'Masuk', exact: true }).click()]);
    await page.goto(base + '/admin/admin_master_santri.php?action=edit&id=' + id);
    if (js) {
      for (const parent of ['Ayah', 'Ibu']) {
        await page.locator(`input[name="wali[${parent}][mode]"][value="baru"]`).check();
        await page.locator(`[name="wali[${parent}][nama]"]`).fill(parent + ' baru browser');
      }
      await Promise.all([page.waitForNavigation(), page.getByRole('button', { name: 'Simpan santri', exact: true }).click()]);
    } else {
      // Pemilih mode wali lama bergantung JS (panel baru semula hidden).
      // Siapkan POST konflik melalui HTTP; yang diuji tanpa JS di sini ialah
      // dialog koreksi dan tombol submit lintas-form, bukan pemilih lama itu.
      const form = { action: 'save', id, nis: await page.locator('#nis').inputValue(), nama_santri: 'Audit Browser Dua Wali', jenis_kelamin: 'P', tgl_lahir: '2013-02-02',
        _csrf: await page.locator('#form-santri [name="_csrf"]').inputValue(), form_token: await page.locator('#form-santri [name="form_token"]').inputValue() };
      for (const parent of ['Ayah', 'Ibu']) { form[`wali[${parent}][mode]`] = 'baru'; form[`wali[${parent}][nama]`] = parent + ' baru browser'; }
      const response = await context.request.post(base + '/admin/admin_master_santri.php', { form, maxRedirects: 0 });
      await page.goto(base + response.headers().location);
    }
    const confirm = page.getByRole('button', { name: 'Ganti dan simpan', exact: true });
    check(await confirm.isVisible(), `D-3 ${width}px JS=${js}: dialog gabungan terlihat`);
    const body = await page.locator('body').innerText();
    check(['Ayah lama', 'Ibu lama', 'Ayah baru browser', 'Ibu baru browser'].every(s => body.includes(s)), `D-3 ${width}px JS=${js}: nilai kedua orang tua jelas`);
    check(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `D-3 ${width}px JS=${js}: tanpa overflow`);
    await page.screenshot({ path: `${out}/D3-dua-konflik-${width}-js-${js}.png`, fullPage: !js });
    await Promise.all([page.waitForNavigation(), confirm.click()]);
    check(page.url().includes('action=detail'), `D-3 ${width}px JS=${js}: satu konfirmasi menyimpan`);
    await page.goto(base + '/admin/admin_master_santri.php?action=detail&id=' + id + '&tab=wali');
    const result = await page.locator('main').innerText();
    check(result.includes('Ayah baru browser') && result.includes('Ibu baru browser'), `D-3 ${width}px JS=${js}: kedua relasi tersimpan`);
    check(errors.length === 0, `D-3 ${width}px JS=${js}: tanpa galat JS`);
    await context.close();
  }
} finally { await browser.close(); }
console.log(`Ringkasan: ${pass} lulus, ${fail} gagal`);
process.exitCode = fail ? 1 : 0;
