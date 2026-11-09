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
        $hata = [];
        foreach (['gemini', 'gemini2', 'gemini3'] as $n => $p) {
            $k = trim((string)($_POST[$p . '_anahtar'] ?? ''));
            if (!empty($_POST[$p . '_sil'])) { $yeni[$p . '_anahtar'] = ''; continue; }
            if ($k === '') { continue; }   // boş kutu → kayıtlı anahtar aynen kalır
            // tarayıcı şifre kutusuna panel şifresini kendiliğinden doldurmuş olabilir → kaydetme
            $sh = sifre_hash();
            if ($sh !== null && password_verify($k, $sh)) { $hata[] = ($n + 1) . '. kutuya panel şifresi doldurulmuş (tarayıcı otomatik doldurdu); kaydedilmedi'; continue; }
            if (!preg_match('/^AIza[\w\-]{30,60}$/', $k)) { $hata[] = ($n + 1) . '. kutudaki değer Gemini anahtarına benzemiyor ("AIza" ile başlar); kaydedilmedi'; continue; }
            $yeni[$p . '_anahtar'] = $k;
        }
        $m = trim((string)($_POST['gemini_model'] ?? ''));
        if ($m !== '' && preg_match('#^[\w.\-/:, ]{2,300}$#', $m)) { $yeni['gemini_model'] = $m; }
        yz_ayar_yaz($yeni);
        git('yapayzeka.php', $hata ? 'Kaydedildi, ama: ' . implode(' · ', $hata) . '.' : 'Ayarlar kaydedildi.', (bool)$hata);
    }
    if ($s === 'dene') {
        $a = yz_ayar(); $sonuc = [];
        $istem = 'Yalnız şu JSON\'u döndür: {"ok":true,"selam":"merhaba"}';
        // her anahtarı tek tek dener (Gemini: ilk iki model; süre ~60 sn'yi aşmasın diye model başına 10 sn)
        $denenecek = [];
        foreach (yz_gemini_anahtarlar($a) as $n => $k) { foreach (array_slice(yz_modeller($a['gemini_model']), 0, 2) as $m) { $denenecek[] = ["Gemini anahtar $n", 'yz_gemini', ['gemini_anahtar' => $k] + $a, $m]; } }
        if (!$denenecek) { $sonuc[] = 'Hiç anahtar girilmemiş.'; }
        $bas = time();
        foreach ($denenecek as [$ad, $fn, $aa, $model]) {
            if (time() - $bas > 45) { $sonuc[] = "$ad $model: denenmedi (süre)"; continue; }
            $t0 = microtime(true);
            try { $j = yz_json($fn($istem, $aa, 10, $model)); $sonuc[] = "$ad – $model: çalışıyor ✓ (" . round(microtime(true) - $t0, 1) . ' sn)' . (!empty($j['ok']) ? '' : ' – cevap beklenenden farklı'); }
            catch (Throwable $e) {
                $m = $e->getMessage(); $sonuc[] = "$ad – $model: HATA – " . mb_substr($m, 0, 110);
            }
        }
        git('yapayzeka.php', implode(' · ', $sonuc));
    }
    if ($s === 'yaz') {
        $v = veri_oku();
        $ids = isset($_POST['id']) ? [preg_replace('/\D/', '', (string)$_POST['id'])] : null;
        $rapor = yz_bekleyenleri_doldur($v['araclar'], 40, $ids);   // Hostinger ~60 sn sonra keser
        if (!$rapor) { git('yapayzeka.php', $ids ? 'Bu ilanın sahibinden açıklaması sunucuda yok (eklenti bir kez çalışınca gelir).' : 'Açıklama bekleyen araç yok.', true); }
        veri_yaz($v, 'yapay zekâ açıklaması (panel)');
        git('yapayzeka.php', implode(' · ', $rapor));
    }
}

$a = yz_ayar();
$temiz = [];
foreach (['gemini_anahtar' => 1, 'gemini2_anahtar' => 2, 'gemini3_anahtar' => 3] as $kk => $n) {
    if ($a[$kk] !== '' && sifre_hash() !== null && password_verify($a[$kk], (string)sifre_hash())) { $temiz[$kk] = ''; }
}
if ($temiz) { yz_ayar_yaz($temiz); $a = yz_ayar(); $_SESSION['hata'] = 'Kayıtlı Gemini anahtarlarından biri aslında panel şifresiydi (tarayıcı otomatik doldurmuş); silindi. O kutuya doğru anahtarı girin.'; }
$v = veri_oku();
$maske = fn(string $k) => $k === '' ? 'girilmemiş' : 'kayıtlı (…' . h(mb_substr($k, -4)) . ')';
$DURUM = ['tamam' => 'yazıldı', 'bekliyor' => 'bekliyor', 'ham_yok' => 'sahibinden metni yok'];
sablon_bas('Yapay zekâ'); mesaj(); ?>
<h1>Yapay zekâ ile ilan açıklaması</h1>
<p>Sahibinden eklentisi yeni bir ilan getirdiğinde, ilanın açıklamasından firma tanıtımı ve takas/kredi yazıları ayıklanır, araca özel kısa bir açıklama
<b>4 dilde</b> yazılır ve başlıktaki yazım hataları düzeltilir. En çok 3 <b>Gemini</b> anahtarı sırayla kullanılır; birinin kotası dolarsa sonrakine geçilir.
Yapay zekâ çalışmazsa ilan yine eklenir, açıklaması sonra yazılır.</p>

<?php require_once __DIR__ . '/_eklenti_durum.php'; $sg = eklenti_saglik(); $ed = eklenti_durum_oku(); ?>
<section class="y-kart" id="eklenti">
  <h2>Sahibinden eklentisi durumu</h2>
  <p><?= $sg['saglikli'] ? '✅' : '⚠️' ?> <?= h($sg['ozet']) ?></p>
  <?php if ($sg['sorunlar']): ?><ul><?php foreach ($sg['sorunlar'] as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <p><small>Son deneme: <?= h(isset($ed['son_deneme']) ? date('d.m.Y H:i', strtotime($ed['son_deneme'])) : '—') ?> ·
    eklenti sürümü <?= h($ed['surum'] ?? '—') ?> · okuma kuralları sürümü <?= h((string)($ed['ayar_surum'] ?? '—')) ?> ·
    <?= !empty($ed['otomatik']) ? 'otomatik çalışma' : 'elle çalıştırma' ?>.
    <?= EKLENTI_ESIK_GUN ?> gün başarılı güncelleme olmazsa ya da son çalışma hata verirse bu uyarı panelin her sayfasında görünür.</small></p>
</section>

<section class="y-kart">
  <h2>Anahtarlar</h2>
  <form method="post" action="yapayzeka.php?s=kaydet" autocomplete="off"><?= csrf_alan() ?>
    <label>1. Gemini API anahtarı – <small><?= $maske($a['gemini_anahtar']) ?></small>
      <input type="password" autocomplete="new-password" name="gemini_anahtar" placeholder="AIza… (değiştirmek için yapıştırın, boş bırakırsanız eskisi kalır)"></label>
    <?php if ($a['gemini_anahtar'] !== ''): ?><label class="y-onay"><input type="checkbox" name="gemini_sil" value="1"> 1. Gemini anahtarını sil</label><?php endif; ?>
    <?php foreach ([2, 3] as $n): $kk = "gemini{$n}_anahtar"; ?>
    <label><?= $n ?>. Gemini API anahtarı <small>(isteğe bağlı; öncekinin kotası dolunca kullanılır)</small> – <small><?= $maske($a[$kk]) ?></small>
      <input type="password" autocomplete="new-password" name="gemini<?= $n ?>_anahtar" placeholder="AIza… (boş bırakırsanız eskisi kalır)"></label>
    <?php if ($a[$kk] !== ''): ?><label class="y-onay"><input type="checkbox" name="gemini<?= $n ?>_sil" value="1"> <?= $n ?>. Gemini anahtarını sil</label><?php endif; ?>
    <?php endforeach; ?>
    <label>Gemini modelleri <small>(virgülle; sırayla denenir, her model için anahtarlar sırayla)</small> <input name="gemini_model" value="<?= h($a['gemini_model']) ?>"></label>
    <p><button class="y-btn">Kaydet</button></p>
  </form>
  <form method="post" action="yapayzeka.php?s=dene"><?= csrf_alan() ?><button class="y-btn y-btn-cizgi">Bağlantıyı dene</button>
    <small>Her anahtara ayrı ayrı kısa bir deneme isteği gönderir (en çok ~50 sn).</small></form>
  <?php $bk = yz_bekle_oku(); if ($bk): ?><p><small>Şu an beklemede (kota doldu ya da yoğun, sonra kendiliğinden tekrar denenir): <?php foreach ($bk as $m => $t): ?><?= h(preg_replace('/^g(\d):/', '$1. anahtar · ', $m)) ?> – <?= h(date('H:i', $t)) ?>'e kadar; <?php endforeach; ?></small></p><?php endif; ?>
  <p><small>Anahtarlar yalnız bu sunucuda saklanır (GitHub'a gitmez, sayfada gösterilmez). İlan açıklamaları yazılırken Google'a
  yalnız araç bilgisi ve sahibinden ilan metni gönderilir; müşteri bilgisi gönderilmez.</small></p>
</section>

<section class="y-kart">
  <h2>Araçlar</h2>
  <form method="post" action="yapayzeka.php?s=yaz"><?= csrf_alan() ?><button class="y-btn">Bekleyenlerin açıklamasını şimdi yaz</button>
    <small>Bir basışta ~40 saniyede yazılabilenler yazılır; kalan varsa tekrar basın.</small></form>
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
