import {launchAudit,base,login} from './audit-runtime.mjs';
import {mkdirSync,writeFileSync} from 'node:fs';
const {browser,page,context}=await launchAudit();const checks=[];const check=(ok,name)=>{checks.push({ok:Boolean(ok),name});console.log(`${ok?'[lulus]':'[gagal]'} ${name}`);};const out=process.env.OUT_DIR??'/tmp/v3-phase4-browser';mkdirSync(out,{recursive:true});
const key=()=>`f4-web-${crypto.randomUUID()}`;
async function auth(username){const r=await context.request.post(base+'/api/v1/auth/login',{data:{username,password:'Sandbox#123'}});return {Authorization:'Bearer '+(await r.json()).data.token};}
async function post(path,headers,data){return context.request.post(base+'/api/v1'+path,{headers:{...headers,'Idempotency-Key':key()},data});}
try{
 const a=await auth('sbx_pengurus_a'),pa=await auth('sbx_ortu_a'),pb=await auth('sbx_ortu_b');
 check((await context.request.get(base+'/api/v1/v3/publikasi')).status()===401,'Tanpa token ditolak');
 const opt=(await (await context.request.get(base+'/api/v1/v3/konseling/options',{headers:a})).json()).data.santri[0];
 const cr=await post('/v3/konseling/kasus',a,{santri_id:opt.santri_id,tahun_ajaran_id:opt.tahun_ajaran_id,kerahasiaan:'Rahasia',tujuan:'PRIVATE-F4-BROWSER'});const cid=(await cr.json()).data.kasus.id;
 const input={sumber_type:'kasus',sumber_id:cid,sumber_version:1,ringkasan:'Ringkasan <script>window.F4_XSS=1</script>',tindak_lanjut:'Tindak lanjut khusus keluarga'};
 check((await post('/v3/publikasi/pratinjau',a,input)).status()===422,'API Rahasia menolak pratinjau manual');
 check((await context.request.get(base+`/api/v1/v3/konseling/kasus/${cid}`,{headers:pa})).status()===403,'Orang tua ditolak API internal');
 const revise=await context.request.patch(base+`/api/v1/v3/konseling/kasus/${cid}`,{headers:{...a,'Idempotency-Key':key()},data:{version:1,kerahasiaan:'Internal',alasan:'Revisi kerahasiaan untuk keluarga'}});check(revise.status()===200,'API revisi sah menjadi Internal');input.sumber_version=2;
 const pr=await post('/v3/publikasi/pratinjau',a,input);const preview=(await pr.json()).data;check(pr.status()===200,'API pratinjau Internal');
 const pi={pratinjau_token:preview.pratinjau_token,konfirmasi:true,idempotency_key:key()};check((await post('/v3/publikasi/terbit',a,{...pi,konfirmasi:false})).status()===422,'API konfirmasi wajib');
 const published=await post('/v3/publikasi/terbit',a,pi);const pub=(await published.json()).data;const id=pub.publikasi_ids[0];check(published.status()===201,'API publikasi berhasil');
 check((await post('/v3/publikasi/terbit',a,pi)).status()===200,'API retry idempoten');
 const dr=await context.request.get(base+`/api/v1/v3/publikasi/${id}`,{headers:pa});const detail=(await dr.json()).data;check(dr.status()===200&&JSON.stringify(preview.konten)===JSON.stringify(Object.fromEntries(Object.entries(detail.publikasi).filter(([k])=>['santri_id','ringkasan','tindak_lanjut'].includes(k)))),'Respons orang tua persis konten pratinjau');
 check(!JSON.stringify(detail).includes('PRIVATE-F4'),'Respons tanpa isi internal');
 check((await context.request.get(base+`/api/v1/v3/publikasi/${id}`,{headers:pb})).status()===403,'IDOR API wali B ditolak');
 check((await context.request.get(base+`/api/v1/v3/publikasi/${id}/dibaca`,{headers:pa})).status()===404,'GET tidak menandai dibaca');
 check((await context.request.get(base+`/api/v1/v3/publikasi/${id}/tarik`,{headers:a})).status()===404,'GET tidak menarik publikasi');
 const blocked=await context.request.patch(base+`/api/v1/v3/konseling/kasus/${cid}`,{headers:{...a,'Idempotency-Key':key()},data:{version:2,kerahasiaan:'Rahasia',alasan:'Uji invariant lewat API'}});check(blocked.status()===409,'API menolak Internal ke Rahasia saat aktif');
 await login(page,'sbx_pengurus_a');
 const url=base+`/portal/v3_publikasi_kelola.php?sumber_type=kasus&sumber_id=${cid}`;
 for(const width of [1440,375]){await page.setViewportSize({width,height:900});const r=await page.goto(url);check(r.status()===200,`${width}px formulir kelola terbuka`);check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),`${width}px tanpa scroll horizontal`);}
 check((await context.request.post(url,{form:{aksi:'pratinjau'}})).status()===419,'CSRF web wajib');
 await page.getByLabel('Ringkasan khusus orang tua').fill('Ringkasan browser <script>window.F4_XSS=1</script>');await page.getByLabel('Tindak lanjut khusus orang tua').fill('Tindak lanjut browser');
 await page.getByRole('button',{name:'Lihat pratinjau',exact:true}).click();const previewText=await page.locator('.v3-publikasi-konten').innerText();check(!await page.evaluate(()=>Boolean(window.F4_XSS)),'Pratinjau meng-escape XSS');
 await page.getByLabel('Saya sudah memeriksa').check();await page.getByRole('button',{name:'Konfirmasi dan terbitkan',exact:true}).click();const webId=new URL(page.url()).searchParams.get('id');check(Boolean(webId),'Web pratinjau-konfirmasi-terbit berhasil');
 await context.clearCookies();await login(page,'sbx_ortu_a');
 check((await page.goto(base+`/portal/v3_konseling_detail.php?id=${cid}`)).status()===403,'Orang tua ditolak halaman internal');
 check((await page.goto(base+`/portal/v3_konseling_cetak.php?id=${cid}`)).status()===403,'Orang tua ditolak cetak internal');
 check((await page.goto(base+`/portal/v3_publikasi_kelola.php?id=${id}`)).status()===403,'Orang tua ditolak halaman kelola');
 await page.goto(base+`/portal/v3_publikasi.php?id=${webId}`);check(await page.locator('.v3-publikasi-konten').innerText()===previewText,'Konten HTML orang tua persis HTML pratinjau');check(!await page.evaluate(()=>Boolean(window.F4_XSS)),'Detail orang tua meng-escape XSS');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Detail orang tua 375px tanpa luapan');await page.screenshot({path:`${out}/parent-375.png`,fullPage:true});
 await page.getByRole('button',{name:'Tandai dibaca'}).click();check((await page.locator('body').innerText()).includes('Dibaca'),'Status dibaca web tersimpan');
 await context.clearCookies();const unauth=await page.goto(base+`/portal/v3_publikasi.php?id=${id}`);check((await page.locator('#username').count())===1&&new URL(page.url()).searchParams.get('next')===`/portal/v3_publikasi.php?id=${id}`,'Deep-link web meminta login');check(!(await page.locator('body').innerText()).includes(input.ringkasan),'Sebelum login tidak membuka snapshot');
 await page.locator('#username').fill('sbx_ortu_b');await page.locator('#password').fill('Sandbox#123');await page.getByRole('button',{name:'Masuk',exact:true}).click();check(page.url().includes(`/portal/v3_publikasi.php?id=${id}`)&&(await page.locator('body').innerText()).includes('Publikasi tidak dapat diakses'),'Deep-link dipulihkan sesudah login dan wali B ditolak');
 check((await post(`/v3/publikasi/${id}/tarik`,a,{version:1,alasan:''})).status()===422,'API penarikan tanpa alasan ditolak');
 check((await post(`/v3/publikasi/${id}/tarik`,a,{version:1,alasan:'Penarikan uji browser'})).status()===200,'API penarikan beralasan');
 const withdrawn=(await (await context.request.get(base+`/api/v1/v3/publikasi/${id}`,{headers:pa})).json()).data;check(withdrawn.publikasi.status==='Ditarik'&&!JSON.stringify(withdrawn).includes(input.ringkasan),'Penarikan menyembunyikan isi lama dari orang tua');
}finally{writeFileSync(out+'/results.json',JSON.stringify(checks,null,2));await browser.close();}
if(checks.some(c=>!c.ok))process.exitCode=1;
