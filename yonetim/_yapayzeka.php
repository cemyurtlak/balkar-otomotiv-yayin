<?php
/* Balkar Otomotiv – yapay zekâ ile ilan açıklaması (Aşama 55).
   Sahibinden ilan açıklamasından (firma tanıtımı, takas, kredi kartı vb. tekrar eden yazılar atılarak) araca özel kısa
   bir açıklama 4 dilde (tr/en/ru/de) ve yazım hatası düzeltilmiş başlık üretir.
   Sağlayıcılar sırayla denenir: 1) Google Gemini  2) NVIDIA NIM (OpenAI uyumlu; ör. deepseek-ai/deepseek-v4-flash-0731).
   Anahtarlar yalnız sunucuda: yonetim/yz_anahtar.php (panel → Yapay zekâ sayfasından yazılır; GitHub'a ve
   guncelle.php'ye girmez). Ham sahibinden açıklamaları: veri/yedek/sahibinden_ac/<ilanNo>.txt (dışarıya kapalı). */
declare(strict_types=1);

const YZ_DOSYA = __DIR__ . '/yz_anahtar.php';
const YZ_AC_KLASOR = YEDEK . '/sahibinden_ac';
/* Model alanına virgülle birden çok model yazılabilir: sırayla denenir (ilki yoğun/kapalıysa sonraki). */
const YZ_VARSAYILAN = ['gemini_anahtar' => '', 'gemini_model' => 'gemini-3.6-flash, gemini-3.8-flash, gemini-3.5-flash-lite',
                       'nim_anahtar' => '', 'nim_model' => 'deepseek-ai/deepseek-v4.1-flash'];
// eski varsayılanlar → yenisi (8 Kasım: 3.8-flash çok yoğundu; NVIDIA v4-flash ve -0731 kullanımdan kalktı)
const YZ_ESKI_MODEL = ['gemini-3.8-flash' => 'gemini_model', 'gemini-3.8-flash, gemini-3.6-flash, gemini-3.5-flash-lite' => 'gemini_model',
                       'deepseek-ai/deepseek-v4-flash-0731' => 'nim_model', 'deepseek-ai/deepseek-v4-flash' => 'nim_model'];   // eski varsayılanlar → yenisi

function yz_ayar(): array {
    $a = is_file(YZ_DOSYA) ? include YZ_DOSYA : [];
    $a = array_merge(YZ_VARSAYILAN, is_array($a) ? $a : []);
    foreach (YZ_ESKI_MODEL as $eski => $alan) { if (trim($a[$alan]) === $eski) { $a[$alan] = YZ_VARSAYILAN[$alan]; } }
    return $a;
}
function yz_modeller(string $s): array { return array_values(array_filter(array_map('trim', explode(',', $s)))); }
function yz_ayar_yaz(array $a): void {
    $a = array_intersect_key(array_merge(yz_ayar(), $a), YZ_VARSAYILAN);
    $icerik = "<?php\n/* Yapay zekâ anahtarları – panelden yazılır. GitHub'a koymayın. */\nreturn " . var_export($a, true) . ";\n";
    file_put_contents(YZ_DOSYA . '.tmp', $icerik, LOCK_EX);
    @chmod(YZ_DOSYA . '.tmp', 0600);
    rename(YZ_DOSYA . '.tmp', YZ_DOSYA);
    if (function_exists('opcache_invalidate')) { @opcache_invalidate(YZ_DOSYA, true); }
}
function yz_hazir(): bool { $a = yz_ayar(); return $a['gemini_anahtar'] !== '' || $a['nim_anahtar'] !== ''; }

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
function yz_nim(string $istem, array $a, int $sure, string $model): string {
    $j = yz_post('https://integrate.api.nvidia.com/v1/chat/completions', ['Authorization: Bearer ' . $a['nim_anahtar']],
        ['model' => $model, 'messages' => [['role' => 'user', 'content' => $istem]], 'temperature' => 0.3,
         'max_tokens' => 2048, 'stream' => false, 'chat_template_kwargs' => ['thinking' => false]], $sure);
    $t = (string)($j['choices'][0]['message']['content'] ?? '');
    if ($t === '') { throw new RuntimeException('boş cevap'); }
    return $t;
}

/** İstemi sırayla sağlayıcılara gönderir. Dönüş: [metin, sağlayıcı adı]. Hepsi başarısızsa RuntimeException. */
function yz_sor(string $istem, int $sure = 40): array {
    $a = yz_ayar(); $hatalar = [];
    foreach ([['Gemini', 'gemini_anahtar', 'gemini_model', 'yz_gemini'], ['NVIDIA', 'nim_anahtar', 'nim_model', 'yz_nim']] as [$ad, $ak, $mk, $fn]) {
        if ($a[$ak] === '') { continue; }
        foreach (yz_modeller($a[$mk]) as $model) {
            for ($deneme = 1; $deneme <= 2; $deneme++) {
                try { return [$fn($istem, $a, $sure, $model), "$ad $model"]; }
                catch (Throwable $e) {
                    $m = $e->getMessage();
                    // 503 / 500 (yoğunluk) → kısa bekleyip bir kez daha; yetki hatası → bu sağlayıcının diğer modellerini deneme
                    if ($deneme === 1 && preg_match('/HTTP 50[023]/', $m)) { sleep(2); continue; }
                    $hatalar[] = "$ad $model: $m";
                    if (preg_match('/HTTP 40[13]/', $m)) { continue 3; }
                    continue 2;
                }
            }
        }
    }
    throw new RuntimeException($hatalar ? implode(' · ', $hatalar) : 'yapay zekâ anahtarı girilmemiş');
}

/** NVIDIA'daki modellerden adı $ara içerenleri listeler (panelde model önerisi için). */
function yz_nim_modelleri(string $anahtar, string $ara = 'deepseek'): array {
    $ch = curl_init('https://integrate.api.nvidia.com/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $anahtar]]);
    $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
    $l = [];
    foreach ((array)($j['data'] ?? []) as $m) { $id = (string)($m['id'] ?? ''); if ($id !== '' && stripos($id, $ara) !== false) { $l[] = $id; } }
    sort($l);
    return $l;
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
    $bas = time();
    foreach ($liste as $i => $a) {
        $id = (string)$a['id'];
        if ($yalniz !== null ? !in_array($id, $yalniz, true) : (($a['yz_durum'] ?? '') !== 'bekliyor')) { continue; }
        if (time() - $bas > $sure_siniri) { $rapor[] = 'süre doldu, kalanlar bir sonraki çalışmada yazılacak'; break; }
        $ham = yz_ham_oku($id);
        if ($ham === '') { $liste[$i]['yz_durum'] = 'ham_yok'; continue; }
        $ad = trim(($a['marka'] ?? '') . ' ' . ($a['seri'] ?? '') . ' ' . ($a['yil'] ?? ''));
        try {
            $y = yz_ilan_yaz($a, $ham, max(15, min(40, $sure_siniri - (time() - $bas))));
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
