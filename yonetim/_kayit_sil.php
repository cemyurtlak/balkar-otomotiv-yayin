<?php
/* Balkar Otomotiv – müşteri kaydını her yerden birlikte silme (Aşama 56).
   Aracını sat teklifi ve ön sipariş iki yerde tutulur (Aşama 49'dan beri): kendi JSON dosyası (Teklifler / Ön siparişler sayfası)
   ve veritabanındaki kopyası (Tüm kayıtlar). Eskiden biri silinince diğeri kalıyordu; artık hangi sayfadan silinirse silinsin
   ikisi de silinir. Teklifin fotoğraf galerisi (veri/teklif/<jeton>) de silinir.
   Gerektirir: _ortak.php, ../teklif/_ayar.php, ../teklif/_db.php */
declare(strict_types=1);

/** $tur: 'teklif' | 'siparis'. Dönüş: silinenlerin kısa açıklaması (boşsa hiçbir şey bulunamadı). */
function kayit_tamamen_sil(string $tur, string $no): array {
    $silinen = [];
    $gecerli = $tur === 'teklif' ? t_kayit_no_gecerli($no) : ($tur === 'siparis' ? s_kayit_no_gecerli($no) : false);
    if (!$gecerli) { return $silinen; }
    $klasor = $tur === 'teklif' ? TEKLIF_KLASOR : SIPARIS_KLASOR;
    foreach (glob($klasor . '/*/' . $no . '.json') ?: [] as $f) {
        $v = json_decode((string)@file_get_contents($f), true);
        // teklifin fotoğraf galerisi: kayıttaki bağlantı .../teklif/?k=<jeton>
        if ($tur === 'teklif' && is_array($v) && preg_match('/[?&]k=([a-f0-9]{24})\b/', (string)($v['foto'] ?? ''), $m)) {
            $d = T_KLASOR . '/' . $m[1];
            if (is_dir($d)) {
                foreach (glob($d . '/*') ?: [] as $x) { @unlink($x); }
                foreach (glob($d . '/.*') ?: [] as $x) { if (is_file($x)) { @unlink($x); } }
                if (@rmdir($d)) { $silinen[] = 'fotoğraflar'; }
            }
        }
        if (@unlink($f) || !is_file($f)) { $silinen[] = 'kayıt dosyası'; }
    }
    $pdo = db();
    if ($pdo) {
        $s = $pdo->prepare('DELETE FROM talepler WHERE tur = ? AND no = ?');
        $s->execute([$tur, $no]);
        if ($s->rowCount() > 0) { $silinen[] = 'Tüm kayıtlar kopyası'; }
    }
    // veritabanı açılamadığı dönemde yedek satır dosyasına düşmüş kopyalar (talepler.php sonra içeri alırdı → silinen geri gelirdi)
    foreach (glob(DB_KLASOR . '/talepler-*.jsonl') ?: [] as $jf) {
        $satirlar = file($jf, FILE_IGNORE_NEW_LINES) ?: [];
        $kalan = array_filter($satirlar, function ($s) use ($tur, $no) { $k = json_decode($s, true); return !(is_array($k) && ($k['tur'] ?? '') === $tur && ($k['no'] ?? '') === $no); });
        if (count($kalan) !== count($satirlar)) { file_put_contents($jf, $kalan ? implode("\n", $kalan) . "\n" : '', LOCK_EX); $silinen[] = 'yedek satır'; }
    }
    return $silinen;
}
