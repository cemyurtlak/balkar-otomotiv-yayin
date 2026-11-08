/* Balkar Otomotiv — site betiği. Bağımlılık yok.
   Metinler sayfaya gömülü window.T içinden gelir (4 dil olustur.py tarafından üretilir). */
(function () {
  "use strict";
  var T = window.T || {}, DIL = window.DIL || "tr", AYAR = window.AYAR || {};
  var VERI = window.ARACLAR || { araclar: [] };
  var ARACLAR = (VERI.araclar || []).filter(function (a) { return a && a.durum !== "satildi"; });
  var YEREL = { tr: "tr-TR", en: "en-GB", ru: "ru-RU", de: "de-DE" }[DIL] || "tr-TR";

  function t(k, vars) {
    var s = T[k];
    if (s == null) { return k; }
    if (vars) { Object.keys(vars).forEach(function (v) { s = s.split("{" + v + "}").join(vars[v]); }); }
    return s;
  }
  function $(s, k) { return (k || document).querySelector(s); }
  function $$(s, k) { return Array.prototype.slice.call((k || document).querySelectorAll(s)); }
  function kacis(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function sayi(n) { return new Intl.NumberFormat(YEREL, { maximumFractionDigits: 0 }).format(n); }
  function para(n) { return sayi(Math.round(n)) + " TL"; }
  function ikon(ad) { return '<svg aria-hidden="true"><use href="#i-' + ad + '"/></svg>'; }
  function wa(metin) { return "https://wa.me/" + AYAR.whatsapp + "?text=" + encodeURIComponent(metin); }
  /* Aşama 49: WhatsApp'a giden her şeyin bir kopyası sunucudaki veritabanına (teklif/talep.php → panel "Tüm kayıtlar").
     Yanıt beklenmez, hata yutulur: WhatsApp akışı hiçbir durumda etkilenmez. PHP olmayan sunucuda (GitHub Pages) istek boşa gider. */
  var TALEP_UC = "../teklif/talep.php";
  function talepKaydet(tur, mesaj, alanlar, tuzak) {
    try {
      if (!/^https?:$/.test(location.protocol) || !mesaj) { return; }
      var yol = location.pathname.split("/").slice(-2).join("/") + location.search;
      var govde = JSON.stringify({ tur: tur, mesaj: mesaj, alanlar: alanlar || {}, dil: DIL, sayfa: yol, web_adresi: tuzak || "" });
      /* text/plain: sendBeacon bazı tarayıcılarda application/json gövdeyi reddeder; sunucu gövdeyi JSON olarak okur */
      if (navigator.sendBeacon && navigator.sendBeacon(TALEP_UC, new Blob([govde], { type: "text/plain;charset=UTF-8" }))) { return; }
      if (window.fetch) { fetch(TALEP_UC, { method: "POST", headers: { "Content-Type": "text/plain;charset=UTF-8" }, body: govde, keepalive: true }).catch(function () {}); }
    } catch (e) { /* kayıt olmasa da WhatsApp çalışır */ }
  }
  /* WhatsApp bağlantısı tıklamaları (araç sorusu, kredi, ödeme, aradığım araç, genel düğmeler). Formlar kendi kaydını yapar. */
  function waTiklamalari() {
    function kaydet(e) {
      if (e.type === "auxclick" && e.button !== 1) { return; }
      var a = e.target.closest && e.target.closest('a[href^="https://wa.me/"]');
      if (!a) { return; }
      var tur = a.hasAttribute("data-wa-arac") ? "arac" : a.hasAttribute("data-kredi-wa") ? "kredi" : a.hasAttribute("data-odeme-wa") ? "odeme" :
        a.hasAttribute("data-wa-aradigim") ? "arama" : "genel";
      if (a.closest("[data-siparis-form], [data-teklif-form]")) { return; }   // ön sipariş / aracını sat zaten kaydedildi
      var mesaj = "";
      try { mesaj = new URL(a.href).searchParams.get("text") || ""; } catch (x) { mesaj = ""; }
      talepKaydet(tur, mesaj || t("ortak.wa_genel_mesaj"));
    }
    document.addEventListener("click", kaydet, true);
    document.addEventListener("auxclick", kaydet, true);
  }
  function anahtar(s) {
    return String(s || "").toLocaleLowerCase("tr").replace(/ı/g, "i").replace(/ğ/g, "g").replace(/ü/g, "u").replace(/ş/g, "s").replace(/ö/g, "o").replace(/ç/g, "c").replace(/[^a-z0-9]+/g, "_").replace(/^_|_$/g, "");
  }
  /* sabit değerlerin (kategori, yakıt, vites...) dile göre adı; çevirisi olmayan yeni bir değer gelirse olduğu gibi gösterilir */
  function etiket(alan, deger) {
    if (deger == null || deger === "") { return ""; }
    var k = "arac." + alan + "_" + deger;
    if (T[k] != null) { return T[k]; }
    var s = String(deger).replace(/_/g, " ");
    return s.charAt(0).toLocaleUpperCase(YEREL) + s.slice(1);
  }
  function ceviriVeyaHam(onek, deger) {
    if (!deger) { return ""; }
    var k = "arac." + onek + "_" + anahtar(deger);
    return T[k] != null ? T[k] : deger;
  }

  /* ---------------- araç yardımcıları */
  function seriAdi(a) {
    var m = /^(.+?) Serisi$/.exec(a.seri || "");
    if (!m || DIL === "tr") { return a.seri || ""; }
    var x = m[1], mercedes = /mercedes/i.test(a.marka || ""), bmw = /bmw/i.test(a.marka || "");
    if (mercedes) { return { en: x + "-Class", ru: x + "-класс", de: x + "-Klasse" }[DIL]; }
    return { en: x + " Series", ru: x + " серии", de: bmw ? x + "er" : x + "-Serie" }[DIL];
  }
  function aracAdi(a) { return [a.marka, seriAdi(a)].filter(Boolean).join(" "); }
  function yilGecerli(v) { var y = Number(v); return /^\d{4}$/.test(String(v)) && y >= 1900 && y <= new Date().getFullYear() + 1; }
  function aracTamAd(a) { return [a.yil, a.marka, seriAdi(a), a.model].filter(Boolean).join(" "); }
  function aracLink(a) { return "arac.html?id=" + encodeURIComponent(a.id); }
  function yeniMi(a) {
    if (!a.ilan_tarihi || !VERI.guncelleme) { return false; }
    return (new Date(VERI.guncelleme) - new Date(a.ilan_tarihi)) / 864e5 <= 3;
  }
  function temizMi(a) {
    var h = a.hasar; return !!h && !h.degisen && !h.boya && !h.lokal && !h.agir_hasar;
  }
  function buyukFoto(u) { return String(u).replace("/x5_", "/x16_"); }
  function cokDilli(v) { if (!v) { return ""; } if (typeof v === "string") { return v; } return v[DIL] || v.tr || ""; }
  function fotoHTML(a, buyuk) {
    var f = (a.fotograflar || [])[0];
    var yok = '<div class="foto-yok">' + ikon("araba") + '<span>' + kacis(t("arac.foto_yok")) + "</span></div>";
    if (!f) { return yok; }
    return yok + '<img src="' + kacis(f) + '" alt="' + kacis(aracTamAd(a)) + '" loading="' + (buyuk ? "eager" : "lazy") + '" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">';
  }
  function kartHTML(a) {
    var oz = [];
    if (a.km != null) { oz.push("<li>" + ikon("gosterge") + sayi(a.km) + " km</li>"); }
    if (a.yakit) { oz.push("<li>" + ikon("yakit") + kacis(etiket("yakit", a.yakit)) + "</li>"); }
    if (a.vites) { oz.push("<li>" + ikon("vites") + kacis(etiket("vites", a.vites)) + "</li>"); }
    return '<a class="arac-kart" href="' + aracLink(a) + '">' +
      '<div class="foto">' + fotoHTML(a) + '<span class="arac-yil">' + kacis(a.yil) + "</span>" +
      (yeniMi(a) ? '<span class="arac-yeni">' + kacis(t("arac.yeni")) + "</span>" : "") +
      (temizMi(a) ? '<span class="arac-temiz">' + kacis(t("arac.hatasiz")) + "</span>" : "") + "</div>" +
      '<div class="arac-govde"><h3 class="arac-baslik">' + kacis(aracAdi(a)) + "</h3>" +
      '<p class="arac-model">' + kacis(a.model || "") + "</p>" +
      '<p class="arac-fiyat">' + (a.fiyat ? para(a.fiyat) : "") + "</p>" +
      '<ul class="arac-ozellik">' + oz.join("") + "</ul></div></a>";
  }
  function sirala(liste, sira) {
    var k = liste.slice();
    var f = {
      yeni: function (a, b) { return String(b.ilan_tarihi || "").localeCompare(String(a.ilan_tarihi || "")); },
      fiyat_artan: function (a, b) { return (a.fiyat || 0) - (b.fiyat || 0); },
      fiyat_azalan: function (a, b) { return (b.fiyat || 0) - (a.fiyat || 0); },
      km: function (a, b) { return (a.km || 0) - (b.km || 0); },
      yil: function (a, b) { return (b.yil || 0) - (a.yil || 0); }
    }[sira] || function () { return 0; };
    return k.sort(f);
  }
  /* Seçim listeleri her zaman satıştaki araçlardan üretilir: yalnızca gerçekten araç olan seçenekler (adetleriyle) görünür,
     panelden yeni bir marka/kategori/yakıt eklenince liste kendiliğinden güncellenir; hiçbir araç listelerin dışında kalmaz. */
  var SIRA = {
    kategori: ["otomobil", "suv", "ticari", "motosiklet"],
    yakit: ["benzin", "dizel", "lpg", "hibrit", "elektrik"],
    vites: ["otomatik", "yari_otomatik", "manuel"]
  };
  function sayim(alan) {
    var s = {};
    ARACLAR.forEach(function (a) { var v = a[alan]; if (v != null && v !== "") { s[v] = (s[v] || 0) + 1; } });
    return s;
  }
  function secenekEkle(s, deger, yazi) { var o = document.createElement("option"); o.value = deger; o.textContent = yazi; s.appendChild(o); }
  function ilkDisindakileriSil(s) { while (s.options.length > 1) { s.remove(1); } }
  /* en yüksek fiyat seçenekleri: yuvarlak tutarlar, araç fiyatlarına göre; son seçenek her zaman en pahalı aracı da kapsar */
  function fiyatBasamaklari() {
    var f = ARACLAR.map(function (a) { return Number(a.fiyat) || 0; }).filter(function (x) { return x > 0; }).sort(function (a, b) { return a - b; });
    if (!f.length) { return []; }
    var fiyatsiz = ARACLAR.length - f.length;   // fiyatı girilmemiş araçlar her fiyat seçiminde listelenir, adete de eklenir
    var b = [250e3, 500e3, 750e3, 1e6, 1.25e6, 1.5e6, 1.75e6, 2e6, 2.5e6, 3e6, 3.5e6, 4e6, 5e6, 6e6, 7.5e6, 10e6, 12.5e6, 15e6, 20e6, 25e6, 30e6, 40e6, 50e6];
    while (b[b.length - 1] < f[f.length - 1]) { b.push(b[b.length - 1] * 2); }
    var aday = [], onceki = 0;
    for (var i = 0; i < b.length; i++) {
      var n = f.filter(function (x) { return x <= b[i]; }).length;
      if (n > onceki) { aday.push({ tutar: b[i], adet: n + fiyatsiz }); onceki = n; }
      if (n === f.length) { break; }
    }
    if (aday.length > 7) {   // çok seçenek olmasın: 6 ara basamak + tümünü kapsayan son basamak
      var son = aday[aday.length - 1], secilen = [];
      for (var k = 1; k <= 6; k++) { var x = aday[Math.round(k * (aday.length - 1) / 7)]; if (secilen.indexOf(x) < 0) { secilen.push(x); } }
      aday = secilen.filter(function (x) { return x !== son; }).concat([son]);
    }
    return aday;
  }
  function markaSecicileri() {
    var markalar = sayim("marka");
    $$("[data-marka-secici]").forEach(function (s) {
      ilkDisindakileriSil(s);
      Object.keys(markalar).sort(function (a, b) { return a.localeCompare(b, "tr"); }).forEach(function (m) { secenekEkle(s, m, m + " (" + markalar[m] + ")"); });
    });
    ["kategori", "yakit", "vites"].forEach(function (alan) {
      var say = sayim(alan), sira = SIRA[alan];
      var degerler = Object.keys(say).sort(function (a, b) {
        var x = sira.indexOf(a), y = sira.indexOf(b);
        return (x < 0 ? 99 : x) - (y < 0 ? 99 : y) || a.localeCompare(b, "tr");
      });
      $$('[data-secenek="' + alan + '"]').forEach(function (s) {
        ilkDisindakileriSil(s);
        degerler.forEach(function (v) { secenekEkle(s, v, etiket(alan, v) + " (" + say[v] + ")"); });
      });
    });
    var basamak = fiyatBasamaklari();
    $$("[data-fiyat-secici]").forEach(function (s) {
      ilkDisindakileriSil(s);
      basamak.forEach(function (x) { secenekEkle(s, x.tutar, para(x.tutar) + " (" + x.adet + ")"); });
    });
    /* model yılı serbest yazılır (liste yok: yıllar geçince güncelleme gerekmez, eski araçlar da girilebilir); yalnızca rakam kabul edilir */
    $$("[data-yil-alan]").forEach(function (el) {
      el.addEventListener("input", function () { var v = el.value.replace(/\D/g, "").slice(0, 4); if (v !== el.value) { el.value = v; } });
    });
  }

  /* ---------------- genel arayüz */
  function arayuz() {
    var menuBtn = $(".menu-btn");
    if (menuBtn) {
      menuBtn.addEventListener("click", function () {
        var acik = document.body.classList.toggle("menu-acik");
        menuBtn.setAttribute("aria-expanded", acik);
        menuBtn.setAttribute("aria-label", acik ? menuBtn.dataset.kapat : menuBtn.dataset.ac);
      });
    }
    var dil = $("[data-dil]");
    if (dil) {
      var btn = $(".dil-btn", dil);
      btn.addEventListener("click", function (e) { e.stopPropagation(); var a = dil.classList.toggle("acik"); btn.setAttribute("aria-expanded", a); });
      document.addEventListener("click", function () { dil.classList.remove("acik"); btn.setAttribute("aria-expanded", "false"); });
      document.addEventListener("keydown", function (e) { if (e.key === "Escape") { dil.classList.remove("acik"); document.body.classList.remove("menu-acik"); } });
    }
    // dil değiştirirken sorgu (araç id, filtre) korunur ve seçim hatırlanır
    $$("[data-dil-link]").forEach(function (a) {
      a.addEventListener("click", function () {
        try { localStorage.setItem("balkar_dil", a.getAttribute("hreflang")); } catch (e) {}
        a.href = a.getAttribute("href").split("?")[0] + location.search + location.hash;
      });
    });
    $$("[data-wa]").forEach(function (a) { a.href = wa(t("ortak.wa_genel_mesaj")); a.target = "_blank"; a.rel = "noopener"; });
    $$("[data-odeme-wa]").forEach(function (a) { a.href = wa(t("sayfalar.odeme_wa_mesaj")); a.target = "_blank"; a.rel = "noopener"; });
    $$("[data-arac-sayisi]").forEach(function (b) { b.textContent = ARACLAR.length; });

    $$("[data-kaydir]").forEach(function (b) {
      b.addEventListener("click", function () {
        var k = $("[data-kaydirici]", b.closest("section"));
        if (k) { k.scrollBy({ left: Number(b.dataset.kaydir) * (k.clientWidth * 0.85), behavior: "smooth" }); }
      });
    });
  }

  /* ---------------- anasayfa */
  function anasayfa() {
    var v = $("[data-vitrin]");
    if (v) {
      v.innerHTML = sirala(ARACLAR, "yeni").slice(0, Number(v.dataset.vitrin) || 6).map(kartHTML).join("");
    }
    var oc = $("[data-one-cikan]");
    if (oc && ARACLAR.length) {
      var a = ARACLAR.filter(function (x) { return x.one_cikan; })[0] || sirala(ARACLAR, "yeni")[0];
      /* köşe yazısı panelden seçilir: yeni | one_cikan | ozel (panelde yazılan, 4 dil) | "" (yazı yok); alan yoksa "Yeni eklendi" */
      var tur = a.one_cikan_etiket == null ? "yeni" : a.one_cikan_etiket;
      var etiket = tur === "yeni" ? t("arac.yeni") : tur === "one_cikan" ? t("arac.one_cikan") : tur === "ozel" ? cokDilli(a.one_cikan_ozel) : "";
      oc.innerHTML = '<a class="one-cikan" href="' + aracLink(a) + '"><div class="foto">' + fotoHTML(a, true) + "</div>" +
        (etiket ? '<span class="one-cikan-etiket">' + kacis(etiket) + "</span>" : "") +
        '<div class="one-cikan-bilgi"><div><h2>' + kacis(aracAdi(a)) + "</h2><p>" + kacis([a.yil, a.model, a.km != null ? sayi(a.km) + " km" : ""].filter(Boolean).join(", ")) + "</p></div>" +
        (a.fiyat ? '<span class="one-cikan-fiyat">' + para(a.fiyat) + "</span>" : "") + "</div></a>";
    }
  }

  /* ---------------- araç listesi */
  function liste() {
    var kutu = $("[data-liste]");
    if (!kutu) { return; }
    var form = $("[data-filtre-form]"), sira = $("[data-sira]"), filtre = $("#filtre");
    var p = new URLSearchParams(location.search);
    ["q", "kategori", "marka", "yakit", "vites", "yil", "fiyat", "km"].forEach(function (k) { if (p.get(k) && form.elements[k]) { form.elements[k].value = p.get(k); } });
    if (p.get("sira")) { sira.value = p.get("sira"); }

    function uygula() {
      var f = {};
      ["q", "kategori", "marka", "yakit", "vites", "yil", "fiyat", "km"].forEach(function (k) { f[k] = (form.elements[k].value || "").trim(); });
      var q = anahtar(f.q);
      var sonuc = ARACLAR.filter(function (a) {
        if (f.kategori && a.kategori !== f.kategori) { return false; }
        if (f.marka && a.marka !== f.marka) { return false; }
        if (f.yakit && a.yakit !== f.yakit) { return false; }
        if (f.vites && a.vites !== f.vites) { return false; }
        if (f.yil.length === 4 && (a.yil || 0) < Number(f.yil)) { return false; }
        if (f.fiyat && (a.fiyat || 0) > Number(f.fiyat)) { return false; }
        if (f.km && (a.km || 0) > Number(f.km)) { return false; }
        if (q) {
          var metin = anahtar([a.marka, a.seri, a.model, a.yil, a.renk, a.baslik].join(" "));
          if (q.split("_").some(function (parca) { return metin.indexOf(parca) < 0; })) { return false; }
        }
        return true;
      });
      sonuc = sirala(sonuc, sira.value);
      kutu.innerHTML = sonuc.map(kartHTML).join("");
      $("[data-sonuc-sayi]").textContent = t("arac.sonuc_sayisi", { n: sonuc.length });
      $("[data-bos]").hidden = sonuc.length > 0;
      var aktif = Object.keys(f).filter(function (k) { return f[k]; }).length;
      var rozet = $("[data-filtre-sayi]"); rozet.hidden = !aktif; rozet.textContent = aktif;
      var u = new URLSearchParams();
      Object.keys(f).forEach(function (k) { if (f[k]) { u.set(k, f[k]); } });
      if (sira.value !== "yeni") { u.set("sira", sira.value); }
      history.replaceState(null, "", location.pathname + (u.toString() ? "?" + u : ""));
      var wa2 = $("[data-wa-aradigim]");
      if (wa2) {
        var istek = Object.keys(f).filter(function (k) { return f[k]; }).map(function (k) {
          var el = form.elements[k]; var et = el.closest("label").querySelector("span").textContent;
          var deger = el.tagName === "SELECT" ? el.options[el.selectedIndex].text : el.value;
          return et + ": " + deger;
        }).join(", ");
        wa2.href = wa(t("arac.wa_aradigim_mesaj") + istek); wa2.target = "_blank"; wa2.rel = "noopener";
      }
      /* ön sipariş bağlantısı: seçili filtreler forma taşınır (Aşama 39) */
      var sp = new URLSearchParams();
      ["marka", "yakit", "vites", "yil", "fiyat", "km", "kategori"].forEach(function (k) { if (f[k]) { sp.set(k, f[k]); } });
      $$("[data-siparis-link]").forEach(function (l) { l.href = "on-siparis.html" + (sp.toString() ? "?" + sp : ""); });
    }
    form.addEventListener("input", uygula);
    form.addEventListener("change", uygula);
    sira.addEventListener("change", uygula);
    form.addEventListener("submit", function (e) { e.preventDefault(); });
    $$("[data-filtre-temizle]").forEach(function (b) { b.addEventListener("click", function (e) { e.preventDefault(); form.reset(); uygula(); }); });
    $$("[data-filtre-ac]").forEach(function (b) { b.addEventListener("click", function () { filtre.classList.add("acik"); document.body.style.overflow = "hidden"; var i = $("input,select", filtre); if (i) { i.focus(); } }); });
    $$("[data-filtre-kapat]").forEach(function (b) { b.addEventListener("click", function () { filtre.classList.remove("acik"); document.body.style.overflow = ""; }); });
    if (VERI.guncelleme) {
      var d = new Date(VERI.guncelleme);
      if (!isNaN(d)) { $("[data-guncelleme]").textContent = t("arac.son_guncelleme") + ": " + d.toLocaleDateString(YEREL, { day: "numeric", month: "long", year: "numeric" }) + "."; }
    }
    uygula();
  }

  /* ---------------- araç detay: arama motorları için yapılandırılmış veri (Car + teklif) ve sayfa açıklaması */
  function aracYapisalVeri(a) {
    var ac = t("arac.paylasim_aciklama", { yil: a.yil, km: sayi(a.km || 0), yakit: a.yakit ? etiket("yakit", a.yakit) : "", vites: a.vites ? etiket("vites", a.vites) : "" });
    var md = $('meta[name="description"]'); if (md) { md.setAttribute("content", ac); }
    if ($("script[data-arac-ld]")) { return; }   // sunucu (arac.php) zaten eklediyse tekrar ekleme
    var yakit = { benzin: "Gasoline", dizel: "Diesel", hibrit: "Hybrid", elektrik: "Electric", lpg: "LPG" };
    var veri = { "@context": "https://schema.org", "@type": a.kategori === "motosiklet" ? "Motorcycle" : "Car",
      name: aracTamAd(a), brand: { "@type": "Brand", name: a.marka }, model: [seriAdi(a), a.model].filter(Boolean).join(" "),
      vehicleModelDate: String(a.yil), mileageFromOdometer: { "@type": "QuantitativeValue", value: a.km || 0, unitCode: "KMT" },
      fuelType: yakit[a.yakit] || a.yakit, vehicleTransmission: a.vites ? etiket("vites", a.vites) : undefined, color: a.renk || undefined,
      itemCondition: "https://schema.org/UsedCondition", description: ac, url: location.href.split("#")[0],
      image: (a.fotograflar || []).slice(0, 5).map(function (u) { return u.replace("/x5_", "/x16_"); }),
      offers: { "@type": "Offer", price: a.fiyat, priceCurrency: "TRY", availability: "https://schema.org/InStock", itemCondition: "https://schema.org/UsedCondition",
        seller: { "@type": "AutoDealer", name: "Balkar Otomotiv", telephone: AYAR.telefon } } };
    var s = document.createElement("script"); s.type = "application/ld+json"; s.setAttribute("data-arac-ld", "");
    s.textContent = JSON.stringify(veri); document.head.appendChild(s);
  }

  /* ---------------- araç detay */
  function detay() {
    var kok = $("[data-detay]");
    if (!kok) { return; }
    var id = new URLSearchParams(location.search).get("id");
    var a = ARACLAR.filter(function (x) { return String(x.id) === String(id); })[0];
    if (!a) { $("[data-bulunamadi]").hidden = false; return; }
    kok.hidden = false;
    document.title = aracTamAd(a) + (a.fiyat ? " – " + para(a.fiyat) : "") + " | Balkar Otomotiv";
    aracYapisalVeri(a);
    function alan(ad, html) { var e = $('[data-alan="' + ad + '"]', kok); if (e) { e.innerHTML = html; } }
    alan("kategori", kacis(etiket("kategori", a.kategori)));
    alan("baslik", kacis(a.yil + " " + aracAdi(a)));
    alan("model", kacis(a.model || ""));
    alan("fiyat", a.fiyat ? para(a.fiyat) : "");
    var hizli = [];
    if (a.km != null) { hizli.push(["gosterge", t("arac.km"), sayi(a.km) + " km"]); }
    if (a.yakit) { hizli.push(["yakit", t("arac.yakit"), etiket("yakit", a.yakit)]); }
    if (a.vites) { hizli.push(["vites", t("arac.vites"), etiket("vites", a.vites)]); }
    hizli.push(["takvim", t("arac.yil"), a.yil]);
    alan("hizli", hizli.slice(0, 4).map(function (h) { return "<li>" + ikon(h[0]) + "<span><small>" + kacis(h[1]) + "</small>" + kacis(h[2]) + "</span></li>"; }).join(""));

    var oz = [
      [t("arac.marka"), a.marka], [t("arac.seri_model"), [seriAdi(a), a.model].filter(Boolean).join(" ")],
      [t("arac.yil"), a.yil], [t("arac.km"), a.km != null ? sayi(a.km) + " km" : ""],
      [t("arac.yakit"), a.yakit ? etiket("yakit", a.yakit) : ""], [t("arac.vites"), a.vites ? etiket("vites", a.vites) : ""],
      [t("arac.kasa"), a.kasa ? ceviriVeyaHam("kasa", a.kasa) : ""], [t("arac.renk"), ceviriVeyaHam("renk", a.renk)],
      [t("arac.motor_gucu"), a.motor_gucu], [t("arac.motor_hacmi"), a.motor_hacmi],
      [t("arac.cekis"), a.cekis ? ceviriVeyaHam("cekis", a.cekis) : ""], [t("arac.kategori"), etiket("kategori", a.kategori)],
      [t("arac.servis_garantisi"), a.servis_garantisi === true ? t("arac.evet") : (a.servis_garantisi === false ? t("arac.hayir") : "")],
      [t("arac.ilan_no"), a.id], [t("arac.ilan_tarihi"), a.ilan_tarihi ? new Date(a.ilan_tarihi).toLocaleDateString(YEREL) : ""]
    ].filter(function (r) { return r[1] !== "" && r[1] != null; });
    alan("ozellikler", oz.map(function (r) { return "<div><dt>" + kacis(r[0]) + "</dt><dd>" + kacis(r[1]) + "</dd></div>"; }).join(""));

    if (a.hasar) {
      var h = a.hasar, yok = t("arac.yok");
      var tr = h.agir_hasar && h.tramer_tl == null ? t("arac.tramer_agir") : (h.tramer_tl ? para(h.tramer_tl) : (h.tramer_tl === 0 ? t("arac.tramer_kayitsiz") : ""));
      var satir = [
        [t("arac.degisen"), cokDilli(h.degisen) || yok, !h.degisen],
        [t("arac.boya"), cokDilli(h.boya) || yok, !h.boya],
        [t("arac.lokal"), cokDilli(h.lokal) || yok, !h.lokal],
        [t("arac.tramer"), tr, h.tramer_tl === 0],
        [t("arac.agir_hasar"), h.agir_hasar ? t("arac.evet") : t("arac.hayir"), !h.agir_hasar, h.agir_hasar]
      ].filter(function (r) { return r[1]; });
      $("[data-hasar-bolum]").hidden = false;
      alan("hasar", satir.map(function (r) { return '<div class="' + (r[3] ? "uyari" : (r[2] ? "temiz" : "")) + '"><dt>' + kacis(r[0]) + "</dt><dd>" + kacis(r[1]) + "</dd></div>"; }).join(""));
    }

    var ac = a.aciklama || {};
    var metin = ac[DIL] || ac.tr || "";
    var basl = a.baslik ? (DIL === "tr" ? a.baslik : t("arac.ilan_basligi") + ": " + a.baslik) : "";
    if (metin || basl) {
      $("[data-aciklama-bolum]").hidden = false;
      $("[data-aciklama-not]").hidden = !(DIL !== "tr" && !ac[DIL]);
      alan("aciklama", (basl ? "<p><b>" + kacis(basl) + "</b></p>" : "") + (metin ? "<p>" + kacis(metin) + "</p>" : ""));
    }

    var mesaj = t("arac.wa_arac_mesaj", { arac: aracTamAd(a), id: a.id });
    var w = $("[data-wa-arac]"); w.href = wa(mesaj); w.target = "_blank"; w.rel = "noopener";
    $$("[data-wa]").forEach(function (x) { x.href = wa(mesaj); });
    $("[data-randevu-link]").href = "iletisim.html?arac=" + encodeURIComponent(aracTamAd(a) + " (" + a.id + ")") + "#randevu";
    $("[data-kredi-link]").href = "kredi.html?fiyat=" + (a.fiyat || "") + "&arac=" + encodeURIComponent(aracTamAd(a));
    var sb = $("[data-sahibinden-link]");
    if (a.sahibinden_url) { sb.href = a.sahibinden_url; } else { sb.hidden = true; }

    // galeri
    var foto = (a.fotograflar || []).filter(Boolean), i = 0;
    var buyuk = $("[data-galeri-buyuk]"), sayac = $("[data-galeri-sayac]"), kucuk = $("[data-galeri-kucuk]");
    buyuk.alt = aracTamAd(a); buyuk.referrerPolicy = "no-referrer";
    buyuk.onerror = function () { if (buyuk.src.indexOf("/x16_") > -1) { buyuk.src = foto[i]; } else { buyuk.style.visibility = "hidden"; } };
    function goster(n) {
      if (!foto.length) { buyuk.style.visibility = "hidden"; return; }
      i = (n + foto.length) % foto.length; buyuk.style.visibility = ""; buyuk.src = buyukFoto(foto[i]);
      var sonraki = new Image(); sonraki.referrerPolicy = "no-referrer"; sonraki.src = buyukFoto(foto[(i + 1) % foto.length]);
      sayac.textContent = (i + 1) + " / " + foto.length;
      $$("button", kucuk).forEach(function (b, j) { b.setAttribute("aria-current", j === i ? "true" : "false"); });
    }
    if (foto.length > 1) {
      kucuk.innerHTML = foto.map(function (f, j) { return '<button type="button" aria-label="' + kacis(t("arac.foto_sonraki")) + " " + (j + 1) + '"><img src="' + kacis(f) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></button>'; }).join("");
      $$("button", kucuk).forEach(function (b, j) { b.addEventListener("click", function () { goster(j); }); });
    } else {
      $$(".galeri-ok").forEach(function (b) { b.hidden = true; }); sayac.hidden = true;
    }
    $$("[data-galeri-git]").forEach(function (b) { b.addEventListener("click", function () { goster(i + Number(b.dataset.galeriGit)); }); });
    var x0 = null, ana = $(".galeri-ana");
    ana.addEventListener("touchstart", function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    ana.addEventListener("touchend", function (e) { if (x0 == null) { return; } var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) { goster(i + (dx < 0 ? 1 : -1)); } x0 = null; });
    document.addEventListener("keydown", function (e) { if (e.key === "ArrowRight") { goster(i + 1); } if (e.key === "ArrowLeft") { goster(i - 1); } });
    goster(0);

    var benzer = ARACLAR.filter(function (x) { return x.id !== a.id; })
      .sort(function (x, y) {   // aynı kategori önce, sonra fiyatı en yakın olan (fiyatı girilmemiş araçta sıralama bozulmaz)
        function uzak(z) { return (z.kategori === a.kategori ? 0 : 1e12) + Math.abs((Number(z.fiyat) || 0) - (Number(a.fiyat) || 0)); }
        return uzak(x) - uzak(y);
      })
      .slice(0, 3);
    if (benzer.length) { $("[data-benzer-bolum]").hidden = false; $("[data-benzer]").innerHTML = benzer.map(kartHTML).join(""); }
  }

  /* ---------------- kredi */
  function kredi() {
    var k = $("[data-kredi]");
    if (!k) { return; }
    var f = $("form", k);
    function oku(el) { return Number(String(el.value).replace(/[^\d]/g, "")) || 0; }
    function yaz(el, n) { el.value = n ? sayi(n) : ""; }
    var p = new URLSearchParams(location.search);
    if (p.get("fiyat")) { var fy = Number(p.get("fiyat")); yaz(f.fiyat, fy); yaz(f.pesinat, Math.round(fy * 0.4 / 1000) * 1000); }
    if (p.get("arac")) { var ka = $("[data-kredi-arac]"); ka.hidden = false; ka.innerHTML = kacis(t("sayfalar.kredi_arac_secili")) + ": <b>" + kacis(p.get("arac")) + "</b>"; }

    function hesapla() {
      var fiyat = oku(f.fiyat), pes = Math.min(oku(f.pesinat), fiyat);
      var vade = Number((f.querySelector("[name=vade]:checked") || {}).value || 12);
      var r = (Number(String(f.faiz.value).replace(",", ".")) || 0) / 100;
      var tutar = Math.max(fiyat - pes, 0);
      var aylik = r > 0 ? tutar * r / (1 - Math.pow(1 + r, -vade)) : tutar / vade;
      var toplam = aylik * vade;
      $('[data-sonuc="aylik"]').textContent = tutar ? para(aylik) : "–";
      $('[data-sonuc="tutar"]').textContent = para(tutar);
      $('[data-sonuc="faiz"]').textContent = para(toplam - tutar);
      $('[data-sonuc="toplam"]').textContent = para(toplam + pes);
      $("[data-pesinat-yuzde]").textContent = fiyat ? "%" + Math.round(pes / fiyat * 100) : "";
      if (fiyat) { f.pesinat_kaydir.value = Math.round(pes / fiyat * 100); }
      var w = $("[data-kredi-wa]");
      w.href = wa(t("sayfalar.kredi_wa_mesaj", { fiyat: para(fiyat), pesinat: para(pes), vade: vade }) + (p.get("arac") ? " (" + p.get("arac") + ")" : ""));
      w.target = "_blank"; w.rel = "noopener";
    }
    $$("[data-para]", f).forEach(function (el) { el.addEventListener("input", function () { var n = oku(el); yaz(el, n); hesapla(); }); });
    f.pesinat_kaydir.addEventListener("input", function () { yaz(f.pesinat, Math.round(oku(f.fiyat) * f.pesinat_kaydir.value / 100 / 1000) * 1000); hesapla(); });
    f.addEventListener("change", hesapla); f.faiz.addEventListener("input", hesapla);
    hesapla();
  }

  /* ---------------- WhatsApp formları */
  /* ---------- Aracını sat: isteğe bağlı fotoğraflar (en fazla data-maks, tarayıcıda 1600 px'e küçültülür,
     sunucuya yüklenir; WhatsApp mesajına tek galeri bağlantısı eklenir). Yükleme olmazsa mesaj yine gider. */
  var MAKS_HAM = 25 * 1024 * 1024;           // tarayıcıda işlenecek en büyük ham dosya
  function fotoKutusu(form) {
    var kutu = $("[data-foto-yukle]", form);
    if (!kutu) { return; }
    var maks = Number(kutu.dataset.maks) || 8, sec = $("[data-foto-sec]", kutu), liste = $("[data-foto-liste]", kutu),
      sayac = $("[data-foto-sayac]", kutu), uyari = $("[data-foto-uyari]", kutu);
    form._fotolar = [];
    function ciz() {
      liste.innerHTML = "";
      form._fotolar.forEach(function (f, i) {
        var d = document.createElement("div"); d.className = "foto-oge";
        var im = document.createElement("img"); im.alt = ""; im.src = f.onizleme; d.appendChild(im);
        var b = document.createElement("button"); b.type = "button"; b.className = "foto-sil"; b.setAttribute("aria-label", t("sayfalar.sat_foto_kaldir")); b.textContent = "×";
        b.addEventListener("click", function () { URL.revokeObjectURL(f.onizleme); form._fotolar.splice(i, 1); uyari.hidden = true; ciz(); });
        d.appendChild(b); liste.appendChild(d);
      });
      sayac.textContent = form._fotolar.length ? t("sayfalar.sat_foto_sayac", { adet: form._fotolar.length, n: maks }) : "";
      $(".foto-ekle", kutu).classList.toggle("pasif", form._fotolar.length >= maks);
    }
    form._fotoCiz = ciz;
    sec.addEventListener("change", function () {
      var fazla = false;
      Array.prototype.forEach.call(sec.files, function (f) {
        if (!/^image\//.test(f.type) || f.size > MAKS_HAM) { return; }
        if (form._fotolar.length >= maks) { fazla = true; return; }
        form._fotolar.push({ dosya: f, onizleme: URL.createObjectURL(f) });
      });
      sec.value = "";
      uyari.hidden = !fazla; if (fazla) { uyari.textContent = t("sayfalar.sat_foto_sinir", { n: maks }); }
      ciz();
    });
    ciz();
  }
  function kucult(f) {
    return new Promise(function (tamam) {
      var img = new Image(), u = URL.createObjectURL(f);
      img.onload = function () {
        var o = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement("canvas"); c.width = Math.round(img.naturalWidth * o); c.height = Math.round(img.naturalHeight * o);
        var x = c.getContext("2d"); x.fillStyle = "#fff"; x.fillRect(0, 0, c.width, c.height); x.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(u);
        c.toBlob(function (b) { tamam(b || f); }, "image/jpeg", 0.82);
      };
      img.onerror = function () { URL.revokeObjectURL(u); tamam(f); };
      img.src = u;
    });
  }
  function gonder(form, satirlar) {
    var fotolar = form._fotolar || [];
    if (!fotolar.length) { window.open(wa(satirlar.join("\n")), "_blank", "noopener"); return; }
    var kutu = $("[data-foto-yukle]", form), uyari = $("[data-foto-uyari]", kutu), dugme = $('button[type="submit"]', form);
    /* pencere tıklama anında açılır (sonradan açılırsa tarayıcı engeller), yükleme bitince WhatsApp'a yönlendirilir */
    var pencere = window.open("", "_blank");
    if (pencere) { try { pencere.opener = null; pencere.document.title = "WhatsApp"; pencere.document.body.innerHTML = '<p style="font:16px system-ui;padding:24px">' + kacis(t("sayfalar.sat_foto_yukleniyor")) + "</p>"; } catch (e) { /* farklı köken */ } }
    var eskiYazi = dugme.innerHTML; dugme.disabled = true; dugme.textContent = t("sayfalar.sat_foto_yukleniyor"); uyari.hidden = true;
    function bitir(link, hata) {
      if (link) { satirlar.push(t("sayfalar.sat_foto_wa") + " (" + fotolar.length + "): " + link); }
      if (hata) { uyari.textContent = t(hata); uyari.hidden = false; }
      dugme.disabled = false; dugme.innerHTML = eskiYazi;
      var url = wa(satirlar.join("\n"));
      if (pencere && !pencere.closed) { pencere.location.href = url; } else { location.href = url; }
    }
    Promise.all(fotolar.map(function (f) { return kucult(f.dosya); })).then(function (bloblar) {
      var fd = new FormData();
      bloblar.forEach(function (b, i) { fd.append("foto[]", b, "foto" + (i + 1) + ".jpg"); });
      fd.append("web_adresi", ($('[name="web_adresi"]', form) || {}).value || "");
      var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, 60000);
      return fetch(kutu.dataset.uc, { method: "POST", body: fd, signal: iptal ? iptal.signal : undefined }).then(function (r) {
        clearTimeout(sure);
        return r.json().catch(function () { return { ok: false }; }).then(function (j) { j.durum = r.status; return j; });
      });
    }).then(function (j) {
      if (j && j.ok && j.link) { bitir(j.link); } else { bitir(null, j && j.durum === 429 ? "sayfalar.sat_foto_cok" : "sayfalar.sat_foto_hata"); }
    }).catch(function () { bitir(null, "sayfalar.sat_foto_hata"); });
  }

  /* ---------- Bilgisayarda "Ara" / telefon numarası: tarayıcı arama yapamadığı için numarayı büyük gösteren pencere.
     Dokunmatik telefonlarda tel: bağlantısı normal çalışır (doğrudan arama ekranı açılır). */
  function aramaPenceresi() {
    if (!window.matchMedia || !window.matchMedia("(hover: hover) and (pointer: fine)").matches) { return; }
    var d = null;
    function kur() {
      d = document.createElement("dialog"); d.className = "ara-pencere"; d.setAttribute("aria-labelledby", "ara-baslik");
      d.innerHTML = '<button type="button" class="ara-kapat" aria-label="' + kacis(t("ortak.kapat")) + '">×</button>' +
        '<div class="ara-ikon">' + ikon("tel") + "</div>" +
        '<h2 id="ara-baslik">' + kacis(t("ortak.ara_baslik")) + "</h2>" +
        '<p class="ara-numara" data-ara-numara></p>' +
        '<p class="ara-not">' + kacis(t("ortak.ara_not")) + "</p>" +
        '<p class="ara-saat">' + ikon("saat") + kacis(t("ortak.saat_hafta") + " " + AYAR.saat + " · " + t("ortak.saat_pazar") + ": " + t("ortak.kapali")) + "</p>" +
        '<div class="ara-dugmeler"><button type="button" class="dugme dugme-cizgi" data-ara-kopyala>' + ikon("belge") + "<span>" + kacis(t("ortak.ara_kopyala")) + "</span></button>" +
        '<a class="dugme dugme-altin" target="_blank" rel="noopener" href="' + kacis(wa(t("ortak.wa_genel_mesaj"))) + '">' + ikon("mesaj") + "WhatsApp</a></div>";
      document.body.appendChild(d);
      $(".ara-kapat", d).addEventListener("click", function () { d.close(); });
      d.addEventListener("click", function (e) { if (e.target === d) { d.close(); } });   /* dışına tıklayınca kapanır */
      $("[data-ara-kopyala]", d).addEventListener("click", function () {
        var b = this, yazi = $("span", b), numara = d.dataset.numara;
        function oldu() { yazi.textContent = t("ortak.ara_kopyalandi"); setTimeout(function () { yazi.textContent = t("ortak.ara_kopyala"); }, 2000); }
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(numara).then(oldu, oldu); }
        else { var g = document.createElement("textarea"); g.value = numara; document.body.appendChild(g); g.select(); try { document.execCommand("copy"); } catch (e) {} g.remove(); oldu(); }
      });
    }
    document.addEventListener("click", function (e) {
      var a = e.target.closest && e.target.closest('a[href^="tel:"]');
      if (!a) { return; }
      e.preventDefault();
      if (!d) { kur(); }
      var ham = a.getAttribute("href").slice(4), gorunen = ham === AYAR.telefon ? AYAR.telefon_gorunen : ham.replace(/^\+90/, "0");
      d.dataset.numara = gorunen; $("[data-ara-numara]", d).textContent = gorunen;
      if (d.showModal) { d.showModal(); } else { d.setAttribute("open", ""); }
    });
  }

  function formlar() {
    $$("[data-wa-form]").forEach(function (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var tamam = true;
        $$("[required]", form).forEach(function (el) {
          var bos = el.type === "checkbox" ? !el.checked : !String(el.value).trim();
          var kap = el.closest(".alan, .onay"); if (kap) { kap.classList.toggle("hatali", bos); }
          if (bos && tamam) { el.focus(); }
          if (bos) { tamam = false; }
        });
        var hataYazi = t("ortak.form_hata");
        $$("[data-yil-alan]", form).forEach(function (el) {   // dolu ise geçerli bir yıl olmalı (1900 – gelecek yıl)
          if (!el.value || yilGecerli(el.value)) { return; }
          var kap = el.closest(".alan"); if (kap) { kap.classList.add("hatali"); }
          if (tamam) { el.focus(); hataYazi = t("ortak.yil_hata"); }
          tamam = false;
        });
        var hata = $("[data-form-hata]", form); hata.textContent = hataYazi; hata.hidden = tamam;
        if (!tamam) { return; }
        var satirlar = ["*" + form.dataset.baslik + "*"];
        $$("[data-etiket]", form).forEach(function (el) {
          if ((el.type === "radio" || el.type === "checkbox") && !el.checked) { return; }
          var v = el.tagName === "SELECT" ? (el.value ? el.options[el.selectedIndex].text : "") : String(el.value).trim();
          if (v) { satirlar.push(el.dataset.etiket + ": " + v); }
        });
        var alanlar = {};
        $$("[name]", form).forEach(function (el) {
          if (!/^[a-z_]{1,30}$/.test(el.name) || el.name === "web_adresi" || el.type === "file") { return; }
          if ((el.type === "radio" || el.type === "checkbox") && !el.checked) { return; }
          var v = el.tagName === "SELECT" ? (el.value ? el.options[el.selectedIndex].text : "") : String(el.value).trim();
          if (v) { alanlar[el.name] = v; }
        });
        talepKaydet(form.id === "randevu" ? "randevu" : "genel", satirlar.join("\n"), alanlar, ($('[name="web_adresi"]', form) || {}).value);
        gonder(form, satirlar);
      });
      fotoKutusu(form);
      $$("input,select,textarea", form).forEach(function (el) {
        el.addEventListener("input", function () { var k = el.closest(".alan, .onay"); if (k) { k.classList.remove("hatali"); } });
        el.addEventListener("change", function () { var k = el.closest(".alan, .onay"); if (k) { k.classList.remove("hatali"); } });
      });
    });
    var arac = $("[data-arac-doldur]"), p = new URLSearchParams(location.search);
    if (arac && p.get("arac")) { arac.value = p.get("arac"); }
    /* hizmetler sayfasından gelen seçim: aracini-sat.html?istek=sat | takas | fiyat */
    if (p.get("istek")) {
      var secim = $('input[name="istek"][data-istek="' + p.get("istek").replace(/[^a-z]/g, "") + '"]');
      if (secim) { secim.checked = true; secim.dispatchEvent(new Event("change", { bubbles: true })); }
    }
    var tarih = $("[data-tarih-sinir]");
    if (tarih) {
      var d = new Date(); tarih.min = d.toISOString().slice(0, 10);
      tarih.addEventListener("change", function () {
        var pazar = tarih.value && new Date(tarih.value + "T12:00:00").getDay() === 0;
        $("[data-pazar-uyari]").hidden = !pazar;
        if (pazar) { tarih.value = ""; }
      });
    }
  }

  /* ---------- Aracını sat: 5 adımlı teklif formu (Aşama 34)
     Marka → Yıl → Model → Şanzıman → Yakıt → Motor hacmi listeleri veri/katalog.json'dan süzülür.
     Her listede "Listede yok, elle yaz" vardır; elle yazılınca sonraki listeler genel seçeneklerle devam eder.
     Katalog yüklenemezse ya da bozuksa form uyarı verip elle doldurma ile çalışır (çökme yok).
     Gönderince her şey tek WhatsApp mesajında (teklif no ile) hazırlanır; sunucu kaydı / fotoğraf yüklemesi
     başarısız olsa da mesaj yine açılır. */
  var ELLE = "__elle";
  var GENEL = {
    kasa: ["hatchback", "sedan", "station", "suv", "crossover", "coupe", "cabrio", "roadster", "mpv", "minivan", "pickup", "panelvan", "kombi", "kombivan", "kamyonet", "minibus"],
    vites: ["manuel", "otomatik", "yari_otomatik"],
    yakit: ["benzin", "dizel", "lpg", "hibrit", "elektrik"],
    hacim: ["0.8", "0.9", "1.0", "1.1", "1.2", "1.3", "1.4", "1.5", "1.6", "1.7", "1.8", "1.9", "2.0", "2.1", "2.2", "2.3", "2.4", "2.5",
      "2.7", "2.8", "2.9", "3.0", "3.2", "3.5", "4.0", "4.4", "5.0"]
  };
  /* Aşama 38: sıra Marka → Yıl → Model → Versiyon → Kasa → Şanzıman → Yakıt → Motor hacmi */
  var ZINCIR = ["marka", "yil", "model", "versiyon", "kasa", "vites", "yakit", "motor"];
  var PARCALAR = ["kaput", "tavan", "bagaj", "on_tampon", "arka_tampon", "sol_on_camurluk", "sag_on_camurluk", "sol_on_kapi", "sag_on_kapi",
    "sol_arka_kapi", "sag_arka_kapi", "sol_arka_camurluk", "sag_arka_camurluk"];

  /* katalog dizini (veri/katalog/index.json): marka → model → yıl aralıkları; versiyonlar marka dosyasında (veri/katalog/<d>.json) */
  function katalogGecerli(k) {
    return k && Array.isArray(k.markalar) && k.markalar.length > 0 && k.markalar.every(function (m) {
      return m && typeof m.ad === "string" && Array.isArray(m.modeller);
    });
  }
  function aralikta(r, y) {
    var buYil = new Date().getFullYear();
    return Array.isArray(r) && r[0] <= y && (r[1] == null ? buYil + 1 : r[1]) >= y;
  }
  function tekil(dizi) { return dizi.filter(function (v, i) { return dizi.indexOf(v) === i; }); }
  function rakam(s) { return String(s || "").replace(/\D/g, ""); }
  function kasaYazi(k) { var s = T["arac.kasa_" + k]; return s == null ? k : s; }
  function noUret() {
    var d = new Date(), harf = "ABCDEFGHJKLMNPRSTUVYZ23456789", s = "";
    for (var i = 0; i < 4; i++) { s += harf.charAt(Math.floor(Math.random() * harf.length)); }
    return String(d.getFullYear()).slice(2) + ("0" + (d.getMonth() + 1)).slice(-2) + ("0" + d.getDate()).slice(-2) + "-" + s;
  }

  function teklifFormu() {
    var form = $("[data-teklif-form]");
    if (!form) { return; }
    var KATALOG = null, MARKA_VERI = {}, adim = 1, TOPLAM = 5, gonderildi = false;
    var klasor = (form.dataset.katalog || "").replace(/[^\/]*$/, "");
    var durumYazi = $("[data-katalog-durum]", form), hataYazi = $("[data-form-hata]", form);
    var S = {}, E = {};
    ZINCIR.forEach(function (a) { S[a] = form.elements[a]; E[a] = form.elements[a + "_elle"]; });
    fotoKutusu(form);

    /* ---- katalog sorguları (her biri hata yakalayıcılı: veri ne olursa olsun form çalışır) */
    function marka() { return KATALOG && S.marka.value && S.marka.value !== ELLE ? KATALOG.markalar[Number(S.marka.value)] : null; }
    function yilDeger() { var v = S.yil.value === ELLE ? E.yil.value : S.yil.value; return yilGecerli(v) ? Number(v) : null; }
    function model() { var m = marka(), y = yilDeger(); return m && y && S.model.value && S.model.value !== ELLE ? (m.modeller[Number(S.model.value)] || null) : null; }
    function markaVeri() { var m = marka(); return m ? MARKA_VERI[m.d] : null; }   // undefined: yükleniyor, false: yüklenemedi
    /* seçili modelin o yıl geçerli versiyon satırları; null = bilgi yok (genel listeler) */
    function satirlar() {
      var md = model(), mv = markaVeri(), y = yilDeger();
      if (!md || !mv || !mv.modeller) { return null; }
      var veri = mv.modeller[Number(S.model.value)];
      if (!veri || veri.ad !== md.ad) { veri = mv.modeller.filter(function (x) { return x.ad === md.ad; })[0]; }
      if (!veri) { return null; }
      var l = (veri.motorlar || []).filter(function (x) { return x && aralikta(x.yil, y); });
      if (!l.length) { l = (veri.motorlar || []).filter(Boolean); }   // yalnız model yılıyla listelenen yıl: modelin tüm versiyonları
      return l.length ? l : null;
    }
    /* "alan" seviyesine kadar (o hariç) yapılan seçimlere uyan satırlar */
    function suzulmus(alan) {
      var l = satirlar(); if (!l) { return null; }
      var sira = ["versiyon", "kasa", "vites", "yakit"], son = sira.indexOf(alan);
      for (var i = 0; i < (son < 0 ? sira.length : son); i++) {
        var a = sira[i], v = S[a].value;
        if (!v || v === ELLE) { if (a === "versiyon" && v === ELLE) { continue; } continue; }
        l = l.filter(function (x) {
          if (a === "versiyon") { return x.ad === v; }
          if (a === "kasa") { return !x.kasa || x.kasa === v; }
          if (a === "yakit") { return v === "lpg" ? (x.yakit === "benzin" || x.yakit === "lpg") : x.yakit === v; }
          return x[a] === v;
        });
      }
      return l;
    }
    function hacimYazi(x) { return x.hacim ? x.hacim : (x.yakit === "elektrik" ? t("sayfalar.teklif_elektrik") : ""); }

    /* ---- bir listenin seçeneklerini hesapla: [[değer, yazı], ...]; null = üst seçim yok */
    function secenekler(alan) {
      var buYil = new Date().getFullYear(), m, l;
      if (alan === "marka") {
        return KATALOG ? KATALOG.markalar.map(function (x, i) { return [String(i), x.ad]; })
          .sort(function (a, b) { return a[1].localeCompare(b[1], "tr"); }) : [];
      }
      if (alan === "yil") {
        if (!S.marka.value) { return null; }
        m = marka();
        var yillar = [];
        if (m) {
          m.modeller.forEach(function (md) {
            (md.y || []).forEach(function (r) { for (var z = r[0]; z <= Math.min(r[1] == null ? buYil : r[1], buYil); z++) { yillar.push(z); } });
          });
        } else { for (var g = buYil; g >= 1980; g--) { yillar.push(g); } }
        return tekil(yillar).sort(function (a, b) { return b - a; }).map(function (y) { return [String(y), String(y)]; });
      }
      if (alan === "model") {
        if (!S.marka.value || !yilDeger()) { return null; }
        m = marka();
        if (!m) { return []; }
        var y = yilDeger();
        return m.modeller.map(function (md, i) { return [String(i), md.ad, md]; })
          .filter(function (r) { return (r[2].y || []).some(function (a) { return aralikta(a, y); }); })
          .map(function (r) { return [r[0], r[1]]; });
      }
      if (alan === "versiyon") {
        if (!S.model.value) { return null; }
        if (model() && markaVeri() === undefined) { return "yukleniyor"; }
        l = satirlar();
        if (!l) { return []; }
        return tekil(l.map(function (x) { return x.ad; })).sort(function (a, b) { return a.localeCompare(b, "tr", { numeric: true }); })
          .map(function (v) { return [v, v]; });
      }
      if (alan === "kasa") {
        if (!S.versiyon.value) { return null; }
        l = suzulmus("kasa");
        var k = l ? tekil(l.map(function (x) { return x.kasa; }).filter(Boolean)) : [];
        if (!k.length) { k = GENEL.kasa.slice(); }
        return k.map(function (x) { return [x, kasaYazi(x)]; }).sort(function (a, b) { return GENEL.kasa.indexOf(a[0]) - GENEL.kasa.indexOf(b[0]); });
      }
      if (alan === "vites") {
        if (!S.kasa.value) { return null; }
        l = suzulmus("vites");
        var v = l && l.length ? tekil(l.map(function (x) { return x.vites; })) : GENEL.vites;
        return GENEL.vites.filter(function (k) { return v.indexOf(k) > -1; }).map(function (k) { return [k, t("arac.vites_" + k)]; });
      }
      if (alan === "yakit") {
        if (!S.vites.value) { return null; }
        l = suzulmus("yakit");
        var yk = l && l.length ? tekil(l.map(function (x) { return x.yakit; })) : GENEL.yakit.slice();
        /* sonradan LPG taktırılan benzinliler (3.5 litreye kadar; süper spor / büyük V8–V12 motorlarda gösterilmez) */
        var lpgUygun = l && l.length ? l.some(function (x) { return x.yakit === "benzin" && (!x.hacim || parseFloat(x.hacim) <= 3.5); }) : true;
        if (yk.indexOf("benzin") > -1 && yk.indexOf("lpg") < 0 && lpgUygun) { yk.push("lpg"); }
        return GENEL.yakit.filter(function (k) { return yk.indexOf(k) > -1; }).map(function (k) { return [k, t("arac.yakit_" + k)]; });
      }
      if (alan === "motor") {
        if (!S.yakit.value) { return null; }
        l = suzulmus("motor");
        if (l && l.length) {
          var h = tekil(l.map(hacimYazi).filter(Boolean)).sort(function (a, b) { return (parseFloat(a) || 0) - (parseFloat(b) || 0); });
          if (h.length) { return h.map(function (x) { return [x, x]; }); }
        }
        if (S.yakit.value === "elektrik") { return [[t("sayfalar.teklif_elektrik"), t("sayfalar.teklif_elektrik")]]; }
        return GENEL.hacim.map(function (x) { return [x, x]; });
      }
      return [];
    }

    function alanAdi(alan) { return S[alan].dataset.etiket || alan; }
    function doldur(alan) {
      var s = S[alan], eski = s.value, eskiElle = E[alan].value, l = null;
      try { l = secenekler(alan); } catch (e) { l = []; }
      while (s.options.length) { s.remove(0); }
      var onceki = ZINCIR[ZINCIR.indexOf(alan) - 1];
      if (l === null || l === "yukleniyor") {
        secenekEkle(s, "", l === null ? t("sayfalar.teklif_once", { alan: alanAdi(onceki) }) : t("sayfalar.teklif_yukleniyor"));
        s.disabled = true; E[alan].hidden = true; E[alan].value = "";
        return;
      }
      s.disabled = false;
      secenekEkle(s, "", t("sayfalar.teklif_sec"));
      l.forEach(function (r) { secenekEkle(s, r[0], r[1]); });
      secenekEkle(s, ELLE, t("sayfalar.teklif_elle"));
      var degerler = l.map(function (r) { return r[0]; });
      if (eski === ELLE) { s.value = ELLE; E[alan].value = eskiElle; }
      else if (eski && degerler.indexOf(eski) > -1) { s.value = eski; }
      else if (l.length === 1 && alan !== "marka") { s.value = l[0][0]; }   // tek seçenek: otomatik seç
      else if (!l.length && alan !== "marka") { s.value = ELLE; }            // listede hiç yok: doğrudan elle
      else { s.value = ""; }
      E[alan].hidden = s.value !== ELLE;
      if (s.value !== ELLE) { E[alan].value = ""; }
    }
    function zincirle(baslangic) {
      for (var i = Math.max(0, ZINCIR.indexOf(baslangic)); i < ZINCIR.length; i++) { doldur(ZINCIR[i]); }
      var lpg = $("[data-lpg-not]", form);
      if (lpg) { lpg.hidden = !Array.prototype.some.call(S.yakit.options, function (o) { return o.value === "lpg"; }); }
    }
    /* marka seçilince o markanın versiyon dosyası yüklenir (bir kez); yüklenemezse genel listelerle devam */
    function markaYukle() {
      var m = marka();
      if (!m || MARKA_VERI[m.d] !== undefined || !window.fetch) { if (m && !window.fetch) { MARKA_VERI[m.d] = false; } return; }
      var d = m.d;
      var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, 20000);
      fetch(klasor + d + ".json?v=" + encodeURIComponent(KATALOG.guncelleme || ""), { signal: iptal ? iptal.signal : undefined })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (v) { clearTimeout(sure); MARKA_VERI[d] = v && Array.isArray(v.modeller) ? v : false; })
        .catch(function () { clearTimeout(sure); MARKA_VERI[d] = false; })
        .then(function () { try { var mm = marka(); if (mm && mm.d === d) { zincirle("versiyon"); } } catch (e) { /* elle devam */ } });
    }
    ZINCIR.forEach(function (alan, i) {
      S[alan].addEventListener("change", function () {
        try {
          E[alan].hidden = S[alan].value !== ELLE;
          if (S[alan].value === ELLE) { E[alan].focus(); }
          if (alan === "marka") { markaYukle(); }
          if (ZINCIR[i + 1]) { zincirle(ZINCIR[i + 1]); }
        } catch (e) { elleMod(); }
      });
      E[alan].addEventListener("input", function () {
        if (alan === "yil") { var v = rakam(E.yil.value).slice(0, 4); if (v !== E.yil.value) { E.yil.value = v; } }
        if (alan === "yil" && ZINCIR[i + 1]) { try { zincirle("model"); } catch (e) { elleMod(); } }
      });
    });

    /* katalog yoksa: tüm listeler genel seçenekler + elle yazma ile çalışır */
    function elleMod() {
      KATALOG = null;
      if (durumYazi) { durumYazi.textContent = t("sayfalar.teklif_liste_hata"); durumYazi.hidden = false; }
      try { zincirle("marka"); S.marka.value = ELLE; E.marka.hidden = false; zincirle("yil"); } catch (e) { /* son çare: alanlar boş kalır */ }
    }
    if (durumYazi) { durumYazi.textContent = t("sayfalar.teklif_yukleniyor"); durumYazi.hidden = false; }
    zincirle("marka");
    (function yukle() {
      var adres = form.dataset.katalog;
      if (!adres || !window.fetch) { elleMod(); return; }
      var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, 15000);
      fetch(adres, { cache: "no-cache", signal: iptal ? iptal.signal : undefined })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (k) {
          clearTimeout(sure);
          if (!katalogGecerli(k)) { throw new Error("katalog"); }
          KATALOG = k;
          if (durumYazi) { durumYazi.hidden = true; }
          zincirle("marka");
          onSecim();
        })
        .catch(function () { clearTimeout(sure); elleMod(); });
    })();
    /* araç detayından gelirse (?marka=...&yil=...) marka ve yılı önceden seç */
    function onSecim() {
      var p = new URLSearchParams(location.search), mk = p.get("marka");
      if (!mk || !KATALOG) { return; }
      var i = KATALOG.markalar.map(function (m) { return m.ad.toLowerCase(); }).indexOf(mk.toLowerCase());
      if (i < 0) { return; }
      S.marka.value = String(i); markaYukle(); zincirle("yil");
      if (p.get("yil") && Array.prototype.some.call(S.yil.options, function (o) { return o.value === p.get("yil"); })) { S.yil.value = p.get("yil"); zincirle("model"); }
    }

    /* ---- para / km alanları: 85000 → 85.000 */
    $$("[data-para-alan]", form).forEach(function (el) {
      el.addEventListener("input", function () { var n = rakam(el.value).slice(0, 12); el.value = n ? sayi(Number(n)) : ""; });
    });
    /* tramer tutarı yalnızca "var" seçilince */
    $$('input[name="tramer"]', form).forEach(function (r) {
      r.addEventListener("change", function () { $("[data-tramer-tutar]", form).hidden = form.elements.tramer.value !== "var"; });
    });
    var bilmiyorum = $("[data-parca-bilinmiyor]", form);
    if (bilmiyorum) { bilmiyorum.addEventListener("change", function () { $("[data-parca-izgara]", form).hidden = bilmiyorum.checked; }); }
    $$("[data-parca]", form).forEach(function (s) {
      s.addEventListener("change", function () { s.closest(".parca").dataset.durum = s.value; });
    });
    $$("input,select,textarea", form).forEach(function (el) {
      ["input", "change"].forEach(function (o) { el.addEventListener(o, function () { var k = el.closest(".alan, .onay"); if (k) { k.classList.remove("hatali"); } hataYazi.hidden = true; }); });
    });

    /* ---- değerler (yazı = müşterinin dilinde, anahtar = panel için dil bağımsız) */
    function secimYazi(alan) {
      var s = S[alan];
      if (s.value === ELLE) { return E[alan].value.trim(); }
      return s.value && s.selectedIndex > -1 ? s.options[s.selectedIndex].text : "";
    }
    function secimAnahtar(alan) {
      var s = S[alan];
      if (s.value === ELLE) { return E[alan].value.trim(); }
      if (alan === "vites" || alan === "yakit" || alan === "kasa") { return s.value; }
      return secimYazi(alan);
    }
    function radyoYazi(ad) {
      var r = $('input[name="' + ad + '"]:checked', form);
      return r ? $("span", r.closest("label")).textContent.trim() : "";
    }
    function deger(ad) { var e = form.elements[ad]; return e ? String(e.value || "").trim() : ""; }

    /* ---- adım doğrulama */
    function isaretle(el, mesaj) {
      var k = el.closest(".alan, .onay"); if (k) { k.classList.add("hatali"); }
      return { el: el, mesaj: mesaj };
    }
    function dogrula(n) {
      var hatalar = [];
      if (n === 1) {
        ZINCIR.forEach(function (alan) {
          var s = S[alan];
          if (s.disabled || !s.value) { hatalar.push(isaretle(s)); return; }
          if (s.value === ELLE) {
            var v = E[alan].value.trim();
            if (!v) { hatalar.push(isaretle(E[alan])); }
            else if (alan === "yil" && !yilGecerli(v)) { hatalar.push(isaretle(E[alan], t("ortak.yil_hata"))); }
          }
        });
      }
      if (n === 2) {
        var km = form.elements.km;
        if (!rakam(km.value)) { hatalar.push(isaretle(km, km.value.trim() ? t("sayfalar.teklif_km_hata") : null)); }
      }
      if (n === 4) {
        ["ad", "tel"].forEach(function (a) { if (!deger(a)) { hatalar.push(isaretle(form.elements[a])); } });
        if (deger("tel") && rakam(deger("tel")).length < 10) { hatalar.push(isaretle(form.elements.tel)); }
        if (!form.elements.kvkk.checked) { hatalar.push(isaretle(form.elements.kvkk)); }
      }
      if (hatalar.length) {
        hataYazi.textContent = hatalar.filter(function (h) { return h.mesaj; }).map(function (h) { return h.mesaj; })[0] || t("ortak.form_hata");
        hataYazi.hidden = false;
        try { hatalar[0].el.focus(); } catch (e) { /* gizli alan */ }
        return false;
      }
      hataYazi.hidden = true;
      return true;
    }

    /* ---- özet ve WhatsApp mesajı */
    function parcaListesi() {
      if (bilmiyorum && bilmiyorum.checked) { return [t("sayfalar.teklif_parca_bilmiyorum")]; }
      var l = [];
      PARCALAR.forEach(function (p) {
        var s = form.elements["parca_" + p];
        if (s && s.value !== "orijinal") { l.push(t("sayfalar.parca_" + p) + ": " + s.options[s.selectedIndex].text); }
      });
      return l.length ? l : [t("sayfalar.teklif_hepsi_orijinal")];
    }
    function bolumler() {
      var tramer = radyoYazi("tramer");
      if (form.elements.tramer.value === "var" && rakam(deger("tramer_tutar"))) { tramer += " – " + deger("tramer_tutar") + " TL"; }
      var beklenti = rakam(deger("beklenti"));
      return [
        [1, [
          [alanAdi("marka"), secimYazi("marka")], [alanAdi("yil"), secimYazi("yil")], [alanAdi("model"), secimYazi("model")],
          [alanAdi("versiyon"), secimYazi("versiyon")], [alanAdi("kasa"), secimYazi("kasa")], [alanAdi("vites"), secimYazi("vites")],
          [alanAdi("yakit"), secimYazi("yakit")], [alanAdi("motor"), secimYazi("motor")]]],
        [2, [
          [t("sayfalar.sat_istek"), radyoYazi("istek")], [t("arac.km"), deger("km")], [t("sayfalar.sat_renk"), deger("renk")],
          [t("sayfalar.sat_beklenti"), beklenti ? sayi(Number(beklenti)) + " TL" : ""]]],
        [3, [
          [t("sayfalar.teklif_tramer"), tramer], [t("sayfalar.teklif_agir"), radyoYazi("agir")],
          [t("sayfalar.teklif_parca_baslik"), parcaListesi().join(", ")], [t("sayfalar.teklif_hasar_not"), deger("hasar_not")],
          [t("sayfalar.sat_foto_baslik"), (form._fotolar || []).length ? String(form._fotolar.length) : ""]]],
        [4, [[t("ortak.ad_soyad"), deger("ad")], [t("ortak.telefonunuz"), deger("tel")], [t("ortak.notunuz"), deger("not")]]]
      ];
    }
    function ozetCiz() {
      var dl = $("[data-teklif-ozet]", form);
      dl.innerHTML = bolumler().map(function (b) {
        return '<div class="teklif-ozet-bolum"><div class="teklif-ozet-bas"><h3>' + kacis(t("sayfalar.teklif_adim" + b[0])) + "</h3>" +
          '<button type="button" class="teklif-duzenle" data-git="' + b[0] + '">' + kacis(t("sayfalar.teklif_duzenle")) + "</button></div>" +
          b[1].filter(function (r) { return r[1]; }).map(function (r) { return "<div><dt>" + kacis(r[0]) + "</dt><dd>" + kacis(r[1]) + "</dd></div>"; }).join("") +
          "</div>";
      }).join("");
      $$("[data-git]", dl).forEach(function (b) { b.addEventListener("click", function () { git(Number(b.dataset.git)); }); });
    }
    function mesajSatirlari(no) {
      var satir = ["*" + form.dataset.baslik + "*", t("sayfalar.teklif_no") + ": " + no];
      bolumler().forEach(function (b) {
        satir.push("", "*" + t("sayfalar.teklif_adim" + b[0]) + "*");
        b[1].forEach(function (r) {
          if (!r[1] || r[0] === t("sayfalar.sat_foto_baslik")) { return; }
          satir.push(r[0] + ": " + r[1]);
        });
      });
      return satir;
    }
    function kayitVerisi(no, fotoLink) {
      var parcalar = {};
      PARCALAR.forEach(function (p) { var s = form.elements["parca_" + p]; if (s) { parcalar[p] = s.value; } });
      var v = {
        no: no, dil: DIL, istek: deger("istek"),
        marka: secimAnahtar("marka"), yil: secimAnahtar("yil"), model: secimAnahtar("model"), versiyon: secimAnahtar("versiyon"), kasa: secimAnahtar("kasa"),
        vites: secimAnahtar("vites"), yakit: secimAnahtar("yakit"), motor: secimAnahtar("motor"),
        elle: ZINCIR.filter(function (a) { return S[a].value === ELLE; }),
        km: rakam(deger("km")), renk: deger("renk"), beklenti: rakam(deger("beklenti")),
        tramer: deger("tramer"), tramer_tutar: rakam(deger("tramer_tutar")), agir: deger("agir"),
        parca_bilinmiyor: !!(bilmiyorum && bilmiyorum.checked), parcalar: parcalar, hasar_not: deger("hasar_not"),
        ad: deger("ad"), tel: deger("tel"), not: deger("not"), foto: fotoLink || "", foto_adet: (form._fotolar || []).length,
        web_adresi: deger("web_adresi")
      };
      return v;
    }

    /* ---- adımlar arası geçiş */
    var geri = $("[data-teklif-geri]", form), ileri = $("[data-teklif-ileri]", form), gonder = $("[data-teklif-gonder]", form), yeni = $("[data-teklif-yeni]", form);
    function git(n) {
      adim = Math.max(1, Math.min(TOPLAM, n));
      $$("[data-adim]", form).forEach(function (f) { f.hidden = Number(f.dataset.adim) !== adim; });
      $$("[data-adim-gosterge]", form).forEach(function (li) {
        var k = Number(li.dataset.adimGosterge);
        li.classList.toggle("tamam", k < adim);
        if (k === adim) { li.setAttribute("aria-current", "step"); } else { li.removeAttribute("aria-current"); }
      });
      var sayac = $("[data-adim-sayac]", form);
      if (sayac) { sayac.textContent = t("sayfalar.teklif_adim_sayac", { n: adim, toplam: TOPLAM }) + " · " + t("sayfalar.teklif_adim" + adim); }
      geri.hidden = adim === 1 || gonderildi;
      ileri.hidden = adim === TOPLAM;
      gonder.hidden = adim !== TOPLAM || gonderildi;
      yeni.hidden = !gonderildi;
      hataYazi.hidden = true;
      if (adim === TOPLAM) { ozetCiz(); }
      var ust = form.getBoundingClientRect().top;
      if (ust < 0 || ust > window.innerHeight * 0.6) { form.scrollIntoView({ behavior: "smooth", block: "start" }); }
    }
    ileri.addEventListener("click", function () { if (dogrula(adim)) { git(adim + 1); } });
    geri.addEventListener("click", function () { git(adim - 1); });
    yeni.addEventListener("click", function () {
      gonderildi = false; form.reset();
      (form._fotolar || []).forEach(function (f) { URL.revokeObjectURL(f.onizleme); });
      form._fotolar = []; if (form._fotoCiz) { form._fotoCiz(); }
      $("[data-teklif-tesekkur]", form).hidden = true;
      $("[data-tramer-tutar]", form).hidden = true; $("[data-parca-izgara]", form).hidden = false;
      $$(".parca", form).forEach(function (p) { delete p.dataset.durum; });
      zincirle("marka"); git(1);
    });
    form.addEventListener("keydown", function (e) {   // Enter formu göndermesin, bir sonraki adıma geçsin
      if (e.key === "Enter" && e.target.tagName !== "TEXTAREA" && e.target.type !== "submit") { e.preventDefault(); if (adim < TOPLAM) { ileri.click(); } }
    });

    /* ---- gönder: (fotoğraflar → galeri bağlantısı) → sunucuya kayıt → WhatsApp */
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (adim !== TOPLAM || gonderildi) { return; }
      for (var n = 1; n < TOPLAM; n++) { if (!dogrula(n)) { git(n); dogrula(n); return; } }
      var no = noUret(), fotolar = form._fotolar || [], uyari = $("[data-foto-uyari]", form);
      var pencere = window.open("", "_blank");   // tıklama anında açılır; sonra açılırsa tarayıcı engeller
      if (pencere) { try { pencere.opener = null; pencere.document.title = "WhatsApp"; pencere.document.body.innerHTML = '<p style="font:16px system-ui;padding:24px">' + kacis(t("sayfalar.sat_foto_yukleniyor")) + "</p>"; } catch (x) { /* farklı köken */ } }
      var eski = gonder.innerHTML; gonder.disabled = true; gonder.textContent = t("sayfalar.sat_foto_yukleniyor");
      function zamanli(url, secenek, ms) {
        var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, ms);
        secenek.signal = iptal ? iptal.signal : undefined;
        return fetch(url, secenek).then(function (r) {
          clearTimeout(sure);
          return r.json().catch(function () { return { ok: false }; }).then(function (j) { j.durum = r.status; return j; });
        }, function (h) { clearTimeout(sure); throw h; });
      }
      function fotoYukle() {
        if (!fotolar.length || !window.fetch) { return Promise.resolve(null); }
        return Promise.all(fotolar.map(function (f) { return kucult(f.dosya); })).then(function (bloblar) {
          var fd = new FormData();
          bloblar.forEach(function (b, i) { fd.append("foto[]", b, "foto" + (i + 1) + ".jpg"); });
          fd.append("web_adresi", deger("web_adresi"));
          return zamanli($("[data-foto-yukle]", form).dataset.uc, { method: "POST", body: fd }, 60000);
        }).then(function (j) {
          if (j && j.ok && j.link) { return j.link; }
          uyari.textContent = t(j && j.durum === 429 ? "sayfalar.sat_foto_cok" : "sayfalar.sat_foto_hata"); uyari.hidden = false;
          return null;
        }).catch(function () { uyari.textContent = t("sayfalar.sat_foto_hata"); uyari.hidden = false; return null; });
      }
      function waSatirlari(link) {
        var satir = mesajSatirlari(no);
        if (link) { satir.push("", t("sayfalar.sat_foto_wa") + " (" + fotolar.length + "): " + link); }
        else if (fotolar.length) { satir.push("", t("sayfalar.sat_foto_wa") + ": " + fotolar.length); }
        return satir;
      }
      function kaydet(link) {
        if (!form.dataset.kaydet || !window.fetch) { return Promise.resolve(); }
        var veri = kayitVerisi(no, link); veri.wa_mesaj = waSatirlari(link).join("\n");   // Aşama 49: veritabanına mesajın kendisi de yazılır
        return zamanli(form.dataset.kaydet, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(veri) }, 10000)
          .catch(function () { /* kayıt olmasa da mesaj gider */ });
      }
      var fotoLink = null;
      fotoYukle().then(function (l) { fotoLink = l; return kaydet(l); }).catch(function () {}).then(function () {
        var url = wa(waSatirlari(fotoLink).join("\n"));
        gonder.disabled = false; gonder.innerHTML = eski;
        gonderildi = true;
        $("[data-teklif-tesekkur]", form).hidden = false;
        git(TOPLAM);
        if (pencere && !pencere.closed) { pencere.location.href = url; } else { location.href = url; }
      });
    });

    git(1);
    /* test ve hata ayıklama için (konsoldan): window.__teklif.mesaj() */
    window.__teklif = { mesaj: function () { return mesajSatirlari("TEST").join("\n"); }, katalog: function () { return KATALOG; }, marka: function () { return markaVeri(); } };
  }

  /* ---------- Ön sipariş (Aşama 39): müşteri aradığı aracı (marka, model, yıl aralığı, km, vites, yakıt, kasa, hasar)
     ve bütçesini yazar. 4 adım: Araç → Bütçe ve ödeme → İletişim → Özet. Talep sunucuya kaydedilir (teklif/siparis.php →
     panel "Ön siparişler") ve tek WhatsApp mesajı açılır; ikisinden biri başarısız olsa da diğeri çalışır.
     Katalog yüklenemezse marka/model elle yazılır (çökme yok). Özette stoktaki uygun araçlar gösterilir. */
  var FARK = "__fark";
  function siparisFormu() {
    var form = $("[data-siparis-form]");
    if (!form) { return; }
    var KATALOG = null, adim = 1, TOPLAM = 4, gonderildi = false, BU_YIL = new Date().getFullYear();
    var S = { marka: form.elements.marka, model: form.elements.model }, E = { marka: form.elements.marka_elle, model: form.elements.model_elle };
    var durumYazi = $("[data-katalog-durum]", form), hataYazi = $("[data-form-hata]", form);
    var geri = $("[data-teklif-geri]", form), ileri = $("[data-teklif-ileri]", form), gonder = $("[data-teklif-gonder]", form), yeni = $("[data-teklif-yeni]", form);
    var tesekkur = $("[data-teklif-tesekkur]", form), waLink = $("[data-siparis-wa]", form);

    function deger(ad) { var e = form.elements[ad]; return e && e.value != null ? String(e.value).trim() : ""; }
    function secili(ad) { return $$('input[name="' + ad + '"]:checked', form).map(function (i) { return i.value; }); }
    function etiketYazi(input) { var s = input && input.closest("label") ? $("span", input.closest("label")) : null; return s ? s.textContent.trim() : ""; }
    function seciliYazi(ad) { return $$('input[name="' + ad + '"]:checked', form).map(etiketYazi).join(", "); }
    function katMarka() { return KATALOG && /^\d+$/.test(S.marka.value) ? KATALOG.markalar[Number(S.marka.value)] || null : null; }
    function katModel() { var m = katMarka(); return m && /^\d+$/.test(S.model.value) ? m.modeller[Number(S.model.value)] || null : null; }
    function secimYazi(alan) {
      var s = S[alan];
      if (s.value === ELLE) { return E[alan].value.trim(); }
      if (s.value === FARK) { return t("arac.farketmez"); }
      return s.value && s.selectedIndex > -1 ? s.options[s.selectedIndex].text : "";
    }

    /* ---- listeler */
    function doldurMarka() {
      var eski = S.marka.value;
      while (S.marka.options.length) { S.marka.remove(0); }
      secenekEkle(S.marka, "", t("sayfalar.teklif_sec"));
      secenekEkle(S.marka, FARK, t("sayfalar.siparis_marka_farketmez"));
      if (KATALOG) {
        KATALOG.markalar.map(function (m, i) { return [String(i), m.ad]; }).sort(function (a, b) { return a[1].localeCompare(b[1], "tr"); })
          .forEach(function (r) { secenekEkle(S.marka, r[0], r[1]); });
      }
      secenekEkle(S.marka, ELLE, t("sayfalar.teklif_elle"));
      S.marka.disabled = false;
      S.marka.value = Array.prototype.some.call(S.marka.options, function (o) { return o.value === eski; }) ? eski : "";
    }
    function doldurModel() {
      var eski = S.model.value, m = katMarka(), mv = S.marka.value;
      while (S.model.options.length) { S.model.remove(0); }
      if (!mv) {
        secenekEkle(S.model, "", t("sayfalar.teklif_once", { alan: S.marka.dataset.etiket }));
        S.model.disabled = true; E.model.hidden = true; E.model.value = "";
        return;
      }
      if (mv === FARK) {   // marka farketmez → model de farketmez
        secenekEkle(S.model, FARK, t("sayfalar.siparis_model_farketmez"));
        S.model.value = FARK; S.model.disabled = true; E.model.hidden = true; E.model.value = "";
        return;
      }
      S.model.disabled = false;
      secenekEkle(S.model, "", t("sayfalar.teklif_sec"));
      secenekEkle(S.model, FARK, t("sayfalar.siparis_model_farketmez"));
      if (m) { m.modeller.forEach(function (md, i) { secenekEkle(S.model, String(i), md.ad); }); }
      secenekEkle(S.model, ELLE, t("sayfalar.teklif_elle"));
      S.model.value = Array.prototype.some.call(S.model.options, function (o) { return o.value === eski; }) ? eski : "";
      E.model.hidden = S.model.value !== ELLE;
      if (S.model.value !== ELLE) { E.model.value = ""; }
    }
    /* yıl listeleri: seçilen model (yoksa marka) hangi yıllarda üretildiyse; bilgi yoksa 1980 – bu yıl */
    function doldurYillar() {
      var araliklar = [], md = katModel(), m = katMarka();
      if (md) { araliklar = md.y || []; }
      else if (m) { m.modeller.forEach(function (x) { araliklar = araliklar.concat(x.y || []); }); }
      var yillar = [];
      araliklar.forEach(function (r) {
        if (!Array.isArray(r)) { return; }
        for (var z = Math.max(1950, Number(r[0]) || BU_YIL); z <= Math.min(r[1] == null ? BU_YIL : Number(r[1]), BU_YIL); z++) { yillar.push(z); }
      });
      if (!yillar.length) { for (var g = 1980; g <= BU_YIL; g++) { yillar.push(g); } }
      yillar = tekil(yillar).sort(function (a, b) { return b - a; });
      ["yil_min", "yil_max"].forEach(function (ad) {
        var s = form.elements[ad], eski = s.value;
        while (s.options.length) { s.remove(0); }
        secenekEkle(s, "", t("arac.farketmez"));
        yillar.forEach(function (y) { secenekEkle(s, String(y), String(y)); });
        s.value = yillar.indexOf(Number(eski)) > -1 ? eski : "";
      });
    }
    function zincir(alan) {
      try {
        if (alan === "marka") { doldurModel(); }
        doldurYillar();
      } catch (e) { elleMod(); }
    }
    function elleMod() {
      KATALOG = null;
      if (durumYazi) { durumYazi.textContent = t("sayfalar.teklif_liste_hata"); durumYazi.hidden = false; }
      try { doldurMarka(); doldurModel(); doldurYillar(); } catch (e) { /* son çare: alanlar elle */ }
    }
    ["marka", "model"].forEach(function (alan) {
      S[alan].addEventListener("change", function () {
        E[alan].hidden = S[alan].value !== ELLE;
        if (S[alan].value === ELLE) { E[alan].focus(); } else { E[alan].value = ""; }
        zincir(alan);
      });
    });

    /* ---- katalog (veri/katalog/index.json; yalnızca marka → model → yıl aralığı kullanılır) */
    if (durumYazi) { durumYazi.textContent = t("sayfalar.teklif_yukleniyor"); durumYazi.hidden = false; }
    S.marka.disabled = true;
    (function yukle() {
      var adres = form.dataset.katalog;
      if (!adres || !window.fetch) { elleMod(); onDoldur(); return; }
      var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, 15000);
      fetch(adres, { cache: "no-cache", signal: iptal ? iptal.signal : undefined })
        .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
        .then(function (k) {
          clearTimeout(sure);
          if (!katalogGecerli(k)) { throw new Error("katalog"); }
          KATALOG = k;
          if (durumYazi) { durumYazi.hidden = true; }
          doldurMarka(); doldurModel(); doldurYillar();
        })
        .catch(function () { clearTimeout(sure); elleMod(); })
        .then(function () { try { onDoldur(); } catch (e) { /* ön dolum isteğe bağlı */ } });
    })();

    /* araç listesinden gelirse (?marka=&model=&yil=&km=&fiyat=&yakit=&vites=&kasa=) alanları önceden doldur */
    function onDoldur() {
      var p = new URLSearchParams(location.search);
      var mk = (p.get("marka") || "").trim(), md = (p.get("model") || "").trim();
      if (mk) {
        var i = KATALOG ? KATALOG.markalar.map(function (m) { return anahtar(m.ad); }).indexOf(anahtar(mk)) : -1;
        if (i > -1) { S.marka.value = String(i); } else { S.marka.value = ELLE; E.marka.value = mk.slice(0, 60); E.marka.hidden = false; }
        doldurModel();
        if (md) {
          var m = katMarka(), j = m ? m.modeller.map(function (x) { return anahtar(x.ad); }).indexOf(anahtar(md)) : -1;
          if (j > -1) { S.model.value = String(j); } else { S.model.value = ELLE; E.model.value = md.slice(0, 60); E.model.hidden = false; }
        }
        doldurYillar();
      }
      var yil = p.get("yil");
      if (yil && Array.prototype.some.call(form.elements.yil_min.options, function (o) { return o.value === yil; })) { form.elements.yil_min.value = yil; }
      [["km", "km_max"], ["fiyat", "butce_max"]].forEach(function (c) {
        var n = rakam(p.get(c[0])).slice(0, 12); if (n) { form.elements[c[1]].value = sayi(Number(n)); }
      });
      var vt = p.get("vites"); if (vt) { $$('input[name="vites"]', form).forEach(function (r) { r.checked = r.value === (vt === "yari_otomatik" ? "otomatik" : vt); }); if (!secili("vites").length) { form.elements.vites[0].checked = true; } }
      var yk = p.get("yakit"); if (yk) { $$('input[name="yakit"]', form).forEach(function (c) { c.checked = c.value === yk; }); }
      var ks = p.get("kasa") || (p.get("kategori") === "suv" ? "suv" : "");
      if (ks && Array.prototype.some.call(form.elements.kasa.options, function (o) { return o.value === ks; })) { form.elements.kasa.value = ks; }
    }

    /* ---- biçimlendirme ve küçük davranışlar */
    $$("[data-para-alan]", form).forEach(function (el) {
      el.addEventListener("input", function () { var n = rakam(el.value).slice(0, 12); el.value = n ? sayi(Number(n)) : ""; });
    });
    var takasAlan = $("[data-takas-alan]", form);
    function takasGoster() { takasAlan.hidden = secili("odeme").indexOf("takas") < 0; }
    $$('input[name="odeme"]', form).forEach(function (c) { c.addEventListener("change", takasGoster); });
    $$("input,select,textarea", form).forEach(function (el) {
      ["input", "change"].forEach(function (o) { el.addEventListener(o, function () { var k = el.closest(".alan, .onay"); if (k) { k.classList.remove("hatali"); } hataYazi.hidden = true; }); });
    });

    /* ---- doğrulama */
    function isaretle(el, mesaj) { var k = el.closest(".alan, .onay"); if (k) { k.classList.add("hatali"); } return { el: el, mesaj: mesaj }; }
    function sayiDeger(ad) { var n = rakam(deger(ad)); return n ? Number(n) : null; }
    function dogrula(n) {
      var h = [];
      if (n === 1) {
        if (!S.marka.value || S.marka.disabled) { h.push(isaretle(S.marka, t("sayfalar.siparis_hata_marka"))); }
        else if (S.marka.value === ELLE && !E.marka.value.trim()) { h.push(isaretle(E.marka)); }
        if (S.marka.value && S.marka.value !== FARK) {
          if (!S.model.value) { h.push(isaretle(S.model, t("sayfalar.siparis_hata_model"))); }
          else if (S.model.value === ELLE && !E.model.value.trim()) { h.push(isaretle(E.model)); }
        }
        var y1 = Number(deger("yil_min")) || 0, y2 = Number(deger("yil_max")) || 0;
        if (y1 && y2 && y1 > y2) { h.push(isaretle(form.elements.yil_max, t("sayfalar.siparis_hata_yil"))); }
        var km = sayiDeger("km_max");
        if (km !== null && km < 1000) { h.push(isaretle(form.elements.km_max, t("sayfalar.siparis_hata_km"))); }
      }
      if (n === 2) {
        var bmax = sayiDeger("butce_max"), bmin = sayiDeger("butce_min");
        if (bmax === null) { h.push(isaretle(form.elements.butce_max)); }
        else if (bmax < 10000) { h.push(isaretle(form.elements.butce_max, t("sayfalar.siparis_hata_butce"))); }
        if (bmin !== null && bmin < 10000) { h.push(isaretle(form.elements.butce_min, t("sayfalar.siparis_hata_butce"))); }
        else if (bmin !== null && bmax !== null && bmin > bmax) { h.push(isaretle(form.elements.butce_min, t("sayfalar.siparis_hata_butce_min"))); }
      }
      if (n === 3) {
        if (!deger("ad")) { h.push(isaretle(form.elements.ad)); }
        if (rakam(deger("tel")).length < 10) { h.push(isaretle(form.elements.tel)); }
        if (!form.elements.kvkk.checked) { h.push(isaretle(form.elements.kvkk)); }
      }
      if (h.length) {
        hataYazi.textContent = h.filter(function (x) { return x.mesaj; }).map(function (x) { return x.mesaj; })[0] || t("ortak.form_hata");
        hataYazi.hidden = false;
        try { h[0].el.focus(); } catch (e) { /* gizli alan */ }
        return false;
      }
      hataYazi.hidden = true;
      return true;
    }

    /* ---- özet / mesaj (müşterinin dilinde) */
    function tl(ad) { var n = sayiDeger(ad); return n ? sayi(n) + " TL" : ""; }
    function yilYazi() {
      var a = deger("yil_min"), b = deger("yil_max");
      if (a && b) { return a === b ? a : a + " – " + b; }
      if (a) { return a + " – " + BU_YIL; }
      if (b) { return "≤ " + b; }
      return t("arac.farketmez");
    }
    function bolumler() {
      var km = sayiDeger("km_max"), kasa = form.elements.kasa;
      return [
        [1, [
          [S.marka.dataset.etiket, secimYazi("marka")], [S.model.dataset.etiket, secimYazi("model")],
          [t("arac.yil"), yilYazi()], [t("sayfalar.siparis_km_max"), km ? sayi(km) + " km" : t("arac.farketmez")],
          [t("sayfalar.siparis_kasa"), kasa.value ? kasa.options[kasa.selectedIndex].text : t("arac.farketmez")],
          [t("sayfalar.siparis_vites"), seciliYazi("vites")],
          [t("arac.yakit"), seciliYazi("yakit") || t("arac.farketmez")],
          [t("sayfalar.siparis_hasar"), seciliYazi("hasar")],
          [t("sayfalar.siparis_renk"), deger("renk")], [t("sayfalar.siparis_istekler"), deger("istekler")]]],
        [2, [
          [t("sayfalar.siparis_ozet_butce_max"), tl("butce_max")], [t("sayfalar.siparis_ozet_butce_min"), tl("butce_min")],
          [t("sayfalar.siparis_ozet_odeme"), seciliYazi("odeme")],
          [t("sayfalar.siparis_takas_arac"), secili("odeme").indexOf("takas") > -1 ? deger("takas_arac") : ""],
          [t("sayfalar.siparis_ozet_zaman"), seciliYazi("zaman")]]],
        [3, [
          [t("ortak.ad_soyad"), deger("ad")], [t("ortak.telefonunuz"), deger("tel")], [t("sayfalar.siparis_sehir"), deger("sehir")],
          [t("sayfalar.siparis_ozet_ulasim"), seciliYazi("ulasim")], [t("ortak.notunuz"), deger("not")]]]
      ];
    }
    function ozetCiz() {
      var dl = $("[data-teklif-ozet]", form);
      dl.innerHTML = bolumler().map(function (b) {
        return '<div class="teklif-ozet-bolum"><div class="teklif-ozet-bas"><h3>' + kacis(t("sayfalar.siparis_adim" + b[0])) + "</h3>" +
          (gonderildi ? "" : '<button type="button" class="teklif-duzenle" data-git="' + b[0] + '">' + kacis(t("sayfalar.teklif_duzenle")) + "</button>") + "</div>" +
          b[1].filter(function (r) { return r[1]; }).map(function (r) { return "<div><dt>" + kacis(r[0]) + "</dt><dd>" + kacis(r[1]) + "</dd></div>"; }).join("") +
          "</div>";
      }).join("");
      $$("[data-git]", dl).forEach(function (b) { b.addEventListener("click", function () { git(Number(b.dataset.git)); }); });
      stokCiz();
    }
    function mesajSatirlari(no) {
      var satir = ["*" + form.dataset.baslik + "*", t("sayfalar.siparis_no") + ": " + no];
      bolumler().forEach(function (b) {
        satir.push("", "*" + t("sayfalar.siparis_adim" + b[0]) + "*");
        b[1].forEach(function (r) { if (r[1]) { satir.push(r[0] + ": " + r[1]); } });
      });
      return satir;
    }

    /* ---- stoktaki uygun araçlar (yalnızca bilgi; talep yine gönderilebilir) */
    function uygunAraclar() {
      var mk = S.marka.value === FARK ? "" : anahtar(secimYazi("marka")), md = S.model.value === FARK ? "" : anahtar(secimYazi("model"));
      var y1 = Number(deger("yil_min")) || 0, y2 = Number(deger("yil_max")) || 0, km = sayiDeger("km_max"), bmax = sayiDeger("butce_max");
      var vt = secili("vites")[0] || "", yk = secili("yakit"), ks = form.elements.kasa.value, hs = secili("hasar")[0] || "";
      return ARACLAR.filter(function (a) {
        if (mk) { var am = anahtar(a.marka); if (am.indexOf(mk) < 0 && mk.indexOf(am) < 0) { return false; } }
        if (md) { var metin = anahtar([a.seri, a.model].join(" ")); if (metin.indexOf(md) < 0 && (!a.seri || md.indexOf(anahtar(a.seri)) < 0)) { return false; } }
        if (y1 && (a.yil || 0) < y1) { return false; }
        if (y2 && (a.yil || 9999) > y2) { return false; }
        if (km && (a.km || 0) > km) { return false; }
        if (bmax && (!a.fiyat || a.fiyat > bmax)) { return false; }
        if (vt && a.vites && (vt === "otomatik" ? a.vites === "manuel" : a.vites !== vt)) { return false; }
        if (yk.length && yk.indexOf(a.yakit) < 0 && !(a.yakit === "lpg" && yk.indexOf("benzin") > -1)) { return false; }
        if (ks && a.kasa && a.kasa !== ks && !((ks === "suv" || ks === "crossover") && (a.kasa === "suv" || a.kasa === "crossover"))) { return false; }
        var hz = a.hasar || {};
        if (hs === "agirsiz" && hz.agir_hasar) { return false; }
        if ((hs === "degisensiz" || hs === "hatasiz") && cokDilli(hz.degisen)) { return false; }
        if (hs === "hatasiz" && (cokDilli(hz.boya) || cokDilli(hz.lokal))) { return false; }
        return true;
      });
    }
    function stokCiz() {
      var kutu = $("[data-siparis-stok]", form), l = [];
      try { l = gonderildi ? [] : sirala(uygunAraclar(), "yeni"); } catch (e) { l = []; }
      kutu.hidden = !l.length;
      if (!l.length) { kutu.innerHTML = ""; return; }
      kutu.innerHTML = "<p><b>" + kacis(t("sayfalar.siparis_stokta", { n: l.length })) + "</b></p><ul>" +
        l.slice(0, 4).map(function (a) {
          return '<li><a href="' + kacis(aracLink(a)) + '" target="_blank" rel="noopener">' + kacis(aracTamAd(a)) + "</a> <small>" +
            kacis([a.km != null ? sayi(a.km) + " km" : "", a.fiyat ? para(a.fiyat) : ""].filter(Boolean).join(" · ")) + "</small></li>";
        }).join("") + "</ul><p class=\"alan-ipucu\">" + kacis(t("sayfalar.siparis_stokta_alt")) + "</p>";
    }

    /* ---- sunucu kaydı (dil bağımsız anahtarlar; panel Türkçe gösterir) */
    function kayitVerisi(no) {
      return {
        no: no, dil: DIL,
        marka: S.marka.value === FARK ? "" : secimYazi("marka"), model: S.model.value === FARK ? "" : secimYazi("model"),
        elle: ["marka", "model"].filter(function (a) { return S[a].value === ELLE; }),
        yil_min: deger("yil_min"), yil_max: deger("yil_max"), km_max: rakam(deger("km_max")), kasa: form.elements.kasa.value,
        vites: secili("vites")[0] || "", yakit: secili("yakit"), hasar: secili("hasar")[0] || "",
        renk: deger("renk"), istekler: deger("istekler"),
        butce_min: rakam(deger("butce_min")), butce_max: rakam(deger("butce_max")), odeme: secili("odeme"),
        takas_arac: secili("odeme").indexOf("takas") > -1 ? deger("takas_arac") : "", zaman: secili("zaman")[0] || "",
        ad: deger("ad"), tel: deger("tel"), sehir: deger("sehir"), ulasim: secili("ulasim")[0] || "", not: deger("not"),
        web_adresi: deger("web_adresi")
      };
    }

    /* ---- adımlar */
    function git(n) {
      adim = Math.max(1, Math.min(TOPLAM, n));
      $$("[data-adim]", form).forEach(function (f) { f.hidden = Number(f.dataset.adim) !== adim; });
      $$("[data-adim-gosterge]", form).forEach(function (li) {
        var k = Number(li.dataset.adimGosterge);
        li.classList.toggle("tamam", k < adim || gonderildi);
        if (k === adim) { li.setAttribute("aria-current", "step"); } else { li.removeAttribute("aria-current"); }
      });
      var sayac = $("[data-adim-sayac]", form);
      if (sayac) { sayac.textContent = t("sayfalar.teklif_adim_sayac", { n: adim, toplam: TOPLAM }) + " · " + t("sayfalar.siparis_adim" + adim); }
      geri.hidden = adim === 1 || gonderildi;
      ileri.hidden = adim === TOPLAM;
      gonder.hidden = adim !== TOPLAM || gonderildi;
      yeni.hidden = !gonderildi;
      $("[data-ozet-alt]", form).hidden = gonderildi;
      hataYazi.hidden = true;
      if (adim === TOPLAM) { ozetCiz(); }
      var ust = form.getBoundingClientRect().top;
      if (ust < 0 || ust > window.innerHeight * 0.6) { form.scrollIntoView({ behavior: "smooth", block: "start" }); }
    }
    ileri.addEventListener("click", function () { if (dogrula(adim)) { git(adim + 1); } });
    geri.addEventListener("click", function () { git(adim - 1); });
    yeni.addEventListener("click", function () {
      gonderildi = false; form.reset(); tesekkur.hidden = true; takasGoster();
      E.marka.hidden = true; E.model.hidden = true;
      doldurMarka(); doldurModel(); doldurYillar(); git(1);
    });
    form.addEventListener("keydown", function (e) {   // Enter formu göndermesin, sonraki adıma geçsin
      if (e.key === "Enter" && e.target.tagName !== "TEXTAREA" && e.target.type !== "submit" && e.target.tagName !== "A") { e.preventDefault(); if (adim < TOPLAM) { ileri.click(); } }
    });

    /* ---- gönder: sunucuya kayıt (10 sn sınır) → WhatsApp. Kayıt olmazsa müşteriye WhatsApp'ı göndermesi açıkça söylenir. */
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (adim !== TOPLAM || gonderildi || gonder.disabled) { return; }
      for (var n = 1; n < TOPLAM; n++) { if (!dogrula(n)) { git(n); dogrula(n); return; } }
      var no = "S" + noUret();
      var pencere = null;
      try { pencere = window.open("", "_blank"); } catch (x) { pencere = null; }   // tıklama anında açılır; sonra açılırsa engellenir
      if (pencere) { try { pencere.opener = null; pencere.document.title = "WhatsApp"; pencere.document.body.innerHTML = '<p style="font:16px system-ui;padding:24px">' + kacis(t("sayfalar.siparis_gonderiliyor")) + "</p>"; } catch (x) { /* farklı köken */ } }
      var eski = gonder.innerHTML; gonder.disabled = true; gonder.textContent = t("sayfalar.siparis_gonderiliyor");
      var url = wa(mesajSatirlari(no).join("\n"));
      function kaydet() {
        if (!form.dataset.kaydet || !window.fetch) { return Promise.resolve(false); }
        var iptal = window.AbortController ? new AbortController() : null, sure = setTimeout(function () { if (iptal) { iptal.abort(); } }, 10000);
        return fetch(form.dataset.kaydet, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify((function () { var v = kayitVerisi(no); v.wa_mesaj = mesajSatirlari(no).join("\n"); return v; })()), signal: iptal ? iptal.signal : undefined })
          .then(function (r) { clearTimeout(sure); return r.json().catch(function () { return {}; }); })
          .then(function (j) { return !!(j && j.ok); }, function () { clearTimeout(sure); return false; });
      }
      kaydet().catch(function () { return false; }).then(function (kayitli) {
        gonder.disabled = false; gonder.innerHTML = eski;
        gonderildi = true;
        $("[data-tesekkur-metin]", form).textContent = t(kayitli ? "sayfalar.siparis_tesekkur" : "sayfalar.siparis_tesekkur_wa", { no: no });
        waLink.href = url;
        tesekkur.hidden = false;
        git(TOPLAM);
        if (pencere && !pencere.closed) { try { pencere.location.href = url; } catch (x) { location.href = url; } } else if (!kayitli) { location.href = url; }
      });
    });

    git(1);
    /* test için (konsoldan): window.__siparis.mesaj() */
    window.__siparis = { mesaj: function () { return mesajSatirlari("TEST").join("\n"); }, kayit: function () { return kayitVerisi("TEST"); }, uygun: uygunAraclar };
  }

  /* ---------- ölçülü animasyonlar: bölümler ve kartlar ekrana girerken yumuşakça belirir.
     Hareket azaltma tercihi açıksa veya tarayıcı desteklemiyorsa hiç çalışmaz (içerik hep görünür). */
  function animasyonlar() {
    var ust = $("#ust");
    if (ust) {
      var golge = function () { ust.classList.toggle("kaydirildi", window.scrollY > 8); };
      golge(); window.addEventListener("scroll", golge, { passive: true });
    }
    if (!("IntersectionObserver" in window) || !window.matchMedia || window.matchMedia("(prefers-reduced-motion: reduce)").matches) { return; }
    var SECICI = ".bolum-bas, .arac-kart, .ilke, .adimlar li, .yorumlar, .yazi-kart, .hizmet, .deger, .ekip-kisi, .kredi-bant, " +
      ".guven-oge, .detay-bilgi, .detay-hasar, .detay-aciklama, .kart-form, .yan-bilgi section, .iletisim-bant-ic > *, .harita, .kredi-sonuc, .makale > *";
    document.documentElement.classList.add("anim");
    var gozcu = new IntersectionObserver(function (girdiler) {
      girdiler.forEach(function (g) {
        if (!g.isIntersecting) { return; }
        var el = g.target; gozcu.unobserve(el);
        el.classList.add("gorundu");
        /* belirme bitince sınıfları kaldır: üzerine gelme (hover) efektleri normal çalışsın */
        setTimeout(function () { el.classList.remove("gor", "gorundu"); el.style.removeProperty("--gec"); }, 1200);
      });
    }, { rootMargin: "0px 0px -40px 0px", threshold: 0.08 });
    function hazirla(kok) {
      var liste = (kok.matches && kok.matches(SECICI)) ? [kok] : [];
      liste = liste.concat($$(SECICI, kok));
      liste.forEach(function (el) {
        if (el.classList.contains("gor") || el.dataset.anim) { return; }
        el.dataset.anim = "1";
        var kardesler = el.parentElement ? Array.prototype.indexOf.call(el.parentElement.children, el) : 0;
        el.style.setProperty("--gec", (Math.min(kardesler, 5) * 70) + "ms");   /* yan yana öğeler sırayla */
        el.classList.add("gor");
        gozcu.observe(el);
      });
    }
    hazirla(document.body);
    /* araç kartları JS ile sonradan eklendiği için (vitrin, liste, filtre) yeni öğeleri de yakala */
    new MutationObserver(function (degisim) {
      degisim.forEach(function (d) { d.addedNodes.forEach(function (n) { if (n.nodeType === 1) { hazirla(n); } }); });
    }).observe(document.getElementById("icerik") || document.body, { childList: true, subtree: true });
  }

  document.addEventListener("DOMContentLoaded", function () {
    markaSecicileri(); arayuz(); anasayfa(); liste(); detay(); kredi(); formlar(); teklifFormu(); siparisFormu(); waTiklamalari(); aramaPenceresi(); animasyonlar();
  });
})();
