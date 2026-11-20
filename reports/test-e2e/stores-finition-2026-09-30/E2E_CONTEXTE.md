# E2E « finition stores » — contexte commun (round 1, 2026-10-01)

Tout agent de ce run lit CE fichier avant d'écrire une ligne. Les faits ci-dessous sont
VÉRIFIÉS ; ne pas les re-découvrir, ne pas les contredire sans preuve.

## Ce qu'on teste
L'application Apple/Google **est le site** `lecayenne.fr` empaqueté par Capacitor 8.
Code testé = worktree du site `/Users/1millnonstop/Downloads/lecayenne-web-deploy/site-wt-stores-2026-09-30`
(branche `app/natif-honnete-2026-09-30`, commit `79d7dde` depuis la reprise du 2026-10-01 04:30), servi statiquement sur
**http://127.0.0.1:8898**. Backend = arbre `qa-corrige-2026-09-28`, servi sur
**http://127.0.0.1:8000** (4 processus PHP, base `foodking_e2e`, `MAIL_MAILER=log`, soketi :6001).

## Règles dures
- **AUCUNE écriture en production.** Toute commande/compte va au backend LOCAL :8000.
  Le site pointe le VPS par défaut : il FAUT réécrire ses métadonnées (voir « Câblage »).
- **Ne modifie AUCUN code produit** (site ou backend). Tu écris des bancs, tu captures, tu
  rapportes. Les correctifs sont faits par l'orchestrateur après revue adverse.
- Données de test : e-mails `e2e-stores-<vague>-<horodatage>@lecayenne-test.fr`,
  téléphones `069x…` uniques, prénom `E2E…`. Jamais un nom de produit inventé : lire
  `data/menu.js` du site ou `SELECT name FROM items WHERE deleted_at IS NULL AND status=5`.
- Max **2 navigateurs simultanés** par agent (le backend de dev a 4 processus).
- Un banc n'a de valeur que s'il peut rougir : chaque scénario a un garde « anti-test-vide »
  (on prouve qu'on a bien ATTEINT l'écran avant de conclure quoi que ce soit dessus).
- `.catch(() => {})` interdit sur une assertion de bascule entre surfaces.

## Câblage éprouvé (copier, ne pas réinventer)
- Lancement : `chromium.launch({ args: ['--disable-features=LocalNetworkAccessChecks,PrivateNetworkAccessChecks'] })`
  (sinon Chromium bloque le fetch vers 127.0.0.1 : « loopback address space »).
- Site → backend local : intercepter le DOCUMENT et réécrire les metas — modèle exact :
  `site-wt-stores-2026-09-30/tests-e2e/compte-email-dabord-2026-09-02.spec.js` lignes 70-81
  (`api-base-url` → `http://127.0.0.1:8000`, `api-key` → `b6d68vy2-m7g5-20r0-5275-h103w73453q120`).
- Code à usage unique (OTP) : lu en base locale par `php artisan tinker` lancé dans
  `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/qa-corrige-2026-09-28`
  — modèle : même fichier, fonctions autour des lignes 40-60 (guillemets doubles seulement dans le PHP).
- **Mode application** : injecter AVANT le chargement un pont Capacitor de la forme EXACTE du
  vrai (`nativePromise`, `nativeCallback`, `addListener` — **PAS de `registerPlugin`**, il
  n'existe pas dans l'app réelle). Modèle qui enregistre chaque appel natif dans
  `window.__natif` : `site-wt-stores-2026-09-30/tests-e2e/app-natif-honnete-2026-09-30.spec.js`
  (constante `PONT`). Dans l'app : paiement en ligne COUPÉ (« Payer sur place » seul), pas
  d'onglet « Avis », aucune annonce « bientôt ».
- Caisse/cuisine/écran client (backend :8000) : connexion `#formEmail` / `#formPassword` sur
  `/login` ; comptes `pos@lecayenne.fr`, `chef@lecayenne.fr`, `admin@lecayenne.fr`, mot de passe
  `123456`. Routes : `/admin/encaissement`, `/admin/kitchen-display-system`, `/kds`,
  `/admin/order-status-screen`, `/admin/pos-orders-tracker`, `/suivi/:trackingToken`.
  Si un écran « ouverture de caisse » s'affiche : `[data-testid="cash-session-opening-input"]` = 100
  puis `[data-testid="cash-session-open-submit"]`.
- Node 22 : `export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"` ;
  `NODE_PATH=/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/.claude/worktrees/qa-corrige-2026-09-28/node_modules`.
  Les bancs du site sont des scripts Node autonomes (`require('playwright')`), pas `@playwright/test`.

## Captures et rapports
- Captures : `/Users/1millnonstop/.claude/jobs/48fdb176/tmp/e2e-stores/<vague>/NN-etat.png`
  (+ `.dom.html` + `.console.json` + `.network.json` — quartet ; modèle d'enregistreur :
  `qa-corrige-2026-09-28/tests/e2e/helpers/mega-audit-snap.js`).
- Banc : `site-wt-stores-2026-09-30/tests-e2e/e2e-stores-vague-<X>-2026-10-01.spec.js`.
- Rapport de vague : `reports/test-e2e/stores-finition-2026-09-30/round-1/vague-<X>-capture.md`
  (états capturés, ce qui a été prouvé, défauts OBSERVÉS avec preuve fichier/capture/réseau,
  et toute anomalie d'instrument). Pas de conclusion « produit cassé » sans 2e moyen indépendant.

## Règles produit à connaître (décisions propriétaire — ne pas les signaler comme défauts)
- Attente : jamais de chiffre précis sauf au bout de la file ; fourchette « ~15–20 min » sinon ;
  JAMAIS le nombre de commandes devant.
- Paiement : site = carte en ligne (Mollie) OU sur place ; application = sur place seulement.
- Apple Pay : uniquement sur https://www.lecayenne.fr (domaine vérifié) — absent ailleurs, voulu.
- La cuisine prépare AVANT l'encaissement d'une commande « sur place » (décision assumée).
- Palette site : noir / orange / jaune / blanc.

## Reprise 2026-10-01 04:30 — ce qui a changé depuis le premier passage
- Site `79d7dde` + backend `5242f2449` : **mode « restaurant fermé »**. Quand l'estimation renvoie
  `service_ouvert:false` (hors 18:00–00:30), le paiement propose « À l'ouverture » (ouverture + 20 min)
  ou une heure choisie, plus de « Dès que possible ». Le suivi d'une commande programmée affiche
  « Prévue pour 18 h 20 ». Ce n'est PAS un défaut : c'est le correctif.
- Suppression du compte : les rappels locaux sont annulés et un message s'affiche après rechargement
  (D-A-01 et D-A-02 de la vague A, corrigés et prouvés par `tests-e2e/hors-service-et-suppression-2026-10-01.spec.js`).
- **Fenêtre de service locale** : la nuit, le backend :8000 dit « fermé ». Seule la **vague B**
  la bascule, par `bash /Users/1millnonstop/.claude/jobs/48fdb176/tmp/e2e-stores/fenetre-service.sh ouvert|defaut`
  (jamais d'édition manuelle de `.env`). B la remet à `defaut` en fin de passage.
- Toute autre vague lit `service_ouvert` dans la réponse de `/api/frontend/order/wait-estimate`
  AU MOMENT de sa capture avant de juger un texte d'attente ou de créneau.

## Round 2 — 2026-10-01 matin (après les correctifs du round 1)
Correctifs livrés depuis le round 1 (tous prouvés par un banc qui rougit sans eux) :
- Backend `1f33aef6e` : suivi d'une commande web acceptée répondait **500** (53 erreurs en production
  du 25 au 28/09). `d1f33574c` : fiche caisse « 02-10-2026 » pour une commande programmée ce soir
  (règle unique `App\Support\CreneauRetrait`) + `queue_number` dans « Mes commandes ».
  `b32b92d90` : version minimale de l'application (`GET /api/frontend/app/config`, refus 422
  `APP_UPDATE_REQUIRED` au-dessous).
- Site, lot `20261001lot1` : écran « Mise à jour nécessaire » ; garde « onglet périmé » réparée
  (morte depuis le 08/08) ; compte connu accueilli « Bon retour » ; déconnexion en haut de l'accueil ;
  texte du profil exact ; titres de fin d'inscription lisibles (crème sur jaune, A-001) ; recours
  « passe au comptoir » affiché (A-002) ; numéro APPELÉ « N°A0051 » au ticket, au suivi et dans les
  notifications (O-B6).
- Site, lot B (à venir) : après connexion → menu, ou on RESTE au paiement (A-006 : le client était
  éjecté du paiement vers la fidélité) ; téléphone « 06 06… » rattrapé, trop long refusé (A-008).

Ce qui ne doit PLUS être signalé comme ouvert : O-B5, O-B6, F-B2, A-001, A-002, A-006, A-008, O-2, O-3, O-4.

## Round 4 — 2026-10-02 (après les revues adverses du round 2)
Site `2a73e94` (lots C à H), backend `5fa5f3b85` (+ assets recompilés). Corrigé ET prouvé par un banc
qui rougit sur le code d'avant — à ne PLUS signaler comme ouvert :
- Vague B : B2-R2-01 (titre de confirmation au numéro appelé), B2-R2-02 (« ~30 min » après
  acceptation : backend `a4c408a59`, migration `preparation_time_confirmed_at`), B2-R2-03 à -09
  (fiche caisse web : composition + Cheddar, statut de paiement, « Paiement au comptoir » /
  « Heure de retrait », plus de 403 livreurs, plus de toast en double, bandeau cuisine et toast
  caisse au « N°A00xx »), B2-R2-10 (« Envoyée au restaurant »), -12 (pas de QR sur une commande
  annulée), -13 (« prête ! » insécable).
- Vague C : C-001/CW-002 (boisson épuisée non proposée en formule), C-002/CW-006 (un seul délai),
  C-003/CW-003/CW-004 (rien de collant sous l'en-tête, bureau compris), C-004/CW-005 (« dépensés »
  = commandes retirées), C-005 à C-011 (logo, prix d'un choix, bandeau hors ligne, aide du créneau
  fermé, lien d'évitement des pages légales, « PARTI ! » lisible), C-009/CW-012 (barre d'action de
  la fiche produit), CW-007 (pied de page sous la barre de paiement), CW-009 (filtre XL).
- RÉFUTÉ : C-012 (boutons − / + du panier : zone tactile 44×44 par ::after, styles.css:1103).
- Décision propriétaire, PAS un défaut à corriger ici : CW-001 (la page allergènes promet un
  récapitulatif d'allergènes que l'assistant n'affiche pas — données de correspondance devinées).
