# Audit adversaire — LOGIQUE MÉTIER tiroir-caisse / réconciliation

Date : 2026-07-11 · Mode : lecture seule + tinker (chiffres DB réels, 0 mutation)
Backend : testttt V1 LOCAL · DB Europe/Paris (stockage local Paris, pas de conversion UTC)

## Périmètre & méthode
- Fichiers audités : `app/Services/Cash/CashDrawerService.php`, `app/Services/Fiscal/ZReportCashEnrichmentService.php`,
  `app/Http/Controllers/Admin/CashOverviewController.php`, `app/Http/Controllers/Admin/CashSessionReportController.php`,
  modèles `CashDrawerSession` + `CashMovement`, call-sites `PaymentService`, `SplitPaymentService`, `CashDrawerController`.
- Chaque hypothèse re-calculée sur données DB réelles (34 sessions, 400 mouvements order_payment, transactions 07-01/07-02).

---

## ✅ INVARIANTS PROUVÉS (tiennent sur données réelles)

1. **Math réconciliation `expected = opening + Σ signed(movements)` et `variance = closing − expected`**
   — vérifié re-calcul brut sur sessions 17,18,20,21,22,30,31 : stored == recomputed à 0,00 € près.
   Ex. session 22 : opening 50 + (order_payment IN 17,30 − cashback OUT 8,40 = +8,90) = 58,90 (stored 58,90) ; variance 59,40−58,90 = **+0,50** (stored 0,50). Signe correct (+ = excédent, − = manquant).
   Ex. session 30 : 70 + 75,90 = 145,90 ; variance 10−145,90 = **−135,90** (stored). ✔
2. **`expected_cash` de la Vue Caisse Unifiée = même source que `reconcileSession`** (opening + Σ signed movements de la session). Aucune fuite d'autres sessions (fix CASH-JOIN-01 tient). ✔
3. **Grand Total == Σ by_source == Σ by_mode** — reconstruit sur 07-01 (71,80 = borne 65,80 + caisse 6,00) et 07-02 (61,70 = caisse 38,80 + borne 22,90). `summarize()` fait un seul passage, `deriveSource` range chaque tx dans **un seul** bucket → **pas de double-comptage** inter-source. ✔
4. **Pas de pollution carte/livreur du tiroir** : 0 mouvement `order_payment` sur 394 rattaché à une commande `delivery_boy_id` ; recordCashOrderMovement n'est déclenché que si `mode===CASH`. `drawer_open` = amount 0 (pas de double-compte du fond). ✔
5. **I1** (1 seule session OPEN par (branch,user)) respecté : 11 sessions OPEN branch 1 = 11 users distincts. ✔
6. **Détecteur `unrecorded_cash` fonctionnel** : trouve 5 cmd (10 €) le 06-12 (cash-tx sans cash_movement), 0 faux-positif les jours à mouvements. ✔

---

## 🔴 FINDINGS

### P1 — Z cash enrichment DROPPE les sessions closed-non-reconciled (+ asymétrie livreur)
`ZReportCashEnrichmentService::aggregateForWindow` L64-66 filtre **`status = RECONCILED` uniquement**.
Une session simplement `closed` (closing_amount déclaré, reconcile pas encore fait) est **exclue** des agrégats cash du Z.
- Repro réelle, fenêtre 2026-06-13..06-20 branch 1 : `aggregateForWindow` → opening 70 / closing 10 / variance −135,90 (session 30 seule). Sessions **19 (closing 75,80) + 31 (50,00) closed-non-reconciled dans la MÊME fenêtre = 125,80 € invisibles** dans `cash_closing_amount`.
- **Asymétrie** : le pendant livreur `enrichClose` L339-342 inclut `[CLOSED, RECONCILED]`. Deux moitiés du même Z n'ont pas la même règle → sous-compte du cash caisse si le reconcile prend du retard, alors que le livreur ne sous-compte pas.
- Impact : colonnes cash observabilité du Z (hors signature HMAC — chaîne NF525 non corrompue) mais **vue de réconciliation du Z fausse** (owner « détecter écarts »).
- Fix : inclure `STATUS_CLOSED` OU auto-reconcile-on-close, ET aligner la règle sur `enrichClose`. Note : le mélange closed(expected/var NULL)+reconciled casse l'égalité Σclosing−Σopening−Σmov=Σvariance ; préférer forcer reconcile avant close du Z.

### P2 — `cash_collected` mal labellisé « Espèces encaissées aujourd'hui »
`CashOverviewController` L246-247 → `cash_collected = round($movementsSum,2)` = **net signé (IN − OUT)**, affiché sous `label.cash_collected_today` = « Espèces encaissées aujourd'hui » (fr.json:1159), CashOverviewComponent.vue L144-148.
- Double tromperie : (a) **net vs brut** — session 20 : encaissé brut 379,46 mais affiché 339,46 (40 € cashback déduits) ; (b) **« aujourd'hui » faux** — c'est « depuis ouverture du tiroir », qui peut couvrir 24 j (session 20 ouverte 06-10→07-04). `expected_cash` reste correct ; seul le libellé induit en erreur.
- Fix : renommer en « Mouvement net depuis ouverture » ou séparer brut encaissé / cashback / net.

### P2 — Carte réconciliation admin n'affiche qu'UN tiroir sur N ouverts
`CashOverviewController::resolveOpenCashSession` L397-421 : admin sans `branch_id` → **la session la plus récemment ouverte** (une seule).
- Repro : session 38 affichée → `expected_cash = 58,50`. **10 autres tiroirs OPEN branch 1 ignorés**. Vrai cash attendu tous tiroirs branch 1 = **1275,05 €** (sous-estimation ×22).
- Cause racine : tiroirs jamais clôturés (11 sessions OPEN zombies depuis 06-12). Hors-enveloppe V1 mono-caissier (par design 1 tiroir), mais dangereux dès qu'il y a plusieurs sessions OPEN, ce qui est l'état DB actuel.
- Fix : agréger TOUS les tiroirs OPEN de la branche (Σ expected) ou lister par tiroir + garde anti-zombie (alerte session ouverte > X h).

### P2 — Grand Total exclut silencieusement les transactions dont la commande n'existe plus
`CashOverviewController` L136 `whereHas('order')` (requis pour l'isolation branch, Transaction n'a pas de BranchScope) + Order utilise **SoftDeletes** (`app/Models/Order.php:17`).
- Repro : 07-01, 15 tx `payment` bruts = 101,20 € mais 6 (29,40 €) référencent des commandes **purgées** (5374-5383) → le contrôleur réel n'en renvoie que 9 = **71,80 €**. Sur tout l'historique : **11 tx / 69,90 € hard-gone droppés**. 0 soft-deleted aujourd'hui, mais **latent** : une commande cash **soft-deleted** (annulation/void sans remboursement) retirerait son encaissement de la vue alors que le cash reste physiquement au tiroir → écart caché.
- Fix : compter les tx à commande absente dans un bucket « non rattachées » (comme `unrecorded_cash`) plutôt que les faire disparaître du total.

### P3 — Doc/commentaires désynchronisés (pas de bug de calcul)
- `config/cash.php` L22-24 dit « max 500 chars » mais le code applique **255** (`variance_reason_max_length`).
- `ZReportCashEnrichmentService` docblock L37 : `cash_opening_amount = Σ sessions ouvertes dans la fenêtre` alors que le code L64-73 somme les sessions **reconciled fermées** dans la fenêtre (comportement code = correct pour le net ; doc obsolète).
- `CashOverviewController` L220-226 : commentaire dit `cash_collected` « restricted to drawer.branch_id + current day window » alors que le code ne filtre que par `cash_drawer_session_id` (code = correct).

---

## Chiffres clés (DB réelle)
- Sessions : 34 (11 OPEN branch 1, toutes users distincts). Mouvements order_payment : 400.
- Session 30 var −135,90 € reconciled (grosse variance passée le gate override). Session 20 : 72 mouvements sur 24 j, var 0.
- 245/400 mouvements order_payment (2035,05 €) sans Transaction correspondante (données de test historiques — divergence structurelle cash_movements vs transactions, non surfacée dans ce sens).
