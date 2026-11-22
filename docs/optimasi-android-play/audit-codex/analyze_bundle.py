"""Audit ZIP/DEX independen; binary dan mapping tetap di penyimpanan privat.

python3 analyze_bundle.py app.aab --dexdump /path/build-tools/36.0.0/dexdump
Tidak menafsirkan keep-rule statistics sebagai skor final Android vitals.
Gunakan android analyze secara terpisah untuk skor resmi lokal.
"""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import tempfile
import zipfile


def sha(data):
    return hashlib.sha256(data).hexdigest()


def analyze(path, dexdump):
    result = {"file": path.name, "sha256": sha(path.read_bytes()), "bytes": path.stat().st_size}
    with zipfile.ZipFile(path) as archive:
        dex = [entry for entry in archive.infolist() if entry.filename.endswith(".dex")]
        result["dex"] = [{"name": e.filename, "bytes": e.file_size, "sha256": sha(archive.read(e))} for e in dex]
        result["dex_total_bytes"] = sum(e.file_size for e in dex)
        result["native_abis"] = sorted({n.split("/")[-2] for n in archive.namelist() if n.endswith(".so")})
        result["metadata"] = {n: archive.read(n).decode() for n in archive.namelist() if n.endswith(("r8.json", "d8.json", "app-metadata.properties"))}
        mapping = next((n for n in archive.namelist() if n.endswith("/proguard.map")), None)
        classes = {}
        if mapping:
            result["mapping_sha256"] = sha(archive.read(mapping))
            with archive.open(mapping) as stream:
                for line in stream:
                    if line[:1] in (b"#", b" "):
                        continue
                    match = re.fullmatch(rb"(\S+) -> (\S+):\r?\n?", line)
                    if match:
                        classes[match[2].decode()] = match[1].decode()
        calls = []
        if dexdump:
            target = re.compile(r"Landroid/view/Window;\.(getStatusBarColor|setStatusBarColor|setNavigationBarColor|getNavigationBarColor)|Landroid/view/WindowManager\$LayoutParams;\.layoutInDisplayCutoutMode")
            with tempfile.TemporaryDirectory() as temp:
                for entry in dex:
                    source = Path(temp) / Path(entry.filename).name
                    source.write_bytes(archive.read(entry))
                    # DEX memakai modified UTF-8 (termasuk surrogate); simbol API
                    # yang dicari ASCII. Jangan gagalkan scan karena string UI.
                    process = subprocess.Popen([dexdump, "-d", str(source)], stdout=subprocess.PIPE, text=True, errors="replace")
                    host = "?"
                    for line in process.stdout:
                        descriptor = re.search(r"Class descriptor\s+:\s+'L(.*);'", line)
                        if descriptor:
                            host = descriptor[1].replace("/", ".")
                        match = target.search(line)
                        if match and re.search(r"\b(?:invoke-|iput|iget)", line):
                            calls.append({"host": classes.get(host, host), "obfuscated_host": host, "symbol": match[1] or "layoutInDisplayCutoutMode"})
                    if process.wait() != 0:
                        raise RuntimeError("dexdump gagal")
            result["gp2_instruction_count"] = len(calls)
            result["gp2_host_count"] = len({c["host"] for c in calls})
            result["gp2_calls"] = calls
    return result


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("artifacts", type=Path, nargs="+")
    parser.add_argument("--dexdump")
    args = parser.parse_args()
    print(json.dumps([analyze(p, args.dexdump) for p in args.artifacts], indent=2))
