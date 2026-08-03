# W5 E2E LIVE — CAISSE (POS) — 2026-07-02

Serveur `127.0.0.1:8766` (foodking_e2e), admin@lecayenne.fr. Captures dans `../captures/`.

## Parcours prouvé (critical-flow PLAYWRIGHT_MCP_OPS §2)
1. **Login** `/login` → 201, redirection `/admin/dashboard`. Console authentifiée = **0 erreur**.
   (Les 39 × 401 vus AVANT login = token de session expiré rejeté, comportement attendu.)
2. **Dashboard** (`central-dashboard.png`) : FR, branding Cayenne, **Audit Trail NF525** rend
   de vraies lignes HMAC-chaînées (Connexion / Encaissement comptoir confirmé / Commande créée).
3. **POS category-first** (`caisse-landing.png`) : 9 catégories avec images (Sandwichs, Galette,
   Burgers, Tacos, Bols, Frites, Desserts, Boissons, Menu enfant), panneaux « Prêt à livrer (0) »
   + « À encaisser borne (0) », ticket caisse, sélecteur À emporter/Livraison. **0 erreur console.**
4. **Wizard Tacos L** (`caisse-wizard-tacosL.png`) : Viandes 0/2 → sélection Cordon Bleu + Poulet
   mariné → **2/2**, sauce Blanche (1ère gratuite). Aperçu ticket : « Viandes : Cordon Bleu,
   Poulet mariné / Sauce : Blanche ». Formule Menu +2,50 € affichée correctement.
5. **Panier** : ligne « Tacos L — Viandes: Cordon Bleu, Poulet mariné / Sauce: Blanche — 7,90 € ».
   → **Le bug historique « Viande 2 perdue au panier » N'EST PAS présent** (les 2 viandes tenues).
6. **Paiement** (`caisse-payment.png`) : modal Espèces/Carte(TPE)/Multi + **pavé numérique**
   (a11y : group « Pavé numérique », boutons « Effacer le dernier chiffre »/« Effacer tout »
   labellisés). Montant reçu 10 € → « Confirmer & Imprimer ticket ».
7. **Réseau** : `POST /pos/quote 200` (re-pricing backend SSOT) → `POST /pos 201 Created` →
   `POST /pos/orders/5398/print-receipt 200`. **0 réponse 4xx/5xx sur le flux.**

## Vérification DB (order 5398)
- `serial=0207265398`, `source_surface=pos`, item price `7.90`.
- **composition_snapshot** (len 579) contient Cordon Bleu + Poulet mariné + Blanche → **exact**.
- `payment_status=15 (PENDING_COUNTER)`, `pos_payment_method=6 (COUNTER_DEFERRED)`, `fiscal_seq=null`.

## Analyse money-path (verify-before-report)
Résultat COUNTER_DEFERRED + fiscal null **initialement suspect**, puis **DISCULPÉ** :
`config/pos.php:180-202 walkin_route_to_counter=true` (=confirmé live) — **owner model (B)
GOAL-CAISSE-UNIFIED 2026-05-30** : toute commande caisse walk-in passe par la file
d'encaissement différée (symétrique kiosk Plan B). Commentaire du code :
« NF525 stays gap-free: the seq is allocated once, at collection, never at this deferred creation. »
→ 5398 est **correct** ; l'allocation fiscale se fera à l'encaissement (/admin/encaissement).
Les commandes 5390-5396 (PAID + fiscal 2581-2588) = déjà encaissées. Enums vérifiés :
`PosPaymentMethod::COUNTER_DEFERRED=6`, `PaymentStatus::PENDING_COUNTER=15`, `CASH=1`, `CARD=2`.

## Observations (à confirmer vagues W2/W3/W6)
- **OBS-POS-1 (P3, data-hygiene)** : 151 commandes PENDING_COUNTER (147 kiosk + 4 pos), dont
  beaucoup anciennes (status=13 DELIVERED + payment_status=15 = livrées mais jamais encaissées)
  → accumulation test-DB, source des « 277 alertes SLA / 21 j » du dashboard. Bruit de données,
  pas un défaut de code. À purger avant prod / non-bloquant V1 LOCAL.
- **OBS-POS-2 (P3, cosmétique frozen)** : format devise incohérent — wizard frozen affiche
  `€7.90` (point, € préfixe) vs grille/panier `7,90 €` (FR). Wizard = frozen strict → doc-only.
- **OBS-POS-3 (perf/sync, → W3)** : la page POS poll `oss-order` **2× par cycle** (doublon
  systématique dans le journal réseau, ex. req 37,38 puis 40,41) + `counter-collect/pending`
  ~1/s. À investiguer en W3 (double-abonnement/poll redondant).
- **total_price=NULL** sur TOUTES les commandes (y compris payées) = normal (total calculé
  ailleurs) — PAS un finding.

## Verdict CAISSE (live)
Flux de prise de commande + composition + pricing SSOT + déferral encaissement = **CORRECT**.
0 erreur console, 0 4xx/5xx. Reste à prouver : encaissement→PAID+fiscal (W5 suite) + KDS.
