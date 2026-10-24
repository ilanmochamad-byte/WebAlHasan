// Regresi audit Tahap 8: jangkar menu HP, fokus galeri, pembiayaan tanpa JS.
// Jalankan sesudah fixture keamanan_psb_http.php pada server sintetis lokal.
import { chromium } from 'playwright';
const base = process.env.BASE_URL || '';
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base) || process.env.T8_BROWSER !== '1') throw Error('Hanya server uji loopback');
let pass = 0, fail = 0;
const check = (ok, label) => { console.log(`[${ok ? 'lulus' : 'gagal'}] ${label}`); ok ? pass++ : fail++; };
const browser = await chromium.launch();
try {
  for (const width of [375, 768, 1280]) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    if (width < 1100) {
      await page.goto(base + '/pendidikan.php');
      await page.locator('[data-s-burger]').click();
      await page.locator('.s-drop summary').filter({ hasText: 'Pendidikan' }).click();
      await page.locator('#s-nav a[href$="#unit-smp"]').click();
      check(await page.evaluate(() => !document.body.classList.contains('s-menu-buka') && document.querySelector('[data-s-burger]').getAttribute('aria-expanded') === 'false'), `${width}: jangkar unit pada halaman yang sama menutup menu dan membuka gulir`);
    }
    await page.goto(base + '/galeri.php');
    await page.locator('[data-s-galeri]').first().click();
    await page.keyboard.press('Shift+Tab');
    check(await page.evaluate(() => !!document.activeElement.closest('.s-viewer')), `${width}: Shift+Tab tetap di penampil galeri`);
    await page.keyboard.press('Tab');
    check(await page.evaluate(() => !!document.activeElement.closest('.s-viewer')), `${width}: Tab berputar di penampil galeri`);
    await page.keyboard.press('ArrowRight');
    check((await page.locator('.s-viewer__pos').innerText()).startsWith('2 dari'), `${width}: tombol panah tetap mengganti foto`);
    await page.keyboard.press('Escape');
    check(await page.evaluate(() => !document.querySelector('.s-viewer') && document.activeElement.matches('[data-s-galeri]') && document.body.style.overflow === ''), `${width}: Escape menutup galeri dan mengembalikan fokus serta gulir`);
    await context.close();

    const nojs = await browser.newContext({ javaScriptEnabled: false, viewport: { width, height: 900 } });
    await nojs.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const p = await nojs.newPage();
    await p.goto(base + '/login_pendaftar.php');
    await p.locator('[name=nisn]').fill('9900000006');
    await p.locator('[name=hp]').fill('081200000006');
    await Promise.all([p.waitForURL(/portal_pendaftar/), p.getByRole('button', { name: 'Masuk', exact: true }).click({ force: true })]);
    await p.goto(base + '/biaya_psb.php');
    await p.locator('[name=metode_pembayaran][value=Transfer]').check({ force: true });
    check(await p.locator('#infoTransfer').isVisible() && await p.getByText('1046-322-987', { exact: true }).isVisible(), `${width}: rekening transfer terbaca tanpa JavaScript`);
    check(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${width}: pembiayaan tanpa JavaScript tidak meluber`);
    await nojs.close();
  }
} finally { await browser.close(); }
console.log(`\nHasil: ${pass} lulus, ${fail} gagal`);
process.exitCode = fail ? 1 : 0;
