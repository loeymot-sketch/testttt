# ULTRAPLAN — Encaissement caisse+borne (cash/carte/TR) → confirmation pré-cloud
**V1 LOCAL Le Cayenne — 2026-06-03 · branch `heal/cms-pr1-quickwins-2026-05-18`**

Owner goal: massive E2E de l'encaissement (espèces/carte/TR, commandes caisse ET borne),
20×/cas, captures Playwright à chaque étape, **confirmation "sans faute en local AVANT le cloud"**.
Method: 12-agent read-only decomposition (`wf_a9235cd9-fa2`) + orchestrator verify (§3ter).
Raw: `decomposition-raw.json`.

## ⛔ VERDICT PRÉ-CLOUD : **NO-GO (pas encore) — l'encaissement n'est PAS "sans faute".**
Le chemin **caisse↔borne est solide**, mais **carte + TR sont des stubs (simulation)**, **espèces a
des trous de persistance NF525**, et le **Z-report stocke des clés numériques** dans le rapport fiscal
immuable. Ce sont exactement les fautes à corriger AVANT le cloud — ta porte de validation a bien
fonctionné. Rien de tout ça ne bloque l'usage *quotidien espèces* d'aujourd'hui ; ça bloque le claim
"complet/sans faute + carte+TR fonctionnels".

---

## 1. État réel par méthode (vérifié sur le code)

| Sujet | État | Détail | file:line |
|---|---|---|---|
| **Caisse↔Borne liaison/sync** | ✅ **works** | les 2 sources convergent sur le MÊME chemin scellé (PENDING_COUNTER+COUNTER_DEFERRED→`confirmCounterPayment`→séquence fiscale→PAID), race-protégé (409), idempotent. Tracker "À encaisser" affiche les 2. | `PaymentService:193-450`, `api.php:800-837` |
| **Espèces + rendu monnaie** | ⚠️ **partial** | rendu calculé/affiché ✓ (prouvé live A0014), MAIS gaps ↓ | `PosCounterCollectModal:264-268`, `PaymentService:315-329,442` |
| **Carte (alternative TPE manuel)** | 🔴 **stub/simulation** | 1 tap → "TPE validé (simulation)", **aucune saisie réf TPE/montant tapé, aucun OrderPayment créé** | `Modal:146-153`, `PaymentService:376-387` |
| **Ticket Restaurant** | 🔴 **stub** | 1 tap → PAID, **aucune saisie nb tickets/valeur, aucun split, 0 conformité FR** (25€/j, dénominations, CONECS) | `Modal:146-153`, `PaymentService:326-330` |
| **DB / Z-report / gestion** | ⚠️ **partial** | voir §3 — clés Z numériques (P1), CashMovement sans payment_method (P1), carte/TR n'écrivent pas CashMovement (P1) | `ZReportService:666`, `CashMovement` migration |
| **Cloud-readiness** | ⚠️ **partial** | 14 boot-guards en place ; cutover = checklist .env/config (§4) | `AppServiceProvider:158-453` |

---

## 2. Travail à construire AVANT le test massif (carte/TR/espèces) — priorisé

> Le test "20×/cas" n'a de sens qu'APRÈS avoir rendu carte+TR fonctionnels. Sinon on teste des stubs.

- **P-CARD (P1, ~80 LOC, non-frozen)** — alternative SumUp manuelle :
  ajouter au modal une sous-section CARTE = champ **réf TPE** (last-4 / ID terminal) + **montant tapé**
  (numpad) + bouton "✓ J'ai tapé sur le TPE" ; backend `confirmCounterPayment` mode=CARD → **créer
  `OrderPayment`** (reference, tendered), valider `montant ≥ total`, **enregistrer CashMovement**
  (pour le Z), retirer le suffixe "(simulation)" du toast. Pas de migration (colonnes OrderPayment
  existent). `PaymentService` = sensible (fiscal-adjacent) → **owner gate**.
- **P-TR (P1)** — Ticket Restaurant fonctionnel :
  sous-section TR = **nb tickets × valeur/ticket** (5/8/10€) → montant TR ; si **montant TR < total →
  split : complément en espèces** (TR ne rend pas la monnaie, plafond 25€/j) ; enregistrer la
  ventilation. Conformité **CONECS/DGFIP = gate cloud** (exemption ou bridge).
- **P-CASH (P1)** — combler les trous NF525 :
  **persister le rendu** (`pos_change_amount` ou CashMovement OUT), rendre la **session caisse
  bloquante** (ou afficher un badge "caisse ouverte/fermée" + `GET /admin/pos/cash-session/status`
  avant confirm), aligner précision `pos_received_amount (19,6)→(10,2)`.
- **P-DB (P1)** — `CashMovement` + **colonne `payment_method`** ; carte/TR **écrivent un CashMovement** ;
  test Z mixte (20 cash + 20 carte + 20 TR → ventilation correcte).
- **P-ZREPORT (P1, FROZEN §7 → owner gate + LOCK)** — `ZReportService:666` : stocker des **clés
  lisibles** (`counter_cash`/`counter_card`/`counter_ticket_restaurant`) au lieu de `'1'/'2'/'5'` dans
  le `total_by_method` signé. Forward-only (anciens Z immuables). Totaux déjà corrects + chaîne valide.
- **P-CONFIG (P1)** — **`POS_WALKIN_ROUTE_TO_COUNTER=true`** dans `.env` (sinon les commandes CAISSE
  walk-in paient inline, pas en différé comptoir).

---

## 3. Confirmation DB + gestion (ce que le test massif doit vérifier)
Après N encaissements par méthode : `orders.pos_payment_method` correct · `OrderPayment` créé
(carte/TR aussi) · `CashMovement` par méthode (cash-trail NF525) · **Z `total_by_method`** ventile
cash/carte/TR avec clés lisibles + sommes exactes · `cash-overview` réconcilie · chaîne fiscale OK.

## 4. Checklist cutover CLOUD (la porte "go-prod" — surtout .env/config, peu de code)
- 🔴 **Secrets fiscaux** `FISCAL_AUDIT_SECRET`/`FISCAL_Z_REPORT_SECRET` → 32+ car. aléatoires (aujourd'hui = padding e2e). Rétention 6 ans.
- 🔴 **Redis** = cache partagé OBLIGATOIRE en multi-pod/ALB (`Cache::lock(audit_chain)` doit sérialiser entre pods). Local file/database = OK single-box seulement (UNI-03).
- 🔴 **`POS_SIMULATION_HARDWARE=false`** + bypass off en prod (boot-guard refuse le boot sinon).
- 🟠 `APP_URL=https://<domaine>` (CORS + broadcasting/auth) · `BROADCAST_DRIVER=pusher` (soketi) · `SESSION_DRIVER` redis ou sticky-session (ALB) · `QUEUE_CONNECTION=redis` + worker dédié (`block_for=5` déjà OK).
- 🟠 **Triggers MySQL BEFORE DELETE** (immutabilité NF525) préservés sur RDS (`mysqldump --triggers`).
- Les 14 boot-guards (`AppServiceProvider:158-453`) attrapent les misconfigs fail-fast.

## 5. Matrice de test MASSIVE (après le code §2) — Playwright MCP, capture à chaque étape
`{Espèces, Carte, Ticket Resto} × {Commande Caisse, Commande Borne} × N(≈20)` :
prise de commande (capture) → board "À encaisser" (capture) → modal méthode (capture: rendu monnaie /
réf TPE / nb tickets) → confirm → PAID (capture) → ticket (capture) → vérif DB+Z. Edge: split TR+cash,
carte sous-montant (422), double-tap (409), caisse fermée.

## 6. PRÉCONDITION d'exécution (sinon résultats non fiables)
**Stabiliser l'environnement** : arrêter le batch `abuse-e2e` + **purger les 1254 commandes
PENDING_COUNTER de pollution** (compte `pos@lecayenne.fr` contendu = sessions qui tombent ; DB
revertée plusieurs fois cette session). Sans env stable + DB propre, un test "20×/cas sans faute"
n'est pas fiable (vécu toute la session). → **action owner : me laisser l'env exclusif** (stop batch +
purge soak), puis je déroule §2 (build) → §5 (massive) → §3+§4 (confirm) → verdict GO/NO-GO ferme.

---
_Décomposition vérifiée. 0 frozen-zone modifié (lecture seule). No push. Owner gates: P-CARD/P-TR
(PaymentService), P-ZREPORT (frozen), CONECS/DGFIP (TR cloud), + stabilisation env pour l'exécution._
