# ULTRA E2E TOUS SYSTÈMES — RAPPORT (2026-07-10)

## Résultat : P0=0 · P1=0 (après heal) · 2 défauts réels healés+testés · 0 frozen · NF525 intact

### W1 — Baseline suites (chaque fonctionnalité couverte par test)
**640 tests passed, 0 failure** : POS 124 · Kiosk 34+13 · KDS 47 · Fiscal 246 · Order 50 · OSS 1 ·
Symmetry 5 · Branch 14 · Security 106.

### W3 — Audit adversaire (workflow 9 agents, chaque finding reproduit+vérifié)
| ID | Sév | Système | Statut | Fichier |
|---|---|---|---|---|
| **POS-1** | **P1** | POS Caisse | ✅ **HEALÉ + test** | OrderQuoteService.php |
| **OSS-01** | P2 | OSS | ✅ **HEALÉ + Vitest** | PreparingAndReadyComponent.vue |
| SYNC-ZOMBIE-ADVANCE | P2 | OSS/KDS | ⚠️ mur client réglé par OSS-01 ; rétention KDS = data test | OrderStatusScreenOrderService |
| KDS-01/02/03 | P3 | KDS | divulgué (edge : grouping raw, recall-window updated_at, sort>50) | KitchenDisplaySystemOrderService |
| OSS-02 | P3 | OSS | divulgué (pas de filtre branche si contexte absent) | OrderStatusScreenController:88 |
| SYNC-ACCEPT-GATE | P3 | Cross | by-design (OSS exclut ACCEPT) | OrderStatusScreenOrderService:63 |
| POS-2 | P3 | POS | divulgué (recall garde ligne item soft-deleted, rejeté au resubmit) | PosParkedOrderService:142 |

**Kiosk** : agent adversaire a erré (StructuredOutput cap) → couvert par W1 (47 tests verts) +
les 2 fixes du jour (rate-limit `kiosk-quote`, cancel `actorIsKioskMachine`, testés 7/7).

## Heals détaillés
### POS-1 (P1) — commande POS DELIVERY ≥30€ bloquée (409)
La règle « livraison offerte ≥ seuil » existait dans le path COMMANDE (OrderService:860-878) mais
PAS dans le path QUOTE → quote.total (avec frais) ≠ order.total (sans) → `sealForCommit` levait
409 « Order total does not match sealed quote total » et AUCUNE commande livraison ≥30€ créée.
**Fix** : miroir de la règle dans `OrderQuoteService::calculatePricing` (non-frozen, sous-total
SSOT). **Test** : `PosFreeDeliveryQuoteSealTest` (quote=32€ delivery=0, commit 201, order créé) PASS.
POS 125/125, Order 50/50 (0 régression).

### OSS-01 (P2) — mur client peignait des lignes VIDES (+ zombies)
Commande à `queue_number` ET `token` null (ex: ordre test 5399 CARDTEST-, 8j) → `<li>` vide sur le
mur client, jamais retiré. **Fix** : `_hydrateFromRows` ne garde que les commandes AVEC identifiant
visible (le client ne peut pas récupérer une commande sans n°). Vitest OSS+KDS 3/3 PASS.

## Gates
- Frozen §7 : **0 fichier touché** (fixes en OrderQuoteService, PreparingAndReadyComponent = non-frozen).
- NF525 : audit_logs=4938 hash=ffe782b9 inchangé.
- **Rien poussé** — backend testttt à déployer VPS (avec les fixes borne/caisse du jour).

## Reste divulgué (non bloquant)
P3 KDS/OSS edge-cases + P2 rétention KDS (largement pollution data test — cf. purge
`foodking:cleanup-web-test-orders`, 186 cmd test PENDING). Décision produit : OSS ACCEPT-gate,
KDS max-age advance orders. Web standalone : déjà convergé 2026-07-09 (non re-testé, isolé).
