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

  ── POURQUOI TOUT EST DANS LE FLUX, ET RIEN PAR-DESSUS ─────────────────────────────────────────
  [2026-08-10, mesuré] La version précédente posait le bandeau des conditions en `position:fixed`
  et devinait la hauteur du message (`min-height:min(30vmin,300px)`). Résultat mesuré sur 12 tailles
  d'écran sur 12 : le message sortait de sa boîte de 16 à 47 px, et ce débordement était INVISIBLE
  dans une mesure de la page parce que les actes étaient en `position:absolute`. En portrait, le
  carré blanc du QR recouvrait la 7e pastille sur toute sa hauteur (« Frites + Boisson » disparue)
  et effaçait les trois mots du geste. Donc : plus une seule boîte de hauteur devinée, plus un seul
  calque posé au-dessus d'un autre. Chaque bloc occupe une ligne, et c'est la ROUE qui cède la
  place quand l'écran est court — jamais le texte qui dit ce qu'on gagne.
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
    /* Le bandeau des conditions est un ÉTAGE de la page, pas un calque par-dessus : en
       `position:fixed` il écrasait les pastilles des lots (264 × 23 px mesurés) et la phrase du
       QR (261 × 34 px en portrait). En colonne, sa hauteur est retirée du budget avant que le
       reste ne se partage l'écran. */
    display:flex; flex-direction:column;
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
    position:relative; flex:1 1 auto; min-height:0; display:grid;
    grid-template-columns:1.15fr .85fr; grid-template-rows:minmax(0,1fr);
    gap:3vmin; align-items:stretch; padding:3.5vmin;
  }
  @media (max-aspect-ratio:1/1){
    .scene{grid-template-columns:1fr; grid-template-rows:minmax(0,1fr) auto; gap:2vmin}
  }

  /* ── CÔTÉ GAUCHE : LE SPECTACLE ─────────────────────────────────────────────────────────── */
  /* Trois étages : le logo, la roue, le message. La roue est sur la ligne élastique — c'est
     ELLE qui rétrécit quand l'écran est court, jamais le texte : un lot annoncé à moitié caché
     est un lot qu'on ne lit pas. */
  .gauche{
    display:grid; grid-template-rows:auto minmax(0,1fr) auto; gap:1.4vmin;
    justify-items:center; align-items:center; min-height:0; min-width:0;
  }

  /* ── LE LOGO ────────────────────────────────────────────────────────────────────────────────
     [2026-08-12 · propriétaire : « le logo, déjà, il s'affiche mal »] Il avait raison, et la cause
     était ici : un filtre `brightness(0) invert(1) sepia(.16) saturate(1.6) hue-rotate(-12deg)`.
     `brightness(0)` écrase TOUTE l'image en noir, `invert(1)` la repasse en blanc, le reste la
     teinte — le résultat est une silhouette crème uniforme. Or le logo n'est pas un lettrage : c'est
     un PIMENT MASCOTTE en couleurs (casquette blanche, lunettes, moustache) à côté d'un lettrage
     noir. Le filtre effaçait purement et simplement la mascotte.

     Le vrai problème n'était pas la couleur du logo, c'était le fond NOIR de la vitrine sous un
     logo dessiné pour du blanc. On ne détruit donc pas le logo : on lui donne sa plaque claire, avec
     une marge intérieure pour qu'il ne touche pas les bords. C'est ce que fait n'importe quelle
     marque sur un fond sombre — et la mascotte, qui est ce qu'on reconnaît de loin, revient. */
  .logo{
    height:min(9vmin,92px); width:auto; display:block;
    background:var(--creme);
    padding:min(1.1vmin,11px) min(1.8vmin,18px);
    border-radius:min(2vmin,18px);
    /* Un léger halo chaud décolle la plaque du fond noir sans dessiner de bordure dure. */
    box-shadow:0 0 0 1px rgba(255,184,0,.22), 0 .8vmin 2.4vmin rgba(0,0,0,.45);
  }

  /* La roue est dimensionnée par sa HAUTEUR, largeur déduite. Deux pièges déjà payés ici :
     · un diamètre exprimé seulement en `vmin` ignore ce qui reste au-dessus et en dessous — c'est
       ce qui poussait le message hors de sa boîte (+89 px mesurés) ;
     · borner la largeur puis laisser `max-height` rogner la hauteur ne conserve PAS le rapport
       d'un canvas : la roue est devenue une ELLIPSE de 434 × 295. Le `100%` est donc DANS le
       `min()` de la hauteur — l'axe imposé est celui qu'on borne, la largeur suit toute seule.
     Le canvas est posé directement sur la ligne élastique : dans un conteneur intermédiaire dont
     la ligne de grille est en `auto`, le pourcentage ne se résout pas. */
  .roue{
    height:min(48vmin,620px,100%); width:auto; display:block;
    filter:drop-shadow(0 2.5vmin 5vmin rgba(0,0,0,.55));
  }

  /* ── LES TROIS ACTES ────────────────────────────────────────────────────────────────────── */
  /* Les trois actes se SUPERPOSENT dans une seule case de grille : c'est le plus grand des trois
     qui donne sa hauteur à la ligne, et la hauteur ne peut donc plus être sous-estimée. Ils
     étaient auparavant en `position:absolute` dans une boîte de hauteur devinée — le contenu en
     sortait sur 12 mesures sur 12, et allait recouvrir le QR et le bandeau. */
  .actes{display:grid; width:100%; min-width:0}
  /* Fondu SEUL, sans glissement : un acte invisible déplacé de 1,6vmin agrandit quand même la
     zone de défilement de la ligne (13 à 30 px mesurés), et rend invérifiable la seule règle qui
     compte ici — rien ne sort de sa boîte. */
  .acte{
    grid-area:1/1; align-self:center; display:grid; place-content:center; text-align:center;
    opacity:0; pointer-events:none; transition:opacity .3s ease;
  }
  .acte.on{opacity:1}

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
  .lots{display:flex; flex-wrap:wrap; gap:1.4vmin; justify-content:center; max-width:min(88vmin,860px)}
  .pastille{
    padding:1.4vmin 2.4vmin; border-radius:99px; font-size:min(3.4vmin,34px); font-weight:900;
    background:rgba(255,255,255,.07); border:1px solid rgba(255,184,0,.42); color:var(--creme);
    /* Les pastilles se posent depuis le HAUT et non depuis le bas : leur position de départ, même
       invisible, agrandissait la zone de défilement de la ligne des actes de 10 à 13 px — vers le
       haut elle ne compte pas, et la seule règle qui vaille ici reste vérifiable à zéro. */
    opacity:0; transform:translateY(-1.4vmin) scale(.94);
  }
  .acte.on .pastille{animation:entrePastille .5s cubic-bezier(.2,1.5,.4,1) forwards}
  @keyframes entrePastille{to{opacity:1; transform:none}}

  /* Acte 3 — le geste. */
  .geste{display:flex; align-items:center; justify-content:center; gap:2.4vmin; flex-wrap:wrap}
  .pas{display:grid; place-items:center; gap:.8vmin}
  .pas .rond{
    width:min(11vmin,110px); height:min(11vmin,110px); border-radius:50%; display:grid; place-items:center;
    font-size:min(5vmin,52px); background:rgba(255,184,0,.14); border:2px solid rgba(255,184,0,.5);
  }
  .pas .mot{font-size:min(3.1vmin,32px); font-weight:900}
  .fleche-h{font-size:min(4.4vmin,46px); opacity:.55}

  /* ── CÔTÉ DROIT : LE QR, IMMOBILE ───────────────────────────────────────────────────────── */
  /* `align-content:center` garde le trio flèche / QR / phrase SOUDÉ : réparti sur la hauteur, la
     flèche se retrouvait à un demi-écran du QR qu'elle est censée désigner. */
  .droite{
    display:grid; place-items:center; align-content:center; text-align:center;
    gap:1.6vmin; min-width:0; min-height:0;
  }
  /* Anneau qui pulse autour du QR : il désigne l'endroit sans rien recouvrir. L'espace qu'il
     occupe est RÉSERVÉ (padding) et sa respiration se fait vers l'intérieur : en débordant de sa
     boîte il sortait de la mise en page de 18 px, et rien ne garantissait qu'un écran plus court
     ne le rogne. */
  .qr-boite{position:relative; display:inline-block; padding:2.4vmin}
  .qr-boite::before{
    content:''; position:absolute; inset:0; border-radius:5vmin;
    border:.5vmin solid rgba(255,184,0,.42); animation:pulseAnneau 2.8s ease-in-out infinite;
  }
  @keyframes pulseAnneau{
    0%,100%{transform:scale(.972); opacity:.5}
    50%{transform:scale(1); opacity:1}
  }
  .qr{background:#fff; border-radius:3vmin; padding:2.2vmin; display:block;
      box-shadow:0 2vmin 6vmin rgba(0,0,0,.5)}
  /* Le terme en `vh` empêche le QR de manger la hauteur du message sur un écran bas. */
  .qr svg{display:block; width:min(38vmin,46vh,480px); height:auto}
  .qr-mots{display:grid; gap:.6vmin}
  .scanne{margin:0; font-size:min(4.4vmin,46px); font-weight:900; letter-spacing:-.01em}
  /* Plancher en px : à 2,4vmin cette phrase tombait à 18 px sur une petite tablette, soit
     3,5 mm de haut — sous la limite de lecture debout à trois mètres. */
  .detail{margin:0; font-size:clamp(22px,2.4vmin,28px); opacity:.78; line-height:1.45}
  .fleche{font-size:min(5vmin,52px); line-height:1; animation:pointe 2.2s ease-in-out infinite}
  @keyframes pointe{0%,100%{transform:translateY(0)}50%{transform:translateY(1.1vmin)}}

  @media (max-aspect-ratio:1/1){
    /* En portrait, le QR se place À CÔTÉ de son texte au lieu d'être empilé dessus : empilés,
       les deux mangeaient les 150 px de hauteur qui manquaient aux lots, et la dernière pastille
       finissait sous le carré blanc. La flèche disparaît — le QR est déjà juste en dessous du
       message, elle ne désigne plus rien. */
    .droite{grid-auto-flow:column; justify-content:center; gap:3.2vmin; text-align:left}
    .fleche{display:none}
    .qr-mots{max-width:min(40vmin,360px)}
    /* Un cran plus petit qu'en paysage : en portrait, tout est empilé et chaque pixel rendu au QR
       est un pixel pris à la roue. 31vmin ≈ 4,8 cm sur une tablette, largement scannable au
       comptoir. */
    .qr svg{width:min(31vmin,42vh,420px)}
  }

  /* ── BANDEAU BAS : la condition, écrite une fois pour toutes ────────────────────────────── */
  /* Plancher en px pour la même raison que ci-dessus, et opacité remontée de 0,66 à 0,8 : c'est
     la SEULE mention des conditions du jeu, donc une information contractuelle. */
  .bandeau{
    flex:0 0 auto; padding:1.2vmin 3vmin; text-align:center;
    border-top:1px solid rgba(255,184,0,.14);
    background:linear-gradient(0deg, rgba(0,0,0,.55), transparent);
    font-size:clamp(20px,2.2vmin,26px); opacity:.8;
  }

  /* ── QUAND LE QR NE PEUT PAS ÊTRE FABRIQUÉ ─────────────────────────────────────────────────
     Cet écran est posé FACE AUX CLIENTS : sans code à scanner, il ne doit plus rien promettre.
     La version précédente continuait d'afficher « Tu gagnes à 100 % », d'annoncer les sept lots
     et de faire tourner la roue — une publicité pour un jeu auquel personne ne pouvait jouer —
     avec pour seule explication un nom de variable d'environnement. Ici : aucune promesse, aucune
     roue, aucun lot. Un écran sobre adressé à l'ÉQUIPE, et le détail technique en petit. */
  .panne{
    flex:1 1 auto; min-height:0; display:grid; place-content:center; justify-items:center;
    gap:2.4vmin; padding:6vmin; text-align:center;
  }
  .panne .logo{height:min(6vmin,62px); opacity:.65}
  .panne-titre{margin:0; font-size:min(6vmin,58px); font-weight:900; letter-spacing:-.015em; color:var(--jaune2)}
  .panne-quoi{margin:0; font-size:clamp(22px,3vmin,34px); line-height:1.4; max-width:34ch; opacity:.92}
  .panne-faire{
    margin:0; font-size:clamp(20px,2.6vmin,30px); line-height:1.45; max-width:44ch;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,184,0,.3);
    border-radius:2vmin; padding:2.2vmin 2.8vmin;
  }
  .panne-faire b{color:var(--jaune2)}
  .panne-tech{
    margin:0; max-width:60ch; opacity:.45; line-height:1.4;
    font-size:clamp(14px,1.6vmin,18px);
    font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  }

  @media (prefers-reduced-motion:reduce){
    .fond,.fleche,.qr-boite::before,.acte.on .pastille{animation:none}
    .acte{transition:none}
    .pastille{opacity:1; transform:none}
  }
</style>
</head>
<body>

@if (! empty($erreur))

  {{-- Pas de lueur orange ici : c'est elle qui donne à l'écran son air de publicité. --}}
  <main class="panne">
    <img class="logo" src="{{ asset('images/kiosk-attract/logo.png') }}" alt="Le Cayenne">
    <p class="panne-titre">Roue indisponible</p>
    <p class="panne-quoi">
      Cet écran ne peut pas fabriquer le code à scanner.
      Aucun jeu n'est proposé aux clients pour le moment.
    </p>
    <p class="panne-faire">
      À faire par l'équipe : ouvrir <b>Réglages de la roue</b> dans l'administration et compléter
      ce qui manque. Cet écran se remet en route tout seul dès que c'est réparé.
    </p>
    <p class="panne-tech">{{ $erreur }}</p>
  </main>

@else

  <div class="fond" aria-hidden="true"></div>

  <main class="scene">

    <div class="gauche">
      {{-- Le VRAI logo, en grand : c'est la première chose vue de loin, et c'est ce qui fait
           reconnaître l'écran comme celui du restaurant et non une publicité quelconque. --}}
      <img class="logo" src="{{ asset('images/kiosk-attract/logo.png') }}" alt="Le Cayenne">

      {{-- Le repère qui désigne le lot gagnant est DESSINÉ dans le canvas (voir plus bas) : la
           roue change de taille pour laisser la place au texte, et un triangle posé à côté en
           HTML se décrochait de son sommet. Le `aria-label` donne la liste des lots hors de
           l'acte 2 : sans lui, la roue est une image muette les deux tiers du temps. --}}
      <canvas class="roue" id="roue" width="720" height="720" role="img"
              aria-label="Roue des lots : {{ implode(', ', array_column($segments ?? [], 'label')) ?: 'à découvrir' }}"></canvas>

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
             déclenche rien, et les libellés viennent du serveur, jamais d'une liste écrite ici.
             C'est aussi l'endroit où les lots sont VRAIMENT lisibles : sur la roue, la géométrie
             d'un secteur limite la taille du texte, ici non. --}}
        <div class="acte" data-acte="1">
          <p class="titre2">Aujourd'hui, on distribue</p>
          <div class="lots">
            {{-- TOUS les lots, sans coupe — et désormais sans coupe POUR DE VRAI : la ligne des
                 actes n'est plus une boîte de hauteur devinée, et le QR n'est plus posé par-dessus.
                 En tronquer un ferait dire à l'écran « on distribue ceci » pendant que la roue en
                 montre un de plus juste au-dessus — le client remarque l'écart, et c'est le genre
                 de détail qui fait douter du reste. --}}
            @foreach ($segments ?? [] as $i => $s)
              <span class="pastille" style="animation-delay:{{ 0.12 * $i }}s">{{ $s['label'] }}</span>
            @endforeach
          </div>
        </div>

        {{-- ACTE 3 — LE GESTE. Trois pastilles, mais DEUX gestes seulement : « scanne » puis
             « tourne ». La troisième est la récompense, pas une corvée de plus — c'est la
             différence entre annoncer un parcours long (qui fait reposer le téléphone) et
             annoncer une fin heureuse. Ne pas la relire comme une étape à supprimer. --}}
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

    <div class="droite">
      <div class="fleche" aria-hidden="true">↓</div>
      <div class="qr-boite">
        <div class="qr" id="qr">{!! $qr !!}</div>
      </div>
      <div class="qr-mots">
        <p class="scanne">Scanne avec ton téléphone</p>
        {{-- [2026-08-12 · propriétaire : « ça va prendre moins d'une minute, c'est pas bien vu que
             ça va prendre quelques secondes »] Il a raison, et pas seulement sur la durée : annoncer
             « moins d'une minute » à quelqu'un qui attend sa commande, c'est lui donner une raison de
             ne pas commencer. « Quelques secondes » est à la fois plus vrai — le parcours mesuré tient
             en une vingtaine de secondes — et moins coûteux à décider. --}}
        <p class="detail">Aucune application à installer.<br>Ça prend quelques secondes.</p>
      </div>
    </div>

  </main>

  <p class="bandeau">Un tour par personne — le lot est à utiliser sur une prochaine commande.</p>

@endif

<script>
(function () {
  'use strict';

  /* Les libellés viennent du SERVEUR. Aucune liste écrite dans cette page : une roue qui affiche
     autre chose que ce qu'elle donne est un mensonge, et le client le découvre au pire moment. */
  var LOTS = @json(array_column($segments ?? [], 'label'));
  // Les CLÉS, dans le même ordre que les libellés : c'est par elles qu'on sait sur lesquels
  // l'animation a le droit de s'arrêter.
  var LOTS_CLES = @json(array_column($segments ?? [], 'key'));
  var LOTS_ARRET = @json($spinnable ?? []);
  // LISTE DE SECOURS, si le serveur n'a pas répondu. Elle doit rester le REFLET des lots réels :
  // le 12 août elle annonçait encore « -10% » et « 50 points », des lots que la roue ne donne plus.
  // Une roue de secours qui promet autre chose que la vraie est un mensonge de plus, pas un filet.
  if (!LOTS.length) { LOTS = ['Boisson', 'Frites', 'Tiramisu', 'Tarte Daim', 'Cheese Burger', 'Cayenne', 'Terminator']; }

  var cv = document.getElementById('roue');
  var ctx = cv && cv.getContext ? cv.getContext('2d') : null;
  var lent = matchMedia('(prefers-reduced-motion: reduce)').matches;

  var COTE = 720, R = COTE / 2, RAYON = R - 8, TAU = Math.PI * 2;
  var angle = 0;
  var feu = 0;                 // éclat résiduel juste après un arrêt (1 → 0)

  /* ── LES COULEURS ─────────────────────────────────────────────────────────────────────────
     Une palette FIXE de six teintes laissait deux secteurs VOISINS identiques dès que le nombre
     de lots n'était pas un multiple de six : avec sept lots, le premier et le dernier se
     touchaient dans le même orange, la roue paraissait n'avoir que six cases, et un lot semblait
     donc deux fois plus probable. La teinte est maintenant CALCULÉE : une case sur deux dans
     l'orange de la marque, l'autre dans son jaune — l'alternance d'une fête foraine — PLUS un
     léger glissement en fonction du rang. C'est ce glissement qui sauve le raccord du tour quand
     le nombre de lots est impair : les deux cases qui se rejoignent appartiennent alors à la même
     famille, et sans lui elles seraient identiques. */
  function teinte(i, N, dl) {
    var jaune = i % 2 === 1, glisse = i / N;
    var h = jaune ? 40 + 6 * glisse : 11 + 11 * glisse;
    var l = Math.max(10, Math.min(92, (jaune ? 57 : 45) + 7 * glisse + dl));
    return 'hsl(' + h.toFixed(1) + ',' + (jaune ? 97 : 88) + '%,' + l.toFixed(1) + '%)';
  }

  /* ── LA TAILLE DES LIBELLÉS ───────────────────────────────────────────────────────────────
     Le texte est dessiné dans le repère du canvas (720 px) puis AFFICHÉ à 340–530 px selon
     l'écran. Une police écrite « 25 px » ne faisait donc plus que 12 px à l'écran : le texte le
     plus petit de la page, alors que c'est lui qui dit ce qu'on gagne. On part maintenant d'une
     taille voulue À L'ÉCRAN et on la convertit — l'échelle est MESURÉE, jamais supposée.
     Puis on réduit juste ce qu'il faut pour que le mot tienne dans son secteur : sa longueur le
     long du rayon, et l'épaisseur de ses deux lignes en travers. Le résultat est mis en cache,
     il ne dépend que de l'échelle d'affichage. */
  var VISEE = 30;              // hauteur de police voulue à l'écran, en px CSS
  var R_TEXTE = R * 0.615;     // milieu du mot, sur le rayon
  var R_MIN = 80, R_MAX = 316; // bande utile : après le moyeu, avant le repère et les ampoules
  var echelle = 1, cache = null, cacheEchelle = -1;

  function police(t) { return '900 ' + t.toFixed(1) + 'px -apple-system,Segoe UI,Roboto,sans-serif'; }

  function mesurerEchelle() {
    var l = cv ? cv.getBoundingClientRect().width : 0;
    echelle = l > 4 ? COTE / l : 1;
  }

  function libelles() {
    if (cache && cacheEchelle === echelle) { return cache; }
    var N = LOTS.length;
    cache = LOTS.map(function (brut) {
      // Deux lignes pour les libellés longs : « Frites + Boisson » sur une seule ligne sort du
      // secteur et se fait rogner par le bord de la roue.
      var mots = String(brut).split(' ');
      var coupe = Math.ceil(mots.length / 2);
      var l1 = mots.length > 1 ? mots.slice(0, coupe).join(' ') : mots[0];
      var l2 = mots.length > 1 ? mots.slice(coupe).join(' ') : '';
      var t = VISEE * echelle;
      for (var k = 0; k < 14; k++) {
        ctx.font = police(t);
        var demi = Math.max(ctx.measureText(l1).width, l2 ? ctx.measureText(l2).width : 0) / 2;
        var interne = R_TEXTE - demi;
        var epais = l2 ? 1.95 * t : 0.8 * t;                 // encre en travers du rayon
        var place = TAU * Math.max(interne, 30) / N * 0.86;   // largeur du secteur à cet endroit
        if (interne >= R_MIN && R_TEXTE + demi <= R_MAX && epais <= place) { break; }
        t *= 0.94;
      }
      return { l1: l1, l2: l2, t: t };
    });
    cacheEchelle = echelle;
    return cache;
  }

  function dessiner() {
    if (!ctx) return;
    var N = LOTS.length, pas = TAU / N, libs = libelles();
    ctx.clearRect(0, 0, COTE, COTE);
    ctx.save();
    ctx.translate(R, R);
    ctx.rotate(angle);

    for (var i = 0; i < N; i++) {
      var a0 = i * pas - Math.PI / 2, a1 = a0 + pas;

      var g = ctx.createRadialGradient(0, 0, RAYON * 0.18, 0, 0, RAYON);
      g.addColorStop(0, teinte(i, N, 10));
      g.addColorStop(1, teinte(i, N, -12));

      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, RAYON, a0, a1);
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
      var abs = ((am + angle) % TAU + TAU) % TAU;
      var retourne = abs > Math.PI / 2 && abs < Math.PI * 1.5;
      ctx.translate(R_TEXTE, 0);
      if (retourne) { ctx.rotate(Math.PI); }
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = '#fff';
      ctx.shadowColor = 'rgba(0,0,0,.55)';
      ctx.shadowBlur = 7;
      var L = libs[i];
      ctx.font = police(L.t);
      /* Un cerne sombre sous le blanc : une case jaune de fête foraine est presque aussi claire
         que le texte posé dessus (1,5:1). Le cerne rend le mot lisible sans assombrir la case,
         donc sans perdre la couleur de la marque. */
      ctx.lineWidth = Math.max(2, L.t * 0.1);
      ctx.strokeStyle = 'rgba(60,24,0,.62)';
      ctx.lineJoin = 'round';
      var lignes = L.l2 ? [[L.l1, -L.t * 0.56], [L.l2, L.t * 0.56]] : [[L.l1, 0]];
      for (var m = 0; m < lignes.length; m++) { ctx.strokeText(lignes[m][0], 0, lignes[m][1]); }
      for (var f = 0; f < lignes.length; f++) { ctx.fillText(lignes[f][0], 0, lignes[f][1]); }
      ctx.restore();
    }
    ctx.restore();

    /* Ampoules de fête foraine : elles font la différence entre « un camembert » et « une roue ». */
    ctx.save();
    ctx.translate(R, R);
    for (var k = 0; k < 24; k++) {
      var aa = angle * 0.5 + (k / 24) * TAU;
      var vive = (Math.floor(Date.now() / 260) + k) % 3 === 0;
      ctx.beginPath();
      ctx.arc(Math.cos(aa) * (R - 2), Math.sin(aa) * (R - 2), vive ? 7 : 5, 0, TAU);
      ctx.fillStyle = vive ? '#FFF3CF' : 'rgba(255,220,150,.55)';
      ctx.shadowColor = 'rgba(255,200,90,.9)';
      ctx.shadowBlur = vive ? 16 : 7;
      ctx.fill();
    }
    // Moyeu.
    ctx.beginPath();
    ctx.arc(0, 0, 58, 0, TAU);
    var gm = ctx.createRadialGradient(-14, -18, 6, 0, 0, 58);
    gm.addColorStop(0, '#FFE9A8');
    gm.addColorStop(1, '#C9791A');
    ctx.fillStyle = gm;
    ctx.shadowColor = 'rgba(0,0,0,.45)';
    ctx.shadowBlur = 18;
    ctx.fill();
    ctx.restore();

    /* Éclat au moment où la roue s'immobilise : c'est CE flash qui fait tourner les têtes. Il est
       peint SUR la roue plutôt que posé en calque HTML — un calque était positionné par rapport à
       une boîte qui ne fait plus la taille de la roue, donc décalé. */
    if (feu > 0.01) {
      ctx.save();
      ctx.translate(R, R);
      var ge = ctx.createRadialGradient(0, 0, RAYON * 0.25, 0, 0, RAYON);
      ge.addColorStop(0, 'rgba(255,240,200,0)');
      ge.addColorStop(1, 'rgba(255,228,155,' + (0.6 * feu).toFixed(3) + ')');
      ctx.globalCompositeOperation = 'lighter';
      ctx.beginPath();
      ctx.arc(0, 0, RAYON, 0, TAU);
      ctx.fillStyle = ge;
      ctx.fill();
      ctx.restore();
    }

    /* LE REPÈRE qui désigne le lot gagnant. Dessiné ici et pas en HTML à côté : la roue cède de
       la place au texte quand l'écran est court, sa taille d'affichage varie donc, et un triangle
       positionné par rapport au conteneur se décrochait de son sommet. */
    ctx.save();
    ctx.translate(R, 0);
    ctx.beginPath();
    ctx.moveTo(-31, 0);
    ctx.lineTo(31, 0);
    ctx.lineTo(0, 44);
    ctx.closePath();
    ctx.fillStyle = '#FFD34D';
    ctx.shadowColor = 'rgba(0,0,0,.6)';
    ctx.shadowBlur = 16;
    ctx.shadowOffsetY = 5;
    ctx.fill();
    // Un cerne sombre : posé sur un secteur ambré, un triangle jaune sans contour se fond dedans
    // et l'écran n'a plus de repère lisible à trois mètres.
    ctx.shadowColor = 'transparent';
    ctx.lineWidth = 5;
    ctx.strokeStyle = 'rgba(38,18,4,.8)';
    ctx.stroke();
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

    /* ── OÙ LA ROUE A LE DROIT DE S'ARRÊTER ────────────────────────────────────────────────
       [2026-08-12] Un arrêt au hasard uniforme désignait gagnant N'IMPORTE QUEL secteur, y compris
       un lot à probabilité nulle — le Terminator que le propriétaire veut afficher sans le donner.
       Mesuré : 1 arrêt sur 7, toutes les dix secondes, en salle, toute la journée. Rien n'est misé
       ici, mais un client qui voit la roue s'arrêter dix fois sur le Terminator et ne le gagne
       jamais n'a pas tort de se sentir mené en bateau. C'est la tromperie corrigée le 10 août pour
       les lots en rupture, revenue par la porte de la vitrine.

       Le serveur dit lesquels sont réellement distribuables ; on tire parmi ceux-là. S'il n'en donne
       aucun (jeu fermé, liste de secours), on retombe sur tous les secteurs : mieux vaut une roue qui
       tourne qu'une roue figée sur un écran de comptoir. */
    var permis = [];
    for (var pi = 0; pi < N; pi++) {
      if (!LOTS_ARRET.length || LOTS_ARRET.indexOf(LOTS_CLES[pi]) !== -1) { permis.push(pi); }
    }
    if (!permis.length) { for (var pj = 0; pj < N; pj++) { permis.push(pj); } }

    var secteur = permis[Math.floor(Math.random() * permis.length)];
    var pas = TAU / N;
    var arret = -(secteur * pas + pas / 2);
    cible = base + TAU * (3 + Math.floor(Math.random() * 3))
          + ((arret - base) % TAU + TAU) % TAU;
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
        feu = 1;
      }
    } else {
      feu = Math.max(0, 1 - (t - depart) / 900);
      if (t - depart > PAUSE) { nouveauLancer(t); }
    }

    dessiner();
    requestAnimationFrame(boucle);
  }

  if (ctx) {
    mesurerEchelle();
    if (lent) {
      /* MOUVEMENT RÉDUIT : on ne fige pas la roue sur son angle brut. À angle nul, une
         SÉPARATION tombe pile sous le repère, entre deux secteurs — l'écran désigne un trait au
         lieu d'un lot, et il a l'air cassé. On centre donc le premier secteur sous le repère. */
      angle = -(TAU / LOTS.length) / 2;
      dessiner();
    } else {
      requestAnimationFrame(boucle);
    }

    /* La roue prend la place qui reste : sa taille d'AFFICHAGE change avec la fenêtre, donc la
       conversion « px d'écran → px de canvas » des libellés doit être refaite. Sans ça, une roue
       redimensionnée garderait des libellés calculés pour l'ancienne taille. */
    var suivre = function () {
      var avant = echelle;
      mesurerEchelle();
      if (avant !== echelle && lent) { dessiner(); }
    };
    if (window.ResizeObserver) { new ResizeObserver(suivre).observe(cv); }
    else { addEventListener('resize', suivre); }
  }

  /* ── LES TROIS ACTES ──────────────────────────────────────────────────────────────────────
     Un acte toutes les 6,5 s. Assez long pour être lu sans se presser par quelqu'un qui attend sa
     commande, assez court pour que la scène change avant qu'on cesse de la voir. */
  var actes = [].slice.call(document.querySelectorAll('.acte'));
  if (actes.length > 1) {
    var courant = 0;
    /* Sortie PUIS entrée, jamais les deux en même temps : en fondu croisé, deux textes de
       hauteurs différentes se superposaient 700 ms toutes les 6,5 s — un dixième du temps
       d'affichage passé en brouillard, « Tu gagnes à 100 % » lisible en fantôme derrière les
       lots. Un fondu croisé n'est lisible que si les deux états ont la même silhouette. */
    var FONDU = lent ? 0 : 320;
    setInterval(function () {
      actes[courant].classList.remove('on');
      courant = (courant + 1) % actes.length;
      var el = actes[courant];
      setTimeout(function () {
        el.classList.add('on');
        // On relance l'entrée des pastilles à chaque passage : sinon l'acte 2 n'est animé qu'une
        // fois, et paraît figé aux passages suivants.
        [].forEach.call(el.querySelectorAll('.pastille'), function (p) {
          p.style.animation = 'none';
          void p.offsetWidth;
          p.style.animation = '';
        });
      }, FONDU);
    }, 6500);
  }

  /* Renouvellement du jeton : la page se recharge à la MOITIÉ de la durée de vie, pour que le QR
     affiché soit toujours valable au moins autant de temps qu'il en reste à l'écran.
     En panne de QR, on réessaie toutes les minutes : l'écran doit repartir tout seul dès que le
     réglage manquant est saisi, sans que personne n'ait à toucher la tablette. */
  setTimeout(function () { location.reload(); }, {{ empty($erreur) ? (int) $refreshMs : 60000 }});
})();
</script>
</body>
</html>
