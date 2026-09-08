import {launchAudit,base,login} from './audit-runtime.mjs';
import {mkdirSync,writeFileSync} from 'node:fs';
const {browser,page,context}=await launchAudit();
const out=process.env.OUT_DIR??'/tmp/v3-browser';mkdirSync(out,{recursive:true});
const checks=[];const check=(ok,name)=>{checks.push({ok,name});console.log(`${ok?'[lulus]':'[gagal]'} ${name}`);};
const tag='WEB'+Date.now();const thresholdMin=2000000+Math.floor(Math.random()*800000);const path='/admin/admin_v3_katalog.php';let xss=false;page.on('dialog',async d=>{xss=true;await d.dismiss();});
try{
 check((await context.request.get(base+'/api/v1/v3/katalog')).status()===401,'API tanpa token ditolak');
 await login(page);
 for(const width of [1440,768,375]){
  await page.setViewportSize({width,height:900});
  for(const kind of ['kategori','katalog','ambang']){
   const response=await page.goto(base+path+'?jenis='+kind);
   check(response.status()===200,`${width} ${kind} HTTP 200`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&[...document.querySelectorAll('.v3-form')].every(e=>e.scrollWidth<=e.clientWidth+1)),`${width} ${kind} tanpa scroll horizontal formulir`);
   check(await page.evaluate(()=>[...document.querySelectorAll('.v3-form input:not([type=hidden]),.v3-form textarea,.v3-form select')].every(e=>e.labels?.length)),`${width} ${kind} label kontrol`);
   await page.screenshot({path:`${out}/${width}-${kind}.png`,fullPage:true});
  }
 }
 await page.goto(base+path+'?jenis=kategori');
 await page.locator('#v3-kode').fill(tag);await page.locator('#v3-nama').fill(tag+' <script>alert(1)</script>');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);
 check((await page.locator('body').innerText()).includes(tag+' <script>alert(1)</script>')&&!xss,'Kategori web tersimpan; XSS menjadi teks');
 const link=page.locator('tr').filter({hasText:tag}).getByRole('link');await link.click();
 const category=await page.locator('input[name=id]').inputValue();
 const csrf=await page.locator('.v3-form input[name=_csrf]').inputValue();
 for(const token of [undefined,'palsu']){
  const response=await context.request.post(base+path+'?jenis=kategori',{form:{kode:tag+'F',nama:'Fiktif',...(token?{_csrf:token}:{})}});
  check(response.status()===419,'CSRF hilang/palsu ditolak');
 }
 await page.locator('#v3-is_active').selectOption('0');await page.locator('#v3-reason').fill('Uji nonaktif');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);
 check((await page.locator('tr').filter({hasText:tag}).innerText()).includes('Nonaktif'),'Nonaktif web');
 await page.locator('tr').filter({hasText:tag}).getByRole('link').click();await page.locator('#v3-is_active').selectOption('1');await page.locator('#v3-reason').fill('Uji aktif');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);
 await page.goto(base+path+'?jenis=katalog');await page.locator('#v3-kode').fill(tag);await page.locator('#v3-nama').fill(tag);await page.locator('#v3-kategori_id').selectOption(category);await page.locator('#v3-poin_default').fill('8');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);check((await page.locator('body').innerText()).includes(tag),'Jenis pelanggaran dibuat melalui web');
 await page.locator('tr').filter({hasText:tag}).getByRole('link').click();
 const endDate=await page.locator('#v3-tanggal_mulai').inputValue();await page.locator('#v3-tanggal_selesai').fill(endDate);await page.locator('#v3-reason').fill('Pengakhiran uji');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);
 await page.locator('tr').filter({hasText:tag}).getByRole('link').click();
 check(await page.locator('#v3-tanggal_selesai').inputValue()===endDate,'Pengakhiran katalog tersimpan melalui web');
 await page.locator('details summary').first().click();
 check((await page.locator('body').innerText()).includes('Pengakhiran uji'),'Alasan pengakhiran tampil di riwayat audit');
 await page.goto(base+path+'?jenis=ambang');await page.locator('#v3-label').fill(tag);await page.locator('#v3-nilai_minimum').fill(String(thresholdMin));await page.locator('#v3-nilai_maksimum').fill(String(thresholdMin+10));await page.locator('#v3-description').fill('Rekomendasi fiktif');
 await page.locator('#v3-tahun_ajaran_id').selectOption({label:'2026/2027 / Ganjil'});
 const year=await page.locator('#v3-tahun_ajaran_id').inputValue();
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);check((await page.locator('body').innerText()).includes(tag),'Ambang dibuat melalui web');
 await page.goto(base+path+'?jenis=kategori');await page.locator('#v3-kode').fill(tag);await page.locator('#v3-nama').fill('Tetap tersimpan');
 await Promise.all([page.waitForNavigation(),page.locator('.v3-form button').click()]);check(await page.locator('#v3-nama').inputValue()==='Tetap tersimpan','Input aman dipertahankan setelah duplikasi');
 check((await page.locator('body').innerText()).includes('Kode sudah digunakan'),'Pesan kode duplikat aman');
 const old=await context.request.get(base+'/admin/admin_pelanggaran.php?hapus=1');check(old.status()===405,'Hapus GET warisan ditolak');
 const oldPost=await context.request.post(base+'/admin/admin_pelanggaran.php',{form:{_csrf:csrf,tambah:1}});check(oldPost.status()===405,'Pencatatan warisan ditutup');
 for(const user of ['sbx_admin','sbx_pengurus_a','sbx_murobi_a','sbx_guru_biasa','sbx_ortu_a']){
  const auth=await context.request.post(base+'/api/v1/auth/login',{data:{username:user,password:'Sandbox#123'}});const a=await auth.json();
  check(auth.status()===200,`${user} login API lama`);if(!a.data?.token)continue;
  const headers={Authorization:'Bearer '+a.data.token};
  const caps=await context.request.get(base+'/api/v1/v3/capabilities',{headers});const c=await caps.json();check(caps.status()===200&&c.data.operasional_tersedia===false,`${user} API capability tanpa fitur operasional`);
  const cat=await context.request.get(base+'/api/v1/v3/katalog',{headers});check(cat.status()===(['sbx_guru_biasa','sbx_ortu_a'].includes(user)?403:200),`${user} otorisasi katalog API`);
  const threshold=await context.request.get(base+'/api/v1/v3/ambang?tahun_ajaran_id='+year,{headers});check(threshold.status()===(['sbx_guru_biasa','sbx_ortu_a'].includes(user)?403:200),`${user} otorisasi ambang API`);
  const mutation=await context.request.post(base+'/api/v1/v3/pelanggaran',{headers,data:{}});check(mutation.status()===404,`${user} endpoint fase 2 tidak ada`);
  const profile=await (await context.request.get(base+'/api/v1/profile',{headers})).json();check(profile.data.capabilities!==undefined&&profile.data.roles!==undefined,`${user} field profil lama tersedia`);
 }
 for(const user of ['sbx_pengurus_a','sbx_murobi_a','sbx_ortu_a']){
  await context.clearCookies();await login(page,user);const response=await page.goto(base+path);check(response.status()===403,`${user} halaman admin ditolak`);
 }
 check(!xss,'Tidak ada eksekusi XSS');
}finally{writeFileSync(`${out}/results.json`,JSON.stringify(checks,null,2));await browser.close();}
if(checks.some(c=>!c.ok))process.exitCode=1;
