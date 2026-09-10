import {launchAudit,base,login} from './audit-runtime.mjs';
import {mkdirSync,writeFileSync} from 'node:fs';

const {browser,page,context}=await launchAudit();
const out=process.env.OUT_DIR??'/tmp/v3-phase2-browser';
mkdirSync(out,{recursive:true});
const checks=[];
const check=(ok,name)=>{checks.push({ok:Boolean(ok),name});console.log(`${ok?'[lulus]':'[gagal]'} ${name}`);};
const tag='WEB-F2-'+Date.now();

async function apiLogin(username){
  const response=await context.request.post(base+'/api/v1/auth/login',{data:{username,password:'Sandbox#123'}});
  const body=await response.json();
  check(response.status()===200&&Boolean(body.data?.token),`${username} login API lama`);
  return {Authorization:'Bearer '+body.data.token};
}

try{
  check((await context.request.get(base+'/api/v1/v3/pelanggaran')).status()===401,'API pelanggaran tanpa token ditolak');
  await login(page,'sbx_pengurus_a');
  for(const width of [1440,375]){
    await page.setViewportSize({width,height:900});
    const response=await page.goto(base+'/portal/v3_pelanggaran.php');
    check(response.status()===200,`${width}px halaman operasional HTTP 200`);
    check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1&&[...document.querySelectorAll('form')].every(e=>e.scrollWidth<=e.clientWidth+1)),`${width}px tanpa luapan horizontal formulir`);
    check(await page.evaluate(()=>[...document.querySelectorAll('form input:not([type=hidden]),form textarea,form select')].every(e=>e.labels?.length)),`${width}px seluruh kontrol formulir berlabel`);
    await page.screenshot({path:`${out}/${width}-pelanggaran.png`,fullPage:true});
  }
  check((await page.locator('body').innerText()).includes('Pelanggaran & poin V3'),'Menu web membuka modul V3');
  const invalidCsrf=await context.request.post(base+'/portal/v3_pelanggaran.php',{form:{aksi:'buat'}});
  check(invalidCsrf.status()===419,'Mutasi web tanpa CSRF ditolak');

  await page.locator('#v3-santri').selectOption({index:1});
  await page.locator('#v3-katalog').selectOption({index:1});
  await page.locator('#v3-tempat').fill('SBX ruang privat '+tag);
  await page.locator('#v3-uraian').fill('SBX uraian privat '+tag+' <script>alert(1)</script>');
  await page.locator('#v3-saksi').fill('SBX saksi privat '+tag);
  await Promise.all([page.waitForURL('**/portal/v3_pelanggaran_detail.php?id=*'),page.getByRole('button',{name:'Simpan catatan'}).click()]);
  const webId=Number(new URL(page.url()).searchParams.get('id'));
  check(webId>0&&(await page.locator('body').innerText()).includes(tag+' <script>alert(1)</script>'),'Pencatatan web tersimpan dan HTML berbahaya menjadi teks');
  await page.screenshot({path:`${out}/detail-pembimbing.png`,fullPage:true});

  await context.clearCookies();await login(page,'sbx_admin');
  const supervised=await page.goto(base+'/portal/v3_pelanggaran_detail.php?id='+webId);
  check(supervised.status()===200&&await page.locator('#corr-point').isVisible(),'Admin murni dapat mengawasi dan memperoleh jalur koreksi khusus');

  await context.clearCookies();await login(page,'sbx_murobi_a');
  const related=await page.goto(base+'/portal/v3_pelanggaran_detail.php?id='+webId);
  check(related.status()===200&&await page.getByRole('button',{name:'Tandai mengetahui'}).isVisible(),'Murobi terkait dapat membaca dan menandai mengetahui');
  await page.locator('#murobi-note').fill('SBX catatan privat '+tag);
  await Promise.all([page.waitForURL('**/portal/v3_pelanggaran_detail.php?id=*'),page.getByRole('button',{name:'Tandai mengetahui'}).click()]);
  check((await page.locator('body').innerText()).includes('Perubahan tersimpan'),'Tanda mengetahui web tersimpan');

  for(const user of ['sbx_murobi_b','sbx_ortu_a','sbx_pengurus_b']){
    await context.clearCookies();await login(page,user);
    const denied=await page.goto(base+'/portal/v3_pelanggaran_detail.php?id='+webId);
    check(denied.status()===403,`${user} ditolak dari detail lintas cakupan`);
  }

  const pembimbing=await apiLogin('sbx_pengurus_a');
  const murobiA=await apiLogin('sbx_murobi_a');
  const murobiB=await apiLogin('sbx_murobi_b');
  const parent=await apiLogin('sbx_ortu_a');
  const outsider=await apiLogin('sbx_pengurus_b');
  const capabilities=await context.request.get(base+'/api/v1/v3/capabilities',{headers:pembimbing});
  const capabilityBody=await capabilities.json();
  check(capabilities.status()===200&&capabilityBody.data.operasional_tersedia===true,'Capability operasional aplikasi tersedia bagi pembimbing');
  const profileResponse=await context.request.get(base+'/api/v1/profile',{headers:pembimbing});
  const profile=(await profileResponse.json()).data;
  check(profileResponse.status()===200&&Object.hasOwn(profile.capabilities,'default_mode')&&Array.isArray(profile.capabilities.menus),'Kontrak profil V1/V2 tetap memuat default_mode dan menus');
  check(profile.capabilities.menus.every(item=>!String(item.key).startsWith('v3')),'Menu aplikasi tetap berasal dari ApiAuthService tanpa menu web V3');

  const optionsResponse=await context.request.get(base+'/api/v1/v3/pelanggaran/options',{headers:pembimbing});
  const options=(await optionsResponse.json()).data;
  check(optionsResponse.status()===200&&options.santri.length>0&&options.katalog.length>0,'Opsi API dibatasi ke cakupan aktif dan katalog aktif');
  const scope=options.santri[0];const catalog=options.katalog[0];const apiTag='API-F2-'+Date.now();
  const payload={santri_id:scope.santri_id,tahun_ajaran_id:scope.tahun_ajaran_id,katalog_id:catalog.id,waktu_kejadian:new Date(Date.now()-60000).toISOString().slice(0,16),tempat:'SBX tempat '+apiTag,uraian:'SBX uraian '+apiTag,saksi:'SBX saksi '+apiTag,lampiran:{nama:'sbx-bukti.pdf',mime:'application/pdf',data_base64:Buffer.from('%PDF-1.4\n%%EOF\n').toString('base64')}};
  const idempotency='browser-'+Date.now()+'-'+Math.random().toString(16).slice(2);
  const createdResponse=await context.request.post(base+'/api/v1/v3/pelanggaran',{headers:{...pembimbing,'Idempotency-Key':idempotency},data:payload});
  const created=(await createdResponse.json()).data;const apiId=Number(created?.pelanggaran?.id);
  check(createdResponse.status()===201&&apiId>0,'POST aplikasi mencatat pelanggaran dengan token dan idempotency key');
  const replay=await context.request.post(base+'/api/v1/v3/pelanggaran',{headers:{...pembimbing,'Idempotency-Key':idempotency},data:payload});
  check(replay.status()===200&&Number((await replay.json()).data?.pelanggaran?.id)===apiId,'Retry API identik me-replay catatan yang sama');
  const detailResponse=await context.request.get(base+'/api/v1/v3/pelanggaran/'+apiId,{headers:pembimbing});
  const detail=(await detailResponse.json()).data;
  check(detailResponse.status()===200&&detail.rekonsiliasi.selisih===0,'Detail API menunjukkan agregat terrekonsiliasi dari ledger');
  check(!Object.hasOwn(detail.pelanggaran,'fingerprint')&&!Object.hasOwn(detail.pelanggaran,'idempotency_key'),'Serializer API tidak membocorkan kolom internal');
  const attachmentId=Number(detail.lampiran?.[0]?.id);
  check(attachmentId>0&&(await context.request.get(base+'/api/v1/v3/lampiran/'+attachmentId,{headers:pembimbing})).status()===200,'Lampiran privat dapat diunduh pelaku dalam cakupan');

  const ackKey='browser-ack-'+Date.now();
  const ack=await context.request.post(base+`/api/v1/v3/pelanggaran/${apiId}/diketahui`,{headers:{...murobiA,'Idempotency-Key':ackKey},data:{catatan:'SBX mengetahui '+apiTag}});
  check(ack.status()===201,'Murobi terkait menandai mengetahui lewat POST bertoken');
  for(const [user,headers] of [['murobi lain',murobiB],['orang tua',parent],['pengurus lain',outsider]]){
    check((await context.request.get(base+'/api/v1/v3/pelanggaran/'+apiId,{headers})).status()===403,`${user} ditolak dari detail API lintas cakupan`);
    check((await context.request.post(base+`/api/v1/v3/pelanggaran/${apiId}/diketahui`,{headers:{...headers,'Idempotency-Key':'deny-'+Date.now()+Math.random()},data:{}})).status()===403,`${user} ditolak dari tanda mengetahui API`);
  }
  check((await context.request.get(base+`/api/v1/v3/pelanggaran/${apiId}/pembatalan`,{headers:pembimbing})).status()===404,'GET tidak dapat membatalkan catatan');
  check((await context.request.get(base+`/api/v1/v3/pelanggaran/${apiId}/diketahui`,{headers:murobiA})).status()===404,'GET tidak dapat menandai mengetahui');
  check((await context.request.get(base+'/api/v1/v3/lampiran/'+attachmentId,{headers:outsider})).status()===403,'Lampiran privat ditolak lintas cakupan');
}finally{
  writeFileSync(`${out}/results.json`,JSON.stringify(checks,null,2));
  await browser.close();
}
if(checks.some(check=>!check.ok))process.exitCode=1;
