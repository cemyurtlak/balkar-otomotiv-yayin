<?php
/*
 * Balkar Otomotiv – dinamik site haritası (.htaccess: /sitemap.xml → bu dosya).
 * Sabit sayfalar sitemap_sayfalar.json'dan (olustur.py yazar), araç sayfaları sunucudaki güncel
 * veri/araclar.json'dan gelir. Böylece eklenti/panel ile eklenen araç hemen girer, satılan hemen çıkar.
 * Bir sorun olursa olustur.py'nin ürettiği sabit sitemap.xml gönderilir.
 */
declare(strict_types=1);
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: max-age=3600');
$s = json_decode((string)@file_get_contents(__DIR__ . '/sitemap_sayfalar.json'), true);
if (!is_array($s) || empty($s['site']) || empty($s['diller'])) { readfile(__DIR__ . '/sitemap.xml'); exit; }
$site = rtrim((string)$s['site'], '/');
$diller = $s['diller'];
$dosyalar = [];
foreach (($s['sayfalar'] ?? []) as $f) { $dosyalar[] = [(string)$f, null]; }
$v = json_decode((string)@file_get_contents(__DIR__ . '/veri/araclar.json'), true);
$vt = @filemtime(__DIR__ . '/veri/araclar.json');
foreach (($v['araclar'] ?? []) as $a) {
    $id = preg_replace('/\D/', '', (string)($a['id'] ?? ''));
    if ($id === '' || ($a['durum'] ?? null) === 'satildi') { continue; }
    $dosyalar[] = ["arac.html?id=$id", $vt ? date('Y-m-d', $vt) : null];
}
$e = fn(string $x) => htmlspecialchars($x, ENT_QUOTES | ENT_XML1, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
foreach ($diller as $d) {
    foreach ($dosyalar as [$f, $tarih]) {
        echo '<url><loc>' . $e("$site/$d/$f") . '</loc>';
        if ($tarih) { echo "<lastmod>$tarih</lastmod>"; }
        foreach ($diller as $x) { echo '<xhtml:link rel="alternate" hreflang="' . $x . '" href="' . $e("$site/$x/$f") . '"/>'; }
        echo "</url>\n";
    }
}
echo "</urlset>\n";
