# État des lieux — vague S : le SITE (tunnel de commande) face à R1..R7

Date : 2026-10-23. Lecture seule : aucun fichier de produit modifié, aucun `git add/commit/push`.
Spec : `tests/Playwright/etat-des-lieux-2026-10-23/S-site-livraison.spec.js` (6 tests, 6 verts, mobile 390x844 puis desktop 1440x900).
Captures (quatuor PNG + DOM + console + réseau) : `reports/test-e2e/etat-des-lieux-2026-10-23/S-captures/` (20 PNG).
Registres : `S-captures/S-ledger.json` (passe complète), `S-captures/S-ledger-debit.json` (rejeu du test 5 après correction d'un critère de l'instrument, voir §8).

## 0. Matrice R1..R7

| # | Exigence | Verdict | Preuve principale |
|---|---|---|---|
| R1 | Choix « À emporter / Livraison » au tout début | **PARTIEL** | Le choix existe mais seulement sur la page de commande (étape « Livraison & paiement »), après carte → panier. Ni l'accueil ni la carte ni le panier ne le proposent. `S-captures/D-05-livraison-choisie.png`, `M-04-commande-mode-retrait.png` ; `funnel.jsx:506-545` ; panier : « Heure de retrait… à l'étape suivante » (`D-03-panier` DOM). |
| R2 | Adresse saisie + choix dans une liste de suggestions | **PARTIEL** | Suggestions BAN via le serveur, mais dans un `<datalist>` natif (`funnel.jsx:554-560`), il faut ensuite appuyer sur « Calculer » ; liste qui disparaît en silence au-delà de 40 appels/min/IP (S-004). 5 suggestions pour « 12 rue de la Gare Lens ». `D-06-adresse-lens-9km.png`. |
| R3 | Distance routière ; < 7 km nos livreurs ; ≥ 7 km devis Uber Direct « à partir de 7 km — tarif calculé par Uber » ; au-delà de la zone Uber Eats | **PARTIEL (distance FAUSSE, devis Uber ABSENT)** | Distance = vol d'oiseau (devis == haversine recalculé, écart < 0,005 km sur 5 adresses ; la route réelle OSRM est de +26 % à +67 %). Un seul seuil à 10 km, aucun seuil à 7 km, aucun devis Uber Direct, libellé absent. Existe : refus > 10 km + lien « Commander sur Uber Eats » (`D-06-adresse-lille-gt12.png`). Lens : 8,82 km à vol d'oiseau = 6,90 € nos livreurs (route 11,14 km). |
| R4 | Annonce « Nouveau : la livraison ! » sur l'accueil | **ABSENT** | `D-01-accueil.png`, `M-01-accueil.png` : « À emporter, retrait au comptoir », « Click & collect ». Seule mention livraison du texte visible : « Aussi sur Uber Eats » (pied de page, `components.jsx:383`). Aucune occurrence dans les sources (grep). |
| R5 | Vouvoiement partout | **FAUX** | 52 phrases tutoyées distinctes vues sur les écrans traversés ; 244 lignes de code source contiennent une chaîne tutoyée (voir §5). |
| R6 | Choix du mode de paiement (à la livraison espèces / carte / titre-restaurant, ou plusieurs paiements) APRÈS la confirmation, enregistré dès le début | **ABSENT** | Le choix est AVANT la confirmation, même page, et ne propose que « Payer sur place — espèces ou carte au comptoir » et « Carte bancaire (en ligne) ». Aucune mention de titre-restaurant ni de paiements multiples. La confirmation ne propose aucun choix (boutons : « Suivre ma commande », « Retour à l'accueil »). Base : `orders` 8838-8842 créées avec `payment_method=1`, `order_type=5`. De plus le libellé est faux pour une livraison (S-002). |
| R7 | QR code d'avis sur la facture/ticket | **ABSENT** | Le seul QR de la confirmation est le « QR de retrait — commande N » (aria-label, `funnel.jsx:127-170, 2507`). Aucun lien d'avis (liens de la confirmation : aucun ; du suivi : Google Maps itinéraire, Uber Eats, `#avis`). La page « Avis » est un « Bientôt » (`screens.jsx:2239`). Ticket de caisse imprimé : `EscPosCommandBuilder::qrCode()` n'est appelé que par `PromoFlyerEscPosRenderer.php:163`, pas par `OrderReceiptEscPosRenderer`. Aucune URL d'avis Google (Place ID) n'existe dans le dépôt. |

## 1. Instrument et état de l'environnement

- Miroir `:8011` == dépôt : `funnel.jsx` empreinte identique ; `compiled/funnel.js` SHA-256 identique entre miroir et dépôt (`S-ledger.json › etatOrigine`) ; `compiled/funnel.js.source-sha256` == `shasum funnel.jsx`. La page charge `compiled/funnel.js?v=20261020livraison3` (`index.html:422`), pas le `.jsx`.
- État d'origine de la livraison : **active** (`GET /api/frontend/delivery/status` → `enabled:true`). Je n'ai RIEN modifié : aucune bascule à remettre. La variante « suspendue » est simulée dans le navigateur par interception de `/delivery/status`.
- Preuve que l'instrument voit les deux états (test 0) : livraison active → le sélecteur `[role=radiogroup][aria-label="Mode de retrait"] [role=radio]` renvoie 2 tuiles (À emporter, Livraison « Dès 3,90 € ») ; suspendue → 1 tuile + un lien « 🛵 Livraison Sur Uber Eats → » (`D-INSTR-livraison-active-tuiles.png`, `D-INSTR-livraison-suspendue-uber.png`). Donc « R1 absent à l'accueil » n'est pas un sélecteur aveugle.
- R4 : le même balayage de texte visible qui ne trouve pas « Nouveau : la livraison » retourne bien la ligne « Aussi sur Uber Eats » (il voit le vocabulaire livraison/Uber) ; second moyen indépendant : grep des sources du site, 0 occurrence.
- R7 : le sélecteur trouve bien le QR existant (`.lcf-ticket-qr`, 1 élément, aria-label « QR de retrait — commande 2310268842 ») ; second moyen : `grep qrCode(` côté backend.
- R3 : second moyen indépendant : haversine recalculé dans le test depuis les coordonnées renvoyées par `/delivery/suggest` + routage OSRM public (`router.project-osrm.org`).
- Limites de l'instrument : la liste `<datalist>` est une fenêtre native non capturable ; je lis ses `<option>` dans le DOM et « choisis » en posant la valeur exacte d'une option (ce que fait le navigateur). Je n'ai pas pu prouver le rendu de cette liste sur iOS Safari / Android Chrome réels (hors de portée, voir S-007). L'envoi du code e-mail échoue en local (« Le code n'a pas pu être envoyé », 422 sur `guest-signup/email-login`) : environnement SMTP, déjà connu, hors sujet ; le code est lu en base (`otps`) comme dans la vague A.
- Les tournées 18:00-21:30 étaient déjà pleines (6 arrêts) avant mes essais (données d'autres sessions) : « Au plus tôt vers 22:00 » est un artefact de la base locale.

## 2. Parcours mesuré (mobile puis desktop, mêmes résultats)

Accueil → carte (40 produits) → fiche Cheese Burger → wizard → panier (sur mobile le tiroir ne s'ouvre pas seul : ouverture par le bouton Panier) → « Passer commande » → page de commande.

Devis par adresse (réponses `POST /api/frontend/delivery/quote`, identiques mobile et desktop) :

| Adresse tapée | Suggestion choisie | HTTP | Distance (devis) | Route OSRM | Affichage client |
|---|---|---|---|---|---|
| zzzz qqq 99999 Nullepart | aucune (0 option) | 422 | — | — | « Adresse invalide. Veuillez en saisir une autre. » (alerte rouge, `D-06-adresse-inconnue.png`) |
| 10 rue Nationale 59000 Lille | « 10 Rue des Stations 59000 Lille » (la BAN a substitué la rue) | 200 allowed:false | 24,34 km | 31,67 km | « est trop loin pour notre livraison (plus de 10 km) » + lien **Commander sur Uber Eats →** (`D-06-adresse-lille-gt12.png`) |
| 12 rue de la Gare 62300 Lens | « 12 Rue de la Gare 62300 Lens » | 200 | 8,82 km | 11,14 km | « Lens · 8,82 km · Livraison 6,90 € » (`D-06-adresse-lens-9km.png`) |
| 1 rue Jean Jaures 62590 Oignies | « 1 Rue Jean Jaurès 62590 Oignies » | 200 | 5,82 km | 9,74 km | « Oignies · 5,82 km · Livraison 5,90 € » (`M-06-adresse-oignies-6km.png`) |
| 12 rue Elie Gruyelle 62110 Hénin-Beaumont | « 12 Rue Elie Gruyelle 62110 Hénin-Beaumont » | 200 | 0,42 km | 0,67 km | « Hénin-Beaumont · 0,42 km · Livraison 3,90 € » (`M-06-adresse-henin.png`) |

Sous un devis valide : créneaux « Au plus tôt / 22:00 / 22:30… départ » (tournées de 30 min). Aucun libellé « tarif calculé par Uber » nulle part.

Commande Hénin passée (mobile 8838/8839/8841, desktop 8840/8842) : `POST /api/frontend/order` → 201, `order_type=5`, `payment_method=1`, `delivery_charge=3.9`, total 9,90 € = 6,00 + 3,90 (cohérent avec bouton et récap). Ces 5 commandes de test ont été **annulées** ensuite par l'API admin (statut 16, motif « E2E-SITE-S… »), arrêts de tournée libérés (`delivery_stops` = 0 ligne pour elles).

## 3. Défauts réels (détails et reproductions dans `S-site-findings.json`)

| Id | Sév. | Titre |
|---|---|---|
| S-001 | **P1** | La confirmation et le suivi d'une commande en LIVRAISON la présentent comme un retrait au comptoir (« Présente ce QR à la caisse pour la récupérer. Tu paies sur place. », « Ticket retrait », « Prête dans ~980 min », étape « Récupéré », « Voir le QR de retrait ») |
| S-002 | **P1** | Pour une livraison, la page de paiement dit « Payer sur place — espèces ou carte au comptoir — ton plat t'attend » + pastille « PAIEMENT AU COMPTOIR » + « envoyée en direct à la caisse » : faux pour un client chez lui, et aucun choix du mode d'encaissement à la porte |
| S-003 | P2 | Adresse refusée (> 10 km) : le récapitulatif affiche « Livraison **Offerte** » en vert et Total 6,00 € |
| S-004 | P2 | Les suggestions d'adresse partent à chaque frappe (21 à 38 appels par adresse) ; limite 40/min/IP : la 2e saisie dans la minute reçoit des 429 et la liste devient vide, sans message |
| S-005 | P2 | Pages statiques et pré-rendu disent « pas de livraison en propre, Uber Eats » alors que le tunnel livre |
| S-006 | P3 | Mobile : le champ adresse tronque la saisie (« 1 RUE JEAN JAUI ») |
| S-007 | P3 (non prouvé sur appareil) | Suggestions dans un `<datalist>` natif : pas de liste visible/stylable garantie sur mobile, appui obligatoire sur « Calculer » |

P0 : 0. P1 : 2.

## 4. R1..R7 en détail

- **R1** : accueil `COMMANDER →` → `#menu` (liste des produits, aucun choix de mode : `ctaCommanderD/M` dans le registre). Tiroir panier : « Heure de retrait, code promo et note cuisine (allergies) à l'étape suivante. » La page de commande ouvre sur le titre « QUAND RÉCUPÉRER TA COMMANDE ? », mode « À emporter » présélectionné ; choisir « Livraison » change le titre en « OÙ LIVRER TA COMMANDE ? » et affiche le champ adresse. Le fil d'Ariane passe de « Retrait & paiement » à « Livraison & paiement ». Le client choisit donc le mode à l'étape 3 sur 4 du tunnel, pas « au tout début ». Le wizard produit ne dit rien du mode.
- **R2** : voir §2. Points forts : adresse vérifiée par le serveur, libellé compris affiché (« Adresse comprise : … »), refus clair pour une adresse inconnue. Manques : pas de carte ni de liste cliquable propre au site ; BAN peut substituer une autre rue (Lille : « rue Nationale » → « rue des Stations ») ; après choix il faut cliquer « Calculer ».
- **R3** : `DeliveryQuoteService.php:69` appelle `distanceKm()` (haversine, `:162`) ; `DeliveryTariff.php:30` coupe à `delivery.max_km = 10`. Le temps de trajet est `distance × 1,3 / 28 km/h` (`:139`), une estimation, pas un routage. Pour R3 il manque : routage réel, seuil 7 km, devis Uber Direct (`UberDirectClient` n'existe que côté caisse, droit `eats.deliveries` absent chez Uber, cf. `docs/LIVRAISON.md` §6), libellé, et le paiement du devis par le client (le coût Uber est aujourd'hui « à la charge du restaurant », `docs/LIVRAISON.md` §6). L'effet de bord à connaître : avec une distance routière, Oignies (5,82 km à vol d'oiseau, 9,74 km de route) basculerait côté Uber ; Lens (route 11,14 km) sortirait même de la zone de 10 km.
- **R4** : voir matrice. Le site vend explicitement « À emporter / Click & collect » sur l'accueil.
- **R6** : la page est « une seule page » (`funnel.jsx:797`, commentaire ONE-PAGE 2026-08-03) : mode, adresse, créneau, paiement, compte et bouton « Confirmer ma commande » ; la commande est créée au clic et `payment_method` (1 comptoir/espèces, 4 carte en ligne) est décidé AVANT. `docs/LIVRAISON.md` §3quinquies le confirme : le site n'envoie jamais le titre-restaurant.

## 5. R5 — vouvoiement

**Navigateur** (écrans traversés : accueil, carte, fiche, wizard, panier, commande retrait + livraison, adresses, paiement, confirmation, suivi) : **52 phrases tutoyées distinctes** (registre `S-ledger.json › tutoiementNavigateurUniques`, détecteur Unicode : pronoms tu/toi/ton/ta/tes/te/t', impératifs « précise, vérifie, réessaie, choisis… »). Exemples visibles : « Où livrer **ta** commande ? », « **Tu** viens chercher », « Dès 3,90 € · selon **ta** commune », « **Ton** email suffit : **tu** reçois un code », « **Tu** gagnes +99 pts sur cette commande », « Présente ce QR à la caisse pour la récupérer. **Tu** paies sur place. », « Suivi de **ta** commande », « **Ton** panier est vide ».

**Sources** (`Site lecayenne/`, commentaires et JSON-LD exclus, numéros de ligne d'origine) : **244 lignes de code contiennent une chaîne tutoyée**, dont **239 sont du texte affiché** et **5 des messages `throw`/`console` d'erreur de paiement/stock** (`api.js:853,976`, `funnel.jsx:1152,1154,1155`, probablement montrés au client par `showPayError`). Par fichier :

| Fichier | Lignes tutoyées affichées |
|---|---|
| `funnel.jsx` | 63 |
| `roue.html` (page publique de la roue) | 54 |
| `api.js` (messages d'erreur renvoyés à l'écran) | 37 |
| `account-v2.jsx` | 30 |
| `screens.jsx` | 30 |
| `wizard-v2.jsx` | 15 |
| `screens-v3.jsx` | 6 |
| `orders.jsx` | 3 |
| `flows.jsx` / `loyalty-v2.jsx` / `upsell.jsx` | 2 / 2 / 2 |
| `racine.jsx`, `components.jsx`, pages SEO (`carte.html`, `tacos.html`, `a-propos.html`…) | 0 (déjà au vouvoiement) |

`compiled/*.js` redouble ces chaînes (156 lignes, dont `compiled/funnel.js` 61) : c'est la sortie générée de ces sources, à ne pas additionner. Lignes où le tutoiement n'apparaît QUE dans un commentaire de code (non affiché) : **76** (dont 22 dans `funnel.jsx`, 16 dans `roue.html`) — exclues du compte. Les valeurs sont vérifiées au grep : p. ex. `funnel.jsx:484` « Entre ton adresse complète », `:502`, `:525` « Tu viens chercher », `:531`, `:1829` « ton plat t'attend », `:2459` « Tu paies sur place », `flows.jsx:89` « Ton panier est vide ».
Remarque de méthode : un premier détecteur à `\b` ASCII comptait « complète/coûte » (faux positifs) ; corrigé par bornes Unicode avant de publier les chiffres.

## 6. Ce qui existe déjà et marche (utile à ne pas reconstruire)

- Tuile Livraison pilotée par le serveur (`useDeliveryEnabled`, `funnel.jsx:21`), bascule caisse → site sans redéploiement, repli « Sur Uber Eats » (prouvé).
- Géocodage serveur BAN, adresse comprise affichée, refus d'adresse inconnue, refus > 10 km avec lien Uber Eats (URL `uber-eats-url`), total/bouton/récap cohérents (9,90 € partout), tournées de 30 min et créneaux.
- Prix recalculé par le serveur (`delivery_charge` posé par le serveur ; la commande a bien `delivery_tier=home`, `delivery_distance_km=0.421`).

## 7. Reproduction rapide

```
export PATH="$HOME/.nvm/versions/node/v22.23.2/bin:$PATH"
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_BASE_URL=http://127.0.0.1:8766 \
  npx playwright test tests/Playwright/etat-des-lieux-2026-10-23/S-site-livraison.spec.js --workers=1 --retries=0 --reporter=line
```
Chaque exécution complète crée 2 commandes de livraison de test (E2E-SITE-S-…) qu'il faut annuler (API admin `POST /api/admin/online-order/change-status/{id}` avec `status:16` et `reason`) pour ne pas remplir les tournées locales.

## 8. Limites et honnêteté sur mes propres mesures

- Dans la passe complète (`S-ledger.json`), le critère du test 5 était trop strict (il comptait l'alerte générique « Renseigne une adresse livrable… » comme message de débit) : il apparaît KO. Après correction du critère (une alerte doit parler de la limite), le rejeu seul (`S-ledger-debit.json`) est OK : 53 appels, 13 réponses 429, 0 option, aucune alerte relative à la limite. Le constat chiffré est identique dans les deux registres.
- Les chaînes R5 « tutoyées » sont détectées par pronoms et une liste d'impératifs ; des impératifs hors liste (ex. « Recharge la page », « Retire-le ») ne sont donc pas comptés : le compte est un minimum.
- Les 20 PNG sont des vues d'écran (non pleine page) ; j'ai lu les PNG suivants : `D-01-accueil`, `M-01-accueil`, `D-06-adresse-lens-9km`, `D-06-adresse-lille-gt12`, `D-06-adresse-inconnue`, `M-06-adresse-oignies-6km`, `D-07-paiement-avant-confirmation`, `D-10-confirmation`, `M-10-confirmation`, `M-11-suivi`, `D-INSTR-livraison-suspendue-uber`. Les autres (états similaires) ne sont attestés que par leur DOM/réseau.
- Non vérifié : le rendu du `<datalist>` sur iOS/Android réels et dans l'application Capacitor.
