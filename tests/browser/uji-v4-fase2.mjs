import fs from 'node:fs';
import {chromium} from 'playwright';
const base=process.env.V4_TEST_URL||'http://127.0.0.1:8962';
if(!/^http:\/\/127\.0\.0\.1:/.test(base))throw Error('Hanya server uji localhost');
const ids=JSON.parse(fs.readFileSync('/private/tmp/v4-fase2-ids.json','utf8'));
const tokens=JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS,'utf8'));
const ok=(v,s)=>{if(!v)throw Error(s);console.log('[lulus] '+s);};
async function api(user,path,body){const r=await fetch(base+'/api/v1'+path,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+tokens[user],'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return {status:r.status,body:await r.json()};}
const path=`/v4/nilai?rencana_mapel_id=${ids.mapel}&santri_id=${ids.santri}`;
let r=await api('v4_guru',path);ok(r.status===200&&r.body.data.hasil.nilai_tampil===85&&r.body.data.hasil.predikat==='B','API 85 predikat B');
for(const who of ['v4_wali','v4_pengurus']){ok((await api(who,path)).status===403,who+' baca 403');ok((await api(who,'/v4/nilai',{})).status===403,who+' tulis kosong 403');}
ok((await api('v4_guru',`/v4/nilai?rencana_mapel_id=${ids.other}`)).status===403,'API guru mapel lain 403');
ok((await api('v4_pendidikan',path)).status===403,'Pendidikan tidak dapat menyamar melalui jalur guru');
let p={rencana_mapel_id:ids.mapel,santri_id:ids.santri,action:'koreksi',komponen:r.body.data.nilai};
ok((await api('v4_pendidikan','/v4/nilai/pengganti',p)).status===422,'API pengganti tanpa alasan 422');
p.alasan='Guru berhalangan pada uji HTTP';p.komponen=p.komponen.map((c,i)=>i===0?{...c,catatan:'Dikoreksi pengganti via API'}:c);r=await api('v4_pendidikan','/v4/nilai/pengganti',p);ok(r.status===200&&r.body.data.riwayat[0].kapasitas==='pendidikan_pengganti'&&r.body.data.nilai.filter(n=>n.kapasitas==='pendidikan_pengganti').length===1,'API pengganti beralasan tercatat hanya pada komponen yang diubah');
ok((await api('v4_pendidikan','/v4/nilai/pengganti',p)).status===409,'API retry versi usang 409');
r=await api('v4_guru',`/v4/nilai?rencana_mapel_id=${ids.mapel}&santri_id=${ids.santri2}`);ok(r.body.data.hasil.kelengkapan==='Belum lengkap'&&Number(r.body.data.nilai[0].nilai)===0,'API nol dan Belum lengkap');
const anonymous=await fetch(base+'/api/v1'+path);ok(anonymous.status===401,'API tanpa bearer 401');
const browser=await chromium.launch({headless:true});
try {
 const page=await browser.newPage({viewport:{width:375,height:812}});
 await page.goto(base+'/portal/index.php');await page.locator('input[name=username]').fill('v4_pendidikan');await page.locator('input[name=password]').fill(process.env.V4_TEST_PASSWORD);await page.locator('form').first().locator('button[type=submit]').click();
 const url=base+`/portal/nilai_semester.php?pengganti=1&rencana_mapel_id=${ids.mapel}&santri_id=${ids.web}`;
 await page.goto(url);ok(await page.getByRole('heading',{name:'Pengisian nilai',exact:true}).count()===1,'Web pengganti 375 px tersedia');
 const csrfStatus=await page.evaluate(async()=>{const r=await fetch(location.href,{method:'POST',body:new URLSearchParams({pengganti:'1',rencana_mapel_id:new URLSearchParams(location.search).get('rencana_mapel_id'),santri_id:new URLSearchParams(location.search).get('santri_id')})});return r.status;});ok(csrfStatus===419,'Web tanpa CSRF 419');
 await page.locator('[name="komponen[0][nilai]"]').fill('80');await page.locator('[name="komponen[1][nilai]"]').fill('78');await page.locator('[name="komponen[2][nilai]"]').fill('92');await page.getByRole('button',{name:'Tambah harian/praktik'}).click();await page.locator('[name="komponen[3][nilai]"]').fill('90');
 await page.locator('[name="komponen[0][catatan]"]').fill('<script>window.v4xss=1</script>');await page.locator('[name=alasan]').fill('Guru berhalangan');
 await page.getByRole('button',{name:'Simpan draf',exact:true}).click();ok((await page.locator('#hasil-nilai').textContent()).includes('Nilai akhir 85 · predikat B'),'Web simpan draf 85 B');ok(await page.evaluate(()=>window.v4xss)===undefined,'Catatan XSS tidak dieksekusi');
 r=await api('v4_guru',`/v4/nilai?rencana_mapel_id=${ids.mapel}&santri_id=${ids.web}`);ok(r.body.data.hasil.nilai_tampil===85,'API membaca hasil simpan web 85');
 const staleForm=await page.locator('#nilai-form').evaluate(f=>new URLSearchParams(new FormData(f)).toString());
 await page.locator('[name=alasan]').fill('Guru berhalangan, menyerahkan nilai');await page.getByRole('button',{name:'Serahkan nilai',exact:true}).click();ok((await page.locator('.nilai-row h3').allTextContents()).filter(x=>x.includes('Dikirim')).length===4,'Web penyerahan empat komponen');
 const staleStatus=await page.evaluate(async body=>{const p=new URLSearchParams(body);p.set('alasan','Uji konflik');p.set('action','koreksi');return (await fetch(location.href,{method:'POST',body:p})).status;},staleForm);ok(staleStatus===409,'Web versi usang 409');
 await page.locator('[name=alasan]').fill('Koreksi catatan capaian');await page.locator('[name="komponen[0][catatan]"]').fill('Catatan terkoreksi');await page.getByRole('button',{name:'Koreksi beralasan',exact:true}).click();ok((await page.locator('.nilai-row h3').allTextContents()).filter(x=>x.includes('Draf')).length===4,'Web koreksi kembali draf');
 ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'375 px tanpa overflow');await page.evaluate(()=>document.documentElement.style.fontSize='24px');ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'375 px teks 24 px tanpa overflow');
 await page.screenshot({path:'docs/phase-v4-2/browser-375.png',fullPage:true});await page.setViewportSize({width:1440,height:1000});ok(!await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),'Desktop tanpa overflow');
 await page.goto(base+`/portal/nilai_semester.php?pengganti=1&rencana_mapel_id=${ids.mapel}&santri_id=${ids.santri2}`);ok((await page.locator('#hasil-nilai').textContent()).includes('Belum lengkap')&&await page.locator('[name="komponen[0][nilai]"]').inputValue()==='0.00','Web nol tetap nol dan Belum lengkap');
 const denied=await page.goto(base+`/portal/nilai_semester.php?rencana_mapel_id=${ids.mapel}`);ok(denied.status()===403,'Web Pendidikan jalur guru 403');
} finally {await browser.close();}
