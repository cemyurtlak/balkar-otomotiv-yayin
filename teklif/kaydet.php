<?php
/* Aracını sat formundan gelen teklifi sunucuya kaydeder (yönetim paneli → Teklifler'de listelenir).
   İstek: JSON (site.js teklifFormu → kayitVerisi). Yanıt: {"ok":true,"no":"251025-AB12"} ya da {"ok":false,"hata":"kod"}.
   Kayıt başarısız olsa da müşterinin WhatsApp mesajı yine gönderilir (form bunu beklemez). */
declare(strict_types=1);
require __DIR__ . '/_ayar.php';
require __DIR__ . '/_db.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function cevap(array $v, int $kod = 200): never { http_response_code($kod); echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cevap(['ok' => false, 'hata' => 'yontem'], 405); }
// yalnızca kendi sitemizden gelen istekler
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

/** Metni temizler: kontrol karakterleri atılır, boşluklar sadeleşir, uzunluk sınırlanır. */
function yazi($v, int $maks = 80): string {
    if (!is_scalar($v)) { return ''; }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '';
    $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $maks);
}
function rakam_y($v, int $maks = 12): string { return substr(preg_replace('/\D/', '', is_scalar($v) ? (string)$v : '') ?? '', 0, $maks); }
function secim($v, array $izin, string $varsayilan = ''): string { $v = is_scalar($v) ? (string)$v : ''; return in_array($v, $izin, true) ? $v : $varsayilan; }

$no = yazi($g['no'] ?? '', 11);
if (!t_kayit_no_gecerli($no)) { cevap(['ok' => false, 'hata' => 'no'], 400); }
$ad = yazi($g['ad'] ?? '', 60); $tel = yazi($g['tel'] ?? '', 20);
$marka = yazi($g['marka'] ?? '', 60); $model = yazi($g['model'] ?? '', 60);
if ($ad === '' || strlen(rakam_y($tel, 20)) < 10 || $marka === '' || $model === '') { cevap(['ok' => false, 'hata' => 'eksik'], 422); }

const PARCA_ADLARI = ['kaput', 'tavan', 'bagaj', 'on_tampon', 'arka_tampon', 'sol_on_camurluk', 'sag_on_camurluk', 'sol_on_kapi', 'sag_on_kapi',
    'sol_arka_kapi', 'sag_arka_kapi', 'sol_arka_camurluk', 'sag_arka_camurluk'];
$parcalar = [];
foreach (PARCA_ADLARI as $p) { $parcalar[$p] = secim($g['parcalar'][$p] ?? '', ['orijinal', 'lokal', 'boyali', 'degisen'], 'orijinal'); }
$foto = yazi($g['foto'] ?? '', 200);
// fotoğraf bağlantısı yalnızca bu sitenin /teklif/?k=<jeton> adresi olabilir
if ($foto !== '' && !(preg_match('~^https?://([^/]+)/(.*/)?teklif/\?k=([a-f0-9]{24})$~', $foto, $m) && strtolower($m[1]) === $host)) { $foto = ''; }

$kayit = [
    'no' => $no, 'tarih' => date('c'), 'durum' => 'yeni', 'galeri_notu' => '',
    'dil' => secim($g['dil'] ?? 'tr', ['tr', 'en', 'ru', 'de'], 'tr'),
    'istek' => secim($g['istek'] ?? '', ['sat', 'takas', 'fiyat'], 'sat'),
    'arac' => [
        'marka' => $marka, 'yil' => rakam_y($g['yil'] ?? '', 4), 'model' => $model, 'versiyon' => yazi($g['versiyon'] ?? '', 90),
        'kasa' => yazi($g['kasa'] ?? '', 40), 'donanim' => yazi($g['donanim'] ?? '', 60),
        'vites' => yazi($g['vites'] ?? '', 40), 'yakit' => yazi($g['yakit'] ?? '', 40), 'motor' => yazi($g['motor'] ?? '', 80),
        'elle' => array_values(array_intersect(['marka', 'yil', 'model', 'versiyon', 'kasa', 'vites', 'yakit', 'motor'], (array)($g['elle'] ?? []))),
        'km' => rakam_y($g['km'] ?? '', 9), 'renk' => yazi($g['renk'] ?? '', 30), 'beklenti' => rakam_y($g['beklenti'] ?? '', 12),
    ],
    'ekspertiz' => [
        'tramer' => secim($g['tramer'] ?? '', ['yok', 'var', 'bilmiyorum'], 'bilmiyorum'), 'tramer_tutar' => rakam_y($g['tramer_tutar'] ?? '', 10),
        'agir' => secim($g['agir'] ?? '', ['yok', 'var', 'bilmiyorum'], 'bilmiyorum'),
        'parca_bilinmiyor' => !empty($g['parca_bilinmiyor']), 'parcalar' => $parcalar, 'hasar_not' => yazi($g['hasar_not'] ?? '', 200),
        'foto' => $foto, 'foto_adet' => min(20, (int)rakam_y($g['foto_adet'] ?? '0', 2)),
    ],
    'kisi' => ['ad' => $ad, 'tel' => $tel, 'not' => yazi($g['not'] ?? '', 500)],
];

t_kayit_klasor_hazirla();
$red = null;
if (!t_hiz_siniri(T_KAYIT_KLASOR, '.sinir.json', T_KAYIT_IP_SAATLIK, T_KAYIT_IP_GUNLUK, T_KAYIT_GENEL_GUNLUK, $red)) { cevap(['ok' => false, 'hata' => $red], 429); }
t_kayit_temizle();

$ay = T_KAYIT_KLASOR . '/' . date('Y-m');
if (!is_dir($ay)) { mkdir($ay, 0755, true); }
$dosya = $ay . '/' . $no . '.json';
if (is_file($dosya)) { cevap(['ok' => true, 'no' => $no, 'tekrar' => true]); }   // aynı teklif iki kez gönderildi
$yazildi = file_put_contents($dosya . '.tmp', json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
if ($yazildi === false || !rename($dosya . '.tmp', $dosya)) { cevap(['ok' => false, 'hata' => 'yazma'], 500); }
// Aşama 49: veritabanına da yaz (başarısız olsa da yukarıdaki kayıt ve WhatsApp mesajı etkilenmez)
db_talep_ekle(['tur' => 'teklif', 'no' => $no, 'dil' => $kayit['dil'], 'ad' => $ad, 'tel' => $tel,
    'ozet' => trim($marka . ' ' . $model . ' ' . $kayit['arac']['yil'] . ($kayit['arac']['km'] !== '' ? ' · ' . $kayit['arac']['km'] . ' km' : '')),
    'mesaj' => mb_substr(is_string($g['wa_mesaj'] ?? null) ? $g['wa_mesaj'] : '', 0, 6000), 'veri' => $kayit, 'sayfa' => 'aracini-sat']);
cevap(['ok' => true, 'no' => $no]);
