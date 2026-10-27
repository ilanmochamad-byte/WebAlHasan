from pathlib import Path
import re,json
root=Path(__file__).resolve().parent
old=root/'audit-codex/regresi';new=root/'audit-codex/f5-1/regresi'
def summary(folder):
 return {m[1]:{'exit':int(m[2]),'lulus':int(m[3]),'gagal':int(m[4])} for line in (folder/'ringkasan.txt').read_text().splitlines() if (m:=re.match(r'^(\S+) exit=(\d+) (\d+)/(\d+)$',line))}
a=summary(old);b=summary(new)
faildiff=[]
for name in sorted(a.keys()&b.keys()):
 left=[l for l in (old/(name+'.txt')).read_text(errors='replace').splitlines() if re.match(r'^\s*\[(gagal|GAGAL|FAIL)\]|^\s*✗',l)]
 right=[l for l in (new/(name+'.txt')).read_text(errors='replace').splitlines() if re.match(r'^\s*\[(gagal|GAGAL|FAIL)\]|^\s*✗',l)]
 if left!=right:faildiff.append({'suite':name,'sebelum':left,'audit':right})
result={'sebelum':len(a),'audit':len(b),'identik_hasil':sum(a[k]==b[k] for k in a.keys()&b.keys()),'berbeda_hasil':[{'suite':k,'sebelum':a[k],'audit':b[k]} for k in a.keys()&b.keys() if a[k]!=b[k]],'belum_dijalankan':sorted(a.keys()-b.keys()),'teks_gagal_berbeda':faildiff}
(root/'audit-codex/f5-1/perbandingan-regresi.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n')
print(json.dumps({k:v for k,v in result.items() if k!='teks_gagal_berbeda'},ensure_ascii=False));print('Suite dengan teks gagal berbeda:',len(faildiff))
