import fs from 'node:fs';
import {chromium} from 'playwright';
const base=process.env.V4_TEST_URL;
if(!/^http:\/\/127\.0\.0\.1:/.test(base))throw Error('Hanya server uji localhost');
const ids=JSON.parse(fs.readFileSync('/private/tmp/v4-fase2-ids.json','utf8'));
const ok=(v,s)=>{if(!v)throw Error(s);console.log('[lulus] '+s);};
const browser=await chromium.launch({headless:true});
try{
 const page=await browser.newPage({viewport:{width:375,height:812}});
 await page.goto(base+'/portal/index.php');await page.locator('[name=username]').fill('v4_guru');await page.locator('[name=password]').fill(process.env.V4_TEST_PASSWORD);await page.locator('button[type=submit]').first().click();
 await page.goto(base+`/portal/nilai_semester.php?rencana_mapel_id=${ids.mapel}&santri_id=${ids.santri}`);
 ok((await page.locator('#hasil-nilai').textContent()).includes('Nilai akhir 85 · predikat B'),'Web guru membaca 85 B');
 await page.locator('[name="komponen[0][catatan]"]').fill('Capaian dikoreksi guru');
 const [bad]=await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Koreksi beralasan',exact:true}).click()]);ok(bad.status()===422,'Web guru koreksi tanpa alasan 422');
 ok(await page.locator('#nilai-form').count()===1&&!/Warning:|Undefined variable/.test(await page.content()),'Formulir tetap tersedia setelah gagal');
 await page.locator('[name="komponen[0][catatan]"]').fill('Capaian dikoreksi guru');await page.locator('[name=alasan]').fill('Penyesuaian catatan guru');await page.getByRole('button',{name:'Koreksi beralasan',exact:true}).click();
 ok(await page.locator('[name="komponen[0][catatan]"]').inputValue()==='Capaian dikoreksi guru','Web guru menyimpan koreksi');
 await page.getByRole('button',{name:'Serahkan nilai',exact:true}).click();ok((await page.locator('.nilai-row h3').allTextContents()).filter(x=>x.includes('Dikirim')).length===4,'Web guru menyerahkan nilai');
 await page.screenshot({path:'docs/phase-v4-2/browser-guru-375.png',fullPage:true});
 for(const query of [`rencana_mapel_id=${ids.other}`,`rencana_mapel_id=${ids.mapel}&santri_id=99999999`,`pengganti=1&rencana_mapel_id=${ids.mapel}`]){ok((await page.goto(base+'/portal/nilai_semester.php?'+query)).status()===403,'Web guru akses silang 403');}
}finally{await browser.close();}
