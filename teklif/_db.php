<?php
/* Aşama 49 – Talep veritabanı (SQLite, tek dosya: veri/veritabani/balkar.sqlite).
   Sitedeki her WhatsApp gönderimi (aracını sat, ön sipariş, randevu formu ve WhatsApp bağlantısı tıklamaları)
   WhatsApp'a gitmesinin YANINDA buraya da kaydedilir; mevcut sistem değişmez. Panel: yonetim/talepler.php.
   Kurulum gerekmez: dosya ilk kayıtta oluşur. Sunucuda pdo_sqlite yoksa ya da veritabanı açılamazsa kayıtlar aynı
   klasörde aylık JSON satır dosyalarına (talepler-YYYY-AA.jsonl) yazılır; panel ikisini birden okur.
   Kayıtlar 180 gün sonra silinir (KVKK metninde yazılı; süre değişirse 4 dilde KVKK metni de güncellenmeli). */
declare(strict_types=1);
date_default_timezone_set('Europe/Istanbul');

const DB_KLASOR = __DIR__ . '/../veri/veritabani';
const DB_DOSYA  = DB_KLASOR . '/balkar.sqlite';
const DB_SAKLAMA_GUN = 180;
/* tür anahtarları → panelde gösterilen ad */
const DB_TURLER = [
    'teklif'  => 'Aracını sat',
    'siparis' => 'Ön sipariş',
    'randevu' => 'Randevu',
    'kredi'   => 'Kredi (WhatsApp)',
    'arac'    => 'Araç sorusu (WhatsApp)',
    'arama'   => 'Aradığım araç (WhatsApp)',
    'odeme'   => 'Ödeme seçenekleri (WhatsApp)',
    'genel'   => 'Genel WhatsApp',
];

function db_klasor_hazirla(): void {
    if (!is_dir(DB_KLASOR)) { @mkdir(DB_KLASOR, 0755, true); }
    if (!is_file(DB_KLASOR . '/.htaccess')) { @file_put_contents(DB_KLASOR . '/.htaccess', "Require all denied\n"); }
    if (!is_file(DB_KLASOR . '/index.html')) { @file_put_contents(DB_KLASOR . '/index.html', ''); }
}

/** Veritabanı bağlantısı; açılamazsa null (kayıt JSON satır dosyasına düşer). */
function db(): ?PDO {
    static $pdo = false;
    if ($pdo !== false) { return $pdo; }
    $pdo = null;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) { return null; }
    try {
        db_klasor_hazirla();
        $p = new PDO('sqlite:' . DB_DOSYA, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $p->exec('PRAGMA busy_timeout = 5000');
        $p->exec('PRAGMA journal_mode = WAL');
        $p->exec('CREATE TABLE IF NOT EXISTS talepler (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tarih TEXT NOT NULL,
            tur TEXT NOT NULL,
            no TEXT NOT NULL DEFAULT \'\',
            dil TEXT NOT NULL DEFAULT \'tr\',
            ad TEXT NOT NULL DEFAULT \'\',
            tel TEXT NOT NULL DEFAULT \'\',
            ozet TEXT NOT NULL DEFAULT \'\',
            mesaj TEXT NOT NULL DEFAULT \'\',
            veri TEXT NOT NULL DEFAULT \'{}\',
            sayfa TEXT NOT NULL DEFAULT \'\',
            durum TEXT NOT NULL DEFAULT \'yeni\',
            galeri_notu TEXT NOT NULL DEFAULT \'\'
        )');
        $p->exec('CREATE INDEX IF NOT EXISTS talep_tarih ON talepler (tarih)');
        $p->exec('CREATE INDEX IF NOT EXISTS talep_tur ON talepler (tur)');
        $pdo = $p;
    } catch (Throwable $e) {
        error_log('balkar db: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** Bir talebi kaydeder. Hiçbir durumda hata fırlatmaz (çağıran formun çalışmasını bozmamalı). true = kaydedildi. */
function db_talep_ekle(array $t): bool {
    $kayit = [
        'tarih' => date('c'),
        'tur'   => array_key_exists((string)($t['tur'] ?? ''), DB_TURLER) ? (string)$t['tur'] : 'genel',
        'no'    => mb_substr((string)($t['no'] ?? ''), 0, 20),
        'dil'   => in_array($t['dil'] ?? '', ['tr', 'en', 'ru', 'de'], true) ? $t['dil'] : 'tr',
        'ad'    => mb_substr((string)($t['ad'] ?? ''), 0, 60),
        'tel'   => mb_substr((string)($t['tel'] ?? ''), 0, 20),
        'ozet'  => mb_substr((string)($t['ozet'] ?? ''), 0, 200),
        'mesaj' => mb_substr((string)($t['mesaj'] ?? ''), 0, 6000),
        'veri'  => json_encode($t['veri'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        'sayfa' => mb_substr((string)($t['sayfa'] ?? ''), 0, 200),
    ];
    try {
        $p = db();
        if ($p) {
            $s = $p->prepare('INSERT INTO talepler (tarih, tur, no, dil, ad, tel, ozet, mesaj, veri, sayfa) VALUES (:tarih, :tur, :no, :dil, :ad, :tel, :ozet, :mesaj, :veri, :sayfa)');
            $s->execute($kayit);
            if (random_int(1, 20) === 1) { db_temizle(); }   // ara sıra eski kayıtları sil
            return true;
        }
    } catch (Throwable $e) { error_log('balkar db ekle: ' . $e->getMessage()); }
    // yedek yol: JSON satır dosyası
    try {
        db_klasor_hazirla();
        $kayit['veri'] = json_decode($kayit['veri'], true);
        $satir = json_encode($kayit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        return @file_put_contents(DB_KLASOR . '/talepler-' . date('Y-m') . '.jsonl', $satir, FILE_APPEND | LOCK_EX) !== false;
    } catch (Throwable $e) { return false; }
}

/** 180 günden eski kayıtları siler (veritabanı ve JSON satır dosyaları). */
function db_temizle(): void {
    $sinir = date('c', time() - DB_SAKLAMA_GUN * 86400);
    try { $p = db(); if ($p) { $p->prepare('DELETE FROM talepler WHERE tarih < ?')->execute([$sinir]); } } catch (Throwable $e) { }
    foreach (glob(DB_KLASOR . '/talepler-*.jsonl') ?: [] as $f) {
        if (preg_match('/talepler-(\d{4})-(\d{2})\.jsonl$/', $f, $m) && mktime(0, 0, 0, (int)$m[2] + 1, 1, (int)$m[1]) < time() - DB_SAKLAMA_GUN * 86400) { @unlink($f); }
    }
}
