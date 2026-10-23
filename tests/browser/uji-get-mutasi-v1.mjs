// Klik nyata mutasi V1 (POST + CSRF) setelah redesain web Tahap 3: keputusan
// pendaftar dan pembayaran ada di panel detail, konfirmasi memakai dialog
// bersama (bukan confirm()/alert() peramban), hapus konten lewat menu ⋯/panel.
import { launchAudit, login, base } from './audit-runtime.mjs';
import { readFileSync } from 'node:fs';
const manifest = process.env.GETMUTASI_BROWSER_FIXTURE;
if (!manifest?.startsWith('/private/tmp/')) throw Error('Private synthetic fixture manifest required.');
const ids = JSON.parse(readFileSync(manifest, 'utf8'));
const { browser, page } = await launchAudit();
let count = 0;
const ok = (v, label) => { if (!v) throw Error(label); count++; console.log('[lulus] ' + label); };
const native = [];
page.on('dialog', async d => { native.push(d.type()); await d.dismiss(); });
const formFor = (field, id, action) => page.locator(`form[action*="${action}"]`).filter({ has: page.locator(`input[name="${field}"][value="${id}"]`) });
const dialog = () => page.locator('dialog.ah-dialog[open]');
// Klik tombol, setujui dialog bersama (opsional ketik nama/alasan), tunggu POST dan halaman tujuan.
const submit = async (button, target, expected, { confirm = true, match = null, reason = null } = {}) => {
  const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(target));
  // Halaman tujuan bisa beralamat sama dengan halaman asal (panel ?id=), jadi
  // tunggu navigasi sesudah POST selesai dimuat, bukan sekadar alamat.
  const landed = page.waitForNavigation({ waitUntil: 'load' });
  await button.click();
  if (confirm) {
    await dialog().waitFor();
    if (match) await page.locator('#ah-dialog-match').fill(match);
    if (reason) await page.locator('#ah-dialog-reason').fill(reason);
    await dialog().locator('[data-ah-ok]').click();
  }
  const response = await responsePromise;
  const body = new URLSearchParams(response.request().postData());
  ok([200, 302].includes(response.status()) && body.get('_csrf')?.length === 64 && body.get(expected.field) === String(expected.id), target + ': klik nyata mengirim POST, CSRF, target');
  await landed;
  ok(new URL(page.url()).pathname.endsWith('/admin/' + expected.page), target + ': kembali ke ' + expected.page);
  return body;
};
try {
  await login(page);
  for (const width of [1440, 375]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(base + '/admin/admin_data.php?id=' + ids.tolak);
    const button = page.locator(`#formStatusTolak${ids.tolak} button`);
    ok(await button.isVisible() && await button.evaluate(el => el.form?.method === 'post' && el.form._csrf.value.length === 64), width + ': tombol Tolak di panel adalah formulir POST ber-CSRF');
    const accept = page.locator(`#formStatusTerima${ids.tolak} button`);
    const rejectBox = await button.boundingBox(); const acceptBox = await accept.boundingBox();
    ok(rejectBox.height >= 44 && acceptBox.height >= 44, width + ': tombol Terima/Tolak bersasaran sentuh ≥ 44px');
    const before = page.url(); let posts = 0;
    const onRequest = r => { if (r.method() === 'POST') posts++; };
    page.on('request', onRequest);
    await button.click();
    await dialog().waitFor();
    await dialog().locator('[data-ah-cancel]').click();
    await page.waitForTimeout(150);
    page.off('request', onRequest);
    ok(posts === 0 && page.url() === before && await dialog().count() === 0, width + ': Batal pada dialog Tolak tidak mengirim POST');
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  await submit(page.locator(`#formStatusTolak${ids.tolak} button`), 'proses_psb_admin.php', { field: 'id', id: ids.tolak, page: 'admin_data.php' });
  ok((await page.locator('.ah-panel').textContent()).includes('Ditolak') && (await page.locator('.ah-note').first().textContent()).includes('Ditolak'), 'Status Ditolak tampil di panel dan pesan setelah klik');
  await page.goto(base + '/admin/admin_data.php?id=' + ids.terima);
  await submit(page.locator(`#formStatusTerima${ids.terima} button`), 'proses_psb_admin.php', { field: 'id', id: ids.terima, page: 'admin_data.php' }, { confirm: false });
  ok((await page.locator('.ah-panel').textContent()).includes('Diterima'), 'Status Diterima tampil setelah klik');
  await submit(formFor('id', ids.terima, 'proses_psb_admin.php').locator('button'), 'proses_psb_admin.php', { field: 'id', id: ids.terima, page: 'admin_data.php' });
  ok(await formFor('id', ids.terima, 'proses_psb_admin.php').count() === 0 && (await page.locator('.ah-note').first().textContent()).includes('Migrasi berhasil'), 'Tombol migrasi hilang dan pesan NIS tampil setelah migrasi');
  await page.goto(base + '/admin/admin_data.php?id=' + ids.hapus);
  await page.locator('.ah-panel details.ah-menu > summary').click();
  const nama = (await page.locator('#ah-panel-title').textContent()).split(' · ').slice(1).join(' · ').trim();
  await page.locator('.ah-panel form[action="hapus_siswa.php"] button').click();
  await dialog().waitFor();
  await page.locator('#ah-dialog-match').fill('salah');
  await dialog().locator('[data-ah-ok]').click();
  ok(await dialog().count() === 1 && !(await page.locator('#ah-dialog-match-error').isHidden()), 'Hapus pendaftar menolak ketikan nama yang salah');
  await dialog().locator('[data-ah-cancel]').click();
  const menu = page.locator('.ah-panel details.ah-menu');
  if (!(await menu.evaluate(n => n.open))) await menu.locator('summary').click();
  await submit(page.locator('.ah-panel form[action="hapus_siswa.php"] button'), 'hapus_siswa.php', { field: 'id', id: ids.hapus, page: 'admin_data.php' }, { match: nama });
  ok(await page.locator('tr').filter({ hasText: ids.prefix + '-hapus' }).count() === 0, 'Pendaftar terhapus hilang dari daftar');
  await page.goto(base + '/admin/admin_pembayaran_psb.php?id=' + ids.bayar);
  await submit(formFor('id', ids.bayar, 'admin_pembayaran_psb.php').locator('button'), 'admin_pembayaran_psb.php', { field: 'id', id: ids.bayar, page: 'admin_pembayaran_psb.php' });
  ok(await formFor('id', ids.bayar, 'admin_pembayaran_psb.php').locator('input[name="act"][value="batal"]').count() === 1, 'Tandai lunas mengganti tombol menjadi Batal lunas (menu ⋯)');
  await page.locator('.ah-panel details.ah-menu > summary').click();
  const batal = await submit(formFor('id', ids.bayar, 'admin_pembayaran_psb.php').locator('button'), 'admin_pembayaran_psb.php', { field: 'id', id: ids.bayar, page: 'admin_pembayaran_psb.php' }, { reason: 'Uji browser sintetis' });
  ok(batal.get('alasan') === 'Uji browser sintetis' && await formFor('id', ids.bayar, 'admin_pembayaran_psb.php').locator('input[name="act"][value="lunas"]').count() === 1, 'Batal lunas mengirim alasan dan memulihkan Tandai lunas');
  await page.goto(base + '/admin/admin_berita.php');
  await formFor('id', ids.berita, 'proses_berita.php').locator('xpath=ancestor::details').locator('summary').click();
  await submit(formFor('id', ids.berita, 'proses_berita.php').locator('button'), 'proses_berita.php', { field: 'id', id: ids.berita, page: 'admin_berita.php' });
  ok(await formFor('id', ids.berita, 'proses_berita.php').count() === 0, 'berita: baris terhapus hilang dari daftar');
  await page.goto(base + '/admin/admin_galeri.php?id=' + ids.galeri);
  await submit(formFor('hapus', ids.galeri, 'admin_galeri.php').locator('button'), 'admin_galeri.php', { field: 'hapus', id: ids.galeri, page: 'admin_galeri.php' });
  ok(await page.locator(`a[href$="id=${ids.galeri}"]`).count() === 0, 'galeri: foto terhapus hilang dari kisi');
  await page.goto(base + '/admin/admin_download.php');
  await formFor('hapus', ids.download, 'admin_download.php').locator('xpath=ancestor::details').locator('summary').click();
  await submit(formFor('hapus', ids.download, 'admin_download.php').locator('button'), 'admin_download.php', { field: 'hapus', id: ids.download, page: 'admin_download.php' });
  ok(await formFor('hapus', ids.download, 'admin_download.php').count() === 0, 'download: baris terhapus hilang dari daftar');
  ok(native.length === 0, 'Tidak ada confirm()/alert() bawaan peramban');
} finally { await browser.close(); }
console.log(`Browser GET mutation audit: ${count} passed, 0 failed.`);
