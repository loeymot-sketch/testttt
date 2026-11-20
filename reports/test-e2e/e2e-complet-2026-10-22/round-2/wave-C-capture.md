# Vague C (application des stores, Capacitor 8) — TOUR 2 : rejeu + constat

Date : 2026-10-23 (≈ 02:00–02:45 CEST). Aucune correction de code produit. Dépôt du site/app en lecture seule (au départ et à la
fin : HEAD `681577d`, 118 fichiers non commités, inchangés ; `app-native.js` sha `25cdc346…` inchangé). `origin/main` toujours `ae8d0ea`.
Backend HEAD `64c4a4f81` (`lang/fr.json` : 2 clés « (and :count more error(s)) »). Serveurs : `:8766`, `:8011` (ligne A = arbre de
travail, pont mort), `:8012` relancé par moi sur `scratchpad/site-www-main` (ligne B = `origin/main` construit, `api-base-url` → `:8766`), **arrêté à la fin**.
Specs : copies `C-*-r2.spec.js` + `C-lib-r2.js` dans `tests/Playwright/e2e-complet-2026-10-22/`, preuves dans
`tests/e2e/__screenshots__/e2e-complet-C-r2/`. Données `E2E-APP-` (client 914, comptes jetables supprimés, commandes à emporter).
Budget : **6 PNG neufs** (+1 capture d'échec Playwright dans `test-results/`, ignoré par git), rien de supprimé. Disque libre fin : 288 Mo (il a baissé de 1,3 Go
pendant la session sans que j'en sois la cause : mes écritures = 6 Mo).

## 1. Rejeu : tour 1 / tour 2 / cause

| Spec (copie r2) | Tour 1 | Tour 2 | Même cause ? |
|---|---|---|---|
| `C-natif` ligne B `:8012` | 7/7 | **7/7** | oui |
| `C-natif` ligne A `:8011` | 0/7 | **0/7** (7 échecs) | oui : pont mort (`app-native.js:38` `registerPlugin`, 0 appel natif) ; NAT-5 : 422 « 1 Viande (actuel : 2) » (C-006). Le message est désormais « …(**et 1 autre erreur**) ». NAT-5 iOS : 1re fois aucun POST émis (écart), non reproduit en 2 rejeux (422 ×2) → artefact isolé, `non reproduit` |
| `C-contrat-api` | 4/4 | **4/4** (au 1er passage 3/4 : C-API-3 a reçu **429**, limiteur d'inscription 3/5 min saturé par mes propres essais ; rejoué après 5 min = 4/4) | oui ; le 429 = environnement/instrument, pas produit |
| `C-m10-rerun` ligne A | 4 échecs / 5 (A1, A2, A3, C1) | **4 / 5, mêmes tests** | oui : A1/A3 pont mort (M10-001), A2 = 422 viandes (C-006) + `LocalNotifications` (instrument : journal remis à zéro par `page.goto`), C1 = `compiled/racine.js` cherche `funnel.jsx` (M10-003) |
| `C-m10-rerun` ligne B | 2 échecs / 5 (A2, C1) | **2 / 5, mêmes tests** | oui : A2 = instrument (idem), C1 = instrument (la spec lit `racine.js` du dépôt de travail, pas du site servi) |
| `C-captures` | « 3/3 verts » (4 `expect`) | **réécrite** : 5 tests, 2 verts (MORD, SUPPR), 4 rouges **pour des constats** (voir §3) | changement d'instrument, pas de produit |

Les 7 verdicts natifs de la ligne B, les 9 portes, l'émulateur et iOS ne sont pas rejoués (pas d'émulateur/APK/Xcode, disque).

## 2. Fermetures vérifiées

**C-101 (P1, « (and 1 more error) ») — FERMÉ, 2 moyens indépendants** (`C-r2-c101.spec.js`, 3/3 verts, `…/c101/constats.json`) :
1. API : POST `/api/frontend/order` avec la charge utile exacte du wizard de la ligne A (Cayenne, 2 variations viande) → 422,
   `message` = « Sélectionnez au maximum 1 Viande 1 (actuel : 2). (**et 1 autre erreur**) » ; aucun fragment anglais dans tout le JSON ;
   **aucune commande créée** (`count(*)` 4535, `max(id)` 8762 avant = après). Pluriel : POST vide sur `guest-signup/verify` → « (et 3 autres erreurs) ».
   Idem sans `Accept-Language`.
2. Écran de l'app (ligne A, la seule qui provoque ce 422 réel) : le refus est **visible** (« …(et 1 autre erreur) » au-dessus de « Confirmer ma commande »), statut 422, 0 commande créée.
La spec mord : le détecteur d'anglais est vérifié contre la chaîne exacte du tour 1 (`(and 1 more error)`), qui le déclenche, et contre la chaîne corrigée, qui ne le déclenche pas.
Remarque (non défaut) : le fragment « (et 1 autre erreur) » est lisible mais technique pour un client ; l'erreur du champ est doublée (« Sélectionnez au maximum… » + « « Cayenne » comprend 1 viande… »).

**Suppression de compte — TOUJOURS FONCTIONNELLE** : API (`C-API-3`, compte jetable) 401 sans jeton / 200 avec ; en base nom « Compte supprimé », e-mail effacé, `deleted_at` posé, 0 jeton,
commande conservée (8760), ancien jeton → 401. UI (`C-R2-SUPPR`, compte 933) : bouton → confirmation → jeton local effacé → message « Ton compte a été supprimé… » ; base `Compte supprimé / NULL / 2026-10-23 02:34:21`, 0 jeton.

CORS (5 chemins × 3 origines de coque + témoin négatif) et commandes avec jeton (201 ×2, total base = 2 × catalogue) : identiques au tour 1.

## 3. Instrument corrigé (C-105) et ce qu'il a trouvé

`C-captures-r2.spec.js` : de 4 à **des assertions par état** (11 états × 2 viewports) : texte attendu rendu (`innerText`, insensible à la casse/typographie), 0 clé i18n, 0 `undefined/NaN/null/[object Object]`, 0 débordement, largeur ET hauteur ≥ 44 px du CTA principal (mesurées), aucune zone tactile < 44×44 hors lien en ligne (soft), 0 erreur JS, 201 de la commande, plus contrastes WCAG **calculés** (fond effectif composité).

**Preuve que les assertions mordent** (3 niveaux) :
- Test `C-R2-MORD` (passe) : sur la vraie page, chaque catégorie est cassée volontairement et doit être détectée — clé i18n (`cart.empty_label`), `Total undefined €`, débordement horizontal, CTA réduit à 30×20, texte absent, bouton « + » de 20 px ; la page saine ne lève rien.
- Cassage du vrai flux (`R2_CASSER`) : `04-panier:cles` → « clé i18n brute : ["cart.empty_label"] » ; `07-…:texte` → « texte attendu absent » ; `02-carte:debord` → « débordement horizontal… + élément non rogné hors écran à droite (droite 690) ». Les 3 font rougir `C-R2-CAP-390` à l'état ciblé, rien d'autre.
- 1re version de mes assertions : le test « mord » a **échoué** (débordement non détecté) → voir défaut d'instrument 1.

**Défauts de mon propre instrument (tour 1 inclus), corrigés dans r2 :**
1. **Le test de débordement du tour 1 (`scrollWidth > innerWidth`) était aveugle.** En émulation mobile le viewport de mise en page s'élargit avec le contenu (`innerWidth` 390 → 690 quand un élément déborde) ; de plus `html` et `body` ont `overflow-x: clip`. Le « 0 débordement sur 22 états » du tour 1 ne prouvait donc rien. Corrigé (largeur de référence = viewport émulé + test des éléments non rognés par un ancêtre interne). **Remesuré : 0 violation sur les 11 états × 390/360** → la conclusion du tour 1 tient, désormais prouvée.
2. Mon mesureur de contraste a d'abord pris un conteneur (le `textContent` du parent contient le texte) : « Faim ? Va voir le menu » sortait à 1,07:1 à tort. Corrigé (feuille uniquement) ; la mesure rejoint celle des pixels de l'émulateur (5,38:1 des deux côtés).
3. Textes en `text-transform: uppercase` (`innerText` rend en capitales) et espaces insécables : regex sensibles à la casse = faux rouges ; corrigé.
4. Limiteur d'inscription 3/5 min : un 429 se lit comme un échec de suppression de compte ; à espacer.

## 4. États ajoutés par la revue adverse

**C-104 — panier VIDE, contraste calculé : CONFIRMÉ sur l'accueil, NON REPRODUIT sur l'onglet Menu.** (`C-R2-VIDE`, rouge sur l'accueil)

| Ouvert depuis | « PANIER » | « Ton panier est vide » | « Faim ? Va voir le menu. » |
|---|---|---|---|
| Accueil (`<body class="lcx-nuit">`) | `rgb(251,247,240)` sur `#FFF` = **1,07:1** | **1,07:1** | 5,38:1 (`rgb(111,106,96)`) |
| Onglet Menu (sans `lcx-nuit`) | `rgb(10,10,10)` = 19,8:1 | 19,8:1 | 5,38:1 |

Moyens indépendants : (a) style calculé + composition des fonds (pile : `lc-cart-drawer` blanc, couleur héritée de `body.lcx-nuit`) ; (b) pixels de l'émulateur réel (tour 1, `device-android/panier-ouvert.png`) : pixel le plus sombre du texte `(251,247,240)` sur `(255,255,255)` = 1,07:1, témoin « Faim ? » 5,38:1 ; (c) PNG Chromium relu à l'image (`13a-panier-vide-accueil-390.png` : titre et phrase quasi invisibles). Précision à l'adversaire : il n'y a pas de « mode jour » système (aucun `prefers-color-scheme` dans le site) ; le défaut dépend de la page d'où le tiroir est ouvert (accueil = nuit). Seuil : WCAG 1.4.3, 4,5:1. Cause : `.lc-cart-title`/ligne « vide » sans couleur propre héritent du corps de nuit (`styles-v7-braise.css:74` n'épingle que `.lcx-nuit .lc-modal`). Atteignable par tout client : accueil → icône Panier avec panier vide.

**C-103 — barre d'état : CONFIRMÉ sur capture appareil du tour 1, NON REJOUÉ sur appareil** (`C-R2-BARRE`, rouge). Appels au démarrage (exacts, assertés) : `setStyle DARK` (= icônes claires), `setBackgroundColor #0A0A0A`, `setOverlaysWebView false` ; fond demandé = 19,8:1 (l'intention est bonne). Mesure sur la bande d'état des 4 PNG d'émulateur (API 36, 1080×2400, 3 % haut) : fond `(250,250,250)`, icônes blanches → **1,04:1** sur chacun (seuil 3:1, WCAG 1.4.11) ; mesuré deux fois (Pillow et canvas Chromium, mêmes pixels). Second moyen : `targetSdkVersion 36` (`variables.gradle`) = edge-to-edge imposé, la couleur demandée n'est pas appliquée ; proxy Chromium : le haut de la page elle-même est clair (Menu 1,07:1, accueil 1,49:1), donc des icônes claires seraient illisibles même sur le contenu. Non prouvé ici : l'effet sur iOS et sur Android < 15 (pas d'appareil).

**C-007 — zones tactiles : REPRODUIT avec assertion** (CAP-390 et CAP-360 rouges uniquement pour cette raison) : boutons « − » et « + » du tiroir panier 28×28 (390 et 360), champ de recherche de la carte 296×40 / 266×40. Les liens CGV/Confidentialité (24×14, 75×14) sont désormais exemptés comme liens dans une phrase ; ne pas les compter. Tous les CTA principaux mesurés ≥ 44 px.

## 5. Constats candidats

Aucun défaut nouveau. État des constats du tour 1 après rejeu :
- **Fermé** : C-101.
- **Confirmés (2 moyens)** : C-103, C-104 (précisé : accueil seulement), C-007.
- **Toujours vrais, inchangés** (rejoués, mêmes causes) : C-001 (pont mort ligne A), C-003, C-004, C-005, C-006 (422 viandes ligne A), C-002 (deux lignes) — tous `owner_gate`/connus.
- **Non reproduit** : NAT-5 iOS ligne A sans POST (1 occurrence sur 3 passages ; 2 rejeux = 422). Cause non établie, ne pas le porter.
- **Hors périmètre** de ce tour : C-008/C-009/C-108/C-109/C-110 (non rejoués : seuls les états nécessaires aux assertions ont été capturés).

## 6. Non couvert
iOS (Xcode absent), connexion Apple/Google réelles, e-mail de bout en bout (SMTP de dev), production (CORS POST, `social/*`, `RefuseOutdatedApp`), appareil Android réel/émulateur (C-103 et C-104 sur appareil = captures du tour 1, non rejouées), états « prête »/notification de fin, portes de publication `release-gates.mjs` et build gradle (disque), safe-area réelle, défilement de la modale à 360×640. La spec `C-captures-r2` n'a pas été jouée contre la ligne A (pont mort).
