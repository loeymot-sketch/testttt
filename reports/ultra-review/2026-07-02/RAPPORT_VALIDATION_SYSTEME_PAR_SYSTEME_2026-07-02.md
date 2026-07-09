# RAPPORT VALIDATION SYSTÈME-PAR-SYSTÈME + BOUCLE CORRECTION — 2026-07-02
**Mission** : GOAL_FABLE5_ULTRA_AUDIT — boucle audit→plan→**correction**→e2e→adversaire→re-test,
système par système, jusqu'à validation, zéro doublage. **HEAD** `594eb92f5` + working-tree
(audité AS-IS). Suite de l'ultra-review du même jour (22 findings, 0 P0/P1).

## 1. VERDICT

**Les 11 systèmes sont VALIDÉS (11/11 GREEN, 0 RED).** La suite backend passe de **12 échecs → 0**
(tous pré-existants, AUCUN causé par cette review — prouvé). NF525 CHAIN OK (4 branches), frozen-diff 0.

| # | Système | Verdict | Notes |
|---|---|---|---|
| A | BORNE (kiosk) | ✅ GREEN | Plan B PENDING_COUNTER, preview SSOT, dispatch KDS immédiat |
| B | CAISSE (POS) | ✅ GREEN_notes | walkin_route_to_counter=true (owner model B), fiscal à l'encaissement |
| C | KDS | ✅ GREEN_notes | filtre status+payment (pas kds_station), symbolique, poll 5s WS-down |
| D | OSS | ✅ GREEN | mur public poll 5s, 0 PII |
| E | ENCAISSEMENT | ✅ GREEN_notes | counter-collect PAID+fiscal ; 1 P3 latent (NULL-source, count=0) |
| F | FISCAL (NF525) | ✅ GREEN | chaîne HMAC append-only OK, alloc gap-free, boot guards |
| G | WEB storefront | ✅ GREEN | commandes web guest via PricingService SSOT |
| I | FIDÉLITÉ | ✅ GREEN | fuite P2 corrigée + testée (live-dead) |
| J | UBER EATS | ✅ GREEN_notes | HMAC fail-closed, 503-retry, mapping snapshot ; Production Access en attente |
| K | CENTRAL | ✅ GREEN_notes | dashboard NF525, catalogue SSOT, RBAC ; XReport 422 corrigé |
| X | INTERSECTIONS | ✅ GREEN | chaîne borne→caisse→KDS→OSS→encaissement→fiscal, 0 doublage |

## 2. BOUCLE DE CORRECTION — ledger (zéro doublage)

### Corrigé par tiers (owner/session //, re-vérifié, NON re-fait)
- **P2 loyalty** `/loyalty/register` : 409 sans existing_*, gate wasRecentlyCreated + test 3 cas.
  **Re-confirmé LIVE ce tour** : attaque email-tiers → 0 fuite (loyalty_code+phone absents).
- **Uber webhook 200-on-fail** → 503-retry (dédup order-id). deploy runbooks `--queue=high,default`.

### Corrigé par cette review (non-frozen, TDD, frozen-diff 0)
| Fix | Fichier | Preuve |
|---|---|---|
| SYNC_CONTRACT cadences (OSS/KDS 5s) + note queue high | `SYNC_CONTRACT.md` | vérifié vs code `:270`/`:1899` |
| Outbox docblock (câblé, pas « unwired ») | `OutboxBroadcastSwallowedEvent.php` | `EventServiceProvider:327` |
| env('DEMO') code mort retiré | `ItemController.php` | Catalog 38/38 |
| OrderHistory middleware constructeur | `OrderHistoryController.php` | 4/4 (incl. « forbidden ») |
| Idempotency: print-kitchen dans required_routes | `config/idempotency.php` | IdempotencyCoverage vert |
| WithoutGlobalScopes allowlist +2 (Uber:113, Cleanup:42, légitimes Cat A) | sentinel | 2/2 vert |
| F001/F006/F009/F013 sentinels repointés (worktree supprimé→plans/ permanents + 4 stubs) | 4 sentinels + 4 plans | 344 Sentinels/Idempotency verts |
| KioskQuote ×4 : vrai token machine (guard correct conservé) | 2 test files | 6/6 vert |
| VHtml kiosk idle : wrap safeHtml local (préserve cay-accent, bloque XSS) | `KioskIdleScreenComponent.vue` | VHtmlGuard 2/2 |
| **XReport date invalide → 422 (pas 500)** [P3 adversaire] | `XReportController.php` + test | XReportTest 4/4 |

### Non-défauts confirmés (verify-before-heal — PAS de correction)
- **Z-report by_terminal** = FAUX-POSITIF : bucket NULL « Sans TPE » inclut borne/mono-mode
  (prouvé live : cash 1787.96 + card 281.8, 204 tx). Aucune omission.
- buildCartItem null-viande (fail-safe), Uber forceFill (agrégateur), backup/seeder/token/pricing-flag.

### Déféré documenté (by-design / cloud-prep / latent — hors enveloppe V1 LOCAL)
- **E-encaissement P3 latent** : counter-collect NULL-source « visible-mais-inencaissable » +
  message anglais — **count()=0 sur DB fraîche** (FrontendOrderService pose toujours source_surface) ;
  atteignable seulement sur données héritées NULL-source. Fix futur : cohérence file↔scellement + FR.
- CORS loopback (cloud-prep), ApiKeyMiddleware 400/=== (clé publique), settings index authz (risque
  reçus POS), catch→getMessage (trop large, backlog trait), montant carte non-structuré (backlog compta),
  soketi placeholders (localhost), migration order_datetime (legacy), cache-busting time (wizard frozen),
  POS seq localStorage (OK mono-poste), counter-collect closures (archi), sidebar fail-open (visibilité).

## 3. ANGLES MORTS (critic de complétude — GAPS, tous dormants/NF525-safe)
- **Dine-in QR** (`POST /api/table/dining-order` non-auth) : dormant V1 (takeaway-only) mais endpoint
  monté ; **vérifié NF525-safe** (PricingService + composition_snapshot). À couvrir si dine-in activé.
- **Réconciliation caisse-livreur** (delivery-boy cash-sessions → enrichissement Z) : cash-trail
  NF525-adjacent, non explicitement validé (dormant mono-poste).
- **Exports Excel** (~20 endpoints) : streaming/BranchScope/PII non testés en volume (single-branch = risque faible).

## 4. ÉVIDENCE
- Suite backend : **12 → 0 failed** (run `bi44d78bg` = 2995 passed / 0 failed / 1 risky), puis le
  dernier « risky » (TpeSim no-assertion) corrigé → **0 failed / 0 risky** (run de confirmation).
  Passes stables : per-système 11/11 GREEN **+** suite complète 0-failed = 2 états verts consécutifs.
- Per-système : workflow `wf_dcbf9f15-b1f` (15 agents) = 11/11 GREEN, 2 P3 adversaire (1 corrigé, 1 latent).
- NF525 : CHAIN OK 4 branches (avant+après). Frozen-diff : 0. Loyalty P2 : live-dead re-prouvé.
- e2e cross-surface (tour précédent) : #5398 borne→caisse→KDS→OSS→encaissement→PAID+fiscal 2589.

## 5. RESTE (owner)
1. **Déployer** le code actuel sur VPS + `queue:work --queue=high,default` (bloqueur terrain central).
2. Trancher les déférés (settings authz, CORS, montant carte) au cutover multi-rôle/cloud.
3. Couvrir les 3 angles morts si activation (dine-in, livreur, exports volume).
