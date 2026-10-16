import fs from 'node:fs';
import crypto from 'node:crypto';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const base=process.env.V4_TEST_URL;
if(!/^http:\/\/127\.0\.0\.1:/.test(base))throw Error('Hanya localhost');
const ids=JSON.parse(fs.readFileSync('/private/tmp/v4-fase4-ids.json'));
const tokens=JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS));
const code=fs.readFileSync('/private/tmp/v4-fase4-code.txt','utf8').trim();
const ok=(v,s)=>{if(!v)throw Error(s);console.log('[lulus] '+s);};
const api=async(user,path,body)=>{const r=await fetch(base+'/api/v1/v4/'+path,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+tokens[user],'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return {status:r.status,headers:r.headers,raw:r,data:r.headers.get('content-type')?.includes('application/json')?(await r.json()).data:null};};
const rid=ids.rapor,query=`rencana_id=${ids.rencana}&santri_id=${ids.santri}`;
for(const user of ['v4_wali4a','v4_guru','v4_pengurus']){
 ok((await api(user,'rapor/pratinjau?'+query)).status===403,'Pratinjau tanpa penugasan '+user+' 403');
 ok((await api(user,'rapor',{action:'Terbit',rencana_id:ids.rencana,santri_id:ids.santri})).status===403,'Penerbitan tanpa penugasan '+user+' 403');
}
try{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase3_assignment.php','v4_pendidikan','0']);ok((await api('v4_pendidikan','rapor/pratinjau?'+query)).status===403,'Pratinjau Pendidikan dicabut 403');ok((await api('v4_pendidikan','rapor',{action:'Ganti',rencana_id:ids.rencana,santri_id:ids.santri})).status===403,'Penerbitan Pendidikan dicabut 403');}finally{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase3_assignment.php','v4_pendidikan','1']);}
const detail=await api('v4_wali4a','rapor/'+rid);
ok(detail.status===200&&detail.headers.get('cache-control').includes('no-store'),'API detail wali privat');
ok((await api('v4_wali4lain','rapor')).data.length===0,'API daftar wali lain kosong');
for(const user of ['v4_wali4lain','v4_guru','v4_pengurus'])for(const path of [`rapor/${rid}`,`rapor/${rid}/pdf/1`,`rapor/${rid}/pdf/1?petugas=1`])ok((await api(user,path)).status===403,'API akses silang '+user+' '+path+' 403');
for(const path of [`rapor/${rid}`,`rapor/${rid}/pdf/1`])ok((await fetch(base+'/api/v1/v4/'+path)).status===401,'API tanpa bearer ditolak');
const hash=async()=>{const r=await fetch(base+`/api/v1/v4/rapor/${rid}/pdf/1`,{headers:{Authorization:'Bearer '+tokens.v4_wali4a}});ok(r.headers.get('cache-control').includes('no-store'),'API PDF privat');return crypto.createHash('sha256').update(Buffer.from(await r.arrayBuffer())).digest('hex');};
ok(await hash()===await hash()&&await hash()===ids.hash_v1,'K3 HTTP PDF berulang identik');
try{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','relation','0']);for(const path of [`rapor/${rid}`,`rapor/${rid}/pdf/1`])ok((await api('v4_wali4a',path)).status===403,'K5 API pencabutan relasi langsung efektif');}finally{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','relation','1']);}
const browser=await chromium.launch({headless:true});
const login=async(user)=>{const page=await browser.newPage({viewport:{width:375,height:812}});await page.goto(base+'/portal/index.php');await page.locator('[name=username]').fill(user);await page.locator('[name=password]').fill(process.env.V4_TEST_PASSWORD);await page.locator('button[type=submit]').first().click();return page;};
const layout=async(page,label)=>{await page.waitForTimeout(300);ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' 375 px tanpa overflow');await page.evaluate(()=>document.documentElement.style.fontSize='24px');ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' teks 24 px tanpa overflow');await page.evaluate(()=>document.documentElement.style.fontSize='');await page.setViewportSize({width:1440,height:1000});ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' desktop tanpa overflow');await page.screenshot({path:`docs/phase-v4-4/${label}-desktop.png`,fullPage:true});await page.setViewportSize({width:375,height:812});await page.waitForTimeout(350);await page.screenshot({path:`docs/phase-v4-4/${label}-375.png`,fullPage:true});};
try{
 const wali=await login('v4_wali4a');await wali.goto(base+'/portal/rapor_wali.php?id='+rid);ok((await wali.locator('body').textContent()).includes('Rapor Semester Al Hasan')&&!await wali.evaluate(()=>window.v4xss),'Web wali snapshot terbit aman XSS');await layout(wali,'wali');
 const wrong=await login('v4_wali4lain');for(const path of [`/portal/rapor_wali.php?id=${rid}`,`/portal/rapor_pdf.php?id=${rid}&version=1`])ok((await wrong.goto(base+path)).status()===403,'Web wali lain detail/PDF 403');
 try{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','relation','0']);for(const path of [`/portal/rapor_wali.php?id=${rid}`,`/portal/rapor_pdf.php?id=${rid}&version=1`])ok((await wali.goto(base+path)).status()===403,'Web relasi dicabut detail/PDF 403');}finally{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','relation','1']);}
 const anon=await browser.newPage();for(const path of [`/portal/rapor_wali.php?id=${rid}`,`/portal/rapor_pdf.php?id=${rid}&version=1`]){await anon.goto(base+path);ok(anon.url().includes('/portal/index.php'),'Web tanpa akun wajib login detail/PDF');}
 const pe=await login('v4_pendidikan');await pe.goto(base+'/portal/rapor_semester.php?'+query);ok(await pe.getByRole('button',{name:'Terbitkan versi pengganti',exact:true}).count()===1,'Web Pendidikan pratinjau dan penggantian');await layout(pe,'pendidikan');
 const bad=await pe.request.post(base+'/portal/rapor_semester.php',{form:{rencana_id:String(ids.rencana),santri_id:String(ids.santri),action:'Ganti',_csrf:'salah'}});ok(bad.status()===419,'Web publikasi CSRF 419');
 const preview=(await api('v4_pendidikan','rapor/pratinjau?'+query)).data;
 const stale=await api('v4_pendidikan','rapor',{action:'Ganti',rencana_id:ids.rencana,santri_id:ids.santri,current_version:preview.current_version,revision:preview.revision,pratinjau_hash:'usang',idempotency_key:crypto.randomBytes(20).toString('hex'),alasan:'Uji usang'});ok(stale.status===409,'API pratinjau usang 409');
 // Public verification never sends codes in request URL.
 const pub=await browser.newPage({viewport:{width:375,height:812}});await pub.goto(base+'/verifikasi_rapor.php#'+code);ok(await pub.locator('#kode').inputValue()===code&&!pub.url().includes('#'),'QR fragment dipindahkan ke form dan dihapus dari URL');
 await pub.getByRole('button',{name:'Periksa',exact:true}).click();let txt=await pub.locator('[role=status]').textContent();ok(txt.includes('Terbit')&&txt.includes('Versi 1')&&!txt.includes('Sintetis'),'K4 kode sah hanya status/versi/tanggal');
 await layout(pub,'verifikasi');
 for(const [file,expected] of [['/private/tmp/v4-fase4-pdf.pdf','PDF cocok'],['/private/tmp/v4-fase4-changed.pdf','PDF tidak cocok']]){await pub.locator('#kode').fill(code);await pub.locator('[name=pdf]').setInputFiles(file);await pub.getByRole('button',{name:'Periksa',exact:true}).click();ok((await pub.locator('[role=status]').textContent()).includes(expected),'K3 '+expected+' melalui unggahan HTTP');}
 await pub.locator('#kode').fill(code);await pub.locator('[name=pdf]').setInputFiles({name:'bad.pdf',mimeType:'application/pdf',buffer:Buffer.from('bukan PDF')});const invalid=await Promise.all([pub.waitForResponse(r=>r.url().endsWith('verifikasi_rapor.php')&&r.request().method()==='POST'),pub.getByRole('button',{name:'Periksa',exact:true}).click()]);ok(invalid[0].status()===422,'Unggahan non-PDF ditolak 422');
 await pub.locator('#kode').fill(code);await pub.locator('[name=pdf]').setInputFiles({name:'too-big.pdf',mimeType:'application/pdf',buffer:Buffer.concat([Buffer.from('%PDF-'),Buffer.alloc(10485760)])});const oversized=await Promise.all([pub.waitForResponse(r=>r.url().endsWith('verifikasi_rapor.php')&&r.request().method()==='POST'),pub.getByRole('button',{name:'Periksa',exact:true}).click()]);ok(oversized[0].status()===422,'Unggahan melebihi 10 MB ditolak 422');
 await pub.goto(base+'/verifikasi_rapor.php');const csrf=await pub.locator('[name=_csrf]').inputValue();let limited=false,attempts=0;
 for(let i=0;i<21;i++){const r=await pub.request.post(base+'/verifikasi_rapor.php',{form:{_csrf:csrf,kode:crypto.randomBytes(24).toString('hex')}});attempts++;const body=await r.text();ok(!body.includes('Santri Sintetis')&&!body.includes('Penanda Sintetis')&&!body.includes('nilai_internal'),'K4 kode acak tanpa data pribadi');if(r.status()===429){ok(r.headers()['retry-after']==='60','K4 rate limit 429 dan Retry-After');limited=true;break;}ok(r.status()===404,'Kode acak 404');}
 ok(limited&&attempts<=21,'K4 batas 20 pemeriksaan per IP/menit tidak dapat dilewati kode berbeda');
 const admin=await login('admin');await admin.goto(base+'/portal/rapor_penanda.php?rencana_id='+ids.rencana);await layout(admin,'penanda');
 ok((await pe.goto(base+'/portal/rapor_penanda.php?rencana_id='+ids.rencana)).status()===403,'Web penanda khusus admin');
 await pe.goto(base+'/portal/rapor_semester.php?'+query);await pe.locator('form').last().locator('[name=alasan]').fill('Penggantian uji melalui formulir web');await pe.getByRole('button',{name:'Terbitkan versi pengganti',exact:true}).click();ok((await pe.locator('body').textContent()).includes('Versi saat ini 2'),'Web mengonfirmasi versi pengganti 2');
 const summary=JSON.parse(execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','summary'],{encoding:'utf8'}));ok(summary.pdfs===2&&summary.inapp===4,'K2 penggantian web tepat satu versi baru dan InApp per wali');
 ok(execFileSync('/opt/homebrew/bin/php',['tests/v4_phase4_mutate.php','hash'],{encoding:'utf8'}).trim()===ids.hash_v1,'K7 hash PDF lama tidak berubah setelah penggantian web');
}finally{await browser.close();}
