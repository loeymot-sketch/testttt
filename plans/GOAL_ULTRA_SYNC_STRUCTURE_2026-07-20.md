# GOAL — Audit ultra-profond web + connectivité totale + structures (programmées · Mollie · SMS · fidélité) · 2026-07-20

> Owner (verbatim condensé) : audit ultra-profond du site web (toutes pages principales/secondaires + toutes
> fiches produits + logique), synchronisation/connectivité avec la gestion et TOUS les systèmes (borne, caisse,
> KDS, web) — **zéro doublon, zéro chose mal faite ou désynchronisée** ; **même logique par catégorie** ; vérifier
> que les CALCULS marchent vraiment (panier, total, paiement) — trauma : la borne affichait un prix sans prendre
> les suppléments et on lui a déjà dit « validé » à tort → **DOUBLE VÉRIFICATION obligatoire : agents adversaires
> + agents captures d'écran avec analyse de logique, preuves RÉELLES, boucle test-e2e jusqu'à validation totale**.
> Puis PRÉPARER les structures : (a) commandes programmées — une commande pour dans 1 h ne s'affiche PAS en
> cuisine ; elle apparaît ~20 min avant + notification KDS « commande programmée à venir » ; statuts synchro
> (prête → visible sur le compte client) ; (b) paiement carte web via **Mollie** (structure complète, mode TEST,
> clés fournies par l'owner à la fin) ; (c) fournisseur SMS (structure, clés owner) ; (d) structure base fidélité
> clients inscrits — vérifier que ça marche.

## §0 Préambule
- **Pipeline par tâche** : audit → fix scope-minimal → RE-audit adversaire + capture (binôme) → convergence
  P0+P1=0 ×2 cycles (règle test-e2e). JAMAIS « validé » sans preuve capturée+analysée.
- **Frozen** (CLAUDE.md §7) intouchables sans LOCK ; NF525 §8 (pricing backend SSOT, snapshot immuable).
- **État déjà PROUVÉ cette session** (à re-vérifier en W2 quand même — mandat double-vérif owner) : drop-prix
  web résolu (orders #171 13,20€ / #172 1,90€, snapshot complet), 26 fixes ultra-audit convergés (`f4d6aa9`),
  CORS durci, parité options web=borne (générateur --check 0 dérive).
- Budget contexte : exécution multi-sessions ; CE DOC = artefact de reprise (chaque vague = checkpoint commité).

## §1 Ancres vérifiées (2026-07-20, greps réels)
| Domaine | Ancre | État |
|---|---|---|
| Cmd programmées | `orders.is_advance_order` + `delivery_time` (migration 2022_11_17 l.32-34) ; FrontendOrder fillable l.50-52 | champs EXISTENT, sémantique KDS à créer |
| KDS | `Admin/KitchenDisplaySystemController` + `KdsSyncController` (transitions append-only, recall idempotent) | board à gater T-20min |
| Mollie | `app/Http/PaymentGateways/Requests/Mollie.php` + `PaymentRequests/Mollie.php` (`mollie_api_key` setting) ; Gateways/ = Credit/Paypal/Senangpay/Stripe… | Requests EXISTENT ; Gateway class + câblage web à faire |
| Web carte | `index.html` meta `feature-online-card` (OFF) + funnel méthode 'card' scaffold + `config/payment.php` verrou serveur | flag+UI prêts, backend à câbler |
| SMS | `app/Http/SmsGateways/Gateways/` : Twilio, Nexmo, Bulksms, Msg91, Clickatell… | structure EXISTE, provider+clés owner |
| Fidélité | migrations loyalty_transactions (+UNIQUE), loyalty_consents, users loyalty fields, orders.loyalty_awarded | tables EXISTENT, intégrité à auditer |
| OTP | table `otps` (token) ; throttles 5/min + 3/5min | prêt, SMS réel = clés |

## §2 Vagues (chacune : binôme CAPTURE+ADVERSAIRE, checkpoint commité, BRAIN §2 màj)

### W1 — AUDIT TOTAL du site web (pages + fiches produits)
Périmètre : accueil, menu (9 catégories), 38 fiches produit (modal + wizard par catégorie), panier, upsell,
checkout, paiement, confirmation, suivi, compte/OTP, fidélité, historique, FAQ, 5 pages légales, footer/nav.
- T1.1 Agent CAPTURES : parcourt le site DÉPLOYÉ (site-lecayenne.vercel.app), capture chaque page/état,
  ANALYSE chaque capture (layout, labels bruts, états vides, honnêteté). → `reports/test-e2e/goal-ultra-sync-2026-07-20/W1-captures/`
- T1.2 Agent ADVERSAIRE logique : chaque fiche produit vs backend VPS (`/api/frontend/item(+details)`) —
  options proposées = vraies options, prix affiché = prix backend, wizard = règles catégorie.
- Acceptance : registre findings vérifiés (file:line+repro) ; heal → re-binôme ; P0+P1=0 ×2.

### W2 — CALCULS money-path re-vérifiés en DOUBLE (mandat trauma owner)
Borne (local :8000) + web (déployé) : pour CHAQUE catégorie composable (sandwich, galette, burger, tacos, bol,
frites, menu enfant) : compo avec suppléments payants → capture du total à CHAQUE étape (wizard→panier→
paiement→confirmation) → **vs total scellé backend + composition_snapshot** (rien de largué, tout facturé).
- Binôme : agent capture (screenshots des totaux) + agent adversaire (DB/curl). Kill-switch : 1 centime d'écart = P0.
- Acceptance : tableau par catégorie « affiché=scellé=snapshot » ; test (à créer) `tests/e2e/money-path-par-categorie.spec`.

### W3 — CONNECTIVITÉ / ANTI-DOUBLONS cross-systèmes
- T3.1 Catalogue SSOT : DB items (45) vs borne (API) vs web (`data/menu.js`) vs caisse — doublons, orphelins,
  items test (id 83 `CENTRAL-CAT-VIS`…), divergences prix/flags/catégories. Générateur `--check` = gate.
- T3.2 Gestion → propagation : modifier (dispo 86, prix si autorisé, nom) depuis l'admin → vérifier borne+web+
  caisse+KDS reflètent (délais réels mesurés, polling vs WS).
- T3.3 Même logique par catégorie : matrice catégorie × règles (viandes N incluses, sauce 1ère gratuite,
  suppléments 0,90, gratiné bols-only 2€, menu 2,50/1,50/1,00) — borne vs web vs caisse : ZÉRO divergence.
- Acceptance : matrice publiée + 0 doublon + registre divergences healées.

### W4 — STRUCTURE commandes programmées (advance orders)
Design d'abord (architecte lit OrderStateMachine[FROZEN—lecture], KDS feed, OSS) puis scope-minimal :
- T4.1 Backend : sémantique `scheduled_at` (datetime cible) dérivée de `delivery_time`/`is_advance_order`
  (champs existants) ; validation OrderRequest (fenêtre ouverture 18h-00h) ; **hors KDS tant que
  `scheduled_at - now > lead`** (lead configurable, défaut 20 min, `config/kds.php` à créer).
- T4.2 KDS : bandeau « ⏰ commande programmée pour HH:MM » (notification à l'approche, pas de carte cuisson
  avant T-lead) ; à T-lead → carte normale + son. PAS de mutation status (append-only pattern respecté).
- T4.3 Web/borne : re-proposer les créneaux (>ASAP) UNIQUEMENT quand le backend honore vraiment (réactive les
  slots retirés le 19/07, cette fois branchés sur scheduled_at réel) ; ticket/суivi affichent l'heure cible.
- T4.4 Compte client : statut « Prête » synchro (canal customer.{id} existant + polling) — preuve capture.
- Gate owner G-W4 : confirmer lead 20 min + créneaux proposés (20/40/60 min ? heures fixes ?).
- Acceptance : e2e — commande T+1h invisible KDS, bandeau visible, apparaît à T-20, « prête » sur compte.
  Tests (à créer) `tests/Feature/Kds/ScheduledOrderGatingTest.php` + spec Playwright captures.

### W5 — STRUCTURE Mollie (carte web, mode TEST)
Réutiliser le squelette SaaS (Requests Mollie existants) :
- T5.1 Gateway `app/Http/PaymentGateways/Gateways/Mollie.php` (create payment → checkout_url, webhook
  `/api/webhook/mollie` signé, idempotent — middleware existant), package `mollie/mollie-api-php` via composer.
- T5.2 Flux web : funnel 'card' (flag `feature-online-card`) → placeOrder(paymentMethod=card) → backend crée
  le paiement Mollie TEST → redirect → retour → webhook confirme → `payment_status=PAID` (allocation fiscale
  = chemin kiosk-paid EXISTANT — pas de nouveau chemin NF525). Échec/annulation → retour propre panier.
- T5.3 Config : `MOLLIE_API_KEY` (.env, `test_xxx` owner plus tard) ; flag serveur `config/payment.php` reste
  le verrou ; TOUT reste OFF par défaut tant que clés absentes (fail-closed).
- Gate owner G-W5 : clés Mollie test + live (fin) ; activation du flag.
- Acceptance : suite Feature mockée (webhook signé/idempotent/montant=scellé) verte ; e2e réel dès clés test.

### W6 — SMS + fidélité DB
- T6.1 SMS : choisir provider FR compatible (gateways existants : Twilio/Nexmo/Bulksms…) ; config prête +
  doc HANDOVER (S10) ; OTP bascule table→SMS réel dès clés. Gate owner G-W6a : choix provider + clés.
- T6.2 Fidélité : audit intégrité base clients inscrits — doublons téléphone, transactions orphelines,
  somme(loyalty_transactions)=solde user, consents RGPD, QR/redeem e2e. Heal + sentinelle si dérive.
- Acceptance : rapport intégrité 0 anomalie (ou healées) + e2e compte réel capture.

### W7 — BOUCLE test-e2e FINALE (convergence globale)
Skill test-e2e : toutes surfaces (web déployé + borne + caisse + KDS + OSS), binômes capture+adversaire,
set-equality 2 cycles propres. Rapport `CONVERGENCE_FINAL.md` + captures analysées + tag.

## §A Armée d'agents (mandat owner : adversaires + captureurs)
| Rôle | Type | Périmètre |
|---|---|---|
| Captureur-analyste ×N | general-purpose + Chrome MCP | 1/vague : captures RÉELLES du déployé, analyse visuelle+logique |
| Adversaire logique ×N | general-purpose read-only | réfute : code vs backend vs affiché, file:line+repro |
| Architecte | Plan | designs W4/W5 avant code |
| Implémenteurs // | general-purpose | fichiers DISJOINTS uniquement (leçon 19/07) |
| Chasseur régression | general-purpose | après chaque lot de fixes, sur le diff |
Règles : findings sans preuve = REJETÉS ; « validé » exige capture analysée + vérif backend croisée.

## §G Gates owner (WHO/WHAT/WHERE)
| Gate | Quoi | Quand |
|---|---|---|
| G-W4 | lead KDS (20 min ?) + liste créneaux proposés | avant T4.3 |
| G-W5 | clés Mollie (test puis live) + activation flag | T5 fin (structure sans clés = OK) |
| G-W6a | provider SMS + clés | fin |
| G-LÉGAL | ~15 champs entité (déjà listés 19/07) | quand dispo |
| G-FIN | go/no-go après CONVERGENCE_FINAL | W7 |

## §F Definition of Done
Toutes vagues convergées (P0+P1=0 ×2, preuves captures+backend), matrice parité catégories publiée, 0 doublon
cross-système, structures programmées/Mollie/SMS/fidélité EN PLACE (testables, fail-closed sans clés), boucle
finale verte. Alors seulement : demander les clés à l'owner (Mollie, SMS) et brancher.
