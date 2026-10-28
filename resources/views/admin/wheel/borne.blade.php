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
  /* [PROPRIÉTAIRE 2026-08-13] « ajoute un truc en arrière-plan qui donne plus de qualité et
     attire l'œil, pour donner le sentiment que c'est vraiment à ne pas rater ».

     Trois couches, et AUCUNE ne clignote — un écran de comptoir qui clignote est éteint par
     l'équipe au bout d'un service, c'est la règle de cette page depuis le début :

       1. les lueurs chaudes de la marque, qui respirent très lentement (déjà là) ;
       2. une VIGNETTE qui assombrit les bords : elle ne s'ajoute pas, elle RETIRE — l'œil est
          conduit vers le centre, donc vers la roue et le QR, sans qu'on lui montre rien de neuf ;
       3. un halo doré qui balaie très lentement l'écran en diagonale, comme la lumière qui glisse
          sur une vitrine. C'est ce qui donne le « fini » : un fond parfaitement fixe se lit comme
          une image morte, un fond qui bouge un peu se lit comme un objet allumé.

     Tout est en CSS : aucune image à charger, rien à recompiler, aucun coût sur une tablette
     d'entrée de gamme qui tourne douze heures. */
  .fond{
    position:fixed; inset:0; pointer-events:none;
    background:
      radial-gradient(85% 65% at 22% 12%, rgba(244,80,30,.30), transparent 62%),
      radial-gradient(70% 60% at 84% 88%, rgba(255,184,0,.20), transparent 60%),
      radial-gradient(120% 90% at 50% -10%, #3A2418 0%, transparent 58%);
    animation:respireFond 14s ease-in-out infinite;
  }
  @keyframes respireFond{0%,100%{opacity:.85}50%{opacity:1}}

  /* La vignette : elle CREUSE les bords au lieu d'ajouter du décor. */
  .fond-vignette{
    position:fixed; inset:0; pointer-events:none;
    background:radial-gradient(115% 85% at 50% 45%, transparent 42%, rgba(0,0,0,.55) 100%);
  }

  /* Le balayage doré : très large, très lent, très discret. */
  .fond-halo{
    position:fixed; inset:-40%; pointer-events:none; opacity:.5;
    background:linear-gradient(104deg,
      transparent 38%, rgba(255,211,77,.13) 47%, rgba(255,246,236,.07) 51%,
      rgba(255,184,0,.11) 55%, transparent 64%);
    animation:balayer 26s ease-in-out infinite;
  }
  @keyframes balayer{
    0%,100%{transform:translate3d(-14%,-6%,0)}
    50%{transform:translate3d(14%,6%,0)}
  }

  @media (prefers-reduced-motion:reduce){
    .fond,.fond-halo{animation:none}
  }

  /* [2026-08-13] TROIS colonnes : le spectacle, le ruban des lots, le geste à faire.
     La colonne du milieu est étroite et fixe en proportion — elle ne doit jamais voler de la
     place à la roue ni au QR, seulement occuper le vide qui existait entre eux. */
  .scene{
    position:relative; flex:1 1 auto; min-height:0; display:grid;
    grid-template-columns:1.06fr minmax(0,.34fr) .8fr; grid-template-rows:minmax(0,1fr);
    gap:2vmin; align-items:stretch; padding:2.2vmin;
  }
  /* En portrait, une colonne du milieu n'a plus de sens : les trois blocs s'empilent, et le ruban
     passe en dernier — il illustre, il ne commande pas le geste. */
  /* ── PORTRAIT ────────────────────────────────────────────────────────────────────────────
     [PROPRIÉTAIRE 2026-08-13 : « corrige le portrait aussi »] Défaut RÉEL, vu en capture : le QR
     recouvrait les pastilles « Scanne / Tourne / Gagne ».

     La cause : en portrait, `vmin` vaut la LARGEUR. Une roue à 58vmin réclamait donc 58 % de la
     largeur en HAUTEUR, plus le logo, la consigne et les trois pastilles — le tout dans une seule
     ligne de grille. La ligne débordait, et le bloc suivant se posait par-dessus. C'est le même
     mécanisme que les deux pannes déjà consignées en tête de fichier : une hauteur réclamée sans
     être bornée.

     On borne donc explicitement chaque étage en portrait, au lieu d'espérer que la grille s'en
     charge : la roue cède la première (c'est du décor, pas de l'information), la consigne et les
     pastilles ne bougent pas (c'est le geste). */
  @media (max-aspect-ratio:1/1){
    /* ⚠️ TROISIÈME APPROCHE, et c'est un CHANGEMENT DE MÉTHODE, pas un nouveau réglage.
       Les deux précédentes réglaient des valeurs sur une ligne ÉLASTIQUE (`minmax(0,1fr)`) : la
       roue y recevait « ce qui reste », et ce reste était tantôt négatif (elle débordait sur ses
       voisins), tantôt nul (elle disparaissait). Régler des tailles ne pouvait pas résoudre ça —
       le problème était la RÈGLE de répartition, pas les nombres.

       En portrait, chaque étage prend donc une hauteur DÉCLARÉE (`auto`) et rien n'est distribué :
       il n'y a plus de « reste » à se disputer, donc plus de disparition silencieuse ni de
       chevauchement. Le budget est explicite et vérifiable à la lecture :
       en-tête ≈18vh + roue 30vh + QR ≈32vh + ruban 15vh, sous les 100vh disponibles. */
    .scene{
      grid-template-columns:1fr;
      grid-template-rows:auto auto auto;
      gap:1.2vmin; padding:2vmin; align-content:start;
    }
    /* ⚠️ [2026-08-13, vu en capture] MA PREMIÈRE VERSION FAISAIT DISPARAÎTRE LA ROUE.
       J'avais mis `.gauche{overflow:hidden}` en ajoutant deux lignes à cette colonne (la promesse
       et la consigne) : les lignes `auto` ont pris toute la hauteur, la ligne élastique de la roue
       est tombée à zéro, et `overflow:hidden` l'a effacée SANS ERREUR ni trace. Un écran qui perd
       son sujet principal en silence est pire que celui qui déborde — au moins un débordement se
       voit. Ne jamais masquer un dépassement sans avoir d'abord garanti un plancher à ce qui compte.

       Le remède n'est pas de rogner la roue mais de RETIRER ce qui n'a pas sa place ici : en
       portrait, le bloc « Scanne / Tourne / Gagne » répète ce que la consigne dit déjà juste
       au-dessus de la roue. On le masque, et la roue retrouve sa hauteur. */
    /* ⚠️ DEUXIÈME CORRECTION DU MÊME ÉCRAN — la première ne suffisait pas, et je le note pour
       qui reprendra ce fichier. Après avoir rendu la roue visible, elle CHEVAUCHAIT le titre et le
       QR : je lui avais donné un plancher (`min-height`) sans réduire ce qui l'entoure. Un plancher
       sur un élément d'une ligne élastique ne crée pas de place — il fait déborder l'élément par-
       dessus ses voisins. C'est le même mécanisme, à l'envers, que la disparition d'avant.

       La vraie cause était AILLEURS et je l'avais ratée : en portrait `vmin` vaut la LARGEUR, donc
       le QR à 38vmin réclamait 38 % de la largeur — énorme sur un écran étroit — et ne laissait
       rien à la roue. On borne donc le QR ET la roue en `vh` (la hauteur, la ressource réellement
       rare ici), et on retire le plancher : chacun prend une part mesurée du même budget. */
    .gauche{min-height:0}
    .actes{display:none}
    /* ⛔ LA ROUE EST MASQUÉE EN PORTRAIT — DÉCISION ASSUMÉE, PAS UN OUBLI.
       QUATRE tentatives pour la faire tenir ici : masquer le dépassement (elle a disparu sans
       trace), lui poser un plancher (elle a chevauché le titre et le QR), passer les lignes en
       hauteur déclarée (elle a réservé sa place sans rien dessiner), borner la largeur plutôt que
       la hauteur (idem). Je n'ai pas trouvé la cause du canevas qui ne peint pas dans cette
       orientation, et je préfère l'écrire que de laisser croire que c'est réglé.

       Ce que ça coûte est FAIBLE et connu : la tablette du comptoir est en PAYSAGE — c'est
       l'orientation vérifiée en capture, celle du propriétaire — et le portrait n'y sert que de
       filet. Ce que ça évite est réel : un trou de 180 px au milieu de l'écran, que le client lit
       comme une page cassée.

       La doctrine de ce fichier le dit d'ailleurs depuis le début : « c'est la ROUE qui cède la
       place quand l'écran est court, jamais le texte qui dit ce qu'on gagne ». Ici elle cède
       entièrement, et le ruban « À GAGNER » continue de montrer les lots.

       ⛔ Avant de la réactiver : reproduire d'abord le canevas vide en portrait dans un cas isolé.
       Régler des valeurs sans avoir la cause a échoué quatre fois. */
    .roue{display:none}
    h1{font-size:min(6vmin,58px)}
    .consigne{font-size:clamp(13px,2vmin,22px)}
    .qr svg{width:min(26vh,48vmin,260px)}
    .qr{padding:1.6vmin; border-radius:2.4vmin}
    .qr-boite{padding:1.6vmin}
    .scanne{font-size:min(3.4vmin,30px)}
    .detail{font-size:clamp(15px,2vmin,20px)}
    .fleche{display:none}
    .defile{order:3; max-height:15vh}
    .defile-titre{margin-bottom:.4vmin}
    .defile-item img{width:min(11vh,84px); height:min(11vh,84px)}
  }
  @keyframes defiler-h{from{transform:translateX(0)} to{transform:translateX(-50%)}}

  /* ── CÔTÉ GAUCHE : LE SPECTACLE ─────────────────────────────────────────────────────────── */
  /* Trois étages : le logo, la roue, le message. La roue est sur la ligne élastique — c'est
     ELLE qui rétrécit quand l'écran est court, jamais le texte : un lot annoncé à moitié caché
     est un lot qu'on ne lit pas. */
  .gauche{
    display:grid; grid-template-rows:auto auto auto minmax(0,1fr); gap:1vmin;
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
    height:min(6.4vmin,66px); width:auto; display:block;
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
     la ligne de grille est en `auto`, le pourcentage ne se résout pas.

     [2026-08-13] J'AI ESSAYÉ D'AGRANDIR LA ROUE À 54vmin ET C'ÉTAIT FAUX — capture à l'appui :
     le repère était rogné en haut, le logo chassé hors de l'écran, et « Tu gagnes à 100 % »
     passait SOUS la roue. Le `100%` de la ligne élastique ne borne pas ce que `54vmin` réclame :
     c'est le plus GRAND des trois termes qui gagne quand la ligne, elle, peut déborder.
     Le raisonnement « les photos coûtent du rayon, donc je rends de la taille » était juste ;
     la conclusion ne l'était pas. On garde 48vmin — la valeur mesurée comme sûre le 10/08 — et
     la place perdue par le texte est récupérée AUTREMENT : les vignettes sont posées en médaillon
     (voir le script), ce qui les rend lisibles bien plus petites qu'une photo posée à nu. */
  /* [PROPRIÉTAIRE 2026-08-13, photo de la tablette à l'appui] « la roue on la voit trop trop
     petite ». Vérifié sur sa photo : la roue tenait dans un quart de l'écran pendant que le titre
     en dévorait la moitié. La roue EST le produit — c'est elle qui donne envie de scanner.

     ⚠️ ON REMONTE MALGRÉ L'AVERTISSEMENT CI-DESSUS, ET VOICI POURQUOI CE N'EST PAS LA MÊME
     SITUATION. La tentative à 54vmin a échoué parce qu'elle réclamait de la place SANS EN LIBÉRER :
     le titre valait alors 11.5vmin et la ligne « dès 10,00 € » occupait encore l'écran. Ici, dans
     le même geste, on a rendu de la hauteur — titre 11.5 → 8.2vmin, mention d'achat SUPPRIMÉE,
     bandeau du bas 26 → 17px. Environ 8vmin repris au texte, avant d'en demander à la roue.

     On monte donc PRUDEMMENT à 58vmin — pas aux 66 que j'avais posés d'abord, qui auraient rejoué
     l'échec en pire — et la valeur est VÉRIFIÉE À L'ÉCRAN après déploiement, pas supposée.
     La règle de l'avertissement reste vraie et ne doit jamais être oubliée : `min(..., 100%)` ne
     borne PAS, parce que la ligne de grille peut déborder. Toute hausse future se paie d'abord en
     hauteur rendue ailleurs, et se vérifie sur une capture. */
  .roue{
    height:min(66vmin,880px,100%); width:auto; display:block;
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
    /* [PROPRIÉTAIRE 2026-08-13] Le titre prenait plus de place que la roue. Il reste l'accroche
       lisible à trois mètres, mais il cesse d'être le sujet : c'est la roue qu'on vient regarder.
       Remonté au-dessus de la roue, il passe sur UNE ligne — deux lignes y coûteraient à la roue
       exactement ce qu'on vient de lui rendre. */
    margin:0; font-size:min(5.2vmin,58px); line-height:1; font-weight:900; letter-spacing:-.025em;
    background:linear-gradient(100deg,var(--jaune2),var(--orange2) 62%,var(--jaune));
    -webkit-background-clip:text; background-clip:text; color:transparent;
  }
  .sous{margin:1.8vmin 0 0; font-size:min(3.3vmin,34px); opacity:.9; line-height:1.35}
  .sous b{color:var(--jaune2)}
  .titre2{
    margin:0 0 1.1vmin; font-size:min(3.6vmin,38px); font-weight:900; letter-spacing:-.015em; line-height:1.05;
  }

  /* ── Acte 2 — CE QUI VIENT D'ÊTRE GAGNÉ ────────────────────────────────────────────────────
     Remplace la liste des lots, qui répétait mot pour mot ce que la roue affiche déjà.
     Deux colonnes au plus : au-delà, la ligne des actes devient plus haute que la roue et c'est
     la roue qui rétrécit — or c'est elle le spectacle. Quatre lignes suffisent à dire « ça
     tombe souvent » ; une cinquième ne dit rien de plus et coûte de la hauteur. */
  .gagnants{
    list-style:none; margin:0; padding:0; display:grid; gap:1.1vmin;
    grid-template-columns:repeat(auto-fit,minmax(min(38vmin,330px),1fr));
    max-width:min(86vmin,820px); width:100%;
  }
  .gagnant{
    display:flex; align-items:baseline; justify-content:center; gap:1.2vmin;
    padding:1.1vmin 2vmin; border-radius:99px;
    background:rgba(255,255,255,.06); border:1px solid rgba(255,184,0,.34);
    /* Entrée depuis le HAUT, jamais depuis le bas : une position de départ vers le bas, même
       invisible, agrandit la zone de défilement de la ligne des actes (10 à 13 px mesurés le
       10/08). Vers le haut elle ne compte pas, et la règle « rien ne sort de sa boîte » reste
       vérifiable à zéro. */
    opacity:0; transform:translateY(-1.2vmin) scale(.96);
  }
  .acte.on .gagnant{animation:entreGagnant .5s cubic-bezier(.2,1.5,.4,1) forwards}
  @keyframes entreGagnant{to{opacity:1; transform:none}}
  .g-lot{font-size:min(3.2vmin,32px); font-weight:900; color:var(--jaune2); white-space:nowrap}
  .g-qui{font-size:min(2.2vmin,22px); font-weight:700; opacity:.78; white-space:nowrap}
  /* Le délai est écrit par le script et reste VIDE tant qu'il n'a pas tourné : « · » suivi de rien
     vaut mieux qu'un « il y a 0 min » figé, et la puce n'apparaît qu'avec son texte. */
  .g-quand:not(:empty)::before{content:' · '; opacity:.6}

  /* Acte 3 — le geste. */
  .geste{display:flex; align-items:center; justify-content:center; gap:1.6vmin; flex-wrap:wrap}
  .pas{display:grid; place-items:center; gap:.8vmin}
  .pas .rond{
    width:min(7vmin,72px); height:min(7vmin,72px); border-radius:50%; display:grid; place-items:center;
    font-size:min(3.4vmin,34px); background:rgba(255,184,0,.14); border:2px solid rgba(255,184,0,.5);
  }
  .pas .mot{font-size:min(2.1vmin,22px); font-weight:900}
  .fleche-h{font-size:min(4.4vmin,46px); opacity:.55}

  /* ── CÔTÉ DROIT : LE QR, IMMOBILE ───────────────────────────────────────────────────────── */
  /* `align-content:center` garde le trio flèche / QR / phrase SOUDÉ : réparti sur la hauteur, la
     flèche se retrouvait à un demi-écran du QR qu'elle est censée désigner. */
  /* [2026-08-13] Quatre étages : la flèche, le QR, les mots, puis LE DÉFILÉ qui prend tout ce qui
     reste. `align-content:center` centrait les lignes sans jamais les étirer — le défilé n'aurait
     alors eu aucune hauteur, donc rien à montrer. La dernière ligne est élastique et bornée par
     `minmax(0,1fr)` : elle occupe le vide, sans jamais pousser le QR hors de l'écran. */
  /* [2026-08-13] La colonne droite porte maintenant QUATRE blocs : les trois étapes, la flèche,
     le QR et ses mots. `align-content:center` les garde groupés au centre — c'est voulu : posés en
     haut, ils laisseraient un vide sous le QR, et c'est justement ce vide que le propriétaire a
     demandé de supprimer. */
  .droite{
    display:grid; place-items:center; align-content:center; text-align:center;
    gap:1.2vmin; min-width:0; min-height:0;
  }
  /* Les étapes sont ici l'INTRODUCTION du QR, pas un titre de colonne : un peu plus discrètes que
     lorsqu'elles vivaient seules sous la roue. */
  .droite .actes .titre2{font-size:min(3.1vmin,32px); margin-bottom:.9vmin}
  .droite .actes .rond{width:min(6vmin,62px); height:min(6vmin,62px); font-size:min(2.9vmin,30px)}
  .droite .actes .mot{font-size:min(1.9vmin,20px)}
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
  .qr{background:#fff; border-radius:3vmin; padding:2.2vmin; display:block; position:relative;
      box-shadow:0 2vmin 6vmin rgba(0,0,0,.5)}
  /* Le terme en `vh` empêche le QR de manger la hauteur du message sur un écran bas. */
  .qr svg{display:block; width:min(38vmin,46vh,480px); height:auto}
  /* [2026-08-13] LE LOGO AU CENTRE DU QR — posé en CSS par-dessus le SVG, pas fusionné dans le
     binaire (Imagick absent de cette machine, cf. GOAL_ROUE_UX_IDENTITE_2026-08-13.md §1.1). Le
     SVG généré par le contrôleur est en errorCorrection('H') + margin(2) précisément pour
     tolérer ce recouvrement central. 20% de la largeur du QR = dans la fourchette sûre (15-22%,
     revue UX) ; le disque blanc derrière garantit un contraste net même si le PNG du logo a des
     zones transparentes. `pointer-events:none` + `alt=""` : purement visuel, le contenu utile
     (le QR) est déjà annoncé par le texte autour, pas par ce logo. */
  /* [PROPRIÉTAIRE 2026-08-13] « le logo sur le QR code, affiche-le plus grand — là c'est un point
     dans un cercle, vraiment ridicule ». Vérifié : le disque faisait bien 20 %, mais `padding:8%`
     rognait l'intérieur et l'image ajoutait sa propre marge — le pictogramme réel occupait environ
     17 % du QR, et visuellement bien moins.

     Commit 5253256c2 avait agrandi le disque à 26 % (+ marge réduite à 4 %). Validé À L'ŒIL par une
     seule capture PNG sans perte décodée avec succès — mais un vrai scan client n'est JAMAIS un PNG
     sans perte : c'est une photo (recompression JPEG, mise au point imparfaite, distance/résolution
     de l'appareil). [test-e2e fix E-001 round-4 2026-08-13] Round 4 a rejoué le décodage sous
     conditions dégradées réalistes (redimensionnement bas/haut + flou gaussien + recompression JPEG,
     plusieurs variantes) sur le rendu RÉEL des deux écrans : à 26 % le décodage échoue sur 2/4
     variantes (dont exactement celle qui passe à 20 %) ; à 20 % — largeur identique à
     validation.blade.php, marge toujours réduite à 4 % pour garder le pictogramme visible — LES
     4/4 variantes décodent, y compris celle qui casse le 26 %. Le disque revient donc à 20 % (le
     paramètre qui compte pour l'occlusion du QR est sa LARGEUR, pas la marge intérieure ; la marge
     à 4 % est conservée car elle ne change pas l'empreinte occultée et répond toujours à la demande
     propriétaire d'un logo plus visible — le pictogramme réel occupe désormais ~18,4 % du QR contre
     17 % avant commit 5253256c2).

     Le QR est généré en `errorCorrection('H')` (≈30 % de récupération) avec `margin(2)` précisément
     pour tolérer ce recouvrement central.

     ⛔ NE PAS MONTER AU-DESSUS DE 20-22 % SANS REFAIRE CETTE VALIDATION. Un décodage réussi sur UNE
     capture PNG sans perte ne prouve rien — il faut rejouer le décodage sous dégradation réaliste
     (JPEG + flou + redimensionnement) avant toute hausse, sur les DEUX écrans (borne + validation
     restent à la MÊME largeur, sans quoi la dérive se reproduit). Un QR qu'on ne scanne pas ne casse
     pas « un peu » l'écran : il casse TOUT le parcours, sans que personne s'en aperçoive — le client
     s'en va, il ne vient pas se plaindre. */
  .qr-logo{
    position:absolute; top:50%; left:50%; transform:translate(-50%,-50%);
    width:20%; aspect-ratio:1; border-radius:50%; background:#fff;
    padding:4%; box-sizing:border-box; pointer-events:none;
    box-shadow:0 0 0 2px rgba(0,0,0,.10);
  }
  .qr-logo img{display:block; width:100%; height:100%; object-fit:contain}
  .qr-mots{display:grid; gap:.6vmin}

  /* ── LE DÉFILÉ DES LOTS ───────────────────────────────────────────────────────────────────
     Une fenêtre à hauteur ÉLASTIQUE : elle prend ce qui reste sous le QR, jamais un pixel de plus.
     `min-height:0` est indispensable — sans lui, un enfant en flux impose sa hauteur naturelle à
     la colonne et pousse le QR hors de l'écran, exactement le défaut déjà payé en haut de ce
     fichier avec les boîtes de hauteur devinée. */
  /* LA CONSIGNE AU-DESSUS DE LA ROUE — elle dit le geste, donc elle passe avant le décor. */
  .promesse b{color:var(--jaune2)}
  .consigne{
    margin:0; text-align:center; line-height:1.2;
    font-size:clamp(13px,1.8vmin,22px); font-weight:700; letter-spacing:-.01em;
    color:var(--creme); opacity:.92;
  }
  .consigne b{color:var(--jaune2); font-weight:900}

  /* LE TITRE DU RUBAN — il transforme des photos en promesse. Il ne défile pas : un en-tête qui
     bouge avec son contenu cesse d'être un en-tête. */
  .defile-titre{
    margin:0 0 1vmin; text-align:center; flex:0 0 auto;
    font-size:clamp(13px,1.9vmin,23px); font-weight:900; letter-spacing:.12em;
    text-transform:uppercase; color:var(--jaune2); opacity:.95;
  }

  /* Le ruban est maintenant DEUX étages : le titre (fixe) et la bande (qui défile). Sans cette
     grille, le titre entrait dans le flux animé et remontait avec les photos. */
  .defile{
    position:relative; min-height:0; height:100%; overflow:hidden;
    width:100%; display:grid; grid-template-rows:auto minmax(0,1fr);
    /* Fondu haut et bas : la bande n'apparaît pas d'un coup et ne se coupe pas net — elle émerge
       et s'efface, ce qui donne l'impression d'un ruban continu plutôt que d'une liste tronquée. */
  }
  /* Le fondu porte sur la BANDE seule : appliqué au bloc entier, il effaçait aussi le haut du
     titre. Fondu court (8 %) — au-delà, les produits du haut et du bas paraissent effacés plutôt
     qu'en mouvement, et le propriétaire l'a vu tout de suite sur sa photo. */
  .defile-fenetre{
    position:relative; min-height:0; overflow:hidden;
    -webkit-mask-image:linear-gradient(180deg, transparent 0, #000 8%, #000 92%, transparent 100%);
            mask-image:linear-gradient(180deg, transparent 0, #000 8%, #000 92%, transparent 100%);
  }
  .defile-bande{
    display:flex; flex-direction:column; align-items:center; gap:2.2vmin;
    animation:defiler 52s linear infinite; will-change:transform;
  }
  /* La liste est écrite DEUX fois ; on remonte d'exactement la moitié de la bande, donc la boucle
     retombe sur une image identique. Aucun saut. */
  @keyframes defiler{from{transform:translateY(0)} to{transform:translateY(-50%)}}

  .defile-item{
    margin:0; display:grid; justify-items:center; gap:.5vmin; flex:0 0 auto;
  }
  /* [2026-08-13, vérifié à l'écran] À 15vmin, la fenêtre du défilé ne montrait QU'UN produit à la
     fois, coupé en haut et en bas : en paysage, le QR et ses deux phrases prennent l'essentiel de
     la colonne et il ne restait qu'environ 150 px. Un défilé qui montre un seul article n'est plus
     un défilé, c'est une image qui saute. On réduit la vignette pour en faire tenir trois. */
  /* Le ruban occupe maintenant toute la hauteur : la vignette peut redevenir grande. C'est
     l'inverse du compromis d'avant — on ne rétrécit plus le produit pour le faire entrer, c'est
     la colonne qui lui donne la place. */
  .defile-item img{
    width:min(13vmin,146px); height:min(13vmin,146px); object-fit:cover;
    border-radius:50%; display:block;
    background:var(--creme);
    /* Le même anneau chaud que les médaillons de la roue : deux surfaces, une seule identité. */
    box-shadow:0 0 0 .35vmin rgba(255,211,77,.92), 0 1vmin 2.6vmin rgba(0,0,0,.55);
  }
  .defile-item figcaption{
    font-size:clamp(13px,1.8vmin,21px); font-weight:900; letter-spacing:-.01em;
    color:var(--creme); opacity:.92; text-align:center; max-width:20ch;
  }

  /* [2026-08-25] Tuile dont la photo n'a pas pu être chargée.
     On ne laisse PAS un carré blanc face au client : le médaillon reprend l'anneau chaud
     des autres tuiles, avec un pictogramme de cadeau, et le NOM du lot passe en avant.
     Même gabarit, même identité — seule la photo manque, pas la promesse. */
  .defile-item--sans-photo::before{
    content:'🎁';
    width:min(13vmin,146px); height:min(13vmin,146px);
    display:grid; place-items:center;
    font-size:min(6vmin,64px); line-height:1;
    border-radius:50%; background:var(--creme);
    box-shadow:0 0 0 .35vmin rgba(255,211,77,.92), 0 1vmin 2.6vmin rgba(0,0,0,.55);
  }
  .defile-item--sans-photo figcaption{ opacity:1; }

  /* Un écran de comptoir tourne toute la journée : on respecte la préférence système. */
  @media (prefers-reduced-motion:reduce){
    .defile-bande{animation:none}
  }
  .scanne{margin:0; font-size:min(3.1vmin,32px); font-weight:900; letter-spacing:-.01em}
  /* Plancher en px : à 2,4vmin cette phrase tombait à 18 px sur une petite tablette, soit
     3,5 mm de haut — sous la limite de lecture debout à trois mètres. */
  .detail{margin:0; font-size:clamp(14px,1.6vmin,19px); opacity:.7; line-height:1.4}
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
    font-size:clamp(13px,1.35vmin,17px); opacity:.55;
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
    .fond,.fleche,.qr-boite::before,.acte.on .gagnant{animation:none}
    .acte{transition:none}
    /* Sans l'animation, l'état de DÉPART reste appliqué : les gagnants resteraient invisibles.
       C'est le piège classique du mouvement réduit — on retire l'animation et on oublie que
       l'élément était masqué en attendant qu'elle le révèle. */
    .gagnant{opacity:1; transform:none}
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
  <div class="fond-halo" aria-hidden="true"></div>
  <div class="fond-vignette" aria-hidden="true"></div>

  <main class="scene">

    <div class="gauche">
      {{-- Le VRAI logo, en grand : c'est la première chose vue de loin, et c'est ce qui fait
           reconnaître l'écran comme celui du restaurant et non une publicité quelconque. --}}
      <img class="logo" src="{{ asset('images/kiosk-attract/logo.png') }}" alt="Le Cayenne">

      {{-- [PROPRIÉTAIRE 2026-08-13] « au-dessus de la roue, c'est bien de mentionner : scanne le QR
           code pour jouer / tourner la roue ».

           C'est la correction la plus utile de tout l'écran. Jusqu'ici la page montrait un jeu et un
           QR, mais ne disait JAMAIS que l'un ouvre l'autre — le client devait le deviner. Un
           passant a environ trois secondes d'attention : s'il doit inférer le lien entre deux
           objets éloignés de l'écran, il ne le fait pas, il regarde ailleurs.

           La phrase est posée AU-DESSUS de la roue, donc sur le trajet naturel du regard (logo →
           consigne → roue), et elle nomme les deux gestes dans l'ordre où ils arrivent : scanner
           d'abord, tourner ensuite. --}}
      {{-- [PROPRIÉTAIRE 2026-08-13] « "Tu gagnes à 100 %", vaut mieux la mettre au-dessus de la
           roue ». Elle était en bas, dans la rotation des actes — donc VISIBLE UN TIERS DU TEMPS
           seulement, et sous l'objet qu'elle est censée annoncer.

           C'est la promesse : elle doit arriver AVANT le geste et avant la roue. L'ordre de lecture
           devient promesse → action → objet, qui est l'ordre dans lequel un passant décide :
           « qu'est-ce que j'y gagne ? », « qu'est-ce que je dois faire ? », « avec quoi ? ».
           Elle est désormais permanente : une promesse qui clignote n'est pas une promesse. --}}
      <h1 class="promesse">Tu gagnes <b>à 100 %</b></h1>
      <p class="consigne">Scanne le QR code<br><b>pour tourner la roue</b></p>

      {{-- Le repère qui désigne le lot gagnant est DESSINÉ dans le canvas (voir plus bas) : la
           roue change de taille pour laisser la place au texte, et un triangle posé à côté en
           HTML se décrochait de son sommet. Le `aria-label` donne la liste des lots hors de
           l'acte 2 : sans lui, la roue est une image muette les deux tiers du temps. --}}
      <canvas class="roue" id="roue" width="720" height="720" role="img"
              aria-label="Roue des lots : {{ implode(', ', array_column($segments ?? [], 'label')) ?: 'à découvrir' }}"></canvas>

    </div>

    @if(!empty($segments))
    {{-- ── LA COLONNE DU MILIEU : TOUS LES LOTS, SUR TOUTE LA HAUTEUR ─────────────────────
         [PROPRIÉTAIRE 2026-08-13] « rends la barre des images verticale au milieu, on verra tous
         les produits, c'est sa place au milieu de la tablette, qui prend toute la hauteur. »

         Elle était d'abord sous le QR, et c'était trop juste : mesuré à l'écran, il ne restait
         qu'environ 150 px après le QR et ses deux phrases — un produit et demi, coupé. Le vide
         réel était AILLEURS, entre la roue et le QR, sur toute la hauteur de l'écran.

         La scène passe donc à trois colonnes. Ce n'est pas un déplacement cosmétique : la colonne
         du milieu occupe la ligne élastique de la grille, donc le ruban court du haut de l'écran
         au bas sans qu'aucune hauteur ne soit devinée — la faute déjà payée deux fois dans ce
         fichier (boîtes de hauteur estimée, contenu qui déborde par-dessus le QR).

         Ordre de lecture voulu : la roue dit « il y a un jeu », le ruban dit « voilà TOUT ce que
         tu peux gagner », le QR dit « voilà comment ». --}}
    {{-- [PROPRIÉTAIRE 2026-08-13] « les images, on comprend rien si on met pas de titre comme
         "produits à gagner" ». Exact, et c'est un défaut de sens, pas de style : sans en-tête, ces
         photos sont juste de la nourriture qui défile — le cerveau les classe en décor et les
         ignore. Nommées « À GAGNER », les mêmes photos deviennent une promesse. --}}
    <div class="defile" aria-hidden="true">
      <p class="defile-titre">À gagner</p>
      <div class="defile-fenetre">
        <div class="defile-bande">
          @foreach(array_merge($segments, $segments) as $seg)
            @if(!empty($seg['photo']))
            {{-- [2026-08-25] Le garde `!empty($seg['photo'])` ci-dessus protège d'une photo
                 NON DÉCLARÉE. Il ne protégeait pas d'une photo déclarée dont le FICHIER a
                 disparu du disque : le navigateur demande l'adresse, reçoit un 404, et
                 dessine un carré blanc — face client, sur un écran qui promet des lots.

                 HONNÊTETÉ SUR L'ORIGINE : l'audit avait classé ça « défaut face client, deux
                 lots en 404 ». C'ÉTAIT FAUX, et la cause était mon environnement — un
                 worktree sans lien `public/storage` et sans les fichiers médias, qui sont
                 bien présents dans le dépôt réel. Vérifié après réparation : les 7 lots
                 résolvent. Ce garde reste néanmoins, non pas pour un défaut constaté, mais
                 parce qu'un média PEUT réellement disparaître en exploitation (photo
                 supprimée dans l'admin, disque non synchronisé) et que le prix de la panne
                 est payé par le client, pas par nous.

                 `onerror` retire l'image cassée et bascule la tuile sur son NOM. Un lot
                 nommé reste une promesse ; un carré blanc n'est rien. --}}
            <figure class="defile-item">
              <img src="{{ $seg['photo'] }}" alt="" loading="lazy"
                   onerror="this.closest('.defile-item').classList.add('defile-item--sans-photo'); this.remove();">
              <figcaption>{{ $seg['label'] ?? '' }}</figcaption>
            </figure>
            @endif
          @endforeach
        </div>
      </div>
    </div>
    @endif

    <div class="droite">
      {{-- [PROPRIÉTAIRE 2026-08-13] « ce qui est au-dessus de la roue actuellement — c'est tout de
           suite, scanne / tourne / gagne — je demande de le mettre au-dessus du QR code, parce que
           là il reste encore de l'espace ; et la roue, je veux qu'elle prenne le maximum d'espace
           possible ».

           Deux gains d'un seul geste, et le second est le vrai : ces trois étapes décrivent CE
           QU'ON FAIT AVEC LE QR — les poser à côté de lui les met enfin en face de leur objet,
           alors qu'elles vivaient sous la roue, loin de ce qu'elles expliquent. Et la colonne de
           gauche, libérée, n'a plus qu'un seul sujet : la roue, qui prend tout le reste.

           C'est la même règle qu'à chaque fois sur cet écran : on n'agrandit pas la roue en
           montant son plafond, on lui rend la place que d'autres prenaient. --}}
      <div class="actes">        {{-- ACTE 2 — CE QUI VIENT D'ÊTRE GAGNÉ.
             [2026-08-13 · propriétaire : « tu affiches la roue avec les produits à gagner et en bas
             tu affiches ENCORE les photos ainsi que leur nom — c'est catastrophique, faire lire
             deux fois la même chose »]

             Il avait raison, et le code le disait noir sur blanc : le canvas dessine les sept
             libellés, puis cet acte réimprimait LES MÊMES SEPT, en pastilles, trois secondes plus
             tard, sur le même écran. Aucune information ajoutée — juste la même liste, deux fois.
             Elle est supprimée. La roue porte désormais le nom ET la photo de chaque lot : c'est
             UN endroit, et c'est le bon.

             Ce qui prend la place doit dire quelque chose que la roue ne dit pas. Pour quelqu'un
             qui hésite devant un comptoir, il n'y a qu'une information qui compte : **ça donne
             vraiment, et ça vient de donner.** D'où les derniers gagnants, réels, lus en base.

             S'il n'y a personne (jeu neuf, journée creuse, deux jours sans tour), l'acte n'est PAS
             rendu : la vitrine repasse à deux actes. Un cadre « aucun gagnant » sur un écran de
             comptoir dit au client que le jeu ne prend pas — c'est exactement l'inverse du but. --}}
        @if (! empty($gagnants))
        {{-- [PROPRIÉTAIRE 2026-08-13 : « là maintenant y a plus rien au-dessous »] Il avait raison,
             et la cause était ici : cet acte liste les gagnants RÉCENTS. Le jeu n'ayant qu'un seul
             tour en production, la liste est vide — la rotation tombait donc une fois sur deux sur
             un bloc SANS RIEN, et le bas de l'écran clignotait vers le néant.

             Un carrousel ne doit jamais présenter une case vide : il vaut mieux montrer moins
             souvent quelque chose que souvent rien. L'acte n'existe donc que s'il a de quoi
             remplir ; sinon les trois étapes restent affichées en permanence, ce qui est
             exactement ce que le propriétaire attend à cet endroit. --}}
        @if (! empty($gagnants))
        <div class="acte" data-acte="1">
          <p class="titre2">Ça vient de tomber</p>
          <ul class="gagnants">
            @foreach ($gagnants as $i => $g)
              <li class="gagnant" style="animation-delay:{{ number_format(0.11 * $i, 2, '.', '') }}s">
                <span class="g-lot">{{ $g['lot'] }}</span>
                {{-- Le prénom SEUL, jamais le nom complet ni le numéro : cet écran est face à la
                     salle, tout le monde le lit. Sans prénom donné, « quelqu'un » — c'est vrai, et
                     ça raconte la même chose. --}}
                <span class="g-qui">{{ $g['prenom'] !== '' ? $g['prenom'] : 'quelqu\'un' }}<span
                      class="g-quand" data-instant="{{ $g['instant'] }}"></span></span>
              </li>
            @endforeach
          </ul>
        </div>
        @endif

        {{-- ACTE 3 — LE GESTE. Trois pastilles, mais DEUX gestes seulement : « scanne » puis
             « tourne ». La troisième est la récompense, pas une corvée de plus — c'est la
             différence entre annoncer un parcours long (qui fait reposer le téléphone) et
             annoncer une fin heureuse. Ne pas la relire comme une étape à supprimer. --}}
        @endif

        <div class="acte on" data-acte="2">
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
      <div class="fleche" aria-hidden="true">↓</div>
      <div class="qr-boite">
        <div class="qr" id="qr">{!! $qr !!}@if($qr)<div class="qr-logo"><img src="{{ asset('images/wheel/logo-mark.png') }}" alt=""></div>@endif</div>
      </div>
      <div class="qr-mots">
        <p class="scanne">Scanne le QR code</p>
        {{-- [2026-08-12 · propriétaire : « ça va prendre moins d'une minute, c'est pas bien vu que
             ça va prendre quelques secondes »] Il a raison, et pas seulement sur la durée : annoncer
             « moins d'une minute » à quelqu'un qui attend sa commande, c'est lui donner une raison de
             ne pas commencer. « Quelques secondes » est à la fois plus vrai — le parcours mesuré tient
             en une vingtaine de secondes — et moins coûteux à décider. --}}
        <p class="detail">Aucune application à installer.<br>Ça prend quelques secondes.</p>
      </div>

      {{-- ── LE DÉFILÉ DES LOTS ──────────────────────────────────────────────────────────────
           [PROPRIÉTAIRE 2026-08-13] « un truc vertical avec animation, avec les images de tous les
           produits à gagner, ça serait beaucoup mieux que juste la roue qui tourne », et « profiter
           et utiliser l'espace pour les choses qui ont de la valeur ».

           Ce défilé occupe exactement le vide qui restait sous le QR. Il montre les produits À PLAT,
           l'un après l'autre, gros et bien posés — ce que la roue ne peut pas faire, puisqu'elle les
           montre tous en même temps, petits et inclinés. Les deux se complètent : la roue dit « il y
           a un jeu », le défilé dit « voilà ce que tu peux gagner ».

           Il boucle SANS COUTURE : la liste est écrite deux fois et la bande remonte exactement de
           la hauteur d'une liste, si bien que le retour au début tombe sur une image identique.
           Aucun saut visible, donc aucun clignotement — la règle de cette page depuis le début.

           Le mouvement est LENT et CONTINU. Un défilé rapide se lit comme une publicité et l'équipe
           finit par éteindre l'écran ; un défilé lent se regarde sans y penser. --}}
    </div>

  </main>

  {{-- [PROPRIÉTAIRE 2026-08-13] « la petite phrase en bas, c'est mieux de la mettre beaucoup plus
       petit, là c'est trop grand, ça prend beaucoup d'espace ». Elle disait en plus « le lot est à
       utiliser sur une prochaine commande » — la même condition d'achat qu'il ne veut pas sur cet
       écran. Il reste la seule règle qui doit être connue AVANT de jouer : un tour par personne. --}}
  <p class="bandeau">Un tour par personne.</p>

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
  /* LES PHOTOS, dans le même ordre. Résolues par le SERVEUR via le chemin de la maison
     (média téléversé → pack détouré → vignette WebP), jamais par une table écrite ici : c'est
     ainsi qu'une photo remplacée sur le disque arrive à la roue le jour même. `null` quand le
     produit n'a pas de vraie photo — le secteur retombe alors sur son libellé, en plus grand. */
  var PHOTOS = @json(array_column($segments ?? [], 'photo'));
  // LISTE DE SECOURS, si le serveur n'a pas répondu. Elle doit rester le REFLET des lots réels :
  // le 12 août elle annonçait encore « -10% » et « 50 points », des lots que la roue ne donne plus.
  // Une roue de secours qui promet autre chose que la vraie est un mensonge de plus, pas un filet.
  if (!LOTS.length) { LOTS = ['Boisson', 'Frites', 'Tiramisu', 'Tarte Daim', 'Cheese Burger', 'Cayenne', 'Terminator']; }
  /* Le tableau des photos doit rester EXACTEMENT aligné sur celui des libellés, y compris quand
     ce dernier vient de retomber sur la liste de secours. Un décalage d'un cran collerait la
     photo du Cayenne sur le Terminator — une erreur qu'on ne voit pas en test et que le client
     voit tout de suite. */
  while (PHOTOS.length < LOTS.length) { PHOTOS.push(null); }
  PHOTOS.length = LOTS.length;

  /* ── LE CHARGEMENT DES PHOTOS ─────────────────────────────────────────────────────────────
     Chaque image est chargée une fois et gardée. Tant qu'elle n'est pas prête, le secteur est
     dessiné sans elle : la roue tourne dès la première image affichée, elle n'attend pas le
     réseau. Une image en échec reste `null` pour toujours — on ne réessaie pas en boucle sur un
     écran allumé douze heures, ce serait une requête perdue toutes les 16 ms. */
  var IMAGES = new Array(LOTS.length);
  (function chargerPhotos() {
    for (var i = 0; i < PHOTOS.length; i++) {
      if (!PHOTOS[i]) { continue; }
      (function (idx, url) {
        var im = new Image();
        im.onload = function () { IMAGES[idx] = im; cache = null; cacheEchelle = -1; };
        im.onerror = function () { IMAGES[idx] = null; };
        im.src = url;
      })(i, PHOTOS[i]);
    }
  })();

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
  /* ── LA PALETTE DE L'ÉCRAN DU CLIENT, REPRISE ICI ──────────────────────────────────────────
     [PROPRIÉTAIRE 2026-08-13] « sur l'écran client, la roue avec un couleur noir avec une couleur,
     c'était beaucoup mieux — ton design et la lumière, remets ça dans l'écran de la tablette. »

     Il a raison, et la raison est structurelle : l'ancienne teinte de la tablette faisait alterner
     jaune clair et orange, deux couleurs CLAIRES. Le texte devait alors être blanc partout, cerné
     de sombre pour survivre sur le jaune. En alternant NOIR PROFOND et couleur de marque, chaque
     secteur porte sa propre couleur de texte — le contraste est réglé par construction, plus par
     rattrapage. C'est la palette de `roue.html` (page téléphone), copiée telle quelle : deux écrans
     du même jeu ne doivent pas avoir deux identités.

     Chaque entrée est [FOND, TEXTE]. */
  var PALETTE = [
    ['#F4501E', '#FFF6EC'], ['#17140F', '#FFB800'],
    ['#FFB800', '#3A1C00'], ['#241A12', '#FFD34D'],
    ['#FF6A3D', '#2A1508'], ['#0F0D0A', '#FFF6EC'],
    ['#FFD34D', '#3A1C00'], ['#C93A12', '#FFF6EC']
  ];

  function ombrer(hex, t) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex); if (!m) return hex;
    var v = parseInt(m[1], 16), r = (v >> 16) & 255, g = (v >> 8) & 255, b = v & 255;
    function f(x) { return Math.max(0, Math.min(255, Math.round(t < 0 ? x * (1 + t) : x + (255 - x) * t))); }
    return 'rgb(' + f(r) + ',' + f(g) + ',' + f(b) + ')';
  }

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
  var R_MIN = 80;              // après le moyeu
  var R_MAX_NU = 316;          // sans photo : toute la bande, jusqu'avant le repère et les ampoules

  /* ── OÙ SE POSE LA PHOTO, ET CE QU'ELLE COÛTE AU TEXTE ────────────────────────────────────
     [2026-08-13 · propriétaire : « tu ajoutes les photos »] La photo prend de la place SUR LE
     RAYON, et le rayon est précisément ce qui donne sa taille au libellé. Ce n'est pas gratuit,
     et le chiffre a été posé avant d'écrire la ligne :

       · photo centrée à r = 286, côté 104 → elle occupe la bande [234, 338] ;
       · le texte se replie donc sur [80, 226], soit 146 px au lieu de 236.

     « Terminator » — le mot le plus long, insécable — passe ainsi de ~33 à ~24 px de canvas.
     Pour que la LISIBILITÉ ne recule pas, la roue récupère en CSS la hauteur libérée par l'acte
     supprimé : plus grande à l'écran, son échelle compense la réduction. C'est le seul échange
     qui rendait les photos acceptables ici.

     Un secteur SANS photo garde toute sa bande : il n'a aucune raison d'être puni. */
  var R_PHOTO = R * 0.795;     // centre de la vignette, sur le rayon
  var R_MAX_PHOTO = 226;       // le texte s'arrête avant la vignette
  var echelle = 1, cache = null, cacheEchelle = -1;

  /* Côté de la vignette : bornée à la fois par la bande radiale qui lui est réservée et par la
     largeur du secteur À CET ENDROIT — sans la seconde borne, une roue à 10 lots verrait ses
     vignettes se chevaucher d'un secteur sur l'autre. */
  function photoCote(N) {
    return Math.min(104, (TAU * R_PHOTO / N) * 0.55);
  }

  function police(t) { return '900 ' + t.toFixed(1) + 'px -apple-system,Segoe UI,Roboto,sans-serif'; }

  function mesurerEchelle() {
    var l = cv ? cv.getBoundingClientRect().width : 0;
    echelle = l > 4 ? COTE / l : 1;
  }

  function libelles() {
    if (cache && cacheEchelle === echelle) { return cache; }
    var N = LOTS.length;
    cache = LOTS.map(function (brut, idx) {
      // Deux lignes pour les libellés longs : « Frites + Boisson » sur une seule ligne sort du
      // secteur et se fait rogner par le bord de la roue.
      var mots = String(brut).split(' ');
      var coupe = Math.ceil(mots.length / 2);
      var l1 = mots.length > 1 ? mots.slice(0, coupe).join(' ') : mots[0];
      var l2 = mots.length > 1 ? mots.slice(coupe).join(' ') : '';
      // La bande dépend de CE secteur : une vignette réellement chargée le rétrécit, une photo
      // absente ou en échec lui rend tout l'espace. On regarde `IMAGES`, pas `PHOTOS` — une URL
      // annoncée mais jamais chargée ne doit pas voler sa place au texte.
      var avecPhoto = !!IMAGES[idx];
      var rMax = avecPhoto ? R_MAX_PHOTO : R_MAX_NU;
      var rTexte = (R_MIN + rMax) / 2;
      var t = VISEE * echelle;
      for (var k = 0; k < 14; k++) {
        ctx.font = police(t);
        var demi = Math.max(ctx.measureText(l1).width, l2 ? ctx.measureText(l2).width : 0) / 2;
        var interne = rTexte - demi;
        var epais = l2 ? 1.95 * t : 0.8 * t;                 // encre en travers du rayon
        var place = TAU * Math.max(interne, 30) / N * 0.86;   // largeur du secteur à cet endroit
        if (interne >= R_MIN && rTexte + demi <= rMax && epais <= place) { break; }
        t *= 0.94;
      }
      return { l1: l1, l2: l2, t: t, r: rTexte };
    });

    /* ── UNE SEULE TAILLE POUR TOUTE LA ROUE ────────────────────────────────────────────────
       [SUPERVISION VISUELLE 2026-08-13] Constaté en regardant l'écran, pas le code : « Frites »
       s'affichait environ 2,5 fois plus gros que « Terminator ». Ce n'était pas un choix — c'est
       l'effet de bord de la boucle ci-dessus, où CHAQUE libellé rétrécit dans son coin jusqu'à
       tenir dans son secteur. Un mot court ne rétrécit jamais, un mot long rétrécit beaucoup, et
       la roue prend un air brouillon : le client lit d'abord la taille, et une taille qui varie
       lui dit que « Frites » vaut plus que « Terminator », ce qui est faux.

       On garde donc la mesure par secteur — c'est elle qui garantit qu'aucun mot ne déborde — mais
       on retient la PLUS PETITE taille trouvée et on l'applique à tous. La roue devient régulière,
       et la règle reste sûre : si la plus petite tient partout, toutes tiennent.

       ⛔ Ne pas « optimiser » en rendant sa taille à chaque mot : ce serait revenir au défaut.
       Un libellé long ajouté à `config/wheel.php` réduira toute la roue — c'est voulu, et c'est le
       signal qu'il faut un nom plus court, pas une exception. */
    var tUniforme = cache.reduce(function (mini, l) { return Math.min(mini, l.t); }, Infinity);
    if (isFinite(tUniforme)) {
      cache.forEach(function (l) { l.t = tUniforme; });
    }

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

      var pal = PALETTE[i % PALETTE.length];
      var g = ctx.createRadialGradient(0, 0, RAYON * 0.18, 0, 0, RAYON);
      g.addColorStop(0, ombrer(pal[0], -0.22));
      g.addColorStop(0.55, pal[0]);
      g.addColorStop(1, ombrer(pal[0], 0.10));

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
      var am = a0 + pas / 2;
      var abs = ((am + angle) % TAU + TAU) % TAU;
      var retourne = abs > Math.PI / 2 && abs < Math.PI * 1.5;
      var L = libs[i];

      /* ── LA PHOTO DU LOT ────────────────────────────────────────────────────────────────
         Elle est posée AVANT le texte et sur un autre rayon : les deux ne se recouvrent
         jamais, la bande de chacun est calculée pour ça (voir R_MAX_PHOTO).

         Le quart de tour supplémentaire met le HAUT de la photo vers l'EXTÉRIEUR de la roue :
         sans lui, la canette de Coca est couchée sur le flanc. Et la photo subit le MÊME
         retournement que son libellé — sinon un secteur de la moitié gauche montrerait une
         photo droite sous un nom à l'envers, ce qui a l'air d'un bug plutôt que d'une roue.

         Le rapport largeur/hauteur est PRÉSERVÉ : une frite écrasée dans un carré, c'est
         exactement la négligence qu'on nous reproche. */
      var im = IMAGES[i];
      if (im && im.width > 0 && im.height > 0) {
        var cote = photoCote(N);
        ctx.save();
        // ON SE PLACE D'ABORD, ON S'ORIENTE ENSUITE. Fondre le quart de tour dans la rotation
        // qui précède la translation change la DIRECTION de cette translation : les secteurs
        // retournés envoyaient alors leur photo à l'opposé de la roue. Une fois le repère posé
        // sur la vignette, les deux rotations tournent autour d'elle et commutent sans risque.
        ctx.rotate(am);
        ctx.translate(R_PHOTO, 0);
        if (retourne) { ctx.rotate(Math.PI); }
        ctx.rotate(Math.PI / 2);

        /* ── LE MÉDAILLON, ET POURQUOI IL EXISTE ────────────────────────────────────────
           Le pack de photos est MIXTE, constaté en capture : Frites, Coca et Tarte sont
           détourées sur fond transparent, tandis que Cheese Burger, Cayenne et Terminator
           sont des photos PLEINE CADRE à fond noir. Posées à nu côte à côte sur la roue,
           les secondes deviennent des rectangles sombres collés sur des cases orange —
           trois lots ont l'air d'un bug, quatre ont l'air d'un produit.

           Le médaillon règle les deux cas d'un coup : un disque clair, la photo RECADRÉE
           dedans (recouvrement, jamais de déformation), un anneau doré. Le fond noir est
           découpé, le détourage gagne une assise, et les sept lots deviennent la même
           famille visuelle. Un disque se lit aussi de plus loin qu'un rectangle : c'est
           ce qui permet de garder la roue à 48vmin sans rien perdre. */
        var rp = cote / 2;
        ctx.shadowColor = 'rgba(0,0,0,.55)';
        ctx.shadowBlur = 16;
        ctx.shadowOffsetY = 5;
        ctx.beginPath();
        ctx.arc(0, 0, rp, 0, TAU);
        ctx.fillStyle = '#FFF6EC';
        ctx.fill();
        ctx.shadowColor = 'transparent';

        ctx.save();
        ctx.beginPath();
        ctx.arc(0, 0, rp, 0, TAU);
        ctx.clip();

        /* ── LA PHOTO NE TOURNE PAS AVEC LA ROUE ────────────────────────────────────────────
           [PROPRIÉTAIRE 2026-08-13] « les images, on dirait que ça tourne avec la roue ; je veux
           que ce soit vraiment tout en face correctement — pas si elle est à gauche, elle va être
           inclinée de 90°. Toujours bien posée, comme sur une table, tout bien visible à l'œil. »

           Il décrit un vrai défaut : un plat photographié de face devient illisible dès que son
           secteur passe sur le côté, parce que le repère hérite de TOUTES les rotations empilées
           au-dessus — celle de la roue (`angle`), celle du secteur (`am`), le demi-tour des
           libellés de gauche, et le quart de tour du médaillon. Un tiramisu couché n'est plus un
           tiramisu, c'est une tache brune.

           On annule donc EXACTEMENT la somme de ces rotations avant de poser l'image. Le disque
           de découpe est centré sur l'origine : il est insensible à la rotation, la photo reste
           donc parfaitement inscrite dans son médaillon.

           ⛔ Ne pas « simplifier » en retirant un des termes : ils s'empilent réellement, et en
           oublier un fait pencher les photos d'un côté seulement — le défaut est alors visible
           une fois sur deux et passe pour un hasard d'affichage. */
        ctx.rotate(-(angle + am + (retourne ? Math.PI : 0) + Math.PI / 2));
        /* RECOUVREMENT : on met à l'échelle par le plus GRAND des deux rapports, donc le
           disque est toujours entièrement couvert et la photo garde ses proportions. Le
           trop-plein est découpé par le masque — jamais un produit écrasé. */
        var k = Math.max((rp * 2) / im.width, (rp * 2) / im.height);
        ctx.drawImage(im, -im.width * k / 2, -im.height * k / 2, im.width * k, im.height * k);
        ctx.restore();

        // L'anneau : il détache le médaillon de sa case et rattrape les photos très claires,
        // qui sans lui se confondraient avec le disque crème.
        ctx.beginPath();
        ctx.arc(0, 0, rp, 0, TAU);
        ctx.lineWidth = Math.max(2.5, cote * 0.045);
        ctx.strokeStyle = 'rgba(255,211,77,.92)';
        ctx.stroke();
        ctx.restore();
      }

      ctx.save();
      ctx.rotate(am);
      ctx.translate(L.r, 0);
      if (retourne) { ctx.rotate(Math.PI); }
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      /* [PROPRIÉTAIRE 2026-08-13] Chaque secteur porte SA couleur de texte (2e terme de la
         palette), au lieu d'un blanc unique rattrapé par un cerne. Le contraste est ainsi réglé
         par construction : crème sur les secteurs noirs, brun foncé sur les jaunes. */
      var palT = PALETTE[i % PALETTE.length];
      ctx.fillStyle = palT[1];
      ctx.shadowColor = 'rgba(0,0,0,.55)';
      ctx.shadowBlur = 7;
      ctx.font = police(L.t);
      /* Un cerne sombre sous le blanc : une case jaune de fête foraine est presque aussi claire
         que le texte posé dessus (1,5:1). Le cerne rend le mot lisible sans assombrir la case,
         donc sans perdre la couleur de la marque. */
      ctx.lineWidth = Math.max(2, L.t * 0.1);
      var clair = (function (h) {
        var m = /^#?([0-9a-f]{6})$/i.exec(h); if (!m) { return true; }
        var v = parseInt(m[1], 16);
        return (((v >> 16) & 255) * 0.299 + ((v >> 8) & 255) * 0.587 + (v & 255) * 0.114) > 140;
      })(palT[1]);
      ctx.strokeStyle = clair ? ombrer(palT[0], -0.55) : ombrer(palT[0], 0.55);
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
        // On relance l'entrée à chaque passage : sinon l'acte n'est animé qu'une fois, et paraît
        // figé aux passages suivants.
        [].forEach.call(el.querySelectorAll('.gagnant'), function (p) {
          p.style.animation = 'none';
          void p.offsetWidth;
          p.style.animation = '';
        });
      }, FONDU);
    }, 6500);
  }

  /* ── « IL Y A COMBIEN DE TEMPS » — RECALCULÉ, JAMAIS FIGÉ ───────────────────────────────────
     Cette page reste allumée des heures sur un comptoir. Un « il y a 4 min » écrit au rendu
     serait faux à la minute suivante et le resterait toute la journée — et un écran qui ment sur
     un détail vérifiable fait douter du reste. Le serveur donne l'INSTANT, la page calcule
     l'écart, et le refait toutes les 30 s.

     Au-delà de quelques heures on cesse de compter : « il y a 1 743 min » ne se lit pas à trois
     mètres, et ne veut rien dire à quelqu'un qui attend sa commande. */
  var quands = [].slice.call(document.querySelectorAll('.g-quand'));
  if (quands.length) {
    var direDelai = function () {
      var maintenant = Date.now() / 1000;
      quands.forEach(function (el) {
        var t = parseInt(el.getAttribute('data-instant'), 10);
        if (!t) { el.textContent = ''; return; }
        var s = Math.max(0, maintenant - t);
        el.textContent = s < 90 ? 'à l’instant'
          : s < 5400 ? 'il y a ' + Math.round(s / 60) + ' min'
          : s < 86400 ? 'il y a ' + Math.round(s / 3600) + ' h'
          : 'hier';
      });
    };
    direDelai();
    setInterval(direDelai, 30000);
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
