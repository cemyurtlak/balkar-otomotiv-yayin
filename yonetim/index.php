<?php
/* Balkar Otomotiv – yönetim paneli (tek dosya yönlendirici) */
declare(strict_types=1);
require __DIR__ . '/_ortak.php';
require __DIR__ . '/_aktar.php';

$s = $_GET['s'] ?? 'liste';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

/* ---------- ilk kurulum: şifre belirleme */
if (sifre_hash() === null) {
    if ($post) {
        csrf_dogrula();
        $p1 = (string)($_POST['sifre'] ?? ''); $p2 = (string)($_POST['sifre2'] ?? '');
        if (mb_strlen($p1) < 10) { git('index.php', 'Şifre en az 10 karakter olmalı.', true); }
        if ($p1 !== $p2) { git('index.php', 'Şifreler aynı değil.', true); }
        $ok = file_put_contents(SIFRE_DOSYA, "<?php\nreturn " . var_export(password_hash($p1, PASSWORD_DEFAULT), true) . ";\n");
        if ($ok === false) { git('index.php', 'Şifre dosyası yazılamadı. "yonetim" klasörüne yazma izni verin (755).', true); }
        session_regenerate_id(true);
        $_SESSION['giris'] = true; $_SESSION['zaman'] = time();
        git('index.php', 'Şifre kaydedildi. Hoş geldiniz.');
    }
    sablon_bas('Kurulum'); mesaj(); ?>
    <section class="y-kart y-dar">
      <h1>Yönetim paneli kurulumu</h1>
      <p>Panele giriş için bir şifre belirleyin. Bu ekran yalnızca ilk açılışta görünür.</p>
      <form method="post"><?= csrf_alan() ?>
        <label>Şifre (en az 10 karakter)<input type="password" name="sifre" required minlength="10" autocomplete="new-password"></label>
        <label>Şifre tekrar<input type="password" name="sifre2" required minlength="10" autocomplete="new-password"></label>
        <button class="y-btn">Şifreyi kaydet</button>
      </form>
    </section>
    <?php sablon_son(); exit;
}

/* ---------- giriş */
if (!giris_var()) {
    if ($post && $s === 'giris') {
        csrf_dogrula();
        $_SESSION['deneme'] = ($_SESSION['deneme'] ?? 0) + 1;
        if ($_SESSION['deneme'] > 5) { sleep(min(10, $_SESSION['deneme'])); }
        if (password_verify((string)($_POST['sifre'] ?? ''), (string)sifre_hash())) {
            session_regenerate_id(true);
            $_SESSION['giris'] = true; $_SESSION['zaman'] = time(); $_SESSION['deneme'] = 0;
            git('index.php');
        }
        git('index.php', 'Şifre hatalı.', true);
    }
    sablon_bas('Giriş'); mesaj(); ?>
    <section class="y-kart y-dar">
      <h1>Yönetim girişi</h1>
      <form method="post" action="index.php?s=giris"><?= csrf_alan() ?>
        <label>Şifre<input type="password" name="sifre" required autofocus autocomplete="current-password"></label>
        <button class="y-btn">Giriş yap</button>
      </form>
    </section>
    <?php sablon_son(); exit;
}
$_SESSION['zaman'] = time();

/* ---------- işlemler (POST) */
if ($post) {
    csrf_dogrula();
    $v = veri_oku();
    $bul = function (string $id) use (&$v): ?int { foreach ($v['araclar'] as $i => $a) { if ((string)$a['id'] === $id) { return $i; } } return null; };

    if ($s === 'cikis') { session_destroy(); git('index.php'); }

    if ($s === 'kaydet') {
        $id = trim((string)($_POST['id'] ?? ''));
        $yeni = $id === '';
        if ($yeni) { $id = 'e' . date('ymdHis'); }
        $i = $bul($id);
        $eski = $i !== null ? $v['araclar'][$i] : [];
        $fotolar = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)($_POST['fotograflar'] ?? ''))), fn($u) => $u !== ''));
        // yüklenen fotoğraflar
        if (!empty($_FILES['foto_yukle']['name'][0])) {
            if (!is_dir(FOTO)) { mkdir(FOTO, 0755, true); }
            foreach ($_FILES['foto_yukle']['tmp_name'] as $k => $tmp) {
                if ($_FILES['foto_yukle']['error'][$k] !== UPLOAD_ERR_OK || $_FILES['foto_yukle']['size'][$k] > 10 * 1024 * 1024) { continue; }
                $bilgi = @getimagesize($tmp);
                $uz = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$bilgi['mime'] ?? ''] ?? null;
                if (!$uz) { continue; }
                $ad = preg_replace('/[^\w-]/', '', $id) . '-' . bin2hex(random_bytes(4)) . '.' . $uz;
                if (move_uploaded_file($tmp, FOTO . '/' . $ad)) { $fotolar[] = '../veri/foto/' . $ad; }
            }
        }
        $a = [
            'id' => $id,
            'kategori' => array_key_exists($_POST['kategori'] ?? '', KATEGORILER) ? $_POST['kategori'] : 'otomobil',
            'marka' => trim((string)$_POST['marka']), 'seri' => trim((string)$_POST['seri']), 'model' => trim((string)$_POST['model']),
            'yil' => sayi_temizle($_POST['yil'] ?? ''), 'km' => sayi_temizle($_POST['km'] ?? ''),
            'renk' => trim((string)$_POST['renk']), 'fiyat' => sayi_temizle($_POST['fiyat'] ?? ''),
            'yakit' => ($_POST['yakit'] ?? '') ?: null, 'vites' => ($_POST['vites'] ?? '') ?: null,
            'kasa' => ($_POST['kasa'] ?? '') ?: null, 'cekis' => ($_POST['cekis'] ?? '') ?: null,
            'motor_gucu' => trim((string)$_POST['motor_gucu']) ?: null, 'motor_hacmi' => trim((string)$_POST['motor_hacmi']) ?: null,
            'baslik' => trim((string)$_POST['baslik']),
            'aciklama' => array_filter(['tr' => trim((string)$_POST['aciklama_tr']), 'en' => trim((string)$_POST['aciklama_en']), 'ru' => trim((string)$_POST['aciklama_ru']), 'de' => trim((string)$_POST['aciklama_de'])], fn($x) => $x !== ''),
            'fotograflar' => array_values(array_unique($fotolar)),
            'sahibinden_url' => trim((string)$_POST['sahibinden_url']),
            'ilan_tarihi' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['ilan_tarihi']) ? $_POST['ilan_tarihi'] : date('Y-m-d'),
            'one_cikan' => !empty($_POST['one_cikan']),
            'one_cikan_etiket' => in_array($_POST['one_cikan_etiket'] ?? 'yeni', ['yeni', 'one_cikan', 'ozel', ''], true) ? (string)($_POST['one_cikan_etiket'] ?? 'yeni') : 'yeni',
            'one_cikan_ozel' => array_filter(['tr' => mb_substr(trim((string)($_POST['one_cikan_ozel_tr'] ?? '')), 0, 30), 'en' => mb_substr(trim((string)($_POST['one_cikan_ozel_en'] ?? '')), 0, 30), 'ru' => mb_substr(trim((string)($_POST['one_cikan_ozel_ru'] ?? '')), 0, 30), 'de' => mb_substr(trim((string)($_POST['one_cikan_ozel_de'] ?? '')), 0, 30)], fn($x) => $x !== ''),
            'durum' => !empty($_POST['satildi']) ? 'satildi' : 'satista',
            'kaynak' => $eski['kaynak'] ?? 'elle',
            'servis_garantisi' => !empty($_POST['servis_garantisi']),
        ];
        // hasar: Türkçe metin değişmediyse panelde olmayan çeviriler korunur
        $eh = $eski['hasar'] ?? [];
        $hp = function (string $k) use ($eh) {
            $yeni = trim((string)($_POST['hasar_' . $k] ?? ''));
            if ($yeni === '' || in_array(mb_strtolower($yeni), ['yok', '-'], true)) { return null; }
            $o = $eh[$k] ?? null;
            return (is_array($o) && ($o['tr'] ?? '') === $yeni) ? $o : ['tr' => $yeni];
        };
        $tramer = trim((string)($_POST['hasar_tramer'] ?? ''));
        $a['hasar'] = ['degisen' => $hp('degisen'), 'boya' => $hp('boya'), 'lokal' => $hp('lokal'),
            'tramer_tl' => $tramer === '' ? null : sayi_temizle($tramer) ?? 0, 'agir_hasar' => !empty($_POST['hasar_agir'])];
        if ($a['one_cikan_etiket'] === 'ozel' && empty($a['one_cikan_ozel']['tr'])) { $a['one_cikan_etiket'] = ''; }
        if ($a['marka'] === '' || !$a['fiyat']) { git('index.php?s=duzenle&id=' . urlencode($id), 'Marka ve fiyat zorunlu.', true); }
        if (!$a['aciklama']) { $a['aciklama'] = ['tr' => '']; }
        if ($a['one_cikan']) { foreach ($v['araclar'] as &$x) { $x['one_cikan'] = false; } unset($x); }
        if ($i === null) { $v['araclar'][] = $a; } else { $v['araclar'][$i] = $a; }
        veri_yaz($v, 'yönetim paneli');
        git('index.php', ($yeni ? 'Araç eklendi: ' : 'Araç güncellendi: ') . $a['marka'] . ' ' . $a['seri']);
    }

    if ($s === 'sil') {
        $i = $bul((string)$_POST['id']);
        if ($i !== null) { $ad = $v['araclar'][$i]['marka'] . ' ' . $v['araclar'][$i]['seri']; array_splice($v['araclar'], $i, 1); veri_yaz($v, 'yönetim paneli'); git('index.php', 'Silindi: ' . $ad); }
        git('index.php');
    }

    if ($s === 'satildi') {
        $i = $bul((string)$_POST['id']);
        if ($i !== null) { $v['araclar'][$i]['durum'] = ($v['araclar'][$i]['durum'] ?? '') === 'satildi' ? 'satista' : 'satildi'; veri_yaz($v, 'yönetim paneli'); }
        git('index.php', 'Durum değiştirildi.');
    }

    if ($s === 'aktar') {
        if (empty($_FILES['dosya']['tmp_name']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) { git('index.php?s=aktar', 'Dosya yüklenemedi.', true); }
        try {
            $satirlar = dosya_oku($_FILES['dosya']['tmp_name'], (string)$_FILES['dosya']['name']);
            [$araclar, $kullanilan, $kullanilmayan] = satirlari_esle($satirlar);
        } catch (Throwable $e) { git('index.php?s=aktar', 'Dosya okunamadı: ' . $e->getMessage(), true); }
        if (!$araclar) { git('index.php?s=aktar', 'Dosyada ilan bulunamadı.', true); }
        if (!is_dir(YEDEK)) { mkdir(YEDEK, 0755, true); }
        $gecici = 'aktarim-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents(YEDEK . '/' . $gecici, json_encode(['araclar' => $araclar, 'kullanilan' => $kullanilan, 'kullanilmayan' => $kullanilmayan, 'dosya' => $_FILES['dosya']['name']], JSON_UNESCAPED_UNICODE));
        $_SESSION['aktarim'] = $gecici;
        git('index.php?s=onizle');
    }

    if ($s === 'uygula') {
        $g = $_SESSION['aktarim'] ?? '';
        $yol = YEDEK . '/' . basename($g);
        if ($g === '' || !is_file($yol)) { git('index.php?s=aktar', 'Önizleme bulunamadı, dosyayı yeniden yükleyin.', true); }
        $p = json_decode((string)file_get_contents($yol), true);
        $kar = aktarim_karsilastir($v['araclar'], $p['araclar']);
        $k = (array)($_POST['k'] ?? []);
        $karar = fn(string $id, string $varsayilan) => (string)($k[$id] ?? $varsayilan);
        $say = ['eklenen' => 0, 'guncellenen' => 0, 'satildi' => 0, 'silinen' => 0, 'atlanan' => 0];
        $yeniler = [];
        foreach ($kar['yeni'] as $a) {
            if ($karar((string)$a['id'], 'atla') === 'ekle') { $yeniler[] = $a; $say['eklenen']++; } else { $say['atlanan']++; }
        }
        $guncel = [];
        foreach ($kar['degisen'] as $d) { if ($karar((string)$d['yeni']['id'], 'atla') === 'guncelle') { $guncel[(string)$d['yeni']['id']] = $d['yeni']; } else { $say['atlanan']++; } }
        $eksik = [];
        foreach ($kar['eksik'] as $e) { $eksik[(string)$e['id']] = $karar((string)$e['id'], 'birak'); }
        $liste = [];
        foreach ($v['araclar'] as $e) {
            $id = (string)$e['id'];
            if (isset($guncel[$id])) { $liste[] = ilan_birlestir($e, $guncel[$id]); $say['guncellenen']++; continue; }
            if (isset($eksik[$id])) {
                if ($eksik[$id] === 'sil') { $say['silinen']++; continue; }
                if ($eksik[$id] === 'satildi') { $e['durum'] = 'satildi'; $say['satildi']++; }
            }
            $liste[] = $e;
        }
        $v['araclar'] = array_merge($yeniler, $liste);   // yeni ilanlar listenin başına
        if ($say['eklenen'] + $say['guncellenen'] + $say['satildi'] + $say['silinen'] === 0) {
            @unlink($yol); unset($_SESSION['aktarim']);
            git('index.php', 'Hiçbir değişiklik seçilmedi; site aynı kaldı.');
        }
        veri_yaz($v, 'sahibinden dosyası: ' . ($p['dosya'] ?? ''));
        @unlink($yol); unset($_SESSION['aktarim']);
        $ozet = array_filter(["{$say['eklenen']} eklendi", "{$say['guncellenen']} güncellendi", "{$say['satildi']} satıldı işaretlendi", "{$say['silinen']} silindi", "{$say['atlanan']} atlandı"], fn($x) => $x[0] !== '0');
        git('index.php', 'Sahibinden aktarımı uygulandı: ' . implode(', ', $ozet) . '.');
    }

    if ($s === 'geri_yukle') {
        $d = basename((string)$_POST['dosya']);
        $yol = YEDEK . '/' . $d;
        if (!preg_match('/^araclar-\d{8}-\d{6}\.json$/', $d) || !is_file($yol)) { git('index.php?s=yedek', 'Yedek bulunamadı.', true); }
        $v2 = json_decode((string)file_get_contents($yol), true);
        if (!is_array($v2)) { git('index.php?s=yedek', 'Yedek okunamadı.', true); }
        veri_yaz($v2, 'yedekten geri yüklendi: ' . $d);
        git('index.php', 'Yedek geri yüklendi.');
    }
    git('index.php');
}

/* ---------- ekranlar (GET) */
$v = veri_oku();

if ($s === 'duzenle') {
    $id = (string)($_GET['id'] ?? '');
    $a = [];
    foreach ($v['araclar'] as $x) { if ((string)$x['id'] === $id) { $a = $x; } }
    $sec = function (string $ad, array $secenek, $deger) { $o = ''; foreach ($secenek as $k => $e) { $o .= '<option value="' . h($k) . '"' . ((string)$k === (string)($deger ?? '') ? ' selected' : '') . '>' . h($e) . '</option>'; } return "<select name=\"$ad\">$o</select>"; };
    sablon_bas($a ? 'Aracı düzenle' : 'Araç ekle'); mesaj(); ?>
    <h1><?= $a ? 'Aracı düzenle' : 'Yeni araç ekle' ?></h1>
    <form class="y-kart y-form" method="post" action="index.php?s=kaydet" enctype="multipart/form-data"><?= csrf_alan() ?>
      <input type="hidden" name="id" value="<?= h($a['id'] ?? '') ?>">
      <div class="y-izgara">
        <label>Kategori<?= $sec('kategori', KATEGORILER, $a['kategori'] ?? 'otomobil') ?></label>
        <label>Marka *<input name="marka" required value="<?= h($a['marka'] ?? '') ?>" placeholder="BMW"></label>
        <label>Seri<input name="seri" value="<?= h($a['seri'] ?? '') ?>" placeholder="3 Serisi"></label>
        <label>Model / paket<input name="model" value="<?= h($a['model'] ?? '') ?>" placeholder="320i M Sport"></label>
        <label>Model yılı<input name="yil" inputmode="numeric" value="<?= h($a['yil'] ?? '') ?>"></label>
        <label>Kilometre<input name="km" inputmode="numeric" value="<?= h($a['km'] ?? '') ?>"></label>
        <label>Fiyat (TL) *<input name="fiyat" required inputmode="numeric" value="<?= h($a['fiyat'] ?? '') ?>"></label>
        <label>Renk<input name="renk" value="<?= h($a['renk'] ?? '') ?>" placeholder="Beyaz"></label>
        <label>Yakıt<?= $sec('yakit', YAKITLAR, $a['yakit'] ?? '') ?></label>
        <label>Vites<?= $sec('vites', VITESLER, $a['vites'] ?? '') ?></label>
        <label>Kasa<?= $sec('kasa', KASALAR, $a['kasa'] ?? '') ?></label>
        <label>Çekiş<?= $sec('cekis', CEKISLER, $a['cekis'] ?? '') ?></label>
        <label>Motor gücü<input name="motor_gucu" value="<?= h($a['motor_gucu'] ?? '') ?>" placeholder="170 hp"></label>
        <label>Motor hacmi<input name="motor_hacmi" value="<?= h($a['motor_hacmi'] ?? '') ?>" placeholder="1598 cc"></label>
        <label>İlan tarihi<input type="date" name="ilan_tarihi" value="<?= h($a['ilan_tarihi'] ?? date('Y-m-d')) ?>"></label>
        <label>Sahibinden ilan linki<input name="sahibinden_url" value="<?= h($a['sahibinden_url'] ?? '') ?>" placeholder="https://www.sahibinden.com/ilan/..."></label>
        <fieldset class="y-tam y-grup"><legend>Hasar ve boya (boş ya da "yok" = yok)</legend>
          <div class="y-izgara">
          <label>Değişen parçalar<input name="hasar_degisen" value="<?= h($a['hasar']['degisen']['tr'] ?? '') ?>" placeholder="yok"></label>
          <label>Boyalı parçalar<input name="hasar_boya" value="<?= h($a['hasar']['boya']['tr'] ?? '') ?>" placeholder="yok"></label>
          <label>Lokal boyalı<input name="hasar_lokal" value="<?= h($a['hasar']['lokal']['tr'] ?? '') ?>" placeholder="yok"></label>
          <label>Tramer tutarı (TL, kayıt yoksa 0)<input name="hasar_tramer" inputmode="numeric" value="<?= h(isset($a['hasar']) && array_key_exists('tramer_tl', $a['hasar']) ? ($a['hasar']['tramer_tl'] ?? '') : '') ?>"></label>
          <label class="y-onay"><input type="checkbox" name="hasar_agir" <?= !empty($a['hasar']['agir_hasar']) ? 'checked' : '' ?>> Ağır hasar kayıtlı</label>
          <label class="y-onay"><input type="checkbox" name="servis_garantisi" <?= !empty($a['servis_garantisi']) ? 'checked' : '' ?>> Servis garantisi var</label>
          </div>
          <p class="y-not">Diğer dillerde parça adları için Türkçe metin gösterilir; "yok" her dilde otomatik çevrilir.</p>
        </fieldset>
        <label class="y-tam">İlan başlığı<input name="baslik" value="<?= h($a['baslik'] ?? '') ?>"></label>
        <label class="y-tam">Açıklama – Türkçe<textarea name="aciklama_tr" rows="5"><?= h($a['aciklama']['tr'] ?? '') ?></textarea></label>
        <details class="y-tam"><summary>Açıklamanın diğer dillerdeki çevirisi (isteğe bağlı)</summary>
          <p class="y-not">Boş bırakılırsa yabancı ziyaretçiye Türkçe açıklama, "satıcının notu Türkçedir" uyarısıyla gösterilir. Teknik bilgiler her zaman 4 dilde otomatik çevrilir.</p>
          <label>English<textarea name="aciklama_en" rows="3"><?= h($a['aciklama']['en'] ?? '') ?></textarea></label>
          <label>Русский<textarea name="aciklama_ru" rows="3"><?= h($a['aciklama']['ru'] ?? '') ?></textarea></label>
          <label>Deutsch<textarea name="aciklama_de" rows="3"><?= h($a['aciklama']['de'] ?? '') ?></textarea></label>
        </details>
        <label class="y-tam">Fotoğraf adresleri (her satıra bir adres; ilk satır kapak fotoğrafı)<textarea name="fotograflar" rows="5"><?= h(implode("\n", $a['fotograflar'] ?? [])) ?></textarea></label>
        <label class="y-tam">Bilgisayardan fotoğraf ekle (JPG, PNG, WEBP – en fazla 10 MB)<input type="file" name="foto_yukle[]" accept="image/jpeg,image/png,image/webp" multiple></label>
        <fieldset class="y-tam y-grup"><legend>Anasayfada öne çıkarma</legend>
          <label class="y-onay"><input type="checkbox" name="one_cikan" <?= !empty($a['one_cikan']) ? 'checked' : '' ?>> Anasayfada büyük göster (öne çıkan)</label>
          <?php $et = $a['one_cikan_etiket'] ?? 'yeni'; $oz = $a['one_cikan_ozel'] ?? []; ?>
          <label>Büyük kartın köşesindeki yazı
            <select name="one_cikan_etiket" data-etiket-sec>
              <option value="yeni" <?= $et === 'yeni' ? 'selected' : '' ?>>Yeni eklendi</option>
              <option value="one_cikan" <?= $et === 'one_cikan' ? 'selected' : '' ?>>Öne çıkan</option>
              <option value="ozel" <?= $et === 'ozel' ? 'selected' : '' ?>>Kendi yazım</option>
              <option value="" <?= $et === '' ? 'selected' : '' ?>>Hiçbir şey yazmasın</option>
            </select>
          </label>
          <div class="y-izgara" data-etiket-ozel <?= $et === 'ozel' ? '' : 'hidden' ?>>
            <label>Kendi yazınız (Türkçe)<input name="one_cikan_ozel_tr" maxlength="30" value="<?= h($oz['tr'] ?? '') ?>" placeholder="Örn. Fırsat aracı"></label>
            <label>English<input name="one_cikan_ozel_en" maxlength="30" value="<?= h($oz['en'] ?? '') ?>" placeholder="Great deal"></label>
            <label>Русский<input name="one_cikan_ozel_ru" maxlength="30" value="<?= h($oz['ru'] ?? '') ?>" placeholder="Выгодное предложение"></label>
            <label>Deutsch<input name="one_cikan_ozel_de" maxlength="30" value="<?= h($oz['de'] ?? '') ?>" placeholder="Top-Angebot"></label>
          </div>
          <p class="y-not">"Yeni eklendi" ve "Öne çıkan" 4 dilde otomatik çevrilir. Kendi yazınızın diğer dilleri boş kalırsa o dillerde Türkçe yazı gösterilir. En fazla 30 karakter.</p>
        </fieldset>
        <label class="y-onay"><input type="checkbox" name="satildi" <?= ($a['durum'] ?? '') === 'satildi' ? 'checked' : '' ?>> Satıldı (sitede gizle)</label>
      </div>
      <button class="y-btn">Kaydet</button> <a href="index.php">Vazgeç</a>
    </form>
    <script>
      (function () { var s = document.querySelector('[data-etiket-sec]'), o = document.querySelector('[data-etiket-ozel]');
        if (s && o) { s.addEventListener('change', function () { o.hidden = s.value !== 'ozel'; }); } })();
    </script>
    <?php sablon_son(); exit;
}

if ($s === 'aktar') {
    sablon_bas('Sahibinden dosyası yükle'); mesaj(); ?>
    <h1>Sahibinden ilanlarını aktar</h1>
    <section class="y-kart">
      <ol class="y-adim">
        <li>sahibinden.com'a mağaza hesabıyla girin.</li>
        <li><b>Galerim → İlan Aktarımı</b> bölümünden yayındaki ilanlarınızın dosyasını indirin.</li>
        <li>İndirdiğiniz dosyayı aşağıdan seçip <b>Önizle</b>'ye basın. Kontrol edip onayladığınızda site güncellenir.</li>
      </ol>
      <form method="post" action="index.php?s=aktar" enctype="multipart/form-data"><?= csrf_alan() ?>
        <label>Dosya (XML, XLSX, CSV veya JSON)<input type="file" name="dosya" required accept=".xml,.xlsx,.csv,.json,.txt"></label>
        <button class="y-btn">Önizle</button>
      </form>
      <p class="y-not">Önizlemede panel, dosyadaki her ilanı sitedekiyle karşılaştırır: yeni ilanları, değişen ilanları (hangi bilginin değiştiğiyle birlikte) ve sahibinden'de artık yayında olmayanları ayrı ayrı gösterir. Her biri için ne yapılacağını siz seçersiniz; onaylamadan sitede hiçbir şey değişmez. Elle girdiğiniz hasar bilgisi, çeviriler ve "öne çıkan" seçimi korunur.</p>
    </section>
    <?php sablon_son(); exit;
}

if ($s === 'onizle') {
    $g = $_SESSION['aktarim'] ?? '';
    $yol = YEDEK . '/' . basename($g);
    if ($g === '' || !is_file($yol)) { git('index.php?s=aktar'); }
    $p = json_decode((string)file_get_contents($yol), true);
    $kar = aktarim_karsilastir($v['araclar'], $p['araclar']);
    $foto = fn($a) => !empty($a['fotograflar'][0]) ? '<img src="' . h($a['fotograflar'][0]) . '" alt="" width="72" height="54" referrerpolicy="no-referrer" loading="lazy">' : '';
    $ad = fn($a) => '<b>' . h(trim(($a['marka'] ?? '') . ' ' . ($a['seri'] ?? ''))) . '</b><br><small>' . h(implode(' · ', array_filter([(string)($a['yil'] ?? ''), (string)($a['model'] ?? '')]))) . ' · ' . alan_goster('fiyat', $a['fiyat'] ?? null) . '</small>';
    $secim = function (string $id, array $secenekler, string $varsayilan): string {
        $o = '<div class="y-secim">';
        foreach ($secenekler as $deger => $yazi) {
            $o .= '<label><input type="radio" name="k[' . h($id) . ']" value="' . h($deger) . '"' . ($deger === $varsayilan ? ' checked' : '') . '> ' . h($yazi) . '</label>';
        }
        return $o . '</div>';
    };
    sablon_bas('Önizleme'); mesaj(); ?>
    <h1>Sahibinden aktarımı: <?= h($p['dosya'] ?? '') ?></h1>
    <section class="y-kart y-ozet-kutular">
      <div><b><?= count($kar['yeni']) ?></b><span>yeni ilan</span></div>
      <div><b><?= count($kar['degisen']) ?></b><span>değişen ilan</span></div>
      <div><b><?= count($kar['eksik']) ?></b><span>sahibinden'de artık yok</span></div>
      <div><b><?= count($kar['ayni']) ?></b><span>değişmemiş</span></div>
    </section>
    <?php if (!empty($p['kullanilmayan'])): ?><p class="y-not">Dosyada kullanılmayan sütunlar: <?= h(implode(', ', array_slice($p['kullanilmayan'], 0, 40))) ?></p><?php endif; ?>
    <form method="post" action="index.php?s=uygula" class="y-aktarim"><?= csrf_alan() ?>

    <?php if ($kar['yeni']): ?>
    <section class="y-kart" data-grup>
      <div class="y-grup-bas"><h2>Yeni ilanlar <small>sahibinden'de var, sitede yok</small></h2>
        <div class="y-toplu"><button type="button" data-hepsi="ekle">Hepsini ekle</button><button type="button" data-hepsi="atla">Hiçbirini ekleme</button></div></div>
      <table class="y-tablo"><tbody>
      <?php foreach ($kar['yeni'] as $a): ?>
        <tr><td><?= $foto($a) ?></td><td><?= $ad($a) ?><br><small><?= alan_goster('km', $a['km'] ?? null) ?> · <?= h(YAKITLAR[$a['yakit'] ?? ''] ?? '') ?> · <?= count($a['fotograflar'] ?? []) ?> fotoğraf</small></td>
        <td><?= $secim((string)$a['id'], ['ekle' => 'Siteye ekle', 'atla' => 'Ekleme'], 'ekle') ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </section>
    <?php endif; ?>

    <?php if ($kar['degisen']): ?>
    <section class="y-kart" data-grup>
      <div class="y-grup-bas"><h2>Değişen ilanlar <small>sahibinden'de güncellenmiş</small></h2>
        <div class="y-toplu"><button type="button" data-hepsi="guncelle">Hepsini güncelle</button><button type="button" data-hepsi="atla">Hiçbirini değiştirme</button></div></div>
      <table class="y-tablo"><tbody>
      <?php foreach ($kar['degisen'] as $d): $e = $d['eski']; ?>
        <tr><td><?= $foto($d['yeni']) ?: $foto($e) ?></td>
        <td><?= $ad($e) ?>
          <ul class="y-fark">
          <?php foreach ($d['farklar'] as $alan => [$eski, $yeni]): ?>
            <?php if ($alan === 'durum'): ?><li><b>Durum:</b> sitede "satıldı", sahibinden'de yeniden yayında → satışa alınacak</li>
            <?php elseif ($alan === 'aciklama'): ?><li><b>Açıklama değişti</b><?= count(array_diff_key($e['aciklama'] ?? [], ['tr' => 1])) ? ' <small>(elle girilmiş İngilizce/Rusça/Almanca çeviriler artık eski metne ait; güncellenirse silinir)</small>' : '' ?>
              <details><summary>Eski ve yeni metni göster</summary><div class="y-iki"><div><small>Eski</small><p><?= nl2br(h(mb_substr(alan_goster('aciklama', $eski), 0, 1500))) ?></p></div><div><small>Yeni</small><p><?= nl2br(h(mb_substr(alan_goster('aciklama', $yeni), 0, 1500))) ?></p></div></div></details></li>
            <?php else: ?><li><b><?= h(KARSILASTIRILAN[$alan] ?? $alan) ?>:</b> <s><?= h(alan_goster($alan, $eski)) ?></s> → <?= h(alan_goster($alan, $yeni)) ?></li><?php endif; ?>
          <?php endforeach; ?>
          </ul></td>
        <td><?= $secim((string)$e['id'], ['guncelle' => 'Güncelle', 'atla' => 'Değiştirme'], 'guncelle') ?></td></tr>
      <?php endforeach; ?></tbody></table>
    </section>
    <?php endif; ?>

    <?php if ($kar['eksik']): ?>
    <section class="y-kart" data-grup>
      <div class="y-grup-bas"><h2>Sahibinden'de artık olmayanlar <small>satılmış ya da yayından kaldırılmış olabilir</small></h2>
        <div class="y-toplu"><button type="button" data-hepsi="satildi">Hepsi satıldı</button><button type="button" data-hepsi="birak">Hepsini bırak</button></div></div>
      <table class="y-tablo"><tbody>
      <?php foreach ($kar['eksik'] as $e): $elle = ($e['kaynak'] ?? '') === 'elle'; ?>
        <tr><td><?= $foto($e) ?></td><td><?= $ad($e) ?><?= $elle ? '<br><span class="y-rozet">panelden elle eklendi</span>' : '' ?></td>
        <td><?= $secim((string)$e['id'], ['satildi' => 'Satıldı işaretle (siteden gizle)', 'sil' => 'Tamamen sil', 'birak' => 'Sitede bırak'], $elle ? 'birak' : 'satildi') ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <p class="y-not">"Satıldı" işaretlenen araç sitede görünmez ama panelde kalır; istediğiniz zaman "Satışa al" ile geri getirebilirsiniz.</p>
    </section>
    <?php endif; ?>

    <?php if ($kar['ayni']): ?><p class="y-not"><?= count($kar['ayni']) ?> ilan sitedekiyle aynı; bunlara dokunulmayacak.</p><?php endif; ?>

    <?php if ($kar['yeni'] || $kar['degisen'] || $kar['eksik']): ?>
      <div class="y-alt-cubuk"><button class="y-btn">Seçimleri uygula</button> <a href="index.php?s=aktar">Vazgeç</a> <small>Uygulamadan önce otomatik yedek alınır.</small></div>
    <?php else: ?>
      <section class="y-kart"><p><b>Her şey güncel.</b> Dosyadaki ilanlar sitedekilerle aynı, yapılacak bir değişiklik yok.</p><a href="index.php">Araç listesine dön</a></section>
    <?php endif; ?>
    </form>
    <script>
      document.querySelectorAll('[data-hepsi]').forEach(function (b) {
        b.addEventListener('click', function () {
          b.closest('[data-grup]').querySelectorAll('input[type=radio][value="' + b.dataset.hepsi + '"]').forEach(function (r) { r.checked = true; });
        });
      });
    </script>
    <?php sablon_son(); exit;
}

if ($s === 'yedek') {
    $yedekler = glob(YEDEK . '/araclar-*.json') ?: [];
    rsort($yedekler);
    sablon_bas('Yedekler'); mesaj(); ?>
    <h1>Yedekler</h1>
    <section class="y-kart"><p class="y-not">Her değişiklikten önce otomatik yedek alınır (son 30 tanesi saklanır).</p>
    <table class="y-tablo"><tbody>
    <?php foreach ($yedekler as $y): $d = basename($y); $j = json_decode((string)file_get_contents($y), true); ?>
      <tr><td><?= h(preg_replace('/araclar-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})\.json/', '$3.$2.$1 $4:$5', $d)) ?></td><td><?= count($j['araclar'] ?? []) ?> araç</td>
      <td><form method="post" action="index.php?s=geri_yukle" onsubmit="return confirm('Bu yedek geri yüklensin mi?')"><?= csrf_alan() ?><input type="hidden" name="dosya" value="<?= h($d) ?>"><button class="y-btn-kucuk">Geri yükle</button></form></td></tr>
    <?php endforeach; if (!$yedekler) { echo '<tr><td>Henüz yedek yok.</td></tr>'; } ?>
    </tbody></table></section>
    <?php sablon_son(); exit;
}

/* liste */
sablon_bas('Araçlar'); mesaj();
$satista = array_filter($v['araclar'], fn($a) => ($a['durum'] ?? '') !== 'satildi'); ?>
<div class="y-bas"><h1>Araçlar <small><?= count($satista) ?> satışta · son güncelleme <?= h(date('d.m.Y H:i', strtotime($v['guncelleme'] ?? 'now'))) ?></small></h1>
<div><a class="y-btn" href="index.php?s=aktar">Sahibinden dosyası yükle</a> <a class="y-btn y-btn-cizgi" href="index.php?s=duzenle">Araç ekle</a></div></div>
<div class="y-tablo-kap"><table class="y-tablo"><thead><tr><th></th><th>Araç</th><th>Yıl</th><th>Km</th><th>Fiyat</th><th>Kaynak</th><th></th></tr></thead><tbody>
<?php foreach ($v['araclar'] as $a): $sat = ($a['durum'] ?? '') === 'satildi'; ?>
<tr class="<?= $sat ? 'y-soluk' : '' ?>">
  <td><?php if (!empty($a['fotograflar'][0])): ?><img src="<?= h($a['fotograflar'][0]) ?>" alt="" width="72" height="54" referrerpolicy="no-referrer" loading="lazy"><?php endif; ?></td>
  <td><b><?= h($a['marka'] . ' ' . $a['seri']) ?></b><?= !empty($a['one_cikan']) ? ' <span class="y-rozet">öne çıkan</span>' : '' ?><?= $sat ? ' <span class="y-rozet">satıldı</span>' : '' ?><br><small><?= h($a['model']) ?></small></td>
  <td><?= h($a['yil']) ?></td><td><?= $a['km'] !== null ? number_format((int)$a['km'], 0, ',', '.') : '' ?></td>
  <td><?= number_format((int)$a['fiyat'], 0, ',', '.') ?> TL</td><td><small><?= h($a['kaynak'] ?? 'sahibinden') ?></small></td>
  <td class="y-islem">
    <a href="index.php?s=duzenle&id=<?= urlencode((string)$a['id']) ?>">Düzenle</a>
    <a href="../tr/arac.html?id=<?= urlencode((string)$a['id']) ?>" target="_blank">Gör</a>
    <form method="post" action="index.php?s=satildi"><?= csrf_alan() ?><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button><?= $sat ? 'Satışa al' : 'Satıldı' ?></button></form>
    <form method="post" action="index.php?s=sil" onsubmit="return confirm('Araç silinsin mi?')"><?= csrf_alan() ?><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button>Sil</button></form>
  </td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php sablon_son();
