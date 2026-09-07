import {launchAudit, base} from './audit-runtime.mjs';
import {mkdirSync,writeFileSync} from 'node:fs';
const out=process.env.OUT_DIR??'/tmp/codex-penugasan-audit/browser';
mkdirSync(out,{recursive:true});
const {browser,page}=await launchAudit();
const checks=[];const errors=[];
const check=(ok,name,details='')=>{checks.push({ok,name,details});console.log(`${ok?'[lulus]':'[gagal]'} ${name} ${details}`);};
page.on('pageerror',e=>errors.push(e.message));
page.on('dialog',d=>d.accept());
try{
 await page.goto(base+'/portal/index.php');
 await page.locator('#username').fill('sbx_admin');
 await page.locator('#password').fill('Sandbox#123');
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Masuk',exact:true}).click()]);
 const mapelName='Audit Browser '+Date.now();
 await page.goto(`${base}/admin/admin_penugasan.php?jenis=mata_pelajaran`);
 await page.locator('#nama').fill(mapelName);
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Simpan',exact:true}).click()]);
 for(const width of [1440,768,390]){
  await page.setViewportSize({width,height:900});
  for(const jenis of ['murobi','pembimbing','guru_mapel','pendidikan','bendahara_bulanan','panitia_psb','bendahara_psb','mata_pelajaran']){
   const response=await page.goto(`${base}/admin/admin_penugasan.php?jenis=${jenis}`);
   check(response.status()===200,`${width} ${jenis}: HTTP 200`);
   const layout=await page.evaluate(()=>({body:document.documentElement.scrollWidth<=innerWidth+1,form:[...document.querySelectorAll('form.row')].every(e=>e.scrollWidth<=e.clientWidth+1),labels:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(e=>!e.labels?.length&&!e.getAttribute('aria-label')).map(e=>e.id||e.name)}));
   check(layout.body&&layout.form,`${width} ${jenis}: body/form tanpa overflow`,JSON.stringify(layout));
   check(layout.labels.length===0,`${width} ${jenis}: label kontrol`);
   await page.screenshot({path:`${out}/${width}-${jenis}.png`,fullPage:true});
  }
 }
 await page.goto(`${base}/admin/admin_penugasan.php?jenis=murobi`);
 await page.locator('#guru_id').focus();
 await page.keyboard.press('Tab');
 check(await page.locator('#tahun_ajaran_id').evaluate(e=>e===document.activeElement),'Keyboard: Tab guru ke tahun ajaran');
 const form=page.locator('form').filter({has:page.locator('input[name=action][value=buat]')});
 await page.locator('#guru_id').selectOption({index:1});
 await page.locator('#kamar_id').selectOption({index:1});
 await page.locator('#tanggal_mulai').fill('2026-09-07');
 await page.locator('#tanggal_selesai').fill('2026-09-06');
 await Promise.all([page.waitForURL('**/admin_penugasan.php?jenis=murobi'),form.getByRole('button',{name:'Simpan penugasan',exact:true}).click()]);
 check((await page.locator('body').innerText()).includes('Tanggal selesai tidak boleh mendahului'), 'Kesalahan rentang tanggal terbaca');
 check(await page.locator('#tanggal_selesai').inputValue()==='2026-09-06','Isian setelah gagal tetap tersedia');
 await page.screenshot({path:`out/error.png`.replace('out/',out+'/'),fullPage:true});
 const edit=page.getByRole('link',{name:'Ubah',exact:true}).first();
 await edit.click();
 check(await page.locator('#guru_id').count()===1 && await page.locator('#tahun_ajaran_id').count()===1,'Mode ubah: label orang/tahun terhubung');
 const summary=page.locator('summary').first();
 await summary.focus();await page.keyboard.press('Enter');
 check(await summary.evaluate(e=>e.parentElement.open),'Keyboard: Enter membuka tindakan');
 await page.goto(`${base}/admin/admin_penugasan.php?jenis=mata_pelajaran`);
 await Promise.all([page.waitForNavigation(),page.locator('tr').filter({hasText:mapelName}).getByRole('button',{name:'Arsipkan',exact:true}).click()]);
 check(errors.length===0,'Tidak ada galat JavaScript',JSON.stringify(errors));
}finally{writeFileSync(`${out}/results.json`,JSON.stringify(checks,null,2));await browser.close();}
if(checks.some(x=>!x.ok))process.exitCode=1;
