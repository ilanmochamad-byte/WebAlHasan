// Real keyboard/confirmation behaviour. All attempted mutations are intercepted.
import{launchAudit,login,base}from'./audit-runtime.mjs';
import{readFileSync,mkdirSync}from'node:fs';
const fixture=JSON.parse(readFileSync(process.env.AUDIT_UI_MANIFEST,'utf8'));
const out=process.env.OUT_DIR??'/tmp/webalhasan-audit-14/interaksi';mkdirSync(out,{recursive:true});
const {browser,page,context}=await launchAudit();page.setDefaultTimeout(15000);let pass=0,fail=0;
function check(ok,label){ok?pass++:fail++;console.log((ok?'[lulus] ':'[gagal] ')+label);}
try{
 await login(page);
 for(const width of[1440,768,390]){
  await page.setViewportSize({width,height:900});
  await page.goto(base+'/admin/admin_wali.php?action=edit&id='+fixture.wali[0]);
  const note=page.locator('.ah-note--warn').filter({hasText:'Identitas ini dipakai bersama'});
  check(await note.locator('li').count()===45,'edit wali: 45 nama sebelum konfirmasi '+width);
  const details=await note.locator('li').evaluateAll(nodes=>nodes.map(x=>({text:x.innerText,visible:x.getClientRects().length>0,clipped:x.scrollWidth>x.clientWidth+1})));
  check(details.length===45&&details.every((x,i)=>x.visible&&!x.clipped&&x.text.includes('Santri '+String(i+1).padStart(2,'0'))),'edit wali: semua nama lengkap/tidak tersembunyi '+width);
  const confirm=page.locator('input[name="konfirmasi_dampak"]');
  check(await confirm.count()===1&&!(await confirm.isChecked()),'konfirmasi wali tidak otomatis tercentang '+width);
  await page.goto(base+'/admin/admin_wali_rekonsiliasi.php?q='+fixture.tag);
  const group=page.locator('.ah-fieldset').filter({hasText:'Nama sama'}).first();
  const text=await group.innerText();check(Array.from({length:45},(_,i)=>fixture.tag+' Santri '+String(i+1).padStart(2,'0')+' Nama panjang untuk pemeriksaan dampak').every(n=>text.includes(n)),'merge wali: 45 nama lengkap sebelum form '+width);
  check(await group.locator('input[name="konfirmasi"]').count()===1&&!(await group.locator('input[name="konfirmasi"]').isChecked()),'konfirmasi merge belum dicentang '+width);
  await page.goto(base+'/admin/admin_pengurus.php?action=create');
  await page.keyboard.press('Tab');check(await page.locator('.ah-skip').evaluate(n=>document.activeElement===n),'tautan lompat menerima fokus pertama '+width);
  await page.keyboard.press('Enter');check(await page.locator('#ah-konten').evaluate(n=>document.activeElement===n),'lompat langsung ke konten '+width);
  if(width<992){
   check(await page.locator('#ah-sidebar').evaluate(n=>n.inert),'laci tertutup tidak masuk urutan Tab '+width);
   await page.locator('#ah-nav-toggle').click();
   check(await page.locator('#ah-sidebar').evaluate(n=>n.contains(document.activeElement))&&await page.locator('#ah-konten').evaluate(n=>n.inert),'fokus pindah ke laci dan latar inert '+width);
   const links=page.locator('#ah-sidebar a[href]');await links.first().focus();await page.keyboard.press('Shift+Tab');check(await links.last().evaluate(n=>document.activeElement===n),'Shift+Tab tetap di laci '+width);
   await page.keyboard.press('Tab');check(await links.first().evaluate(n=>document.activeElement===n),'Tab berputar ke awal laci '+width);
   await page.keyboard.press('Escape');check(await page.locator('#ah-nav-toggle').evaluate(n=>document.activeElement===n)&&!(await page.locator('#ah-konten').evaluate(n=>n.inert)),'Escape menutup dan mengembalikan fokus '+width);
  }
  await page.locator('#nama').focus();await page.keyboard.press('Tab');check(await page.evaluate(()=>{const s=getComputedStyle(document.activeElement);return s.outlineStyle!=='none'&&parseFloat(s.outlineWidth)>=2;}),'fokus keyboard terlihat '+width);
  await page.goto(base+'/admin/admin_akun.php');
  const opener=page.getByRole('button',{name:'Beri Admin…'}).first();await opener.click();await page.waitForFunction(()=>{const m=bootstrap.Modal.getInstance(document.getElementById('adminModal'));return m?._isShown&&!m._isTransitioning;});
  await page.locator('#konfirmasi_admin').focus();await page.keyboard.press('Tab');check(await page.locator('#adminModal').evaluate(n=>n.contains(document.activeElement)),'fokus keyboard dalam dialog admin '+width);
  check((await page.locator('#adminModal').innerText()).includes('administrator')|| (await page.locator('#adminModal').innerText()).includes('admin'),'dialog menjelaskan hak admin '+width);
  await page.evaluate(async()=>{await Promise.all(document.getAnimations().map(a=>a.finished.catch(()=>{})))});await page.addScriptTag({path:process.env.AXE_PATH});const violations=await page.evaluate(async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21aa']}});return r.violations.map(x=>({id:x.id,nodes:x.nodes.map(n=>({target:n.target,summary:n.failureSummary}))}))});check(violations.length===0,'aksesibilitas modal '+width+' '+JSON.stringify(violations));
  await page.locator('#konfirmasi_admin').focus();await page.keyboard.press('Escape');await page.waitForFunction(()=>{const m=bootstrap.Modal.getInstance(document.getElementById('adminModal'));return !m?._isShown&&!m?._isTransitioning;});await page.waitForFunction(el=>document.activeElement===el,await opener.elementHandle());check(await opener.evaluate(n=>document.activeElement===n),'dialog mengembalikan fokus ke pemicu '+width);
 }
 for(const reduced of['no-preference','reduce']){
  await page.emulateMedia({reducedMotion:reduced});await page.setViewportSize({width:390,height:900});await page.goto(base+'/admin/admin_kamar.php');
  await page.locator('#ah-nav-toggle').click();
  const motion=await page.locator('#ah-sidebar').evaluate(n=>({duration:getComputedStyle(n).transitionDuration,reduce:matchMedia('(prefers-reduced-motion: reduce)').matches}));
  const duration=Math.max(...motion.duration.split(',').map(parseFloat));check(reduced==='reduce'?motion.reduce&&duration<0.001:!motion.reduce&&duration>0.01,'laci menghormati preferensi gerak '+reduced);
  await page.keyboard.press('Escape');check(await page.locator('#ah-nav-toggle').getAttribute('aria-expanded')==='false','laci tetap berfungsi '+reduced);
 }
 // Actual dangerous form cancellation must send no request and leave its action usable.
 await page.setViewportSize({width:1440,height:900});
 for(const[route,selector,impact]of[
  ['admin_pengurus.php','form[data-confirm]','penugasan'],['admin_kelas.php','form[data-confirm]','Keanggotaan'],['admin_tahun.php','form[data-confirm]','semester'],['admin_pembimbing.php','form[data-confirm]','Cakupan'],['admin_guru.php','form[data-confirm]','jadwal'],['admin_wali.php','form[data-confirm]','akun'],['admin_master_santri.php','form[data-confirm]','perizinan'],['admin_pengajian.php?tab=jadwal','form[data-confirm]','pertemuan']
 ]){
  await page.goto(base+'/admin/'+route);const form=page.locator(selector).filter({has:page.locator('button')}).last();let message='',posted=0;
  const dialog=async d=>{message=d.message();await d.dismiss()};page.on('dialog',dialog);
  await page.route('**/*',async r=>{if(r.request().method()==='POST'){posted++;return r.fulfill({status:422,body:'Intercepted audit mutation'});}return r.fallback();});
  await form.locator('button').first().click();check(message.toLowerCase().includes(impact.toLowerCase())&&posted===0&&!(await form.locator('button').first().isDisabled()),'batal konfirmasi: dampak jelas, tanpa mutasi '+route);
  await page.unroute('**/*');page.off('dialog',dialog);
 }
 await page.goto(base+'/admin/admin_pengurus.php?action=create');await page.locator('#nama').fill('');await page.locator('#jabatan').fill('Jabatan tetap');
 // Bypass client required only to exercise server validation; server remains authoritative.
 await page.locator('form').filter({has:page.locator('#nama')}).evaluate(f=>f.noValidate=true);
 await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Simpan',exact:true}).click()]);
 check(await page.locator('#jabatan').inputValue()==='Jabatan tetap'&&await page.locator('#nama').getAttribute('aria-invalid')==='true','error server mempertahankan nilai dan atribut invalid');
 check(await page.locator('#nama').evaluate(n=>n.getAttribute('aria-describedby').split(' ').some(id=>document.getElementById(id)?.textContent.includes('Nama'))),'pesan kolom terhubung untuk pembaca layar');
 await page.screenshot({path:out+'/form-invalid.png',fullPage:true});
 await page.setViewportSize({width:390,height:900});await page.goto(base+'/admin/admin_wali.php?action=edit&id='+fixture.wali[0]);await page.screenshot({path:out+'/wali-45-mobile.png',fullPage:true});
}finally{await browser.close();}
console.log(`Total interaksi: ${pass} lulus, ${fail} gagal`);process.exitCode=fail?1:0;
