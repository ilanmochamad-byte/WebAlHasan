import fs from 'node:fs';
import {execFileSync} from 'node:child_process';
import {chromium} from 'playwright';
const db=process.env.DB_NAME,web=process.env.IOP_WEB,app=process.env.IOP_APP;
if(!/^test_.*_test$/.test(db)||![web,app].every(x=>/^http:\/\/127\.0\.0\.1:\d+$/.test(x)))throw Error('Fixture localhost only');
const ids=JSON.parse(fs.readFileSync(process.env.SEED_JSON,'utf8'));
const sql=q=>execFileSync('mysql',['--default-character-set=utf8mb4','-NB',db,'-e',q],{encoding:'utf8'}).trim();
const D=new Date(ids.tanggal+'T00:00:00Z');D.setUTCDate(D.getUTCDate()+14);const day=D.toISOString().slice(0,10);
let good=0,bad=0;const ok=(v,s)=>{console.log((v?'[lulus] ':'[gagal] ')+s);v?good++:bad++;};
const login=await fetch(web+'/api/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:'sbx_guru_biasa',password:'Sandbox#123',device_name:'auditor'})}).then(r=>r.json());
const izin=s=>{sql(`INSERT INTO izin_pengajuan(santri_id,murobi_guru_id,tahun_ajaran_id,tgl_izin,tgl_kembali,alasan,status,asal_pengajuan,diajukan_pada) VALUES(${s},${ids.guru_h},${ids.tahun},'${day}','${day}','PRIVATE-UI-AUDITOR','Disetujui','Pengurus',NOW())`);const i=Number(sql(`SELECT MAX(id) FROM izin_pengajuan WHERE santri_id=${s}`));sql(`INSERT INTO izin_keputusan(pengajuan_id,hasil,alasan,diputus_oleh_user_id,kapasitas,diputus_pada) VALUES(${i},'Disetujui','PRIVATE-UI-AUDITOR',${ids.admin_id},'Murobi',NOW())`);return i;};
const [a,b]=ids.santri_nama.PA,[c]=ids.santri_nama.PI;
const izinIds=[a,b,c].map(s=>izin(s.id));
const opened=await fetch(web+`/api/v1/schedules/${ids.jadwal_gabungan}/meetings`,{method:'POST',headers:{Authorization:'Bearer '+login.data.token,'Content-Type':'application/json'},body:JSON.stringify({date:day,idempotency_key:'audit-ui-open-'+ids.sfx})}).then(r=>r.json());
if(!opened.success)throw Error(JSON.stringify(opened));const m=opened.data.id;
const browser=await chromium.launch();const page=await browser.newPage({viewport:{width:375,height:812}});const errors=[],outside=[],puts=[];let lose=true;
page.on('pageerror',e=>errors.push(e.message));page.on('dialog',d=>d.accept());
await page.route('**/*',async route=>{
 const r=route.request(),u=new URL(r.url());if(u.hostname!=='127.0.0.1'){outside.push(u.origin);return route.abort();}
 if(u.origin!==web||!u.pathname.startsWith('/api/v1'))return route.fallback();
 const cors={'Access-Control-Allow-Origin':app,'Access-Control-Allow-Headers':'authorization,content-type','Access-Control-Allow-Methods':'GET,POST,PUT'};
 if(r.method()==='OPTIONS')return route.fulfill({status:204,headers:cors});
 const response=await route.fetch();
 if(r.method()==='PUT'&&u.pathname.endsWith('/attendance')){
  puts.push(JSON.parse(r.postData()));if(lose){lose=false;return route.fulfill({status:503,headers:{...cors,'Content-Type':'application/json'},body:JSON.stringify({success:false,data:null,error:{code:'AUDIT_RESPONSE_LOST',message:'Respons uji hilang sesudah commit'}})});}
 }
 return route.fulfill({response,headers:{...response.headers(),...cors}});
});
const button=x=>page.getByRole('button',{name:x,exact:true});
const card=s=>page.locator('div').filter({has:page.getByText(s.nama,{exact:true})}).filter({has:page.getByRole('radiogroup')}).last();
const status=async(s,t)=>(await card(s).getByRole('radio',{name:t}).getAttribute('aria-checked'))==='true';
const note=s=>card(s).getByPlaceholder('Catatan santri (opsional)');
const wait=x=>page.getByText(x,{exact:false}).first().waitFor({timeout:30000});
async function refresh(){if(!await button('Perbarui daftar').isVisible())await button('Tindakan lain').click();await button('Perbarui daftar').click();await wait('Daftar');await page.waitForTimeout(800);}
try{
 await page.goto(app+'/login');await page.getByPlaceholder('Masukkan username').fill('sbx_guru_biasa');await page.getByPlaceholder('Masukkan password').fill('Sandbox#123');await button('Masuk').click();await page.waitForURL(u=>u.pathname!=='/login');
 await page.goto(app+`/meeting/${m}`);await wait('Santri · 5 orang');
 ok(await status(a,'Izin')&&await status(b,'Izin')&&await status(c,'Izin'),'tiga draf Izin pada pembukaan baru');
 await card(b).getByRole('radio',{name:'Hadir'}).click();await note(c).fill('Catatan guru dipertahankan');await card(c).getByRole('radio',{name:'Terlambat'}).click();
 sql(`UPDATE izin_pengajuan SET status='Dibatalkan' WHERE id IN (${izinIds.join(',')})`);await refresh();
 ok(await status(a,'Hadir')&&await note(a).inputValue()==='','pembatalan: draf otomatis belum diedit kembali Hadir dan catatan kosong');
 ok(await status(b,'Hadir')&&await note(b).inputValue()==='','pembatalan: pilihan Hadir manual dipertahankan');
 ok(await status(c,'Terlambat')&&await note(c).inputValue()==='Catatan guru dipertahankan','pembatalan: status dan catatan manual dipertahankan');
 ok(await card(a).getByText(/^Izin s\.d\./).count()===0,'penanda hilang sesudah pembatalan dan perbarui');
 const i2=izin(a.id);await refresh();
 ok(await status(a,'Izin')&&(await note(a).inputValue()).startsWith(`Izin No. ${i2} — `),'izin baru sesudah pembatalan kembali mengisi draf dan catatan terbaru');
 await button('Semua hadir (4)').click();ok(await status(a,'Izin')&&await status(c,'Hadir')&&await note(c).inputValue()==='Catatan guru dipertahankan','Semua hadir melewati usulan terbaru dan mempertahankan catatan guru');
 await button('Kirim absensi').dblclick();await wait('Respons uji hilang');
 ok(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE entity_id=${m} AND action='api.attendance.save'`))===1,'respons hilang sesudah commit: satu penyimpanan terjadi');
 await button('Kirim absensi').dblclick();await wait('Absensi berhasil disimpan');
 ok(puts.length===2&&puts[0].idempotency_key===puts[1].idempotency_key,'ulang setelah respons hilang menggunakan kunci idempotensi sama dan dua PUT total');
 ok(Number(sql(`SELECT COUNT(*) FROM audit_logs WHERE entity_id=${m} AND action='api.attendance.save'`))===1,'ulang sesudah respons hilang tidak menyimpan dua kali');
 ok(sql(`SELECT status FROM absensi_santri WHERE pertemuan_id=${m} AND santri_id=${a.id}`)==='Izin','status Izin terbaru tersimpan');
 await page.goto(app+`/meeting/${m}`);await wait('Santri · 5 orang');
 ok(await status(a,'Izin')&&await status(b,'Hadir')&&await status(c,'Hadir'),'memuat ulang Selesai memakai status tersimpan');
 await page.goto(app+`/report/${m}`);await wait('Peserta');
 ok(!(await page.locator('body').innerText()).includes('PRIVATE-UI-AUDITOR')&&!JSON.stringify(puts).includes('PRIVATE-UI-AUDITOR'),'layar laporan dan kiriman absensi tanpa isi izin privat');
 ok(errors.length===0&&outside.length===0,'tanpa galat halaman atau permintaan host luar');
 console.log('Pertemuan audit UI: '+m);
}finally{await browser.close();}
console.log(`Hasil: ${good} lulus, ${bad} gagal`);process.exitCode=bad?1:0;
