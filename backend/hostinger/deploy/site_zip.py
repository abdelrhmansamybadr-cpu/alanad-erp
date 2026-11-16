"""Zips a folder ON THE SERVER (one-time PHP file), downloads the single zip to deploy/backups/, then deletes the zip and the PHP file.
   FTP_PW='...' python site_zip.py <ftp-folder> <https-host-that-serves-it> <name> [exclude1,exclude2]
   e.g. python site_zip.py /domains/quadrocloud.net/public_html/erp2 https://erp2.quadrocloud.net erp2 """
import io, secrets, sys, time, urllib.request
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy

folder, host, name = sys.argv[1], sys.argv[2], sys.argv[3]
excl = [e for e in (sys.argv[4].split(",") if len(sys.argv) > 4 else []) if e]
key = secrets.token_hex(8)
PHP = r"""<?php
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8'); set_time_limit(0); ini_set('memory_limit', '1024M');
$excl = json_decode('__EXCL__', true);
$zipPath = __DIR__ . '/_backup___K__.zip';
$z = new ZipArchive(); $z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
$n = 0;
foreach ($it as $f) {
    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(__DIR__) + 1));
    $top = explode('/', $rel)[0];
    if (in_array($top, $excl, true) || str_starts_with($top, '_backup_') || str_starts_with($top, '_zip_')) continue;
    $z->addFile($f->getPathname(), $rel); $n++;
}
$z->close();
echo "zipped $n files, " . round(filesize($zipPath) / 1e6, 1) . " MB\n";
"""
php = PHP.replace("__K__", key).replace("__EXCL__", __import__("json").dumps(excl))
f = deploy.ftp()
f.storbinary(f"STOR {folder}/_zip_{key}.php", io.BytesIO(php.encode()))
try:
    r = urllib.request.urlopen(urllib.request.Request(f"{host}/_zip_{key}.php?k={key}", headers={"User-Agent": "Mozilla/5.0"}), timeout=900)
    print(r.read().decode().strip())
    chunks = []
    f.retrbinary(f"RETR {folder}/_backup_{key}.zip", chunks.append)
    out = Path(__file__).resolve().parent / "backups" / f"{name}-{time.strftime('%Y%m%d-%H%M%S')}.zip"
    out.write_bytes(b"".join(chunks))
    print("saved", out, out.stat().st_size, "bytes")
finally:
    for n in (f"_zip_{key}.php", f"_backup_{key}.zip"):
        try: f.delete(f"{folder}/{n}")
        except Exception: pass
    print("leftovers:", [n for n in f.nlst(folder) if key in n])
