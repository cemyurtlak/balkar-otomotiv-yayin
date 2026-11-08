<?php
/* Balkar Otomotiv – araç kataloğu yönetimi (aracını sat formunun Marka → Yıl → Model → Versiyon → Kasa → Şanzıman → Yakıt → Motor listeleri).
   Veri: veri/katalog.json. Her kayıtta önce yedek alınır (veri/yedek/katalog-*.json), yedekten geri dönülebilir.
   Marka ve model adla, versiyon satırı sırasıyla (n) bulunur. (Kod içinde versiyon satırları tarihsel olarak "motorlar" diye tutulur.) */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';

if (sifre_hash() === null || !giris_var()) { git('index.php'); }
$_SESSION['zaman'] = time();

const K_YAKIT = ['benzin' => 'Benzin', 'dizel' => 'Dizel', 'hibrit' => 'Hibrit / plug-in', 'elektrik' => 'Elektrik', 'lpg' => 'Fabrika LPG'];
const K_VITES = ['manuel' => 'Manuel', 'otomatik' => 'Otomatik', 'yari_otomatik' => 'Yarı otomatik'];
const K_KASA = ['' => '— (belirtilmemiş)', 'hatchback' => 'Hatchback', 'sedan' => 'Sedan', 'station' => 'Station Wagon', 'suv' => 'SUV', 'crossover' => 'Crossover', 'coupe' => 'Coupe', 'cabrio' => 'Cabrio', 'roadster' => 'Roadster', 'mpv' => 'MPV', 'minivan' => 'Minivan', 'pickup' => 'Pick-Up', 'panelvan' => 'Panelvan', 'kombi' => 'Kombi', 'kombivan' => 'Kombivan', 'kamyonet' => 'Kamyonet', 'minibus' => 'Minibüs'];

$k = katalog_oku();
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$g = (string)($_GET['g'] ?? '');

function marka_i(array $k, string $ad): ?int { foreach ($k['markalar'] as $i => $m) { if (mb_strtolower($m['ad']) === mb_strtolower($ad)) { return $i; } } return null; }
function model_i(array $m, string $ad): ?int { foreach ($m['modeller'] as $i => $md) { if (mb_strtolower($md['ad']) === mb_strtolower($ad)) { return $i; } } return null; }
function ad_temiz($s): string { return mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$s) ?? ''), 0, 60); }
function adres(string $marka = '', string $model = '', array $ek = []): string {
    $q = array_filter(['m' => $marka, 'md' => $model] + $ek, fn($v) => $v !== '' && $v !== null);
    return 'katalog.php' . ($q ? '?' . http_build_query($q) : '');
}
function yil_yazi(array $y): string { return $y[0] . ' – ' . ($y[1] === null ? 'bugün' : $y[1]); }
function motor_dogrula(array $p, ?string &$hata): ?array {
    $ad = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($p['ad'] ?? '')) ?? ''), 0, 90);
    $kasa = (string)($p['kasa'] ?? '');
    $yakit = (string)($p['yakit'] ?? ''); $vites = (string)($p['vites'] ?? '');
    $hacim = str_replace(',', '.', trim((string)($p['hacim'] ?? '')));
    $bas = sayi_temizle($p['bas'] ?? ''); $son = sayi_temizle($p['son'] ?? '');
    $ust = (int)date('Y') + 1;
    if ($ad === '') { $hata = 'Versiyon adı boş olamaz.'; return null; }
    if (!isset(K_KASA[$kasa])) { $hata = 'Kasa tipi geçersiz.'; return null; }
    if (!isset(K_YAKIT[$yakit])) { $hata = 'Yakıt seçin.'; return null; }
    if (!isset(K_VITES[$vites])) { $hata = 'Şanzıman seçin.'; return null; }
    if ($yakit === 'elektrik') { $hacim = null; }
    elseif (preg_match('/^\d(\.\d)?$/', $hacim)) { $hacim = number_format((float)$hacim, 1, '.', ''); }
    else { $hata = 'Motor hacmi 1.6 gibi yazılmalı (elektrikli araçta boş kalır).'; return null; }
    if ($hacim !== null && ((float)$hacim < 0.5 || (float)$hacim > 8.5)) { $hata = 'Motor hacmi 0.5 ile 8.5 arasında olmalı.'; return null; }
    if ($bas === null || $bas < 1950 || $bas > $ust) { $hata = "Başlangıç yılı 1950 – $ust arasında olmalı."; return null; }
    if ($son !== null && ($son < $bas || $son > $ust)) { $hata = 'Bitiş yılı başlangıçtan küçük olamaz (hâlâ satılıyorsa boş bırakın).'; return null; }
    $m = ['ad' => $ad, 'kasa' => $kasa, 'hacim' => $hacim, 'yakit' => $yakit, 'vites' => $vites, 'yil' => [$bas, $son]];
    if ($kasa === '') { unset($m['kasa']); }
    if (!empty($p['kontrol'])) { $m['kontrol'] = true; }
    return $m;
}

/* ---------- işlemler */
if ($post) {
    csrf_dogrula();
    $s = (string)($_GET['s'] ?? '');
    $mAd = (string)($_POST['marka'] ?? ''); $mdAd = (string)($_POST['model'] ?? '');
    $mi = marka_i($k, $mAd);
    $mdi = $mi !== null ? model_i($k['markalar'][$mi], $mdAd) : null;

    if ($s === 'marka_ekle') {
        $ad = ad_temiz($_POST['ad'] ?? '');
        if ($ad === '') { git(adres(), 'Marka adı boş olamaz.', true); }
        if (marka_i($k, $ad) !== null) { git(adres($ad), 'Bu marka zaten var.', true); }
        $k['markalar'][] = ['ad' => $ad, 'modeller' => []];
        katalog_yaz($k); git(adres($ad), "$ad eklendi. Şimdi model ekleyin (modeli olmayan marka formda görünmez).");
    }
    if ($mi === null && $s !== 'geri_yukle') { git(adres(), 'Marka bulunamadı.', true); }

    if ($s === 'marka_adlandir') {
        $ad = ad_temiz($_POST['ad'] ?? '');
        if ($ad === '') { git(adres($mAd), 'Marka adı boş olamaz.', true); }
        $j = marka_i($k, $ad);
        if ($j !== null && $j !== $mi) { git(adres($mAd), 'Bu adla başka bir marka var.', true); }
        $k['markalar'][$mi]['ad'] = $ad;
        katalog_yaz($k); git(adres($ad), 'Marka adı değiştirildi.');
    }
    if ($s === 'marka_sil') {
        if (empty($_POST['onay'])) { git(adres($mAd), 'Silmek için onay kutusunu işaretleyin.', true); }
        array_splice($k['markalar'], $mi, 1);
        katalog_yaz($k); git(adres(), "$mAd ve tüm modelleri silindi (yedekten geri alınabilir).");
    }
    if ($s === 'model_ekle') {
        $ad = ad_temiz($_POST['ad'] ?? '');
        if ($ad === '') { git(adres($mAd), 'Model adı boş olamaz.', true); }
        if (model_i($k['markalar'][$mi], $ad) !== null) { git(adres($mAd, $ad), 'Bu model zaten var.', true); }
        $k['markalar'][$mi]['modeller'][] = ['ad' => $ad, 'motorlar' => []];
        katalog_yaz($k); git(adres($mAd, $ad), "$ad eklendi. Şimdi versiyon ekleyin (versiyonu ve model yılı olmayan model formda görünmez).");
    }
    if ($mdi === null && in_array($s, ['model_yillar', 'model_adlandir', 'model_sil', 'motor_kaydet', 'motor_sil', 'kontrol_kaldir'], true)) { git(adres($mAd), 'Model bulunamadı.', true); }
    if ($s === 'model_yillar') {   // motor satırından bağımsız, modelin formda listelendiği yıllar
        $bas = sayi_temizle($_POST['bas'] ?? ''); $son = sayi_temizle($_POST['son'] ?? ''); $ust = (int)date('Y') + 1;
        if ($bas === null) { unset($k['markalar'][$mi]['modeller'][$mdi]['yillar']); katalog_yaz($k); git(adres($mAd, $mdAd), 'Model yılları kaldırıldı; model yalnızca versiyonlarının yıllarında listelenir.'); }
        if ($bas < 1950 || $bas > $ust || ($son !== null && ($son < $bas || $son > $ust))) { git(adres($mAd, $mdAd), 'Yıl aralığı geçersiz.', true); }
        $k['markalar'][$mi]['modeller'][$mdi]['yillar'] = [$bas, $son];
        katalog_yaz($k); git(adres($mAd, $mdAd), 'Model yılları kaydedildi.');
    }
    if ($s === 'model_adlandir') {
        $ad = ad_temiz($_POST['ad'] ?? '');
        if ($ad === '') { git(adres($mAd, $mdAd), 'Model adı boş olamaz.', true); }
        $j = model_i($k['markalar'][$mi], $ad);
        if ($j !== null && $j !== $mdi) { git(adres($mAd, $mdAd), 'Bu adla başka bir model var.', true); }
        $k['markalar'][$mi]['modeller'][$mdi]['ad'] = $ad;
        katalog_yaz($k); git(adres($mAd, $ad), 'Model adı değiştirildi.');
    }
    if ($s === 'model_sil') {
        if (empty($_POST['onay'])) { git(adres($mAd, $mdAd), 'Silmek için onay kutusunu işaretleyin.', true); }
        array_splice($k['markalar'][$mi]['modeller'], $mdi, 1);
        katalog_yaz($k); git(adres($mAd), "$mdAd silindi (yedekten geri alınabilir).");
    }
    if ($mdi === null) { git(adres($mAd), 'Bilinmeyen işlem.', true); }
    $motorlar = &$k['markalar'][$mi]['modeller'][$mdi]['motorlar'];
    $n = isset($_POST['n']) && $_POST['n'] !== '' ? (int)$_POST['n'] : null;
    if ($n !== null && !isset($motorlar[$n])) { git(adres($mAd, $mdAd), 'Versiyon bulunamadı (sayfa eski olabilir, yenileyin).', true); }
    if ($s === 'motor_kaydet') {
        $hata = null; $m = motor_dogrula($_POST, $hata);
        if (!$m) { git(adres($mAd, $mdAd, $n !== null ? ['n' => $n] : []), $hata ?? 'Geçersiz bilgi.', true); }
        foreach ($motorlar as $i => $x) {
            if ($i !== $n && $x['ad'] === $m['ad'] && ($x['kasa'] ?? '') === ($m['kasa'] ?? '') && $x['yakit'] === $m['yakit'] && $x['vites'] === $m['vites'] && $x['yil'] === $m['yil']) {
                git(adres($mAd, $mdAd), 'Aynı versiyon satırı zaten var.', true);
            }
        }
        if ($n === null) { $motorlar[] = $m; } else { $motorlar[$n] = $m; }
        katalog_yaz($k); git(adres($mAd, $mdAd), 'Versiyon kaydedildi: ' . $m['ad'] . ' (' . K_VITES[$m['vites']] . ', ' . yil_yazi($m['yil']) . ')');
    }
    if ($s === 'motor_sil') {
        array_splice($motorlar, (int)$n, 1);
        katalog_yaz($k); git(adres($mAd, $mdAd), 'Versiyon silindi.');
    }
    if ($s === 'kontrol_kaldir') {
        unset($motorlar[(int)$n]['kontrol']);
        katalog_yaz($k);
        git(($_POST['don'] ?? '') === 'kontrol' ? 'katalog.php?g=kontrol' : adres($mAd, $mdAd), 'Satır doğrulandı olarak işaretlendi.');
    }
    if ($s === 'geri_yukle') {
        $d = basename((string)($_POST['dosya'] ?? ''));
        $yol = YEDEK . '/' . $d;
        if (!preg_match('/^katalog-\d{8}-\d{6}\.json$/', $d) || !is_file($yol)) { git('katalog.php?g=yedek', 'Yedek bulunamadı.', true); }
        $v2 = json_decode((string)file_get_contents($yol), true);
        if (!is_array($v2) || !isset($v2['markalar'])) { git('katalog.php?g=yedek', 'Yedek okunamadı.', true); }
        katalog_yaz($v2); git('katalog.php', 'Katalog yedekten geri yüklendi (önceki hâli de yedeklendi).');
    }
    git(adres(), 'Bilinmeyen işlem.', true);
}

/* ---------- ekranlar */
$mAd = (string)($_GET['m'] ?? ''); $mdAd = (string)($_GET['md'] ?? '');
$mi = $mAd !== '' ? marka_i($k, $mAd) : null;
$mdi = $mi !== null && $mdAd !== '' ? model_i($k['markalar'][$mi], $mdAd) : null;
$say = function (array $m): array {
    $mot = 0; $kon = 0;
    foreach ($m['modeller'] as $md) { $mot += count($md['motorlar']); foreach ($md['motorlar'] as $x) { if (!empty($x['kontrol'])) { $kon++; } } }
    return [count($m['modeller']), $mot, $kon];
};
$model_yillari = function (array $md): string {
    $araliklar = array_map(fn($x) => $x['yil'], $md['motorlar']);
    if (!empty($md['yillar'])) { $araliklar[] = $md['yillar']; }
    if (!$araliklar) { return '—'; }
    $b = min(array_map(fn($y) => $y[0], $araliklar));
    $son = array_map(fn($y) => $y[1], $araliklar);
    return $b . ' – ' . (in_array(null, $son, true) ? 'bugün' : max($son));
};
$silme_formu = function (string $islem, string $metin, array $gizli) {
    echo '<details class="y-sil"><summary>' . h($metin) . '</summary><form method="post" action="katalog.php?s=' . h($islem) . '">' . csrf_alan();
    foreach ($gizli as $a => $v) { echo '<input type="hidden" name="' . h($a) . '" value="' . h($v) . '">'; }
    echo '<label class="y-onay"><input type="checkbox" name="onay" value="1" required> Evet, silinsin</label><button class="y-btn y-btn-cizgi">Sil</button></form></details>';
};

sablon_bas('Araç kataloğu'); mesaj();
$toplam_kontrol = array_sum(array_map(fn($m) => $say($m)[2], $k['markalar']));
?>
<nav class="y-yol"><a href="katalog.php">Araç kataloğu</a><?php if ($mi !== null): ?> › <a href="<?= h(adres($k['markalar'][$mi]['ad'])) ?>"><?= h($k['markalar'][$mi]['ad']) ?></a><?php endif; ?><?php if ($mdi !== null): ?> › <?= h($k['markalar'][$mi]['modeller'][$mdi]['ad']) ?><?php endif; ?>
  <span class="y-yol-sag"><a href="katalog.php?g=kontrol">Kontrol edilecek bilgiler (<?= $toplam_kontrol ?>)</a> · <a href="katalog.php?g=yedek">Katalog yedekleri</a></span></nav>
<?php

/* ---- yedekler */
if ($g === 'yedek'):
    $yedekler = glob(YEDEK . '/katalog-*.json') ?: []; rsort($yedekler); ?>
  <h1>Katalog yedekleri <small>Her kayıttan önce otomatik alınır; son 30 yedek tutulur.</small></h1>
  <section class="y-kart"><?php if (!$yedekler): ?><p class="y-not">Henüz yedek yok.</p><?php else: ?>
    <div class="y-tablo-kap"><table class="y-tablo"><tr><th>Tarih</th><th>Boyut</th><th></th></tr>
    <?php foreach ($yedekler as $y): $b = basename($y); preg_match('/(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/', $b, $t); ?>
      <tr><td><?= h("$t[3].$t[2].$t[1] $t[4]:$t[5]") ?></td><td><?= number_format(filesize($y) / 1024, 0, ',', '.') ?> KB</td>
      <td class="y-islem"><form method="post" action="katalog.php?s=geri_yukle" onsubmit="return confirm('Katalog bu yedeğe dönsün mü?')"><?= csrf_alan() ?><input type="hidden" name="dosya" value="<?= h($b) ?>"><button>Geri yükle</button></form></td></tr>
    <?php endforeach; ?></table></div><?php endif; ?></section>
<?php sablon_son(); exit; endif;

/* ---- kontrol edilecek bilgiler */
if ($g === 'kontrol'): ?>
  <h1>Kontrol edilecek bilgiler <small>Türkiye'de satıldığı yıllar veya motor bilgisi kesin doğrulanamayan versiyonlar (<?= $toplam_kontrol ?>). Doğruysa "Doğru" deyin, hatalıysa düzenleyin.</small></h1>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Marka / model</th><th>Versiyon</th><th>Kasa</th><th>Hacim</th><th>Yakıt</th><th>Şanzıman</th><th>Yıllar</th><th></th></tr>
    <?php foreach ($k['markalar'] as $m): foreach ($m['modeller'] as $md): foreach ($md['motorlar'] as $n => $x): if (empty($x['kontrol'])) { continue; } ?>
      <tr><td><?= h($m['ad']) ?> <small><?= h($md['ad']) ?></small></td><td><?= h($x['ad']) ?></td><td><?= h(K_KASA[$x['kasa'] ?? ''] ?? ($x['kasa'] ?? '')) ?></td><td><?= h($x['hacim'] ?? '—') ?></td>
        <td><?= h(K_YAKIT[$x['yakit']] ?? $x['yakit']) ?></td><td><?= h(K_VITES[$x['vites']] ?? $x['vites']) ?></td><td><?= h(yil_yazi($x['yil'])) ?></td>
        <td class="y-islem"><a href="<?= h(adres($m['ad'], $md['ad'], ['n' => $n])) ?>#motor-form">Düzenle</a>
          <form method="post" action="katalog.php?s=kontrol_kaldir"><?= csrf_alan() ?><input type="hidden" name="marka" value="<?= h($m['ad']) ?>"><input type="hidden" name="model" value="<?= h($md['ad']) ?>"><input type="hidden" name="n" value="<?= $n ?>"><input type="hidden" name="don" value="kontrol"><button>Doğru</button></form></td></tr>
    <?php endforeach; endforeach; endforeach; ?>
  </table></div></section>
<?php sablon_son(); exit; endif;

/* ---- model: versiyonlar */
if ($mdi !== null):
    $m = $k['markalar'][$mi]; $md = $m['modeller'][$mdi];
    $dz = isset($_GET['n']) && isset($md['motorlar'][(int)$_GET['n']]) ? (int)$_GET['n'] : null;
    $f = $dz !== null ? $md['motorlar'][$dz] : ['ad' => '', 'hacim' => '', 'yakit' => 'benzin', 'vites' => 'manuel', 'yil' => [null, null]];
    $gizli = '<input type="hidden" name="marka" value="' . h($m['ad']) . '"><input type="hidden" name="model" value="' . h($md['ad']) . '">'; ?>
  <div class="y-bas"><h1><?= h($m['ad'] . ' ' . $md['ad']) ?> <small>Formda <?= h($model_yillari($md)) ?> yıllarında listelenir · <?= count($md['motorlar']) ?> versiyon satırı</small></h1></div>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Versiyon</th><th>Kasa</th><th>Hacim</th><th>Yakıt</th><th>Şanzıman</th><th>Türkiye'de satış yılları</th><th></th></tr>
    <?php foreach ($md['motorlar'] as $n => $x): ?>
      <tr<?= $n === $dz ? ' class="y-secili"' : '' ?>><td><?= h($x['ad']) ?><?php if (!empty($x['kontrol'])): ?> <span class="y-rozet y-rozet-uyari">kontrol et</span><?php endif; ?></td>
        <td><?= h(K_KASA[$x['kasa'] ?? ''] ?? ($x['kasa'] ?? '')) ?></td><td><?= h($x['hacim'] ?? '—') ?></td><td><?= h(K_YAKIT[$x['yakit']] ?? $x['yakit']) ?></td><td><?= h(K_VITES[$x['vites']] ?? $x['vites']) ?></td><td><?= h(yil_yazi($x['yil'])) ?></td>
        <td class="y-islem"><a href="<?= h(adres($m['ad'], $md['ad'], ['n' => $n])) ?>#motor-form">Düzenle</a>
          <?php if (!empty($x['kontrol'])): ?><form method="post" action="katalog.php?s=kontrol_kaldir"><?= csrf_alan() . $gizli ?><input type="hidden" name="n" value="<?= $n ?>"><button>Doğru</button></form><?php endif; ?>
          <form method="post" action="katalog.php?s=motor_sil" onsubmit="return confirm('Bu versiyon silinsin mi?')"><?= csrf_alan() . $gizli ?><input type="hidden" name="n" value="<?= $n ?>"><button>Sil</button></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$md['motorlar']): ?><tr><td colspan="7" class="y-not">Versiyon yok. <?= empty($md['yillar']) ? 'Bu model formda görünmez; aşağıdan ekleyin.' : 'Formda model yıllarında listelenir; müşteri versiyonu elle yazar, kasa, şanzıman, yakıt ve motor genel listelerden seçilir.' ?></td></tr><?php endif; ?>
  </table></div></section>

  <section class="y-kart" id="motor-form"><h2><?= $dz !== null ? 'Versiyonu düzenle' : 'Versiyon ekle' ?></h2>
    <p class="y-not">Aynı versiyonun farklı kasa, şanzıman ya da yıllar için ayrı satırı olur. Elektrikli araçta hacim boş kalır. Hâlâ satılıyorsa bitiş yılını boş bırakın: yeni yıl gelince formda kendiliğinden görünür.</p>
    <form method="post" action="katalog.php?s=motor_kaydet"><?= csrf_alan() . $gizli ?><?php if ($dz !== null): ?><input type="hidden" name="n" value="<?= $dz ?>"><?php endif; ?>
      <div class="y-izgara">
        <label>Versiyon adı (ör. 1.3 Multijet Urban 95HP)<input name="ad" required maxlength="90" value="<?= h($f['ad']) ?>"></label>
        <label>Kasa tipi<select name="kasa"><?php foreach (K_KASA as $a => $y): ?><option value="<?= $a ?>"<?= ($f['kasa'] ?? '') === $a ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></label>
        <label>Motor hacmi (ör. 1.6)<input name="hacim" maxlength="4" inputmode="decimal" value="<?= h($f['hacim'] ?? '') ?>" placeholder="elektrikte boş"></label>
        <label>Yakıt<select name="yakit"><?php foreach (K_YAKIT as $a => $y): ?><option value="<?= $a ?>"<?= $f['yakit'] === $a ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></label>
        <label>Şanzıman<select name="vites"><?php foreach (K_VITES as $a => $y): ?><option value="<?= $a ?>"<?= $f['vites'] === $a ? ' selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?></select></label>
        <label>İlk yıl<input name="bas" required inputmode="numeric" maxlength="4" value="<?= h($f['yil'][0]) ?>"></label>
        <label>Son yıl (hâlâ satılıyorsa boş)<input name="son" inputmode="numeric" maxlength="4" value="<?= h($f['yil'][1]) ?>"></label>
      </div>
      <label class="y-onay"><input type="checkbox" name="kontrol" value="1"<?= !empty($f['kontrol']) ? ' checked' : '' ?>> Bilgi kesin değil, "kontrol et" listesinde kalsın</label>
      <button class="y-btn"><?= $dz !== null ? 'Kaydet' : 'Ekle' ?></button>
      <?php if ($dz !== null): ?> <a class="y-btn y-btn-cizgi" href="<?= h(adres($m['ad'], $md['ad'])) ?>">Vazgeç</a><?php endif; ?>
    </form>
  </section>

  <section class="y-kart"><h2>Model</h2>
    <form method="post" action="katalog.php?s=model_yillar" class="y-satir"><?= csrf_alan() . $gizli ?>
      <label>Model yılları: ilk yıl (boş = kullanılmaz)<input name="bas" inputmode="numeric" maxlength="4" value="<?= h($md['yillar'][0] ?? '') ?>"></label>
      <label>son yıl (hâlâ satılıyorsa boş)<input name="son" inputmode="numeric" maxlength="4" value="<?= h($md['yillar'][1] ?? '') ?>"></label>
      <button class="y-btn y-btn-cizgi">Yılları kaydet</button></form>
    <p class="y-not">Model yılları, versiyonlar o yılı kapsamasa da modelin formda listelenmesini sağlar (o yıllarda modelin tüm versiyonları gösterilir). Örnek siteden gelen yıl aralıkları burada tutulur.</p>
    <form method="post" action="katalog.php?s=model_adlandir" class="y-satir"><?= csrf_alan() . $gizli ?><label>Model adı<input name="ad" required maxlength="60" value="<?= h($md['ad']) ?>"></label><button class="y-btn y-btn-cizgi">Adı değiştir</button></form>
    <?php $silme_formu('model_sil', 'Bu modeli sil', ['marka' => $m['ad'], 'model' => $md['ad']]); ?>
  </section>
<?php sablon_son(); exit; endif;

/* ---- marka: modeller */
if ($mi !== null):
    $m = $k['markalar'][$mi]; [$a, $b, $c] = $say($m); ?>
  <div class="y-bas"><h1><?= h($m['ad']) ?> <small><?= $a ?> model · <?= $b ?> versiyon<?= $c ? " · $c kontrol edilecek" : '' ?></small></h1></div>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Model</th><th>Formda listelendiği yıllar</th><th>Versiyon</th><th></th></tr>
    <?php foreach ($m['modeller'] as $md): $kon = count(array_filter($md['motorlar'], fn($x) => !empty($x['kontrol']))); ?>
      <tr><td><a href="<?= h(adres($m['ad'], $md['ad'])) ?>"><?= h($md['ad']) ?></a><?php if ($kon): ?> <span class="y-rozet y-rozet-uyari"><?= $kon ?> kontrol</span><?php endif; ?></td>
        <td><?= h($model_yillari($md)) ?></td><td><?= count($md['motorlar']) ?></td><td class="y-islem"><a href="<?= h(adres($m['ad'], $md['ad'])) ?>">Versiyonlar</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$m['modeller']): ?><tr><td colspan="4" class="y-not">Model yok. Bu marka formda görünmez; aşağıdan ekleyin.</td></tr><?php endif; ?>
  </table></div></section>
  <section class="y-kart"><h2>Model ekle</h2>
    <form method="post" action="katalog.php?s=model_ekle" class="y-satir"><?= csrf_alan() ?><input type="hidden" name="marka" value="<?= h($m['ad']) ?>"><label>Model adı (Türkiye'de satıldığı adla)<input name="ad" required maxlength="60"></label><button class="y-btn">Ekle</button></form>
  </section>
  <section class="y-kart"><h2>Marka</h2>
    <form method="post" action="katalog.php?s=marka_adlandir" class="y-satir"><?= csrf_alan() ?><input type="hidden" name="marka" value="<?= h($m['ad']) ?>"><label>Marka adı<input name="ad" required maxlength="60" value="<?= h($m['ad']) ?>"></label><button class="y-btn y-btn-cizgi">Adı değiştir</button></form>
    <?php $silme_formu('marka_sil', 'Bu markayı ve tüm modellerini sil', ['marka' => $m['ad']]); ?>
  </section>
<?php sablon_son(); exit; endif;

/* ---- ana ekran: markalar + arama */
$q = trim((string)($_GET['q'] ?? ''));
$mod_say = array_sum(array_map(fn($m) => count($m['modeller']), $k['markalar']));
$mot_say = array_sum(array_map(fn($m) => $say($m)[1], $k['markalar'])); ?>
  <div class="y-bas"><h1>Araç kataloğu <small><?= count($k['markalar']) ?> marka · <?= $mod_say ?> model · <?= $mot_say ?> versiyon satırı · son güncelleme <?= h(date('d.m.Y H:i', strtotime((string)($k['guncelleme'] ?? 'now')))) ?></small></h1>
    <form method="get" class="y-satir"><label>Ara (marka, model, versiyon)<input type="search" name="q" value="<?= h($q) ?>" placeholder="ör. Egea, 1.6, Urban"></label><button class="y-btn y-btn-cizgi">Ara</button></form></div>
  <p class="y-not">Buradaki liste sitedeki "Aracını sat" formunu besler: müşteri marka ve yılı seçince yalnızca o yıl Türkiye'de satılan modeller, model seçince o modelin o yılki versiyonları, versiyon seçince de kasa, şanzıman, yakıt ve motor hacmi çıkar. Listede olmayan araçlar formda "Listede yok, elle yaz" ile girilir.</p>
<?php if ($q !== ''):
    $ql = mb_strtolower($q); $sonuc = [];
    foreach ($k['markalar'] as $m) { foreach ($m['modeller'] as $md) { foreach ($md['motorlar'] as $n => $x) {
        if (str_contains(mb_strtolower($m['ad'] . ' ' . $md['ad'] . ' ' . $x['ad'] . ' ' . ($x['hacim'] ?? '')), $ql)) { $sonuc[] = [$m, $md, $n, $x]; }
    } } } ?>
  <section class="y-kart"><h2>"<?= h($q) ?>" için <?= count($sonuc) ?> versiyon<?= count($sonuc) > 300 ? ' (ilk 300 gösteriliyor)' : '' ?></h2>
  <div class="y-tablo-kap"><table class="y-tablo"><tr><th>Marka / model</th><th>Versiyon</th><th>Kasa</th><th>Hacim</th><th>Yakıt</th><th>Şanzıman</th><th>Yıllar</th><th></th></tr>
  <?php foreach (array_slice($sonuc, 0, 300) as [$m, $md, $n, $x]): ?>
    <tr><td><?= h($m['ad']) ?> <a href="<?= h(adres($m['ad'], $md['ad'])) ?>"><?= h($md['ad']) ?></a></td><td><?= h($x['ad']) ?><?php if (!empty($x['kontrol'])): ?> <span class="y-rozet y-rozet-uyari">kontrol et</span><?php endif; ?></td>
      <td><?= h(K_KASA[$x['kasa'] ?? ''] ?? ($x['kasa'] ?? '')) ?></td><td><?= h($x['hacim'] ?? '—') ?></td><td><?= h(K_YAKIT[$x['yakit']] ?? $x['yakit']) ?></td><td><?= h(K_VITES[$x['vites']] ?? $x['vites']) ?></td><td><?= h(yil_yazi($x['yil'])) ?></td>
      <td class="y-islem"><a href="<?= h(adres($m['ad'], $md['ad'], ['n' => $n])) ?>#motor-form">Düzenle</a></td></tr>
  <?php endforeach; ?></table></div></section>
<?php endif; ?>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Marka</th><th>Model</th><th>Versiyon</th><th>Kontrol edilecek</th></tr>
    <?php foreach ($k['markalar'] as $m): [$a, $b, $c] = $say($m); ?>
      <tr><td><a href="<?= h(adres($m['ad'])) ?>"><?= h($m['ad']) ?></a></td><td><?= $a ?></td><td><?= $b ?></td><td><?= $c ?: '—' ?></td></tr>
    <?php endforeach; ?>
  </table></div></section>
  <section class="y-kart"><h2>Marka ekle</h2>
    <form method="post" action="katalog.php?s=marka_ekle" class="y-satir"><?= csrf_alan() ?><label>Marka adı<input name="ad" required maxlength="60"></label><button class="y-btn">Ekle</button></form>
  </section>
<?php sablon_son();
