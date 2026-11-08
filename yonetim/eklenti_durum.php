<?php
/* Balkar Otomotiv – eklenti sağlık durumu (Aşama 57), GitHub Actions günlük kontrolü için.
   POST JSON {"k": güncelleme anahtarı} → {"saglikli": bool, "sorunlar": [...], "ozet": "..."}. Anahtarsız / yanlışsa 404. */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/_eklenti_durum.php';
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$ozet_dosya = __DIR__ . '/guncelle_anahtar.php';
$ozet = is_file($ozet_dosya) ? (string)(include $ozet_dosya) : '';
$g = json_decode((string)file_get_contents('php://input', false, null, 0, 10000), true);
$k = is_array($g) ? (string)($g['k'] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $ozet === '' || $k === '' || !hash_equals($ozet, hash('sha256', $k))) {
    http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><title>404</title>404'; exit;
}
require __DIR__ . '/_yapayzeka.php';
$s = eklenti_saglik();
$v = veri_oku();
$bek = count(array_filter($v['araclar'], fn($a) => ($a['yz_durum'] ?? '') === 'bekliyor' && ($a['durum'] ?? 'satista') !== 'satildi'));
if ($bek >= 5) { $s['notlar'][] = "$bek aracın yapay zekâ açıklaması bekliyor (panel → Yapay zekâ → Bekleyenleri şimdi yaz)."; }
header('Content-Type: application/json; charset=utf-8');
echo json_encode($s, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
