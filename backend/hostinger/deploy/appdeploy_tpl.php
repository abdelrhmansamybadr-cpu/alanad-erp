<?php
// One-time: install the new web app into the /app folder only (the old site and /api are not touched). Needs the secret key.
if (($_GET['k'] ?? '') !== '__K__') { http_response_code(404); exit; }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(0);
$base = __DIR__;
function rrmdir(string $p): void {
    if (is_link($p) || is_file($p)) { @unlink($p); return; }
    foreach (scandir($p) as $e) { if ($e === '.' || $e === '..') continue; rrmdir("$p/$e"); }
    @rmdir($p);
}
$z = new ZipArchive();
if ($z->open("$base/_app___K__.zip") !== true) { echo "ABORT: no package\n"; exit; }
$tmp = "$base/_newapp";
if (is_dir($tmp)) rrmdir($tmp);
mkdir($tmp);
$z->extractTo($tmp); $z->close();
if (!is_file("$tmp/index.html")) { echo "ABORT: package incomplete\n"; exit; }
if (is_dir("$base/app")) rrmdir("$base/app");
rename($tmp, "$base/app");
@unlink("$base/_app___K__.zip");
echo "app installed: " . count(scandir("$base/app")) . " entries; api present: " . (is_file("$base/api/router.php") ? "yes" : "NO") . "; old site present: " . (is_file("$base/index.html") ? "yes" : "NO") . "\n";
@unlink(__FILE__);
echo "done\n";
