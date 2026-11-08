<?php
/* Balkar Otomotiv – sahibinden eklentisinin veri gönderdiği uç (Aşama 54).
   POST, gövde JSON: { "k": güncelleme anahtarı, "onizle": true|false, "R": {ilanNo: ham ilan}, "hatalar": [...], "surum": "1.0" }
   Anahtar, guncelle.php ile aynıdır (sunucuda yalnız özeti: yonetim/guncelle_anahtar.php). Anahtarsız / yanlışsa 404.
   onizle=true → hiçbir şey yazılmaz, yalnız ne değişeceği döner. Yazmadan önce panelin olağan yedeği alınır (veri/yedek). */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/_aktar.php';
require __DIR__ . '/_sahibinden.php';
require __DIR__ . '/_yapayzeka.php';
@set_time_limit(150);

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
function cevap(int $kod, array $v): never {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$ozet_dosya = __DIR__ . '/guncelle_anahtar.php';
$ozet = is_file($ozet_dosya) ? (string)(include $ozet_dosya) : '';
$ham = (string)file_get_contents('php://input', false, null, 0, 8_000_000);
$g = json_decode($ham, true);
$k = is_array($g) ? (string)($g['k'] ?? '') : '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $ozet === '' || $k === '' || !hash_equals($ozet, hash('sha256', $k))) {
    http_response_code(404); header('Content-Type: text/html; charset=utf-8'); echo '<!doctype html><title>404</title>404'; exit;
}

$kilit = fopen(sys_get_temp_dir() . '/balkar_sahibinden.lock', 'c');
if (!$kilit || !flock($kilit, LOCK_EX | LOCK_NB)) { cevap(409, ['ok' => false, 'hata' => 'Başka bir güncelleme sürüyor.']); }

// yalnız yapay zekâ devamı: sahibinden verisi gönderilmez, açıklaması bekleyen araçlar yazılır (eklenti kalan varsa tekrar çağırır)
if (!empty($g['yz_devam'])) {
    $v = veri_oku();
    $yz = yz_bekleyenleri_doldur($v['araclar'], 50);
    $yazilan = count(array_filter($yz, fn($x) => str_contains($x, 'açıklama yazıldı')));
    if ($yazilan > 0) { veri_yaz($v, 'yapay zekâ açıklaması (eklenti)'); }
    $kalan = count(array_filter($v['araclar'], fn($a) => ($a['yz_durum'] ?? '') === 'bekliyor' && ($a['durum'] ?? 'satista') !== 'satildi'));
    flock($kilit, LOCK_UN);
    cevap(200, ['ok' => true, 'yz' => $yz, 'yazilan' => $yazilan, 'kalan' => $kalan]);
}
$R = is_array($g['R'] ?? null) ? $g['R'] : [];
$okuma_hatasi = array_slice(array_map('strval', (array)($g['hatalar'] ?? [])), 0, 50);
$onizle = !empty($g['onizle']);

$gelen = []; $cevir_hata = [];
foreach ($R as $id => $v) {
    if (!is_array($v)) { continue; }
    try { $gelen[] = sh_ilan_cevir((string)$id, $v); } catch (Throwable $e) { $cevir_hata[] = $e->getMessage(); }
}

$v = veri_oku();
$satista_sh = count(array_filter($v['araclar'], fn($a) => ($a['kaynak'] ?? '') === 'sahibinden' && ($a['durum'] ?? 'satista') !== 'satildi'));
$toplam_hata = count($okuma_hatasi) + count($cevir_hata);
// güvenlik: okuma yarım kaldıysa (giriş sayfası, bot doğrulaması, sayfa yapısı değişti) siteye dokunma,
// yoksa okunamayan ilanlar "satıldı" sayılırdı
if (!$gelen) { cevap(422, ['ok' => false, 'hata' => 'Hiç ilan okunamadı; site değiştirilmedi.', 'hatalar' => array_merge($okuma_hatasi, $cevir_hata)]); }
if ($toplam_hata > 0) { cevap(422, ['ok' => false, 'hata' => "$toplam_hata ilan okunamadı; eksik veriyle siteyi değiştirmemek için güncelleme yapılmadı. Biraz sonra tekrar deneyin.", 'hatalar' => array_merge($okuma_hatasi, $cevir_hata)]); }
if ($satista_sh >= 6 && count($gelen) < $satista_sh / 2 && empty($g['zorla'])) {
    cevap(422, ['ok' => false, 'hata' => 'Sahibinden\'de ' . count($gelen) . " ilan okundu, sitede $satista_sh var. Bu kadar büyük düşüş hatalı okuma olabilir; site değiştirilmedi. Doğruysa eklentide \"Yine de uygula\" ile gönderin."]);
}

[$liste, $rapor] = sh_birlestir($v['araclar'], $gelen);
$degisiklik = count($rapor['eklenen']) + count($rapor['guncellenen']) + count($rapor['satildi']) + count($rapor['geri_geldi']);
@mkdir(YEDEK, 0755, true);
$sonuc = ['ok' => true, 'onizle' => $onizle, 'okunan' => count($gelen), 'rapor' => $rapor,
          'satista' => count(array_filter($liste, fn($a) => ($a['durum'] ?? 'satista') !== 'satildi')), 'yazildi' => false];
if (!$onizle) {   // ham açıklamalar yapay zekâ için saklanır; yeni ilanların açıklaması yazılır (en çok ~50 sn)
    foreach ($R as $id => $ham) { if (is_array($ham)) { yz_ham_kaydet((string)$id, (string)($ham['ac'] ?? '')); } }
    $yz = yz_bekleyenleri_doldur($liste, 50);
    if ($yz) { $sonuc['rapor']['yapay_zeka'] = $yz; $degisiklik += count($yz); }
    elseif (!yz_hazir() && array_filter($liste, fn($a) => ($a['yz_durum'] ?? '') === 'bekliyor')) {
        $sonuc['rapor']['yapay_zeka'] = ['Yapay zekâ anahtarı girilmemiş: yeni ilanların açıklaması boş (panel → Yapay zekâ).'];
    }
}
if (!$onizle && $degisiklik > 0) {
    $v['araclar'] = $liste;
    veri_yaz($v, 'sahibinden eklentisi (' . date('d.m.Y H:i') . ')');
    $sonuc['yazildi'] = true;
}
@file_put_contents(YEDEK . '/sahibinden_son.json', json_encode(['zaman' => date('c'), 'onizle' => $onizle] + $sonuc,
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
flock($kilit, LOCK_UN);
cevap(200, $sonuc);
