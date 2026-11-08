<?php
/* Sahibinden "İlan Aktarımı" dosyasını (XML / JSON / CSV / XLSX) okuyup site araç biçimine çevirir.
   Dosya biçimi kesin bilinmediği için alan adları esnek eşleştirilir; önizleme ekranında sonuç kontrol edilir. */
declare(strict_types=1);

const ALAN_ESLER = [
    'id'          => ['ilan_no', 'ilanno', 'ilan_numarasi', 'ilan_id', 'id', 'classified_id', 'classifiedid', 'listing_id', 'advert_id'],
    'marka'       => ['marka', 'brand', 'make', 'marka_adi'],
    'seri'        => ['seri', 'series', 'model_serisi', 'seri_adi'],
    'model'       => ['model', 'model_adi', 'alt_model', 'paket', 'trim', 'versiyon'],
    'yil'         => ['yil', 'model_yili', 'year', 'uretim_yili'],
    'km'          => ['km', 'kilometre', 'kilometer', 'mileage'],
    'fiyat'       => ['fiyat', 'price', 'satis_fiyati', 'ilan_fiyati', 'tutar'],
    'renk'        => ['renk', 'color', 'colour'],
    'yakit'       => ['yakit', 'yakit_tipi', 'yakit_motor_tipi', 'motor_tipi', 'fuel', 'fuel_type'],
    'vites'       => ['vites', 'vites_tipi', 'sanziman', 'gear', 'transmission'],
    'kasa'        => ['kasa', 'kasa_tipi', 'body', 'body_type'],
    'motor_gucu'  => ['motor_gucu', 'beygir', 'hp', 'power', 'motor_gucu_hp'],
    'motor_hacmi' => ['motor_hacmi', 'silindir_hacmi', 'cc', 'engine_size'],
    'cekis'       => ['cekis', 'drive', 'drivetrain'],
    'baslik'      => ['baslik', 'ilan_basligi', 'title', 'name'],
    'aciklama'    => ['aciklama', 'ilan_aciklamasi', 'description', 'detay'],
    'kategori'    => ['kategori', 'kategori_adi', 'category', 'kategori_yolu', 'category_path'],
    'url'         => ['url', 'link', 'ilan_linki', 'ilan_url', 'detail_url', 'permalink'],
    'tarih'       => ['ilan_tarihi', 'tarih', 'yayin_tarihi', 'date', 'created', 'created_at', 'guncelleme_tarihi'],
    'foto'        => ['foto', 'fotograf', 'fotograflar', 'resim', 'resimler', 'image', 'images', 'photo', 'photos', 'photo_url', 'gorsel', 'gorseller', 'img'],
];

/** Dosyayı satır dizisine (her satır: anahtar => değer) çevirir. */
function dosya_oku(string $yol, string $ad): array {
    $uzanti = strtolower(pathinfo($ad, PATHINFO_EXTENSION));
    $icerik = (string)file_get_contents($yol);
    if ($uzanti === 'xlsx') { return xlsx_oku($yol); }
    $bas = ltrim(substr($icerik, 0, 200), "\xEF\xBB\xBF \t\r\n");
    if ($uzanti === 'json' || str_starts_with($bas, '{') || str_starts_with($bas, '[')) { return json_satirlar($icerik); }
    if ($uzanti === 'xml' || str_starts_with($bas, '<')) { return xml_satirlar($icerik); }
    return csv_satirlar($icerik);
}

function json_satirlar(string $s): array {
    $v = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $s), true);
    if (!is_array($v)) { throw new RuntimeException('JSON dosyası okunamadı.'); }
    return en_buyuk_liste($v);
}
function en_buyuk_liste(array $v): array {
    if (array_is_list($v) && isset($v[0]) && is_array($v[0])) { return $v; }
    $en = [];
    foreach ($v as $alt) { if (is_array($alt)) { $l = en_buyuk_liste($alt); if (count($l) > count($en)) { $en = $l; } } }
    return $en;
}

function xml_satirlar(string $s): array {
    libxml_use_internal_errors(true);
    $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    if ($x === false) { throw new RuntimeException('XML dosyası okunamadı.'); }
    // en çok tekrar eden ve alt öğesi olan öğe = ilan
    $sayac = [];
    foreach ($x->xpath('//*') as $el) { if ($el->count() > 1) { $sayac[$el->getName()] = ($sayac[$el->getName()] ?? 0) + 1; } }
    arsort($sayac);
    $ad = array_key_first($sayac);
    if ($ad === null) { return []; }
    $satirlar = [];
    foreach ($x->xpath('//' . $ad) as $el) { $satirlar[] = xml_duzle($el); }
    return $satirlar;
}
function xml_duzle(SimpleXMLElement $el, string $on = ''): array {
    $r = [];
    foreach ($el->attributes() as $k => $v) { $r[$on . $k] = (string)$v; }
    foreach ($el->children() as $c) {
        $k = $on . $c->getName();
        if ($c->count() > 0) {
            foreach (xml_duzle($c, $k . '_') as $kk => $vv) { $r[$kk] = isset($r[$kk]) ? array_merge((array)$r[$kk], (array)$vv) : $vv; }
            // <Photos><Photo>url</Photo>...</Photos> gibi listeler
            $metinler = [];
            foreach ($c->children() as $cc) { if ($cc->count() === 0 && trim((string)$cc) !== '') { $metinler[] = trim((string)$cc); } }
            if (count($metinler) > 1) { $r[$k] = $metinler; }
        } else {
            $v = trim((string)$c);
            if (isset($r[$k])) { $r[$k] = array_merge((array)$r[$k], [$v]); } else { $r[$k] = $v; }
            foreach ($c->attributes() as $ak => $av) { $r[$k . '_' . $ak] = (string)$av; }
        }
    }
    return $r;
}

function csv_satirlar(string $s): array {
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1254'); }
    $ilk = strtok($s, "\n");
    $ayrac = ';';
    foreach ([';', ',', "\t", '|'] as $a) { if (substr_count((string)$ilk, $a) > substr_count((string)$ilk, $ayrac)) { $ayrac = $a; } }
    $f = fopen('php://memory', 'r+'); fwrite($f, $s); rewind($f);
    $baslik = fgetcsv($f, 0, $ayrac, '"', '');
    if (!$baslik) { throw new RuntimeException('CSV başlık satırı bulunamadı.'); }
    $r = [];
    while (($satir = fgetcsv($f, 0, $ayrac, '"', '')) !== false) {
        if (count(array_filter($satir, fn($x) => trim((string)$x) !== '')) === 0) { continue; }
        $r[] = array_combine(array_map('trim', $baslik), array_pad(array_slice($satir, 0, count($baslik)), count($baslik), ''));
    }
    return $r;
}

/** Basit XLSX okuyucu: ilk sayfa, ilk satır başlık. */
function xlsx_oku(string $yol): array {
    if (!class_exists('ZipArchive')) { throw new RuntimeException('Sunucuda ZipArchive yok; dosyayı CSV olarak kaydedip yükleyin.'); }
    $z = new ZipArchive();
    if ($z->open($yol) !== true) { throw new RuntimeException('Excel dosyası açılamadı.'); }
    $ortak = [];
    if (($ss = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = simplexml_load_string($ss);
        foreach ($x->si as $si) { $ortak[] = isset($si->t) ? (string)$si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]'))); }
    }
    $sayfa = $z->getFromName('xl/worksheets/sheet1.xml');
    if ($sayfa === false) { throw new RuntimeException('Excel sayfası bulunamadı.'); }
    $x = simplexml_load_string($sayfa);
    $tablo = [];
    foreach ($x->sheetData->row as $row) {
        $satir = [];
        foreach ($row->c as $c) {
            preg_match('/^([A-Z]+)/', (string)$c['r'], $m);
            $sut = 0; foreach (str_split($m[1]) as $ch) { $sut = $sut * 26 + ord($ch) - 64; }
            $tip = (string)$c['t'];
            $v = $tip === 's' ? ($ortak[(int)$c->v] ?? '') : ($tip === 'inlineStr' ? (string)$c->is->t : (string)$c->v);
            $satir[$sut - 1] = $v;
        }
        $tablo[] = $satir;
    }
    $z->close();
    if (!$tablo) { return []; }
    $baslik = array_shift($tablo);
    $r = [];
    foreach ($tablo as $s) {
        $o = [];
        foreach ($baslik as $i => $b) { $o[trim((string)$b)] = $s[$i] ?? ''; }
        if (array_filter($o, fn($x) => trim((string)$x) !== '')) { $r[] = $o; }
    }
    return $r;
}

/** Satırları site araç biçimine çevirir. Dönüş: [araclar, kullanilan_sutunlar, kullanilmayan_sutunlar] */
function satirlari_esle(array $satirlar): array {
    $kullanilan = []; $tum = [];
    $araclar = [];
    foreach ($satirlar as $satir) {
        $duz = [];
        foreach ($satir as $k => $v) { $duz[anahtar((string)$k)] = $v; $tum[anahtar((string)$k)] = (string)$k; }
        $al = function (string $alan) use ($duz, &$kullanilan) {
            foreach (ALAN_ESLER[$alan] as $ad) {
                foreach ($duz as $k => $v) {
                    if ($k === $ad || (strlen($ad) > 3 && str_ends_with($k, '_' . $ad))) {
                        if ($v === '' || $v === null || $v === []) { continue; }
                        $kullanilan[$k] = true; return $v;
                    }
                }
            }
            return null;
        };
        $metin = fn($v) => is_array($v) ? trim(implode(' ', array_map('strval', $v))) : trim((string)($v ?? ''));

        // fotoğraflar: eşleşen alan + her yerdeki resim adresleri
        $foto = [];
        $fv = $al('foto');
        foreach ((array)$fv as $p) { foreach (preg_split('/[\s,;|]+/', (string)$p) as $u) { if (preg_match('#^https?://#', $u)) { $foto[] = $u; } } }
        foreach ($duz as $k => $v) {
            foreach ((array)$v as $p) {
                if (is_string($p) && preg_match_all('#https?://[^\s"\'<>,;|]+\.(?:jpe?g|png|webp)#i', $p, $m)) {
                    foreach ($m[0] as $u) { $foto[] = $u; $kullanilan[$k] = true; }
                }
            }
        }
        $foto = array_values(array_unique($foto));

        $id = preg_replace('/[^\w-]/', '', $metin($al('id')));
        $url = $metin($al('url'));
        if ($id === '' && preg_match('/(\d{8,})/', $url, $m)) { $id = $m[1]; }
        if ($id === '') { $id = 'a' . substr(md5(json_encode($satir)), 0, 10); }
        if ($url === '' && ctype_digit($id)) { $url = 'https://www.sahibinden.com/ilan/' . $id . '/detay'; }

        $kat = $metin($al('kategori'));
        $tarih = $metin($al('tarih'));
        $ts = $tarih !== '' ? strtotime(str_replace(['.', '/'], '-', $tarih)) : false;

        $a = [
            'id' => $id,
            'kategori' => kategori_esle($kat . ' ' . $url),
            'marka' => $metin($al('marka')),
            'seri' => $metin($al('seri')),
            'model' => $metin($al('model')),
            'yil' => sayi_temizle($metin($al('yil'))),
            'km' => sayi_temizle($metin($al('km'))),
            'renk' => $metin($al('renk')),
            'fiyat' => sayi_temizle(preg_replace('/[,.]\d{2}$/', '', $metin($al('fiyat')))),
            'yakit' => yakit_esle($metin($al('yakit'))),
            'vites' => vites_esle($metin($al('vites'))),
            'kasa' => anahtar($metin($al('kasa'))) ?: null,
            'motor_gucu' => $metin($al('motor_gucu')) ?: null,
            'motor_hacmi' => $metin($al('motor_hacmi')) ?: null,
            'cekis' => null,
            'baslik' => $metin($al('baslik')),
            'aciklama' => ['tr' => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $metin($al('aciklama')))))],
            'fotograflar' => $foto,
            'sahibinden_url' => $url,
            'ilan_tarihi' => $ts ? date('Y-m-d', $ts) : date('Y-m-d'),
            'one_cikan' => false,
            'kaynak' => 'sahibinden',
        ];
        $c = anahtar($metin($al('cekis')));
        if ($c !== '') { $a['cekis'] = (str_contains($c, '4') || str_contains($c, 'awd')) ? '4wd' : (str_contains($c, 'arka') || str_contains($c, 'rear') ? 'arkadan' : 'onden'); }
        $araclar[] = $a;
    }
    $kullanilmayan = array_values(array_diff_key($tum, $kullanilan));
    return [$araclar, array_keys($kullanilan), $kullanilmayan];
}

/* ---------- sahibinden dosyası ile sitedeki araçları karşılaştırma
   Dosyada boş gelen alanlar "değişti" sayılmaz (uygulamada eski değer korunur). */
const KARSILASTIRILAN = [
    'fiyat' => 'Fiyat', 'km' => 'Kilometre', 'yil' => 'Yıl', 'marka' => 'Marka', 'seri' => 'Seri', 'model' => 'Model',
    'baslik' => 'İlan başlığı', 'aciklama' => 'Açıklama', 'renk' => 'Renk', 'yakit' => 'Yakıt', 'vites' => 'Vites',
    'kasa' => 'Kasa', 'cekis' => 'Çekiş', 'motor_gucu' => 'Motor gücü', 'motor_hacmi' => 'Motor hacmi',
    'kategori' => 'Kategori', 'fotograflar' => 'Fotoğraflar', 'sahibinden_url' => 'İlan adresi',
];

function _norm($v): string {
    if (is_array($v)) { return implode('|', array_map('_norm', $v)); }
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower((string)($v ?? ''))));
}

function alan_goster(string $k, $v): string {
    if ($v === null || $v === '' || $v === []) { return '—'; }
    switch ($k) {
        case 'fiyat': return number_format((int)$v, 0, ',', '.') . ' TL';
        case 'km': return number_format((int)$v, 0, ',', '.') . ' km';
        case 'yakit': return YAKITLAR[$v] ?? (string)$v;
        case 'vites': return VITESLER[$v] ?? (string)$v;
        case 'kasa': return KASALAR[$v] ?? (string)$v;
        case 'cekis': return CEKISLER[$v] ?? (string)$v;
        case 'kategori': return KATEGORILER[$v] ?? (string)$v;
        case 'fotograflar': return count((array)$v) . ' fotoğraf';
        case 'aciklama': return (string)(is_array($v) ? ($v['tr'] ?? '') : $v);
        default: return (string)$v;
    }
}

/** Dosyadaki bir ilan ile sitedeki hâlini alan alan karşılaştırır: [alan => [eski, yeni]] */
function ilan_farklari(array $e, array $a): array {
    $f = [];
    foreach (array_keys(KARSILASTIRILAN) as $k) {
        $yeni = $k === 'aciklama' ? ($a['aciklama']['tr'] ?? '') : ($a[$k] ?? null);
        $eski = $k === 'aciklama' ? ($e['aciklama']['tr'] ?? '') : ($e[$k] ?? null);
        if ($yeni === null || $yeni === '' || $yeni === []) { continue; }          // dosyada yok → eski korunur
        if ($k === 'fotograflar') {
            $ey = array_map(fn($u) => preg_replace('#/x\d+_#', '/', (string)$u), (array)$eski);
            $yy = array_map(fn($u) => preg_replace('#/x\d+_#', '/', (string)$u), (array)$yeni);
            if ($ey !== $yy) { $f[$k] = [$eski, $yeni]; }
            continue;
        }
        if (in_array($k, ['fiyat', 'km', 'yil'], true)) { if ((int)$eski !== (int)$yeni) { $f[$k] = [$eski, $yeni]; } continue; }
        if (_norm($eski) !== _norm($yeni)) { $f[$k] = [$eski, $yeni]; }
    }
    if (($e['durum'] ?? '') === 'satildi') { $f['durum'] = ['satildi', 'satista']; }   // sitede satıldı, sahibinden'de yeniden yayında
    return $f;
}

/** Dört grup: yeni, degisen, ayni, eksik (sitede var, dosyada yok) */
function aktarim_karsilastir(array $site, array $dosya): array {
    $mevcut = [];
    foreach ($site as $e) { $mevcut[(string)$e['id']] = $e; }
    $sonuc = ['yeni' => [], 'degisen' => [], 'ayni' => [], 'eksik' => []];
    $gorulen = [];
    foreach ($dosya as $a) {
        $id = (string)$a['id']; $gorulen[$id] = true;
        if (!isset($mevcut[$id])) { $sonuc['yeni'][] = $a; continue; }
        $f = ilan_farklari($mevcut[$id], $a);
        if ($f) { $sonuc['degisen'][] = ['eski' => $mevcut[$id], 'yeni' => $a, 'farklar' => $f]; } else { $sonuc['ayni'][] = $mevcut[$id]; }
    }
    foreach ($site as $e) {
        if (!isset($gorulen[(string)$e['id']]) && ($e['durum'] ?? '') !== 'satildi') { $sonuc['eksik'][] = $e; }
    }
    return $sonuc;
}

/** Güncellemede sitede elle girilenler korunur; Türkçe açıklama değiştiyse eski çeviriler (artık yanlış) silinir. */
function ilan_birlestir(array $e, array $a): array {
    $trDegisti = ($a['aciklama']['tr'] ?? '') !== '' && _norm($a['aciklama']['tr']) !== _norm($e['aciklama']['tr'] ?? '');
    $sonuc = $e;
    foreach ($a as $k => $v) {
        if (in_array($k, ['one_cikan', 'kaynak', 'ilan_tarihi'], true)) { continue; }
        if ($k === 'aciklama') { $sonuc['aciklama'] = $trDegisti ? ['tr' => $a['aciklama']['tr']] : array_merge($e['aciklama'] ?? [], array_filter($a['aciklama'] ?? [])); continue; }
        if ($v === null || $v === '' || $v === []) { continue; }
        $sonuc[$k] = $v;
    }
    $sonuc['durum'] = 'satista';
    return $sonuc;
}
