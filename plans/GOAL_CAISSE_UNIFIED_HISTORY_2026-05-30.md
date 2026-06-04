# GOAL — Caisse unifiée + Prépa-avant-encaissement + Historique (V1 LOCAL Le Cayenne)
**Date** 2026-05-30 · branche `heal/cms-pr1-quickwins-2026-05-18` · orchestrateur = Claude (cerveau)
Source : Wave 1 analyse profonde (`reports/.../wyimfvebx` — 5 systèmes × analyse + cross-check).

## §0 — La carte (état actuel, vérifié file:line)
- **CAISSE** : 2 surfaces de paiement aujourd'hui. (1) **Comptoir/walk-in** = paie EN LIGNE à la vente via le FROZEN `PaymentComponent.vue` (order créé déjà PAID). (2) **Borne** = Plan B (`kiosk.payment_route_all_to_counter=true` par défaut) → order créé `PENDING_COUNTER`, fiscal_seq NULL ; encaissé plus tard via `PosCounterCollectModal.vue` (**NON-frozen**) → `PaymentService::confirmCounterPayment` (alloue fiscal-seq à l'encaissement, supporte **cash+carte+mobile+ticket**).
- **D1 (préparer avant encaisser)** : **DÉJÀ LIVE pour la borne** (kiosk auto-ACCEPT → arrive au KDS, cuisine prépare avant paiement ; badge "non encaissé" existe déjà). Le "gate pay-before-kitchen" (`isReleasedToKitchen`) est **DEAD CODE** (jamais appelé runtime). **Résiduel** : `KdsOrderCard.vue` SUPPRIME le bouton bump pour un order non-payé → le chef ne peut pas avancer ACCEPT→PREPARING→PREPARED. (Le serveur NE bloque PAS — le commentaire prétendant le contraire est FAUX.)
- **D2 (encaissement unifié)** : `PosCounterCollectModal` (non-frozen) + `confirmCounterPayment` (service non-frozen) font déjà cash+carte. MAIS il y a **2 UIs séparées** (walk-in inline frozen vs borne collect) → pas de page unique. `CashOverview` agrège déjà tout en lecture (badge caisse/borne) mais est read-only + tronqué (MAX_ROWS).
- **D3 (dashboard)** : aucune entrée d'encaissement ACTIONNABLE depuis le dashboard (que des vues read-only).
- **HISTORIQUE** : **aucune page unique ne montre TOUT**. Bugs réels : sales-report **sur-évalue le CA** (somme `total` SANS filtre payment_status → inclut non-payés/PENDING_COUNTER → diverge de cash-overview + Z) ; borne mal-étiquetée **"WEB"** (SOURCE_KIOSK réutilise WEB=5, pas de badge Borne/Caisse) ; les listes n'affichent pas fiscal_seq ni parent_order_id (refunds).

## §1 — ✅ Confirmation cruciale : ZÉRO fichier frozen à toucher · NF525-safe
Tout (D1+D2+D3+historique) se réalise sur la **couche non-frozen** : `KdsOrderCard.vue`, `KitchenDisplaySystemComponent.vue`, `PosOrdersTrackerComponent.vue`, `PosComponent.vue`, `PosCounterCollectModal.vue`, `CashOverviewComponent.vue`, les closures `routes/api.php`, `FrontendOrderService`, une nouvelle page `/admin/encaissement`, une nouvelle page `/admin/historique`, `SimpleOrderResource`. **NF525** : fiscal-seq reste alloué UNIQUEMENT à l'encaissement (`confirmCounterPayment`), le ticket cuisine n'est pas un doc fiscal ; aucune 2e site d'allocation. `PaymentComponent.vue`/`pos-wizard.js`/`FiscalSequenceService`/`ZReportService` **intacts**.

## §2 — ⚠️ LA décision owner (le seul vrai fork — D2 walk-in)
Pour « UNE seule page d'encaissement, tout le monde via la caisse » : la borne y est déjà. Le walk-in paie INLINE (wizard frozen). Deux modèles :
- **(A) Walk-in garde le paiement inline rapide** (wizard frozen intact) **+** une **page unifiée** `/admin/encaissement` qui montre+encaisse les commandes borne à collecter **+** une **page Historique unifiée** qui montre TOUT (borne+comptoir, payé+non-payé, badge origine). → « voir/gérer tout d'un seul endroit » SANS changer le flux caissier rapide. **0 frozen, 0 risque NF525, le plus léger.**
- **(B) Walk-in passe AUSSI par create-then-collect** : la commande comptoir est créée `PENDING_COUNTER` puis encaissée dans la MÊME file unifiée que la borne (via le modal non-frozen, pas le wizard frozen). → vraiment UNE seule file/page d'encaissement pour tous, badge origine. Mais **change le flux caissier** (construire → encaisser au lieu de payer inline) et **déprécie de facto le paiement inline du wizard frozen** (que tu avais figé comme "parfait"). Non-frozen + NF525-safe (fiscal à l'encaissement) mais c'est un vrai changement de workflow.

## §3 — Décomposition (commune aux 2 modèles, + le delta du fork)
**COMMUN (quel que soit A/B) :**
- **W-D1** (KDS prépa-avant-pay) : `KdsOrderCard.vue` — afficher le bump CTA ET la note "Non encaissé / paiement en attente" (arrêter de masquer le CTA). Auditer KdsV2Grid/KitchenDisplaySystemComponent pour le même pattern. *Test* : chef bump un order PENDING_COUNTER → ACCEPT→PREPARING OK + note visible.
- **W-HIST** (historique — priorité owner) : nouvelle page read-only `/admin/historique` + endpoint `OrderService::list(source=null)` + résolveur origine (deriveSource) → badge Borne/Caisse/Livreur ; colonnes fiscal_seq, parent_order_id (refund), payment_status, timeline (order_status_transitions) ; filtres date/origine/statut/paiement ; export. *Fix bug* **H-03** : `salesReportOverview` (OrderService:2680) filtrer payment_status=PAID → CA cohérent avec cash-overview + Z. *Fix* **H-02** : badge origine au lieu de "WEB".
- **W-ENC** (page encaissement unifiée + dashboard) : nouvelle page `/admin/encaissement` listant les commandes à encaisser (borne PENDING_COUNTER) avec actions cash/carte via `confirmCounterPayment` + badge origine ; lien actionnable depuis le dashboard (D3). *Test* : encaisser une commande borne cash + carte → fiscal-seq alloué, PAID, sync KDS/OSS.
**DELTA si (B)** : router le walk-in en `PENDING_COUNTER` (FrontendOrderService/PosController) → il apparaît dans `/admin/encaissement` → encaissé via le modal. Étendre `counter-collect/pending` pour inclure le walk-in. (Si (A) : le walk-in reste inline, juste affiché dans l'historique.)

## §4 — Waves d'exécution
1. **W-D1** (KdsCard bump+note) — non-frozen, ~petit. 2. **W-HIST** (historique + H-03/H-02 fixes) — la priorité owner. 3. **W-ENC** (page encaissement unifiée + dashboard). 4. **(B)-delta** si choisi. 5. **E2E** GStack+Superpowers+Adversarial + captures analysées + sync verify. 6. Convergence (gates verts, fiscal gap-free, 0 frozen) + livre.

## §5 — Owner-gates
- **Fork A/B** (§2) = la seule décision bloquante. (Aucune touche frozen quelle que soit la réponse.)
- Si (B) déprécie le paiement inline du wizard frozen → confirmation owner explicite (ça touche le mandat "wizard parfait figé", même sans éditer le fichier).
