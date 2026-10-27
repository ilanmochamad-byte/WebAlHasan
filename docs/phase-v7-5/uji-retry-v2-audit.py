import os, subprocess, time, json, collections, pathlib
root=pathlib.Path(__file__).resolve().parents[2]
db=os.environ['DB_NAME']
if not db.startswith('test_') or not db.endswith('_test'):raise SystemExit(2)
sid=subprocess.check_output(['mysql','-N','-B',db,'-e',"SELECT id FROM santri WHERE nis='V7S0008'"],text=True).strip()
key='codex-f5-v2-'+str(time.time_ns());start=str(int(time.time()*1000)+1500)
cmd=['php',str(root/'tests/v7_phase1_worker.php'),'pengurus','sbx_v7_pembimbing_satu',sid,'2062-06-04','2062-06-05',key,start,'Fixture retry pengurus auditor']
processes=[subprocess.Popen(cmd,cwd=root,env=os.environ,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True) for _ in range(8)]
results=[]
for p in processes:
 out,err=p.communicate(timeout=30)
 try:results.append(json.loads(out))
 except ValueError:results.append({'ok':False,'http':0,'exit':p.returncode})
count=subprocess.check_output(['mysql','-N','-B',db,'-e',f"SELECT COUNT(*) FROM izin_pengajuan WHERE santri_id={int(sid)} AND tgl_izin='2062-06-04'"],text=True).strip()
print('Delapan proses pengurus V2 berkunci/isi sama: '+json.dumps(collections.Counter('sukses' if r.get('ok') else str(r.get('http')) for r in results)))
print('ID sukses unik='+str(len({r.get('id') for r in results if r.get('ok')}))+'; baris='+count)
print(('[lulus] ' if count=='1' else '[gagal] ')+'Tidak ada pengajuan efektif ganda')
errors=sum(1 for r in results if r.get('http')==500)
print(('[terbuka] ' if errors else '[lulus] ')+f'Retry pengurus V2 menghasilkan {errors} respons 500 pada putaran ini (T-1)')
