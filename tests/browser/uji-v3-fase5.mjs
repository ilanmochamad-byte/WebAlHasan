import {launchAudit,base,login} from './audit-runtime.mjs';
import {mkdirSync,writeFileSync} from 'node:fs';
const {browser,page,context}=await launchAudit();const checks=[];
const check=(ok,name)=>{checks.push({ok:Boolean(ok),name});console.log(`${ok?'[lulus]':'[gagal]'} ${name}`);};
const out=process.env.OUT_DIR??'/tmp/v3-phase5-browser';mkdirSync(out,{recursive:true});
try{
 const loginApi=async username=>{const r=await context.request.post(base+'/api/v1/auth/login',{data:{username,password:'Sandbox#123'}});return {Authorization:'Bearer '+(await r.json()).data.token};};
 const a=await loginApi('sbx_pengurus_a'),b=await loginApi('sbx_pengurus_b'),pa=await loginApi('sbx_ortu_a');
 check((await context.request.get(base+'/api/v1/v3/mobile/options',{headers:pa})).status()===403,'Baseline options menolak wali lewat HTTP');
 check((await context.request.get(base+'/api/v1/v3/laporan')).status()===401,'Laporan API memerlukan autentikasi');
 const options=(await (await context.request.get(base+'/api/v1/v3/mobile/options',{headers:a})).json()).data;
 check(options.santri.length<=25&&options.katalog.length<=25,'Options mobile berhalaman lewat HTTP');
 const sid=options.santri[0].santri_id;
 const denied=(await (await context.request.get(base+`/api/v1/v3/laporan?jenis=kasus&santri_id=${sid}`,{headers:b})).json()).data;
 check(denied.total===0,'Laporan API lintas pembimbing kosong');
 for(const user of ['sbx_admin','sbx_pengurus_a','sbx_murobi_a','sbx_ortu_a']){
  await context.clearCookies();await login(page,user);
  for(const width of [1440,375]){
   await page.setViewportSize({width,height:900});const r=await page.goto(base+'/portal/v3_laporan.php');
   check(r.status()===200,`${user} HTML ${width}px terbuka`);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${user} ${width}px tanpa luapan`);
   check(!await page.evaluate(()=>Boolean(window.F5_XSS)),`${user} HTML XSS tidak berjalan`);
   const csv=await context.request.get(base+'/portal/v3_laporan.php?format=csv');const text=await csv.text();
   check(csv.status()===200&&!text.includes('PRIVATE-F5')&&!text.includes('ringkasan_internal'),`${user} CSV sesuai proyeksi tanpa isi rahasia`);
   const cr=await page.goto(base+'/portal/v3_laporan.php?format=cetak');check(cr.status()===200&&!await page.evaluate(()=>Boolean(window.F5_XSS)),`${user} cetak escape XSS`);
   const pdf=await page.pdf({path:`${out}/${user}-${width}.pdf`,format:'A4',landscape:true});check(pdf.length>1000,`${user} PDF benar-benar dihasilkan Chromium`);
  }
 }
 await context.clearCookies();await login(page,'sbx_ortu_a');
 check((await page.goto(base+'/portal/v3_laporan.php?jenis=kasus')).status()===403,'Wali menebak jenis internal ditolak web');
 const csv=await context.request.get(base+'/portal/v3_laporan.php?format=csv');
 check((await csv.text()).includes("'=F5 formula"),'CSV keluarga menetralkan formula nyata dari snapshot');
 const p=await page.goto(base+'/portal/v3_laporan.php?jenis=publikasi');
 check(p.status()===200&&(await page.locator('body').innerText()).includes('<script>window.F5_XSS=1</script>')&&!await page.evaluate(()=>Boolean(window.F5_XSS)),'HTML keluarga menampilkan teks XSS secara aman');
 await page.screenshot({path:out+'/parent-375.png',fullPage:true});
}finally{writeFileSync(out+'/results.json',JSON.stringify(checks,null,2));await browser.close();}
if(checks.some(c=>!c.ok))process.exitCode=1;
