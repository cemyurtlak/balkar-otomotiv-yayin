<?php
/* Balkar Otomotiv – Tüm kayıtlar (Aşama 49). Sitedeki her WhatsApp gönderiminin veritabanındaki kopyası:
   aracını sat, ön sipariş, randevu formu ve WhatsApp bağlantısı tıklamaları (araç sorusu, kredi, ödeme, genel).
   Veritabanı: veri/veritabani/balkar.sqlite (teklif/_db.php). Excel'e aktarma: ?s=csv. Panel yalnızca Türkçedir. */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../teklif/_db.php';

if (sifre_hash() === null || !giris_var()) { git('index.php'); }
$_SESSION['zaman'] = time();

const TALEP_DURUMLARI = ['yeni' => 'Yeni', 'goruldu' => 'İncelendi', 'donuldu' => 'Dönüş yapıldı', 'kapandi' => 'Kapandı'];
const T_DIL = ['tr' => 'Türkçe', 'en' => 'İngilizce', 'ru' => 'Rusça', 'de' => 'Almanca'];

$pdo = db();

/* Veritabanı açılamadığı dönemde JSON satır dosyasına düşen kayıtlar, veritabanı çalışınca içeri alınır. */
if ($pdo) {
    foreach (glob(DB_KLASOR . '/talepler-*.jsonl') ?: [] as $jf) {
        $ekle = $pdo->prepare('INSERT INTO talepler (tarih, tur, no, dil, ad, tel, ozet, mesaj, veri, sayfa) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $pdo->beginTransaction();
        foreach (file($jf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $satir) {
            $k = json_decode($satir, true);
            if (!is_array($k) || empty($k['tarih'])) { continue; }
            $ekle->execute([$k['tarih'], $k['tur'] ?? 'genel', $k['no'] ?? '', $k['dil'] ?? 'tr', $k['ad'] ?? '', $k['tel'] ?? '', $k['ozet'] ?? '', $k['mesaj'] ?? '',
                json_encode($k['veri'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $k['sayfa'] ?? '']);
        }
        $pdo->commit();
        @rename($jf, $jf . '.aktarildi');
    }
    db_temizle();
}

function t_tel_wa(string $tel): string {
    $r = preg_replace('/\D/', '', $tel) ?? '';
    if (str_starts_with($r, '00')) { $r = substr($r, 2); }
    elseif (str_starts_with($r, '0')) { $r = '90' . substr($r, 1); }
    elseif (strlen($r) === 10 && str_starts_with($r, '5')) { $r = '90' . $r; }
    return $r;
}
/** Liste filtresi (tür, durum, arama) → SQL koşulu. */
function t_kosul(string $tur, string $d, string $q, array &$p): string {
    $k = [];
    if ($tur === 'form') { $k[] = "tur IN ('teklif','siparis','randevu')"; }
    elseif ($tur === 'tik') { $k[] = "tur NOT IN ('teklif','siparis','randevu')"; }
    elseif ($tur !== '') { $k[] = 'tur = ?'; $p[] = $tur; }
    if ($d !== '') { $k[] = 'durum = ?'; $p[] = $d; }
    if ($q !== '') {
        $k[] = "(no LIKE ? OR ad LIKE ? OR tel LIKE ? OR ozet LIKE ? OR mesaj LIKE ? OR REPLACE(REPLACE(tel,' ',''),'-','') LIKE ?)";
        $l = '%' . $q . '%'; array_push($p, $l, $l, $l, $l, $l, '%' . preg_replace('/\D/', '', $q) . '%');
        if (preg_replace('/\D/', '', $q) === '') { array_pop($p); $p[] = $l; }
    }
    return $k ? ' WHERE ' . implode(' AND ', $k) : '';
}
function geri_adres(): string { return 'talepler.php?' . http_build_query(array_filter(['tur' => $_POST['tur_f'] ?? '', 'd' => $_POST['d'] ?? '', 'q' => $_POST['q'] ?? ''])); }

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) {
    csrf_dogrula();
    if (!$pdo) { git('talepler.php', 'Veritabanı açılamadı.', true); }
    $s = (string)($_GET['s'] ?? ''); $id = (int)($_POST['id'] ?? 0);
    $v = $pdo->prepare('SELECT * FROM talepler WHERE id = ?'); $v->execute([$id]); $v = $v->fetch();
    if (!$v) { git('talepler.php', 'Kayıt bulunamadı (süresi dolmuş olabilir).', true); }
    if ($s === 'guncelle' || $s === 'durum') {
        $d = (string)($_POST['durum'] ?? '');
        if (!isset(TALEP_DURUMLARI[$d])) { $d = $v['durum']; }
        if ($s === 'guncelle') {
            $pdo->prepare('UPDATE talepler SET durum = ?, galeri_notu = ? WHERE id = ?')->execute([$d, mb_substr(trim((string)($_POST['galeri_notu'] ?? '')), 0, 1000), $id]);
            git('talepler.php?id=' . $id, 'Kayıt güncellendi.');
        }
        $pdo->prepare('UPDATE talepler SET durum = ? WHERE id = ?')->execute([$d, $id]);
        git(geri_adres(), '#' . $id . ': ' . TALEP_DURUMLARI[$d]);
    }
    if ($s === 'sil') {
        if (empty($_POST['onay'])) { git('talepler.php?id=' . $id, 'Silmek için onay kutusunu işaretleyin.', true); }
        $pdo->prepare('DELETE FROM talepler WHERE id = ?')->execute([$id]);
        git('talepler.php', 'Kayıt silindi. (Aracını sat / ön sipariş ise kendi sayfasındaki kayıt durur.)');
    }
    git('talepler.php', 'Bilinmeyen işlem.', true);
}

$tur = (string)($_GET['tur'] ?? ''); $d = (string)($_GET['d'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
if ($tur !== '' && !in_array($tur, ['form', 'tik'], true) && !isset(DB_TURLER[$tur])) { $tur = ''; }
if ($d !== '' && !isset(TALEP_DURUMLARI[$d])) { $d = ''; }

/* ---------- Excel (CSV) */
if (($_GET['s'] ?? '') === 'csv' && $pdo) {
    $p = []; $w = t_kosul($tur, $d, $q, $p);
    $st = $pdo->prepare('SELECT * FROM talepler' . $w . ' ORDER BY tarih DESC'); $st->execute($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="balkar-kayitlar-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");   // Excel Türkçe karakterleri doğru açsın
    fputcsv($o, ['No', 'Tarih', 'Tür', 'Ad soyad', 'Telefon', 'Özet', 'WhatsApp mesajı', 'Durum', 'Galeri notu', 'Dil', 'Sayfa'], ';');
    while ($r = $st->fetch()) {
        fputcsv($o, [$r['no'] !== '' ? $r['no'] : '#' . $r['id'], date('d.m.Y H:i', strtotime($r['tarih'])), DB_TURLER[$r['tur']] ?? $r['tur'], $r['ad'], $r['tel'], $r['ozet'],
            str_replace('*', '', $r['mesaj']), TALEP_DURUMLARI[$r['durum']] ?? $r['durum'], $r['galeri_notu'], T_DIL[$r['dil']] ?? $r['dil'], $r['sayfa']], ';');
    }
    fclose($o); exit;
}

/* ---------- detay */
if (isset($_GET['id']) && $pdo) {
    $v = $pdo->prepare('SELECT * FROM talepler WHERE id = ?'); $v->execute([(int)$_GET['id']]); $v = $v->fetch();
    if (!$v) { git('talepler.php', 'Kayıt bulunamadı (süresi dolmuş olabilir).', true); }
    $veri = json_decode($v['veri'], true) ?: [];
    $wa = t_tel_wa($v['tel']);
    $baglanti = $v['tur'] === 'teklif' ? 'teklifler.php?no=' . rawurlencode($v['no']) : ($v['tur'] === 'siparis' ? 'siparisler.php?no=' . rawurlencode($v['no']) : '');
    sablon_bas('Kayıt #' . $v['id']); mesaj(); ?>
  <nav class="y-yol"><a href="talepler.php">Tüm kayıtlar</a> › <?= h($v['no'] !== '' ? $v['no'] : '#' . $v['id']) ?></nav>
  <div class="y-bas"><h1><?= h(DB_TURLER[$v['tur']] ?? $v['tur']) ?> <small><?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?> · dil: <?= h(T_DIL[$v['dil']] ?? $v['dil']) ?><?= $v['sayfa'] !== '' ? ' · sayfa: ' . h($v['sayfa']) : '' ?></small></h1>
    <span class="y-durum y-durum-<?= h($v['durum']) ?>"><?= h(TALEP_DURUMLARI[$v['durum']] ?? $v['durum']) ?></span></div>
  <div class="y-detay">
    <section class="y-kart"><h2>WhatsApp'a giden mesaj</h2>
      <pre class="y-mesaj"><?= h(str_replace('*', '', $v['mesaj'])) ?></pre>
      <?php if ($baglanti): ?><p><a class="y-btn y-btn-cizgi" href="<?= h($baglanti) ?>">Ayrıntılı kaydı aç (<?= $v['tur'] === 'teklif' ? 'Teklifler' : 'Ön siparişler' ?>)</a></p><?php endif; ?>
      <?php if (!in_array($v['tur'], ['teklif', 'siparis', 'randevu'], true)): ?><p class="y-not">Bu bir WhatsApp düğmesi tıklamasıdır: ziyaretçi bu mesajla WhatsApp'ı açtı. Mesajı gönderip göndermediği ve kim olduğu bilinmez; WhatsApp'tan yazdıysa sohbet orada görünür.</p><?php endif; ?>
    </section>
    <?php if ($v['ad'] !== '' || $v['tel'] !== ''): ?><section class="y-kart"><h2>Müşteri</h2>
      <dl class="y-dl"><dt>Ad soyad</dt><dd><?= h($v['ad']) ?></dd><dt>Telefon</dt><dd><?= h($v['tel']) ?></dd>
        <?php if ($v['tur'] === 'randevu'): foreach (['arac' => 'Araç', 'tarih' => 'Tarih', 'saat' => 'Saat', 'test' => 'Test sürüşü', 'not' => 'Not'] as $k => $ad): if (!empty($veri[$k])): ?><dt><?= h($ad) ?></dt><dd><?= nl2br(h((string)$veri[$k])) ?></dd><?php endif; endforeach; endif; ?></dl>
      <p class="y-dugmeler"><a class="y-btn" href="tel:<?= h(preg_replace('/[^\d+]/', '', $v['tel'])) ?>">Ara</a>
        <?php if ($wa): ?><a class="y-btn y-btn-cizgi" target="_blank" rel="noopener" href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('Merhaba ' . $v['ad'] . ', Balkar Otomotiv\'den yazıyoruz. Sitemizden gönderdiğiniz talep hakkında...') ?>">WhatsApp'tan yaz</a><?php endif; ?></p>
    </section><?php endif; ?>
    <section class="y-kart"><h2>Galeri takibi</h2>
      <form method="post" action="talepler.php?s=guncelle"><?= csrf_alan() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <label>Durum<select name="durum"><?php foreach (TALEP_DURUMLARI as $dk => $ad): ?><option value="<?= $dk ?>"<?= $v['durum'] === $dk ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select></label>
        <label>Galeri notu (yalnızca panelde görünür)<textarea name="galeri_notu" rows="4" maxlength="1000"><?= h($v['galeri_notu']) ?></textarea></label>
        <button class="y-btn">Kaydet</button>
      </form>
      <details class="y-sil"><summary>Bu kaydı sil</summary><form method="post" action="talepler.php?s=sil"><?= csrf_alan() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
        <label class="y-onay"><input type="checkbox" name="onay" value="1" required> Evet, kalıcı olarak silinsin</label><button class="y-btn y-btn-cizgi">Sil</button></form></details>
      <p class="y-not">Kayıtlar <?= DB_SAKLAMA_GUN ?> gün sonra (<?= h(date('d.m.Y', strtotime($v['tarih']) + DB_SAKLAMA_GUN * 86400)) ?>) otomatik silinir.</p>
    </section>
  </div>
<?php sablon_son(); exit;
}

/* ---------- liste */
sablon_bas('Tüm kayıtlar'); mesaj();
if (!$pdo): ?>
  <div class="y-bas"><h1>Tüm kayıtlar</h1></div>
  <section class="y-kart"><p>Veritabanı açılamadı: sunucuda PHP <b>pdo_sqlite</b> eklentisi kapalı olabilir (hPanel → PHP Yapılandırması → Eklentiler) ya da <code>veri/veritabani</code> klasörüne yazılamıyor.</p>
    <p class="y-not">Kayıtlar kaybolmaz: bu sürede <code>veri/veritabani/talepler-YYYY-AA.jsonl</code> dosyalarına yazılır ve veritabanı çalışınca bu sayfa açıldığında otomatik içeri alınır. Aracını sat ve ön sipariş kayıtları kendi sayfalarında her durumda görünür.</p></section>
<?php sablon_son(); exit; endif;

$p = []; $w = t_kosul($tur, $d, $q, $p);
$st = $pdo->prepare('SELECT * FROM talepler' . $w . ' ORDER BY tarih DESC, id DESC LIMIT 500'); $st->execute($p); $liste = $st->fetchAll();
$top = $pdo->prepare('SELECT COUNT(*) FROM talepler' . $w); $top->execute($p); $toplam = (int)$top->fetchColumn();
$sayim = []; foreach ($pdo->query('SELECT tur, COUNT(*) n FROM talepler GROUP BY tur') as $r) { $sayim[$r['tur']] = (int)$r['n']; }
$hepsi = array_sum($sayim);
$form_say = ($sayim['teklif'] ?? 0) + ($sayim['siparis'] ?? 0) + ($sayim['randevu'] ?? 0);
$sek = function (string $k, string $ad, int $n) use ($tur, $q) {
    return '<a href="?' . h(http_build_query(array_filter(['tur' => $k, 'q' => $q]))) . '"' . ($tur === $k ? ' aria-current="page"' : '') . '>' . h($ad) . ' (' . $n . ')</a>';
}; ?>
  <div class="y-bas"><h1>Tüm kayıtlar <small>Sitede WhatsApp'a giden her şeyin veritabanındaki kopyası: formlar (ad ve telefonla) ve WhatsApp düğmesi tıklamaları. WhatsApp akışı aynen sürer.</small></h1>
    <form method="get" class="y-satir"><?php if ($tur !== ''): ?><input type="hidden" name="tur" value="<?= h($tur) ?>"><?php endif; ?><?php if ($d !== ''): ?><input type="hidden" name="d" value="<?= h($d) ?>"><?php endif; ?>
      <label>Ara (no, ad, telefon, araç, mesaj)<input type="search" name="q" value="<?= h($q) ?>"></label><button class="y-btn y-btn-cizgi">Ara</button>
      <a class="y-btn y-btn-cizgi" href="?<?= h(http_build_query(array_filter(['s' => 'csv', 'tur' => $tur, 'd' => $d, 'q' => $q]))) ?>">Excel'e aktar</a></form></div>
  <nav class="y-sekme"><?= $sek('', 'Tümü', $hepsi) ?><?= $sek('form', 'Formlar', $form_say) ?><?= $sek('tik', 'WhatsApp tıklamaları', $hepsi - $form_say) ?>
    <?php foreach (DB_TURLER as $k => $ad): if (!empty($sayim[$k])): ?><?= $sek($k, $ad, $sayim[$k]) ?><?php endif; endforeach; ?></nav>
  <nav class="y-sekme"><a href="?<?= h(http_build_query(array_filter(['tur' => $tur, 'q' => $q]))) ?>"<?= $d === '' ? ' aria-current="page"' : '' ?>>Her durum</a>
    <?php foreach (TALEP_DURUMLARI as $k => $ad): ?><a href="?<?= h(http_build_query(array_filter(['tur' => $tur, 'd' => $k, 'q' => $q]))) ?>"<?= $d === $k ? ' aria-current="page"' : '' ?>><?= h($ad) ?></a><?php endforeach; ?></nav>
  <section class="y-kart"><div class="y-tablo-kap"><table class="y-tablo">
    <tr><th>Tarih</th><th>Tür</th><th>Özet</th><th>Müşteri</th><th>Durum</th></tr>
    <?php foreach ($liste as $v): ?>
      <tr<?= $v['durum'] === 'yeni' ? ' class="y-yeni"' : '' ?>><td><?= h(date('d.m.Y H:i', strtotime($v['tarih']))) ?><?php if ($v['no'] !== ''): ?><br><small><?= h($v['no']) ?></small><?php endif; ?></td>
        <td><?= h(DB_TURLER[$v['tur']] ?? $v['tur']) ?></td>
        <td><a href="talepler.php?id=<?= (int)$v['id'] ?>"><?= h(mb_strimwidth($v['ozet'] !== '' ? $v['ozet'] : str_replace('*', '', $v['mesaj']), 0, 90, '…')) ?></a></td>
        <td><?php if ($v['ad'] !== '' || $v['tel'] !== ''): ?><?= h($v['ad']) ?><br><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $v['tel'])) ?>"><?= h($v['tel']) ?></a><?php else: ?><small>—</small><?php endif; ?></td>
        <td><form method="post" action="talepler.php?s=durum" class="y-durum-form"><?= csrf_alan() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="tur_f" value="<?= h($tur) ?>"><input type="hidden" name="d" value="<?= h($d) ?>"><input type="hidden" name="q" value="<?= h($q) ?>">
          <select name="durum" onchange="this.form.submit()" aria-label="Durum"><?php foreach (TALEP_DURUMLARI as $dk => $ad): ?><option value="<?= $dk ?>"<?= $v['durum'] === $dk ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
          <noscript><button>Kaydet</button></noscript></form></td></tr>
    <?php endforeach; ?>
    <?php if (!$liste): ?><tr><td colspan="5" class="y-not"><?= $hepsi ? 'Bu filtreye uyan kayıt yok.' : 'Henüz kayıt yok. Sitede bir form gönderildikçe ya da WhatsApp düğmesine basıldıkça burada listelenir.' ?></td></tr><?php endif; ?>
  </table></div><?php if ($toplam > count($liste)): ?><p class="y-not">En yeni <?= count($liste) ?> kayıt gösteriliyor (toplam <?= $toplam ?>). Tamamı için "Excel'e aktar".</p><?php endif; ?></section>
  <p class="y-not">Kayıtlar <?= DB_SAKLAMA_GUN ?> gün sonra otomatik silinir (KVKK metninde yazılı). Veritabanı dosyası: <code>veri/veritabani/balkar.sqlite</code> (dışarıdan erişime kapalı; Yedekler'e dahil değildir, hosting yedeğinde bulunur).</p>
<?php sablon_son();
