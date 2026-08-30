// K6 inventory audit: each route/mode, real CSS, axe, keyboard, reduced motion.
import{launchAudit,login,base}from'./audit-runtime.mjs';
import{readFileSync,writeFileSync,mkdirSync}from'node:fs';
const out=process.env.OUT_DIR??'/tmp/webalhasan-audit-14/ui';mkdirSync(out,{recursive:true});
const axe=process.env.AXE_PATH;if(!axe)throw Error('AXE_PATH required; no skipped accessibility assertions');
const fixture=JSON.parse(readFileSync(process.env.AUDIT_FIXTURE_MANIFEST,'utf8'));
const A=['/portal/index.php','/portal/izin_ringkasan.php','/admin/admin_akun.php','/admin/admin_master_santri.php','/admin/admin_master_santri.php?action=create','/admin/admin_wali.php','/admin/admin_wali.php?action=create','/admin/admin_wali_rekonsiliasi.php','/admin/admin_guru.php','/admin/admin_guru.php?action=create','/admin/admin_murobi.php','/admin/admin_kamar.php','/admin/admin_kamar.php?action=create','/admin/admin_pengajian.php?tab=jadwal','/admin/admin_pengajian.php?tab=jadwal&action=create','/admin/admin_pengajian.php?tab=pertemuan','/admin/admin_laporan_absensi.php','/admin/ubah_password.php','/admin/logout.php'];
const B=['/admin/admin_pengurus.php','/admin/admin_pengurus.php?action=create','/admin/admin_kelas.php','/admin/admin_kelas.php?action=create','/admin/admin_tahun.php','/admin/admin_tahun.php?action=create','/admin/admin_pembimbing.php','/admin/laporan_absensi_detail.php?id='+fixture.meeting,'/portal/izin.php','/portal/izin_buat.php','/portal/izin_antrean.php','/portal/laporan.php','/portal/notifikasi.php'];
const uiFixture=JSON.parse(readFileSync(process.env.AUDIT_UI_MANIFEST,'utf8'));
A.push('/admin/admin_wali.php?action=edit&id='+uiFixture.wali[0],'/admin/admin_wali.php?action=detail&id='+uiFixture.wali[0],'/admin/admin_wali_rekonsiliasi.php?q='+uiFixture.tag);
B.push(...[56,81,1,36,93].map(id=>'/portal/izin_detail.php?id='+id));
const D=['admin_dashboard','admin_data','admin_santri','admin_rekap_santri','admin_alumni','admin_pembayaran_psb','admin_rekap_keuangan','admin_berita','admin_galeri','admin_download','admin_pelanggaran','admin_notifikasi','admin_izin'].map(x=>'/admin/'+x+'.php');
const{browser,context,page}=await launchAudit();let results=[],errors=[];page.on('pageerror',e=>errors.push({path:page.url(),message:e.message}));
try{
 await login(page);
 for(const width of [1440,768,390]){
  await page.setViewportSize({width,height:900});
  for(const[group,routes]of[['A',A],['B',B],['D',D]])for(const path of routes){
   const response=await page.goto(base+path);await page.locator('body').waitFor();
   await page.addScriptTag({path:axe});
   const accessibility=await page.evaluate(async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}});return{violations:r.violations.map(v=>({id:v.id,impact:v.impact,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))})),incomplete:r.incomplete.map(v=>({id:v.id,count:v.nodes.length}))};});
   const dom=await page.evaluate(()=>({title:document.title,h1:[...document.querySelectorAll('h1')].map(x=>x.textContent.trim()),overflow:document.documentElement.scrollWidth>innerWidth+1,bodyAH:document.body.classList.contains('ah'),breadcrumbs:!!document.querySelector('[aria-label="Jalur halaman"]'),activeMenu:document.querySelectorAll('#ah-sidebar [aria-current=page]').length,unlabelled:[...document.querySelectorAll('input:not([type=hidden]),select,textarea')].filter(x=>!x.labels?.length&&!x.getAttribute('aria-label')&&!x.getAttribute('aria-labelledby')).map(x=>x.name),iconOnly:[...document.querySelectorAll('button,a')].filter(x=>x.getClientRects().length&&!x.innerText.trim()&&!x.getAttribute('aria-label')&&!x.getAttribute('aria-labelledby')&&!x.querySelector('img[alt]')).map(x=>x.outerHTML.slice(0,250))}));
   let legacyWithoutCss=null;
   if(group==='D'){
    legacyWithoutCss=await page.evaluate(()=>{const sheet=[...document.querySelectorAll('link[rel=stylesheet]')].find(x=>x.href.includes('/assets/ui/alhasan.css'));if(sheet)sheet.disabled=true;return{overflow:document.documentElement.scrollWidth>innerWidth+1,width:document.documentElement.scrollWidth,loadedShared:!!sheet};});
   }
   results.push({group,path,width,status:response.status(),...dom,...accessibility,legacyWithoutCss});
   console.log(`${group} ${width} ${path} HTTP${response.status()} overflow=${dom.overflow} axe=${accessibility.violations.map(v=>v.id).join(',')}`);
  }
 }
}finally{writeFileSync(out+'/results.json',JSON.stringify({results,errors},null,2));await browser.close();}

let passed=0,failed=0;
for(const r of results){
 const standalone=['/admin/ubah_password.php','/admin/logout.php'].includes(r.path);
 const ok=r.status===200&&(r.group==='D'
  ? (!r.overflow||r.legacyWithoutCss.overflow)
  : !r.overflow&&r.h1.length===1&&(standalone||(r.breadcrumbs&&r.activeMenu===1))&&r.unlabelled.length===0&&r.iconOnly.length===0&&r.violations.length===0);
 ok?passed++:failed++;console.log((ok?'[lulus] ':'[gagal] ')+(r.group==='D'?'dampak CSS (bukan kelulusan aksesibilitas legacy) ':'navigasi/label/overflow/axe ')+r.width+' '+r.path);
}
if(errors.length)failed++;else passed++;console.log((errors.length?'[gagal] ':'[lulus] ')+'tidak ada kesalahan JavaScript pada seluruh rute');
console.log(`Total inventaris UI: ${passed} lulus, ${failed} gagal; ${results.length} observasi`);process.exitCode=failed?1:0;
