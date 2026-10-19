import { launchAudit, login, base } from './audit-runtime.mjs';
import { readFileSync } from 'node:fs';
const manifest = process.env.GETMUTASI_BROWSER_FIXTURE;
if (!manifest?.startsWith('/private/tmp/')) throw Error('Private synthetic fixture manifest required.');
const ids = JSON.parse(readFileSync(manifest, 'utf8'));
const { browser, page } = await launchAudit();
let count = 0;
const ok = (v, label) => { if (!v) throw Error(label); count++; console.log('[lulus] ' + label); };
const formFor = (field, id, action) => page.locator(`form[action*="${action}"]`).filter({ has: page.locator(`input[name="${field}"][value="${id}"]`) });
const submit = async (button, target, expected, confirm = true) => {
  const dialogs = [];
  const handler = async d => { dialogs.push(d.type()); await d.accept(); };
  page.on('dialog', handler);
  const responsePromise = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(target));
  await button.click();
  const response = await responsePromise;
  const body = new URLSearchParams(response.request().postData());
  ok([200,302].includes(response.status()) && body.get('_csrf')?.length === 64 && body.get(expected.field) === String(expected.id), target + ': real click submits POST, CSRF, target');
  await page.waitForURL('**/admin/' + expected.page);
  await page.waitForLoadState('load');
  if (confirm) ok(dialogs.includes('confirm'), target + ': confirmation appears');
  page.off('dialog', handler);
};
try {
  await login(page);
  for (const width of [1440, 375]) {
    await page.setViewportSize({width, height:900});
    await page.goto(base + '/admin/admin_data.php');
    const button = page.locator(`button[form="formStatusTolak${ids.tolak}"]`);
    ok(await button.isVisible() && await button.evaluate(el => el.form?.method === 'post' && el.form._csrf.value.length === 64), width + ': external reject button associates with CSRF POST form');
    const accept = page.locator(`button[form="formStatusTerima${ids.terima}"]`);
    const rejectBox = await button.boundingBox(); const acceptBox = await accept.boundingBox();
    ok(rejectBox.width > 0 && acceptBox.width > 0, width + ': accept/reject buttons have usable boxes');
    const before = page.url();
    let confirmed = false; let posts = 0;
    const onRequest = r => { if (r.method() === 'POST') posts++; };
    page.on('request', onRequest);
    page.once('dialog', async d => { confirmed = d.type() === 'confirm'; await d.dismiss(); });
    await button.click();
    await page.waitForTimeout(100);
    page.off('request', onRequest);
    ok(confirmed && posts === 0 && page.url() === before, width + ': cancel reject sends no POST');
  }
  await page.setViewportSize({width:1440,height:900});
  await submit(page.locator(`button[form="formStatusTolak${ids.tolak}"]`), 'proses_psb_admin.php', {field:'id',id:ids.tolak,page:'admin_data.php'});
  ok((await page.locator('tr').filter({hasText:ids.prefix+'-tolak'}).first().textContent()).includes('Ditolak'), 'Reject status visible after real submit');
  await submit(page.locator(`button[form="formStatusTerima${ids.terima}"]`), 'proses_psb_admin.php', {field:'id',id:ids.terima,page:'admin_data.php'}, false);
  ok((await page.locator('tr').filter({hasText:ids.prefix+'-terima'}).first().textContent()).includes('DITERIMA'), 'Accepted status visible after real submit');
  await submit(formFor('id',ids.terima,'proses_psb_admin.php').locator('button'), 'proses_psb_admin.php', {field:'id',id:ids.terima,page:'admin_data.php'});
  ok(await formFor('id',ids.terima,'proses_psb_admin.php').count() === 0, 'Migration button gone after successful migration');
  await submit(formFor('id',ids.hapus,'hapus_siswa.php').locator('button'), 'hapus_siswa.php', {field:'id',id:ids.hapus,page:'admin_data.php'});
  ok(await page.locator('tr').filter({hasText:ids.prefix+'-hapus'}).count() === 0, 'Deleted applicant gone from list');
  await page.goto(base+'/admin/admin_pembayaran_psb.php');
  await submit(formFor('id',ids.bayar,'admin_pembayaran_psb.php').locator('button'), 'admin_pembayaran_psb.php', {field:'id',id:ids.bayar,page:'admin_pembayaran_psb.php'});
  ok(await formFor('id',ids.bayar,'admin_pembayaran_psb.php').locator('input[name="act"][value="batal"]').count() === 1, 'Approve changes button to cancel payment');
  await submit(formFor('id',ids.bayar,'admin_pembayaran_psb.php').locator('button'), 'admin_pembayaran_psb.php', {field:'id',id:ids.bayar,page:'admin_pembayaran_psb.php'});
  ok(await formFor('id',ids.bayar,'admin_pembayaran_psb.php').locator('input[name="act"][value="lunas"]').count() === 1, 'Cancel payment restores approve button');
  for (const [key,field,action] of [['berita','id','proses_berita.php'],['galeri','hapus','admin_galeri.php'],['download','hapus','admin_download.php']]) {
    await page.goto(base+`/admin/admin_${key}.php`);
    await submit(formFor(field,ids[key],action).locator('button'), action, {field,id:ids[key],page:`admin_${key}.php`});
    ok(await formFor(field,ids[key],action).count() === 0, key + ': deleted row gone from list');
  }
} finally { await browser.close(); }
console.log(`Browser GET mutation audit: ${count} passed, 0 failed.`);
