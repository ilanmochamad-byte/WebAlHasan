import pathlib,subprocess,tarfile,io,json,re
out=pathlib.Path(__file__).resolve().parent/'audit-codex'
app='com.ilanmochamad.alhasanApps';markers=[b'RAHASIA-AUDIT-NATIVE',b'RAHASIA-IOS-V7',b'SBX V7 Santri',b'Sandbox#123']
pid=subprocess.check_output(['adb','shell','pidof',app],text=True).strip()
logs=subprocess.check_output(['adb','logcat','-d','--pid='+pid])
pathlib.Path('/private/tmp/v7f5-codex-android-log.txt').write_bytes(logs)
blob=subprocess.check_output(['adb','exec-out','run-as',app,'tar','-cf','-','.']);files=[]
with tarfile.open(fileobj=io.BytesIO(blob)) as t:
 for f in t.getmembers():
  if f.isfile():files.append(t.extractfile(f).read())
print('Android log baris:',len(logs.splitlines()),'baris berpenanda:',sum(any(m in line for m in markers) for line in logs.splitlines()))
print('Android pola bearer mentah log/data:',len(re.findall(rb'Bearer [A-Za-z0-9._~-]{15,}',logs)),sum(bool(re.search(rb'Bearer [A-Za-z0-9._~-]{15,}',f)) for f in files))
print('Android berkas data:',len(files),'berkas berpenanda:',sum(any(m in f for m in markers) for f in files))
container=pathlib.Path(subprocess.check_output(['xcrun','simctl','get_app_container','booted',app,'data'],text=True).strip())
files=[f for f in container.rglob('*') if f.is_file()];bad=[]
for f in files:
 try:
  if any(m in f.read_bytes() for m in markers):bad.append(str(f.relative_to(container)))
 except OSError:pass
logs=subprocess.check_output(['xcrun','simctl','spawn','booted','log','show','--style','compact','--last','20m','--predicate','process == "alhasanApps"'],stderr=subprocess.DEVNULL)
pathlib.Path('/private/tmp/v7f5-codex-ios-log.txt').write_bytes(logs)
print('iOS log baris:',len(logs.splitlines()),'baris berpenanda:',sum(any(m in line for m in markers) for line in logs.splitlines()))
print('iOS pola bearer mentah log:',len(re.findall(rb'Bearer [A-Za-z0-9._~-]{15,}',logs)))
print('iOS berkas data:',len(files),'berkas berpenanda:',len(bad))
