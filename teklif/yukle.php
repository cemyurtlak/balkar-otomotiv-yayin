<?php
/* Aracını sat formundan gelen fotoğrafları alır, güvenli biçimde yeniden kaydeder ve tek bir galeri bağlantısı döndürür.
   Yanıt: {"ok":true,"link":"https://.../teklif/?k=...","adet":3}  ya da  {"ok":false,"hata":"kod"} */
declare(strict_types=1);
require __DIR__ . '/_ayar.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function cevap(array $v, int $kod = 200): void { http_response_code($kod); echo json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { cevap(['ok' => false, 'hata' => 'yontem'], 405); }

// yalnızca kendi sitemizden gelen istekler
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$kaynak = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
if ($kaynak !== '' && strtolower((string)parse_url($kaynak, PHP_URL_HOST)) !== strtolower((string)parse_url('http://' . $host, PHP_URL_HOST))) {
    cevap(['ok' => false, 'hata' => 'kaynak'], 403);
}
// bot tuzağı: görünmez alan doldurulmuşsa sessizce reddet
if (trim((string)($_POST['web_adresi'] ?? '')) !== '') { cevap(['ok' => false, 'hata' => 'red'], 400); }

$dosyalar = $_FILES['foto'] ?? null;
if (!$dosyalar || !is_array($dosyalar['name'])) { cevap(['ok' => false, 'hata' => 'dosya_yok'], 400); }
$adet = count($dosyalar['name']);
if ($adet < 1) { cevap(['ok' => false, 'hata' => 'dosya_yok'], 400); }
if ($adet > T_MAKS_FOTO) { cevap(['ok' => false, 'hata' => 'cok_fazla'], 413); }

t_klasor_hazirla();

// --- hız sınırı (IP kalıcı tutulmaz, yalnızca tuzlu özeti; kayıt 1 gün sonra düşer)
$tuz_dosya = T_KLASOR . '/.tuz';
if (!is_file($tuz_dosya)) { file_put_contents($tuz_dosya, bin2hex(random_bytes(16))); }
$ip = hash('sha256', trim((string)file_get_contents($tuz_dosya)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
$fp = fopen(T_KLASOR . '/.sinir.json', 'c+');
flock($fp, LOCK_EX);
$sinir = json_decode((string)stream_get_contents($fp), true) ?: [];
$simdi = time();
foreach ($sinir as $k => $zamanlar) { $sinir[$k] = array_values(array_filter($zamanlar, fn($z) => $z > $simdi - 86400)); if (!$sinir[$k]) { unset($sinir[$k]); } }
$benim = $sinir[$ip] ?? [];
$son_saat = count(array_filter($benim, fn($z) => $z > $simdi - 3600));
$genel = array_sum(array_map('count', $sinir));
$red = null;
if ($son_saat >= T_IP_SAATLIK || count($benim) >= T_IP_GUNLUK) { $red = 'sinir'; }
elseif ($genel >= T_GENEL_GUNLUK) { $red = 'yogun'; }
if (!$red) { $sinir[$ip][] = $simdi; }
ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($sinir)); flock($fp, LOCK_UN); fclose($fp);
if ($red) { cevap(['ok' => false, 'hata' => $red], 429); }

t_temizle();
if (t_klasor_boyutu() > T_KOTA) { cevap(['ok' => false, 'hata' => 'kota'], 507); }

// --- dosyaları doğrula ve GD ile yeniden kaydet (EXIF/konum bilgisi ve gömülü içerik silinir)
$jeton = bin2hex(random_bytes(12));
$hedef = T_KLASOR . '/' . $jeton;
mkdir($hedef, 0755);
$kaydedilen = 0;
for ($i = 0; $i < $adet; $i++) {
    if (($dosyalar['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { continue; }
    $tmp = (string)$dosyalar['tmp_name'][$i];
    if (!is_uploaded_file($tmp) || filesize($tmp) > T_MAKS_BOYUT) { continue; }
    $bilgi = @getimagesize($tmp);
    if (!$bilgi || !in_array($bilgi[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) { continue; }
    [$g, $y] = $bilgi;
    if ($g < 1 || $y < 1 || $g * $y > T_MAKS_PIKSEL) { continue; }
    $kaynak_g = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$kaynak_g) { continue; }
    $oran = min(1, T_KENAR / max($g, $y));
    $yg = max(1, (int)round($g * $oran)); $yy = max(1, (int)round($y * $oran));
    $yeni = imagecreatetruecolor($yg, $yy);
    imagefill($yeni, 0, 0, imagecolorallocate($yeni, 255, 255, 255));
    imagecopyresampled($yeni, $kaynak_g, 0, 0, 0, 0, $yg, $yy, $g, $y);
    $kaydedilen++;
    imagejpeg($yeni, sprintf('%s/%02d.jpg', $hedef, $kaydedilen), T_KALITE);
    imagedestroy($yeni); imagedestroy($kaynak_g);
}
if ($kaydedilen === 0) { @rmdir($hedef); cevap(['ok' => false, 'hata' => 'gecersiz'], 415); }

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$yol = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
cevap(['ok' => true, 'adet' => $kaydedilen, 'link' => ($https ? 'https' : 'http') . '://' . $host . $yol . '/?k=' . $jeton]);
