import fs from 'node:fs'; import crypto from 'node:crypto'; import { execFileSync } from 'node:child_process';
// Audit Claude Fase 5. Env: V4_TEST_URL, V4_HTTP_TOKENS, V4_ADMIN_TOKEN_FILE, V4_IDS_FILE, V4_DB_SOCKET, DB_NAME (*_test).
const base=process.env.V4_TEST_URL; if(!/^http:\/\/127\.0\.0\.1:/.test(base)) throw Error('localhost only');
if(!/_test$/.test(process.env.DB_NAME||'')||!process.env.V4_DB_SOCKET)throw Error('Hanya DB *_test dengan socket uji');
const t={...JSON.parse(fs.readFileSync(process.env.V4_HTTP_TOKENS)),...JSON.parse(fs.readFileSync(process.env.V4_ADMIN_TOKEN_FILE))};
const ids=JSON.parse(fs.readFileSync(process.env.V4_IDS_FILE));
let fail=0; const ok=(v,l)=>{console.log((v?'[lulus] ':'[gagal] ')+l); if(!v) fail++;};
const get=async(u,p,raw=false)=>{const r=await fetch(base+'/api/v1'+p,{headers:u?{Authorization:'Bearer '+t[u]}:{}}); return {s:r.status,h:r.headers,b:raw?Buffer.from(await r.arrayBuffer()):await r.json().catch(()=>null)};};
const sql=q=>execFileSync('/opt/homebrew/bin/mariadb',['--socket='+process.env.V4_DB_SOCKET,'-u','root','-N','-B',process.env.DB_NAME,'-e',q]).toString().trim();
// 1. capabilities
const caps={};
for(const u of ['admin','v4_guru','v4_pembimbing','v4_murobi','v4_pendidikan','v4_wali4a','v4_wali4lain','v4_pengurus']){const r=await get(u,'/v4/capabilities');caps[u]=r.b?.data?.list;ok(r.s===200&&Array.isArray(caps[u]),`capabilities ${u} 200`);ok((r.h.get('cache-control')||'').includes('no-store'),`capabilities ${u} no-store`);}
console.log('# caps',JSON.stringify(caps));
ok((await get(null,'/v4/capabilities')).s===401,'capabilities tanpa token 401');
ok(!caps.admin.includes('rapor.wali'),'admin tanpa relasi wali tidak mendapat rapor.wali');
ok(caps.v4_wali4a.includes('rapor.wali')&&caps.v4_wali4a.length===1,'wali hanya rapor.wali');
ok(!caps.v4_guru.some(c=>c.startsWith('rapor.')||c.startsWith('sikap.')),'guru tanpa menu rapor/sikap');
// 2. proyeksi wali
const rid=ids.rapor;
const d=await get('v4_wali4a',`/v4/rapor/${rid}`);ok(d.s===200,'wali4a detail 200');
const forbidden=/^(gambar|aset|aset_id|penanda_tangan_id|.*sha256|.*hash|kelas_id|rencana_id|tahun_ajaran_id|sumber|kekurangan|actor_user_id|alasan|kapasitas|pdf_private_path|komponen|verifikasi_kode|kode)$/;
const bad=[];const walk=(o,p)=>{if(Array.isArray(o))o.forEach((x,i)=>walk(x,p+'['+i+']'));else if(o&&typeof o==='object')for(const[k,v]of Object.entries(o)){if(forbidden.test(k))bad.push(p+'.'+k);walk(v,p+'.'+k);}};walk(d.b.data,'');
ok(bad.length===0,'JSON wali tanpa kunci internal: '+(bad.slice(0,8).join(',')||'-'));
ok(!JSON.stringify(d.b).includes('data:image'),'JSON wali tanpa data:image');
ok(d.b.data.versi.some(v=>v.snapshot)&&d.b.data.versi.every(v=>!v.snapshot||!('id' in v.snapshot.santri)),'snapshot wali tanpa santri.id');
for(const [u,p,exp] of [['v4_wali4a',`/v4/rapor/${rid}?petugas=1`,403],['v4_wali4lain',`/v4/rapor/${rid}`,403],['v4_pendidikan',`/v4/rapor/${rid}`,403],['admin',`/v4/rapor/${rid}`,403],['v4_guru',`/v4/rapor/${rid}`,403],['v4_guru',`/v4/rapor/${rid}?petugas=1`,403],[null,`/v4/rapor/${rid}`,401]]){const r=await get(u,p);ok(r.s===exp,`${u??'anon'} ${p} → ${r.s} (harap ${exp})`);}
const st=await get('v4_pendidikan',`/v4/rapor/${rid}?petugas=1`);ok(st.s===200&&JSON.stringify(st.b).includes('gambar'),'Pendidikan petugas=1 tetap lengkap (gambar ada)');
// 3. PDF
const rows=sql(`SELECT version,status,pdf_sha256 FROM v4_rapor_versi WHERE rapor_id=${rid} ORDER BY version`).split('\n').map(l=>l.split('\t'));
const rstat=sql(`SELECT status FROM v4_rapor WHERE id=${rid}`);console.log('# rapor status',rstat,'versi',rows.map(r=>r[0]+':'+r[1]).join(' '));
for(const [ver,status,sha] of rows){const r=await get('v4_wali4a',`/v4/rapor/${rid}/pdf/${ver}`,true);
 if(status==='Terbit'&&rstat==='Terbit'){ok(r.s===200&&crypto.createHash('sha256').update(r.b).digest('hex')===sha,`PDF v${ver} wali byte = hash kanonis DB`);ok((r.h.get('content-type')||'').startsWith('application/pdf')&&(r.h.get('cache-control')||'').includes('no-store'),`PDF v${ver} content-type pdf + no-store`);
  const r2=await get('v4_wali4a',`/v4/rapor/${rid}/pdf/${ver}`,true);ok(Buffer.compare(r.b,r2.b)===0,`PDF v${ver} unduhan ulang identik`);
  const w=await get('v4_pendidikan',`/v4/rapor/${rid}/pdf/${ver}?petugas=1`,true);ok(w.s===200&&Buffer.compare(w.b,r.b)===0,`PDF v${ver} petugas = wali (identik)`);}
 else ok(r.s===403,`PDF v${ver} (${status}) tidak ditawarkan ke wali → ${r.s}`);
 ok((await get('v4_wali4lain',`/v4/rapor/${rid}/pdf/${ver}`,true)).s===403,`PDF v${ver} wali lain 403`);}
ok(sql(`SELECT COUNT(*) FROM v4_rapor_versi WHERE rapor_id=${rid}`)==String(rows.length),'Tidak ada versi baru dibuat oleh pembacaan');
// 4. relasi dicabut → capability & detail hilang (dipulihkan sesudahnya)
const wid=sql(`SELECT u.wali_id FROM users u WHERE u.username='v4_wali4a'`);const sid=sql(`SELECT santri_id FROM v4_rapor WHERE id=${rid}`);
sql(`UPDATE santri_wali SET archived_at=NOW() WHERE wali_id=${wid} AND santri_id=${sid}`);
try{const c=await get('v4_wali4a','/v4/capabilities');const r=await get('v4_wali4a',`/v4/rapor/${rid}`);const p=await get('v4_wali4a',`/v4/rapor/${rid}/pdf/${rows.at(-1)[0]}`,true);
 ok(r.s===403&&p.s===403,'Relasi dicabut: detail dan PDF 403 seketika');console.log('# caps setelah cabut',JSON.stringify(c.b?.data?.list));}
finally{sql(`UPDATE santri_wali SET archived_at=NULL WHERE wali_id=${wid} AND santri_id=${sid}`);}
ok((await get('v4_wali4a',`/v4/rapor/${rid}`)).s===200,'Relasi dipulihkan: akses kembali');
console.log(fail?`GAGAL ${fail}`:'SEMUA LULUS');process.exit(fail?1:0);
