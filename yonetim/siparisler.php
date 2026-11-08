<?php
/* Balkar Otomotiv – ön siparişler (sitedeki "Ön sipariş" formundan gelen araç arama talepleri, Aşama 39).
   Kayıtlar: veri/siparisler/<YYYY-AA>/<no>.json (teklif/siparis.php yazar), 180 gün sonra otomatik silinir.
   Detayda, talebe uyan ve şu an satışta olan araçlar (veri/araclar.json) listelenir. Panel yalnızca Türkçedir. */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/../teklif/_ayar.php';

if (sifre_hash() === null || !giris_var()) { git('index.php'); }
$_SESSION['zaman'] = time();

const S_KASA = ['hatchback' => 'Hatchback', 'sedan' => 'Sedan', 'station' => 'Station Wagon', 'suv' => 'SUV', 'crossover' => 'Crossover', 'coupe' => 'Coupe', 'cabrio' => 'Cabrio', 'roadster' => 'Roadster', 'mpv' => 'MPV', 'minivan' => 'Minivan', 'pickup' => 'Pick-Up', 'panelvan' => 'Panelvan', 'kombi' => 'Kombi', 'kombivan' => 'Kombivan', 'kamyonet' => 'Kamyonet', 'minibus' => 'Minibüs'];
const S_YAKIT = ['benzin' => 'Benzin', 'dizel' => 'Dizel', 'lpg' => 'Benzin & LPG', 'hibrit' => 'Hibrit', 'elektrik' => 'Elektrik'];
const S_VITES = ['manuel' => 'Manuel', 'otomatik' => 'Otomatik'];
const S_HASAR = ['agirsiz' => 'Ağır hasar kaydı olmasın', 'degisensiz' => 'Değişen parça olmasın', 'hatasiz' => 'Boyasız ve değişensiz'];
const S_ODEME = ['nakit' => 'Nakit / havale', 'kredi' => 'Taşıt kredisi', 'kart' => 'Kredi kartına taksit', 'takas' => 'Takas', 'tasarruf' => 'Tasarruf finansmanı'];
const S_ZAMAN = ['hemen' => 'Hemen', 'ay' => '1 ay içinde', 'uc' => '1–3 ay içinde', 'acele' => 'Acelesi yok'];
const S_ULASIM = ['ara' => 'Telefonla aransın', 'wa' => 'WhatsApp\'tan yazılsın'];
const S_DIL = ['tr' => 'Türkçe', 'en' => 'İngilizce', 'ru' => 'Rusça', 'de' => 'Almanca'];

function siparis_dosya(string $no): ?string {
    if (!s_kayit_no_gecerli($no)) { return null; }
    $l = glob(SIPARIS_KLASOR . '/*/' . $no . '.json') ?: [];
    return $l[0] ?? null;
}
function siparis_oku(string $f): ?array { $v = json_decode((string)@file_get_contents($f), true); return is_array($v) && isset($v['no']) ? $v : null; }
function siparis_yaz(string $f, array $v): void {
    file_put_contents($f . '.tmp', json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    $mt = filemtime($f); rename($f . '.tmp', $f); @touch($f, $mt);   // saklama süresi gönderim tarihinden sayılır
}
function s_tl($s): string { return $s !== '' && $s !== null ? number_format((int)$s, 0, ',', '.') . ' TL' : ''; }
function s_yil(array $a): string {
    $x = $a['yil_min'] ?? ''; $y = $a['yil_max'] ?? '';
    if ($x !== '' && $y !== '') { return $x === $y ? $x : "$x – $y"; }
    if ($x !== '') { return "$x ve sonrası"; }
    if ($y !== '') { return "$y ve öncesi"; }
    return 'Farketmez';
}
function s_arac(array $a): string {
    $m = trim(($a['marka'] ?? '') . ' ' . ($a['model'] ?? ''));
    return $m !== '' ? $m : 'Marka farketmez';
}
function s_butce(array $b): string {
    $min = $b['min'] ?? ''; $max = $b['max'] ?? '';
    return $min !== '' ? s_tl($min) . ' – ' . s_tl($max) : 'en fazla ' . s_tl($max);
}
function s_liste(array $l, array $ad): string { return $l ? implode(', ', array_map(fn($k) => $ad[$k] ?? $k, $l)) : 'Farketmez'; }
function s_tel_wa(string $tel): string {
    $r = preg_replace('/\D/', '', $tel) ?? '';
    if (str_starts_with($r, '00')) { $r = substr($r, 2); }
    elseif (str_starts_with($r, '0')) { $r = '90' . substr($r, 1); }
    elseif (strlen($r) === 10 && str_starts_with($r, '5')) { $r = '90' . $r; }
    return $r;
}
function s_anahtar(string $s): string { return anahtar($s); }
/** Talebe uyan, şu an satışta olan araçlar (sitedeki "stoktaki uygun araçlar" ile aynı mantık). */
function uygun_araclar(array $v): array {
    $a = $v['arac'] ?? []; $b = $v['butce'] ?? [];
    $mk = s_anahtar((string)($a['marka'] ?? '')); $md = s_anahtar((string)($a['model'] ?? ''));
    $sonuc = [];
    static $araclar = null;
    $araclar ??= veri_oku()['araclar'];
    foreach ($araclar as $x) {
        if (($x['durum'] ?? '') === 'satildi') { continue; }
        if ($mk !== '') { $am = s_anahtar((string)($x['marka'] ?? '')); if ($am === '' || (!str_contains($am, $mk) && !str_contains($mk, $am))) { continue; } }
        if ($md !== '') { $metin = s_anahtar(($x['seri'] ?? '') . ' ' . ($x['model'] ?? '')); $seri = s_anahtar((string)($x['seri'] ?? '')); if (!str_contains($metin, $md) && ($seri === '' || !str_contains($md, $seri))) { continue; } }
        if (($a['yil_min'] ?? '') !== '' && (int)($x['yil'] ?? 0) < (int)$a['yil_min']) { continue; }
        if (($a['yil_max'] ?? '') !== '' && (int)($x['yil'] ?? 9999) > (int)$a['yil_max']) { continue; }
        if (($a['km_max'] ?? '') !== '' && (int)($x['km'] ?? 0) > (int)$a['km_max']) { continue; }
        if (($b['max'] ?? '') !== '' && (empty($x['fiyat']) || (int)$x['fiyat'] > (int)$b['max'])) { continue; }
        $vt = $a['vites'] ?? ''; $xv = $x['vites'] ?? '';
        if ($vt !== '' && $xv !== '' && ($vt === 'otomatik' ? $xv === 'manuel' : $xv !== $vt)) { continue; }
        $yk = $a['yakit'] ?? [];
        if ($yk && !in_array($x['yakit'] ?? '', $yk, true) && !(($x['yakit'] ?? '') === 'lpg' && in_array('benzin', $yk, true))) { continue; }
        $ks = $a['kasa'] ?? ''; $xk = $x['kasa'] ?? '';
        if ($ks !== '' && $xk !== '' && $xk !== $ks && !(in_array($ks, ['suv', 'crossover'], true) && in_array($xk, ['suv', 'crossover'], true))) { continue; }
        $hz = $x['hasar'] ?? []; $dolu = fn($d) => is_array($d) ? trim((string)($d['tr'] ?? '')) !== '' : trim((string)$d) !== '';
        $hs = $a['hasar'] ?? '';
        if ($hs === 'agirsiz' && !empty($hz['agir_hasar'])) { continue; }
        if (in_array($hs, ['degisensiz', 'hatasiz'], true) && $dolu($hz['degisen'] ?? '')) { continue; }
        if ($hs === 'hatasiz' && ($dolu($hz['boya'] ?? '') || $dolu($hz['lokal'] ?? ''))) { continue; }
        $sonuc[] = $x;
    }
    return $sonuc;
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    csrf_dogrula();
    $s = (string)($_GET['s'] ?? '');
    $no = (string)($_POST['no'] ?? '');
    $f = siparis_dosya($no);
    if (!$f || !($v = siparis_oku($f))) { git('siparisler.php', 'Talep bulunamadı (süresi dolmuş olabilir).', true); }
    if ($s === 'guncelle') {
        $d = (string)($_POST['durum'] ?? '');
        if (isset(SIPARIS_DURUMLARI[$d])) { $v['durum'] = $d; }
        $v['galeri_notu'] = mb_substr(trim((string)($_POST['galeri_notu'] ?? '')), 0, 1000);
        $v['guncelleme'] = date('c');
        siparis_yaz($f, $v);
        git('siparisler.php?no=' . rawurlencode($no), 'Talep güncellendi.');
    }
    if ($s === 'durum') {
        $d = (string)($_POST['durum'] ?? '');
        if (isset(SIPARIS_DURUMLARI[$d])) { $v['durum'] = $d; $v['guncelleme'] = date('c'); siparis_yaz($f, $v); }
        git('siparisler.php?' . http_build_query(array_filter(['d' => $_POST['d'] ?? '', 'q' => $_POST['q'] ?? ''])), "$no: " . (SIPARIS_DURUMLARI[$d] ?? ''));
    }
    if ($s === 'sil') {
        if (empty($_POST['onay'])) { git('siparisler.php?no=' . rawurlencode($no), 'Silmek için onay kutusunu işaretleyin.', true); }
        @unlink($f);
        git('siparisler.php', "$no numaralı talep silindi.");
    }
    git('siparisler.php', 'Bilinmeyen işlem.', true);
}

if (is_dir(S_KAYIT_KLASOR)) { s_kayit_temizle(); }

/* ---------- detay */
if (isset($_GET['no'])) {
    $no = (string)$_GET['no']; $f = siparis_dosya($no); $v = $f ? siparis_oku($f) : null;
    if (!$v) { git('siparisler.php', 'Talep bulunamadı (süresi dolmuş olabilir).', true); }
    $a = $v['arac'] ?? []; $b = $v['butce'] ?? []; $k = $v['kisi'] ?? [];
    $elle = $a['elle'] ?? [];
    $isaret = fn(string $alan) => in_array($alan, $elle, true) ? ' <span class="y-rozet y-rozet-uyari" title="Müşteri listede bulamayıp elle yazdı">elle</span>' : '';
    $wa = s_tel_wa((string)($k['tel'] ?? ''));
    $uygun = uygun_araclar($v);
    sablon_bas('Ön sipariş ' . $v['no']); mesaj(); ?>
  <nav class="y-yol"><a href="siparisler.php">Ön siparişler</a> › <?= h($v['no']) ?></nav>
  <div class="y-bas"><h1><?= h(s_arac($a)) ?> <small>Talep no <?= h($v['no']) ?> · <?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?> · bütçe <?= h(s_butce($b)) ?> · form dili: <?= h(S_DIL[$v['dil']] ?? $v['dil']) ?></small></h1>
    <span class="y-durum y-durum-<?= h($v['durum']) ?>"><?= h(SIPARIS_DURUMLARI[$v['durum']] ?? $v['durum']) ?></span></div>
  <div class="y-detay">
    <section class="y-kart"><h2>Müşteri</h2>
      <dl class="y-dl"><dt>Ad soyad</dt><dd><?= h($k['ad'] ?? '') ?></dd><dt>Telefon</dt><dd><?= h($k['tel'] ?? '') ?></dd>
        <?php if (!empty($k['sehir'])): ?><dt>Şehir</dt><dd><?= h($k['sehir']) ?></dd><?php endif; ?>
        <dt>Ulaşım tercihi</dt><dd><?= h(S_ULASIM[$k['ulasim'] ?? ''] ?? 'Farketmez') ?></dd>
        <?php if (!empty($k['not'])): ?><dt>Notu</dt><dd><?= nl2br(h($k['not'])) ?></dd><?php endif; ?></dl>
      <p class="y-dugmeler"><a class="y-btn" href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)($k['tel'] ?? ''))) ?>">Ara</a>
        <?php if ($wa): ?><a class="y-btn y-btn-cizgi" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('Merhaba ' . ($k['ad'] ?? '') . ', Balkar Otomotiv\'den yazıyoruz. Aradığınız araçla ilgili ön sipariş talebiniz (no ' . $v['no'] . ') hakkında...') ?>">WhatsApp'tan yaz</a><?php endif; ?></p>
    </section>
    <section class="y-kart"><h2>Aranan araç</h2>
      <dl class="y-dl">
        <dt>Marka</dt><dd><?= ($a['marka'] ?? '') !== '' ? h($a['marka']) . $isaret('marka') : 'Farketmez (önerilere açık)' ?></dd>
        <dt>Model</dt><dd><?= ($a['model'] ?? '') !== '' ? h($a['model']) . $isaret('model') : 'Farketmez' ?></dd>
        <dt>Model yılı</dt><dd><?= h(s_yil($a)) ?></dd>
        <dt>Kilometre</dt><dd><?= ($a['km_max'] ?? '') !== '' ? 'en fazla ' . number_format((int)$a['km_max'], 0, ',', '.') . ' km' : 'Farketmez' ?></dd>
        <dt>Kasa tipi</dt><dd><?= h(S_KASA[$a['kasa'] ?? ''] ?? 'Farketmez') ?></dd>
        <dt>Şanzıman</dt><dd><?= h(S_VITES[$a['vites'] ?? ''] ?? 'Farketmez') ?></dd>
        <dt>Yakıt</dt><dd><?= h(s_liste($a['yakit'] ?? [], S_YAKIT)) ?></dd>
        <dt>Hasar durumu</dt><dd><?= h(S_HASAR[$a['hasar'] ?? ''] ?? 'Farketmez') ?></dd>
        <?php if (!empty($a['renk'])): ?><dt>Renk</dt><dd><?= h($a['renk']) ?></dd><?php endif; ?>
        <?php if (!empty($a['istekler'])): ?><dt>Özel istekler</dt><dd><?= h($a['istekler']) ?></dd><?php endif; ?>
      </dl>
    </section>
    <section class="y-kart"><h2>Bütçe ve ödeme</h2>
      <dl class="y-dl">
        <dt>Bütçe</dt><dd><?= h(s_butce($b)) ?></dd>
        <dt>Ödeme şekli</dt><dd><?= h($b['odeme'] ?? [] ? s_liste($b['odeme'], S_ODEME) : 'Belirtmedi') ?></dd>
        <?php if (!empty($b['takas_arac'])): ?><dt>Takas aracı</dt><dd><?= h($b['takas_arac']) ?></dd><?php endif; ?>
        <dt>Ne zaman</dt><dd><?= h(S_ZAMAN[$b['zaman'] ?? ''] ?? 'Belirtmedi') ?></dd>
      </dl>
    </section>
    <section class="y-kart"><h2>Stoktaki uygun araçlar (<?= count($uygun) ?>)</h2>
      <?php if ($uygun): ?><ul class="y-uygun"><?php foreach (array_slice($uygun, 0, 10) as $x): ?>
        <li><a href="../tr/arac.html?id=<?= h(rawurlencode((string)$x['id'])) ?>" target="_blank" rel="noopener"><?= h(trim(($x['yil'] ?? '') . ' ' . ($x['marka'] ?? '') . ' ' . ($x['seri'] ?? '') . ' ' . ($x['model'] ?? ''))) ?></a>
          <small><?= h(implode(' · ', array_filter([isset($x['km']) ? number_format((int)$x['km'], 0, ',', '.') . ' km' : '', !empty($x['fiyat']) ? s_tl($x['fiyat']) : '']))) ?></small></li>
      <?php endforeach; ?></ul><p class="y-not">Müşteriye bu araçları önerebilirsiniz (sitede de özet adımında gösterildi).</p>
      <?php else: ?><p class="y-not">Şu an satıştaki araçlardan bu talebe uyan yok. Araç bulununca durumu "Araç önerildi" yapın.</p><?php endif; ?>
    </section>
    <section class="y-kart"><h2>Galeri takibi</h2>
      <form method="post" action="siparisler.php?s=guncelle"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>">
        <label>Durum<select name="durum"><?php foreach (SIPARIS_DURUMLARI as $d => $ad): ?><option value="<?= $d ?>"<?= $v['durum'] === $d ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></label>
        <label>Galeri notu (yalnızca panelde görünür; ör. bakılan araçlar, verilen fiyat)<textarea name="galeri_notu" rows="4" maxlength="1000"><?= h($v['galeri_notu'] ?? '') ?></textarea></label>
        <button class="y-btn">Kaydet</button>
      </form>
      <details class="y-sil"><summary>Bu talebi sil</summary><form method="post" action="siparisler.php?s=sil"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>">
        <label class="y-onay"><input type="checkbox" name="onay" value="1" required> Evet, kalıcı olarak silinsin</label><button class="y-btn y-btn-cizgi">Sil</button></form></details>
      <p class="y-not">Kayıtlar gönderimden <?= S_KAYIT_SAKLAMA_GUN ?> gün sonra (<?= h(date('d.m.Y', filemtime($f) + S_KAYIT_SAKLAMA_GUN * 86400)) ?>) otomatik silinir.</p>
    </section>
  </div>
<?php sablon_son(); exit;
}

/* ---------- liste */
$d = (string)($_GET['d'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
$hepsi = [];
foreach (glob(SIPARIS_KLASOR . '/*/*.json') ?: [] as $f) { $v = siparis_oku($f); if ($v) { $hepsi[] = $v; } }
usort($hepsi, fn($a, $b) => strcmp((string)$b['tarih'], (string)$a['tarih']));
$sayim = array_fill_keys(array_keys(SIPARIS_DURUMLARI), 0);
foreach ($hepsi as $v) { if (isset($sayim[$v['durum']])) { $sayim[$v['durum']]++; } }
$liste = array_filter($hepsi, function ($v) use ($d, $q) {
    if ($d !== '' && $v['durum'] !== $d) { return false; }
    if ($q === '') { return true; }
    $metin = mb_strtolower(implode(' ', [$v['no'], s_arac($v['arac'] ?? []), $v['kisi']['ad'] ?? '', $v['kisi']['tel'] ?? '', preg_replace('/\D/', '', (string)($v['kisi']['tel'] ?? ''))]));
    return str_contains($metin, mb_strtolower($q));
});
sablon_bas('Ön siparişler'); mesaj(); ?>
  <div class="y-bas"><h1>Ön siparişler <small>Sitedeki "Ön sipariş" formundan gelen araç arama talepleri (<?= count($hepsi) ?>). Müşteri ayrıca aynı bilgileri WhatsApp'tan gönderir; talep numarası (S ile başlar) iki yerde de aynıdır.</small></h1>
    <form method="get" class="y-satir"><?php if ($d !== ''): ?><input type="hidden" name="d" value="<?= h($d) ?>"><?php endif; ?><label>Ara (no, araç, ad, telefon)<input type="search" name="q" value="<?= h($q) ?>"></label><button class="y-btn y-btn-cizgi">Ara</button></form></div>
  <nav class="y-sekme"><a href="siparisler.php<?= $q !== '' ? '?q=' . rawurlencode($q) : '' ?>"<?= $d === '' ? ' aria-current="page"' : '' ?>>Tümü (<?= count($hepsi) ?>)</a>
    <?php foreach (SIPARIS_DURUMLARI as $k => $ad): ?><a href="?<?= h(http_build_query(array_filter(['d' => $k, 'q' => $q]))) ?>"<?= $d === $k ? ' aria-current="page"' : '' ?>><?= h($ad) ?> (<?= $sayim[$k] ?>)</a><?php endforeach; ?></nav>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Tarih</th><th>Aranan araç</th><th>Bütçe</th><th>Stokta</th><th>Müşteri</th><th>Durum</th></tr>
    <?php foreach ($liste as $v): $a = $v['arac'] ?? []; $k = $v['kisi'] ?? []; $n = count(uygun_araclar($v)); ?>
      <tr<?= $v['durum'] === 'yeni' ? ' class="y-yeni"' : '' ?>><td><?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?><br><small><?= h($v['no']) ?></small></td>
        <td><a href="siparisler.php?no=<?= h(rawurlencode($v['no'])) ?>"><?= h(s_arac($a)) ?></a><br><small><?= h(implode(' · ', array_filter([s_yil($a) !== 'Farketmez' ? s_yil($a) : '', ($a['km_max'] ?? '') !== '' ? '≤ ' . number_format((int)$a['km_max'], 0, ',', '.') . ' km' : '', S_VITES[$a['vites'] ?? ''] ?? '', ($a['yakit'] ?? []) ? s_liste($a['yakit'], S_YAKIT) : '']))) ?></small></td>
        <td><?= h(s_butce($v['butce'] ?? [])) ?></td>
        <td><?= $n ? '<a href="siparisler.php?no=' . h(rawurlencode($v['no'])) . '">' . $n . ' araç</a>' : '—' ?></td>
        <td><?= h($k['ad'] ?? '') ?><br><a href="tel:<?= h(preg_replace('/[^\d+]/', '', (string)($k['tel'] ?? ''))) ?>"><?= h($k['tel'] ?? '') ?></a></td>
        <td><form method="post" action="siparisler.php?s=durum" class="y-durum-form"><?= csrf_alan() ?><input type="hidden" name="no" value="<?= h($v['no']) ?>"><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <select name="durum" onchange="this.form.submit()" aria-label="Durum"><?php foreach (SIPARIS_DURUMLARI as $dk => $ad): ?><option value="<?= $dk ?>"<?= $v['durum'] === $dk ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
          <noscript><button>Kaydet</button></noscript></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="6" class="y-not"><?= $hepsi ? 'Bu filtreye uyan talep yok.' : 'Henüz ön sipariş yok. Sitedeki "Ön sipariş" formu gönderildikçe burada listelenir.' ?></td></tr><?php endif; ?>
  </table></div></section>
  <p class="y-not">Talepler <?= S_KAYIT_SAKLAMA_GUN ?> gün sonra otomatik silinir (KVKK metninde yazılı).</p>
<?php sablon_son();
