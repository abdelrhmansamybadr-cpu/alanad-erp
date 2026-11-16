"""Deploy helper for erpsaudi.quadrotechs.com (shared hosting, FTPS). The FTP password comes ONLY from the FTP_PW environment variable.

  FTP_PW='...' python deploy.py backup  core/inventory.php router.php          # read-only: saves the live copies to deploy/backups/<time>/
  FTP_PW='...' python deploy.py migrate ../../database/migrations/008_x.sql    # uploads, runs once, deletes its temporary files
  FTP_PW='...' python deploy.py api     core/finance.php handlers/x.php        # uploads api files, checks each is byte-identical, in the order given
  FTP_PW='...' python deploy.py app     ../../../../new\ ERp\ system/Enterprise-ERP-System-Design/dist   # installs the built web app into /app only
  FTP_PW='...' python deploy.py clean                                          # lists any leftover temporary files (there should be none)

Only the erpsaudi folder is touched. Never use anything outside /domains/quadrotechs.com/public_html/erpsaudi.
"""
import ftplib, hashlib, os, secrets, subprocess, sys, time, zipfile, urllib.request
from pathlib import Path

HOST, USER = "152.239.124.234", "u665632021"
ROOT = "/domains/quadrotechs.com/public_html/erpsaudi"
SITE = "https://erpsaudi.quadrotechs.com"
HERE = Path(__file__).resolve().parent
API_SRC = HERE.parent / "api"


def ftp() -> ftplib.FTP_TLS:
    f = ftplib.FTP_TLS(HOST, timeout=90)
    f.login(USER, os.environ["FTP_PW"])
    f.prot_p()
    return f


def get(f, path) -> bytes:
    chunks = []
    f.retrbinary("RETR " + path, chunks.append)
    return b"".join(chunks)


def call(url: str) -> str:
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    try:
        return urllib.request.urlopen(req, timeout=300).read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return f"HTTP {e.code}: " + e.read().decode("utf-8", "replace")[:300]


def cmd_backup(files):
    out = HERE / "backups" / time.strftime("%Y%m%d-%H%M%S")
    out.mkdir(parents=True, exist_ok=True)
    f = ftp()
    for rel in files:
        (out / rel.replace("/", "_")).write_bytes(get(f, f"{ROOT}/api/{rel}"))
        print("backed up", rel)
    print("saved in", out)


def cmd_migrate(sql_path):
    sql = Path(sql_path).resolve()
    key = secrets.token_hex(10)
    runner = (HERE / "mig_tpl.php").read_text(encoding="utf-8").replace("__K__", key)
    f = ftp()
    f.mkd(f"{ROOT}/_mig_{key}")
    f.storbinary("STOR " + f"{ROOT}/_mig_{key}/{sql.name}", open(sql, "rb"))
    f.storbinary("STOR " + f"{ROOT}/_mig_{key}.php", runner.encode("utf-8") and __import__("io").BytesIO(runner.encode("utf-8")))
    try:
        print(call(f"{SITE}/_mig_{key}.php?k={key}").strip()[:600])
    finally:
        for rel in (f"_mig_{key}/{sql.name}", f"_mig_{key}.php"):
            try: f.delete(f"{ROOT}/{rel}")
            except Exception as e: print("could not delete", rel, e)
        try: f.rmd(f"{ROOT}/_mig_{key}")
        except Exception as e: print("folder:", e)
        print("leftovers:", [n for n in f.nlst(ROOT) if key in n])


def cmd_api(files):
    f = ftp()
    for rel in files:
        data = (API_SRC / rel).read_bytes()
        f.storbinary("STOR " + f"{ROOT}/api/{rel}", __import__("io").BytesIO(data))
        same = hashlib.sha256(get(f, f"{ROOT}/api/{rel}")).hexdigest() == hashlib.sha256(data).hexdigest()
        print("api", rel, "identical" if same else "DIFFERENT")
        if not same: sys.exit(1)


def cmd_app(dist):
    dist = Path(dist).resolve()
    assert (dist / "index.html").exists(), "build first: VITE_BASE=/app/ npx vite build"
    key = secrets.token_hex(10)
    zp = HERE / f"_app_{key}.zip"
    with zipfile.ZipFile(zp, "w", zipfile.ZIP_DEFLATED) as z:
        for p in dist.rglob("*"):
            if p.is_file(): z.write(p, p.relative_to(dist).as_posix())
    runner = (HERE / "appdeploy_tpl.php").read_text(encoding="utf-8").replace("__K__", key)
    f = ftp()
    f.storbinary("STOR " + f"{ROOT}/_app_{key}.zip", open(zp, "rb"))
    f.storbinary("STOR " + f"{ROOT}/_appdeploy_{key}.php", __import__("io").BytesIO(runner.encode("utf-8")))
    try:
        print(call(f"{SITE}/_appdeploy_{key}.php?k={key}").strip())
    finally:
        zp.unlink(missing_ok=True)
        for n in (f"_app_{key}.zip", f"_appdeploy_{key}.php"):
            try: f.delete(f"{ROOT}/{n}")
            except Exception: pass   # the installer deletes itself and the zip
        print("leftovers:", [n for n in f.nlst(ROOT) if key in n])


def cmd_clean():
    f = ftp()
    print("temporary files on the server:", [n for n in f.nlst(ROOT) if "/_mig_" in n or "/_app_" in n or "/_appdeploy_" in n or "/_newapp" in n])


if __name__ == "__main__":
    c, rest = sys.argv[1], sys.argv[2:]
    {"backup": lambda: cmd_backup(rest), "migrate": lambda: cmd_migrate(rest[0]), "api": lambda: cmd_api(rest), "app": lambda: cmd_app(rest[0]), "clean": cmd_clean}[c]()
