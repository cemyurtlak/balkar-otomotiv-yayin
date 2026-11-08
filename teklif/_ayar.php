<?php
/* Aracını sat formu – fotoğraf yükleme ayarları ve ortak yardımcılar.
   Sınırlar kötüye kullanıma (çok sayıda / büyük dosya ile sunucuyu doldurma) karşıdır. */
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');

const T_MAKS_FOTO      = 8;                 // bir gönderimde en fazla fotoğraf
const T_MAKS_BOYUT     = 4 * 1024 * 1024;   // dosya başına en fazla 4 MB (tarayıcı zaten küçültüp gönderir)
const T_MAKS_PIKSEL    = 40000000;          // 40 MP üstü reddedilir (sıkıştırma bombası koruması)
const T_KENAR          = 1600;              // sunucuda en uzun kenar 1600 px'e indirilir
const T_KALITE         = 82;                // JPEG kalitesi
const T_IP_SAATLIK     = 5;                 // aynı IP'den saatte en fazla 5 gönderim
const T_IP_GUNLUK      = 15;                // aynı IP'den günde en fazla 15 gönderim
const T_GENEL_GUNLUK   = 200;               // tüm site için günde en fazla 200 gönderim
const T_KOTA           = 1024 * 1024 * 1024;// klasör toplamı en fazla 1 GB
const T_SAKLAMA_GUN    = 30;                // fotoğraflar 30 gün sonra otomatik silinir

define('T_KLASOR', dirname(__DIR__) . '/veri/teklif');

function t_klasor_hazirla(): void {
    if (!is_dir(T_KLASOR)) { mkdir(T_KLASOR, 0755, true); }
    $ht = T_KLASOR . '/.htaccess';
    if (!is_file($ht)) {
        file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh|html?)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    if (!is_file(T_KLASOR . '/index.html')) { file_put_contents(T_KLASOR . '/index.html', ''); }
}

function t_jeton_gecerli(string $k): bool { return (bool)preg_match('/^[a-f0-9]{24}$/', $k); }

/** 30 günden eski gönderim klasörlerini siler. */
function t_temizle(): void {
    $sinir = time() - T_SAKLAMA_GUN * 86400;
    foreach (glob(T_KLASOR . '/*', GLOB_ONLYDIR) ?: [] as $d) {
        if (t_jeton_gecerli(basename($d)) && filemtime($d) < $sinir) {
            foreach (glob($d . '/*') ?: [] as $f) { @unlink($f); }
            @rmdir($d);
        }
    }
}

function t_klasor_boyutu(): int {
    $top = 0;
    foreach (glob(T_KLASOR . '/*/*.jpg') ?: [] as $f) { $top += (int)filesize($f); }
    return $top;
}

/* ---------- Aracını sat teklif kayıtları (Aşama 34): kaydet.php yazar, yönetim paneli (teklifler.php) okur.
   KVKK metninde 180 gün yazılıdır; süre değişirse 4 dilde KVKK metni de güncellenmelidir. */
const T_KAYIT_SAKLAMA_GUN = 180;            // teklif kayıtları 180 gün sonra otomatik silinir
const T_KAYIT_IP_SAATLIK  = 6;              // aynı IP'den saatte en fazla 6 teklif
const T_KAYIT_IP_GUNLUK   = 20;             // aynı IP'den günde en fazla 20 teklif
const T_KAYIT_GENEL_GUNLUK = 300;           // tüm site için günde en fazla 300 teklif
const T_KAYIT_MAKS_BAYT   = 20 * 1024;      // istek gövdesi en fazla 20 KB
define('T_KAYIT_KLASOR', dirname(__DIR__) . '/veri/teklifler');

function t_kayit_no_gecerli(string $no): bool { return (bool)preg_match('/^\d{6}-[A-Z0-9]{4}$/', $no); }

/** Kayıt klasörünü hazırlar (web'den erişim kapalı). */
function t_kayit_klasor_hazirla(): void {
    if (!is_dir(T_KAYIT_KLASOR)) { mkdir(T_KAYIT_KLASOR, 0755, true); }
    $ht = T_KAYIT_KLASOR . '/.htaccess';
    if (!is_file($ht)) { file_put_contents($ht, "Require all denied\n"); }
    if (!is_file(T_KAYIT_KLASOR . '/index.html')) { file_put_contents(T_KAYIT_KLASOR . '/index.html', ''); }
}

/** 180 günden eski teklif kayıtlarını ve boş ay klasörlerini siler. */
function t_kayit_temizle(): void {
    $sinir = time() - T_KAYIT_SAKLAMA_GUN * 86400;
    foreach (glob(T_KAYIT_KLASOR . '/*', GLOB_ONLYDIR) ?: [] as $ay) {
        if (!preg_match('/^\d{4}-\d{2}$/', basename($ay))) { continue; }
        foreach (glob($ay . '/*.json') ?: [] as $f) {
            if (filemtime($f) < $sinir) { @unlink($f); }
        }
        if (!(glob($ay . '/*.json') ?: [])) { @rmdir($ay); }
    }
}

/** IP'nin tuzlu özeti ile basit hız sınırı. $dosya içinde son 24 saatin zamanları tutulur. true = izin var. */
function t_hiz_siniri(string $klasor, string $ad, int $saatlik, int $gunluk, int $genel, ?string &$red = null): bool {
    $tuz_dosya = $klasor . '/.tuz';
    if (!is_file($tuz_dosya)) { file_put_contents($tuz_dosya, bin2hex(random_bytes(16))); }
    $ip = hash('sha256', trim((string)file_get_contents($tuz_dosya)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $fp = fopen($klasor . '/' . $ad, 'c+');
    if (!$fp) { return true; }
    flock($fp, LOCK_EX);
    $sinir = json_decode((string)stream_get_contents($fp), true) ?: [];
    $simdi = time();
    foreach ($sinir as $k => $z) { $sinir[$k] = array_values(array_filter((array)$z, fn($x) => $x > $simdi - 86400)); if (!$sinir[$k]) { unset($sinir[$k]); } }
    $benim = $sinir[$ip] ?? [];
    $red = null;
    if (count(array_filter($benim, fn($x) => $x > $simdi - 3600)) >= $saatlik || count($benim) >= $gunluk) { $red = 'sinir'; }
    elseif (array_sum(array_map('count', $sinir)) >= $genel) { $red = 'yogun'; }
    if (!$red) { $sinir[$ip][] = $simdi; }
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($sinir)); flock($fp, LOCK_UN); fclose($fp);
    return $red === null;
}

/* ---------- Ön sipariş kayıtları (Aşama 39): siparis.php yazar, yönetim paneli (siparisler.php) okur.
   KVKK metninde 180 gün yazılıdır (aracını sat ile aynı süre); süre değişirse 4 dilde KVKK metni de güncellenmelidir. */
const S_KAYIT_SAKLAMA_GUN = 180;
const S_KAYIT_IP_SAATLIK  = 6;              // aynı IP'den saatte en fazla 6 talep
const S_KAYIT_IP_GUNLUK   = 20;             // aynı IP'den günde en fazla 20 talep
const S_KAYIT_GENEL_GUNLUK = 300;           // tüm site için günde en fazla 300 talep
define('S_KAYIT_KLASOR', dirname(__DIR__) . '/veri/siparisler');

function s_kayit_no_gecerli(string $no): bool { return (bool)preg_match('/^S\d{6}-[A-Z0-9]{4}$/', $no); }

function s_kayit_klasor_hazirla(): void {
    if (!is_dir(S_KAYIT_KLASOR)) { mkdir(S_KAYIT_KLASOR, 0755, true); }
    if (!is_file(S_KAYIT_KLASOR . '/.htaccess')) { file_put_contents(S_KAYIT_KLASOR . '/.htaccess', "Require all denied\n"); }
    if (!is_file(S_KAYIT_KLASOR . '/index.html')) { file_put_contents(S_KAYIT_KLASOR . '/index.html', ''); }
}

/** 180 günden eski ön sipariş kayıtlarını ve boş ay klasörlerini siler. */
function s_kayit_temizle(): void {
    $sinir = time() - S_KAYIT_SAKLAMA_GUN * 86400;
    foreach (glob(S_KAYIT_KLASOR . '/*', GLOB_ONLYDIR) ?: [] as $ay) {
        if (!preg_match('/^\d{4}-\d{2}$/', basename($ay))) { continue; }
        foreach (glob($ay . '/*.json') ?: [] as $f) { if (filemtime($f) < $sinir) { @unlink($f); } }
        if (!(glob($ay . '/*.json') ?: [])) { @rmdir($ay); }
    }
}
