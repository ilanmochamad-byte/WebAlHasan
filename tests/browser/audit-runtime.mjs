// Shared setup for reproducible audit regressions, isolated from user browser sessions.
import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
export const base=process.env.BASE_URL??'http://127.0.0.1:8940';
if(!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)||process.env.PERAPIHAN_AUDIT_DB!=='1')throw Error('Audit requires explicit localhost sandbox opt-in');
export async function launchAudit(){
 const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||undefined});
 const context=await browser.newContext({viewport:{width:1440,height:900}});
 await context.route('**/*',async route=>{
  const url=new URL(route.request().url());if(url.origin===base)return route.continue();
  const paths=[[/bootstrap.*\.min\.css$/,'bootstrap/dist/css/bootstrap.min.css','text/css'],[/bootstrap.*bundle.*\.js$/,'bootstrap/dist/js/bootstrap.bundle.min.js','application/javascript'],[/font-awesome.*all\.min\.css$/,'@fortawesome/fontawesome-free/css/all.min.css','text/css']];
  for(const [re,file,type] of paths)if(re.test(url.href))return route.fulfill({contentType:type,body:readFileSync(new URL('./node_modules/'+file,import.meta.url))});
  if(/\/webfonts\/fa-[\w-]+\.woff2$/.test(url.pathname))return route.fulfill({contentType:'font/woff2',body:readFileSync(new URL('./node_modules/@fortawesome/fontawesome-free/webfonts/'+url.pathname.split('/').pop(),import.meta.url))});
  return route.abort();
 });
 const page=await context.newPage();
 return{browser,context,page};
}
export async function login(page,user='sbx_admin'){
 await page.goto(base+'/portal/');await page.locator('#username').fill(user);await page.locator('#password').fill('Sandbox#123');
 await Promise.all([page.waitForURL('**/portal/index.php'),page.getByRole('button',{name:'Masuk',exact:true}).click()]);
}
