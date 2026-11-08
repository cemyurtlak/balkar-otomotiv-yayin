<?php
/*
 * Balkar Otomotiv – araç detay sayfası için sunucu tarafı paylaşım bilgisi.
 *
 * WhatsApp, Facebook, Instagram, X ve Telegram bağlantı önizlemesi oluştururken JavaScript çalıştırmaz.
 * Bu yüzden ".../tr/arac.html?id=123" paylaşılınca önizlemede o aracın fotoğrafı, adı ve fiyatı
 * görünsün diye .htaccess bu adresi buraya yönlendirir. Dosya, hazır arac.html sayfasını okuyup
 * yalnızca <head> içindeki başlık/açıklama/paylaşım etiketlerini araca göre doldurur ve
 * arama motorları için Car yapılandırılmış verisini ekler. Sayfanın geri kalanı değişmez.
 * PHP çalışmayan yerde (GitHub Pages) sayfa olduğu gibi açılır, yalnızca önizleme genel olur.
 */
declare(strict_types=1);

$DILLER = ['tr', 'en', 'ru', 'de'];
$dil = in_array($_GET['dil'] ?? '', $DILLER, true) ? $_GET['dil'] : 'tr';
$html = @file_get_contents(__DIR__ . "/$dil/arac.html");
if ($html === false) { http_response_code(404); exit; }
header('Content-Type: text/html; charset=utf-8');

$id = preg_replace('/\D/', '', (string)($_GET['id'] ?? ''));
$veri = json_decode((string)@file_get_contents(__DIR__ . '/veri/araclar.json'), true);
$metin = json_decode((string)@file_get_contents(__DIR__ . '/arac_metin.json'), true);
$m = $metin[$dil] ?? null;
$a = null;
foreach (($veri['araclar'] ?? []) as $x) {
    if ((string)($x['id'] ?? '') === $id && ($x['durum'] ?? null) !== 'satildi') { $a = $x; break; }
}
if ($id === '' || !$a || !$m) { echo $html; exit; }

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

function sayi_bicim($n, string $dil): string {
    $ayrac = ['tr' => '.', 'de' => '.', 'en' => ',', 'ru' => ' '][$dil];
    return number_format((float)$n, 0, ',', $ayrac);
}

// site.js seriAdi() ile aynı: "E Serisi" → E-Class / E-класс / E-Klasse
function seri_adi(array $a, string $dil): string {
    $seri = (string)($a['seri'] ?? '');
    if ($dil === 'tr' || !preg_match('/^(.+?) Serisi$/u', $seri, $r)) { return $seri; }
    $x = $r[1];
    $mercedes = stripos((string)$a['marka'], 'mercedes') !== false;
    $bmw = stripos((string)$a['marka'], 'bmw') !== false;
    if ($mercedes) { return ['en' => "$x-Class", 'ru' => "$x-класс", 'de' => "$x-Klasse"][$dil]; }
    return ['en' => "$x Series", 'ru' => "$x серии", 'de' => $bmw ? "{$x}er" : "$x-Serie"][$dil];
}

$site = rtrim((string)$metin['_site'], '/');
$ad = trim(implode(' ', array_filter([(string)$a['yil'], (string)$a['marka'], seri_adi($a, $dil), (string)($a['model'] ?? '')])));
$fiyat = !empty($a['fiyat']) ? sayi_bicim($a['fiyat'], $dil) . ' TL' : '';
$baslik = $ad . ($fiyat ? " – $fiyat" : '') . ' | Balkar Otomotiv';
$aciklama = strtr($m['aciklama'], [
    '{yil}' => (string)$a['yil'],
    '{km}' => sayi_bicim($a['km'] ?? 0, $dil),
    '{yakit}' => $m['yakit'][$a['yakit'] ?? ''] ?? '',
    '{vites}' => $m['vites'][$a['vites'] ?? ''] ?? '',
]);
$adres = "$site/$dil/arac.html?id=" . rawurlencode($id);
$fotolar = array_map(fn($u) => str_replace('/x5_', '/x16_', (string)$u), array_slice($a['fotograflar'] ?? [], 0, 5));
$gorsel = $fotolar[0] ?? null;

// <head> etiketlerini değiştir
// callback kullanılır: başlıktaki $ veya \ işaretleri değiştirme kalıbı olarak yorumlanmasın
$degis = function (string $desen, string $yeni) use (&$html) { $html = preg_replace_callback($desen, fn() => $yeni, $html, 1); };
$degis('#<title>.*?</title>#s', '<title>' . e($baslik) . '</title>');
$degis('#<meta name="description" content="[^"]*">#', '<meta name="description" content="' . e($aciklama) . '">');
$degis('#<link rel="canonical" href="[^"]*">#', '<link rel="canonical" href="' . e($adres) . '">');
foreach (['og:title' => $baslik, 'og:description' => $aciklama, 'og:url' => $adres, 'og:image:alt' => $ad] as $k => $v) {
    $degis('#<meta property="' . preg_quote($k, '#') . '" content="[^"]*">#', '<meta property="' . $k . '" content="' . e($v) . '">');
}
foreach (['twitter:title' => $baslik, 'twitter:description' => $aciklama, 'twitter:image:alt' => $ad] as $k => $v) {
    $degis('#<meta name="' . preg_quote($k, '#') . '" content="[^"]*">#', '<meta name="' . $k . '" content="' . e($v) . '">');
}
if ($gorsel) {
    foreach (['og:image', 'og:image:secure_url'] as $k) {
        $degis('#<meta property="' . $k . '" content="[^"]*">#', '<meta property="' . $k . '" content="' . e($gorsel) . '">');
    }
    $degis('#<meta name="twitter:image" content="[^"]*">#', '<meta name="twitter:image" content="' . e($gorsel) . '">');
    // araç fotoğrafının ölçüsü genel görselden farklı: sabit 1200x630 bilgisini kaldır
    $html = preg_replace('#<meta property="og:image:(width|height)" content="\d+">\s*#', '', $html);
}

// arama motorları için Car yapılandırılmış verisi (site.js bunu görürse tekrar eklemez)
$yakitEn = ['benzin' => 'Gasoline', 'dizel' => 'Diesel', 'hibrit' => 'Hybrid', 'elektrik' => 'Electric', 'lpg' => 'LPG'];
$ld = array_filter([
    '@context' => 'https://schema.org',
    '@type' => ($a['kategori'] ?? '') === 'motosiklet' ? 'Motorcycle' : 'Car',
    'name' => $ad,
    'brand' => ['@type' => 'Brand', 'name' => (string)$a['marka']],
    'model' => trim(seri_adi($a, $dil) . ' ' . ($a['model'] ?? '')),
    'vehicleModelDate' => (string)$a['yil'],
    'mileageFromOdometer' => ['@type' => 'QuantitativeValue', 'value' => (int)($a['km'] ?? 0), 'unitCode' => 'KMT'],
    'fuelType' => $yakitEn[$a['yakit'] ?? ''] ?? null,
    'vehicleTransmission' => $m['vites'][$a['vites'] ?? ''] ?? null,
    'color' => $a['renk'] ?? null,
    'itemCondition' => 'https://schema.org/UsedCondition',
    'description' => $aciklama,
    'url' => $adres,
    'image' => $fotolar,
    'offers' => [
        '@type' => 'Offer', 'price' => (int)($a['fiyat'] ?? 0), 'priceCurrency' => 'TRY',
        'availability' => 'https://schema.org/InStock', 'itemCondition' => 'https://schema.org/UsedCondition', 'url' => $adres,
        'seller' => ['@type' => 'AutoDealer', 'name' => 'Balkar Otomotiv', 'telephone' => (string)$metin['_telefon']],
    ],
], fn($v) => $v !== null && $v !== '' && $v !== []);
$json = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
$degis('#</head>#', '<script type="application/ld+json" data-arac-ld>' . $json . "</script>\n</head>");

echo $html;
