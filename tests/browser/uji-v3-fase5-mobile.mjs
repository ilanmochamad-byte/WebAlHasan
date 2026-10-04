// Expo web uses the real local API. This is not Android/iOS physical evidence.
import {chromium} from 'playwright';
import {mkdirSync,writeFileSync} from 'node:fs';
const api=process.env.BASE_URL??'http://127.0.0.1:8940';
const app=process.env.MOBILE_URL??'http://127.0.0.1:8082';
if(process.env.PERAPIHAN_AUDIT_DB!=='1'||![api,app].every(u=>/^http:\/\/127\.0\.0\.1:\d+$/.test(u)))throw Error('Local test opt-in required');
const browser=await chromium.launch();const checks=[];const out='/tmp/v3-phase5-mobile';mkdirSync(out,{recursive:true});
const check=(ok,name)=>{checks.push({ok:Boolean(ok),name});console.log(`${ok?'[lulus]':'[gagal]'} ${name}`);};
let cid;const note='SBX F5 catatan UI '+crypto.randomUUID();
async function session(user){
 const context=await browser.newContext({viewport:{width:375,height:900}});
 await context.route('**/*',async route=>{
  const url=new URL(route.request().url());if(url.origin===app||(url.origin===api&&!url.pathname.startsWith('/api/v1/')))return route.continue();if(url.origin!==api&&!url.pathname.startsWith('/api/v1/'))return route.abort();
  // Same backend, adding browser CORS for the isolated local Expo harness only.
  if(route.request().method()==='OPTIONS')return route.fulfill({status:204,headers:{'access-control-allow-origin':'*','access-control-allow-headers':'*'}});
  const req=route.request();const response=await context.request.fetch(api+url.pathname+url.search,{method:req.method(),headers:req.headers(),data:req.postData()??undefined});
  await route.fulfill({response,headers:{...response.headers(),'access-control-allow-origin':'*'}});
 });
 const page=await context.newPage();await page.goto(app+'/login');
 await page.getByPlaceholder('Masukkan username').fill(user);await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');
 await page.getByRole('button',{name:'Masuk',exact:true}).click().catch(async e=>{await page.screenshot({path:out+'/error.png'});for(const f of page.frames())console.log((await f.locator('body').innerText().catch(()=>'' )).slice(-5000));throw e;});
 await page.waitForURL(u=>!u.pathname.includes('login'),{timeout:30000}).catch(async e=>{console.log((await page.locator('body').innerText()).slice(-4000));await page.screenshot({path:out+'/login-error.png'});throw e;});
 await page.goto(app+'/pembinaan');await page.getByRole('button',{name:user.includes('ortu')?'Informasi untuk keluarga':'Pelanggaran & poin',exact:true}).waitFor({timeout:60000});
 return {context,page};
}
try{
 const {context,page}=await session('sbx_pengurus_a');
 check(await page.getByRole('button',{name:'Catat pelanggaran',exact:true}).count()===1,'Menu pembimbing berbasis capability');
  const auth=await context.request.post(api+'/api/v1/auth/login',{data:{username:'sbx_pengurus_a',password:'Sandbox#123'}});const headers={Authorization:'Bearer '+(await auth.json()).data.token};
 const options=(await (await context.request.get(api+'/api/v1/v3/mobile/options',{headers})).json()).data;
 // Dropdown harus menyatukan katalog lintas halaman, dan draf bertahan di latar.
 const options2=(await (await context.request.get(api+'/api/v1/v3/mobile/options?page=2',{headers})).json()).data;
 const choose=async(label,choice)=>{await page.getByRole('button',{name:label,exact:true}).click();await page.getByRole('button',{name:choice,exact:true}).click();};
 const studentLabel=s=>`${s.nama}${s.tahun ? ` · ${s.tahun} / ${s.semester}` : ''}`;
 if(options2.katalog.length>0){
  await page.goto(app+'/pembinaan/buat?jenis=pelanggaran');
  await choose('Santri dalam cakupan aktif',studentLabel(options.santri[0]));
  await page.getByLabel('Uraian kejadian',{exact:true}).fill('SBX F5 dropdown');
  const k2=options2.katalog[0];await page.getByRole('button',{name:'Jenis pelanggaran',exact:true}).click();
  await page.getByLabel('Cari jenis pelanggaran',{exact:true}).fill(k2.nama);
  await page.getByRole('button',{name:`${k2.nama} · ${k2.tingkat} · ${k2.poin_default} poin`,exact:true}).first().click();
  check(await page.getByLabel('Uraian kejadian',{exact:true}).inputValue()==='SBX F5 dropdown','Memilih katalog lintas halaman tidak menghapus uraian');
  await page.getByLabel('Waktu kejadian',{exact:true}).fill('2026-09-27T09:00');
  check(await page.getByRole('button',{name:'Simpan',exact:true}).isEnabled(),'Santri halaman 1 dapat dipadukan dengan katalog halaman 2 melalui dropdown');
  const visibility=state=>page.evaluate(s=>{Object.defineProperty(document,'visibilityState',{configurable:true,get:()=>s});Object.defineProperty(document,'hidden',{configurable:true,get:()=>s==='hidden'});document.dispatchEvent(new Event('visibilitychange'));},state);
  await visibility('hidden');await visibility('visible');await page.getByRole('button',{name:'Jenis pelanggaran',exact:true}).waitFor();
  check(await page.getByLabel('Uraian kejadian',{exact:true}).inputValue()==='SBX F5 dropdown'&&await page.getByRole('button',{name:'Simpan',exact:true}).isEnabled(),'Isian privat bertahan di memori setelah latar/aktif sesuai keputusan 4 Oktober');
  await page.goto(app+'/pembinaan');await page.getByRole('button',{name:'Catat pelanggaran',exact:true}).waitFor({timeout:60000});
 }else check(false,'Fixture memerlukan katalog aktif lebih dari 25 untuk uji dropdown');
 await page.getByRole('button',{name:'Catat pelanggaran',exact:true}).click();
 await choose('Santri dalam cakupan aktif',studentLabel(options.santri[0]));
 const catalog=options.katalog[0];await page.getByRole('button',{name:'Jenis pelanggaran',exact:true}).click();await page.getByLabel('Cari jenis pelanggaran',{exact:true}).fill(catalog.nama);await page.getByRole('button',{name:`${catalog.nama} · ${catalog.tingkat} · ${catalog.poin_default} poin`,exact:true}).click();
 await page.getByLabel('Waktu kejadian',{exact:true}).fill('2026-09-27T10:00');
 await page.getByLabel('Uraian kejadian',{exact:true}).fill(note);
 await page.getByRole('button',{name:'Simpan',exact:true}).click();await page.waitForURL(u=>u.pathname.includes('/pembinaan/detail'));
 await page.getByText(note,{exact:true}).waitFor();check(true,'Pelanggaran dicatat melalui UI aplikasi');
 await page.goto(app+'/pembinaan');await page.getByRole('button',{name:'Rekomendasi tindak lanjut',exact:true}).click();
 const recommendations=(await (await context.request.get(api+'/api/v1/v3/mobile/rekomendasi',{headers})).json()).data.rows;
 const recommendation=recommendations.find(r=>r.santri_id===options.santri[0].santri_id);if(!recommendation)throw Error('Seed requires pending recommendation for first student');
 await page.getByRole('button',{name:`${recommendation.nama_santri} · ${recommendation.label_snapshot} · ${recommendation.rekomendasi_snapshot}`,exact:true}).first().click();
 await choose('Santri dalam cakupan aktif',studentLabel(options.santri[0]));
 await page.getByLabel('Tujuan pendampingan',{exact:true}).fill('SBX F5 alur Expo web');
 await choose('Kerahasiaan (wajib dipilih)','Internal');
 await page.getByRole('button',{name:'Simpan',exact:true}).click();
 await page.waitForURL(u=>u.pathname.includes('/pembinaan/detail'),{timeout:30000});cid=Number(new URL(page.url()).searchParams.get('id'));
 check(Number.isSafeInteger(cid)&&cid>0,'Kasus dibuat melalui UI aplikasi');
  await page.getByRole('button',{name:'Mulai pendampingan',exact:true}).waitFor();
 await context.request.post(api+`/api/v1/v3/konseling/kasus/${cid}/status`,{headers,data:{version:1,status:'Dalam Pendampingan',idempotency_key:`f5-conflict-${crypto.randomUUID()}`}});
 await page.getByRole('button',{name:'Mulai pendampingan',exact:true}).click();await page.getByText(/Muat ulang data sebelum mencoba kembali/).waitFor();check(true,'Konflik versi nyata UI menampilkan tindakan muat ulang');
 await page.getByRole('button',{name:'Muat ulang versi terbaru',exact:true}).click();await page.getByRole('button',{name:'Selesaikan kasus',exact:true}).waitFor();
 for(let n=1;n<=2;n++){
  await page.getByLabel('Jadwal sesi baru / jadwal ulang',{exact:true}).fill(`2026-09-${27+n}T10:00`);
  await page.getByRole('button',{name:'Tambahkan sesi baru',exact:true}).click();
  const done=page.getByRole('button',{name:/Selesaikan sesi #.* dengan isian di bawah/});await done.waitFor();
  await page.getByLabel('Ringkasan internal sesi / ringkasan penutupan',{exact:true}).fill(`SBX sesi ${n}`);
  await page.getByLabel('Hasil sesi',{exact:true}).fill(`SBX hasil ${n}`);
  await done.click();await done.waitFor({state:'hidden'});
 }
 await page.getByLabel('Ringkasan internal sesi / ringkasan penutupan',{exact:true}).fill('SBX penutupan dua sesi');
 await page.getByRole('button',{name:'Selesaikan kasus',exact:true}).click();await page.getByRole('button',{name:'Selesaikan kasus',exact:true}).waitFor({state:'hidden'});
 const detail=(await (await context.request.get(api+`/api/v1/v3/konseling/kasus/${cid}`,{headers})).json()).data;
 check(detail.rekomendasi.some(r=>r.id===recommendation.id),'Rekomendasi ditautkan ke kasus UI yang sama');
 check(detail.kasus.status==='Selesai'&&detail.sesi_aktif.length===2&&detail.sesi_aktif.every(s=>s.status==='Selesai'),'Dua sesi berbeda dan penutupan UI tanpa duplikasi');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'UI detail 375px tanpa luapan');await page.screenshot({path:out+'/pembimbing-375.png',fullPage:true});
  const web=await context.newPage();await web.goto(api+'/portal/');await web.locator('#username').fill('sbx_pengurus_a');await web.locator('#password').fill('Sandbox#123');await web.getByRole('button',{name:'Masuk',exact:true}).click();await web.waitForURL('**/portal/index.php');
 await web.goto(api+'/portal/v3_konseling.php');
 check(await web.locator('#case-privacy').inputValue()===''&&await web.locator('#case-privacy').evaluate(el=>el.required&&!el.checkValidity()),'Website meminta pemilihan kerahasiaan eksplisit');
 await web.locator('#case-purpose').fill('SBX draf website');await web.locator('#case-privacy').selectOption('Internal');
 await web.locator('#case-opened').fill('2026-09-27T10:00');
 await web.evaluate(()=>{Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'hidden'});document.dispatchEvent(new Event('visibilitychange'));Object.defineProperty(document,'visibilityState',{configurable:true,get:()=> 'visible'});document.dispatchEvent(new Event('visibilitychange'));});
 check(await web.locator('#case-purpose').inputValue()==='SBX draf website'&&await web.locator('#case-privacy').inputValue()==='Internal'&&await web.locator('#case-opened').inputValue()==='2026-09-27T10:00','Website mempertahankan isian ketika halaman kembali aktif');
 await web.goto(api+'/portal/v3_pelanggaran.php');
 check(await web.locator('#v3-waktu').getAttribute('type')==='datetime-local'&&await web.locator('#v3-katalog').evaluate(el=>el.tagName==='SELECT'&&el.options.length>1),'Website sudah memiliki kalender/jam dan dropdown katalog');
 const wr=await web.goto(api+`/portal/v3_konseling_detail.php?id=${cid}`);check(wr.status()===200&&(await web.locator('body').innerText()).includes('SBX penutupan dua sesi'),'Website membaca kasus dan dua sesi aplikasi yang sama');
 await context.close();
 const m=await session('sbx_murobi_a');await m.page.goto(app+`/pembinaan/detail?jenis=kasus&id=${cid}`);
 await m.page.getByRole('button',{name:'Tandai mengetahui',exact:true}).waitFor();await m.page.getByLabel('Catatan murobi / alasan tindakan',{exact:true}).fill('SBX catatan murobi aplikasi');
 await m.page.getByRole('button',{name:'Tandai mengetahui',exact:true}).click();await m.page.getByText('Catatan murobi: SBX catatan murobi aplikasi',{exact:true}).waitFor();
 check(true,'Murobi menandai kasus yang sama melalui UI');await m.context.close();
 const p=await session('sbx_ortu_a');check(await p.page.getByRole('button',{name:'Kasus & sesi konseling',exact:true}).count()===0,'Menu wali hanya publikasi');
 await p.page.getByRole('button',{name:'Informasi untuk keluarga',exact:true}).click();await p.page.getByRole('button',{name:/^(Terbit|Ditarik) ·/}).first().click();await p.page.waitForURL(u=>u.pathname.startsWith('/publikasi/'));await p.page.getByText('Ringkasan untuk orang tua',{exact:true}).waitFor();check(!(await p.page.locator('body').innerText()).includes('PRIVATE-F5'),'Wali membaca snapshot aplikasi tanpa isi internal');
 await p.page.goto(app+`/pembinaan/detail?jenis=kasus&id=${cid}`);await p.page.getByText('Informasi tidak dapat diakses. Muat ulang atau masuk dengan akun yang berhak.',{exact:true}).waitFor();check(true,'Deep-link wali ke data internal ditolak server dengan pesan netral');await p.context.close();
 const b=await session('sbx_pengurus_b');await b.page.goto(app+`/pembinaan/detail?jenis=kasus&id=${cid}`);await b.page.getByText('Informasi tidak dapat diakses. Muat ulang atau masuk dengan akun yang berhak.',{exact:true}).waitFor();check(true,'Deep-link pembimbing lain ditolak server');await b.context.close();
 const mb=await session('sbx_murobi_b');await mb.page.goto(app+`/pembinaan/detail?jenis=kasus&id=${cid}`);await mb.page.getByText('Informasi tidak dapat diakses. Muat ulang atau masuk dengan akun yang berhak.',{exact:true}).waitFor();check(true,'Deep-link murobi lain ditolak server');await mb.context.close();
 const admin=await session('sbx_admin');check(await admin.page.getByRole('button',{name:'Buka kasus',exact:true}).count()===0,'Admin mengikuti capability pengawasan tanpa memperoleh tombol kelola');await admin.context.route('**/api/v1/v3/mobile/rekomendasi*',route=>route.fulfill({status:200,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:true,data:{rows:[],page:1,per_page:25},error:null})}));
 await admin.page.goto(app+'/pembinaan/daftar?jenis=rekomendasi');await admin.page.getByText('Belum ada data dalam cakupan aktif Anda.',{exact:true}).waitFor();check(true,'Keadaan kosong UI terarah');
 let fail=true;await admin.context.route('**/api/v1/v3/capabilities',async route=>{if(!fail)return route.fallback();return route.fulfill({status:503,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:false,data:null,error:{code:'TEST_FAILURE',message:'SBX server sementara gagal.'}})});});
 await admin.page.goto(app+'/pembinaan');await admin.page.getByRole('button',{name:'Coba lagi',exact:true}).waitFor();check(true,'Galat server UI menyediakan retry setelah percobaan GET terbatas');fail=false;await admin.page.getByRole('button',{name:'Coba lagi',exact:true}).click();await admin.page.getByRole('button',{name:'Pelanggaran & poin',exact:true}).waitFor();check(true,'Retry UI memuat capability nyata kembali');
 await admin.context.route('**/api/v1/v3/capabilities',route=>route.fulfill({status:401,contentType:'application/json',headers:{'access-control-allow-origin':'*'},body:JSON.stringify({success:false,data:null,error:{code:'EXPIRED',message:'SBX sesi berakhir.'}})}));await admin.page.goto(app+'/pembinaan');await admin.page.getByPlaceholder('Masukkan username').waitFor();check(true,'401 UI menghapus sesi dan meminta login');await admin.context.close();
 const unauth=await browser.newPage();await unauth.goto(app+`/pembinaan/detail?jenis=kasus&id=${cid}`);await unauth.getByPlaceholder('Masukkan username').waitFor();check(!(await unauth.locator('body').innerText()).includes('SBX F5 alur'),'Detail deep-link tanpa login tidak membaca isi');await unauth.close();
}finally{writeFileSync(out+'/results.json',JSON.stringify(checks,null,2));await browser.close();}
if(checks.some(c=>!c.ok))process.exitCode=1;
