"""K5-07: validate actual PDF rows against the independent report matrix oracle."""
import json, os, re, subprocess, sys
from pathlib import Path
import pdfplumber
root=Path(__file__).resolve().parent.parent
folder=Path(os.environ['AUDIT_REPORT_PDFS'])
passed=failed=0
def check(ok,label):
 global passed,failed
 passed+=int(ok);failed+=int(not ok)
 print(('[lulus] ' if ok else '[gagal] ')+label,flush=True)
for scope in ['santri','guru','gabungan']:
 oracle=json.loads((folder/(scope+'.json')).read_text())
 for orientation in ['css','lanskap','potret']:
  pdf=folder/(scope+'-'+orientation+'.pdf')
  subprocess.run(['node',str(root/'tests/browser/cetak-pdf.mjs'),str(folder/(scope+'.html')),str(pdf),'--orientasi='+orientation],check=True,capture_output=True)
  with pdfplumber.open(pdf) as document:
   rows=[];text='';footers=True;margins=True;whole_status=True
   for n,page in enumerate(document.pages,1):
    pt=page.extract_text() or '';text+=pt+'\n'
    footers &= f'Halaman {n} dari {len(document.pages)}' in pt
    margins &= all(c['x0']>=25 and c['x1']<=page.width-25 and c['top']>=30 and c['bottom']<=page.height-25 for c in page.chars if c.get('text','').strip())
    for table in page.extract_tables():
     for row in table:
      if len(row)!=10 or not re.fullmatch(r'\d{4}-\d{2}-\d{2}',row[1] or ''): continue
      whole_status &= '\n' not in row[7]
      rows.append([row[1],row[5].split('\n')[0],' '.join(row[6].split()),''.join(row[7].split())])
   check(sorted(rows)==sorted(oracle['expected']) and len(rows)==oracle['count'],scope+'/'+orientation+' semua identitas, tanggal, jenis, status PDF sama dengan oracle')
   check(whole_status,scope+'/'+orientation+' kata status tidak terpotong antarbaris')
   check(footers and 'Halaman 0' not in text,scope+'/'+orientation+' jumlah fisik dan nomor halaman sesuai')
   check(margins,scope+'/'+orientation+' isi berada dalam margin cetak')
   check('Cetak / Simpan PDF' not in text and 'Navigasi utama' not in text and 'Tip cetak:' not in text,scope+'/'+orientation+' kontrol layar dan sidebar tidak tercetak')
print(f'Total matriks PDF: {passed} lulus, {failed} gagal')
sys.exit(bool(failed))
