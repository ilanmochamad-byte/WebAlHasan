import fs from 'node:fs';
import crypto from 'node:crypto';
import { chromium } from 'playwright';
const base = process.env.V4_TEST_URL, mobile = process.env.V4_APP_URL;
if (![base, mobile].every(url => /^http:\/\/127\.0\.0\.1:/.test(url))) throw Error('Hanya server uji localhost');
const tokens = JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS));
const ids = JSON.parse(fs.readFileSync(process.env.V4_IDS_FILE));
const out = process.env.V4_EVIDENCE_DIR;
const ok = (v, label) => { if (!v) throw Error(label); console.log('[lulus] '+label); };
async function api(user, path, body) {
 const response = await fetch(base+'/api/v1/v4/'+path, { method: body ? 'POST' : 'GET', headers: { Authorization: 'Bearer '+tokens[user], 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined });
 const payload = await response.json(); return { status: response.status, data: payload.data };
}
const browser = await chromium.launch({ headless: true });
async function app(user) {
 const page = await browser.newPage({ viewport: { width: 375, height: 812 } });
 // Transport proxy for Expo web only. Backend still authenticates every real request.
 await page.route('**/api/v1/**', async route => {
  if (new URL(route.request().url()).origin !== base) { await route.abort(); throw Error('Alamat API bundle bukan localhost uji; request diblokir'); }
  if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: { 'Access-Control-Allow-Origin': mobile, 'Access-Control-Allow-Headers': 'authorization,content-type', 'Access-Control-Allow-Methods': 'GET,POST,PUT' } });
  const response = await route.fetch(); await route.fulfill({ response, headers: { ...response.headers(), 'Access-Control-Allow-Origin': mobile } });
 });
 await page.goto(mobile+'/login');
 await page.getByPlaceholder('Masukkan username').fill(user); await page.getByPlaceholder('Masukkan password').fill(process.env.V4_TEST_PASSWORD);
 await page.getByRole('button', { name: 'Masuk', exact: true }).click();
 await page.waitForURL(url => url.pathname !== '/login');
 return page;
}
async function pick(page, label, option) {
 await page.getByRole('button', { name: label, exact: true }).click();
 await page.getByRole('button', { name: option, exact: true }).click();
 await page.getByRole('button', { name: 'Tutup pilihan', exact: true }).waitFor({ state: 'hidden' });
}
async function layout(page, label) {
 for (const width of [375, 1440]) {
  await page.setViewportSize({ width, height: 900 });
  ok(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), label+' '+width+' px tanpa overflow');
  await page.screenshot({ path: `${out}/app-${label}-${width}.png`, fullPage: true });
 }
 await page.setViewportSize({width:375,height:812});
}
async function web(user, path) {
 const page = await browser.newPage({ viewport: {width:375,height:812} });
 await page.goto(base+'/portal/index.php'); await page.locator('[name=username]').fill(user); await page.locator('[name=password]').fill(process.env.V4_TEST_PASSWORD); await page.locator('button[type=submit]').first().click(); await page.goto(base+path); return page;
}
try {
 const option = (await api('v4_guru','nilai/options')).data.find(o => Number(o.id) === Number(ids.mapel));
 const students = (await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}`)).data.santri;
 let student;
 for (const s of students) {
  const d = await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}&santri_id=${s.id}`);
  const a = await api('v4_pembimbing',`sikap?rencana_id=${ids.rencana}&santri_id=${s.id}`);
  if (!d.data.nilai.length && !a.data.sikap) { student=s; break; }
 }
 if (!student) throw Error('Butuh santri sintetis tanpa bahan untuk alur app');
 const teacher=await app('v4_guru'); await teacher.goto(mobile+'/rapor/nilai');
 await pick(teacher,'Kelas, semester, dan mapel',`${option.nama_kelas} · ${option.tahun} ${option.semester} · ${option.mata_pelajaran}`);
 await pick(teacher,'Santri',student.nama_santri);
 await teacher.getByRole('textbox',{name:'Harian 1',exact:true}).fill('85'); await teacher.getByRole('textbox',{name:'Tengah',exact:true}).fill('78'); await teacher.getByRole('textbox',{name:'Akhir',exact:true}).fill('92');
 const saved=teacher.waitForResponse(r=>r.url().endsWith('/v4/nilai')&&r.request().method()==='POST');
 await teacher.getByRole('button',{name:'Serahkan nilai',exact:true}).dblclick();ok((await saved).status()===200,'Aplikasi guru menyerahkan nilai ke API nyata');
 await teacher.getByText('Perubahan tersimpan.',{exact:false}).waitFor();
 const actual=(await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}&santri_id=${student.id}`)).data;
 ok(actual.nilai.length===3&&actual.hasil.nilai_tampil===85,'Nilai aplikasi tersimpan 3 komponen dan 85 B');
 const site=await web('v4_guru',`/portal/nilai_semester.php?rencana_mapel_id=${ids.mapel}&santri_id=${student.id}`);
 ok((await site.locator('#hasil-nilai').textContent()).includes('Nilai akhir 85'),'Nilai aplikasi identik pada website');
 await layout(teacher,'guru');
 await teacher.getByRole('textbox',{name:'Alasan koreksi / tindakan admin'}).fill('Koreksi aplikasi sintetis');
 const newer=structuredClone(actual.nilai); newer[0].nilai='91';
 ok((await api('v4_guru','nilai',{rencana_mapel_id:ids.mapel,santri_id:student.id,action:'koreksi',alasan:'Perubahan dari sesi lain',komponen:newer})).status===200,'Sesi lain menyimpan versi baru');
 const conflict=teacher.waitForResponse(r=>r.url().endsWith('/v4/nilai')&&r.request().method()==='POST');
 await teacher.getByRole('button',{name:'Koreksi nilai beralasan'}).click();ok((await conflict).status()===409,'Aplikasi versi usang mendapat 409');
 await teacher.getByText('Muat ulang data sebelum mencoba kembali.',{exact:false}).waitFor();
 ok((await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}&santri_id=${student.id}`)).data.nilai[0].nilai==='91.00','409 aplikasi tidak menimpa versi terbaru');
 const guide=await app('v4_pembimbing');await guide.goto(mobile+'/rapor/sikap');
 await pick(guide,'Santri, kelas, dan semester',`${student.nama_santri} · ${option.nama_kelas} · ${option.tahun} ${option.semester}`);
 for(const label of ['Adab/akhlak','Kedisiplinan','Tanggung jawab'])await pick(guide,label,'Baik');
 await guide.getByRole('textbox',{name:'Catatan pembimbing',exact:true}).fill('Catatan aplikasi sintetis');
 const attitude=guide.waitForResponse(r=>r.url().endsWith('/v4/sikap')&&r.request().method()==='POST');await guide.getByRole('button',{name:'Serahkan sikap',exact:true}).click();ok((await attitude).status()===200,'Aplikasi pembimbing menyerahkan sikap');
 await guide.getByText('Perubahan tersimpan.',{exact:false}).waitFor();await layout(guide,'pembimbing');
 const attitudeWeb=await web('v4_pembimbing',`/portal/sikap_semester.php?rencana_id=${ids.rencana}&santri_id=${student.id}`);
 ok((await attitudeWeb.locator('[name=catatan]').inputValue())==='Catatan aplikasi sintetis','Sikap aplikasi identik pada website');
 const mentor=await app('v4_murobi');await mentor.goto(mobile+'/rapor/sikap?mode=murobi');
 await pick(mentor,'Santri, kelas, dan semester',`${student.nama_santri} · ${option.nama_kelas} · ${option.tahun} ${option.semester}`);
 const known=mentor.waitForResponse(r=>r.url().endsWith('/v4/sikap/murobi'));await mentor.getByRole('button',{name:'Ketahui sikap tanpa mengubah isi'}).click();ok((await known).status()===200,'Aplikasi murobi mengetahui sikap');await mentor.getByText('Perubahan tersimpan.',{exact:false}).waitFor();await layout(mentor,'murobi');
 await mentor.getByRole('textbox',{name:'Catatan pembimbing',exact:true}).fill('Koreksi murobi dari aplikasi');
 await mentor.getByRole('textbox',{name:'Alasan koreksi (wajib untuk koreksi)',exact:true}).fill('Klarifikasi sintetis');
 const corrected=mentor.waitForResponse(r=>r.url().endsWith('/v4/sikap/murobi'));await mentor.getByRole('button',{name:'Koreksi sikap beralasan'}).click();ok((await corrected).status()===200,'Aplikasi murobi koreksi beralasan');await mentor.getByText('Perubahan tersimpan.',{exact:false}).waitFor();
 const edu=await app('v4_pendidikan');await edu.goto(mobile+'/rapor/nilai?pengganti=1');
 await pick(edu,'Kelas, semester, dan mapel',`${option.nama_kelas} · ${option.tahun} ${option.semester} · ${option.mata_pelajaran}`);await pick(edu,'Santri',student.nama_santri);
 await edu.getByRole('textbox',{name:'Catatan Akhir',exact:true}).fill('Pengganti Pendidikan dari aplikasi');
 await edu.getByRole('textbox',{name:'Alasan pengganti / koreksi (wajib)',exact:true}).fill('Guru berhalangan sintetis');
 const substitute=edu.waitForResponse(r=>r.url().endsWith('/v4/nilai/pengganti'));await edu.getByRole('button',{name:'Koreksi nilai beralasan'}).click();ok((await substitute).status()===200,'Aplikasi Pendidikan mengisi pengganti beralasan');await edu.getByText('Perubahan tersimpan.',{exact:false}).waitFor();
 const substituted=(await api('v4_pendidikan',`nilai?rencana_mapel_id=${ids.mapel}&santri_id=${student.id}&pengganti=1`)).data;
 ok(substituted.nilai.find(v=>v.komponen==='Akhir').kapasitas==='pendidikan_pengganti','Riwayat kapasitas pengganti tersimpan oleh server');
 await edu.goto(mobile+'/rapor/verifikasi');
 await pick(edu,'Kelas dan semester',`${option.nama_kelas} · ${option.tahun} ${option.semester}`);await pick(edu,'Santri',student.nama_santri);
 const verified=edu.waitForResponse(r=>r.url().endsWith('/v4/verifikasi')&&r.request().method()==='POST');await edu.getByRole('button',{name:'Verifikasi Sikap',exact:true}).click();ok((await verified).status()===200,'Aplikasi Pendidikan memverifikasi sikap');await edu.getByText('Perubahan tersimpan.',{exact:false}).waitFor();
 const previewResponse=edu.waitForResponse(r=>r.url().endsWith('/v4/rapor/pratinjau'));
 await edu.getByRole('button',{name:'Pratinjau rapor dari server'}).click();ok((await previewResponse).status()===200,'Pratinjau aplikasi memakai gate server');
 await edu.getByRole('button',{name:'Terbitkan rapor',exact:true}).waitFor();ok(!await edu.getByRole('button',{name:'Terbitkan rapor',exact:true}).isEnabled(),'Aplikasi menahan terbit saat mapel wajib lain belum lengkap');await layout(edu,'pendidikan');
 for(const user of ['v4_pengurus','v4_wali4lain','v4_murobi_lain','v4_pembimbing_lain']){
  const p=await app(user);await p.goto(mobile+'/rapor/nilai');await p.getByText('Informasi tidak dapat diakses.',{exact:false}).waitFor();ok(true,'Aplikasi menolak akses nilai '+user);await p.close();
 }
 const parent=await app('v4_wali4a');await parent.goto(mobile+'/rapor');
 const report=(await api('v4_wali4a','rapor')).data.find(r=>r.status==='Terbit');if(!report)throw Error('Butuh rapor Terbit');
 const notifications=await fetch(base+'/api/v1/notifikasi',{headers:{Authorization:'Bearer '+tokens.v4_wali4a}}).then(r=>r.json());
 const notification=notifications.data.items.find(n=>n.tautan.rapor_id===Number(report.id));
 ok(notification.judul==='Rapor semester tersedia'&&notification.isi==='Rapor semester tersedia'&&!notification.santri_nama,'Notifikasi InApp rapor generik');
 await parent.goto(mobile+'/notifikasi/'+notification.id);await parent.getByRole('button',{name:'Buka rapor semester'}).click();await parent.getByRole('button',{name:'Unduh PDF rapor',exact:true}).waitFor();
 const download=parent.waitForEvent('download');await parent.getByRole('button',{name:'Unduh PDF rapor',exact:true}).click();const dl=await download;
 const digest=crypto.createHash('sha256').update(fs.readFileSync(await dl.path())).digest('hex');
 const parentWeb=await web('v4_wali4a',`/portal/rapor_wali.php?id=${report.id}`);
 const file=await parentWeb.request.get(base+`/portal/rapor_pdf.php?id=${report.id}&version=${report.current_version}`);
 ok(digest===crypto.createHash('sha256').update(await file.body()).digest('hex'),'PDF unduhan aplikasi dan website hash identik');await layout(parent,'wali');
 const other=await app('v4_wali4lain');await other.goto(mobile+'/rapor/'+report.id);await other.getByText('Informasi tidak dapat diakses.',{exact:false}).waitFor();ok(true,'Wali lain ditolak aplikasi pada ID langsung');
 ok((await api('v4_wali4lain',`rapor/${report.id}`)).status===403,'Wali lain ditolak API pada ID langsung');
 await other.goto(mobile+'/rapor');await other.getByText('Belum ada akses rapor',{exact:true}).waitFor();ok(true,'Aplikasi menampilkan keadaan tanpa akses ketika relasi wali kosong');
 await parent.route(`**/v4/rapor/${report.id}`,route=>route.abort());await parent.goto(mobile+'/rapor/'+report.id);await parent.getByText('Perangkat sedang offline',{exact:false}).waitFor();ok(true,'Aplikasi memberi pesan jaringan dan retry aman (gangguan mock)');
 await parent.unroute(`**/v4/rapor/${report.id}`);await parent.getByRole('button',{name:'Coba lagi',exact:true}).click();await parent.getByRole('button',{name:'Unduh PDF rapor',exact:true}).waitFor();ok(true,'Retry baca memuat ulang server');
 await parent.route(`**/v4/rapor/${report.id}`,route=>route.request().method()==='OPTIONS'?route.fallback():route.fulfill({status:401,headers:{'Access-Control-Allow-Origin':mobile,'Content-Type':'application/json'},body:JSON.stringify({success:false,data:null,error:{code:'TOKEN_EXPIRED',message:'Token uji kedaluwarsa'}})}));await parent.reload();await parent.getByPlaceholder('Masukkan username').waitFor();ok(await parent.evaluate(()=>sessionStorage.getItem('alhasan_teacher_api_token')===null),'401 membersihkan token dan meminta login (respons mock)');
 console.log('Bukti ini Expo WEB Chromium, bukan simulator/perangkat fisik atau pengiriman Push nyata.');
} finally { await browser.close(); }
