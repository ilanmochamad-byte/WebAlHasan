import {mkdtempSync,mkdirSync,writeFileSync,readFileSync,rmSync} from 'node:fs';
import {execFileSync,spawnSync} from 'node:child_process';
const script='/Users/ilanmochamad/alhasanApps/scripts/versi-aplikasi.mjs';
const dir=mkdtempSync('/private/tmp/pcs-codex-versi-');
const git=(...a)=>execFileSync('git',['-C',dir,...a],{stdio:'pipe'});
let pass=0,fail=0;
const ok=(v,s)=>{console.log(`[${v?'lulus':'gagal'}] ${s}`);v?pass++:fail++;};
const run=(...a)=>spawnSync('node',[script,...a,'--repo',dir,'--dasar','main'],{encoding:'utf8'});
const version=v=>writeFileSync(dir+'/app.json',JSON.stringify({expo:{version:v}}));
try {
 git('init','-q','-b','main'); git('config','user.name','Audit');git('config','user.email','audit@example.invalid');
 version('1.2.0');mkdirSync(dir+'/src');writeFileSync(dir+'/src/a.ts','export const n=1;');git('add','.');git('commit','-qm','baseline');git('checkout','-qb','fitur');
 writeFileSync(dir+'/src/a.ts','export const n=2;');
 ok(run('periksa','--kerja').status===1,'Perubahan kerja tanpa kenaikan ditolak');
 git('commit','-qam','fitur tanpa versi');ok(run('periksa').status===1,'HEAD berperilaku baru tanpa kenaikan ditolak');
 version('1.0.1');git('commit','-qam','turun');const down=run('periksa');ok(down.status===1 && down.stdout.includes('Versi turun'),'HEAD versi turun ditolak');
 ok(run('naik','minor').status===0 && JSON.parse(readFileSync(dir+'/app.json')).expo.version==='1.3.0','Perintah minor menghasilkan 1.3.0');
 ok(run('naik','minor').status===0 && JSON.parse(readFileSync(dir+'/app.json')).expo.version==='1.3.0','Pengulangan tidak menaikkan paket dua kali');
 git('commit','-qam','versi');ok(run('periksa').status===0,'HEAD 1.3.0 lulus');
 writeFileSync(dir+'/src/a.ts','export const n=3;');git('commit','-qam','koreksi');ok(run('periksa').status===0,'Koreksi paket sama tetap satu kenaikan');
 git('checkout','-q','main');version('1.4.0');git('commit','-qam','main lebih baru');git('checkout','-q','fitur');const latest=run('periksa');ok(latest.status===1 && latest.stdout.includes('Versi turun'),'Baseline main lebih baru tidak diabaikan oleh merge-base');
} finally {rmSync(dir,{recursive:true,force:true});}
console.log(`Ringkasan: ${pass} lulus, ${fail} gagal`);process.exitCode=fail?1:0;
