# GOAL — FIDÉLITÉ UNIFIÉE & SYNCHRONISÉE sur tous les systèmes

**Date:** 2026-06-11 · **Statut:** ⏸️ PLAN-ONLY (attend `lance le GOAL` + décision D11) · **Auteur:** Claude superviseur
**Fondation (tout prouvé, rien supposé):** test-e2e fidélité A→Z `reports/test-e2e/loyalty-global-2026-06-10/CONVERGENCE_LOYALTY.md` — backend sain (earn réel +15 pts, redeem POS idempotent 409, clawback, 79/0) MAIS barème divergent (10 pts/€ backend vs 1 pt/€ clients), min 50 vs 100, +25 non backé, QR mobile rejeté caisse, **et AUCUN event fidélité dans le bus outbox** (vérifié : 0 fichier `app/Events/*Loyalty*`, 0 listener outbox) — le solde ne se synchronise jamais en temps réel entre surfaces.

## §0 — CONTRATS
NF525 intact (les points ne touchent jamais le prix SSOT ; la remise redeem POS passe déjà par le chemin LOCKé existant) · bus sync = SHARED ZONE → tout nouvel event suit le pattern outbox EXACT (`SYNC_CONTRACT.md §3bis`, plain event + `Persist*ToOutbox` + `domain_events`, jamais ShouldBroadcast) avec doc §3 mise à jour · clients standalone V1 (la « sync » clients = parité de barème + préparation wireup, pas de câblage) · idempotence partout (acquis : kiosk redeem + POS redeem ; à étendre aux nouveaux chemins).

## §1 — WAVE L1 : BARÈME UNIQUE (dépend D11 — défaut recommandé : 1 pt/€ partout)
- T-L1.1 **Seed explicite** `loyalty_setup` (settings group) avec le chiffre D11 + migration/commande `foodking:set-loyalty-rates {per_euro} {per_discount} {min}` — la valeur vit en DB, plus dans 3 fallbacks.
  • anchors: `LoyaltySetupResource.php:20-22` (?? 10), `Frontend/LoyaltyController.php:475` (get(...,10)), `AwardLoyaltyPointsOnDelivery.php:84` (get(...,10))
  • acceptance: `(À CRÉER tests/Feature/Loyalty/LoyaltyRateSsotTest.php)` — les 3 chemins lisent la MÊME valeur seedée ; fallback ≠ silencieux (warning log si setting absent)
- T-L1.2 **Aligner les 3 fallbacks** sur le chiffre D11 (défense en profondeur si le setting manque) + arrondi unifié : décider floor vs round UNE fois (clients = round ; backend = floor → aligner backend sur round OU l'inverse, 1 ligne par côté, pinné par test).
  • acceptance: `LoyaltyRateSsotTest` + test d'arrondi croisé (0,90 € → même résultat partout)
- T-L1.3 **Min redeem unifié** (50 vs 100 → valeur D11) : backend `min_redeem_points` + clients `mobile/data/loyalty.js` + `/Downloads/web/data/loyalty.js` (drift documenté loyalty.js:56).
  • acceptance: parité node test étendu (les 2 repos) + `PosLoyaltyRedeemTest` re-vert
- T-L1.4 **+25 pts inscription** : trancher (D11 note) — backé (event registered → award, idempotent) OU retiré du wording clients. Pas de promesse fantôme.

## §2 — WAVE L2 : SYNC TEMPS RÉEL DU SOLDE (le manque structurel)
Constat : POS redeem modal, kiosk loyalty, balance API — chacun refetch à l'ouverture ; un earn/redeem/clawback n'est JAMAIS poussé. Une caissière avec le modal ouvert voit un solde périmé.
- T-L2.1 **Event `LoyaltyBalanceChanged`** (plain event pattern outbox : user_id, branch_id, balance_after, delta, reason earn|redeem|clawback|refund) + `PersistLoyaltyBalanceChangedToOutbox` → `private-branch.{id}` (+ canal user si pertinent pour le tracker client V2). Dispatché par les 4 écritures : `AwardLoyaltyPointsOnDelivery`, redeem POS (`PosLoyaltyController`), redeem kiosk/frontend (`LoyaltyController::redeem`), clawback/refund (`LoyaltyService:21,141`).
  • ⚠️ bus = SHARED ZONE → LOCK doc léger + mise à jour `SYNC_CONTRACT.md §3bis` (tableau events) dans le même commit
  • acceptance: `(À CRÉER tests/Feature/Loyalty/LoyaltyBalanceChangedOutboxTest.php)` — 4 chemins → 1 row domain_events chacun, payload exact, idempotence préservée (replay redeem ≠ 2e event)
- T-L2.2 **Consommateur POS** : `PosLoyaltyRedeemModal.vue` (+ PosComponent) s'abonne via le service Echo existant → solde live dans le modal (pattern `posItemAvailabilityHandler`).
  • acceptance: `(À CRÉER tests/js/posLoyaltyLiveBalance.spec.js)` mount-level + e2e :8767 — redeem dans un onglet → modal de l'autre se met à jour sans reload (preuve capture)
- T-L2.3 **Consommateur kiosk** : `KioskLoyaltyComponent` refetch on-event (léger, pas de re-render lourd) ; dégradation = polling existant (aucune perte, latence seulement — invariant §7 SYNC_CONTRACT).
- T-L2.4 **Histo/projection** : `loyalty_transactions.balance_after` déjà écrit (prouvé tx#10/11) — vérifier l'ordre concurrent (2 earns simultanés → balance_after cohérent, lock existant ? sinon `lockForUpdate` sur la ligne user).
  • acceptance: `(À CRÉER tests/Feature/Loyalty/ConcurrentBalanceConsistencyTest.php)` 2 awards parallèles → soldes séquentiels sans écrasement

## §3 — WAVE L3 : PONT CLIENTS (préparation wireup D12, toujours 0 câblage V1)
- T-L3.1 **QR signé** : la route `POST /loyalty/qr` existe (mint signé) — le mobile fallback plaintext `FK:` (LoyaltyQR.jsx:51) reste affiché mais marqué « DÉMO » explicite + le doc wireup V2 (`docs/WIREUP_V2_CLIENTS_MAPPING.md §4`) gagne la séquence exacte mint→scan caisse (HMAC secret config/loyalty.php:24).
- T-L3.2 **Codes récompense LCY** : décision D12 — si « wireup V2 » : spec table `loyalty_rewards` (id, points_cost, type, label) + endpoint validate/burn idempotent, DOC ONLY V1 ; si « abandon » : retirer le générateur LCY mobile.
- T-L3.3 **Sentinel de parité cross-produit permanent** : `(À CRÉER tests/Feature/Sentinels/LoyaltyRateParitySentinelTest.php)` — lit le setting backend + parse `mobile/data/loyalty.js` + `/Downloads/web/data/loyalty.js` (regex config) → FAIL si les 3 barèmes divergent. Le drift 10-vs-1 ne pourra plus jamais revenir silencieusement.

## §4 — VALIDATION (la triade prouvée)
Par wave : PHPUnit filtré + suites Loyalty complètes (baseline 79) · e2e :8767/foodking_e2e re-parcours A→Z (earn→balance→redeem POS→clawback) + captures · clients :8096/:8097 parité visuelle · **adversaire par wave** (états authentifiés, replay idempotence, course concurrente) · 2 cycles propres identiques. Frozen diff 0 (rien de ce plan ne touche §7 — le redeem POS UI LOCKé n'est pas modifié, seulement abonné).

## §G — GATES
| Gate | Quoi | Statut |
|---|---|---|
| **D11** (owner, PRÉALABLE Wave L1) | barème earn 1 vs 10 pts/€ + arrondi + min 50/100 + sort du +25 | PENDING — page décisions |
| D12 (owner, Wave L3) | scope QR/récompenses wireup | PENDING |
| LOCK-SYNC-LOYALTY (process) | nouvel event sur le bus partagé | à émettre en T-L2.1 (doc léger, pattern §3bis) |

## §F — DONE
Un seul barème vivant en DB lu par 100% des chemins (sentinel permanent) ; chaque mouvement de points émet un event outbox consommé live par la caisse (prouvé 2-onglets) et la borne ; arrondis et minimums identiques partout ; QR/récompenses tranchés et documentés pour V2 ; suites Loyalty ≥79 vertes + nouveaux tests ; adversaire épuisé ×2 cycles. **PLAN-ONLY — attend `lance le GOAL` (et D11).**
