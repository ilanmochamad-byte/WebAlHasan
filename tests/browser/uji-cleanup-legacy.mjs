import { launchAudit, login, base } from './audit-runtime.mjs';
import { mkdirSync } from 'node:fs';

const out = process.env.CLEANUP_BROWSER_EVIDENCE;
if (!out?.startsWith('/private/tmp/')) throw Error('Use a private test evidence directory.');
mkdirSync(out, { recursive: true });
const { browser, page } = await launchAudit();
let count = 0;
const ok = (value, label) => {
  if (!value) throw Error(label);
  count++;
  console.log('[lulus] ' + label);
};
try {
  await login(page);
  for (const width of [1440, 375]) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(base + '/portal/index.php');
    const menu = page.locator('#ah-sidebar');
    ok(await menu.locator('a[href="/portal/izin.php"]').count() === 1, `${width}: satu menu daftar izin V2`);
    ok(await menu.locator('a[href*="admin_izin.php"]').count() === 0, `${width}: tidak ada menu tulis izin lama`);
    ok(await menu.locator('a[href="/admin/admin_pelanggaran.php"]').count() === 1, `${width}: menu arsip pelanggaran tetap ada`);
    ok(await menu.locator('a[href="/admin/admin_data.php"]').count() === 1, `${width}: menu PSB tetap ada`);
    if (width === 375) await page.getByRole('button', { name: 'Buka menu navigasi' }).click();
    await menu.locator('a[href="/portal/izin.php"]').click();
    await page.waitForURL('**/portal/izin.php');
    ok(await page.locator('select[name="source"] option[value="legacy"]').count() === 1, `${width}: filter riwayat izin warisan tersedia`);
    await page.selectOption('select[name="source"]', 'legacy');
    await page.locator('select[name="source"]').evaluate(el => el.form.requestSubmit());
    await page.waitForURL('**source=legacy**');
    ok((await page.locator('body').textContent()).includes('CLEANUP synthetic history'), `${width}: riwayat sintetis tampil setelah filter`);
    await page.goto(base + '/admin/admin_pelanggaran.php');
    ok((await page.locator('h1').textContent()).includes('warisan'), `${width}: judul arsip baca-saja`);
    ok(await page.locator('form[method="post"]').count() === 0, `${width}: arsip tanpa form tambah/hapus`);
    ok(await page.locator('td script').count() === 0
      && (await page.locator('tbody').textContent()).includes('<script>synthetic</script>'), `${width}: isi arsip dirender sebagai teks`);
    await page.goto(base + '/admin/admin_pengajian.php?tab=jadwal');
    await page.locator('a[href*="tab=pertemuan"]').first().click();
    await page.waitForURL('**tab=pertemuan**');
    ok((await page.locator('body').textContent()).includes('Pertemuan'), `${width}: tab jadwal ke pertemuan tetap berjalan`);
    if (width === 375) {
      await page.goto(base + '/portal/izin.php?source=legacy');
      await page.screenshot({ path: out + '/riwayat-izin-375.png', fullPage: true });
    }
  }
  for (const actor of ['sbx_guru_biasa', 'sbx_pengurus_a', 'sbx_ortu_a']) {
    await page.context().clearCookies();
    const target = await page.context().newPage();
    await login(target, actor);
    await target.goto(base + '/portal/index.php');
    ok(await target.locator('#ah-sidebar a[href*="admin_izin.php"]').count() === 0, actor + ': tidak ditawarkan antarmuka lama');
    ok(await target.locator('#ah-sidebar a[href*="admin_pelanggaran.php"]').count() === 0, actor + ': arsip admin tidak ditawarkan');
    await target.close();
  }
} finally {
  await browser.close();
}
console.log(`Hasil browser cleanup: ${count} lulus, 0 gagal.`);
