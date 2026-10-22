/** Matriks baca-saja untuk membandingkan baseline dan hasil audit Tahap 1. */
import { launchAudit, login, base } from './audit-runtime.mjs';
import { writeFileSync } from 'node:fs';
if (!process.env.MATRIX_OUT) throw Error('Set MATRIX_OUT ke berkas bukti audit.');
const rows = [];
for (const user of ['sbx_admin', 'sbx_guru_biasa', 'sbx_murobi_a', 'sbx_pengurus_a', 'sbx_ortu_a']) {
    const { browser, context, page } = await launchAudit();
    try {
      await login(page, user);
      // Baseline belum memiliki .ah-nav-sec; ambil kedua bentuk sidebar.
      const urls = await page.locator('#ah-sidebar a[href]').evaluateAll(nodes => [...new Set(nodes
        .map(n => new URL(n.href)).filter(u => !/logout\.php$/.test(u.pathname)).map(u => u.pathname + u.search))]);
      for (const width of [375, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        for (const url of urls) {
          const response = await page.goto(base + url);
          rows.push({ user, width, url, status: response.status(), layout: !!await page.locator('#ah-shell').count(),
            overflow: await page.evaluate(() => document.documentElement.scrollWidth - innerWidth) });
        }
      }
    } finally { await context.close(); await browser.close(); }
}
writeFileSync(process.env.MATRIX_OUT, JSON.stringify(rows, null, 2) + '\n');
console.log(`${rows.length} observasi disimpan; ${rows.filter(r => r.layout && r.overflow > 0).length} Layout melebar.`);
