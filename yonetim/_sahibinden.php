<?php
/* Balkar Otomotiv – sahibinden eklentisinden gelen ham ilan verisini site araç biçimine çevirir ve
   sitedeki listeyle birleştirir (Aşama 54). Ham biçim (eklentideki toplayici.js):
   R[ilanNo] = { b: başlık, f: "475.000 TL", bilgi: {"Marka": ..., "KM": ...}, parca: {"front-hood": "changed-new"},
                 foto: [x5_ adresleri], ac: açıklama metni, url: ilan adresi }
   Kurallar:
   - Sitede olan ilan: yalnız fiyat, km, fotoğraflar ve ilan adresi sahibinden'den güncellenir; geri kalan her şey
     (marka/model/başlık düzeltmeleri, 4 dilli açıklama, hasar, öne çıkan, servis garantisi) sitedeki hâliyle kalır.
   - Yeni ilan: bütün bilgiler + boya/değişen şeması (4 dil) + açıklamadaki tramer tutarı.
   - Sahibinden'de artık olmayan ilan: "satıldı" işaretlenir (siteden gizlenir, silinmez; satildi_kaynak=sahibinden).
     İlan yeniden yayına girerse geri açılır. Panelden elle "satıldı" yapılan araç gizli kalır. Elle eklenen araçlara dokunulmaz.
   - Panelden tamamen silinen araç sahibinden'de duruyorsa YENİ ilan olarak (ham sahibinden verisiyle) geri eklenir. */
declare(strict_types=1);

const SH_PARCA = [  // parça sınıfı → [tr, en, ru, de]
    'front-bumper' => ['Ön tampon', 'Front bumper', 'Передний бампер', 'Frontstoßstange'],
    'front-hood' => ['Kaput', 'Bonnet', 'Капот', 'Motorhaube'],
    'roof' => ['Tavan', 'Roof', 'Крыша', 'Dach'],
    'front-right-mudguard' => ['Sağ ön çamurluk', 'Front right wing', 'Переднее правое крыло', 'Vorderer rechter Kotflügel'],
    'front-right-door' => ['Sağ ön kapı', 'Front right door', 'Передняя правая дверь', 'Vordere rechte Tür'],
    'rear-right-door' => ['Sağ arka kapı', 'Rear right door', 'Задняя правая дверь', 'Hintere rechte Tür'],
    'rear-right-mudguard' => ['Sağ arka çamurluk', 'Rear right wing', 'Заднее правое крыло', 'Hinterer rechter Kotflügel'],
    'front-left-mudguard' => ['Sol ön çamurluk', 'Front left wing', 'Переднее левое крыло', 'Vorderer linker Kotflügel'],
    'front-left-door' => ['Sol ön kapı', 'Front left door', 'Передняя левая дверь', 'Vordere linke Tür'],
    'rear-left-door' => ['Sol arka kapı', 'Rear left door', 'Задняя левая дверь', 'Hintere linke Tür'],
    'rear-left-mudguard' => ['Sol arka çamurluk', 'Rear left wing', 'Заднее левое крыло', 'Hinterer linker Kotflügel'],
    'rear-hood' => ['Bagaj kapağı', 'Boot lid', 'Крышка багажника', 'Heckklappe'],
    'rear-bumper' => ['Arka tampon', 'Rear bumper', 'Задний бампер', 'Heckstoßstange'],
];
const SH_AY = ['ocak' => 1, 'şubat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'haziran' => 6, 'temmuz' => 7,
               'ağustos' => 8, 'eylül' => 9, 'ekim' => 10, 'kasım' => 11, 'aralık' => 12];

function sh_metin($v, int $en = 300): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($v ?? ''))));
    return mb_substr($s, 0, $en);
}

/** Parça listesini 4 dilde "Kaput, sol ön çamurluk" biçimine çevirir. */
function sh_parca_birlestir(array $parcalar): ?array {
    if (!$parcalar) { return null; }
    $out = [];
    foreach (['tr', 'en', 'ru', 'de'] as $n => $dil) {
        $ad = [];
        foreach (array_values($parcalar) as $j => $p) {
            $s = SH_PARCA[$p][$n];
            $ilk = explode(' ', $s)[0];
            if ($j > 0 && ($dil !== 'de' || in_array($ilk, ['Vorderer', 'Vordere', 'Hinterer', 'Hintere'], true))) {
                $s = mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
            }
            $ad[] = $s;
        }
        $out[$dil] = implode(', ', $ad);
    }
    return $out;
}

/** "30 Ekim 2026" → "2026-10-30" */
function sh_tarih(string $s): ?string {
    $p = preg_split('/\s+/u', trim($s));
    if (count($p) !== 3) { return null; }
    $ay = SH_AY[mb_strtolower(strtr($p[1], ['I' => 'ı', 'İ' => 'i']))] ?? null;
    if ($ay === null || !ctype_digit($p[0]) || !ctype_digit($p[2])) { return null; }
    return sprintf('%04d-%02d-%02d', (int)$p[2], $ay, (int)$p[0]);
}

/** Açıklamadan tramer tutarını çıkarır. Bulunamazsa null. */
function sh_tramer(string $ac): ?int {
    $t = mb_strtolower(strtr($ac, ['I' => 'ı', 'İ' => 'i']));
    $t = preg_replace('/\s+/u', ' ', $t);
    if (preg_match('/tramer\s*[:\-]?\s*(kayd[ıi]\s*)?(yok|yoktur|bulunmamaktad[ıi]r|0\s*tl|sıf[ıi]r)|tramers[ıi]z|tramersz|hasar kayd[ıi] (yok|bulunmamaktad[ıi]r)/u', $t)) { return 0; }
    if (preg_match('/tramer[^0-9]{0,20}?([0-9][0-9.,]*)\s*(bin|k\b)?/u', $t, $m)
        || preg_match('/([0-9][0-9.,]*)\s*(bin|k\b)?\s*(tl)?\s*tramer/u', $t, $m)) {
        $s = $m[1];
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $s)) { $s = preg_replace('/[.,]/', '', $s); }
        elseif (preg_match('/^\d+[.,]\d{1,2}$/', $s)) { $s = str_replace(',', '.', $s); }
        $n = (float)$s;
        if (!empty($m[2])) { $n *= 1000; }
        return $n > 0 && $n < 10000000 ? (int)round($n) : null;
    }
    return null;
}

function sh_kasa(?string $s): ?string {
    $s = mb_strtolower((string)$s);
    foreach (['hatchback' => 'hatchback', 'sedan' => 'sedan', 'suv' => 'suv', 'station' => 'station', 'coupe' => 'coupe',
              'cabrio' => 'cabrio', 'mpv' => 'mpv', 'pick' => 'pickup', 'crossover' => 'suv'] as $k => $v) {
        if (str_starts_with($s, $k)) { return $v; }
    }
    return null;
}
function sh_cekis(?string $s): ?string {
    $s = (string)$s;
    if (str_contains($s, '4x4') || str_contains($s, 'AWD') || str_contains($s, '4WD')) { return '4wd'; }
    if (str_contains($s, 'Önden')) { return 'onden'; }
    if (str_contains($s, 'Arkadan')) { return 'arkadan'; }
    return null;
}

/** Tek ilanı site biçimine çevirir. Geçersizse RuntimeException. */
function sh_ilan_cevir(string $id, array $v): array {
    if (!preg_match('/^\d{8,12}$/', $id)) { throw new RuntimeException("geçersiz ilan no: $id"); }
    $b = is_array($v['bilgi'] ?? null) ? $v['bilgi'] : [];
    $url = (string)($v['url'] ?? '');
    if (!preg_match('#^https://www\.sahibinden\.com/ilan/[a-z0-9.\-]+-' . $id . '/detay$#', $url)) { throw new RuntimeException("$id: ilan adresi beklenen biçimde değil"); }
    $fiyat = (int)preg_replace('/\D/', '', (string)($v['f'] ?? ''));
    $marka = sh_metin($b['Marka'] ?? '', 60);
    if ($marka === '' || $fiyat <= 0) { throw new RuntimeException("$id: marka veya fiyat okunamadı"); }
    $foto = [];
    foreach ((array)($v['foto'] ?? []) as $f) {
        $f = (string)$f;
        if (preg_match('#^https://i\d\.shbdn\.com/photos/[\w/]+\.(jpe?g|png|webp)$#i', $f) && str_contains($f, $id)) { $foto[] = $f; }
    }
    $foto = array_slice(array_values(array_unique($foto)), 0, 60);
    $parca = ['degisen' => [], 'boya' => [], 'lokal' => []];
    foreach ((array)($v['parca'] ?? []) as $p => $d) {
        if (!isset(SH_PARCA[$p])) { continue; }
        $d = (string)$d;
        if (str_starts_with($d, 'changed')) { $parca['degisen'][] = $p; }
        elseif (str_starts_with($d, 'localpainted')) { $parca['lokal'][] = $p; }
        elseif (str_starts_with($d, 'painted')) { $parca['boya'][] = $p; }
    }
    $yakitHam = sh_metin($b['Yakıt / Motor Tipi'] ?? ($b['Yakıt'] ?? ''), 40);
    $kat = str_contains($url, '-arazi-suv-pickup-') ? 'suv' : (str_contains($url, '-motosiklet-') ? 'motosiklet'
         : (preg_match('/-(ticari|minivan|panelvan|kamyon)/', $url) ? 'ticari' : 'otomobil'));
    $tramer = sh_tramer((string)($v['ac'] ?? ''));
    return [
        'id' => $id, 'kategori' => $kat, 'marka' => $marka,
        'seri' => sh_metin($b['Seri'] ?? '', 60), 'model' => sh_metin($b['Model'] ?? '', 100),
        'yil' => sayi_temizle($b['Yıl'] ?? '') ?? 0, 'km' => sayi_temizle($b['KM'] ?? '') ?? 0,
        'renk' => sh_metin($b['Renk'] ?? '', 40), 'fiyat' => $fiyat,
        'yakit' => yakit_esle($yakitHam) ?? (anahtar($yakitHam) ?: null),
        'vites' => vites_esle(sh_metin($b['Vites'] ?? '', 40)) ?? 'otomatik',
        'kasa' => sh_kasa($b['Kasa Tipi'] ?? null),
        'motor_gucu' => sh_metin($b['Motor Gücü'] ?? '', 30) ?: null,
        'motor_hacmi' => sh_metin($b['Motor Hacmi'] ?? '', 30) ?: null,
        'cekis' => sh_cekis($b['Çekiş'] ?? null),
        'baslik' => sh_metin($v['b'] ?? '', 200),
        'aciklama' => ['tr' => ''],
        'fotograflar' => $foto,
        'sahibinden_url' => $url,
        'ilan_tarihi' => sh_tarih((string)($b['İlan Tarihi'] ?? '')) ?? date('Y-m-d'),
        'one_cikan' => false,
        'servis_garantisi' => ($b['Servis Garantisi'] ?? '') === 'Evet',
        'hasar' => [
            'degisen' => sh_parca_birlestir($parca['degisen']),
            'boya' => sh_parca_birlestir($parca['boya']),
            'lokal' => sh_parca_birlestir($parca['lokal']),
            'tramer_tl' => $tramer ?? 0,
            'agir_hasar' => ($b['Ağır Hasar Kayıtlı'] ?? '') === 'Evet',
        ],
        '_tramer_bulundu' => $tramer !== null,
        '_parca_var' => (bool)array_intersect_key((array)($v['parca'] ?? []), SH_PARCA),
        '_tarih_var' => sh_tarih((string)($b['İlan Tarihi'] ?? '')) !== null,
        'kaynak' => 'sahibinden',
    ];
}

/** Sitedeki listeyle birleştirir. Dönüş: [yeni araç listesi, rapor] */
function sh_birlestir(array $site, array $gelen): array {
    $mevcut = [];
    foreach ($site as $i => $e) { $mevcut[(string)$e['id']] = $i; }
    $rapor = ['eklenen' => [], 'guncellenen' => [], 'satildi' => [], 'geri_geldi' => [], 'ayni' => 0];
    $ad = fn(array $a) => trim(($a['marka'] ?? '') . ' ' . ($a['seri'] ?? '') . ' ' . ($a['yil'] ?? ''));
    $liste = $site;
    $gorulen = [];
    foreach ($gelen as $a) {
        $id = (string)$a['id']; $gorulen[$id] = true;
        $tramerVar = $a['_tramer_bulundu']; unset($a['_tramer_bulundu'], $a['_parca_var'], $a['_tarih_var']);
        if (!isset($mevcut[$id])) { $liste[] = $a + ['durum' => 'satista', 'yz_durum' => 'bekliyor']; $rapor['eklenen'][] = $ad($a) . ' – ' . number_format($a['fiyat'], 0, ',', '.') . ' TL'; continue; }
        $e = $site[$mevcut[$id]];
        $f = ilan_farklari($e, $a);
        $y = $e;
        // sahibinden'den gelenler (boş değilse); hasar, açıklama, öne çıkan, servis garantisi elle düzeltilmiş olabilir → korunur
        // Sitedeki marka/model/başlık/açıklama elle düzeltilmiş ve 4 dile çevrilmiş olabilir → korunur.
        // Sahibinden'den yalnız gerçekten değişenler alınır: fiyat, km, fotoğraflar, ilan adresi.
        $f = array_intersect_key($f, array_flip(['fiyat', 'km', 'fotograflar', 'sahibinden_url']));
        foreach (array_keys($f) as $k) { $y[$k] = $a[$k]; }
        if (!isset($e['hasar']) || !is_array($e['hasar'])) { $y['hasar'] = $a['hasar']; }
        elseif ($tramerVar && (int)($e['hasar']['tramer_tl'] ?? 0) !== $a['hasar']['tramer_tl']) { $y['hasar']['tramer_tl'] = $a['hasar']['tramer_tl']; $f['tramer'] = true; }
        if (($e['durum'] ?? 'satista') === 'satildi') {
            if (($e['satildi_kaynak'] ?? '') === 'sahibinden') {   // eklenti gizlemişti, ilan yeniden yayında → geri aç
                $rapor['geri_geldi'][] = $ad($a); $y['durum'] = 'satista'; unset($y['satildi_kaynak']);
            }   // panelden elle "satıldı" yapıldıysa (ilan sahibinden'de henüz duruyor olsa da) gizli kalır
        } else { $y['durum'] = 'satista'; }
        unset($f['aciklama'], $f['durum']);
        // açıklaması hiç yazılmamış sahibinden ilanı → yapay zekâ sırasına (elle/önceden yazılmış açıklamaya dokunulmaz)
        if (trim((string)($y['aciklama']['tr'] ?? '')) === '' && !isset($y['yz_durum'])) { $y['yz_durum'] = 'bekliyor'; }
        $liste[$mevcut[$id]] = $y;
        if ($f) {
            $alanlar = [];
            foreach (array_keys($f) as $k) {
                if ($k === 'fiyat') { $alanlar[] = 'fiyat ' . number_format((int)$e['fiyat'], 0, ',', '.') . ' → ' . number_format((int)$a['fiyat'], 0, ',', '.') . ' TL'; }
                elseif ($k === 'km') { $alanlar[] = 'km ' . number_format((int)$e['km'], 0, ',', '.') . ' → ' . number_format((int)$a['km'], 0, ',', '.'); }
                else { $alanlar[] = KARSILASTIRILAN[$k] ?? $k; }
            }
            $rapor['guncellenen'][] = $ad($a) . ': ' . implode(', ', $alanlar);
        } else { $rapor['ayni']++; }
    }
    foreach ($liste as $i => $e) {
        if (isset($gorulen[(string)$e['id']]) || ($e['kaynak'] ?? '') !== 'sahibinden' || ($e['durum'] ?? 'satista') === 'satildi') { continue; }
        $liste[$i]['durum'] = 'satildi';
        $liste[$i]['satildi_kaynak'] = 'sahibinden';
        $liste[$i]['one_cikan'] = false;
        $rapor['satildi'][] = $ad($e);
    }
    // sıra: satıştakiler en yeni ilan önce, satılanlar sonda
    usort($liste, function ($x, $y) {
        $sx = ($x['durum'] ?? 'satista') === 'satildi'; $sy = ($y['durum'] ?? 'satista') === 'satildi';
        if ($sx !== $sy) { return $sx <=> $sy; }
        return strcmp((string)($y['ilan_tarihi'] ?? ''), (string)($x['ilan_tarihi'] ?? ''));
    });
    // öne çıkan araç satıldıysa: en yeni ilan öne çıkar (sitede her zaman bir öne çıkan olsun)
    $oneCikan = array_filter($liste, fn($a) => !empty($a['one_cikan']) && ($a['durum'] ?? 'satista') !== 'satildi');
    if (!$oneCikan) {
        foreach ($liste as $i => $a) { if (($a['durum'] ?? 'satista') !== 'satildi') { $liste[$i]['one_cikan'] = true; $rapor['one_cikan'] = $ad($a); break; } }
    }
    return [array_values($liste), $rapor];
}

/** Okunan veride "sahibinden sayfa yapısı değişmiş olabilir" işaretleri arar. Dönüş: uyarı satırları (boşsa sorun yok).
    Tek tük eksik normaldir (ör. motosiklette hasar şeması yok); aynı eksik birçok ilanda birden görülürse uyarılır. */
function sh_okuma_kontrol(array $gelen, array $rapor): array {
    $n = count($gelen);
    if ($n === 0) { return []; }
    $esik = max(2, (int)ceil($n * 0.25));
    $say = ['foto' => 0, 'parca' => 0, 'km' => 0, 'model' => 0, 'tarih' => 0];
    foreach ($gelen as $a) {
        $oto = $a['kategori'] !== 'motosiklet';
        if (!$a['fotograflar']) { $say['foto']++; }
        if ($oto && !$a['_parca_var']) { $say['parca']++; }
        if ($oto && !$a['km']) { $say['km']++; }
        if ($a['seri'] === '' && $a['model'] === '') { $say['model']++; }
        if (!$a['_tarih_var']) { $say['tarih']++; }
    }
    $ad = ['foto' => 'fotoğraf okunamadı', 'parca' => 'boya/değişen şeması okunamadı', 'km' => 'km okunamadı',
           'model' => 'seri/model okunamadı', 'tarih' => 'ilan tarihi okunamadı'];
    $u = [];
    foreach ($say as $k => $c) { if ($c >= $esik) { $u[] = "$n ilanın $c tanesinde {$ad[$k]} – sahibinden sayfa yapısı değişmiş olabilir, Cem'e haber verin."; } }
    if (count($rapor['satildi'] ?? []) >= max(3, (int)ceil($n * 0.2))) {
        $u[] = count($rapor['satildi']) . ' ilan birden sahibinden\'den kalkmış görünüyor (satıldı olarak gizlendi). Gerçekten satıldılarsa sorun yok; değilse Cem\'e haber verin (panel → Yedekler ile geri alınabilir).';
    }
    return $u;
}
