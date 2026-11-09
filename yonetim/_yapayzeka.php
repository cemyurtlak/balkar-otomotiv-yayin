<?php
/* Balkar Otomotiv – yapay zekâ ile ilan açıklaması (Aşama 55).
   Sahibinden ilan açıklamasından (firma tanıtımı, takas, kredi kartı vb. tekrar eden yazılar atılarak) araca özel kısa
   bir açıklama 4 dilde (tr/en/ru/de) ve yazım hatası düzeltilmiş başlık üretir.
   Sağlayıcılar sırayla denenir: 1) Google Gemini  (NVIDIA NIM yedeği 9 Kasım'da kaldırıldı: sürekli zaman aşımı).
   Anahtarlar yalnız sunucuda: yonetim/yz_anahtar.php (panel → Yapay zekâ sayfasından yazılır; GitHub'a ve
   guncelle.php'ye girmez). Ham sahibinden açıklamaları: veri/yedek/sahibinden_ac/<ilanNo>.txt (dışarıya kapalı). */
declare(strict_types=1);

const YZ_DOSYA = __DIR__ . '/yz_anahtar.php';
const YZ_AC_KLASOR = YEDEK . '/sahibinden_ac';
/* Model alanına virgülle birden çok model yazılabilir: sırayla denenir (ilki yoğun/kapalıysa sonraki). */
const YZ_VARSAYILAN = ['gemini_anahtar' => '', 'gemini2_anahtar' => '', 'gemini3_anahtar' => '', 'gemini_model' => 'gemini-3.6-flash, gemini-3.5-flash-lite, gemini-3.8-flash',
                      ];
// eski varsayılanlar → yenisi (8 Kasım: 3.8-flash çok yoğundu; NVIDIA v4-flash ve -0731 kullanımdan kalktı)
const YZ_ESKI_MODEL = ['gemini-3.8-flash' => 'gemini_model', 'gemini-3.8-flash, gemini-3.6-flash, gemini-3.5-flash-lite' => 'gemini_model',
                       'gemini-3.6-flash, gemini-3.8-flash, gemini-3.5-flash-lite' => 'gemini_model',];   // eski varsayılanlar → yenisi

function yz_ayar(): array {
    $a = is_file(YZ_DOSYA) ? include YZ_DOSYA : [];
    if (is_array($a) && (isset($a['nim_anahtar']) || isset($a['nim_model']))) {   // NVIDIA kaldırıldı → dosyadan da sil
        unset($a['nim_anahtar'], $a['nim_model']);
        yz_ayar_yaz_ham($a);
    }
    $a = array_merge(YZ_VARSAYILAN, is_array($a) ? $a : []);
    foreach (YZ_ESKI_MODEL as $eski => $alan) { if (trim($a[$alan]) === $eski) { $a[$alan] = YZ_VARSAYILAN[$alan]; } }
    return $a;
}
function yz_modeller(string $s): array { return array_values(array_filter(array_map('trim', explode(',', $s)))); }
function yz_ayar_yaz(array $a): void {
    yz_ayar_yaz_ham(array_intersect_key(array_merge(yz_ayar(), $a), YZ_VARSAYILAN));
}
function yz_ayar_yaz_ham(array $a): void {
    $icerik = "<?php\n/* Yapay zekâ anahtarları – panelden yazılır. GitHub'a koymayın. */\nreturn " . var_export($a, true) . ";\n";
    file_put_contents(YZ_DOSYA . '.tmp', $icerik, LOCK_EX);
    @chmod(YZ_DOSYA . '.tmp', 0600);
    rename(YZ_DOSYA . '.tmp', YZ_DOSYA);
    if (function_exists('opcache_invalidate')) { @opcache_invalidate(YZ_DOSYA, true); }
}
/** Girilmiş Gemini anahtarları: [sıra no => anahtar] (1, 2, 3). */
function yz_gemini_anahtarlar(array $a): array {
    $l = [];
    foreach ([1 => 'gemini_anahtar', 2 => 'gemini2_anahtar', 3 => 'gemini3_anahtar'] as $n => $k) { if (($a[$k] ?? '') !== '') { $l[$n] = $a[$k]; } }
    return $l;
}
function yz_hazir(): bool { return (bool)yz_gemini_anahtarlar(yz_ayar()); }

function yz_post(string $url, array $baslik, array $govde, int $sure): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $sure, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $baslik),
        CURLOPT_POSTFIELDS => json_encode($govde, JSON_UNESCAPED_UNICODE)]);
    $v = curl_exec($ch); $kod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hata = curl_error($ch); curl_close($ch);
    if ($v === false) { throw new RuntimeException('bağlantı hatası: ' . $hata); }
    $j = json_decode((string)$v, true);
    if ($kod !== 200) {
        $m = is_array($j) ? (string)($j['error']['message'] ?? $j['detail'] ?? $j['title'] ?? '') : '';
        throw new RuntimeException("HTTP $kod" . ($kod === 429 ? ' (kota doldu)' : '') . ($m !== '' ? ': ' . mb_substr($m, 0, 160) : ''));
    }
    return is_array($j) ? $j : [];
}

function yz_gemini(string $istem, array $a, int $sure, string $model): string {
    $j = yz_post('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
        ['x-goog-api-key: ' . $a['gemini_anahtar']],
        ['contents' => [['role' => 'user', 'parts' => [['text' => $istem]]]],
         'generationConfig' => ['temperature' => 0.3, 'responseMimeType' => 'application/json', 'maxOutputTokens' => 2048]], $sure);
    $t = '';
    foreach ($j['candidates'][0]['content']['parts'] ?? [] as $p) { if (empty($p['thought'])) { $t .= (string)($p['text'] ?? ''); } }
    if ($t === '') { throw new RuntimeException('boş cevap'); }
    return $t;
}

/** İstemi sırayla sağlayıcılara gönderir. Dönüş: [metin, sağlayıcı adı]. Hepsi başarısızsa RuntimeException. */
/* Kota dolan (429) ya da cevap vermeyen model bir süre atlanır; bilgisi veri/yedek/yz_bekle.json'da. */
const YZ_BEKLE = YEDEK . '/yz_bekle.json';
function yz_bekle_oku(): array { $b = is_file(YZ_BEKLE) ? json_decode((string)file_get_contents(YZ_BEKLE), true) : []; return is_array($b) ? array_filter($b, fn($t) => $t > time()) : []; }
function yz_bekle_yaz(string $model, int $sn): void {
    $b = yz_bekle_oku(); $b[$model] = time() + $sn;
    if (!is_dir(YEDEK)) { @mkdir(YEDEK, 0755, true); }
    @file_put_contents(YZ_BEKLE, json_encode($b), LOCK_EX);
}

function yz_sor(string $istem, int $sure = 40): array {
    // $sure: TÜM denemeler için toplam süre (sn). Hostinger istekleri ~60 sn'de keser (504); her deneme kalan süreyle sınırlı.
    // Sıra: her Gemini modeli için önce 1., sonra 2., 3. anahtar. Kota (429) anahtar+model bazında beklemeye alınır.
    $a = yz_ayar(); $hatalar = []; $son = time() + $sure; $bekle = yz_bekle_oku(); $atlanan = 0;
    $sira = [];
    foreach (yz_modeller($a['gemini_model']) as $model) {
        foreach (yz_gemini_anahtarlar($a) as $n => $k) { $sira[] = ["Gemini#$n", 'yz_gemini', ['gemini_anahtar' => $k] + $a, $model, "g$n:$model", "g$n"]; }
    }
    $yetkisiz = [];
    foreach ($sira as [$ad, $fn, $aa, $model, $bk, $anahtar_id]) {
        if (isset($yetkisiz[$anahtar_id])) { continue; }
        if (isset($bekle[$bk]) || isset($bekle[$model])) { $atlanan++; continue; }   // kısa süre önce kota doldu / takıldı
        for ($deneme = 1; $deneme <= 2; $deneme++) {
            $kalan = $son - time();
            if ($kalan < 6) { $hatalar[] = 'süre yetmedi'; break 2; }
            try { return [$fn($istem, $aa, min(18, $kalan), $model), "$ad $model"]; }
            catch (Throwable $e) {
                $m = $e->getMessage();
                // 503 / 500 (yoğunluk) → kısa bekleyip bir kez daha; yetki hatası → bu anahtarın diğer modellerini deneme
                if ($deneme === 1 && preg_match('/HTTP 50[023]/', $m) && $son - time() > 12) { sleep(2); continue; }
                $hatalar[] = "$ad $model: " . mb_substr($m, 0, 90);
                if (str_contains($m, 'HTTP 429')) { yz_bekle_yaz($bk, preg_match('/per ?day|daily|günlük/i', $m) ? 6 * 3600 : 20 * 60); }
                elseif (str_contains($m, 'timed out') || preg_match('/HTTP 50[023]/', $m)) { yz_bekle_yaz($model, 10 * 60); }   // model yoğun: tüm anahtarlar için
                if (preg_match('/HTTP 40[013]/', $m)) { $yetkisiz[$anahtar_id] = true; }
                continue 2;
            }
        }
    }
    if ($atlanan && !$hatalar) { $hatalar[] = "tüm modeller kısa süreliğine beklemede (kota doldu ya da yoğun); biraz sonra tekrar denenecek"; }
    throw new RuntimeException($hatalar ? implode(' · ', $hatalar) : 'yapay zekâ anahtarı girilmemiş');
}


function yz_json(string $t): array {
    $t = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($t)));
    $i = strpos($t, '{'); $s = strrpos($t, '}');
    $j = ($i !== false && $s !== false) ? json_decode(substr($t, $i, $s - $i + 1), true) : null;
    if (!is_array($j)) { throw new RuntimeException('cevap JSON değil'); }
    return $j;
}

function yz_istem(array $arac, string $ham): string {
    $bilgi = sprintf('%s %s %s, %s, %s km, %s, %s', $arac['marka'] ?? '', $arac['seri'] ?? '', $arac['model'] ?? '', $arac['yil'] ?? '',
        number_format((int)($arac['km'] ?? 0), 0, ',', '.'), $arac['yakit'] ?? '', $arac['vites'] ?? '');
    $hasar = [];
    foreach (['degisen' => 'Değişen', 'boya' => 'Boyalı', 'lokal' => 'Lokal boyalı'] as $k => $ad) { if (!empty($arac['hasar'][$k]['tr'])) { $hasar[] = "$ad: " . $arac['hasar'][$k]['tr']; } }
    $hasar[] = 'Tramer: ' . number_format((int)($arac['hasar']['tramer_tl'] ?? 0), 0, ',', '.') . ' TL';
    $ham = mb_substr(trim(preg_replace("/[ \t]+/u", ' ', preg_replace("/\n\s*\n+/u", "\n", $ham))), 0, 6000);
    $hasarS = implode('; ', $hasar);
    $baslikS = (string)($arac['baslik'] ?? '');
    return <<<ISTEM
Bir ikinci el araç galerisinin (Balkar Otomotiv, Antalya) web sitesi için ilan metni hazırlıyorsun.

SİTEDE AYRICA GÖSTERİLEN BİLGİLER (açıklamada TEKRARLAMA):
Araç: $bilgi
Hasar şeması: $hasarS

SAHİBİNDEN İLAN BAŞLIĞI: $baslikS

SAHİBİNDEN İLAN AÇIKLAMASI (ham):
---
$ham
---

GÖREV:
1. "aciklama": Ham açıklamadan YALNIZ bu araca özel bilgileri çıkar (donanım, bakım, ekspertiz/hasar ayrıntısı, tramerin nedeni,
   airbag/şasi durumu, tescil yılı gibi). 1–3 kısa, sade cümle. Şunları KESİNLİKLE yazma: firma tanıtımı, yetki belgesi,
   takas, kredi kartı/taksit/kredi koşulları, senetle satış, "arayınız", telefon, adres, internet adresi, "dilediğiniz ekspertize
   gösterebilirsiniz", büyük harf ve ünlem süsleri. Yukarıda "sitede ayrıca gösterilen" bilgileri (yıl, km, yakıt, vites,
   değişen/boyalı parça listesi, tramer tutarı) tekrar yazma. Ham metinde araca özel ek bilgi yoksa dört dil için de boş metin ("") ver.
   Ham metinde olmayan hiçbir şeyi uydurma. Aynı anlamı dört dilde ver: tr (Türkçe), en (İngilizce), ru (Rusça), de (Almanca).
2. "baslik": Sahibinden başlığını aynı üslupla (BÜYÜK HARF, / ayraçları) koru; yalnız bariz yazım hatalarını düzelt
   (ör. SANROFF→SANROOF, HAYLET→HAYALET, TRAMERSZ→TRAMERSİZ, FUL→FULL). Kelime ekleme ya da çıkarma.

ÖRNEK açıklama üslubu:
- {"tr":"Airbag'ler orijinal. Sol şasi kulağında ezik var; hasar kaydı normal, ağır hasar değil.","en":"Airbags are original. The left chassis mount has a dent; the damage record is standard, not a major-damage record.","ru":"Подушки безопасности оригинальные. Есть вмятина на левом кронштейне лонжерона; запись о повреждении обычная, не тяжёлая.","de":"Airbags original. Leichte Delle am linken Rahmenausleger; normaler Schadenseintrag, kein schwerer Unfallschaden."}
- {"tr":"Tramer kaydı far değişiminden kaynaklanıyor.","en":"The insurance-claim record is from a headlamp replacement.","ru":"Страховая запись связана с заменой фары.","de":"Der Versicherungseintrag stammt von einem Scheinwerfertausch."}

YALNIZ şu biçimde geçerli JSON döndür, başka hiçbir şey yazma:
{"baslik":"...","aciklama":{"tr":"...","en":"...","ru":"...","de":"..."}}
ISTEM;
}

/** Araç için açıklama + başlık üretir. Dönüş: ['baslik'=>..., 'aciklama'=>[tr,en,ru,de], 'saglayici'=>...] */
function yz_ilan_yaz(array $arac, string $ham, int $sure = 40): array {
    [$t, $kim] = yz_sor(yz_istem($arac, $ham), $sure);
    $j = yz_json($t);
    $ac = [];
    foreach (['tr', 'en', 'ru', 'de'] as $d) { $ac[$d] = mb_substr(trim(strip_tags((string)($j['aciklama'][$d] ?? ''))), 0, 600); }
    if ($ac['tr'] === '') { $ac = ['tr' => '']; }   // ek bilgi yok
    elseif (in_array('', $ac, true)) { throw new RuntimeException("$kim: dillerden biri boş geldi"); }
    $b = trim(strip_tags((string)($j['baslik'] ?? '')));
    // başlık yalnız küçük yazım düzeltmesi olmalı: çok farklıysa sahibinden başlığı kalır
    $eski = (string)($arac['baslik'] ?? '');
    if ($b === '' || mb_strlen($b) > 200 || abs(mb_strlen($b) - mb_strlen($eski)) > 12) { $b = $eski; }
    return ['baslik' => $b, 'aciklama' => $ac, 'saglayici' => $kim];
}

function yz_ham_kaydet(string $id, string $ac): void {
    if (!preg_match('/^\d{8,12}$/', $id) || trim($ac) === '') { return; }
    if (!is_dir(YZ_AC_KLASOR)) { @mkdir(YZ_AC_KLASOR, 0755, true); }
    @file_put_contents(YZ_AC_KLASOR . "/$id.txt", mb_substr($ac, 0, 20000), LOCK_EX);
}
function yz_ham_oku(string $id): string {
    $f = YZ_AC_KLASOR . '/' . preg_replace('/\D/', '', $id) . '.txt';
    return is_file($f) ? (string)file_get_contents($f) : '';
}

/** Açıklaması bekleyen araçları doldurur (süre sınırlı). $liste referansla değişir. Dönüş: rapor satırları. */
function yz_bekleyenleri_doldur(array &$liste, int $sure_siniri = 60, ?array $yalniz = null): array {
    $rapor = [];
    if (!yz_hazir()) { return $rapor; }
    $son = time() + $sure_siniri;
    foreach ($liste as $i => $a) {
        $id = (string)$a['id'];
        if ($yalniz !== null ? !in_array($id, $yalniz, true) : (($a['yz_durum'] ?? '') !== 'bekliyor')) { continue; }
        if ($son - time() < 10) { $rapor[] = 'süre doldu, kalanlar bir sonraki çalışmada yazılacak'; break; }
        $ham = yz_ham_oku($id);
        if ($ham === '') { $liste[$i]['yz_durum'] = 'ham_yok'; continue; }
        $ad = trim(($a['marka'] ?? '') . ' ' . ($a['seri'] ?? '') . ' ' . ($a['yil'] ?? ''));
        try {
            $y = yz_ilan_yaz($a, $ham, $son - time());
            $liste[$i]['aciklama'] = $y['aciklama'];
            $liste[$i]['baslik'] = $y['baslik'];
            $liste[$i]['yz_durum'] = 'tamam';
            $rapor[] = "$ad: açıklama yazıldı ({$y['saglayici']})" . ($y['aciklama']['tr'] === '' ? ' – ilanda araca özel ek bilgi yok' : '');
        } catch (Throwable $e) {
            $liste[$i]['yz_durum'] = 'bekliyor';
            $rapor[] = "$ad: açıklama yazılamadı – " . $e->getMessage();
            if (str_contains($e->getMessage(), 'girilmemiş')) { break; }
        }
    }
    return $rapor;
}
