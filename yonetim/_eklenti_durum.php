<?php
/* Balkar Otomotiv – sahibinden eklentisinin sağlık durumu (Aşama 57).
   Eklenti her çalışmada (başarı, uyarı, hata) sunucuya bildirir; burada veri/yedek/eklenti_durum.json'a yazılır.
   Kullananlar: sahibinden_al.php (yazar), panel üst uyarısı (_ortak.php sablon_bas), eklenti_durum.php (GitHub günlük kontrolü). */
declare(strict_types=1);

const EKLENTI_DURUM = YEDEK . '/eklenti_durum.json';
const EKLENTI_ESIK_GUN = 3;   // bu kadar gün başarılı güncelleme yoksa uyarı

function eklenti_durum_oku(): array {
    $d = is_file(EKLENTI_DURUM) ? json_decode((string)file_get_contents(EKLENTI_DURUM), true) : null;
    return is_array($d) ? $d : [];
}
function eklenti_durum_yaz(array $alan): void {
    if (!is_dir(YEDEK)) { @mkdir(YEDEK, 0755, true); }
    $d = array_merge(eklenti_durum_oku(), $alan, ['son_deneme' => date('c')]);
    @file_put_contents(EKLENTI_DURUM . '.tmp', json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    @rename(EKLENTI_DURUM . '.tmp', EKLENTI_DURUM);
}

/** Dönüş: ['saglikli' => bool, 'sorunlar' => [...], 'ozet' => '...'] */
function eklenti_saglik(): array {
    $d = eklenti_durum_oku();
    $sorun = [];
    $basari = isset($d['son_basari']) ? strtotime($d['son_basari']) : 0;
    $hata = isset($d['son_hata_zaman']) ? strtotime($d['son_hata_zaman']) : 0;
    if (!$d) {
        return ['saglikli' => true, 'sorunlar' => [], 'ozet' => 'Eklenti henüz hiç çalışmadı.', 'kurulmadi' => true];
    }
    if ($hata >= $basari && $hata > 0) { $sorun[] = 'Son çalışma hata verdi (' . date('d.m.Y H:i', $hata) . '): ' . ($d['son_hata'] ?? ''); }
    $gun = $basari ? (int)floor((time() - $basari) / 86400) : null;
    if ($basari === 0) { $sorun[] = 'Eklenti hiç başarılı güncelleme yapmadı.'; }
    elseif ($gun >= EKLENTI_ESIK_GUN) { $sorun[] = "$gun gündür başarılı güncelleme yok (son: " . date('d.m.Y H:i', $basari) . '). Bilgisayar/Chrome kapalı ya da sahibinden oturumu düşmüş olabilir.'; }
    if (!empty($d['son_uyari']) && ($d['son_uyari_zaman'] ?? '') === ($d['son_basari'] ?? '-')) { $sorun[] = 'Son güncellemede eksik okuma şüphesi: ' . implode(' · ', (array)$d['son_uyari']); }
    $ozet = $basari ? 'Son başarılı güncelleme: ' . date('d.m.Y H:i', $basari) . ' (' . ($d['okunan'] ?? '?') . ' ilan, eklenti ' . ($d['surum'] ?? '?') . ')' : 'Başarılı güncelleme yok.';
    return ['saglikli' => !$sorun, 'sorunlar' => $sorun, 'ozet' => $ozet];
}
