<?php
/* Galeri çalışanının WhatsApp'taki bağlantıdan açtığı fotoğraf sayfası: /teklif/?k=<jeton>
   Jeton tahmin edilemez (96 bit); sayfa arama motorlarına kapalı. Yalnızca Türkçe (galeri iç kullanımı). */
declare(strict_types=1);
require __DIR__ . '/_ayar.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
$k = (string)($_GET['k'] ?? '');
$fotolar = [];
$tarih = null;
if (t_jeton_gecerli($k) && is_dir(T_KLASOR . '/' . $k)) {
    $fotolar = array_map('basename', glob(T_KLASOR . '/' . $k . '/*.jpg') ?: []);
    sort($fotolar);
    $tarih = filemtime(T_KLASOR . '/' . $k);
}
if (!$fotolar) { http_response_code(404); }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Araç fotoğrafları – Balkar Otomotiv</title>
<style>
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: #f6f0e4; color: #141821; }
  header { background: linear-gradient(135deg, #1d3a66, #0c1729 45%, #111 100%); color: #f6f0e4; padding: 20px 16px; border-bottom: 3px solid #c9a24e; }
  header h1 { margin: 0 0 4px; font-size: 1.3rem; } header p { margin: 0; color: #b9c3d6; font-size: .9rem; }
  main { max-width: 1100px; margin: 0 auto; padding: 16px; display: grid; gap: 14px; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); }
  a.f { display: block; border-radius: 12px; overflow: hidden; background: #e7dfcf; box-shadow: 0 10px 24px -16px #142a4c88; }
  a.f img { display: block; width: 100%; height: auto; }
  .bos { grid-column: 1 / -1; text-align: center; padding: 40px 0; color: #6a6558; }
</style></head><body>
<header><h1>Balkar Otomotiv – müşteri araç fotoğrafları</h1>
<p><?= $fotolar ? count($fotolar) . ' fotoğraf · ' . date('d.m.Y H:i', $tarih) . ' · ' . date('d.m.Y', $tarih + T_SAKLAMA_GUN * 86400) . ' tarihinde otomatik silinir' : 'Fotoğraf bulunamadı' ?></p></header>
<main>
<?php foreach ($fotolar as $f): $u = '../veri/teklif/' . $k . '/' . rawurlencode($f); ?>
  <a class="f" href="<?= h($u) ?>" target="_blank" rel="noopener"><img src="<?= h($u) ?>" alt="Araç fotoğrafı <?= h($f) ?>" loading="lazy"></a>
<?php endforeach; ?>
<?php if (!$fotolar): ?><p class="bos">Bağlantı geçersiz ya da fotoğrafların saklama süresi (<?= T_SAKLAMA_GUN ?> gün) dolmuş.</p><?php endif; ?>
</main></body></html>
