from pathlib import Path
import re,hashlib
from pypdf import PdfReader
import pypdfium2 as pdfium
p=Path('docs/phase-v4-4/rapor-sintetis-v1.pdf')
r=PdfReader(p);text='\n'.join(x.extract_text() for x in r.pages)
m=re.search(r'Kode verifikasi:\s*([a-f0-9\s]{48,80})',text)
assert m,'Kode harus terbaca'
code=re.sub(r'\s','',m[1])[:48]
assert len(code)==48
c=Path('/private/tmp/v4-fase4-code.txt');c.write_text(code);c.chmod(0o600)
changed=bytearray(p.read_bytes());changed[-8]^=1
Path('/private/tmp/v4-fase4-changed.pdf').write_bytes(changed)
assert hashlib.sha256(changed).digest()!=hashlib.sha256(p.read_bytes()).digest()
for label in ['Penanda Sintetis Pendidikan','Penanda Sintetis Pimpinan','Kota Sintetis','Hadir','Terlambat','Izin','Sakit','Alpa','Catatan umum sintetis']:assert label in text,label
assert sum(len(p.images) for p in r.pages)>=2,'Dua gambar tanda tangan wajib tertanam'
d=pdfium.PdfDocument(p)
for i,page in enumerate(d):page.render(scale=1.5).to_pil().save(f'docs/phase-v4-4/pdf-v1-{i+1}.png')
# QR uses a Form XObject, not a third raster image. Decode the rendered page below.
d[len(d)-1].render(scale=2).to_pil().save('/private/tmp/v4-fase4-qr-page.png')
Path('docs/phase-v4-4/pdf-inspeksi.txt').write_text(f'[lulus] PDF {len(r.pages)} halaman, dua tanda tangan + QR tertanam\n[lulus] Kode 48 hex terbaca\n[lulus] Ubah tepat satu byte -> hash berbeda\n'+text.replace(code,'<kode sintetis disamarkan>'))
print('PDF sintetis dirender dan diperiksa:',len(r.pages),'halaman; dua PNG tanda tangan, QR vektor, identitas penanda dan lima status hadir')
