# Audit adversaire du DIFF de session — `5367ae350~1..HEAD` (HEAD `9312404c5`)

Date : 2026-07-11 · Auditeur : sub-agent adversaire de CODE (lecture seule + tests)
Périmètre : 12 fichiers de code. Verdict : **0 défaut réel (P0/P1/P2). Diff SAIN.**

Frozen zones (§7) : aucun des 12 fichiers n'est frozen (OrderService lu en référence, non modifié ;
c'est **OrderQuoteService** qui est touché, hors liste frozen). NF525 (§8) : le pricing reste 100 %
SSOT — aucune modif du moteur de prix ni des données scellées.

Preuve tests (21/21 verts) :
- `tests/Feature/Pos/PosFreeDeliveryQuoteSealTest` — 1 PASS (quote↔seal parity DELIVERY ≥ seuil)
- `tests/Feature/Order/CancelReasonEnforceTest` — 7 PASS (kiosk vs admin vs session)
- `tests/Feature/Hardware/BorneCaisseWidthDecoupledTest` — 7 PASS (largeur + codepage €)
- `tests/Feature/Outbox/HealthQueueWorkerContractViolationTest` — 6 PASS (recency-floor)

---

## Fichier par fichier

### 1. HealthController.php — plancher récence 24h (`created_at >= now()->subDay()`)
**Sain.** Un outage COURT actuel génère des `domain_events` récents (< 24h) → toujours comptés
→ 503 déclenché. Le floor n'exclut que les orphelins > 24h (résidus d'incident passé, gérés par
outbox:rescue/prune). Test « recent backlog counts even with ancient orphans present » + « genuinely
pending rows still trip worker lag » PASS. Faux négatif seulement si outage > 24h SANS nouvelle
commande — cas où le worker n'est de toute façon pas sollicité. Pas de régression.

### 2. OrderStatusRequest.php — actorIsKioskMachine
**Sain.** Nouvelle logique : exige `currentAccessToken() instanceof PersonalAccessToken` +
`abilities` contient `kiosk:order` ET PAS `*`.
- Vrai kiosk (`['kiosk:order']`, §9) → TRUE (motif enum whitelist). Test PASS.
- Admin SPA (TransientToken, `can()`=true partout) → FALSE → free-text. **C'est le fix.** Test PASS.
- Admin API `['*']` → FALSE (wildcard exclu) → free-text. Correct.
- Session web (token null) → FALSE. Correct.
Aucun faux kiosk possible sans PersonalAccessToken scoppé kiosk:order (flux légitime).
Note non-bloquante (hors diff) : `authorize()` L31 utilise encore `tokenCan('kiosk:order')` — mais
mitigé par le check de rôle L25 + ownership couche service. Non introduit par ce diff.

### 3. RouteServiceProvider + routes/api.php + config/kiosk.php — bucket kiosk-quote
**Sain, pas de bypass.** La création de commande garde `throttle:kiosk-orders`
(`config kiosk.order_rate_limit`=30/min). Le quote (lecture seule, calcul prix) a son bucket
`kiosk-quote` (120/min) keyé `kioskq:userId|ip`. Séparer ne réduit AUCUNE protection de mutation :
l'ordre reste borné. Quote authentifié (resolveActor 401 sinon). Cosmétique : littéral fallback
`5` subsiste à RouteServiceProvider:64 mais la valeur config (30) l'emporte.

### 4. OrderQuoteService.php — livraison offerte ≥ seuil dans le quote (risque 409 NF525)
**Sain — miroir EXACT de OrderService:860-878.** Même source seuil
(`Settings::group('delivery')->get('free_delivery_above',30)`), même garde
(`order_type==DELIVERY && freeAbove>0 && accumulatedSubtotal>=freeAbove && deliveryCharge>0`),
même recalcul `forPos(...,0.0)`. `accumulatedSubtotal` ne dépend pas de delivery_charge → seuil
cohérent des 2 côtés. `sealForCommit` (OQS:122) compare `quote.total_ttc` (=`pricing->total`, hors
frais quand offert) à `order.total` (OrderService:1099/1116, aussi hors frais) → MATCH, pas de 409.
Prouvé e2e par PosFreeDeliveryQuoteSealTest (commit réel PASS). Sous-total 100 % SSOT → pas de
contournement client.

### 5. OrderStatusScreenOrderService + Controller + PreparingAndReadyComponent.vue — garde zombie
**Sain.** Filtre backend `queue_number!='' OR token!=''` sur `list()` ET `listForBranch()` (miroir).
Requête DB de vérité : sur 3203 commandes, seulement **2** en PREPARING/PREPARED matchent la garde —
id=4829 (WV2N944110) et id=5399 (CARDTEST-…), toutes deux type=25 KIOSK = résidus de test exacts
visés. **Aucune commande légitime masquée.** Garde `branchId<=0` : uniquement dans `publicIndex`
(mur PUBLIC → collection vide si aucune branche active) ; `index()` admin inchangé (pas de
régression de visibilité admin). Filtre Vue = défense en profondeur cohérente.
Risque futur théorique (P3, non reproduit) : une commande légitime sans queue_number NI token serait
masquée — mitigé par l'allocation queue standard ; données actuelles = 0 cas légitime.

### 6. EscPosTicketBytesService + OrderReceiptEscPosRenderer + config/printing.php — largeur/codepage €
**Sain.** Fallback borne : `borne_width_chars ?: width_chars(caisse=42) ?: printer ?: 48`.
`euro_as_text` : borne sans `borne_code_page` → suffixe « EUR » (ASCII) au lieu de « € » (évite « ⌐ »).
Vérifié : TOUS les montants passent par `money()` (→ `$this->moneySuffix`) ; aucun « € » codé en dur
dans les chemins de sortie (les occurrences restantes sont des commentaires ou le texte ASCII « euros »).
Pas de fuite d'état : renderer NON singleton (résolution transitoire), `moneySuffix` réinitialisé au
début de CHAQUE `renderClientTicket` (L52), et `renderKitchenTicket` n'appelle jamais `money()`.
« EUR » vs « € » = simple rendu papier, données fiscales scellées inchangées → pas de violation NF525.
7 tests PASS (largeur découplée + codepage borne isolé de la caisse).

---

## Conclusion
**0 défaut réel introduit par ce diff.** Les 4 nouveaux tests (21 cas) couvrent chaque heal et
passent. Aucun frozen touché, invariants NF525 préservés, aucun bypass de sécurité, parité
quote↔order scellée prouvée. Observations P3 (non reproduites, non bloquantes) documentées ci-dessus.
