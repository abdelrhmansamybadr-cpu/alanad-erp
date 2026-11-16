"""Upload ONE report PDF to the erpsaudi site under an unguessable folder and print its link.
FTP password comes only from the FTP_PW environment variable. Touches nothing except <erpsaudi>/reports/<random>/.

  FTP_PW='...' python upload_report.py "C:/Users/abdel/Desktop/ERP For saudi/Alanad-ERP-Status-and-Priorities-AR.pdf"
"""
import ftplib, os, secrets, sys, urllib.request
from pathlib import Path

HOST, USER = "152.239.124.234", "u665632021"
ROOT = "/domains/quadrotechs.com/public_html/erpsaudi"
SITE = "https://erpsaudi.quadrotechs.com"

src = Path(sys.argv[1])
if not src.is_file() or src.suffix.lower() != ".pdf":
    sys.exit("give the path of a .pdf file")
token = secrets.token_urlsafe(18)
f = ftplib.FTP_TLS(HOST, timeout=90)
f.login(USER, os.environ["FTP_PW"])
f.prot_p()
for d in (f"{ROOT}/reports", f"{ROOT}/reports/{token}"):
    try:
        f.mkd(d)
    except ftplib.error_perm:
        pass
name = "Alanad-ERP-Report-AR.pdf"
with open(src, "rb") as fh:
    f.storbinary(f"STOR {ROOT}/reports/{token}/{name}", fh)
# keep directory listing off and search engines away
f.storbinary(f"STOR {ROOT}/reports/{token}/index.html", __import__("io").BytesIO(b"<!doctype html><meta name=robots content=noindex>"))
f.quit()
url = f"{SITE}/reports/{token}/{name}"
req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
r = urllib.request.urlopen(req, timeout=60)
ok = r.status == 200 and int(r.headers.get("Content-Length", 0)) == src.stat().st_size
print(url, "VERIFIED" if ok else "UPLOADED BUT SIZE CHECK FAILED")
