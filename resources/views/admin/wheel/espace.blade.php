{{--
  ESPACE COMMERÇANT DE LA ROUE — [2026-11-19 · propriétaire]

  « Je veux une interface, un accès privé seul : tout le tableau de bord, la roue, la gestion des
  produits, les avis, les liens des réseaux sociaux — modifiable et fonctionnel à 100 %. »

  Une seule page, autonome (hors de l'application Vue de l'administration), derrière la même porte
  que les écrans de comptoir (`wheel.access`). Toutes les données viennent de
  `WheelMerchantController` ; cette page ne calcule ni probabilité qui compte, ni coût, ni droit :
  le serveur décide, la page affiche et envoie.

  Sécurité de l'affichage : tout texte venant de la base passe par `esc()` ou `textContent`. Rien
  n'est injecté en HTML brut.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex,nofollow">
<title>Espace roue — Le Cayenne</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Barlow+Semi+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap">
<style>
:root{
  --ink:#1A1A1A;--ink-soft:#5E5852;--paper:#FBF7F1;--line:#E8DFD3;--white:#fff;
  --brand:#F4501E;--brand-btn:#D9400F;--brand-dark:#B8360C;--brand-soft:#FDE8DF;
  --gold:#FFB800;--gold-soft:#FFF4D6;--gold-ink:#7A5200;
  --ok:#0B7A4B;--ok-soft:#E2F3EA;--bad:#B42A2F;--bad-soft:#FBE9E9;--warn:#8F5300;--warn-soft:#FFF1D9;
  --display:"Barlow Semi Condensed","Arial Narrow",system-ui,sans-serif;
  --sans:"Inter",system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  --r:16px;
}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{background:var(--paper);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.5;-webkit-font-smoothing:antialiased;min-height:100dvh}
h1,h2,h3{font-family:var(--display);letter-spacing:-.005em;line-height:1.15}
button,input,select,textarea{font:inherit;color:inherit}
button{cursor:pointer;background:none;border:0}
a{color:inherit}
:focus-visible{outline:2px solid var(--brand);outline-offset:2px;border-radius:6px}
.num{font-variant-numeric:tabular-nums}
.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}

/* ── Charpente ─────────────────────────────────────────────── */
.side{position:fixed;inset:0 auto 0 0;width:256px;background:var(--ink);color:#fff;display:flex;flex-direction:column;z-index:30;transform:translateX(-100%);transition:transform .2s}
.side.open{transform:none}
.scrim{position:fixed;inset:0;background:rgba(26,26,26,.5);z-index:25;display:none}
.scrim.open{display:block}
@media(min-width:1024px){.side{transform:none}.scrim{display:none!important}.main{padding-left:256px}.burger{display:none!important}}
.brandbar{display:flex;align-items:center;gap:10px;padding:20px 18px 16px}
.logo{width:38px;height:38px;border-radius:10px;background:var(--brand);display:grid;place-items:center;font-family:var(--display);font-weight:800;font-size:20px;color:#fff;flex:none}
.brandbar b{display:block;font-family:var(--display);font-size:19px;font-weight:800;letter-spacing:.02em}
.brandbar small{display:block;font-size:12px;color:rgba(255,255,255,.55)}
.nav{flex:1;overflow-y:auto;padding:6px 10px 12px}
.nav h4{font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.4);padding:14px 10px 6px;font-weight:600}
.nav a{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;text-decoration:none;color:rgba(255,255,255,.78);font-size:14.5px;font-weight:500}
.nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.nav a[aria-current=page]{background:rgba(244,80,30,.18);color:#fff;box-shadow:inset 3px 0 0 var(--brand)}
.nav a .ic{width:20px;text-align:center;flex:none}
.nav a .badge{margin-left:auto;background:var(--gold);color:var(--ink);font-size:12px;font-weight:700;border-radius:999px;padding:0 7px;min-width:22px;text-align:center}
.sidefoot{border-top:1px solid rgba(255,255,255,.1);padding:12px 10px;display:grid;gap:4px}
.sidefoot a,.sidefoot button{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:10px;color:rgba(255,255,255,.75);font-size:14px;text-decoration:none;width:100%;text-align:left}
.sidefoot a:hover,.sidefoot button:hover{background:rgba(255,255,255,.08);color:#fff}

.top{position:sticky;top:0;z-index:20;background:rgba(251,247,241,.92);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.topin{max-width:1200px;margin:0 auto;padding:12px 16px;display:flex;align-items:center;gap:12px}
.burger{width:40px;height:40px;border-radius:10px;border:1px solid var(--line);background:#fff;display:grid;place-items:center;font-size:18px}
.top h1{font-size:24px;font-weight:800;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wrap{max-width:1200px;margin:0 auto;padding:20px 16px 120px}
@media(min-width:1024px){.topin,.wrap{padding-left:32px;padding-right:32px}}

/* ── Éléments ──────────────────────────────────────────────── */
.card{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:20px}
.card.flush{padding:0}
.card h2{font-size:20px;font-weight:700;margin-bottom:4px}
.card .sub{color:var(--ink-soft);font-size:14px;margin-bottom:14px}
.grid{display:grid;gap:16px}
.g2{grid-template-columns:1fr}.g3{grid-template-columns:1fr}.g4{grid-template-columns:repeat(2,minmax(0,1fr))}
@media(min-width:720px){.g2{grid-template-columns:repeat(2,minmax(0,1fr))}.g3{grid-template-columns:repeat(3,minmax(0,1fr))}}
.split{display:grid;gap:16px;grid-template-columns:1fr;align-items:start}
@media(min-width:1100px){.g4{grid-template-columns:repeat(4,minmax(0,1fr))}.split{grid-template-columns:minmax(0,1fr) 360px}}
.sticky{position:sticky;top:84px}
.stack>*+*{margin-top:16px}
.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.between{justify-content:space-between}
.muted{color:var(--ink-soft)}
.small{font-size:13px}
.hello{margin-bottom:18px}
.hello p{color:var(--ink-soft)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:0 16px;border-radius:12px;font-weight:600;font-size:14.5px;text-decoration:none;border:1px solid transparent;white-space:nowrap;transition:filter .15s,background .15s}
.btn:active{transform:scale(.98)}
.btn[disabled]{opacity:.55;cursor:not-allowed}
.btn.primary{background:var(--brand-btn);color:#fff}.btn.primary:hover{background:var(--brand-dark)}
.btn.gold{background:var(--gold);color:var(--ink)}.btn.gold:hover{filter:brightness(.95)}
.btn.ghost{background:#fff;border-color:var(--line);color:var(--ink)}.btn.ghost:hover{background:var(--paper)}
.btn.dark{background:var(--ink);color:#fff}
.btn.danger{background:#fff;border-color:rgba(180,42,47,.35);color:var(--bad)}.btn.danger:hover{background:var(--bad-soft)}
.btn.sm{min-height:34px;padding:0 12px;font-size:13.5px;border-radius:10px}

.pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:3px 10px;font-size:12.5px;font-weight:600;border:1px solid transparent;white-space:nowrap}
.pill .dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.pill.ok{background:var(--ok-soft);color:var(--ok);border-color:rgba(11,122,75,.2)}
.pill.warn{background:var(--warn-soft);color:var(--warn);border-color:rgba(143,83,0,.2)}
.pill.bad{background:var(--bad-soft);color:var(--bad);border-color:rgba(180,42,47,.2)}
.pill.brand{background:var(--brand-soft);color:var(--brand-dark);border-color:rgba(244,80,30,.2)}
.pill.mute{background:rgba(26,26,26,.06);color:var(--ink-soft)}
.pill.gold{background:var(--gold-soft);color:var(--gold-ink)}

.banner{display:flex;gap:14px;align-items:flex-start;border-radius:var(--r);padding:16px 18px;border:1px solid;margin-bottom:16px;flex-wrap:wrap}
.banner .txt{flex:1;min-width:220px}
.banner b{display:block;font-size:15.5px}
.banner.ok{background:var(--ok-soft);border-color:rgba(11,122,75,.25)}
.banner.warn{background:var(--warn-soft);border-color:rgba(143,83,0,.25)}
.banner.bad{background:var(--bad-soft);border-color:rgba(180,42,47,.25)}

.kpi{background:#fff;border:1px solid var(--line);border-radius:var(--r);padding:16px 18px}
.kpi .l{font-size:13px;color:var(--ink-soft);font-weight:500}
.kpi .v{font-family:var(--display);font-size:34px;font-weight:800;line-height:1.1;margin-top:4px}
.kpi .h{font-size:12.5px;color:var(--ink-soft);margin-top:2px}
.kpi.accent{background:var(--ink);color:#fff;border-color:var(--ink)}
.kpi.accent .l,.kpi.accent .h{color:rgba(255,255,255,.65)}
.kpi.accent .v{color:var(--gold)}

.seg{display:inline-flex;background:rgba(26,26,26,.06);border-radius:12px;padding:3px}
.seg button{padding:6px 12px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--ink-soft)}
.seg button[aria-pressed=true]{background:#fff;color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.1)}

label.f{display:block;font-size:13.5px;font-weight:600;margin-bottom:6px}
.hint{display:block;font-size:12.5px;color:var(--ink-soft);font-weight:400;margin-top:4px;line-height:1.45}
.in{width:100%;min-height:44px;border:1px solid var(--line);background:#fff;border-radius:12px;padding:9px 12px;font-size:15px}
.in:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(244,80,30,.15)}
.in.n{max-width:120px}
select.in{appearance:auto}
.field+.field{margin-top:14px}
.inline{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
.check{display:flex;gap:10px;align-items:flex-start;font-size:14.5px}
.check input{width:20px;height:20px;accent-color:var(--brand-btn);flex:none;margin-top:1px}
.toggle{position:relative;width:52px;height:30px;border-radius:999px;background:#CFC6BA;flex:none;transition:background .15s}
.toggle::after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .15s}
.toggle[aria-checked=true]{background:var(--ok)}
.toggle[aria-checked=true]::after{transform:translateX(22px)}

.list{list-style:none}
.list>li{display:flex;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid var(--line)}
.list>li:last-child{border-bottom:0}
.thumb{width:44px;height:44px;border-radius:10px;object-fit:cover;background:var(--paper);border:1px solid var(--line);flex:none}
.thumb.ph{display:grid;place-items:center;font-size:20px}
.grow{flex:1;min-width:0}
.trunc{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar{height:8px;border-radius:999px;background:rgba(26,26,26,.08);overflow:hidden}
.bar>i{display:block;height:100%;background:var(--brand);border-radius:inherit}
.bar.gold>i{background:var(--gold)}
.bar.ok>i{background:var(--ok)}

table.t{width:100%;border-collapse:collapse;font-size:14px}
table.t th{text-align:left;font-size:12.5px;font-weight:600;color:var(--ink-soft);padding:10px 12px;border-bottom:1px solid var(--line);white-space:nowrap}
table.t td{padding:11px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
table.t tr:last-child td{border-bottom:0}
.tablewrap{overflow-x:auto}

/* Lot (ticket) */
.lotcard{background:#fff;border:1px solid var(--line);border-radius:18px;overflow:hidden}
.lothead{display:flex;gap:12px;align-items:center;padding:14px 16px}
.lotbody{border-top:2px dashed var(--line);padding:14px 16px;position:relative}
.lotbody::before,.lotbody::after{content:"";position:absolute;top:-11px;width:20px;height:20px;border-radius:50%;background:var(--paper);border:1px solid var(--line)}
.lotbody::before{left:-11px;clip-path:inset(0 0 0 50%)}
.lotbody::after{right:-11px;clip-path:inset(0 50% 0 0)}
.lotcard.arch{opacity:.7}
.swatch{display:inline-block;vertical-align:middle;width:14px;height:14px;border-radius:4px;flex:none;border:1px solid rgba(0,0,0,.1)}

.wheelbox{display:grid;place-items:center;padding:8px 0}
.wheelbox svg{width:100%;max-width:340px;height:auto;filter:drop-shadow(0 12px 24px rgba(26,26,26,.18))}
.legend{list-style:none;display:grid;gap:6px;font-size:13.5px}
.legend li{display:flex;align-items:center;gap:8px}

.chart svg{width:100%;height:auto;display:block}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{border:1px solid var(--line);background:#fff;border-radius:999px;padding:6px 12px;font-size:13.5px;font-weight:600;color:var(--ink-soft)}
.chip[aria-pressed=true]{background:var(--ink);border-color:var(--ink);color:#fff}
.chip .c{opacity:.7;margin-left:4px}

.empty{text-align:center;padding:36px 16px;color:var(--ink-soft)}
.empty b{display:block;color:var(--ink);font-size:16px;margin-bottom:4px}

.publish{position:fixed;left:0;right:0;bottom:0;z-index:35;background:var(--ink);color:#fff;transform:translateY(110%);visibility:hidden;transition:transform .2s,visibility .2s}
.publish.show{transform:none;visibility:visible}
.publish .in2{max-width:1200px;margin:0 auto;padding:12px 16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap}
@media(min-width:1024px){.publish{left:256px}.publish .in2{padding-left:32px;padding-right:32px}}
.publish .msg{flex:1;min-width:200px}
.publish .msg b{display:block}
.publish .msg span{font-size:13px;color:rgba(255,255,255,.65)}

.toasts{position:fixed;right:16px;bottom:16px;z-index:60;display:grid;gap:8px;max-width:min(92vw,420px)}
.toast{background:var(--ink);color:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 10px 25px rgba(0,0,0,.2);font-size:14px;animation:tin .2s ease-out}
.toast.ok{border-left:4px solid #3BD38A}.toast.err{border-left:4px solid #FF6B6B}
@keyframes tin{from{transform:translateY(8px);opacity:0}to{transform:none;opacity:1}}

.modal{position:fixed;inset:0;z-index:50;background:rgba(26,26,26,.5);display:none;align-items:flex-end;justify-content:center;padding:0}
.modal.open{display:flex}
@media(min-width:640px){.modal{align-items:center;padding:24px}}
.dialog{background:#fff;width:100%;max-width:480px;border-radius:20px 20px 0 0;padding:22px;max-height:92vh;overflow:auto;animation:pop .18s ease-out}
@media(min-width:640px){.dialog{border-radius:20px}}
.dialog h3{font-size:22px;margin-bottom:6px}
.dialog .acts{display:flex;gap:10px;justify-content:flex-end;margin-top:18px;flex-wrap:wrap}
@keyframes pop{from{transform:scale(.97);opacity:0}to{transform:none;opacity:1}}

.phone{background:var(--ink);border-radius:28px;padding:10px;max-width:260px;margin:0 auto}
.phone .scr{background:var(--paper);border-radius:20px;padding:16px 14px;min-height:300px}
.phone .stp{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid var(--line);border-radius:12px;padding:10px;margin-top:8px;font-size:13px}
.phone .stp i{width:24px;height:24px;border-radius:50%;background:var(--brand);color:#fff;font-style:normal;font-weight:700;display:grid;place-items:center;font-size:12px;flex:none}
.phone .stp.off{opacity:.45}

.steps4{counter-reset:s;list-style:none;display:grid;gap:12px}
.steps4 li{display:flex;gap:12px;align-items:flex-start}
.steps4 li::before{counter-increment:s;content:counter(s);width:28px;height:28px;border-radius:50%;background:var(--brand-soft);color:var(--brand-dark);font-weight:800;display:grid;place-items:center;flex:none}

.skeleton{color:var(--ink-soft);padding:40px 0;text-align:center}
.err-inline{color:var(--bad);font-size:13.5px;margin-top:6px}
[hidden]{display:none!important}
@media(max-width:480px){.top .pill{display:none}.top h1{font-size:21px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<a class="sr" href="#contenu">Aller au contenu</a>

<aside class="side" id="side" aria-label="Navigation de l'espace roue">
  <div class="brandbar">
    <div class="logo" aria-hidden="true">C</div>
    <div><b>LE CAYENNE</b><small>Espace roue · privé</small></div>
  </div>
  <nav class="nav" id="nav">
    <h4>Pilotage</h4>
    <a href="#tableau" data-v="tableau"><span class="ic" aria-hidden="true">◧</span>Tableau de bord</a>
    <a href="#roue" data-v="roue"><span class="ic" aria-hidden="true">◎</span>Ma roue</a>
    <a href="#cadeaux" data-v="cadeaux"><span class="ic" aria-hidden="true">🎁</span>Mes cadeaux</a>
    <a href="#avis" data-v="avis"><span class="ic" aria-hidden="true">★</span>Avis &amp; réseaux</a>
    <h4>Au comptoir</h4>
    <a href="#comptoir" data-v="comptoir"><span class="ic" aria-hidden="true">⌂</span>Comptoir</a>
    <a href="#lots" data-v="lots"><span class="ic" aria-hidden="true">✓</span>Lots gagnés<span class="badge" id="badgeDus" hidden></span></a>
    <h4>Suivi</h4>
    <a href="#stats" data-v="stats"><span class="ic" aria-hidden="true">▤</span>Statistiques</a>
    <a href="#reglages" data-v="reglages"><span class="ic" aria-hidden="true">⚙</span>Réglages du jeu</a>
  </nav>
  <div class="sidefoot">
    <a href="/admin/pos" data-testid="esp-retour-caisse"><span aria-hidden="true">←</span>Retour à la caisse</a>
    <form method="post" action="{{ route('admin.wheel.lock') }}">
      @csrf
      <button type="submit" data-testid="esp-fermer"><span aria-hidden="true">🔒</span>Fermer l'accès</button>
    </form>
  </div>
</aside>
<div class="scrim" id="scrim"></div>

<div class="main">
  <header class="top">
    <div class="topin">
      <button class="burger" id="burger" aria-label="Ouvrir le menu" aria-controls="side" aria-expanded="false">☰</button>
      <h1 id="titre">Tableau de bord</h1>
      <span id="etatJeu" class="pill mute" data-testid="esp-etat-jeu">…</span>
    </div>
  </header>

  <main class="wrap" id="contenu" tabindex="-1">
    <div class="skeleton" id="chargement">Chargement de votre espace…</div>

    {{-- ── TABLEAU DE BORD ── --}}
    <section data-view="tableau" hidden>
      <div class="hello">
        <h2 style="font-size:28px;font-weight:800">Bonjour 👋</h2>
        <p>Voici ce qui s'est passé à la roue de votre établissement.</p>
      </div>
      <div id="bannerJeu"></div>
      <div class="row between" style="margin-bottom:12px">
        <h3 style="font-size:18px">En chiffres</h3>
        <div class="seg" role="group" aria-label="Période" id="segPeriode">
          <button data-p="aujourdhui" aria-pressed="false">Aujourd'hui</button>
          <button data-p="semaine" aria-pressed="true">7 jours</button>
          <button data-p="mois" aria-pressed="false">30 jours</button>
        </div>
      </div>
      <div class="grid g4" id="kpis"></div>
      <div class="split" style="margin-top:16px">
        <div class="stack">
          <div class="card">
            <div class="row between"><h2>Parties et cadeaux remis</h2><button class="btn ghost sm" id="btnVueTable" aria-pressed="false">Voir le tableau</button></div>
            <p class="sub">Sur les 30 derniers jours, jour par jour.</p>
            <div class="chart" id="chart"></div>
            <div class="tablewrap" id="chartTable" hidden></div>
          </div>
          <div class="card">
            <div class="row between"><h2>Vos cadeaux en ce moment</h2><a class="btn ghost sm" href="#cadeaux">Gérer</a></div>
            <p class="sub">Ce qui reste à gagner sur la campagne en cours.</p>
            <ul class="list" id="resteLots"></ul>
          </div>
        </div>
        <div class="stack">
          <div class="card">
            <h2>Raccourcis</h2>
            <div class="grid" style="margin-top:10px">
              <a class="btn primary" href="/admin/roue-borne" target="_blank" rel="noopener">Ouvrir l'écran du comptoir</a>
              <a class="btn ghost" href="#lots">Remettre un lot</a>
              <a class="btn ghost" href="#roue">Modifier la roue</a>
            </div>
          </div>
          <div class="card">
            <h2>Derniers gagnants</h2>
            <ul class="list" id="derniers"></ul>
          </div>
          <div class="card" id="cardAlertes" hidden>
            <h2>Points d'attention</h2>
            <ul class="list" id="alertes"></ul>
          </div>
        </div>
      </div>
    </section>

    {{-- ── MA ROUE ── --}}
    <section data-view="roue" hidden>
      <div class="split">
        <div class="stack">
          <div class="card">
            <h2>Réglez les chances de gagner</h2>
            <p class="sub">La chance de chaque cadeau est calculée sur le total. Mettez <b>0</b> pour l'afficher sans jamais le faire gagner (un cadeau « vitrine »).</p>
            <div class="tablewrap">
              <table class="t" aria-label="Chances de chaque cadeau">
                <thead><tr><th></th><th>Texte sur la roue</th><th>Cadeau</th><th>Poids</th><th class="num">Chance</th></tr></thead>
                <tbody id="tChances"></tbody>
              </table>
            </div>
          </div>
          <div class="card">
            <h2>Comment ça marche</h2>
            <ul class="steps4" style="margin-top:10px">
              <li><span>Le <b>serveur</b> tire au sort à chaque partie : personne ne peut tricher depuis son téléphone.</span></li>
              <li><span>Un cadeau <b>épuisé</b>, au <b>plafond du jour</b> ou <b>en rupture</b> disparaît tout seul de la roue.</span></li>
              <li><span>Les changements sont visibles par vos clients dès que vous cliquez sur <b>Publier la roue</b>.</span></li>
            </ul>
          </div>
        </div>
        <div class="card sticky">
          <div class="row between"><h2>Aperçu en direct</h2><span class="pill brand" id="pillBrouillon" hidden>Brouillon</span></div>
          <p class="sub">Ce que vos clients voient.</p>
          <div class="wheelbox" id="wheelPreview"></div>
          <ul class="legend" id="legend"></ul>
        </div>
      </div>
    </section>

    {{-- ── MES CADEAUX ── --}}
    <section data-view="cadeaux" hidden>
      <div class="row between" style="margin-bottom:14px">
        <p class="muted" style="max-width:640px">Chaque cadeau est un <b>vrai produit de votre carte</b> (ou des points, ou une remise). Le stock et le coût suivent ce produit quand vous le remettez.</p>
        <button class="btn primary" id="btnAjout" data-testid="esp-ajout-cadeau">+ Ajouter un cadeau</button>
      </div>
      <div id="warnRemises"></div>
      <div class="grid g2" id="cadeauxActifs"></div>
      <details class="card" style="margin-top:16px" id="blocArchives" hidden>
        <summary style="cursor:pointer;font-weight:700">Cadeaux retirés de la roue <span class="muted" id="nbArchives"></span></summary>
        <p class="sub" style="margin-top:8px">Ils ne sont plus ni tirés ni affichés, mais ceux qui les ont déjà gagnés peuvent toujours les recevoir.</p>
        <div class="grid g2" id="cadeauxArchives"></div>
      </details>
    </section>

    {{-- ── AVIS & RÉSEAUX ── --}}
    <section data-view="avis" hidden>
      <form id="formLiens" novalidate>
        <div class="split">
          <div class="stack">
            <div class="card">
              <h2>Votre fiche Google</h2>
              <p class="sub">Le lien qui ouvre directement le formulaire d'avis. Dans Google Business Profile : « Demander des avis », puis copiez le lien.</p>
              <div class="field">
                <label class="f" for="review_url">Lien vers vos avis Google <span id="st_review_url"></span></label>
                <div class="row" style="flex-wrap:nowrap">
                  <input class="in" type="url" id="review_url" name="review_url" placeholder="https://g.page/r/…/review" autocomplete="off">
                  <a class="btn ghost sm" id="test_review_url" target="_blank" rel="noopener noreferrer">Tester</a>
                </div>
                <span class="hint" id="hint_review"></span>
              </div>
              <div class="banner warn" style="margin:16px 0 0">
                <div class="txt"><b>Ce que la plateforme garantit</b><span class="small">Aucune note minimale n'est suggérée, aucun avis n'est vérifié : le client gagne sa partie qu'il ait aimé ou non. Ne demandez pas un avis positif : l'avis doit rester libre.</span></div>
              </div>
            </div>
            <div class="card">
              <h2>Vos réseaux sociaux</h2>
              <p class="sub">Laissez vide un réseau que vous n'utilisez pas : il ne sera pas proposé au client.</p>
              <div class="field">
                <label class="f" for="instagram_url">Instagram <span id="st_instagram_url"></span></label>
                <div class="row" style="flex-wrap:nowrap"><input class="in" type="url" id="instagram_url" name="instagram_url" placeholder="https://www.instagram.com/votrecompte" autocomplete="off"><a class="btn ghost sm" id="test_instagram_url" target="_blank" rel="noopener noreferrer">Tester</a></div>
              </div>
              <div class="field">
                <label class="f" for="snapchat_url">Snapchat <span id="st_snapchat_url"></span></label>
                <div class="row" style="flex-wrap:nowrap"><input class="in" type="url" id="snapchat_url" name="snapchat_url" placeholder="https://www.snapchat.com/add/votrecompte" autocomplete="off"><a class="btn ghost sm" id="test_snapchat_url" target="_blank" rel="noopener noreferrer">Tester</a></div>
              </div>
              <div class="field">
                <label class="f" for="facebook_url">Facebook <span id="st_facebook_url"></span></label>
                <div class="row" style="flex-wrap:nowrap"><input class="in" type="url" id="facebook_url" name="facebook_url" placeholder="https://www.facebook.com/votrepage" autocomplete="off"><a class="btn ghost sm" id="test_facebook_url" target="_blank" rel="noopener noreferrer">Tester</a></div>
              </div>
            </div>
            <div class="card">
              <h2>Le parcours avant la roue</h2>
              <p class="sub">Ce que le client fait avant de pouvoir tourner la roue.</p>
              <label class="check"><input type="checkbox" id="review_required" name="review_required"><span><b>Proposer de laisser un avis</b><span class="hint">Le client ouvre votre fiche Google avant de jouer.</span></span></label>
              <div class="field" style="margin-left:30px"><label class="f" for="review_dwell">Temps minimum sur la page d'avis (secondes)</label><input class="in n num" type="number" min="0" max="180" id="review_dwell" name="review_dwell"></div>
              <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
              <label class="check"><input type="checkbox" id="follow_required" name="follow_required"><span><b>Proposer de vous suivre</b><span class="hint">Le client ouvre un de vos réseaux avant de jouer.</span></span></label>
              <div class="field" style="margin-left:30px"><label class="f" for="follow_dwell">Temps minimum sur le réseau (secondes)</label><input class="in n num" type="number" min="0" max="180" id="follow_dwell" name="follow_dwell"></div>
              <hr style="border:0;border-top:1px solid var(--line);margin:16px 0">
              <div class="field"><label class="f" for="min_order">Minimum de commande pour jouer (€)</label><input class="in n num" type="number" min="0" max="200" step="0.5" id="min_order" name="min_order"><span class="hint">Le client joue après une commande d'au moins ce montant.</span></div>
            </div>
            <p class="err-inline" id="errLiens" role="alert"></p>
            <div class="row"><button type="submit" class="btn primary" id="btnLiens" data-testid="esp-save-liens">Enregistrer les liens</button></div>
          </div>
          <div class="card sticky">
            <h2>Aperçu côté client</h2>
            <p class="sub">Les étapes affichées sur le téléphone.</p>
            <div class="phone" aria-hidden="true"><div class="scr">
              <div style="text-align:center;font-family:var(--display);font-weight:800;font-size:20px">Tentez votre chance !</div>
              <div style="text-align:center;font-size:12px;color:var(--ink-soft)">Le Cayenne</div>
              <div id="phoneSteps"></div>
              <div class="stp" style="background:var(--brand);color:#fff;border-color:var(--brand);justify-content:center;font-weight:700">Tourner la roue</div>
            </div></div>
          </div>
        </div>
      </form>
    </section>

    {{-- ── COMPTOIR ── --}}
    <section data-view="comptoir" hidden>
      <div class="split">
        <div class="stack">
          <div class="card">
            <h2>Le jeu, du comptoir au cadeau</h2>
            <ol class="steps4" style="margin-top:12px">
              <li><span><b>Le client commande</b> (au moins <span id="cMin">…</span>).</span></li>
              <li><span><b>Il scanne le QR</b> de l'écran du comptoir — le QR se renouvelle seul, une photo partagée dehors ne vaut rien.</span></li>
              <li><span><b>Il joue sur son téléphone</b> : avis, réseaux, puis la roue. Le résultat est tiré par le serveur.</span></li>
              <li><span><b>Il revient avec son code</b> : vous lui remettez son cadeau dans « Lots gagnés ».</span></li>
            </ol>
          </div>
          <div class="grid g2">
            <div class="card">
              <h2>Écran du comptoir</h2>
              <p class="sub">La tablette face aux clients : la roue tourne et le QR se renouvelle tout seul.</p>
              <a class="btn primary" href="/admin/roue-borne" target="_blank" rel="noopener" data-testid="esp-ouvrir-borne">Ouvrir l'écran</a>
            </div>
            <div class="card">
              <h2>Valider le tour d'un client</h2>
              <p class="sub">Génère un QR à usage unique pour UN client (sans la tablette).</p>
              <a class="btn ghost" href="/admin/roue-validation" target="_blank" rel="noopener">Ouvrir la validation</a>
            </div>
            <div class="card">
              <h2>Remettre par numéro</h2>
              <p class="sub">Retrouver un client par son téléphone ou son code et lui remettre son cadeau.</p>
              <a class="btn ghost" href="#lots">Aller aux lots gagnés</a>
            </div>
            <div class="card">
              <h2>Historique détaillé</h2>
              <p class="sub">Tout ce qui a été gagné et remis, avec les auteurs.</p>
              <a class="btn ghost" href="/admin/roue-historique" target="_blank" rel="noopener">Ouvrir l'historique</a>
            </div>
          </div>
        </div>
        <div class="card sticky">
          <h2>Page du jeu</h2>
          <p class="sub">Adresse de la page que vos clients ouvrent en scannant le QR. Elle n'ouvre une partie qu'avec un QR valide émis au comptoir.</p>
          <div class="in" id="cUrl" style="display:flex;align-items:center;word-break:break-all;font-size:14px"></div>
          <div class="row" style="margin-top:10px"><button class="btn ghost sm" id="btnCopier">Copier le lien</button></div>
        </div>
      </div>
    </section>

    {{-- ── LOTS GAGNÉS ── --}}
    <section data-view="lots" hidden>
      <div class="card" style="margin-bottom:16px">
        <div class="row between">
          <div class="chips" id="filtres" role="group" aria-label="Filtrer les lots"></div>
          <div style="flex:1;min-width:220px;max-width:380px">
            <label class="sr" for="recherche">Rechercher</label>
            <input class="in" type="search" id="recherche" placeholder="Code, prénom, 4 derniers chiffres, cadeau…" autocomplete="off">
          </div>
        </div>
      </div>
      <div class="card flush">
        <div class="tablewrap">
          <table class="t" aria-label="Lots gagnés">
            <thead><tr><th>Gagné le</th><th>Cadeau</th><th>Client</th><th>Code</th><th>Statut</th><th></th></tr></thead>
            <tbody id="tLots"></tbody>
          </table>
        </div>
      </div>
      <p class="muted small" style="margin-top:10px">Les 90 derniers jours. Le numéro du client n'est jamais affiché en entier.</p>
    </section>

    {{-- ── STATISTIQUES ── --}}
    <section data-view="stats" hidden>
      <div class="grid g4" id="kpisStats"></div>
      <div class="grid g2" style="margin-top:16px">
        <div class="card">
          <h2>Par cadeau, campagne en cours</h2>
          <p class="sub">Gagnés, remis, et taux de retrait.</p>
          <div class="tablewrap"><table class="t"><thead><tr><th>Cadeau</th><th class="num">Gagnés</th><th class="num">Remis</th><th>Taux de retrait</th></tr></thead><tbody id="tParLot"></tbody></table></div>
        </div>
        <div class="card">
          <h2>Où les clients décrochent</h2>
          <p class="sub">Parties commencées mais pas terminées, 7 derniers jours.</p>
          <ul class="list" id="abandons"></ul>
        </div>
      </div>
      <div class="card" style="margin-top:16px">
        <h2>Parties et cadeaux remis</h2>
        <p class="sub">30 derniers jours.</p>
        <div class="chart" id="chart2"></div>
      </div>
    </section>

    {{-- ── RÉGLAGES DU JEU ── --}}
    <section data-view="reglages" hidden>
      <div class="grid g2">
        <div class="card">
          <div class="row between" style="flex-wrap:nowrap">
            <div><h2>Jeu ouvert au public</h2><p class="sub" style="margin:0">Fermé, seuls les tests en aperçu fonctionnent. Les lots déjà gagnés restent valables.</p></div>
            <button class="toggle" role="switch" id="tOuvert" aria-checked="false" aria-label="Jeu ouvert au public" data-testid="esp-toggle-ouvert"></button>
          </div>
        </div>
        <div class="card">
          <h2>Campagne en cours</h2>
          <p class="sub">Nouvelle campagne = les compteurs de cadeaux repartent à zéro et chaque client peut rejouer une fois. L'historique est conservé.</p>
          <div class="row between"><span class="pill mute num" id="campagne">…</span><button class="btn ghost sm" id="btnCampagne">Démarrer une nouvelle campagne</button></div>
        </div>
        <form class="card" id="formJeu" novalidate>
          <h2>Règles des lots</h2>
          <div class="inline" style="margin-top:10px">
            <div><label class="f" for="prize_validity_days">Validité d'un lot (jours)</label><input class="in num" type="number" min="1" max="365" id="prize_validity_days" name="prize_validity_days"><span class="hint">Après, le cadeau ne peut plus être remis.</span></div>
            <div><label class="f" for="daily_total_cap">Parties maximum par jour</label><input class="in num" type="number" min="1" max="100000" id="daily_total_cap" name="daily_total_cap"><span class="hint">Au-delà, la roue s’arrête jusqu’au lendemain.</span></div>
          </div>
          <p class="err-inline" id="errJeu" role="alert"></p>
          <div class="row" style="margin-top:14px"><button class="btn primary" type="submit" id="btnJeu">Enregistrer</button></div>
        </form>
        <div class="card">
          <h2>Accès à cet espace</h2>
          <ul class="list small" id="acces"></ul>
          <div class="row" style="margin-top:10px"><a class="btn ghost sm" href="/admin/roue?ecrans=1">Anciens écrans de la roue</a></div>
        </div>
      </div>
    </section>
  </main>
</div>

<div class="publish" id="publish" role="region" aria-label="Modifications non publiées">
  <div class="in2">
    <div class="msg"><b>Modifications non publiées</b><span>Vos clients voient encore l'ancienne roue.</span></div>
    <button class="btn ghost sm" id="btnAnnuler" style="background:transparent;color:#fff;border-color:rgba(255,255,255,.3)">Annuler</button>
    <button class="btn gold" id="btnPublier" data-testid="esp-publier">Publier la roue</button>
  </div>
</div>

<div class="modal" id="modal" role="dialog" aria-modal="true" aria-labelledby="mTitre">
  <div class="dialog">
    <h3 id="mTitre"></h3>
    <div id="mCorps"></div>
    <p class="err-inline" id="mErr" role="alert"></p>
    <div class="acts"><button class="btn ghost" id="mNon">Annuler</button><button class="btn primary" id="mOui">Confirmer</button></div>
  </div>
</div>

<div class="toasts" id="toasts" aria-live="polite"></div>

@verbatim
<script>
(function () {
  'use strict';

  var CSRF = document.querySelector('meta[name=csrf-token]').getAttribute('content');
  var BASE = '/admin/roue/espace';
  var COULEURS = [['#F4501E','#FFF6EC'],['#17140F','#FFB800'],['#FFB800','#3A1C00'],['#241A12','#FFD34D'],
                  ['#FF6A3D','#2A1508'],['#0F0D0A','#FFF6EC'],['#FFD34D','#3A1C00'],['#C93A12','#FFF6EC']];
  var TYPES = {free_item:'Produit offert', points:'Points de fidélité', coupon_percent:'Remise en %', coupon_fixed:'Remise en €'};
  var TITRES = {tableau:'Tableau de bord', roue:'Ma roue', cadeaux:'Mes cadeaux', avis:'Avis & réseaux',
                comptoir:'Comptoir', lots:'Lots gagnés', stats:'Statistiques', reglages:'Réglages du jeu'};

  var D = null;            // données serveur
  var brouillon = null;    // copie éditable des lots
  var sale = false;        // brouillon modifié ?
  var periode = 'semaine';
  var lotsGagnes = [];
  var filtre = 'du';
  var $ = function (id) { return document.getElementById(id); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function eur(n) { return (Number(n) || 0).toLocaleString('fr-FR', {style:'currency', currency:'EUR'}); }
  function nb(n) { return (Number(n) || 0).toLocaleString('fr-FR'); }
  function date(iso, heure) {
    if (!iso) return '';
    var d = new Date(iso);
    var o = {day:'2-digit', month:'2-digit'};
    if (heure) { o.hour = '2-digit'; o.minute = '2-digit'; }
    return d.toLocaleString('fr-FR', o);
  }
  function depuis(ts) {
    var s = Math.max(0, Math.round(Date.now() / 1000 - ts));
    if (s < 60) return 'à l’instant';
    if (s < 3600) return 'il y a ' + Math.round(s / 60) + ' min';
    if (s < 86400) return 'il y a ' + Math.round(s / 3600) + ' h';
    return 'il y a ' + Math.round(s / 86400) + ' j';
  }
  function safeUrl(u) { return /^https?:\/\//i.test(String(u || '')) ? String(u) : ''; }

  function toast(msg, type) {
    var t = document.createElement('div');
    t.className = 'toast ' + (type || 'ok');
    t.textContent = msg;
    $('toasts').appendChild(t);
    setTimeout(function () { t.remove(); }, type === 'err' ? 7000 : 4000);
  }

  function api(url, opts) {
    opts = opts || {};
    var init = {method: opts.method || 'GET', credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}};
    if (opts.body !== undefined) {
      init.headers['Content-Type'] = 'application/json';
      init.headers['X-CSRF-TOKEN'] = CSRF;
      init.body = JSON.stringify(opts.body);
    }
    return fetch(url, init).then(function (r) {
      if (r.status === 401 || r.status === 419) {
        toast('Votre accès a expiré. Entrez de nouveau le code.', 'err');
        setTimeout(function () { location.href = '/admin/roue'; }, 1200);
        throw new Error('locked');
      }
      return r.json().catch(function () { return {status: false, message: 'Réponse illisible du serveur (' + r.status + ').'}; })
        .then(function (j) { j.__ok = r.ok; return j; });
    });
  }

  // ── Modale ──────────────────────────────────────────────────
  var mAction = null, dernierFocus = null;
  function ouvrirModale(titre, corpsHtml, libelleOui, action, danger) {
    dernierFocus = document.activeElement;
    $('mTitre').textContent = titre;
    $('mCorps').innerHTML = corpsHtml;
    $('mErr').textContent = '';
    $('mOui').textContent = libelleOui || 'Confirmer';
    $('mOui').className = 'btn ' + (danger ? 'danger' : 'primary');
    $('mOui').disabled = false;
    mAction = action;
    $('modal').classList.add('open');
    var f = $('mCorps').querySelector('input');
    (f || $('mOui')).focus();
  }
  function fermerModale() { $('modal').classList.remove('open'); mAction = null; if (dernierFocus) dernierFocus.focus(); }
  $('mNon').addEventListener('click', fermerModale);
  $('modal').addEventListener('click', function (e) { if (e.target === $('modal')) fermerModale(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && $('modal').classList.contains('open')) fermerModale(); });
  $('mOui').addEventListener('click', function () {
    if (!mAction) return;
    $('mOui').disabled = true;
    Promise.resolve(mAction()).then(function (fermer) {
      if (fermer !== false) fermerModale(); else $('mOui').disabled = false;
    }).catch(function () { $('mOui').disabled = false; });
  });
  $('mCorps').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('mOui').click(); } });
  $('mCorps').addEventListener('input', function () { $('mErr').textContent = ''; });

  // ── Navigation ──────────────────────────────────────────────
  function vueCourante() { var h = (location.hash || '#tableau').slice(1); return TITRES[h] ? h : 'tableau'; }
  function montrer() {
    var v = vueCourante();
    document.querySelectorAll('section[data-view]').forEach(function (s) { s.hidden = s.getAttribute('data-view') !== v || !D; });
    document.querySelectorAll('#nav a').forEach(function (a) {
      if (a.getAttribute('data-v') === v) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    $('titre').textContent = TITRES[v];
    document.title = TITRES[v] + ' — Espace roue';
    fermerMenu();
    if (v === 'lots' && D) chargerLots();
  }
  window.addEventListener('hashchange', function () { montrer(); window.scrollTo(0, 0); });
  function fermerMenu() { $('side').classList.remove('open'); $('scrim').classList.remove('open'); $('burger').setAttribute('aria-expanded', 'false'); }
  $('burger').addEventListener('click', function () {
    $('side').classList.add('open'); $('scrim').classList.add('open'); $('burger').setAttribute('aria-expanded', 'true');
  });
  $('scrim').addEventListener('click', fermerMenu);
  window.addEventListener('beforeunload', function (e) { if (sale) { e.preventDefault(); e.returnValue = ''; } });

  // ── Chargement ──────────────────────────────────────────────
  function charger() {
    return api(BASE + '/donnees').then(function (j) {
      if (!j.status) { toast(j.message || 'Chargement impossible.', 'err'); return; }
      D = j.data;
      if (!sale) brouillon = JSON.parse(JSON.stringify(D.lots));
      $('chargement').hidden = true;
      rendreTout();
      montrer();
    }).catch(function (e) {
      if (e && e.message === 'locked') return;
      $('chargement').textContent = 'Connexion impossible. Vérifiez le réseau puis rechargez la page.';
    });
  }

  function rendreTout() {
    rendreEtat(); rendreTableau(); rendreRoue(); rendreCadeaux(); rendreLiens(); rendreComptoir(); rendreStats(); rendreReglages();
  }

  // ── État du jeu (en-tête + bandeau) ─────────────────────────
  function rendreEtat() {
    var j = D.jeu, p = $('etatJeu');
    p.className = 'pill ' + (j.ouvert ? 'ok' : 'warn');
    p.innerHTML = '<span class="dot"></span>' + (j.ouvert ? 'Jeu en ligne' : 'Jeu fermé au public');
    var dus = D.aRemettre || 0, b = $('badgeDus');
    b.hidden = dus <= 0; b.textContent = dus;
    var html;
    if (j.ouvert) {
      html = '<div class="banner ok"><div class="txt"><b>Votre roue est en ligne</b><span class="small">Vos clients peuvent jouer après une commande. ' +
        esc(nb(D.bilan.plafond_jour.utilise)) + ' partie(s) aujourd’hui' +
        (D.bilan.plafond_jour.plafond > 0 ? ' sur ' + esc(nb(D.bilan.plafond_jour.plafond)) + ' maximum' : '') + '.</span></div>' +
        '<button class="btn ghost sm" data-jeu="0">Suspendre le jeu</button></div>';
    } else {
      html = '<div class="banner warn"><div class="txt"><b>Votre roue est fermée au public</b><span class="small">Seuls les tests en aperçu fonctionnent. Ouvrez-la quand vos cadeaux et vos liens sont prêts.</span></div>' +
        '<button class="btn primary sm" data-jeu="1" data-testid="esp-ouvrir-jeu">Ouvrir le jeu</button></div>';
    }
    $('bannerJeu').innerHTML = html;
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-jeu]');
    if (!b) return;
    basculerJeu(b.getAttribute('data-jeu') === '1');
  });
  function basculerJeu(ouvrir) {
    ouvrirModale(ouvrir ? 'Ouvrir le jeu au public ?' : 'Suspendre le jeu ?',
      '<p class="muted">' + (ouvrir
        ? 'Vos clients pourront jouer dès maintenant, après une commande, en scannant le QR du comptoir.'
        : 'La roue disparaît pour vos clients. Les lots déjà gagnés restent valables et peuvent être remis.') + '</p>',
      ouvrir ? 'Ouvrir le jeu' : 'Suspendre', function () {
        return api(BASE + '/jeu', {method: 'POST', body: {ouvert: ouvrir}}).then(function (r) {
          if (!r.status) { $('mErr').textContent = r.message; return false; }
          toast(ouvrir ? 'Le jeu est ouvert à vos clients.' : 'Le jeu est suspendu.');
          return charger();
        });
      }, !ouvrir);
  }

  // ── Tableau de bord ─────────────────────────────────────────
  $('segPeriode').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-p]'); if (!b) return;
    periode = b.getAttribute('data-p');
    this.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
    rendreKpis();
  });
  function kpi(l, v, h, accent) {
    return '<div class="kpi' + (accent ? ' accent' : '') + '"><div class="l">' + esc(l) + '</div><div class="v num">' + esc(v) + '</div>' + (h ? '<div class="h">' + esc(h) + '</div>' : '') + '</div>';
  }
  function rendreKpis() {
    var p = D.bilan.periodes[periode];
    $('kpis').innerHTML =
      kpi('Parties jouées', nb(p.tours), p.libelle) +
      kpi('Cadeaux remis', nb(p.cadeaux_remis), p.cadeaux_dus ? nb(p.cadeaux_dus) + ' encore à remettre' : 'tous remis') +
      kpi('Lots à remettre', nb(D.aRemettre), 'encore valables, toutes périodes', true) +
      kpi('Valeur offerte', eur((p.valeur_offerte || 0) + (p.valeur_points || 0)), 'produits au prix carte + points');
  }
  function rendreTableau() {
    rendreKpis();
    $('chart').innerHTML = graphique(D.serie);
    $('chartTable').innerHTML = '<table class="t"><thead><tr><th>Jour</th><th class="num">Parties</th><th class="num">Remis</th></tr></thead><tbody>' +
      D.serie.slice().reverse().map(function (r) { return '<tr><td>' + esc(date(r.jour)) + '</td><td class="num">' + r.parties + '</td><td class="num">' + r.remis + '</td></tr>'; }).join('') + '</tbody></table>';
    var actifs = D.lots.filter(function (l) { return !l.archived; });
    $('resteLots').innerHTML = actifs.length ? actifs.map(function (l) {
      var pct = l.quantity > 0 ? Math.min(100, Math.round(100 * l.donnes / l.quantity)) : 0;
      return '<li>' + vignette(l) + '<div class="grow"><div class="row between"><b class="trunc">' + esc(l.label) + '</b><span class="small muted num">' +
        (l.quantity > 0 ? esc(nb(l.restant)) + ' / ' + esc(nb(l.quantity)) + ' restants' : 'illimité · ' + esc(nb(l.donnes)) + ' gagnés') + '</span></div>' +
        (l.quantity > 0 ? '<div class="bar' + (pct >= 90 ? '' : ' gold') + '" style="margin-top:6px"><i style="width:' + pct + '%"></i></div>' : '') +
        (!l.tirable && l.weight > 0 ? '<span class="pill bad" style="margin-top:6px">Ne peut pas sortir en ce moment</span>' : '') + '</div></li>';
    }).join('') : '<li class="muted">Aucun cadeau sur la roue.</li>';
    $('derniers').innerHTML = D.derniers.length ? D.derniers.map(function (g) {
      return '<li><div class="thumb ph" aria-hidden="true">🎁</div><div class="grow"><b class="trunc">' + esc(g.lot) + '</b><div class="small muted">' + esc(g.prenom || 'Quelqu’un') + ' · ' + esc(depuis(g.instant)) + '</div></div></li>';
    }).join('') : '<li class="muted">Les gagnants apparaîtront ici dès que vos clients joueront.</li>';
    var al = D.bilan.avertissements || [];
    $('cardAlertes').hidden = !al.length;
    $('alertes').innerHTML = al.map(function (a) { return '<li><span class="pill warn">!</span><span class="small">' + esc(a) + '</span></li>'; }).join('');
  }
  $('btnVueTable').addEventListener('click', function () {
    var t = $('chartTable').hidden;
    $('chartTable').hidden = !t; $('chart').hidden = t;
    this.textContent = t ? 'Voir le graphique' : 'Voir le tableau';
    this.setAttribute('aria-pressed', String(t));
  });

  function graphique(serie) {
    // Sur téléphone, un dessin plus étroit garde des graduations lisibles une fois mis à l'échelle.
    var etroit = window.innerWidth < 640;
    var W = etroit ? 360 : 720, H = etroit ? 200 : 220, pad = 28, n = serie.length || 1, pas = etroit ? 10 : 5;
    var brut = Math.max.apply(null, serie.map(function (r) { return Math.max(r.parties, r.remis); }).concat([0]));
    // Graduations ENTIÈRES : une échelle « 1, 1, 1, 0, 0 » ne veut rien dire.
    var max = Math.max(4, Math.ceil(brut / 4) * 4);
    var bw = (W - pad * 2) / n;
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Parties et cadeaux remis par jour sur 30 jours">';
    for (var g = 0; g <= 4; g++) {
      var y = pad + (H - pad * 2) * g / 4;
      s += '<line x1="' + pad + '" x2="' + (W - pad) + '" y1="' + y + '" y2="' + y + '" stroke="#E8DFD3"/>';
      s += '<text x="' + (pad - 6) + '" y="' + (y + 4) + '" font-size="10" text-anchor="end" fill="#5E5852">' + Math.round(max * (4 - g) / 4) + '</text>';
    }
    serie.forEach(function (r, i) {
      var x = pad + i * bw, h1 = (H - pad * 2) * r.parties / max, h2 = (H - pad * 2) * r.remis / max;
      s += '<rect x="' + (x + bw * 0.12) + '" y="' + (H - pad - h1) + '" width="' + (bw * 0.4) + '" height="' + h1 + '" rx="2" fill="#F4501E"><title>' + esc(date(r.jour)) + ' : ' + r.parties + ' partie(s)</title></rect>';
      s += '<rect x="' + (x + bw * 0.52) + '" y="' + (H - pad - h2) + '" width="' + (bw * 0.36) + '" height="' + h2 + '" rx="2" fill="#FFB800"><title>' + esc(date(r.jour)) + ' : ' + r.remis + ' remis</title></rect>';
      if (i % pas === 0 || i === n - 1) s += '<text x="' + (x + bw / 2) + '" y="' + (H - 8) + '" font-size="10" text-anchor="middle" fill="#5E5852">' + esc(date(r.jour)) + '</text>';
    });
    s += '</svg><div class="row small muted" style="margin-top:6px"><span class="row" style="gap:6px"><span class="swatch" style="background:#F4501E"></span>Parties</span><span class="row" style="gap:6px"><span class="swatch" style="background:#FFB800"></span>Cadeaux remis</span></div>';
    return s;
  }

  function vignette(l) {
    var src = l.photo || (l.cost_item_id && produit(l.cost_item_id) ? produit(l.cost_item_id).photo : null);
    if (src) return '<img class="thumb" alt="" src="' + esc(src) + '" loading="lazy">';
    var ic = l.type === 'points' ? '★' : (l.type && l.type.indexOf('coupon_') === 0 ? '%' : '🎁');
    return '<div class="thumb ph" aria-hidden="true">' + ic + '</div>';
  }
  function produit(id) {
    id = Number(id);
    for (var i = 0; i < D.produits.length; i++) if (D.produits[i].id === id) return D.produits[i];
    return null;
  }

  // ── Ma roue (chances + aperçu) ──────────────────────────────
  function actifsBrouillon() { return brouillon.filter(function (l) { return !l.archived; }); }
  function totalPoids() { return actifsBrouillon().reduce(function (t, l) { return t + Math.max(0, Number(l.weight) || 0); }, 0); }
  function chance(l) { var t = totalPoids(); return t > 0 && !l.archived ? Math.round(1000 * Math.max(0, Number(l.weight) || 0) / t) / 10 : 0; }
  function resumeCadeau(l) {
    if (l.type === 'free_item') { var p = produit(l.cost_item_id); return p ? p.name : 'Produit à choisir'; }
    if (l.type === 'points') return (l.value || 0) + ' points';
    if (l.type === 'coupon_percent') return '-' + (l.value || 0) + ' % (max ' + eur(l.max_discount) + ')';
    return '-' + eur(l.value);
  }
  function rendreRoue() {
    var act = actifsBrouillon();
    $('tChances').innerHTML = act.map(function (l, i) {
      var c = COULEURS[i % COULEURS.length], idx = brouillon.indexOf(l);
      return '<tr><td><span class="swatch" style="background:' + c[0] + '"></span></td>' +
        '<td><b>' + esc(l.label) + '</b>' + (Number(l.weight) === 0 ? ' <span class="pill gold">vitrine</span>' : '') + '</td>' +
        '<td class="small muted">' + esc(resumeCadeau(l)) + '</td>' +
        '<td><label class="sr" for="w' + idx + '">Poids de ' + esc(l.label) + '</label><input class="in n num" style="min-height:38px;max-width:90px" type="number" min="0" max="1000" id="w' + idx + '" data-i="' + idx + '" data-k="weight" value="' + esc(l.weight) + '"></td>' +
        '<td class="num"><b data-chance="' + idx + '">' + chance(l).toLocaleString('fr-FR') + ' %</b></td></tr>';
    }).join('');
    rendreApercu();
  }
  function rendreApercu() {
    var act = actifsBrouillon(), n = act.length, R = 150, cx = 160, cy = 160;
    if (!n) { $('wheelPreview').innerHTML = '<div class="empty">Ajoutez des cadeaux pour composer la roue.</div>'; $('legend').innerHTML = ''; return; }
    var s = '<svg viewBox="0 0 320 340" role="img" aria-label="Aperçu de la roue avec ' + n + ' cadeaux">';
    s += '<circle cx="160" cy="160" r="158" fill="#17140F"/>';
    act.forEach(function (l, i) {
      var a0 = -Math.PI / 2 + 2 * Math.PI * i / n, a1 = a0 + 2 * Math.PI / n, c = COULEURS[i % COULEURS.length];
      var x0 = cx + R * Math.cos(a0), y0 = cy + R * Math.sin(a0), x1 = cx + R * Math.cos(a1), y1 = cy + R * Math.sin(a1);
      s += '<path d="M' + cx + ' ' + cy + ' L' + x0 + ' ' + y0 + ' A' + R + ' ' + R + ' 0 ' + (n === 1 ? 1 : 0) + ' 1 ' + x1 + ' ' + y1 + ' Z" fill="' + c[0] + '" stroke="#17140F" stroke-width="2"/>';
      var am = (a0 + a1) / 2, deg = am * 180 / Math.PI;
      // Moitié gauche : on retourne le texte, sinon il se lit à l'envers.
      var cosA = Math.cos(am); if (cosA < -0.0001) deg += 180;
      var tx = cx + R * 0.6 * Math.cos(am), ty = cy + R * 0.6 * Math.sin(am);
      var txt = String(l.label || '').slice(0, 14);
      s += '<text x="' + tx + '" y="' + ty + '" fill="' + c[1] + '" font-family="Barlow Semi Condensed,Arial Narrow,sans-serif" font-weight="800" font-size="' + (n > 8 ? 12 : 15) + '" text-anchor="middle" dominant-baseline="middle" transform="rotate(' + deg + ' ' + tx + ' ' + ty + ')">' + esc(txt) + '</text>';
    });
    s += '<circle cx="160" cy="160" r="26" fill="#FFB800" stroke="#17140F" stroke-width="4"/><text x="160" y="161" text-anchor="middle" dominant-baseline="middle" font-family="Barlow Semi Condensed,sans-serif" font-weight="800" font-size="13" fill="#17140F">GO</text>';
    s += '<path d="M150 0 L170 0 L160 22 Z" fill="#fff" stroke="#17140F" stroke-width="2"/></svg>';
    $('wheelPreview').innerHTML = s;
    $('legend').innerHTML = act.map(function (l, i) {
      return '<li><span class="swatch" style="background:' + COULEURS[i % COULEURS.length][0] + '"></span><span class="grow trunc">' + esc(l.label) + '</span><b class="num">' + chance(l).toLocaleString('fr-FR') + ' %</b></li>';
    }).join('') + '<li class="small muted" style="margin-top:4px">Total : 100 %. Le tirage se fait sur le serveur.</li>';
  }
  $('tChances').addEventListener('input', function (e) {
    var t = e.target; if (!t.dataset || t.dataset.k !== 'weight') return;
    brouillon[Number(t.dataset.i)].weight = Math.max(0, Math.min(1000, parseInt(t.value, 10) || 0));
    marquerSale();
    actifsBrouillon().forEach(function (l) {
      var el = document.querySelector('[data-chance="' + brouillon.indexOf(l) + '"]');
      if (el) el.textContent = chance(l).toLocaleString('fr-FR') + ' %';
    });
    rendreApercu();
  });
  $('tChances').addEventListener('change', function () { rendreRoue(); rendreCadeaux(); });

  function marquerSale() { sale = true; $('publish').classList.add('show'); $('pillBrouillon').hidden = false; }
  function nettoyer() { sale = false; $('publish').classList.remove('show'); $('pillBrouillon').hidden = true; }
  $('btnAnnuler').addEventListener('click', function () {
    brouillon = JSON.parse(JSON.stringify(D.lots)); nettoyer(); rendreRoue(); rendreCadeaux(); toast('Modifications abandonnées.');
  });
  $('btnPublier').addEventListener('click', function () {
    var b = this; b.disabled = true;
    var lots = brouillon.map(function (l) {
      return {key: l.key || null, label: String(l.label || '').trim(), type: l.type, value: Number(l.value) || 0,
        max_discount: Number(l.max_discount) || 0, cost_item_id: l.cost_item_id ? Number(l.cost_item_id) : null,
        weight: Math.max(0, parseInt(l.weight, 10) || 0), quantity: Math.max(0, parseInt(l.quantity, 10) || 0),
        daily_cap: Math.max(0, parseInt(l.daily_cap, 10) || 0), kitchen_prep: !!l.kitchen_prep, archived: !!l.archived};
    });
    api(BASE + '/roue', {method: 'POST', body: {lots: lots}}).then(function (r) {
      b.disabled = false;
      if (!r.status) { toast(r.message || 'Enregistrement refusé.', 'err'); return; }
      nettoyer(); toast(r.message); charger();
    }).catch(function () { b.disabled = false; });
  });

  // ── Mes cadeaux ─────────────────────────────────────────────
  function optionsProduits(sel) {
    var parCat = {};
    D.produits.forEach(function (p) { (parCat[p.categorie || 'Autres'] = parCat[p.categorie || 'Autres'] || []).push(p); });
    var h = '<option value="">— Choisir un produit de la carte —</option>';
    Object.keys(parCat).sort().forEach(function (c) {
      h += '<optgroup label="' + esc(c) + '">' + parCat[c].map(function (p) {
        return '<option value="' + p.id + '"' + (Number(sel) === p.id ? ' selected' : '') + '>' + esc(p.name) + ' — ' + esc(eur(p.price)) + '</option>';
      }).join('') + '</optgroup>';
    });
    return h;
  }
  function carteCadeau(l) {
    var i = brouillon.indexOf(l), id = function (k) { return 'c' + i + '_' + k; };
    var donnes = l.donnes || 0;
    var etat = l.archived ? '<span class="pill mute">Retiré</span>'
      : (Number(l.weight) === 0 ? '<span class="pill gold">Vitrine · jamais gagné</span>'
      : (l.key && D.lots.some(function (x) { return x.key === l.key && !x.tirable; }) ? '<span class="pill bad">Ne sort pas en ce moment</span>' : '<span class="pill ok">Sur la roue · ' + chance(l).toLocaleString('fr-FR') + ' %</span>'));
    var h = '<article class="lotcard' + (l.archived ? ' arch' : '') + '" data-lot="' + i + '">';
    h += '<div class="lothead">' + vignette(l) + '<div class="grow"><b class="trunc" style="display:block;font-size:16px">' + esc(l.label || 'Nouveau cadeau') + '</b><span class="small muted">' + esc(TYPES[l.type] || '') + ' · ' + esc(resumeCadeau(l)) + '</span></div>' + etat + '</div>';
    h += '<div class="lotbody">';
    if (!l.archived) {
      h += '<div class="inline">';
      h += '<div><label class="f" for="' + id('label') + '">Texte sur la roue</label><input class="in" maxlength="30" id="' + id('label') + '" data-k="label" value="' + esc(l.label) + '"></div>';
      h += '<div><label class="f" for="' + id('type') + '">Type de cadeau</label><select class="in" id="' + id('type') + '" data-k="type">' +
        Object.keys(TYPES).map(function (t) { return '<option value="' + t + '"' + (l.type === t ? ' selected' : '') + '>' + TYPES[t] + '</option>'; }).join('') + '</select></div>';
      h += '</div>';
      if (l.key && donnes > 0) {
        h += '<p class="hint" style="margin:10px 0 0">Déjà gagné : changer le type ou le produit crée un nouveau cadeau ; l’ancien reste dû à ses gagnants.</p>';
      }
      if (l.type === 'free_item') {
        h += '<div class="field"><label class="f" for="' + id('item') + '">Produit offert</label><select class="in" id="' + id('item') + '" data-k="cost_item_id">' + optionsProduits(l.cost_item_id) + '</select><span class="hint">Le stock et le coût de ce produit sont mis à jour quand vous remettez le cadeau.</span></div>';
        h += '<label class="check" style="margin-top:10px"><input type="checkbox" data-k="kitchen_prep"' + (l.kitchen_prep ? ' checked' : '') + '><span>À préparer en cuisine<span class="hint">L’équipe est prévenue au moment de la remise.</span></span></label>';
      } else if (l.type === 'points') {
        h += '<div class="field"><label class="f" for="' + id('val') + '">Nombre de points</label><input class="in n num" type="number" min="1" max="10000" id="' + id('val') + '" data-k="value" value="' + esc(l.value || '') + '"></div>';
      } else if (l.type === 'coupon_percent') {
        h += '<div class="inline field"><div><label class="f" for="' + id('val') + '">Remise (%)</label><input class="in num" type="number" min="1" max="100" id="' + id('val') + '" data-k="value" value="' + esc(l.value || '') + '"></div>' +
          '<div><label class="f" for="' + id('max') + '">Plafond (€)</label><input class="in num" type="number" min="0.5" max="200" step="0.5" id="' + id('max') + '" data-k="max_discount" value="' + esc(l.max_discount || '') + '"><span class="hint">Obligatoire : -15 % d’une grosse commande coûterait cher.</span></div></div>';
      } else {
        h += '<div class="field"><label class="f" for="' + id('val') + '">Montant de la remise (€)</label><input class="in n num" type="number" min="0.5" max="100" step="0.5" id="' + id('val') + '" data-k="value" value="' + esc(l.value || '') + '"></div>';
      }
      h += '<div class="inline field">';
      h += '<div><label class="f" for="' + id('w') + '">Poids</label><input class="in num" type="number" min="0" max="1000" id="' + id('w') + '" data-k="weight" value="' + esc(l.weight) + '"><span class="hint">0 = affiché, jamais gagné.</span></div>';
      h += '<div><label class="f" for="' + id('q') + '">Quantité totale</label><input class="in num" type="number" min="0" max="100000" id="' + id('q') + '" data-k="quantity" value="' + esc(l.quantity) + '"><span class="hint">0 = illimité.' + (l.key ? ' Déjà gagnés : ' + esc(nb(donnes)) + '.' : '') + '</span></div>';
      h += '<div><label class="f" for="' + id('d') + '">Max. par jour</label><input class="in num" type="number" min="0" max="10000" id="' + id('d') + '" data-k="daily_cap" value="' + esc(l.daily_cap) + '"><span class="hint">0 = sans plafond.</span></div>';
      h += '</div>';
      if (l.quantity > 0 && l.key) {
        var pct = Math.min(100, Math.round(100 * donnes / l.quantity));
        h += '<div style="margin-top:12px"><div class="row between small"><span class="muted">Stock du jeu</span><b class="num">' + esc(nb(Math.max(0, l.quantity - donnes))) + ' restants</b></div><div class="bar gold" style="margin-top:4px"><i style="width:' + pct + '%"></i></div></div>';
      }
      h += '<div class="row" style="margin-top:14px;justify-content:flex-end"><button class="btn danger sm" data-act="retirer">Retirer de la roue</button></div>';
    } else {
      h += '<div class="row between"><span class="small muted">' + esc(nb(donnes)) + ' gagné(s) sur cette campagne.</span><button class="btn ghost sm" data-act="remettre">Remettre sur la roue</button></div>';
    }
    h += '</div></article>';
    return h;
  }
  function rendreCadeaux() {
    var act = brouillon.filter(function (l) { return !l.archived; }), arch = brouillon.filter(function (l) { return l.archived; });
    $('cadeauxActifs').innerHTML = act.length ? act.map(carteCadeau).join('') : '<div class="card empty"><b>Aucun cadeau</b>Ajoutez votre premier cadeau.</div>';
    $('blocArchives').hidden = !arch.length;
    $('nbArchives').textContent = '(' + arch.length + ')';
    $('cadeauxArchives').innerHTML = arch.map(carteCadeau).join('');
    var aRemise = act.some(function (l) { return String(l.type).indexOf('coupon_') === 0; });
    $('warnRemises').innerHTML = (aRemise && !D.remisesAcceptees)
      ? '<div class="banner warn"><div class="txt"><b>Les codes de remise sont éteints dans la caisse</b><span class="small">Les cadeaux « remise » ne sortiront pas tant qu’ils sont éteints. Préférez un produit offert ou des points.</span></div></div>' : '';
  }
  function surChamp(e) {
    var t = e.target, art = t.closest('[data-lot]'); if (!art || !t.dataset.k) return;
    var l = brouillon[Number(art.getAttribute('data-lot'))], k = t.dataset.k;
    if (k === 'kitchen_prep') l.kitchen_prep = t.checked;
    else if (k === 'label' || k === 'type') l[k] = t.value;
    else if (k === 'cost_item_id') {
      l.cost_item_id = t.value ? Number(t.value) : null;
      var p = produit(l.cost_item_id);
      // Le texte sur la roue suit le produit tant qu'il n'a pas été personnalisé.
      if (p && (!l.label || l.label === 'Nouveau cadeau' || l._auto)) { l.label = p.name.slice(0, 30); l._auto = true; }
      l.photo = p ? p.photo : null;
    } else l[k] = t.value === '' ? 0 : Number(t.value);
    if (k === 'label') l._auto = false;
    marquerSale();
    if (e.type === 'change' && (k === 'type' || k === 'cost_item_id')) { rendreCadeaux(); }
    rendreRoue();
  }
  $('cadeauxActifs').addEventListener('change', surChamp);
  $('cadeauxActifs').addEventListener('input', function (e) { if (e.target.tagName === 'INPUT' && e.target.type !== 'checkbox') surChamp(e); });
  document.querySelector('section[data-view=cadeaux]').addEventListener('click', function (e) {
    var b = e.target.closest('[data-act]'); if (!b) return;
    var l = brouillon[Number(b.closest('[data-lot]').getAttribute('data-lot'))];
    if (b.getAttribute('data-act') === 'retirer') {
      if (!l.key) brouillon.splice(brouillon.indexOf(l), 1); else l.archived = true;
      toast('« ' + (l.label || 'Cadeau') + ' » retiré — pensez à publier.');
    } else { l.archived = false; toast('« ' + l.label + ' » remis sur la roue — pensez à publier.'); }
    marquerSale(); rendreCadeaux(); rendreRoue();
  });
  $('btnAjout').addEventListener('click', function () {
    if (actifsBrouillon().length >= 16) { toast('Seize cadeaux au maximum sur la roue.', 'err'); return; }
    brouillon.push({key: null, label: 'Nouveau cadeau', type: 'free_item', value: 0, max_discount: 0, cost_item_id: null,
      weight: 5, quantity: 10, daily_cap: 3, kitchen_prep: false, archived: false, donnes: 0, photo: null, _auto: true});
    marquerSale(); rendreCadeaux(); rendreRoue();
    var cartes = $('cadeauxActifs').querySelectorAll('.lotcard');
    var der = cartes[cartes.length - 1];
    if (der) { der.scrollIntoView({behavior: 'smooth', block: 'center'}); var s = der.querySelector('select[data-k=cost_item_id]'); if (s) s.focus(); }
  });

  // ── Avis & réseaux ──────────────────────────────────────────
  var LIENS = ['review_url', 'instagram_url', 'snapchat_url', 'facebook_url'];
  var PILL_LIEN = {saisi: ['ok', 'Votre lien'], retire: ['mute', 'Retiré'], defaut: ['warn', 'Valeur par défaut'], secours: ['warn', 'Lien de secours'], absent: ['mute', 'Absent']};
  function rendreLiens() {
    var v = D.liens.valeurs;
    LIENS.forEach(function (k) { $(k).value = v[k] || ''; majTest(k); });
    $('review_required').checked = String(v.review_required) === '1';
    $('follow_required').checked = String(v.follow_required) === '1';
    $('review_dwell').value = v.review_dwell; $('follow_dwell').value = v.follow_dwell; $('min_order').value = v.min_order;
    D.liens.etats.forEach(function (e) {
      var p = PILL_LIEN[e.etat] || ['mute', e.etat];
      $('st_' + e.cle).innerHTML = '<span class="pill ' + p[0] + '" title="' + esc(e.dit) + '" style="margin-left:6px">' + esc(p[1]) + '</span>';
    });
    $('hint_review').textContent = D.liens.avisDerive ? 'Rien de collé : vos clients ouvrent une recherche Google Maps du restaurant. Collez votre vrai lien pour un avis en un appui.' : '';
    rendrePhone();
  }
  function majTest(k) {
    var u = safeUrl($(k).value.trim()) || (k === 'review_url' ? safeUrl(D.liens.avisEffectif) : '');
    var a = $('test_' + k);
    if (u) { a.href = u; a.removeAttribute('aria-disabled'); a.style.opacity = ''; a.style.pointerEvents = ''; }
    else { a.removeAttribute('href'); a.setAttribute('aria-disabled', 'true'); a.style.opacity = '.45'; a.style.pointerEvents = 'none'; }
  }
  function rendrePhone() {
    var avis = $('review_required').checked, suivre = $('follow_required').checked;
    var res = ['instagram_url', 'snapchat_url', 'facebook_url'].filter(function (k) { return $(k).value.trim(); })
      .map(function (k) { return {instagram_url: 'Instagram', snapchat_url: 'Snapchat', facebook_url: 'Facebook'}[k]; });
    $('phoneSteps').innerHTML =
      '<div class="stp' + (avis ? '' : ' off') + '"><i>1</i>Donner mon avis sur Google</div>' +
      '<div class="stp' + (suivre && res.length ? '' : ' off') + '"><i>2</i>' + (res.length ? 'Nous suivre sur ' + esc(res.join(', ')) : 'Aucun réseau renseigné') + '</div>';
  }
  $('formLiens').addEventListener('input', function (e) { if (LIENS.indexOf(e.target.id) >= 0) majTest(e.target.id); rendrePhone(); });
  $('formLiens').addEventListener('submit', function (e) {
    e.preventDefault();
    var b = $('btnLiens'); b.disabled = true; $('errLiens').textContent = '';
    var body = {};
    LIENS.forEach(function (k) { body[k] = $(k).value.trim(); });
    body.review_required = $('review_required').checked; body.follow_required = $('follow_required').checked;
    body.review_dwell = $('review_dwell').value === '' ? null : Number($('review_dwell').value);
    body.follow_dwell = $('follow_dwell').value === '' ? null : Number($('follow_dwell').value);
    body.min_order = $('min_order').value === '' ? null : Number($('min_order').value);
    api(BASE + '/liens', {method: 'POST', body: body}).then(function (r) {
      b.disabled = false;
      if (!r.status) { $('errLiens').textContent = r.message || 'Enregistrement refusé.'; toast(r.message || 'Enregistrement refusé.', 'err'); return; }
      toast(r.message); charger();
    }).catch(function () { b.disabled = false; });
  });

  // ── Comptoir ────────────────────────────────────────────────
  function rendreComptoir() {
    $('cMin').textContent = eur(D.jeu.min_order);
    $('cUrl').textContent = D.jeu.roue_url || 'Adresse publique non configurée (WHEEL_PUBLIC_URL).';
    $('btnCopier').disabled = !D.jeu.roue_url;
  }
  $('btnCopier').addEventListener('click', function () {
    var u = D.jeu.roue_url; if (!u) return;
    (navigator.clipboard ? navigator.clipboard.writeText(u) : Promise.reject()).then(function () { toast('Lien copié.'); },
      function () { toast('Copie impossible : sélectionnez le lien à la main.', 'err'); });
  });

  // ── Lots gagnés ─────────────────────────────────────────────
  var ETATS = {du: ['warn', 'À remettre'], remis: ['ok', 'Remis'], code: ['brand', 'Code de remise'], expire: ['mute', 'Expiré']};
  function chargerLots() {
    $('tLots').innerHTML = '<tr><td colspan="6" class="muted">Chargement…</td></tr>';
    return api(BASE + '/lots').then(function (r) {
      if (!r.status) { toast(r.message || 'Chargement impossible.', 'err'); return; }
      lotsGagnes = r.data; rendreLots();
    });
  }
  function rendreLots() {
    var compte = {tous: lotsGagnes.length, du: 0, remis: 0, code: 0, expire: 0};
    lotsGagnes.forEach(function (l) { compte[l.etat] = (compte[l.etat] || 0) + 1; });
    var defs = [['du', 'À remettre'], ['tous', 'Tous'], ['remis', 'Remis'], ['code', 'Codes de remise'], ['expire', 'Expirés']];
    $('filtres').innerHTML = defs.map(function (d) {
      return '<button class="chip" data-f="' + d[0] + '" aria-pressed="' + (filtre === d[0]) + '">' + d[1] + '<span class="c num">' + (compte[d[0]] || 0) + '</span></button>';
    }).join('');
    var q = $('recherche').value.trim().toLowerCase();
    var liste = lotsGagnes.filter(function (l) {
      if (filtre !== 'tous' && l.etat !== filtre) return false;
      if (!q) return true;
      return [l.code, l.prenom, l.tel_fin, l.lot].join(' ').toLowerCase().indexOf(q) >= 0;
    });
    if (!liste.length) {
      $('tLots').innerHTML = '<tr><td colspan="6"><div class="empty"><b>' + (lotsGagnes.length ? 'Aucun lot ne correspond' : 'Aucun lot pour l’instant') + '</b>' +
        (lotsGagnes.length ? 'Modifiez la recherche ou le filtre.' : 'Les lots apparaissent ici dès qu’un client gagne à la roue.') + '</div></td></tr>';
      return;
    }
    $('tLots').innerHTML = liste.map(function (l) {
      var e = ETATS[l.etat] || ['mute', l.etat];
      var detail = l.etat === 'remis' ? 'le ' + date(l.remis_le, true) + (l.remis_par ? ' par ' + l.remis_par : '')
        : (l.etat === 'du' && l.expire_le ? 'jusqu’au ' + date(l.expire_le) : '');
      return '<tr><td class="num small">' + esc(date(l.quand, true)) + '</td><td><b>' + esc(l.lot) + '</b></td>' +
        '<td class="small">' + esc(l.prenom || '—') + (l.tel_fin ? ' <span class="muted num">••' + esc(l.tel_fin) + '</span>' : '') + '</td>' +
        '<td class="num small">' + esc(l.code || '—') + '</td>' +
        '<td><span class="pill ' + e[0] + '">' + esc(e[1]) + '</span>' + (detail ? '<div class="small muted">' + esc(detail) + '</div>' : '') + '</td>' +
        '<td style="text-align:right">' + (l.etat === 'du' ? '<button class="btn primary sm" data-remettre="' + l.id + '">Remettre</button>' : '') + '</td></tr>';
    }).join('');
  }
  $('filtres').addEventListener('click', function (e) { var b = e.target.closest('[data-f]'); if (!b) return; filtre = b.getAttribute('data-f'); rendreLots(); });
  $('recherche').addEventListener('input', rendreLots);
  $('tLots').addEventListener('click', function (e) {
    var b = e.target.closest('[data-remettre]'); if (!b) return;
    var id = Number(b.getAttribute('data-remettre'));
    var l = lotsGagnes.filter(function (x) { return x.id === id; })[0]; if (!l) return;
    ouvrirModale('Remettre « ' + l.lot + ' »',
      '<p class="muted" style="margin-bottom:12px">Demandez son numéro au client' + (l.tel_fin ? ' (il finit par <b class="num">' + esc(l.tel_fin) + '</b>)' : '') + ' et saisissez-le en entier : c’est la preuve que le cadeau est bien le sien.</p>' +
      '<div class="field"><label class="f" for="mTel">Numéro de téléphone du client</label><input class="in num" id="mTel" type="tel" inputmode="tel" autocomplete="off" placeholder="06 12 34 56 78"></div>',
      'Valider la remise', function () {
        var tel = $('mTel').value.trim();
        if ((tel.match(/\d/g) || []).length < 6) { $('mErr').textContent = 'Saisissez le numéro complet du client.'; return false; }
        return api(BASE + '/lots/' + id + '/remettre', {method: 'POST', body: {phone: tel}}).then(function (r) {
          if (!r.status) { $('mErr').textContent = r.message || 'Remise refusée.'; return false; }
          toast(r.message); chargerLots(); charger();
        });
      });
  });

  // ── Statistiques ────────────────────────────────────────────
  function rendreStats() {
    var m = D.bilan.periodes.mois;
    var tot = D.parLot.reduce(function (a, l) { return {g: a.g + l.gagnes, r: a.r + l.remis}; }, {g: 0, r: 0});
    $('kpisStats').innerHTML =
      kpi('Parties (30 j)', nb(m.tours), '') +
      kpi('Cadeaux remis (30 j)', nb(m.cadeaux_remis), '') +
      kpi('Taux de retrait', (tot.g ? Math.round(100 * tot.r / tot.g) : 0) + ' %', 'campagne en cours', true) +
      kpi('Codes de remise', nb(m.codes_utilises) + ' / ' + nb(m.codes_emis), 'utilisés / émis (30 j)');
    $('tParLot').innerHTML = D.parLot.length ? D.parLot.map(function (l) {
      return '<tr><td><b>' + esc(l.lot) + '</b></td><td class="num">' + l.gagnes + '</td><td class="num">' + l.remis + '</td><td><div class="row" style="flex-wrap:nowrap"><div class="bar ok grow"><i style="width:' + l.taux + '%"></i></div><span class="num small">' + l.taux + ' %</span></div></td></tr>';
    }).join('') : '<tr><td colspan="4" class="muted">Aucune partie sur cette campagne.</td></tr>';
    var maxA = Math.max(1, Math.max.apply(null, D.abandons.map(function (a) { return a.n; })));
    $('abandons').innerHTML = D.abandons.map(function (a) {
      return '<li><div class="grow"><div class="row between small"><span>' + esc(a.etape) + '</span><b class="num">' + a.n + '</b></div><div class="bar" style="margin-top:5px"><i style="width:' + Math.round(100 * a.n / maxA) + '%"></i></div></div></li>';
    }).join('');
    $('chart2').innerHTML = graphique(D.serie);
  }

  // ── Réglages du jeu ─────────────────────────────────────────
  function rendreReglages() {
    var j = D.jeu;
    $('tOuvert').setAttribute('aria-checked', String(!!j.ouvert));
    $('campagne').textContent = j.campaign_key;
    $('prize_validity_days').value = j.prize_validity_days;
    $('daily_total_cap').value = j.daily_total_cap;
    $('acces').innerHTML =
      '<li><span class="grow">Code de la maison</span>' + (j.pin_configure ? '<span class="pill ok">Configuré</span>' : '<span class="pill warn">Absent</span>') + '</li>' +
      '<li><span class="grow">Fermeture automatique après</span><b class="num">' + esc(nb(j.session_minutes)) + ' min</b></li>' +
      '<li><span class="grow">Depuis la caisse</span><span class="pill brand">Accès direct</span></li>';
  }
  $('tOuvert').addEventListener('click', function () { basculerJeu(this.getAttribute('aria-checked') !== 'true'); });
  $('formJeu').addEventListener('submit', function (e) {
    e.preventDefault();
    var b = $('btnJeu'); b.disabled = true; $('errJeu').textContent = '';
    api(BASE + '/jeu', {method: 'POST', body: {
      prize_validity_days: Number($('prize_validity_days').value), daily_total_cap: Number($('daily_total_cap').value)}}).then(function (r) {
      b.disabled = false;
      if (!r.status) { $('errJeu').textContent = r.message; return; }
      toast(r.message); charger();
    }).catch(function () { b.disabled = false; });
  });
  $('btnCampagne').addEventListener('click', function () {
    ouvrirModale('Démarrer une nouvelle campagne ?',
      '<p class="muted">Les compteurs de cadeaux repartent à zéro et chaque client pourra rejouer une fois. Les lots déjà gagnés restent dus et l’historique est conservé.</p>',
      'Démarrer', function () {
        return api(BASE + '/campagne', {method: 'POST', body: {}}).then(function (r) {
          if (!r.status) { $('mErr').textContent = r.message; return false; }
          toast(r.message); return charger();
        });
      });
  });

  charger();
  // Rafraîchissement des CHIFFRES seulement : jamais sur un écran de saisie (on écraserait ce qui est tapé).
  setInterval(function () {
    if (sale || $('modal').classList.contains('open') || document.visibilityState !== 'visible') return;
    if (['tableau', 'lots', 'stats', 'comptoir'].indexOf(vueCourante()) < 0) return;
    charger();
  }, 60000);
})();
</script>
@endverbatim
</body>
</html>
