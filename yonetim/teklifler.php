<?php
/* Balkar Otomotiv – aracını sat teklifleri (sitedeki 5 adımlı formdan gelen kayıtlar).
   Kayıtlar: veri/teklifler/<YYYY-AA>/<no>.json (teklif/kaydet.php yazar), 180 gün sonra otomatik silinir.
   Panel yalnızca Türkçedir; müşteri hangi dilde yazdıysa kayıtlar burada Türkçe görünür. */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/../teklif/_ayar.php';

if (sifre_hash() === null || !giris_var()) { git('index.php'); }
$_SESSION['zaman'] = time();

const T_ISTEK = ['sat' => 'Satmak istiyor', 'takas' => 'Takas istiyor', 'fiyat' => 'Değerini öğrenmek istiyor'];
const T_YVH = ['yok' => 'Yok', 'var' => 'Var', 'bilmiyorum' => 'Bilmiyor'];
const T_PARCA = ['kaput' => 'Kaput', 'tavan' => 'Tavan', 'bagaj' => 'Bagaj kapağı', 'on_tampon' => 'Ön tampon', 'arka_tampon' => 'Arka tampon',
    'sol_on_camurluk' => 'Sol ön çamurluk', 'sag_on_camurluk' => 'Sağ ön çamurluk', 'sol_on_kapi' => 'Sol ön kapı', 'sag_on_kapi' => 'Sağ ön kapı',
    'sol_arka_kapi' => 'Sol arka kapı', 'sag_arka_kapi' => 'Sağ arka kapı', 'sol_arka_camurluk' => 'Sol arka çamurluk', 'sag_arka_camurluk' => 'Sağ arka çamurluk'];
const T_PARCA_DURUM = ['orijinal' => 'Orijinal', 'lokal' => 'Lokal boyalı', 'boyali' => 'Boyalı', 'degisen' => 'Değişen'];
const T_KASA = ['hatchback' => 'Hatchback', 'sedan' => 'Sedan', 'station' => 'Station Wagon', 'suv' => 'SUV', 'crossover' => 'Crossover', 'coupe' => 'Coupe', 'cabrio' => 'Cabrio', 'roadster' => 'Roadster', 'mpv' => 'MPV', 'minivan' => 'Minivan', 'pickup' => 'Pick-Up', 'panelvan' => 'Panelvan', 'kombi' => 'Kombi', 'kombivan' => 'Kombivan', 'kamyonet' => 'Kamyonet', 'minibus' => 'Minibüs'];
const T_DIL = ['tr' => 'Türkçe', 'en' => 'İngilizce', 'ru' => 'Rusça', 'de' => 'Almanca'];

function teklif_dosya(string $no): ?string {
    if (!t_kayit_no_gecerli($no)) { return null; }
    $l = glob(TEKLIF_KLASOR . '/*/' . $no . '.json') ?: [];
    return $l[0] ?? null;
}
function teklif_oku(string $f): ?array { $v = json_decode((string)@file_get_contents($f), true); return is_array($v) && isset($v['no']) ? $v : null; }
function teklif_yaz(string $f, array $v): void {
    file_put_contents($f . '.tmp', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    $mt = filemtime($f); rename($f . '.tmp', $f); @touch($f, $mt);   // saklama süresi gönderim tarihinden sayılır
}
function vites_y(string $v): string { return VITESLER[$v] ?? $v; }
function yakit_y(string $v): string { return YAKITLAR[$v] ?? $v; }
function arac_yazi(array $a): string { return trim(implode(' ', array_filter([$a['yil'] ?? '', $a['marka'] ?? '', $a['model'] ?? '', $a['donanim'] ?? '']))); }
function tel_wa(string $tel): string {
    $r = preg_replace('/\D/', '', $tel) ?? '';
    if (str_starts_with($r, '00')) { $r = substr($r, 2); }
    elseif (str_starts_with($r, '0')) { $r = '90' . substr($r, 1); }
    elseif (strlen($r) === 10 && str_starts_with($r, '5')) { $r = '90' . $r; }
    return $r;
}
function tl($s): string { return $s !== '' && $s !== null ? number_format((int)$s, 0, ',', '.') . ' TL' : ''; }

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    csrf_dogrula();
    $s = (string)($_GET['s'] ?? '');
    $no = (string)($_POST['no'] ?? '');
    $f = teklif_dosya($no);
    if (!$f || !($v = teklif_oku($f))) { git('teklifler.php', 'Teklif bulunamadı (süresi dolmuş olabilir).', true); }
    if ($s === 'guncelle') {
        $d = (string)($_POST['durum'] ?? '');
        if (isset(TEKLIF_DURUMLARI[$d])) { $v['durum'] = $d; }
        $v['galeri_notu'] = mb_substr(trim((string)($_POST['galeri_notu'] ?? '')), 0, 1000);
        $v['guncelleme'] = date('c');
        teklif_yaz($f, $v);
        git('teklifler.php?no=' . rawurlencode($no), 'Teklif güncellendi.');
    }
    if ($s === 'durum') {   // listeden hızlı durum değişikliği
        $d = (string)($_POST['durum'] ?? '');
        if (isset(TEKLIF_DURUMLARI[$d])) { $v['durum'] = $d; $v['guncelleme'] = date('c'); teklif_yaz($f, $v); }
        git('teklifler.php?' . http_build_query(array_filter(['d' => $_POST['d'] ?? '', 'q' => $_POST['q'] ?? ''])), "$no: " . (TEKLIF_DURUMLARI[$d] ?? ''));
    }
    if ($s === 'sil') {
        if (empty($_POST['onay'])) { git('teklifler.php?no=' . rawurlencode($no), 'Silmek için onay kutusunu işaretleyin.', true); }
        @unlink($f);
        git('teklifler.php', "$no numaralı teklif silindi.");
    }
    git('teklifler.php', 'Bilinmeyen işlem.', true);
}

if (is_dir(T_KAYIT_KLASOR)) { t_kayit_temizle(); }

/* ---------- detay */
if (isset($_GET['no'])) {
    $no = (string)$_GET['no']; $f = teklif_dosya($no); $v = $f ? teklif_oku($f) : null;
    if (!$v) { git('teklifler.php', 'Teklif bulunamadı (süresi dolmuş olabilir).', true); }
    $a = $v['arac'] ?? []; $e = $v['ekspertiz'] ?? []; $k = $v['kisi'] ?? [];
    $elle = $a['elle'] ?? [];
    $isaret = fn(string $alan) => in_array($alan, $elle, true) ? ' <span class="y-rozet y-rozet-uyari" title="Müşteri listede bulamayıp elle yazdı">elle</span>' : '';
    $parcalar = [];
    foreach (T_PARCA as $p => $ad) { $d = $e['parcalar'][$p] ?? 'orijinal'; if ($d !== 'orijinal') { $parcalar[] = [$ad, T_PARCA_DURUM[$d] ?? $d, $d]; } }
    $wa = tel_wa((string)($k['tel'] ?? ''));
    sablon_bas('Teklif ' . $v['no']); mesaj(); ?>
  <nav class="y-yol"><a href="teklifler.php">Teklifler</a> › <?= h($v['no']) ?></nav>
  <div class="y-bas"><h1><?= h(arac_yazi($a)) ?> <small>Teklif no <?= h($v['no']) ?> · <?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?> · <?= h(T_ISTEK[$v['istek']] ?? $v['istek']) ?> · form dili: <?= h(T_DIL[$v['dil']] ?? $v['dil']) ?></small></h1>
    <span class="y-durum y-durum-<?= h($v['durum']) ?>"><?= h(TEKLIF_DURUMLARI[$v['durum']] ?? $v['durum']) ?></span></div>
  <div class="y-detay">
    <section class="y-kart"><h2>Müşteri</h2>
      <dl class="y-dl"><dt>Ad soyad</dt><dd><?= h($k['ad'] ?? '') ?></dd><dt>Telefon</dt><dd><?= h($k['tel'] ?? '') ?></dd><?php if (!empty($k['not'])): ?><dt>Notu</dt><dd><?= nl2br(h($k['not'])) ?></dd><?php endif; ?></dl>
      <p class="y-dugmeler"><a class="y-btn" href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)($k['tel'] ?? ''))) ?>">Ara</a>
        <?php if ($wa): ?><a class="y-btn y-btn-cizgi" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('Merhaba ' . ($k['ad'] ?? '') . ', Balkar Otomotiv\'den yazıyoruz. ' . arac_yazi($a) . ' aracınızla ilgili teklifiniz (no ' . $v['no'] . ') hakkında...') ?>">WhatsApp'tan yaz</a><?php endif; ?></p>
    </section>
    <section class="y-kart"><h2>Araç</h2>
      <dl class="y-dl">
        <dt>Marka</dt><dd><?= h($a['marka'] ?? '') . $isaret('marka') ?></dd>
        <dt>Yıl</dt><dd><?= h($a['yil'] ?? '') . $isaret('yil') ?></dd>
        <dt>Model</dt><dd><?= h($a['model'] ?? '') . $isaret('model') ?></dd>
        <?php if (!empty($a['versiyon'])): ?><dt>Versiyon</dt><dd><?= h($a['versiyon']) . $isaret('versiyon') ?></dd><?php endif; ?>
        <?php if (!empty($a['kasa'])): ?><dt>Kasa tipi</dt><dd><?= h(T_KASA[$a['kasa']] ?? $a['kasa']) . $isaret('kasa') ?></dd><?php endif; ?>
        <?php if (!empty($a['donanim'])): ?><dt>Donanım</dt><dd><?= h($a['donanim']) ?></dd><?php endif; ?>
        <dt>Şanzıman</dt><dd><?= h(vites_y((string)($a['vites'] ?? ''))) . $isaret('vites') ?></dd>
        <dt>Yakıt</dt><dd><?= h(yakit_y((string)($a['yakit'] ?? ''))) . $isaret('yakit') ?></dd>
        <dt>Motor</dt><dd><?= h($a['motor'] ?? '') . $isaret('motor') ?></dd>
        <dt>Kilometre</dt><dd><?= ($a['km'] ?? '') !== '' ? number_format((int)$a['km'], 0, ',', '.') . ' km' : '—' ?></dd>
        <?php if (!empty($a['renk'])): ?><dt>Renk</dt><dd><?= h($a['renk']) ?></dd><?php endif; ?>
        <dt>Beklediği fiyat</dt><dd><?= h(tl($a['beklenti'] ?? '')) ?: '—' ?></dd>
      </dl>
    </section>
    <section class="y-kart"><h2>Ekspertiz (müşteri beyanı)</h2>
      <dl class="y-dl">
        <dt>Tramer</dt><dd><?= h(T_YVH[$e['tramer'] ?? ''] ?? '') ?><?= ($e['tramer'] ?? '') === 'var' && !empty($e['tramer_tutar']) ? ' – ' . h(tl($e['tramer_tutar'])) : '' ?></dd>
        <dt>Ağır hasar kaydı</dt><dd><?= h(T_YVH[$e['agir'] ?? ''] ?? '') ?></dd>
        <dt>Boya / değişen</dt><dd><?php if (!empty($e['parca_bilinmiyor'])): ?>Müşteri bilmiyor, ekspertizde bakılacak<?php elseif (!$parcalar): ?>Tüm parçalar orijinal<?php else: ?>
          <ul class="y-parca"><?php foreach ($parcalar as [$ad, $d, $kod]): ?><li class="y-parca-<?= h($kod) ?>"><?= h($ad) ?>: <b><?= h($d) ?></b></li><?php endforeach; ?></ul><?php endif; ?></dd>
        <?php if (!empty($e['hasar_not'])): ?><dt>Hasar notu</dt><dd><?= h($e['hasar_not']) ?></dd><?php endif; ?>
        <dt>Fotoğraflar</dt><dd><?php if (!empty($e['foto'])): ?><a href="<?= h($e['foto']) ?>" target="_blank" rel="noopener"><?= (int)($e['foto_adet'] ?? 0) ?> fotoğrafı aç</a> <small class="y-not">(30 gün saklanır)</small><?php elseif (!empty($e['foto_adet'])): ?><?= (int)$e['foto_adet'] ?> fotoğraf seçildi ama yüklenemedi; WhatsApp'tan isteyin.<?php else: ?>—<?php endif; ?></dd>
      </dl>
    </section>
    <section class="y-kart"><h2>Galeri takibi</h2>
      <form method="post" action="teklifler.php?s=guncelle"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>">
        <label>Durum<select name="durum"><?php foreach (TEKLIF_DURUMLARI as $d => $ad): ?><option value="<?= $d ?>"<?= $v['durum'] === $d ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></label>
        <label>Galeri notu (yalnızca panelde görünür; ör. verilen fiyat, ekspertiz sonucu)<textarea name="galeri_notu" rows="4" maxlength="1000"><?= h($v['galeri_notu'] ?? '') ?></textarea></label>
        <button class="y-btn">Kaydet</button>
      </form>
      <details class="y-sil"><summary>Bu teklifi sil</summary><form method="post" action="teklifler.php?s=sil"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>">
        <label class="y-onay"><input type="checkbox" name="onay" value="1" required> Evet, kalıcı olarak silinsin</label><button class="y-btn y-btn-cizgi">Sil</button></form></details>
      <p class="y-not">Kayıtlar gönderimden <?= T_KAYIT_SAKLAMA_GUN ?> gün sonra (<?= h(date('d.m.Y', filemtime($f) + T_KAYIT_SAKLAMA_GUN * 86400)) ?>) otomatik silinir.</p>
    </section>
  </div>
<?php sablon_son(); exit;
}

/* ---------- liste */
$d = (string)($_GET['d'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
$hepsi = [];
foreach (glob(TEKLIF_KLASOR . '/*/*.json') ?: [] as $f) { $v = teklif_oku($f); if ($v) { $hepsi[] = $v; } }
usort($hepsi, fn($a, $b) => strcmp((string)$b['tarih'], (string)$a['tarih']));
$sayim = array_fill_keys(array_keys(TEKLIF_DURUMLARI), 0);
foreach ($hepsi as $v) { if (isset($sayim[$v['durum']])) { $sayim[$v['durum']]++; } }
$liste = array_filter($hepsi, function ($v) use ($d, $q) {
    if ($d !== '' && $v['durum'] !== $d) { return false; }
    if ($q === '') { return true; }
    $metin = mb_strtolower(implode(' ', [$v['no'], arac_yazi($v['arac'] ?? []), $v['arac']['motor'] ?? '', $v['kisi']['ad'] ?? '', $v['kisi']['tel'] ?? '', preg_replace('/\D/', '', (string)($v['kisi']['tel'] ?? ''))]));
    return str_contains($metin, mb_strtolower($q));
});
sablon_bas('Teklifler'); mesaj(); ?>
  <div class="y-bas"><h1>Aracını sat teklifleri <small>Sitedeki formdan gelen talepler (<?= count($hepsi) ?>). Müşteri ayrıca aynı bilgileri WhatsApp'tan gönderir; teklif numarası iki yerde de aynıdır.</small></h1>
    <form method="get" class="y-satir"><?php if ($d !== ''): ?><input type="hidden" name="d" value="<?= h($d) ?>"><?php endif; ?><label>Ara (no, araç, ad, telefon)<input type="search" name="q" value="<?= h($q) ?>"></label><button class="y-btn y-btn-cizgi">Ara</button></form></div>
  <nav class="y-sekme"><a href="teklifler.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>"<?= $d === '' ? ' aria-current="page"' : '' ?>>Tümü (<?= count($hepsi) ?>)</a>
    <?php foreach (TEKLIF_DURUMLARI as $k => $ad): ?><a href="?<?= h(http_build_query(array_filter(['d' => $k, 'q' => $q]))) ?>"<?= $d === $k ? ' aria-current="page"' : '' ?>><?= h($ad) ?> (<?= $sayim[$k] ?>)</a><?php endforeach; ?></nav>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Tarih</th><th>Araç</th><th>Km</th><th>İstek</th><th>Müşteri</th><th>Durum</th></tr>
    <?php foreach ($liste as $v): $a = $v['arac'] ?? []; $k = $v['kisi'] ?? []; ?>
      <tr<?= $v['durum'] === 'yeni' ? ' class="y-yeni"' : '' ?>><td><?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?><br><small><?= h($v['no']) ?></small></td>
        <td><a href="teklifler.php?no=<?= h(rawurlencode($v['no'])) ?>"><?= h(arac_yazi($a)) ?></a><br><small><?= h(implode(' · ', array_filter([$a['versiyon'] ?? '', $a['motor'] ?? '', yakit_y((string)($a['yakit'] ?? '')), vites_y((string)($a['vites'] ?? ''))]))) ?></small></td>
        <td><?= ($a['km'] ?? '') !== '' ? number_format((int)$a['km'], 0, ',', '.') : '—' ?></td>
        <td><?= h(T_ISTEK[$v['istek']] ?? $v['istek']) ?></td>
        <td><?= h($k['ad'] ?? '') ?><br><a href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)($k['tel'] ?? ''))) ?>"><?= h($k['tel'] ?? '') ?></a></td>
        <td><form method="post" action="teklifler.php?s=durum" class="y-durum-form"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>"><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <select name="durum" onchange="this.form.submit()" aria-label="Durum"><?php foreach (TEKLIF_DURUMLARI as $dk => $ad): ?><option value="<?= $dk ?>"<?= $v['durum'] === $dk ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
          <noscript><button>Kaydet</button></noscript></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="6" class="y-not"><?= $hepsi ? 'Bu filtreye uyan teklif yok.' : 'Henüz teklif yok. Sitedeki "Aracını sat" formu gönderildikçe burada listelenir.' ?></td></tr><?php endif; ?>
  </table></div></section>
  <p class="y-not">Teklifler <?= T_KAYIT_SAKLAMA_GUN ?> gün sonra otomatik silinir (KVKK metninde yazılı). Fotoğraflar 30 gün saklanır.</p>
<?php sablon_son();
