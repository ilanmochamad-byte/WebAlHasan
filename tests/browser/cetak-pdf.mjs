/**
 * Render halaman cetak menjadi PDF sungguhan memakai Chromium (Playwright).
 *
 * Dipakai `tests/v2_phase5_cetak_pdf.php` untuk MEMBUKTIKAN hasil PDF, bukan
 * hanya memeriksa string CSS. Pemeriksaan string tidak pernah dapat menangkap
 * cacat seperti `Halaman 0`, karena cacat itu baru muncul ketika mesin cetak
 * mengevaluasi `counter(page)`.
 *
 * Skrip ini SENGAJA tidak memakai `preferCSSPageSize` secara membabi buta:
 * mode `--paksa-orientasi` meniru perilaku Safari/dialog cetak macOS yang
 * MENGABAIKAN `@page { size: ... }` dan memakai orientasi pilihan pengguna.
 * Dengan begitu pengujian dapat membuktikan bahwa hasil potret pun tetap
 * terbaca dan bernomor benar (kriteria penerimaan 2 dan 4).
 *
 * Pemakaian:
 *   node tests/browser/cetak-pdf.mjs <input.html> <output.pdf> [--orientasi=css|potret|lanskap]
 *
 * Keluaran: JSON satu baris ke stdout berisi jumlah halaman dan ukuran kertas.
 */

import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';

const [inputArg, outputArg, ...opsi] = process.argv.slice(2);

if (!inputArg || !outputArg) {
  console.error('Pemakaian: node tests/browser/cetak-pdf.mjs <input.html> <output.pdf> [--orientasi=css|potret|lanskap]');
  process.exit(2);
}

const orientasi = (opsi.find((o) => o.startsWith('--orientasi=')) ?? '--orientasi=css').split('=')[1];
if (!['css', 'potret', 'lanskap'].includes(orientasi)) {
  console.error('Orientasi tidak dikenal: ' + orientasi);
  process.exit(2);
}

const input = resolve(inputArg);
const output = resolve(outputArg);

// CHROMIUM_PATH memungkinkan pemakaian Chromium yang sudah tersedia di mesin
// atau lingkungan CI, ketika revisi bawaan Playwright tidak dapat diunduh
// (misalnya sandbox tanpa jaringan keluar). Perilaku render tidak berubah.
const browser = await chromium.launch(
  process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}
);
try {
  const page = await browser.newPage();
  await page.goto(pathToFileURL(input).href, { waitUntil: 'load' });
  // Media cetak diaktifkan supaya seluruh aturan `@media print` benar-benar
  // dievaluasi, sama seperti ketika pengguna menekan Cetak.
  await page.emulateMedia({ media: 'print' });

  /**
   * Meniru dialog cetak yang MENGABAIKAN `@page { size: ... }`.
   *
   * `preferCSSPageSize:false` saja TIDAK cukup pada Chromium modern: aturan
   * `@page{size:A4 landscape}` tetap menentukan kotak halaman. Karena itu
   * orientasi dipaksa dengan menyuntikkan aturan `@page` baru di akhir cascade,
   * persis seperti pengguna Safari yang memilih orientasi sendiri.
   */
  if (orientasi !== 'css') {
    await page.addStyleTag({
      content: `@page{size:A4 ${orientasi === 'lanskap' ? 'landscape' : 'portrait'}}`,
    });
  }

  const pdfOptions = {
    path: output,
    printBackground: true,
    preferCSSPageSize: true,
  };

  await page.pdf(pdfOptions);
  await page.close();
} finally {
  await browser.close();
}

// Membaca kembali PDF hanya untuk melaporkan jumlah halaman dan ukurannya.
const { readFileSync } = await import('node:fs');
const buf = readFileSync(output);
const teks = buf.toString('latin1');
const kotak = [...teks.matchAll(/\/MediaBox\s*\[\s*([\d.\s-]+?)\s*\]/g)].map((m) =>
  m[1].trim().split(/\s+/).map(Number),
);
const pertama = kotak[0] ?? [0, 0, 0, 0];
const lebar = pertama[2] - pertama[0];
const tinggi = pertama[3] - pertama[1];

process.stdout.write(
  JSON.stringify({
    berkas: output,
    orientasi_diminta: orientasi,
    lebar_pt: Number(lebar.toFixed(1)),
    tinggi_pt: Number(tinggi.toFixed(1)),
    orientasi_hasil: lebar > tinggi ? 'lanskap' : 'potret',
    jumlah_mediabox: kotak.length,
  }) + '\n',
);
