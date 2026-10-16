import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const base=process.env.V4_TEST_URL;
if(!/^http:\/\/127\.0\.0\.1:/.test(base))throw Error('Hanya server uji localhost');
const tokens=JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS,'utf8'));
const ids=JSON.parse(fs.readFileSync('/private/tmp/v4-fase3-ids.json','utf8'));
const ok=(v,s)=>{if(!v)throw Error(s);console.log('[lulus] '+s);};
const api=async(user,path,body)=>{const r=await fetch(base+'/api/v1/v4/'+path,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+tokens[user],'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return {status:r.status,data:(await r.json()).data,headers:r.headers};};
const query=`rencana_id=${ids.rencana}&santri_id=${ids.santri}`;
const read=()=>api('v4_pendidikan','verifikasi?'+query);
const material=async(kind,key=0)=>(await read()).data.matriks[0].bahan.find(m=>m.jenis===kind&&m.mapel_key===key);
const act=async(kind,action,alasan='',key=0)=>{const m=await material(kind,key);return api('v4_pendidikan','verifikasi',{rencana_id:ids.rencana,santri_id:ids.santri,jenis:kind,mapel_key:key,action,alasan,...m.pemeriksaan});};
const initial=await api('v4_pembimbing','sikap?'+query);
ok(initial.status===200&&['Baik','Sangat Baik'].includes(initial.data.sikap.adab)&&initial.headers.get('cache-control').includes('no-store'),'API pembimbing membaca sikap privat');
for(const [user,mode] of [['v4_pembimbing_lain','pembimbing'],['v4_murobi_lain','murobi'],['v4_wali','pembimbing'],['v4_guru','pembimbing']]){
 ok((await api(user,`sikap?${query}&mode=${mode}`)).status===403,'API baca di luar cakupan '+user+' 403');
 ok((await api(user,mode==='murobi'?'sikap/murobi':'sikap',{rencana_id:ids.rencana,santri_id:ids.santri,version:0,action:'diketahui'})).status===403,'API mutasi di luar cakupan '+user+' 403');
}
ok((await fetch(base+'/api/v1/v4/sikap?'+query)).status===401,'API tanpa bearer 401');
for(const plan of ids.rencana_luar)ok((await api('v4_pendidikan',`verifikasi?rencana_id=${plan}`)).status===403,'API Pendidikan di luar kelas/semester 403');
for(const [user,path,body] of [['v4_pembimbing','sikap',{rencana_id:ids.rencana,santri_id:ids.santri,version:0}],['v4_murobi','sikap/murobi',{rencana_id:ids.rencana,santri_id:ids.santri,version:0}],['v4_pendidikan','verifikasi',{rencana_id:ids.rencana,santri_id:ids.santri,action:'verifikasi'}]]){try{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase3_assignment.php',user,'0']);ok((await api(user,(path==='sikap/murobi'?'sikap?mode=murobi&':'sikap'===path?'sikap?':'verifikasi?')+query)).status===403,'API penugasan dicabut baca '+user);ok((await api(user,path,body)).status===403,'API penugasan dicabut tulis '+user);}finally{execFileSync('/opt/homebrew/bin/php',['tests/v4_phase3_assignment.php',user,'1']);}}
for(const user of ['v4_pembimbing','v4_murobi','v4_pengurus','v4_wali'])ok((await api(user,'verifikasi?'+query)).status===403,'API matriks '+user+' 403');
const old=initial.data.sikap;
let payload={...old,rencana_id:ids.rencana,action:'koreksi',alasan:'Uji API',catatan:'Catatan API <script>window.v4xss=1</script>'};
ok((await api('v4_pembimbing','sikap',{...payload,alasan:''})).status===422,'API koreksi tanpa alasan 422');
let saved=await api('v4_pembimbing','sikap',payload);ok(saved.status===200,'API pembimbing koreksi beralasan');
ok((await api('v4_pembimbing','sikap',payload)).status===409,'API retry versi lama 409');
let after=await read();ok(after.data.matriks[0].bahan.find(m=>m.jenis==='sikap').pemeriksaan.status==='Draf','API koreksi membatalkan verifikasi');
ok((await api('v4_pendidikan','verifikasi',{rencana_id:ids.rencana,santri_id:ids.santri,action:'periksa_finalisasi',matriks_hash:'usang'})).status===409,'API gate matriks usang 409');
const browser=await chromium.launch({headless:true});
const login=async(user)=>{const page=await browser.newPage({viewport:{width:375,height:812}});await page.goto(base+'/portal/index.php');await page.locator('[name=username]').fill(user);await page.locator('[name=password]').fill(process.env.V4_TEST_PASSWORD);await page.locator('button[type=submit]').first().click();return page;};
const layout=async(page,label)=>{ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' 375 px tanpa overflow');await page.evaluate(()=>document.documentElement.style.fontSize='24px');ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' teks 24 px tanpa overflow');await page.evaluate(()=>document.documentElement.style.fontSize='');await page.setViewportSize({width:1440,height:1000});ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),label+' desktop tanpa overflow');await page.setViewportSize({width:375,height:812});await page.waitForFunction(()=>document.getElementById('ah-sidebar').getBoundingClientRect().right<=1);};
try{
 const pemb=await login('v4_pembimbing');await pemb.goto(base+'/portal/sikap_semester.php?'+query);
 ok(await pemb.locator('#sikap-form').count()===1&&!await pemb.evaluate(()=>window.v4xss),'Web pembimbing formulir aman dari XSS');
  const bad=await pemb.request.post(base+'/portal/sikap_semester.php',{form:{rencana_id:String(ids.rencana),santri_id:String(ids.santri),mode:'pembimbing',_csrf:'salah'}});ok(bad.status()===419,'Web sikap CSRF salah 419');
 await pemb.getByRole('button',{name:'Serahkan sikap',exact:true}).click();ok((await pemb.locator('#status-sikap').textContent()).includes('Dikirim'),'Web pembimbing menyerahkan sikap');
 await layout(pemb,'Sikap pembimbing');await pemb.screenshot({path:(process.env.V4_EVIDENCE_DIR||'docs/phase-v4-3')+'/browser-pembimbing-375.png',fullPage:true});
 const mu=await login('v4_murobi');await mu.goto(base+'/portal/sikap_semester.php?mode=murobi&'+query);
 await mu.getByRole('button',{name:'Tandai diketahui',exact:true}).click();ok((await mu.locator('#status-sikap').textContent()).includes('Diketahui'),'Web murobi mengetahui');ok(await mu.getByRole('button',{name:'Tandai diketahui',exact:true}).count()===0,'Web tombol diketahui tidak dapat diklik dua kali');
 let r=await api('v4_murobi',`sikap?mode=murobi&${query}`);ok((await api('v4_murobi','sikap/murobi',{rencana_id:ids.rencana,santri_id:ids.santri,action:'diketahui',version:Number(r.data.sikap.version)})).status===409,'API diketahui kedua kali 409');
 await mu.locator('[name=adab]').selectOption('Sangat Baik');await mu.locator('[name=catatan]').fill('Koreksi melalui web murobi');await mu.locator('[name=alasan]').fill('Koreksi pengamatan murobi');await mu.getByRole('button',{name:'Koreksi beralasan',exact:true}).click();ok(await mu.locator('[name=adab]').inputValue()==='Sangat Baik'&&await mu.locator('.alert-danger').count()===0,'Web murobi koreksi beralasan');
 await layout(mu,'Sikap murobi');await mu.screenshot({path:(process.env.V4_EVIDENCE_DIR||'docs/phase-v4-3')+'/browser-murobi-375.png',fullPage:true});
 const pe=await login('v4_pendidikan');await pe.goto(base+'/portal/verifikasi_semester.php?'+query);
 ok((await pe.locator('h2').allTextContents()).length===1&&await pe.locator('[data-jenis=nilai]').count()===2,'Web matriks dua mapel wajib dan santri');
 ok((await pe.locator('[data-jenis=kehadiran]').textContent()).includes('Belum dicatat: 0'),'Web penghitung Belum dicatat terpisah');
 const card=pe.locator('[data-jenis=sikap]');await card.locator('button[value=kembalikan]').click();ok(await pe.locator('.alert-danger').count()===1,'Web pengembalian tanpa alasan ditolak');
 await pe.locator('[data-jenis=sikap] [name=alasan]').fill('Periksa kembali catatan');await pe.locator('[data-jenis=sikap] button[value=kembalikan]').click();ok((await pe.locator('[data-jenis=sikap] h3').textContent()).includes('Perlu Perbaikan'),'Web pengembalian beralasan');
 await pe.getByRole('button',{name:'Periksa kelayakan finalisasi',exact:true}).click();ok((await pe.locator('.alert-danger').textContent()).includes('Finalisasi ditolak'),'Web gate menolak sikap belum diverifikasi');
 await layout(pe,'Matriks Pendidikan');await pe.screenshot({path:(process.env.V4_EVIDENCE_DIR||'docs/phase-v4-3')+'/browser-pendidikan-375.png',fullPage:true});
 const csrfBad=await pe.request.post(base+'/portal/verifikasi_semester.php',{form:{rencana_id:String(ids.rencana),santri_id:String(ids.santri),_csrf:'salah'}});ok(csrfBad.status()===419,'Web verifikasi CSRF 419');
 const outside=await login('v4_pembimbing_lain');ok((await outside.goto(base+'/portal/sikap_semester.php?'+query)).status()===403,'Web pembimbing lain 403');
 const otherMu=await login('v4_murobi_lain');ok((await otherMu.goto(base+'/portal/sikap_semester.php?mode=murobi&'+query)).status()===403,'Web murobi lain 403');
 ok((await pemb.goto(base+'/portal/verifikasi_semester.php?'+query)).status()===403,'Web pembimbing matriks 403');
 // Perbaiki, verifikasi, lalu koreksi murobi setelah verifikasi dan tolak gate.
 r=await api('v4_pembimbing','sikap?'+query);payload={...r.data.sikap,rencana_id:ids.rencana,action:'koreksi',alasan:'Perbaikan setelah dikembalikan',catatan:'Diperbaiki lewat API'};
 r=await api('v4_pembimbing','sikap',payload);r=await api('v4_pembimbing','sikap',{...r.data.sikap,rencana_id:ids.rencana,action:'serahkan'});r=await api('v4_murobi','sikap/murobi',{rencana_id:ids.rencana,santri_id:ids.santri,version:Number(r.data.sikap.version),action:'diketahui'});
 const m=await material('sikap');const vp={rencana_id:ids.rencana,santri_id:ids.santri,jenis:'sikap',mapel_key:0,action:'verifikasi',...m.pemeriksaan};
 ok((await api('v4_pendidikan','verifikasi',vp)).status===200,'API Pendidikan verifikasi sikap');ok((await api('v4_pendidikan','verifikasi',vp)).status===409,'API retry verifikasi 409');
 r=await api('v4_murobi','sikap?mode=murobi&'+query);r=await api('v4_murobi','sikap/murobi',{...r.data.sikap,rencana_id:ids.rencana,action:'koreksi',alasan:'Koreksi pascaverifikasi',catatan:'Koreksi murobi terbaru'});ok(r.status===200&&(await material('sikap')).pemeriksaan.status==='Dikirim','Koreksi murobi pascaverifikasi memerlukan pemeriksaan ulang');
 after=await read();ok((await api('v4_pendidikan','verifikasi',{rencana_id:ids.rencana,santri_id:ids.santri,action:'periksa_finalisasi',matriks_hash:after.data.matriks_hash})).status===422,'API gate menolak pascakoreksi murobi');
}finally{await browser.close();}
