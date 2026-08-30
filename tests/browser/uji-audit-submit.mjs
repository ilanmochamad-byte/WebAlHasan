// A-13: observe the real browser form submission; abort before any mutation.
import{launchAudit,login,base}from'./audit-runtime.mjs';
const{browser,page}=await launchAudit();let fail=0,count=0;
function check(ok,label){count++;if(!ok)fail++;console.log((ok?'[lulus] ':'[gagal] ')+label);}
try{
 await login(page);
 await page.goto(base+'/admin/admin_pengajian.php?tab=pertemuan');
 const form=page.locator('form').filter({has:page.locator('#tanggal_pertemuan')});
 const options=await form.locator('#schedule_id option').evaluateAll(nodes=>nodes.map(n=>n.value).filter(Boolean));
 if(!options.length)throw Error('No sandbox schedule');
 await form.locator('#schedule_id').selectOption(options[0]);
 for(const action of ['draft','open']){
  let posted=null;
  await page.route('**/admin/admin_pengajian.php?tab=pertemuan',async route=>{if(route.request().method()!=='POST')return route.continue();posted=new URLSearchParams(route.request().postData());return route.fulfill({status:422,contentType:'text/html',body:'<p>Audit intercepted before mutation</p>'});});
  await form.locator('button[value="'+action+'"]').click();
  await page.waitForLoadState('load');
  check(posted?.get('action')===action,'clicked submitter '+action+' is included');
  check(posted?.get('tab')==='pertemuan'&&!!posted?.get('_csrf'),'tab and CSRF still present');
  await page.unroute('**/admin/admin_pengajian.php?tab=pertemuan');await page.goto(base+'/admin/admin_pengajian.php?tab=pertemuan');await form.locator('#schedule_id').selectOption(options[0]);
 }
 await page.goto(base+'/admin/admin_wali.php');
 const archive=page.locator('form[onsubmit]').first();
 const dismiss=dialog=>dialog.dismiss();page.on('dialog',dismiss);
 await archive.getByRole('button').click();
 check(!(await archive.getByRole('button').isDisabled()),'cancelled confirmation leaves button usable');
 page.off('dialog',dismiss);
}finally{await browser.close();}
console.log(`Total submit browser: ${count-fail} lulus, ${fail} gagal`);process.exitCode=fail?1:0;
