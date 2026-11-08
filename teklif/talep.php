<?php
/* Aşama 49 – Randevu formu ve WhatsApp bağlantısı tıklamalarını veritabanına kaydeder (yonetim/talepler.php).
   İstek: JSON {tur, mesaj, sayfa, dil, alanlar?{ad, tel, ...}, web_adresi}. site.js talepKaydet() gönderir
   (navigator.sendBeacon; yanıt beklenmez). WhatsApp her durumda açılır; bu uç başarısız olsa da site etkilenmez.
   Aracını sat ve ön sipariş buraya gelmez: kaydet.php / siparis.php kendi kaydının yanında veritabanına yazar. */
declare(strict_types=1);
require __DIR__ . '/_ayar.php';
require __DIR__ . '/_db.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function cevap(array $v, int $kod = 200): never { http_response_code($kod); echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function yazi($v, int $maks = 80): string {
    if (!is_scalar($v)) { return ''; }
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '';
    $s = trim(preg_replace('/[ \t]+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $maks);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cevap(['ok' => false, 'hata' => 'yontem'], 405); }
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$kaynak = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($kaynak === '' || strtolower((string)parse_url($kaynak, PHP_URL_HOST)) !== strtolower((string)parse_url('http://' . $host, PHP_URL_HOST))) {
    cevap(['ok' => false, 'hata' => 'kaynak'], 403);
}
$govde = (string)file_get_contents('php://input', false, null, 0, T_KAYIT_MAKS_BAYT + 1);
if (strlen($govde) > T_KAYIT_MAKS_BAYT) { cevap(['ok' => false, 'hata' => 'buyuk'], 413); }
$g = json_decode($govde, true);
if (!is_array($g)) { cevap(['ok' => false, 'hata' => 'bicim'], 400); }
if (trim((string)($g['web_adresi'] ?? '')) !== '') { cevap(['ok' => false, 'hata' => 'red'], 400); }   // bot tuzağı

$tur = (string)($g['tur'] ?? '');
if (!array_key_exists($tur, DB_TURLER) || in_array($tur, ['teklif', 'siparis'], true)) { cevap(['ok' => false, 'hata' => 'tur'], 400); }
$form = $tur === 'randevu';

// metin satırlarını koruyarak temizle (WhatsApp mesajı çok satırlıdır)
$mesaj = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', is_string($g['mesaj'] ?? null) ? $g['mesaj'] : '') ?? '';
$mesaj = mb_substr(trim($mesaj), 0, 4000);
if ($mesaj === '') { cevap(['ok' => false, 'hata' => 'eksik'], 422); }

$alanlar = [];
foreach ((is_array($g['alanlar'] ?? null) ? $g['alanlar'] : []) as $k => $v) {
    if (count($alanlar) >= 20 || !is_string($k) || !preg_match('/^[a-z_]{1,30}$/', $k)) { continue; }
    $alanlar[$k] = yazi($v, $k === 'not' ? 500 : 120);
}
$ad = $alanlar['ad'] ?? ''; $tel = $alanlar['tel'] ?? '';
if ($form && ($ad === '' || strlen(preg_replace('/\D/', '', $tel) ?? '') < 10)) { cevap(['ok' => false, 'hata' => 'eksik'], 422); }

// hız sınırı: form gönderimleri ve tıklamalar ayrı sayılır
db_klasor_hazirla();
$red = null;
$izin = $form
    ? t_hiz_siniri(DB_KLASOR, '.sinir-form.json', 6, 20, 300, $red)
    : t_hiz_siniri(DB_KLASOR, '.sinir-tik.json', 40, 150, 3000, $red);
if (!$izin) { cevap(['ok' => false, 'hata' => $red], 429); }

$ilk = trim(strtok(str_replace('*', '', $mesaj), "\n") ?: '');
$ozet = $form ? trim(($alanlar['arac'] ?? '') . ' ' . ($alanlar['tarih'] ?? '') . ' ' . ($alanlar['saat'] ?? '')) : $ilk;
$ok = db_talep_ekle([
    'tur' => $tur, 'dil' => yazi($g['dil'] ?? 'tr', 2), 'ad' => $ad, 'tel' => $tel,
    'ozet' => $ozet !== '' ? $ozet : $ilk, 'mesaj' => $mesaj, 'veri' => (object)$alanlar, 'sayfa' => yazi($g['sayfa'] ?? '', 200),
]);
cevap(['ok' => $ok], $ok ? 200 : 500);
