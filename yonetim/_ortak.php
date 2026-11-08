<?php
/* Balkar Otomotiv yönetim paneli – ortak işlevler */
declare(strict_types=1);
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Istanbul');

const VERI_KLASOR = __DIR__ . '/../veri';
const ARAC_JSON   = VERI_KLASOR . '/araclar.json';
const ARAC_JS     = VERI_KLASOR . '/araclar.js';
const YEDEK       = VERI_KLASOR . '/yedek';
const FOTO        = VERI_KLASOR . '/foto';
const SIFRE_DOSYA = __DIR__ . '/sifre.php';
const KATALOG_JSON = VERI_KLASOR . '/katalog.json';         // aracını sat formunun araç kataloğu (katalog.php)
const TEKLIF_KLASOR = VERI_KLASOR . '/teklifler';           // aracını sat teklif kayıtları (teklifler.php)
const SIPARIS_KLASOR = VERI_KLASOR . '/siparisler';         // ön sipariş (araç arama) talepleri (siparisler.php)
const SIPARIS_DURUMLARI = ['yeni' => 'Yeni', 'araniyor' => 'Araç aranıyor', 'onerildi' => 'Araç önerildi', 'satildi' => 'Satış yapıldı', 'kapandi' => 'Kapandı'];
const TEKLIF_DURUMLARI = ['yeni' => 'Yeni', 'arandi' => 'Arandı', 'teklif' => 'Teklif verildi', 'alindi' => 'Araç alındı', 'kapandi' => 'Kapandı'];
const KATEGORILER = ['otomobil' => 'Otomobil', 'suv' => 'Arazi, SUV & Pickup', 'motosiklet' => 'Motosiklet', 'ticari' => 'Ticari araç'];
const YAKITLAR    = ['' => '—', 'benzin' => 'Benzin', 'dizel' => 'Dizel', 'hibrit' => 'Hibrit', 'elektrik' => 'Elektrik', 'lpg' => 'Benzin & LPG'];
const VITESLER    = ['' => '—', 'otomatik' => 'Otomatik', 'manuel' => 'Manuel', 'yari_otomatik' => 'Yarı otomatik'];
const KASALAR     = ['' => '—', 'sedan' => 'Sedan', 'hatchback' => 'Hatchback', 'station' => 'Station wagon', 'suv' => 'SUV', 'coupe' => 'Coupe', 'cabrio' => 'Cabrio', 'mpv' => 'MPV', 'pickup' => 'Pickup'];
const CEKISLER    = ['' => '—', 'onden' => 'Önden çekiş', 'arkadan' => 'Arkadan itiş', '4wd' => '4x4'];

ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS'])) { ini_set('session.cookie_secure', '1'); }
session_name('balkar_yonetim');
session_start();

function h($s): string { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

function csrf(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
    return $_SESSION['csrf'];
}
function csrf_alan(): string { return '<input type="hidden" name="csrf" value="' . csrf() . '">'; }
function csrf_dogrula(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Oturum süresi doldu. Sayfayı yenileyip tekrar deneyin.'); }
}

function sifre_hash(): ?string {
    if (!is_file(SIFRE_DOSYA)) { return null; }
    $h = include SIFRE_DOSYA;
    return is_string($h) && $h !== '' ? $h : null;
}
function giris_var(): bool { return !empty($_SESSION['giris']) && ($_SESSION['zaman'] ?? 0) > time() - 8 * 3600; }

function veri_oku(): array {
    if (!is_file(ARAC_JSON)) { return ['guncelleme' => date('c'), 'kaynak' => '', 'araclar' => []]; }
    $v = json_decode((string)file_get_contents(ARAC_JSON), true);
    return is_array($v) ? $v + ['araclar' => []] : ['guncelleme' => date('c'), 'araclar' => []];
}

/** Veriyi kaydeder: önce yedek alır, sonra JSON ve JS dosyasını atomik yazar. */
function veri_yaz(array $v, string $kaynak): void {
    if (!is_dir(YEDEK)) { mkdir(YEDEK, 0755, true); }
    if (is_file(ARAC_JSON)) {
        copy(ARAC_JSON, YEDEK . '/araclar-' . date('Ymd-His') . '.json');
        $yedekler = glob(YEDEK . '/araclar-*.json') ?: [];
        sort($yedekler);
        while (count($yedekler) > 30) { @unlink(array_shift($yedekler)); }
    }
    $v['guncelleme'] = date('c');
    $v['kaynak'] = $kaynak;
    $v['araclar'] = array_values($v['araclar']);
    $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $js = 'window.ARACLAR=' . json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
    file_put_contents(ARAC_JSON . '.tmp', $json, LOCK_EX); rename(ARAC_JSON . '.tmp', ARAC_JSON);
    file_put_contents(ARAC_JS . '.tmp', $js, LOCK_EX); rename(ARAC_JS . '.tmp', ARAC_JS);
}

function sayi_temizle($s): ?int {
    $s = preg_replace('/[^\d]/', '', (string)$s);
    return $s === '' ? null : (int)$s;
}

/** Türkçe yazımı anahtar biçimine çevirir: "Gümüş Gri" → "gumus_gri" */
function anahtar(string $s): string {
    $s = preg_replace('/(?<=[a-zçğıöşü0-9])(?=[A-ZÇĞİÖŞÜ])/u', '_', $s); // IlanBasligi → Ilan_Basligi
    $s = mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']));
    $s = strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i']);
    return trim(preg_replace('/[^a-z0-9]+/', '_', $s), '_');
}

/** Serbest yazılmış yakıt/vites/kategori değerlerini site anahtarlarına çevirir. */
function yakit_esle(string $s): ?string {
    $k = anahtar($s);
    if ($k === '') { return null; }
    if (str_contains($k, 'lpg')) { return 'lpg'; }
    if (str_contains($k, 'hibrit') || str_contains($k, 'hybrid')) { return 'hibrit'; }
    if (str_contains($k, 'elektrik') || str_contains($k, 'electric')) { return 'elektrik'; }
    if (str_contains($k, 'dizel') || str_contains($k, 'diesel')) { return 'dizel'; }
    if (str_contains($k, 'benzin') || str_contains($k, 'petrol') || str_contains($k, 'gasoline')) { return 'benzin'; }
    return null;
}
function vites_esle(string $s): ?string {
    $k = anahtar($s);
    if ($k === '') { return null; }
    if (str_contains($k, 'yari')) { return 'yari_otomatik'; }
    if (str_contains($k, 'otomatik') || str_contains($k, 'auto')) { return 'otomatik'; }
    if (str_contains($k, 'manuel') || str_contains($k, 'manual') || str_contains($k, 'duz')) { return 'manuel'; }
    return null;
}
function kategori_esle(string $s): string {
    $k = anahtar($s);
    if (str_contains($k, 'motosiklet') || str_contains($k, 'motor')) { return 'motosiklet'; }
    if (str_contains($k, 'suv') || str_contains($k, 'arazi') || str_contains($k, 'pickup')) { return 'suv'; }
    if (str_contains($k, 'ticari') || str_contains($k, 'minivan') || str_contains($k, 'panelvan') || str_contains($k, 'kamyon')) { return 'ticari'; }
    return 'otomobil';
}

function sablon_bas(string $baslik): void { ?>
<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= h($baslik) ?> – Balkar Yönetim</title>
<link rel="icon" href="../varliklar/img/favicon-32.png"><link rel="stylesheet" href="yonetim.css"></head><body>
<header class="y-ust"><a class="y-marka" href="index.php"><img src="../varliklar/img/logo-96.png" alt="" width="36" height="36">Balkar Yönetim</a>
<?php if (giris_var()): $yeni_teklif = yeni_teklif_sayisi(); $yeni_siparis = yeni_siparis_sayisi(); ?><nav><a href="index.php">Araçlar</a><a href="index.php?s=duzenle">Araç ekle</a><a href="index.php?s=aktar">Sahibinden dosyası yükle</a><a href="teklifler.php">Teklifler<?php if ($yeni_teklif): ?> <span class="y-rozet" title="Yeni teklif"><?= $yeni_teklif ?></span><?php endif; ?></a><a href="siparisler.php">Ön siparişler<?php if ($yeni_siparis): ?> <span class="y-rozet" title="Yeni ön sipariş"><?= $yeni_siparis ?></span><?php endif; ?></a><a href="talepler.php">Tüm kayıtlar<?php $yeni_kayit = yeni_kayit_sayisi(); if ($yeni_kayit): ?> <span class="y-rozet" title="Yeni form kaydı (randevu vb.)"><?= $yeni_kayit ?></span><?php endif; ?></a><a href="katalog.php">Araç kataloğu</a><a href="index.php?s=yedek">Yedekler</a><a href="../tr/araclar.html" target="_blank">Siteyi aç</a>
<form method="post" action="index.php?s=cikis"><?= csrf_alan() ?><button>Çıkış</button></form></nav><?php endif; ?></header>
<main class="y-kap">
<?php }
/** Durumu "yeni" olan teklif sayısı (menü rozeti). */
function yeni_teklif_sayisi(): int {
    $n = 0;
    foreach (glob(TEKLIF_KLASOR . '/*/*.json') ?: [] as $f) {
        $v = json_decode((string)@file_get_contents($f), true);
        if (is_array($v) && ($v['durum'] ?? 'yeni') === 'yeni') { $n++; }
    }
    return $n;
}

/** Aşama 49: veritabanındaki durumu "yeni" randevu kayıtları (teklif ve ön siparişin kendi rozeti var; tıklamalar sayılmaz). */
function yeni_kayit_sayisi(): int {
    require_once __DIR__ . '/../teklif/_db.php';
    if (!is_file(DB_DOSYA)) { return 0; }
    try { $p = db(); return $p ? (int)$p->query("SELECT COUNT(*) FROM talepler WHERE durum = 'yeni' AND tur = 'randevu'")->fetchColumn() : 0; }
    catch (Throwable $e) { return 0; }
}

/** Durumu "yeni" olan ön sipariş sayısı (menü rozeti). */
function yeni_siparis_sayisi(): int {
    $n = 0;
    foreach (glob(SIPARIS_KLASOR . '/*/*.json') ?: [] as $f) {
        $v = json_decode((string)@file_get_contents($f), true);
        if (is_array($v) && ($v['durum'] ?? 'yeni') === 'yeni') { $n++; }
    }
    return $n;
}

/* ---------- araç kataloğu (aracını sat formu) */
function katalog_oku(): array {
    if (!is_file(KATALOG_JSON)) { return ['surum' => 1, 'guncelleme' => date('c'), 'markalar' => []]; }
    $v = json_decode((string)file_get_contents(KATALOG_JSON), true);
    return is_array($v) && isset($v['markalar']) && is_array($v['markalar']) ? $v : ['surum' => 1, 'guncelleme' => date('c'), 'markalar' => []];
}
/** Kataloğu kaydeder: önce yedek alır (son 30 yedek tutulur), sıralar, atomik yazar. */
function katalog_yaz(array $k): void {
    if (!is_dir(YEDEK)) { mkdir(YEDEK, 0755, true); }
    if (is_file(KATALOG_JSON)) {
        copy(KATALOG_JSON, YEDEK . '/katalog-' . date('Ymd-His') . '.json');
        $yedekler = glob(YEDEK . '/katalog-*.json') ?: [];
        sort($yedekler);
        while (count($yedekler) > 30) { @unlink(array_shift($yedekler)); }
    }
    $sirala = fn($a, $b) => strcmp(mb_strtolower($a['ad']), mb_strtolower($b['ad']));
    foreach ($k['markalar'] as &$m) { $m['modeller'] = array_values($m['modeller']); usort($m['modeller'], $sirala);
        foreach ($m['modeller'] as &$md) { $md['motorlar'] = array_values($md['motorlar']); } unset($md); }
    unset($m);
    $k['markalar'] = array_values($k['markalar']);
    usort($k['markalar'], $sirala);
    $k['guncelleme'] = date('c');
    $k['panel'] = true;   // panelde düzenlendi: kaynak/katalog betikleri --zorla olmadan üzerine yazmaz
    $json = json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(KATALOG_JSON . '.tmp', $json, LOCK_EX); rename(KATALOG_JSON . '.tmp', KATALOG_JSON);
    katalog_bol($k);
}

/** Siteye giden parçalar: veri/katalog/index.json (marka → model → yıl aralıkları) + marka başına <d>.json (versiyonlar).
    kaynak/katalog/katalog_bol.py ile aynı biçim. */
function katalog_anahtar(string $s): string {
    $s = mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']));
    $s = strtr($s, ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'é' => 'e', '&' => 'e', '°' => 'o']);
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s === '' ? 'marka' : $s;
}
function katalog_aralik_birlestir(array $a): array {
    $a = array_values(array_filter($a, fn($x) => is_array($x) && count($x) === 2));
    usort($a, fn($x, $y) => $x[0] <=> $y[0]); $o = [];
    foreach ($a as $x) {
        $i = count($o) - 1;
        if ($i >= 0 && ($o[$i][1] === null || $x[0] <= $o[$i][1] + 1)) { $o[$i][1] = ($o[$i][1] === null || $x[1] === null) ? null : max($o[$i][1], $x[1]); }
        else { $o[] = [$x[0], $x[1]]; }
    }
    return $o;
}
function katalog_bol(array $k): void {
    $klasor = VERI_KLASOR . '/katalog';
    if (!is_dir($klasor)) { mkdir($klasor, 0755, true); }
    $index = ['guncelleme' => $k['guncelleme'] ?? date('c'), 'markalar' => []]; $kullanilan = []; $yazilan = [];
    foreach ($k['markalar'] as $m) {
        $d = katalog_anahtar($m['ad']); while (isset($kullanilan[$d])) { $d .= '-2'; } $kullanilan[$d] = 1;
        $im = ['ad' => $m['ad'], 'd' => $d, 'modeller' => []];
        foreach ($m['modeller'] as $md) {
            $ar = array_map(fn($x) => $x['yil'], $md['motorlar'] ?? []);
            if (!empty($md['yillar'])) { $ar[] = $md['yillar']; }
            $im['modeller'][] = ['ad' => $md['ad'], 'y' => katalog_aralik_birlestir($ar)];
        }
        $index['markalar'][] = $im;
        $dosya = $klasor . '/' . $d . '.json'; $yazilan[] = basename($dosya);
        file_put_contents($dosya . '.tmp', json_encode(['modeller' => $m['modeller']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($dosya . '.tmp', $dosya);
    }
    file_put_contents($klasor . '/index.json.tmp', json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX); rename($klasor . '/index.json.tmp', $klasor . '/index.json');
    foreach (glob($klasor . '/*.json') ?: [] as $f) { if (basename($f) !== 'index.json' && !in_array(basename($f), $yazilan, true)) { @unlink($f); } }   // silinen markalar
}

function sablon_son(): void { echo "</main></body></html>"; }
function mesaj(): void {
    if (!empty($_SESSION['mesaj'])) { echo '<p class="y-mesaj">' . h($_SESSION['mesaj']) . '</p>'; unset($_SESSION['mesaj']); }
    if (!empty($_SESSION['hata'])) { echo '<p class="y-mesaj y-hata">' . h($_SESSION['hata']) . '</p>'; unset($_SESSION['hata']); }
}
function git(string $u, string $m = '', bool $hata = false): never {
    if ($m !== '') { $_SESSION[$hata ? 'hata' : 'mesaj'] = $m; }
    header('Location: ' . $u); exit;
}
