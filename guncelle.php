<?php
/* Balkar Otomotiv – sunucu güncelleme sayfası (Aşama 52).
   Kullanım: https://balkarotomotiv.com/guncelle.php?k=ANAHTAR            → site dosyaları güncellenir (veri/ dokunulmaz)
             https://balkarotomotiv.com/guncelle.php?k=ANAHTAR&veri=araclar → ek olarak veri/araclar.json + .js da güncellenir
                                                                              (sunucudaki eskileri önce veri/yedek/ içine kopyalanır)
   Kaynak: GitHub deposu GUNCELLE_DEPO'nun main dalı (olustur.py --yayin çıktısı, yayin/ klasörü).
   Anahtar: düz hali yalnız Cem'in bilgisayarında (GUNCELLEME_ANAHTARI.txt, GitHub'a gitmez); sunucuda yalnız özeti
   yonetim/guncelle_anahtar.php içinde durur (depoda yok, üzerine yazılmaz). Anahtarsız / yanlış anahtarla 404 döner.
   Hiçbir zaman yazılmayanlar: yonetim/sifre.php, yonetim/guncelle_anahtar.php, yonetim/yz_anahtar.php, veri/ (yukarıdaki istisna dışında). Dosya silmez. */
declare(strict_types=1);
const GUNCELLE_DEPO = 'cemyurtlak/balkar-otomotiv-yayin';
const GUNCELLE_DAL  = 'main';
@set_time_limit(110);
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function bulunamadi(): never { http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><title>404</title>404'; exit; }
$ozet_dosya = __DIR__ . '/yonetim/guncelle_anahtar.php';
$ozet = is_file($ozet_dosya) ? (string)(include $ozet_dosya) : '';
$k = (string)($_GET['k'] ?? '');
if ($ozet === '' || $k === '' || !hash_equals($ozet, hash('sha256', $k))) { bulunamadi(); }

header('Content-Type: text/plain; charset=utf-8');
$kilit = fopen(sys_get_temp_dir() . '/balkar_guncelle.lock', 'c');
if (!$kilit || !flock($kilit, LOCK_EX | LOCK_NB)) { echo "Başka bir güncelleme sürüyor, biraz sonra tekrar deneyin.\n"; exit; }

function indir(string $url, int $sure = 60): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => $sure,
        CURLOPT_USERAGENT => 'balkar-guncelle', CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json']]);
    $v = curl_exec($ch); $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    if ($v === false || $kod !== 200) { throw new RuntimeException("indirilemedi ($kod): $url"); }
    return (string)$v;
}
function temizle(string $d): void {
    if (!is_dir($d)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($d);
}

$veri_araclar = ($_GET['veri'] ?? '') === 'araclar';
$gecici = sys_get_temp_dir() . '/balkar_guncelle_' . bin2hex(random_bytes(4));
try {
    $commit = '?';
    try { $c = json_decode(indir('https://api.github.com/repos/' . GUNCELLE_DEPO . '/commits/' . GUNCELLE_DAL, 20), true);
          $commit = substr((string)($c['sha'] ?? '?'), 0, 7) . ' – ' . (string)($c['commit']['message'] ?? ''); } catch (Throwable $e) { }
    echo "Depo: " . GUNCELLE_DEPO . " (" . GUNCELLE_DAL . "), commit $commit\n";
    $yerel = getenv('BALKAR_GUNCELLE_ZIP');   // yalnız yerel deneme için (sunucuda tanımlı değil)
    $zip = $yerel ? (string)file_get_contents($yerel) : indir('https://codeload.github.com/' . GUNCELLE_DEPO . '/zip/refs/heads/' . GUNCELLE_DAL, 90);
    @mkdir($gecici, 0700, true);
    file_put_contents("$gecici/paket.zip", $zip);
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive(); if ($z->open("$gecici/paket.zip") !== true) { throw new RuntimeException('zip açılamadı'); }
        $z->extractTo("$gecici/x"); $z->close();
    } else { (new PharData("$gecici/paket.zip"))->extractTo("$gecici/x"); }
    $kok = glob("$gecici/x/*", GLOB_ONLYDIR)[0] ?? '';
    if ($kok === '' || !is_file("$kok/tr/index.html")) { throw new RuntimeException('paket beklenen yapıda değil (tr/index.html yok)'); }

    $yazilan = 0; $ayni = 0; $atlanan = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($kok, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) { continue; }
        $yol = str_replace('\\', '/', substr($f->getPathname(), strlen($kok) + 1));
        if (str_contains($yol, '..') || str_starts_with($yol, '.git/') || str_starts_with($yol, '.github/')) { $atlanan++; continue; }
        if (in_array($yol, ['yonetim/sifre.php', 'yonetim/guncelle_anahtar.php', 'yonetim/yz_anahtar.php'], true)) { $atlanan++; continue; }
        if (str_starts_with($yol, 'veri/') && !($veri_araclar && in_array($yol, ['veri/araclar.json', 'veri/araclar.js'], true))) { $atlanan++; continue; }
        $hedef = __DIR__ . '/' . $yol;
        if (is_file($hedef) && hash_file('sha256', $hedef) === hash_file('sha256', $f->getPathname())) { $ayni++; continue; }
        if (str_starts_with($yol, 'veri/') && is_file($hedef)) {   // araç listesinin eski hali yedeğe
            @mkdir(__DIR__ . '/veri/yedek', 0755, true);
            copy($hedef, __DIR__ . '/veri/yedek/' . date('Ymd-His') . '-guncelle-' . basename($yol));
        }
        if (!is_dir(dirname($hedef))) { mkdir(dirname($hedef), 0755, true); }
        if (!copy($f->getPathname(), $hedef . '.yeni') || !rename($hedef . '.yeni', $hedef)) { throw new RuntimeException("yazılamadı: $yol"); }
        echo "  yazıldı: $yol\n"; $yazilan++;
    }
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    echo "\n$yazilan dosya güncellendi, $ayni dosya zaten aynıydı, $atlanan dosya atlandı (veri/ ve gizli dosyalar).\n";
    echo $veri_araclar ? "Araç listesi de güncellendi (eski hali veri/yedek/ içinde).\n" : "Araç listesi ve kayıtlar (veri/) değiştirilmedi.\n";
    echo "BİTTİ ✓\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "HATA: " . $e->getMessage() . "\nSite değişmedi ya da kısmen güncellendi; hata düzeltilip tekrar çalıştırılabilir.\n";
} finally {
    temizle($gecici);
    flock($kilit, LOCK_UN);
}
