import { chromium } from '../../tests/browser/node_modules/playwright/index.mjs';
import fs from 'node:fs';
const base='http://127.0.0.1:8977',user='sbx_v7_wali_a',password='Sandbox#123';
const auth=await fetch(base+'/api/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({username:user,password,device_name:'audit-paritas'})});const token=(await auth.json()).data.token;
const b=await chromium.launch({headless:true});const p=await b.newPage();await p.route('**/*',r=>new URL(r.request().url()).hostname==='127.0.0.1'?r.fallback():r.abort());await p.goto(base+'/portal/index.php');await p.fill('#username',user);await p.fill('#password',password);await Promise.all([p.waitForURL(/portal\/index.php/),p.getByRole('button',{name:'Masuk',exact:true}).click()]);
let failed=0;
for(const [name,file,expected,label] of [['Android','/private/tmp/v7f5-codex-native-id','Diajukan','Diteruskan ke murobi'],['iOS','/private/tmp/v7f5-codex-ios-id','Diajukan','Diteruskan ke murobi']]){
 const id=Number(fs.readFileSync(file,'utf8'));const r=await fetch(base+`/api/v1/v7/izin/pengajuan/${id}`,{headers:{Authorization:'Bearer '+token}});const d=(await r.json()).data;
 await p.goto(base+`/portal/izin_wali_detail.php?id=${id}`);const text=await p.locator('body').innerText();const ok=d.pengajuan.id===id&&d.pengajuan.status===expected&&text.includes(label);failed+=!ok;console.log(`[${ok?'lulus':'gagal'}] ${name} #${id}: API ${d.pengajuan.status}; website menampilkan ${label} pada ID sama`);
}
await b.close();process.exit(failed?1:0);
