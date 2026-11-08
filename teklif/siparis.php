<?php
/* Ön sipariş formundan (on-siparis.html) gelen araç arama talebini sunucuya kaydeder → yönetim paneli "Ön siparişler".
   İstek: JSON (site.js siparisFormu → kayitVerisi). Yanıt: {"ok":true,"no":"S251025-AB12"} ya da {"ok":false,"hata":"kod"}.
   Kayıt başarısız olsa da müşterinin WhatsApp mesajı yine açılır (form müşteriye WhatsApp'ı göndermesini söyler). */
declare(strict_types=1);
require __DIR__ . '/_ayar.php';
require __DIR__ . '/_db.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function cevap(array $v, int $kod = 200): never { http_response_code($kod); echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cevap(['ok' => false, 'hata' => 'yontem'], 405); }
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$kaynak = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($kaynak !== '' && strtolower((string)parse_url($kaynak, PHP_URL_HOST)) !== strtolower((string)parse_url('http://' . $host, PHP_URL_HOST))) {
    cevap(['ok' => false, 'hata' => 'kaynak'], 403);
}
$govde = (string)file_get_contents('php://input', false, null, 0, T_KAYIT_MAKS_BAYT + 1);
if (strlen($govde) > T_KAYIT_MAKS_BAYT) { cevap(['ok' => false, 'hata' => 'buyuk'], 413); }
$g = json_decode($govde, true);
if (!is_array($g)) { cevap(['ok' => false, 'hata' => 'bicim'], 400); }
if (trim((string)($g['web_adresi'] ?? '')) !== '') { cevap(['ok' => false, 'hata' => 'red'], 400); }   // bot tuzağı

function yazi($v, int $maks = 80): string {
    if (!is_scalar($v)) { return ''; }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '';
    $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $maks);
}
function rakam_y($v, int $maks = 12): string { return substr(preg_replace('/\D/', '', is_scalar($v) ? (string)$v : '') ?? '', 0, $maks); }
function secim($v, array $izin, string $varsayilan = ''): string { $v = is_scalar($v) ? (string)$v : ''; return in_array($v, $izin, true) ? $v : $varsayilan; }
function coklu($v, array $izin): array { return array_values(array_unique(array_filter(is_array($v) ? $v : [], fn($x) => is_string($x) && in_array($x, $izin, true)))); }
function yil_y($v): string { $y = rakam_y($v, 4); return ($y !== '' && (int)$y >= 1950 && (int)$y <= (int)date('Y') + 1) ? $y : ''; }

$no = yazi($g['no'] ?? '', 12);
if (!s_kayit_no_gecerli($no)) { cevap(['ok' => false, 'hata' => 'no'], 400); }
$ad = yazi($g['ad'] ?? '', 60); $tel = yazi($g['tel'] ?? '', 20);
$butce_max = rakam_y($g['butce_max'] ?? '', 12);
if ($ad === '' || strlen(rakam_y($tel, 20)) < 10 || $butce_max === '') { cevap(['ok' => false, 'hata' => 'eksik'], 422); }
$yil_min = yil_y($g['yil_min'] ?? ''); $yil_max = yil_y($g['yil_max'] ?? '');
if ($yil_min !== '' && $yil_max !== '' && (int)$yil_min > (int)$yil_max) { [$yil_min, $yil_max] = [$yil_max, $yil_min]; }
$odeme = coklu($g['odeme'] ?? [], ['nakit', 'kredi', 'kart', 'takas', 'tasarruf']);

$kayit = [
    'no' => $no, 'tarih' => date('c'), 'durum' => 'yeni', 'galeri_notu' => '',
    'dil' => secim($g['dil'] ?? 'tr', ['tr', 'en', 'ru', 'de'], 'tr'),
    'arac' => [
        'marka' => yazi($g['marka'] ?? '', 60), 'model' => yazi($g['model'] ?? '', 60),   // boş = farketmez
        'elle' => array_values(array_intersect(['marka', 'model'], (array)($g['elle'] ?? []))),
        'yil_min' => $yil_min, 'yil_max' => $yil_max, 'km_max' => rakam_y($g['km_max'] ?? '', 9),
        'kasa' => secim($g['kasa'] ?? '', ['hatchback', 'sedan', 'station', 'suv', 'crossover', 'coupe', 'cabrio', 'roadster', 'mpv', 'minivan', 'pickup', 'panelvan', 'kombi', 'kombivan', 'kamyonet', 'minibus']),
        'vites' => secim($g['vites'] ?? '', ['manuel', 'otomatik']),
        'yakit' => coklu($g['yakit'] ?? [], ['benzin', 'dizel', 'lpg', 'hibrit', 'elektrik']),
        'hasar' => secim($g['hasar'] ?? '', ['agirsiz', 'degisensiz', 'hatasiz']),
        'renk' => yazi($g['renk'] ?? '', 40), 'istekler' => yazi($g['istekler'] ?? '', 200),
    ],
    'butce' => [
        'min' => rakam_y($g['butce_min'] ?? '', 12), 'max' => $butce_max, 'odeme' => $odeme,
        'takas_arac' => in_array('takas', $odeme, true) ? yazi($g['takas_arac'] ?? '', 120) : '',
        'zaman' => secim($g['zaman'] ?? '', ['hemen', 'ay', 'uc', 'acele']),
    ],
    'kisi' => ['ad' => $ad, 'tel' => $tel, 'sehir' => yazi($g['sehir'] ?? '', 40), 'ulasim' => secim($g['ulasim'] ?? '', ['ara', 'wa']), 'not' => yazi($g['not'] ?? '', 500)],
];

s_kayit_klasor_hazirla();
$red = null;
if (!t_hiz_siniri(S_KAYIT_KLASOR, '.sinir.json', S_KAYIT_IP_SAATLIK, S_KAYIT_IP_GUNLUK, S_KAYIT_GENEL_GUNLUK, $red)) { cevap(['ok' => false, 'hata' => $red], 429); }
s_kayit_temizle();

$ay = S_KAYIT_KLASOR . '/' . date('Y-m');
if (!is_dir($ay)) { mkdir($ay, 0755, true); }
$dosya = $ay . '/' . $no . '.json';
if (is_file($dosya)) { cevap(['ok' => true, 'no' => $no, 'tekrar' => true]); }
$yazildi = file_put_contents($dosya . '.tmp', json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
if ($yazildi === false || !rename($dosya . '.tmp', $dosya)) { cevap(['ok' => false, 'hata' => 'yazma'], 500); }
// Aşama 49: veritabanına da yaz (başarısız olsa da yukarıdaki kayıt ve WhatsApp mesajı etkilenmez)
$a = $kayit['arac'];
db_talep_ekle(['tur' => 'siparis', 'no' => $no, 'dil' => $kayit['dil'], 'ad' => $ad, 'tel' => $tel,
    'ozet' => trim(($a['marka'] ?: 'Farketmez') . ' ' . $a['model'] . ' · bütçe ' . number_format((float)$butce_max, 0, ',', '.') . ' TL'),
    'mesaj' => mb_substr(is_string($g['wa_mesaj'] ?? null) ? $g['wa_mesaj'] : '', 0, 6000), 'veri' => $kayit, 'sayfa' => 'on-siparis']);
cevap(['ok' => true, 'no' => $no]);
