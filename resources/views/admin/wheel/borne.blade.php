{{--
  LA TABLETTE DU COMPTOIR — une VITRINE, pas un jeu.

  [2026-08-10] Le propriétaire a tranché : « ce n'est pas la page qu'il voudrait avoir en réel,
  parce qu'il y a une page qui va faire la PUBLICITÉ sur la tablette, et l'autre que le client aura
  sur son téléphone après avoir scanné le QR. » Deux surfaces, deux métiers :

    · ICI (tablette, posée face aux clients) : de l'AFFICHAGE. Personne ne la touche — ni l'équipe
      pendant un service, ni le client qui se contente de scanner. Son seul travail est de faire
      LEVER LES YEUX puis de donner un QR.
    · Là-bas (roue.html, sur le téléphone du client) : le parcours.

  ── POURQUOI UNE BOUCLE EN TROIS ACTES ─────────────────────────────────────────────────────────
  Une image fixe devient invisible en une heure : le cerveau cesse de la voir. Ce qui rattrape un
  regard, c'est le MOUVEMENT qui change. La page joue donc trois actes qui s'enchaînent —
  l'accroche, les lots, le geste à faire — pendant que la roue tourne, ralentit, tombe sur un lot et
  recommence. Chaque acte tient en une phrase lisible à trois mètres.

  Rien ne clignote. Un écran qui clignote au comptoir est éteint par l'équipe au bout d'un service.

  ── POURQUOI LE QR NE BOUGE JAMAIS ─────────────────────────────────────────────────────────────
  Il reste au même endroit, à la même taille, pendant les trois actes. Un QR qui apparaît et
  disparaît oblige le client à ATTENDRE le bon moment pour sortir son téléphone — et il ne le fait
  pas. Ce qui change autour de lui, c'est ce qui donne envie de le scanner.

  ── POURQUOI IL SE RENOUVELLE ──────────────────────────────────────────────────────────────────
  Le jeton est à usage unique et court : un QR figé serait consommé par le premier scan, et les
  suivants tomberaient sur « validation introuvable ». La page en redemande donc un régulièrement.
  Effet de bord utile : une photo du QR partagée à l'extérieur ne vaut plus rien quelques minutes
  plus tard. Il faut être DEVANT le comptoir.
--}}
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Le Cayenne — Tourne la roue</title>
<meta name="robots" content="noindex, nofollow">
<style>
  :root{
    --orange:#F4501E; --orange2:#FF6A3D; --jaune:#FFB800; --jaune2:#FFD34D;
    --noir:#0E0C0A; --creme:#FFF6EC;
  }
  *{box-sizing:border-box}
  html,body{margin:0; height:100%; overflow:hidden}
  body{
    background:var(--noir); color:var(--creme);
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased;
  }

  /* Lueur de fond qui respire très lentement : la scène a l'air VIVANTE même quand rien ne se
     passe, sans jamais attirer l'œil au détriment du texte. */
  .fond{
    position:fixed; inset:0; pointer-events:none;
    background:
      radial-gradient(85% 65% at 22% 12%, rgba(244,80,30,.30), transparent 62%),
      radial-gradient(70% 60% at 84% 88%, rgba(255,184,0,.20), transparent 60%),
      radial-gradient(120% 90% at 50% -10%, #3A2418 0%, transparent 58%);
    animation:respireFond 14s ease-in-out infinite;
  }
  @keyframes respireFond{0%,100%{opacity:.85}50%{opacity:1}}

  .scene{
    position:relative; height:100%; display:grid;
    grid-template-columns:1.15fr .85fr; gap:3vmin; align-items:center; padding:3.5vmin;
  }
  @media (max-aspect-ratio:1/1){ .scene{grid-template-columns:1fr; grid-template-rows:1fr auto; gap:2vmin} }

  /* ── CÔTÉ GAUCHE : LE SPECTACLE ─────────────────────────────────────────────────────────── */
  .gauche{position:relative; display:grid; place-items:center; height:100%; min-height:0}

  .logo{
    height:min(7.5vmin,78px); width:auto; display:block; margin:0 auto 1.5vmin;
    /* Lettrage sombre sur fond sombre : on l'éclaircit sans le rendre blanc cassant. */
    filter:brightness(0) invert(1) sepia(.16) saturate(1.6) hue-rotate(-12deg); opacity:.97;
  }

  .roue-boite{position:relative; display:grid; place-items:center}
  .roue{
    width:min(46vmin,470px); height:min(46vmin,470px); display:block;
    filter:drop-shadow(0 2.5vmin 5vmin rgba(0,0,0,.55));
  }
  /* Le repère qui désigne le lot gagnant, en haut de la roue. */
  .repere{
    position:absolute; top:-1.2vmin; left:50%; transform:translateX(-50%);
    width:0; height:0; border-left:1.5vmin solid transparent; border-right:1.5vmin solid transparent;
    border-top:2.6vmin solid var(--jaune2); filter:drop-shadow(0 .5vmin 1vmin rgba(0,0,0,.6)); z-index:2;
  }
  /* Éclat au moment où la roue s'immobilise : c'est CE flash qui fait tourner les têtes. */
  .eclat{
    position:absolute; inset:-8%; border-radius:50%; pointer-events:none; opacity:0;
    background:radial-gradient(circle, rgba(255,211,77,.55), transparent 62%);
  }
  .eclat.va{animation:eclate 1.1s ease-out}
  @keyframes eclate{0%{opacity:0; transform:scale(.85)}22%{opacity:1}100%{opacity:0; transform:scale(1.22)}}

  /* ── LES TROIS ACTES ────────────────────────────────────────────────────────────────────── */
  .actes{position:relative; width:100%; min-height:min(30vmin,300px); display:grid; place-items:center}
  .acte{
    position:absolute; inset:0; display:grid; place-content:center; text-align:center;
    opacity:0; transform:translateY(2.2vmin) scale(.985); pointer-events:none;
    transition:opacity .7s ease, transform .7s cubic-bezier(.2,.9,.3,1);
  }
  .acte.on{opacity:1; transform:none}

  h1{
    margin:0; font-size:min(11.5vmin,132px); line-height:.92; font-weight:900; letter-spacing:-.025em;
    background:linear-gradient(100deg,var(--jaune2),var(--orange2) 62%,var(--jaune));
    -webkit-background-clip:text; background-clip:text; color:transparent;
  }
  .sous{margin:1.8vmin 0 0; font-size:min(3.3vmin,34px); opacity:.9; line-height:1.35}
  .sous b{color:var(--jaune2)}
  .titre2{
    margin:0 0 2vmin; font-size:min(5.2vmin,54px); font-weight:900; letter-spacing:-.015em; line-height:1.05;
  }

  /* Acte 2 — les lots, en pastilles qui entrent une par une. */
  .lots{display:flex; flex-wrap:wrap; gap:1.4vmin; justify-content:center; max-width:min(78vmin,760px)}
  .pastille{
    padding:1.4vmin 2.4vmin; border-radius:99px; font-size:min(3.4vmin,34px); font-weight:900;
    background:rgba(255,255,255,.07); border:1px solid rgba(255,184,0,.42); color:var(--creme);
    opacity:0; transform:translateY(1.4vmin) scale(.94);
  }
  .acte.on .pastille{animation:entrePastille .5s cubic-bezier(.2,1.5,.4,1) forwards}
  @keyframes entrePastille{to{opacity:1; transform:none}}

  /* Acte 3 — le geste, en deux temps. On n'en annonce jamais trois : « scanne, tourne » suffit. */
  .geste{display:flex; align-items:center; justify-content:center; gap:2.4vmin; flex-wrap:wrap}
  .pas{display:grid; place-items:center; gap:.8vmin}
  .pas .rond{
    width:min(11vmin,110px); height:min(11vmin,110px); border-radius:50%; display:grid; place-items:center;
    font-size:min(5vmin,52px); background:rgba(255,184,0,.14); border:2px solid rgba(255,184,0,.5);
  }
  .pas .mot{font-size:min(3.1vmin,32px); font-weight:900}
  .fleche-h{font-size:min(4.4vmin,46px); opacity:.55}

  /* ── CÔTÉ DROIT : LE QR, IMMOBILE ───────────────────────────────────────────────────────── */
  .droite{display:grid; place-items:center; text-align:center; gap:1.6vmin}
  .qr-boite{position:relative; display:inline-block}
  /* Anneau qui pulse autour du QR : il désigne l'endroit sans rien recouvrir. */
  .qr-boite::before{
    content:''; position:absolute; inset:-2.2vmin; border-radius:5vmin;
    border:.5vmin solid rgba(255,184,0,.42); animation:pulseAnneau 2.8s ease-in-out infinite;
  }
  @keyframes pulseAnneau{
    0%,100%{transform:scale(1); opacity:.5}
    50%{transform:scale(1.035); opacity:1}
  }
  .qr{background:#fff; border-radius:3vmin; padding:2.2vmin; display:block;
      box-shadow:0 2vmin 6vmin rgba(0,0,0,.5)}
  .qr svg{display:block; width:min(36vmin,380px); height:auto}
  .scanne{margin:0; font-size:min(4.4vmin,46px); font-weight:900; letter-spacing:-.01em}
  .detail{margin:0; font-size:min(2.4vmin,25px); opacity:.72; line-height:1.5}
  .fleche{font-size:min(5vmin,52px); line-height:1; animation:pointe 2.2s ease-in-out infinite}
  @keyframes pointe{0%,100%{transform:translateY(0)}50%{transform:translateY(1.1vmin)}}

  .err{background:rgba(217,48,37,.16); border:1px solid rgba(217,48,37,.5); border-radius:2vmin;
       padding:2.4vmin; font-size:min(2.8vmin,28px); line-height:1.5; max-width:60vmin}

  /* ── BANDEAU BAS : la condition, écrite une fois pour toutes ────────────────────────────── */
  .bandeau{
    position:fixed; left:0; right:0; bottom:0; padding:1.4vmin 3vmin;
    background:linear-gradient(0deg, rgba(0,0,0,.55), transparent);
    font-size:min(2.2vmin,23px); opacity:.66; text-align:center;
  }

  @media (prefers-reduced-motion:reduce){
    .fond,.fleche,.qr-boite::before,.eclat.va,.acte.on .pastille{animation:none}
    .acte{transition:none}
    .pastille{opacity:1; transform:none}
  }
</style>
</head>
<body>
<div class="fond" aria-hidden="true"></div>

<div class="scene">

  <div class="gauche">
    <div style="display:grid; place-items:center; gap:2vmin; width:100%">
      {{-- Le VRAI logo, en grand : c'est la première chose vue de loin, et c'est ce qui fait
           reconnaître l'écran comme celui du restaurant et non une publicité quelconque. --}}
      <img class="logo" src="{{ asset('images/kiosk-attract/logo.png') }}" alt="Le Cayenne">

      <div class="roue-boite">
        <div class="repere" aria-hidden="true"></div>
        <canvas class="roue" id="roue" width="720" height="720" aria-hidden="true"></canvas>
        <div class="eclat" id="eclat" aria-hidden="true"></div>
      </div>

      <div class="actes">
        {{-- ACTE 1 — L'ACCROCHE. Une promesse, lisible à trois mètres. --}}
        <div class="acte on" data-acte="0">
          <h1>Tu gagnes<br>à 100 %</h1>
          <p class="sous">
            {{-- Un `@if` COLLÉ à un mot (« commande@if ») n'est pas reconnu comme directive par
                 Blade : le `@endif` devient orphelin et la vue ne compile plus. D'où l'expression. --}}
            Un lot pour ta prochaine commande{!! ! empty($minOrder)
                ? ', <b>dès ' . number_format($minOrder, 2, ',', ' ') . ' €</b>'
                : '' !!}.
          </p>
        </div>

        {{-- ACTE 2 — CE QU'IL Y A À GAGNER. On montre les lots : une promesse sans contenu ne
             déclenche rien, et les libellés viennent du serveur, jamais d'une liste écrite ici. --}}
        <div class="acte" data-acte="1">
          <p class="titre2">Aujourd'hui, on distribue</p>
          <div class="lots">
            {{-- TOUS les lots, sans coupe. En tronquer un ferait dire à l'écran « on distribue
                 ceci » pendant que la roue en montre un de plus juste au-dessus — le client
                 remarque l'écart, et c'est le genre de détail qui fait douter du reste. --}}
            @foreach ($segments ?? [] as $i => $s)
              <span class="pastille" style="animation-delay:{{ 0.12 * $i }}s">{{ $s['label'] }}</span>
            @endforeach
          </div>
        </div>

        {{-- ACTE 3 — LE GESTE. Deux temps, jamais trois : « scanne, tourne ». Annoncer un
             parcours long fait reposer le téléphone avant même de commencer. --}}
        <div class="acte" data-acte="2">
          <p class="titre2">C'est tout de suite</p>
          <div class="geste">
            <div class="pas"><div class="rond">📱</div><div class="mot">Scanne</div></div>
            <div class="fleche-h" aria-hidden="true">→</div>
            <div class="pas"><div class="rond">🎡</div><div class="mot">Tourne</div></div>
            <div class="fleche-h" aria-hidden="true">→</div>
            <div class="pas"><div class="rond">🎁</div><div class="mot">Gagne</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="droite">
    @if (! empty($erreur))
      <p class="err">{{ $erreur }}</p>
    @else
      <div class="fleche" aria-hidden="true">↓</div>
      <div class="qr-boite">
        <div class="qr" id="qr">{!! $qr !!}</div>
      </div>
      <p class="scanne">Scanne avec ton téléphone</p>
      <p class="detail">Aucune application à installer.<br>Ça prend moins d'une minute.</p>
    @endif
  </div>

</div>

@if (empty($erreur))
  <p class="bandeau">Un tour par personne — le lot est à utiliser sur une prochaine commande.</p>
@endif

<script>
(function () {
  'use strict';

  /* Les libellés viennent du SERVEUR. Aucune liste écrite dans cette page : une roue qui affiche
     autre chose que ce qu'elle donne est un mensonge, et le client le découvre au pire moment. */
  var LOTS = @json(array_column($segments ?? [], 'label'));
  if (!LOTS.length) { LOTS = ['-10%', '50 points', 'Boisson offerte', '-15%', '100 points', 'Frites offertes']; }

  var cv = document.getElementById('roue');
  var ctx = cv && cv.getContext ? cv.getContext('2d') : null;
  var eclat = document.getElementById('eclat');
  var lent = matchMedia('(prefers-reduced-motion: reduce)').matches;

  var COULEURS = ['#F4501E', '#FFB800', '#FF6A3D', '#FFD34D', '#E8431A', '#FFC633'];
  var angle = 0;

  function ombrer(hex, t) {
    var n = parseInt(hex.slice(1), 16), r = n >> 16, g = (n >> 8) & 255, b = n & 255;
    function f(x) { return Math.max(0, Math.min(255, Math.round(t < 0 ? x * (1 + t) : x + (255 - x) * t))); }
    return 'rgb(' + f(r) + ',' + f(g) + ',' + f(b) + ')';
  }

  function dessiner() {
    if (!ctx) return;
    var N = LOTS.length, R = 360, pas = (Math.PI * 2) / N;
    ctx.clearRect(0, 0, 720, 720);
    ctx.save();
    ctx.translate(R, R);
    ctx.rotate(angle);

    for (var i = 0; i < N; i++) {
      var a0 = i * pas - Math.PI / 2, a1 = a0 + pas;
      var base = COULEURS[i % COULEURS.length];

      var g = ctx.createRadialGradient(0, 0, R * 0.18, 0, 0, R);
      g.addColorStop(0, ombrer(base, 0.22));
      g.addColorStop(1, ombrer(base, -0.18));

      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, R - 8, a0, a1);
      ctx.closePath();
      ctx.fillStyle = g;
      ctx.fill();
      ctx.strokeStyle = 'rgba(0,0,0,.22)';
      ctx.lineWidth = 2;
      ctx.stroke();

      /* LIBELLÉS À L'ENVERS. Un texte couché le long du rayon se retourne dès que son secteur
         passe dans la moitié gauche : « Boisson offerte » s'y lit « ǝʇɹǝɟɟo uossıoq ». La mesure
         doit porter sur l'angle ABSOLU — rotation de la roue COMPRISE. La tester sur le seul angle
         du secteur (`cos(am)`) donne une réponse figée pendant que la roue tourne : c'était le
         défaut vu en capture, la moitié des lots s'affichait à l'envers. */
      ctx.save();
      var am = a0 + pas / 2;
      ctx.rotate(am);
      var abs = ((am + angle) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
      var retourne = abs > Math.PI / 2 && abs < Math.PI * 1.5;
      ctx.translate(R * 0.60, 0);
      if (retourne) { ctx.rotate(Math.PI); }
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = '#fff';
      ctx.shadowColor = 'rgba(0,0,0,.5)';
      ctx.shadowBlur = 6;
      // Deux lignes pour les libellés longs : « Frites + Boisson » sur une seule ligne déborde du
      // secteur et se fait rogner par le bord de la roue.
      var mots = String(LOTS[i]).split(' ');
      var l1 = mots.length > 1 ? mots.slice(0, Math.ceil(mots.length / 2)).join(' ') : mots[0];
      var l2 = mots.length > 1 ? mots.slice(Math.ceil(mots.length / 2)).join(' ') : '';
      var taille = (l1.length > 9 || l2.length > 9) ? 25 : 31;
      ctx.font = '900 ' + taille + 'px -apple-system,Segoe UI,Roboto,sans-serif';
      if (l2) { ctx.fillText(l1, 0, -taille * 0.55); ctx.fillText(l2, 0, taille * 0.55); }
      else { ctx.fillText(l1, 0, 0); }
      ctx.restore();
    }
    ctx.restore();

    /* Ampoules de fête foraine : elles font la différence entre « un camembert » et « une roue ». */
    ctx.save();
    ctx.translate(R, R);
    for (var k = 0; k < 24; k++) {
      var aa = angle * 0.5 + (k / 24) * Math.PI * 2;
      var vive = (Math.floor(Date.now() / 260) + k) % 3 === 0;
      ctx.beginPath();
      ctx.arc(Math.cos(aa) * (R - 2), Math.sin(aa) * (R - 2), vive ? 7 : 5, 0, Math.PI * 2);
      ctx.fillStyle = vive ? '#FFF3CF' : 'rgba(255,220,150,.55)';
      ctx.shadowColor = 'rgba(255,200,90,.9)';
      ctx.shadowBlur = vive ? 16 : 7;
      ctx.fill();
    }
    // Moyeu.
    ctx.beginPath();
    ctx.arc(0, 0, 58, 0, Math.PI * 2);
    var gm = ctx.createRadialGradient(-14, -18, 6, 0, 0, 58);
    gm.addColorStop(0, '#FFE9A8');
    gm.addColorStop(1, '#C9791A');
    ctx.fillStyle = gm;
    ctx.shadowColor = 'rgba(0,0,0,.45)';
    ctx.shadowBlur = 18;
    ctx.fill();
    ctx.restore();
  }

  /* ── LE RYTHME ────────────────────────────────────────────────────────────────────────────
     La roue ne tourne pas en continu : elle LANCE, ralentit, tombe sur un lot, s'arrête une
     seconde, puis repart. Une rotation constante devient du papier peint en quelques minutes ;
     un arrêt sur un lot rejoue la même petite tension à chaque cycle, et c'est elle qui fait
     lever les yeux. */
  var TOUR = 7200;          // durée d'un lancer, en millisecondes
  var PAUSE = 2600;         // temps d'arrêt sur le lot
  var depart = 0, base = 0, cible = 0, etat = 'lance';

  function nouveauLancer(t) {
    depart = t;
    base = angle;
    // Entre 3 et 5 tours, puis un arrêt sur un secteur au hasard. Rien n'est misé ici : c'est de
    // l'affichage. Le vrai tirage vit sur le serveur, et il est le seul à décider.
    var N = LOTS.length;
    var secteur = Math.floor(Math.random() * N);
    var pas = (Math.PI * 2) / N;
    var arret = -(secteur * pas + pas / 2);
    cible = base + Math.PI * 2 * (3 + Math.floor(Math.random() * 3))
          + ((arret - base) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
    etat = 'lance';
  }

  function boucle(t) {
    if (!depart) { nouveauLancer(t); }

    if (etat === 'lance') {
      var p = Math.min(1, (t - depart) / TOUR);
      // Décélération très douce en fin de course : c'est ce ralentissement qui crée l'attente.
      var e = 1 - Math.pow(1 - p, 3.4);
      angle = base + (cible - base) * e;
      if (p >= 1) {
        etat = 'pause';
        depart = t;
        if (eclat && !lent) {
          eclat.classList.remove('va');
          void eclat.offsetWidth;      // relance l'animation
          eclat.classList.add('va');
        }
      }
    } else if (t - depart > PAUSE) {
      nouveauLancer(t);
    }

    dessiner();
    requestAnimationFrame(boucle);
  }

  if (ctx) {
    if (lent) { dessiner(); } else { requestAnimationFrame(boucle); }
  }

  /* ── LES TROIS ACTES ──────────────────────────────────────────────────────────────────────
     Un acte toutes les 6,5 s. Assez long pour être lu sans se presser par quelqu'un qui attend sa
     commande, assez court pour que la scène change avant qu'on cesse de la voir. */
  var actes = [].slice.call(document.querySelectorAll('.acte'));
  if (actes.length > 1) {
    var courant = 0;
    setInterval(function () {
      actes[courant].classList.remove('on');
      courant = (courant + 1) % actes.length;
      var el = actes[courant];
      el.classList.add('on');
      // On relance l'entrée des pastilles à chaque passage : sinon l'acte 2 n'est animé qu'une
      // fois, et paraît figé aux passages suivants.
      [].forEach.call(el.querySelectorAll('.pastille'), function (p) {
        p.style.animation = 'none';
        void p.offsetWidth;
        p.style.animation = '';
      });
    }, 6500);
  }

  /* Renouvellement du jeton : la page se recharge à la MOITIÉ de la durée de vie, pour que le QR
     affiché soit toujours valable au moins autant de temps qu'il en reste à l'écran. */
  setTimeout(function () { location.reload(); }, {{ (int) $refreshMs }});
})();
</script>
</body>
</html>
