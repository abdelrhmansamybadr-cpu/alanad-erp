"""Installs the new website at the ROOT of erpsaudi.quadrotechs.com and removes the old site files. Keeps /api (the backend) and /reports.
   Build first (Git Bash: export MSYS_NO_PATHCONV=1):  VITE_BASE=/ npx vite build --outDir dist-root --emptyOutDir
   FTP_PW='...' python deploy_root.py <path-to-dist-root>"""
import io, secrets, sys, zipfile
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
import deploy

dist = Path(sys.argv[1]).resolve()
assert (dist / "index.html").exists()
assert 'src="/assets/' in (dist / "index.html").read_text(encoding="utf-8"), "wrong base path: build with VITE_BASE=/ and MSYS_NO_PATHCONV=1"

HTACCESS = """Options -MultiViews
RewriteEngine On

# the old /app/ address keeps working: it opens the same page at the root
RewriteRule ^app/?(.*)$ /$1 [R=301,L]

# the backend and the shared report files are not part of the web app
RewriteCond %{REQUEST_URI} ^/(api|reports)(/|$)
RewriteRule ^ - [L]

# single-page app: every other address opens index.html, the app decides what to show
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.html [L]

<FilesMatch "\\.php$">
    SetHandler application/x-httpd-php
</FilesMatch>
"""

PHP = r"""<?php
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8'); set_time_limit(0);
$base = __DIR__;
$keep = ['api', 'reports', '.well-known', 'cgi-bin', '.user.ini', 'php.ini', 'error_log'];
function rrmdir(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    foreach (scandir($p) as $e) { if ($e === '.' || $e === '..') continue; rrmdir("$p/$e"); }
    @rmdir($p);
}
$z = new ZipArchive();
if ($z->open("$base/_site___K__.zip") !== true) { echo "ABORT: no package\n"; exit; }
$tmp = "$base/_newsite___K__";
if (is_dir($tmp)) rrmdir($tmp);
mkdir($tmp); $z->extractTo($tmp); $z->close();
if (!is_file("$tmp/index.html") || !is_file("$tmp/htaccess.txt")) { echo "ABORT: package incomplete\n"; exit; }
if (!is_file("$base/api/router.php")) { echo "ABORT: api folder missing, nothing was deleted\n"; exit; }
$removed = 0;
foreach (scandir($base) as $e) {
    if ($e === '.' || $e === '..' || in_array($e, $keep, true)) continue;
    if (str_starts_with($e, '_newsite_') || str_starts_with($e, '_site_') || str_starts_with($e, '_install_')) continue;
    rrmdir("$base/$e"); $removed++;
}
$added = 0;
foreach (scandir($tmp) as $e) {
    if ($e === '.' || $e === '..') continue;
    $dest = $e === 'htaccess.txt' ? '.htaccess' : $e;
    rename("$tmp/$e", "$base/$dest"); $added++;
}
@rmdir($tmp); @unlink("$base/_site___K__.zip");
echo "removed $removed old entries, installed $added new ones; api present: " . (is_file("$base/api/router.php") ? 'yes' : 'NO') . "; reports present: " . (is_dir("$base/reports") ? 'yes' : 'NO') . "\n";
@unlink(__FILE__);
echo "done\n";
"""

key = secrets.token_hex(10)
zp = Path(__file__).resolve().parent / f"_site_{key}.zip"
with zipfile.ZipFile(zp, "w", zipfile.ZIP_DEFLATED) as z:
    for p in dist.rglob("*"):
        if p.is_file() and p.name != ".htaccess":
            z.write(p, p.relative_to(dist).as_posix())
    z.writestr("htaccess.txt", HTACCESS)
f = deploy.ftp()
f.storbinary(f"STOR {deploy.ROOT}/_site_{key}.zip", open(zp, "rb"))
f.storbinary(f"STOR {deploy.ROOT}/_install_{key}.php", io.BytesIO(PHP.replace("__K__", key).encode()))
try:
    print(deploy.call(f"{deploy.SITE}/_install_{key}.php?k={key}").strip())
finally:
    zp.unlink(missing_ok=True)
    try:
        f2 = deploy.ftp()
        for n in (f"_install_{key}.php", f"_site_{key}.zip"):
            try: f2.delete(f"{deploy.ROOT}/{n}")
            except Exception: pass
        print("leftovers:", [n for n in f2.nlst(deploy.ROOT) if key in n])
    except Exception as e:
        print("cleanup check failed:", e)
