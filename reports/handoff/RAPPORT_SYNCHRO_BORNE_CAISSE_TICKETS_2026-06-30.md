# Rapport — Synchro BORNE ↔ CAISSE ↔ Tickets (client + cuisine) + KDS

**Date** : 2026-06-30
**Goal owner** : « max test e2e réel borne, tap-tap-tap, agents reverse, valider TOUS les
produits totalement au wizard. Pour la box ET la borne, on commence par la borne (le plus
important) puis la box, on vérifie TOUJOURS la synchro entre le Ticket et la box, ça doit
être PAREIL sur la borne et sur la box — c'est le ticket CLIENT et le ticket CUISINE, ça
doit être absolument comme demandé. »

**Méthode** : commandes réelles passées au wizard borne (résolution portrait 1080×1920,
Playwright headless `channel:chrome`) → rendu réel des tickets (ESC/POS décodé) →
inspection caisse/KDS → workflow adversarial 22 agents (5 axes → vérification → synthèse).

---

## 1. LA BORNE D'ABORD (le plus important) — ✅ VALIDÉE

- **301 commandes borne** passées (source=5), tous types de produits (Tacos M/L, Méga,
  Terminator, burgers, bols, menus enfant, desserts, boissons).
- **Multi-viandes** : Tacos L / Méga / Terminator portent bien **2 viandes distinctes**
  dans `composition_snapshot` (ex. Viande 1 = Mexicanos, Viande 2 = Cordon Bleu).
  Le fix « 2e viande perdue au panier » (`buildCartItem`, `Object.values().flat()`) est
  **déployé en production** et propagé jusqu'aux tickets.

## 2. SYNCHRO TICKET CLIENT ↔ TICKET CUISINE — ✅ IDENTIQUES

Les deux tickets sont rendus par le **même** `OrderReceiptEscPosRenderer`, à partir du
**même** `composition_snapshot` scellé → synchro garantie par construction. Vérifié sur
les commandes réelles :

| Produit | Ticket CUISINE (symbolique) | Ticket CLIENT | Les 2 viandes ? |
|---|---|---|---|
| Tacos L #5325 | `G \| TACOS \| L \| Mex Cordon \| MAY` | `Mexicanos, Cordon Bleu, Mayonnaise` | ✅ sur les 2 |
| Méga #5314 | `S \| MÉGA \| Mex Cordon \| STO \| MAY` | `Mexicanos, Cordon Bleu, …, Pain` | ✅ sur les 2 |
| Terminator #5315 | `S \| TERMINATOR \| Mex Cordon \| STO \| MAY` | `Mexicanos, Cordon Bleu, …` | ✅ sur les 2 |

Format cuisine = **exactement la spec owner** (support `G`/`S` | produit | taille |
viandes | crudités `STO` | sauce). Vérifié par les agents reverse :
- Ordre suppléments-avant-menu : **conforme** (réfuté comme bug).
- Indicateur menu sur la ligne 1 : **présent** (réfuté comme bug).
- Type d'oignon dans le symbole crudités : **distingué** (réfuté comme bug).

## 3. SYNCHRO ÉCRAN CUISINE (KDS) ↔ TICKET CUISINE — ✅ PARITÉ

- Le KDS (`kdsSymbolic.js`, JS) et le ticket cuisine (`KitchenTicketSymbolicFormatter`, PHP)
  produisent les **mêmes symboles** : parité 18/18 (Vitest) + symboles viande/sauce/crudités
  matchés PHP↔JS. Une commande 2-viandes affiche « Mex Cordon » sur l'écran comme sur le papier.

## 4. LA BOX (CAISSE) — ✅ SYNCHRONISÉE

- **File « à encaisser borne »** : les commandes borne Plan B (`source_surface=kiosk`,
  `payment_status=PENDING_COUNTER`, `pos_payment_method=COUNTER_DEFERRED`) remontent
  correctement dans la caisse (`kioskCashOrders`), repoll Echo + fallback polling.
- **Ticket caisse == ticket borne** : même renderer, même snapshot → octets identiques.
- **Fiscalisation NF525 au bon moment** : aucun N° fiscal à la création borne (pas encore
  une vente) ; `confirmCounterPayment` alloue le `fiscal_sequence_no` (monotone, gap-free,
  `Cache::lock` + `lockForUpdate`) **à l'encaissement**, écrase la méthode par le vrai mode,
  écrit Transaction + AuditLog. Garde-fous : anti-double-encaissement 409, refus
  CANCELED/REJECTED, monnaie insuffisante 422.

---

## 5. BUG TROUVÉ ET CORRIGÉ pendant cette passe — incohérence cross-surface paiement

**Symptôme** : une commande borne **avant encaissement** affichait sur le ticket client
**« PAIEMENT 6 : 0,00 € »** au lieu de **« À RÉGLER EN CAISSE »**. Pire : le reçu **écran/API**
affichait une ligne « méthode 6 = **total** » (ex. 7,90 €, comme si payée) alors que le flag
`payment_pending_counter` était vrai → **l'écran et le ticket papier se contredisaient.**

**Cause** : `pos_payment_method = COUNTER_DEFERRED (6)` = paiement différé au comptoir (le
flux V1 `payment_route_all_to_counter`). Les 3 renderers de paiement traitaient cette valeur
comme un vrai règlement et inventaient un montant (`received>0 ? received : total`).

**Fix (3 surfaces, miroir, NON-frozen, TDD red→green)** :

| Surface | Fichier | Garde |
|---|---|---|
| Ticket papier ESC/POS | `app/Services/Hardware/OrderReceiptEscPosRenderer.php` (`payments()`) | `if method===COUNTER_DEFERRED return []` → « ** A REGLER EN CAISSE ** » |
| Reçu écran / API | `app/Http/Resources/OrderDetailsResource.php` (`buildPaymentsBreakdown()`) | idem → `payments_breakdown = []` |
| Helper reçu JS | `resources/js/helpers/posReceiptBuilder.js` (`formatPaymentsBreakdown()`) | idem → `[]` |

**Sûreté** : à l'encaissement, `confirmCounterPayment()` écrit le **vrai** mode (1/2…) → la
ventilation réapparaît, le ticket final montre le vrai paiement. Vérifié sur les commandes
réelles : ticket = « À RÉGLER EN CAISSE », écran `payments_breakdown=[]`, `pending_counter=true`.

**Tests ajoutés** (régression, protégés par mutation) :
- `OrderReceiptEscPosRendererTest::test_counter_deferred_kiosk_order_is_marked_to_pay_not_payment6`
- `PosReceiptFiscalExposureTest::test_counter_deferred_kiosk_order_exposes_empty_payments_breakdown`
- `posReceiptBuilder.spec.js` : `returns [] for COUNTER_DEFERRED (6) not yet settled`

---

## 6. Preuves (tout vert)

- PHPUnit : `OrderReceiptEscPosRendererTest` **17/17**, `PosReceiptFiscalExposureTest` **6/6**.
- Vitest : `posReceiptBuilder` **20/20**, `kioskWizardMultiViande` **5/5**, KDS symbolique **18/18**.
- Parité PHP↔JS symboles : verte (1 échec = `F006…SentinelTest` cherchant un worktree
  supprimé = **bruit pré-existant sans rapport**).
- Bundle POS reconstruit (`pos-shell.js` + `mix-manifest.json`), sentinelle fraîcheur **3/3**.
- **Frozen-zone : 0 ligne** (renderer + resource + helper JS hors §7).

## 7. Reste à faire (gates owner)

1. **Committer + déployer le fix paiement** : actuellement dans le working tree, le code
   committé/déployé (HEAD `7fea24f5e`) porte encore le « PAIEMENT 6 ». PHP serveur (pas de
   rebuild requis côté serveur) + bundle POS reconstruit (à pousser). → **gate commit/déploy owner**.
2. **Durcissement de test (préventif, non bloquant)** : verrouiller la parité PHP↔JS de
   l'**assemblage** 2-viandes par un golden JSON committé (le sentinel actuel ne compare que
   les tables de symboles, pas la ligne assemblée), et versionner le test de parité données-réelles
   (actuellement dans un fixture scratchpad éphémère).

**Verdict** : synchro borne ↔ caisse ↔ ticket client ↔ ticket cuisine ↔ KDS **CONFORME et
cohérente**, multi-viandes propagé partout, format cuisine exactement comme demandé. Une
incohérence de paiement cross-surface trouvée et corrigée sur les 3 surfaces.
