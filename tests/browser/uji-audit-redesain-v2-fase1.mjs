import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const { RTV2_WEB: web, RTV2_APP: app } = process.env;
assert.ok([web, app].every((url) => /^http:\/\/127\.0\.0\.1:\d+$/.test(url ?? '')));
const browser = await chromium.launch();
let failures = 0;
const outside = new Set();
const unexpectedRouteErrors = [];

async function check(label, assertion) {
  try {
    await assertion();
    console.log(`LULUS ${label}`);
  } catch (error) {
    failures++;
    console.error(`GAGAL ${label}: ${error.message}`);
  }
}

async function dimensions(page) {
  const eyes = await page.getByRole('button', { name: /^Tampilkan/ }).all();
  assert.ok(eyes.length > 0, 'tombol mata harus tersedia');
  for (const eye of eyes) {
    const size = await eye.boundingBox();
    assert.ok(size && size.width >= 44 && size.height >= 44, JSON.stringify(size));
  }
}

const page = await browser.newPage({ viewport: { width: 375, height: 812 }, timezoneId: 'Asia/Jakarta' });
try {
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.hostname !== '127.0.0.1') {
      outside.add(url.hostname);
      return route.abort();
    }
    if (url.origin !== web) return route.fallback();
    const cors = {
      'Access-Control-Allow-Origin': app,
      'Access-Control-Allow-Headers': 'authorization,content-type',
      'Access-Control-Allow-Methods': 'GET,POST,PUT',
    };
    if (request.method() === 'OPTIONS') return route.fulfill({ status: 204, headers: cors });
    if (url.pathname.endsWith('/belum-dibaca')) {
      return route.fulfill({ status: 503, headers: cors, json: { success: false, data: null } });
    }
    try {
      const response = await route.fetch({ maxRedirects: 0 });
      await route.fulfill({ response, headers: { ...response.headers(), ...cors } });
    } catch (error) {
      // Navigation can cancel an in-flight request when navigating away.
      if (!/Route is already handled|Request context disposed/.test(error.message)) {
        unexpectedRouteErrors.push(`${request.method()} ${url.pathname}: ${error.name}`);
      }
    }
  });

  await page.goto(app + '/login');
  await page.getByPlaceholder('Masukkan username').waitFor();
  await check('target mata di Masuk minimal 44×44', () => dimensions(page));
  await check('target isian Masuk minimal 44×44', async () => {
    for (const label of ['Masukkan username', 'Masukkan password']) {
      const size = await page.getByPlaceholder(label).boundingBox();
      assert.ok(size && size.width >= 44 && size.height >= 44, JSON.stringify(size));
    }
  });
  await page.getByPlaceholder('Masukkan username').fill('sbx_pengurus_biasa');
  await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
  await page.getByRole('button', { name: 'Masuk', exact: true }).click();
  const identity = page.getByTestId('kartu-identitas');
  await identity.getByText('Pengurus', { exact: true }).first().waitFor();
  await page.getByText('Penugasan aktif', { exact: true }).waitFor();
  await check('ringkasan tidak menampilkan angka notifikasi saat respons belum tersedia', async () => {
    assert.equal(await page.getByText('Notifikasi belum dibaca', { exact: true }).count(), 0);
  });
  await check('status identitas Beranda memakai ikon dan teks', async () => {
    const status = identity.getByText('Pengurus', { exact: true }).first();
    assert.equal(await status.locator('..').locator('img').count(), 1);
  });
  await identity.getByRole('button', { name: /Buka profil/ }).click();
  await page.getByRole('button', { name: /^Ganti password/ }).click();
  await page.getByLabel('Ulangi password baru', { exact: true }).waitFor();
  await check('target ketiga mata di Ganti password minimal 44×44', () => dimensions(page));
  await check('semua tujuan jaringan di 127.0.0.1', () => assert.equal(outside.size, 0));
} finally {
  await page.unrouteAll({ behavior: 'wait' });
  await browser.close();
}
assert.deepEqual(unexpectedRouteErrors, []);
process.exitCode = failures ? 1 : 0;
