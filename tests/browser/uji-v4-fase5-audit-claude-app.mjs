import fs from 'node:fs'; import { execFileSync } from 'node:child_process';
import { chromium } from 'playwright';
// Audit Claude Fase 5. Env: V4_TEST_URL, V4_APP_URL (Expo web), V4_HTTP_TOKENS, V4_IDS_FILE, V4_DB_SOCKET, V4_TEST_PASSWORD.
const base=process.env.V4_TEST_URL, mobile=process.env.V4_APP_URL;
if(![base,mobile].every(u=>/^http:\/\/127\.0\.0\.1:/.test(u)))throw Error('localhost only');
if(!/_test$/.test(process.env.DB_NAME||'')||!process.env.V4_DB_SOCKET)throw Error('Hanya DB *_test dengan socket uji');
const tokens=JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS)); const ids=JSON.parse(fs.readFileSync(process.env.V4_IDS_FILE));
let fail=0; const ok=(v,l)=>{console.log((v?'[lulus] ':'[gagal] ')+l); if(!v)fail++;};
const sql=q=>execFileSync('/opt/homebrew/bin/mariadb',['--socket='+process.env.V4_DB_SOCKET,'-u','root','-N','-B',process.env.DB_NAME,'-e',q]).toString().trim();
async function api(u,p,body){const r=await fetch(base+'/api/v1/v4/'+p,{method:body?'POST':'GET',headers:{Authorization:'Bearer '+tokens[u],'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return {status:r.status,data:(await r.json()).data};}
const browser=await chromium.launch({headless:true});
async function app(user){const page=await browser.newPage({viewport:{width:375,height:812}});
 await page.route('**/api/v1/**',async route=>{if(new URL(route.request().url()).origin!==base){await route.abort();return;}
  if(route.request().method()==='OPTIONS')return route.fulfill({status:204,headers:{'Access-Control-Allow-Origin':mobile,'Access-Control-Allow-Headers':'authorization,content-type','Access-Control-Allow-Methods':'GET,POST,PUT'}});
  const response=await route.fetch();await route.fulfill({response,headers:{...response.headers(),'Access-Control-Allow-Origin':mobile}});});
 await page.goto(mobile+'/login');await page.getByPlaceholder('Masukkan username').fill(user);await page.getByPlaceholder('Masukkan password').fill(process.env.V4_TEST_PASSWORD);
 await page.getByRole('button',{name:'Masuk',exact:true}).click();await page.waitForURL(u=>u.pathname!=='/login');return page;}
async function pick(page,label,option){await page.getByRole('button',{name:label,exact:true}).click();await page.getByRole('button',{name:option,exact:true}).click();await page.getByRole('button',{name:'Tutup pilihan',exact:true}).waitFor({state:'hidden'});}
try{
 const option=(await api('v4_guru','nilai/options')).data.find(o=>Number(o.id)===Number(ids.mapel));
 const students=(await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}`)).data.santri;let student;
 for(const s of students){const d=await api('v4_guru',`nilai?rencana_mapel_id=${ids.mapel}&santri_id=${s.id}`);if(!d.data.nilai.length){student=s;break;}}
 if(!student)throw Error('Butuh santri tanpa nilai');
 const p=await app('v4_guru');await p.goto(mobile+'/rapor/nilai');
 await pick(p,'Kelas, semester, dan mapel',`${option.nama_kelas} · ${option.tahun} ${option.semester} · ${option.mata_pelajaran}`);await pick(p,'Santri',student.nama_santri);
 await p.getByRole('textbox',{name:'Harian 1',exact:true}).fill('80');await p.getByRole('textbox',{name:'Tengah',exact:true}).fill('70');await p.getByRole('textbox',{name:'Akhir',exact:true}).fill('90');
 // A. Jawaban hilang: server commit, klien menerima kegagalan jaringan
 let posts=0; let dropped=false;
 await p.route('**/v4/nilai',async route=>{if(route.request().method()!=='POST')return route.fallback();posts++;
  if(!dropped){dropped=true;const r=await route.fetch();ok(r.status()===200,'Server commit pada request pertama (respons kemudian dibuang)');return route.abort('connectionreset');}
  return route.fallback();});
 await p.getByRole('button',{name:'Serahkan nilai',exact:true}).dblclick();
 await p.getByText('offline',{exact:false}).or(p.getByText('koneksi',{exact:false})).first().waitFor();
 ok(posts===1,`Ketukan ganda hanya mengirim satu POST (terhitung ${posts})`);
 const conflict=p.waitForResponse(r=>r.url().endsWith('/v4/nilai')&&r.request().method()==='POST');
 await p.getByRole('button',{name:'Serahkan nilai',exact:true}).click();const c=await conflict;
 ok(c.status()===409,`Retry manual sesudah jawaban hilang → ${c.status()} (bukan duplikat)`);
 await p.getByText('Muat ulang data sebelum mencoba kembali.',{exact:false}).waitFor();
 const rows=sql(`SELECT COUNT(*) FROM v4_nilai WHERE rencana_mapel_id=${ids.mapel} AND santri_id=${student.id}`);
 ok(rows==='3',`Tidak ada nilai ganda (baris=${rows})`);
 await p.waitForTimeout(800);
 ok(await p.getByRole('textbox',{name:'Harian 1',exact:true}).inputValue()==='80.00'||await p.getByRole('textbox',{name:'Harian 1',exact:true}).inputValue()==='80','Form dimuat ulang dari server setelah 409');
 await p.unroute('**/v4/nilai');
 // B. Wali: relasi dicabut saat layar terbuka
 const w=await app('v4_wali4a');await w.goto(mobile+'/rapor/'+ids.rapor);await w.getByRole('button',{name:'Unduh PDF rapor',exact:true}).waitFor();
 const name=sql(`SELECT s.nama_santri FROM santri s JOIN v4_rapor r ON r.santri_id=s.id WHERE r.id=${ids.rapor}`);
 ok(await w.getByText(name,{exact:true}).count()>0,'Detail wali menampilkan nama santri sebelum cabut');
 const wid=sql(`SELECT wali_id FROM users WHERE username='v4_wali4a'`);const sid=sql(`SELECT santri_id FROM v4_rapor WHERE id=${ids.rapor}`);
 sql(`UPDATE santri_wali SET archived_at=NOW() WHERE wali_id=${wid} AND santri_id=${sid}`);
 try{
  const pdf=w.waitForResponse(r=>r.url().includes(`/v4/rapor/${ids.rapor}/pdf/`));await w.getByRole('button',{name:'Unduh PDF rapor',exact:true}).click();ok((await pdf).status()===403,'Unduh setelah relasi dicabut 403');
  await w.getByText('Informasi tidak dapat diakses.',{exact:false}).waitFor();
  ok(await w.getByText(name,{exact:true}).count()===0,'Setelah 403 isi rapor lama dibersihkan dari layar');
  await w.goto(mobile+'/rapor');await w.getByText('Belum ada akses rapor',{exact:true}).waitFor();ok(true,'Hub tanpa menu wali setelah relasi dicabut');
 }finally{sql(`UPDATE santri_wali SET archived_at=NULL WHERE wali_id=${wid} AND santri_id=${sid}`);}
 // C. Belum login → rute rapor meminta login dulu
 const anon=await browser.newPage();await anon.goto(mobile+'/rapor/'+ids.rapor);await anon.waitForTimeout(1500);
 ok(new URL(anon.url()).pathname==='/login'||await anon.getByPlaceholder('Masukkan username').count()>0,'Tanpa sesi, rute rapor diarahkan ke login');
 ok(await anon.getByText(name,{exact:true}).count()===0,'Tanpa sesi tidak ada isi rapor');
 // D. Penyimpanan perangkat (web): tidak ada nilai/snapshot di storage
 const store=await w.evaluate(()=>JSON.stringify({l:{...localStorage},s:{...sessionStorage}}));
 ok(!store.includes(name)&&!/nilai_tampil|snapshot|data:image/.test(store),'localStorage/sessionStorage tanpa isi rapor');
}finally{await browser.close();}
console.log(fail?`GAGAL ${fail}`:'SEMUA LULUS');process.exit(fail?1:0);
