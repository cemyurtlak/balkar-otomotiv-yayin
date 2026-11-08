<?php
/* Balkar Otomotiv – Yapay zekâ ayarları ve ilan açıklaması yazdırma (Aşama 55).
   Anahtarlar yalnız sunucuda (yonetim/yz_anahtar.php); sayfada hiçbir zaman açık gösterilmez. */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/_yapayzeka.php';

if (sifre_hash() === null || !giris_var()) { git('index.php'); }
$_SESSION['zaman'] = time();
$s = $_GET['s'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($post) {
    csrf_dogrula();
    @set_time_limit(150);
    if ($s === 'kaydet') {
        $yeni = [];
        foreach (['gemini', 'nim'] as $p) {
            $k = trim((string)($_POST[$p . '_anahtar'] ?? ''));
            if (!empty($_POST[$p . '_sil'])) { $yeni[$p . '_anahtar'] = ''; }
            elseif ($k !== '') { $yeni[$p . '_anahtar'] = $k; }
            $m = trim((string)($_POST[$p . '_model'] ?? ''));
            if ($m !== '' && preg_match('#^[\w.\-/:]{2,100}$#', $m)) { $yeni[$p . '_model'] = $m; }
        }
        yz_ayar_yaz($yeni);
        git('yapayzeka.php', 'Ayarlar kaydedildi.');
    }
    if ($s === 'dene') {
        $a = yz_ayar(); $sonuc = [];
        $istem = 'Yalnız şu JSON\'u döndür: {"ok":true,"selam":"merhaba"}';
        foreach (['Gemini' => ['gemini_anahtar', 'yz_gemini'], 'NVIDIA' => ['nim_anahtar', 'yz_nim']] as $ad => [$alan, $fn]) {
            if ($a[$alan] === '') { $sonuc[] = "$ad: anahtar girilmemiş"; continue; }
            $t0 = microtime(true);
            try { $j = yz_json($fn($istem, $a, 30)); $sonuc[] = "$ad: çalışıyor ✓ (" . round(microtime(true) - $t0, 1) . ' sn)' . (!empty($j['ok']) ? '' : ' – cevap beklenenden farklı'); }
            catch (Throwable $e) { $sonuc[] = "$ad: HATA – " . $e->getMessage(); }
        }
        git('yapayzeka.php', implode(' · ', $sonuc));
    }
    if ($s === 'yaz') {
        $v = veri_oku();
        $ids = isset($_POST['id']) ? [preg_replace('/\D/', '', (string)$_POST['id'])] : null;
        $rapor = yz_bekleyenleri_doldur($v['araclar'], 100, $ids);
        if (!$rapor) { git('yapayzeka.php', $ids ? 'Bu ilanın sahibinden açıklaması sunucuda yok (eklenti bir kez çalışınca gelir).' : 'Açıklama bekleyen araç yok.', true); }
        veri_yaz($v, 'yapay zekâ açıklaması (panel)');
        git('yapayzeka.php', implode(' · ', $rapor));
    }
}

$a = yz_ayar();
$v = veri_oku();
$maske = fn(string $k) => $k === '' ? 'girilmemiş' : 'kayıtlı (…' . h(mb_substr($k, -4)) . ')';
$DURUM = ['tamam' => 'yazıldı', 'bekliyor' => 'bekliyor', 'ham_yok' => 'sahibinden metni yok'];
sablon_bas('Yapay zekâ'); mesaj(); ?>
<h1>Yapay zekâ ile ilan açıklaması</h1>
<p>Sahibinden eklentisi yeni bir ilan getirdiğinde, ilanın açıklamasından firma tanıtımı ve takas/kredi yazıları ayıklanır, araca özel kısa bir açıklama
<b>4 dilde</b> yazılır ve başlıktaki yazım hataları düzeltilir. Önce <b>Gemini</b> denenir; çalışmazsa ya da kotası dolarsa <b>NVIDIA</b> devreye girer.
Yapay zekâ çalışmazsa ilan yine eklenir, açıklaması sonra yazılır.</p>

<section class="y-kart">
  <h2>Anahtarlar</h2>
  <form method="post" action="yapayzeka.php?s=kaydet" autocomplete="off"><?= csrf_alan() ?>
    <label>1) Google Gemini API anahtarı – <small><?= $maske($a['gemini_anahtar']) ?></small>
      <input type="password" name="gemini_anahtar" placeholder="AIza… (değiştirmek için yapıştırın, boş bırakırsanız eskisi kalır)"></label>
    <label>Gemini modeli <input name="gemini_model" value="<?= h($a['gemini_model']) ?>"></label>
    <?php if ($a['gemini_anahtar'] !== ''): ?><label class="y-onay"><input type="checkbox" name="gemini_sil" value="1"> Gemini anahtarını sil</label><?php endif; ?>
    <label>2) Yedek: NVIDIA NIM API anahtarı – <small><?= $maske($a['nim_anahtar']) ?></small>
      <input type="password" name="nim_anahtar" placeholder="nvapi-… (değiştirmek için yapıştırın, boş bırakırsanız eskisi kalır)"></label>
    <label>NVIDIA modeli <input name="nim_model" value="<?= h($a['nim_model']) ?>"></label>
    <?php if ($a['nim_anahtar'] !== ''): ?><label class="y-onay"><input type="checkbox" name="nim_sil" value="1"> NVIDIA anahtarını sil</label><?php endif; ?>
    <p><button class="y-btn">Kaydet</button></p>
  </form>
  <form method="post" action="yapayzeka.php?s=dene"><?= csrf_alan() ?><button class="y-btn y-btn-cizgi">Bağlantıyı dene</button>
    <small>İkisine de kısa bir deneme isteği gönderir.</small></form>
  <p><small>Anahtarlar yalnız bu sunucuda saklanır (GitHub'a gitmez, sayfada gösterilmez). İlan açıklamaları yazılırken Google'a / NVIDIA'ya
  yalnız araç bilgisi ve sahibinden ilan metni gönderilir; müşteri bilgisi gönderilmez.</small></p>
</section>

<section class="y-kart">
  <h2>Araçlar</h2>
  <form method="post" action="yapayzeka.php?s=yaz"><?= csrf_alan() ?><button class="y-btn">Bekleyenlerin açıklamasını şimdi yaz</button>
    <small>Biri yaklaşık 5–20 saniye sürer.</small></form>
  <div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Araç</th><th>Açıklama (TR)</th><th>Durum</th><th></th></tr>
    <?php foreach ($v['araclar'] as $x): if (($x['durum'] ?? '') === 'satildi') { continue; } ?>
    <tr><td><?= h(trim($x['marka'] . ' ' . $x['seri'] . ' ' . $x['yil'])) ?><br><small><?= h($x['id']) ?></small></td>
      <td><small><?= h(mb_substr((string)($x['aciklama']['tr'] ?? ''), 0, 140)) ?: '—' ?></small></td>
      <td><small><?= h($DURUM[$x['yz_durum'] ?? ''] ?? (($x['aciklama']['tr'] ?? '') !== '' ? 'elle / önceden' : '—')) ?></small></td>
      <td><?php if (($x['kaynak'] ?? '') === 'sahibinden'): ?><form method="post" action="yapayzeka.php?s=yaz" onsubmit="return confirm('Bu aracın açıklaması ve başlığı yapay zekâyla yeniden yazılsın mı? (Eski hali yedekte kalır.)')"><?= csrf_alan() ?>
        <input type="hidden" name="id" value="<?= h($x['id']) ?>"><button class="y-btn-kucuk">Yeniden yaz</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <p><small>"Yeniden yaz" için sunucuda o ilanın sahibinden metni olmalı; eklenti bir kez çalıştığında tüm ilanlarınki kaydedilir.
  Açıklamayı elle düzeltmek için: Araçlar → aracı düzenle.</small></p>
</section>
<?php sablon_son();
