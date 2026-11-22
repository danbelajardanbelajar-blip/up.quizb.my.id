"""Record staged changed hunks with timestamps and final content hashes; no file contents."""
from datetime import datetime, timezone, timedelta
import hashlib, json, re, subprocess
from pathlib import Path
WIB=timezone(timedelta(hours=7))
def git(*args): return subprocess.check_output(['git',*args],text=True,encoding='utf-8')
root=Path(git('rev-parse','--show-toplevel').strip())
now=datetime.now(WIB); records=[]
for name in git('diff','--cached','--name-only','--diff-filter=ACM').splitlines():
    if name=='CHANGELOG_AUDIT.md' or name.startswith('docs/change-audit/'): continue
    p=root/name; diff=git('diff','--cached','--unified=0','--',name)
    hunks=[]
    for line in diff.splitlines():
        match=re.match(r'@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)',line)
        if match: hunks.append(dict(old_start=int(match[1]),old_count=int(match[2] or 1),new_start=int(match[3]),new_count=int(match[4] or 1),section=match[5].strip()))
    blob=subprocess.check_output(['git','show',':'+name])
    records.append(dict(file=name,last_write_wib=datetime.fromtimestamp(p.stat().st_mtime,WIB).isoformat(timespec='seconds'),sha256=hashlib.sha256(blob).hexdigest(),hunks=hunks))
if not records: raise SystemExit('No staged changes')
destination=root/'docs/change-audit'/('proctoring-'+now.strftime('%Y%m%d-%H%M%S')+'.json');destination.parent.mkdir(parents=True,exist_ok=True)
destination.write_text(json.dumps(dict(recorded_at_wib=now.isoformat(timespec='seconds'),baseline=git('rev-parse','HEAD').strip(),timestamp_note='last_write_wib is the final filesystem write time, not a reconstruction of every prior edit',files=records),ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
log=root/'CHANGELOG_AUDIT.md'
with log.open('a',encoding='utf-8') as out:
    out.write('\n\n### ['+now.strftime('%Y-%m-%d %H:%M:%S')+' WIB] — Verifikasi kamera server v2\n')
    out.write('- Bagian: enrollment, deteksi/matching, nonce/receipt, akses soal, pengumpulan atomik, recovery dan tinjauan guru.\n- Validasi: 16 skenario Node; 37 assertion PHP; 7 inferensi model nyata; 4 integrasi PHP–Python; template Alpine di Edge; API lintas akun. Lint PHP/JS dan diff check lulus.\n- Keterbatasan: tes poller notifikasi lama gagal pada app.v5.js yang tidak diubah. MySQL/kamera nyata online belum diuji; proc_open dinonaktifkan hosting. Jangan pull gate sebelum runtime siap.\n- Manifest waktu penulisan akhir, SHA-256, file dan hunk baris: ['+destination.name+']('+destination.relative_to(root).as_posix()+'). Timestamp tidak mengklaim merekonstruksi riwayat edit yang tidak direkam.\n')
print(destination.relative_to(root).as_posix())
