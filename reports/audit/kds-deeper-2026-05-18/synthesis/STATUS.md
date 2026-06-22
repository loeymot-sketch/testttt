# KDS Standalone Deeper Audit — Wave C STATUS

**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Pre-audit HEAD** : `4ad1adba8`
**Scope** : KDS aspects NOT covered by Z-1 (S3-kds i18n catalog) or PK-1..4 (POS×KDS intersection)
**Track** : Wave C parallel master (OSS deeper + Loyalty cross-surface = sister masters, disjoint scope)
**Specialists dispatched** : Architect (KD-1), UX/A11y (KD-2), RED (KD-3) — 3 (lighter than Z-1+PK had 5; justified since Z-1/PK covered fundamentals)

---

## Verdict

**PASS_WITH_FINDINGS_HEAL_INLINE** — 1 P0 promoted by RED + 2 P2 healed in this audit (data + 1-line code edits, frozen-zone diff = 0). 4 P1 / 3 P2 / 3 P3 deferred V1.0.X (owner-batch with PK-4 backlog).

V1 single-resto Le Cayenne FR-locale: SHIPPABLE post-heal.
V1.1+ multi-station OR EN-locale cloud deploys: REQUIRE V1.0.X owner-batch (KD-A1-01 / KD-A1-04 / KD-RED3-02 / KD-UX2-02).

---

## Heals applied this audit cycle

| ID | Severity | Description | Files | LOC |
|---|---|---|---|---|
| KD-RED3-01 (was KD-UX2-01 P1) | **P0** | 7 EN-locale allergen modal keys leaking French to chefs in EN tenant | `resources/js/languages/en.json` | 7 strings |
| KD-UX2-03 | P2 | `KdsOrderLine.vue:23` aria-label `"allergen"` hardcoded English — localized via `$t('label.kds_line_allergen_icon_aria')` | `KdsOrderLine.vue` + 3 i18n files | 1 vue + 3 keys |
| KD-UX2-04 | P2 | Dead `\|\| 'Allergènes :'` fallback at `KdsOrderLine.vue:105` (sibling of Z-1 P1-S3-04 at parent) | `KdsOrderLine.vue` | 1 line |

**Verification** : `npx vitest run tests/js/kdsAllergens.spec.js tests/js/kdsAriaI18n.spec.js` = **19/19 PASS**. JSON parse OK on fr/en/ar. Frozen-zone diff = 0 (all healed files are NOT in frozen list).

---

## Per-zone outcomes

| Specialist | Findings | P0 | P1 | P2 | P3 | Heals applied |
|---|---|---|---|---|---|---|
| **KD-1 Architect** | Multi-station + observability + V2/legacy + outbox-bypass + cache_key | 0 | 2 (defer V1.0.X) | 2 (defer V1.0.X) | 1 (defer V1.0.2) | — |
| **KD-2 UX/A11y** | EN allergen modal + bump-undo retry + aria localization + dead fallback + reduced-motion + tablet width | 0 | 2 (1 healed, 1 defer V1.0.X) | 2 (both healed) | 2 (defer V1.0.2) | KD-UX2-01 + KD-UX2-03 + KD-UX2-04 |
| **KD-3 RED** | Allergen-EN-modal blocker + obs-blindspot + outbox-routing + bump-sentinel + CI-vs-audit posture | **1 healed** | 2 (defer V1.0.X) | 2 (defer V1.0.X) | — | KD-RED3-01 (= KD-UX2-01) |

---

## The 4-list

### Healed inline this audit cycle
- **P0**: KD-RED3-01 — 7 EN allergen modal strings translated (en.json)
- **P2**: KD-UX2-03 — aria-label localized + 3 new i18n keys (`kds_line_allergen_icon_aria` × fr/en/ar)
- **P2**: KD-UX2-04 — dropped dead FR fallback

### Deferred V1.0.X — owner-batch
- **P1** KD-A1-01 — Multi-station bump localStorage NOT user/station-scoped (architectural gap; latent until V1.1+ multi-terminal)
- **P1** KD-A1-02 / KD-RED3-02 — KdsSyncService emits ZERO metrics; SyncOverviewController dashboard has consumer pre-wired awaiting producer (~3 LOC heal)
- **P1** KD-UX2-02 — Bump-undo silent drift: bumpItem is local-only, but downstream auto-PATCH on `isReadyOrder===true` can fail → bumped-stale localStorage
- **P1** KD-RED3-03 — DispatchKdsTicket NOT routed through Outbox (vs Wave 3 sibling commits 335b98134 / e264be951); silent catch at KitchenDisplaySystemOrderService.php:244
- **P2** KD-A1-03 — V2-as-default invariant has NO Vitest sentinel; future flip would silently re-enable 8 cluster-7 P0s in legacy `?v2=0`
- **P2** KD-A1-04 — DispatchKdsTicket exception swallowed silently (same root as KD-RED3-03, architect framing)
- **P2** KD-RED3-04 — Bump-stays-local invariant has no test sentinel; future agent could naïvely wire to backend
- **P2** KD-RED3-05 — Meta-finding: 10 KDS V1.0.X TZ failures (commit c2613cab0 regression) co-exist with this audit's "production-ready" claim

### Deferred V1.0.2 — polish
- **P3** KD-A1-05 — KdsSyncService cache_key + cache_hit/miss instrumentation (batches with KD-A1-02)
- **P3** KD-UX2-05 — KdsUndoToast `prefers-reduced-motion` could surface text countdown
- **P3** KD-UX2-06 — Toast `min-width: 620px` hardcoded — tablet portrait risk

### Out of scope (acknowledged, NOT re-counted)
- Z-1 P1-S3-01..05 (i18n FR-in-EN + hardcoded FR aria-label catalog at `KdsOrderCard.vue` + legacy `?v2=0` 11-string template — already documented)
- PK-3 KDSOrderItemsResource allergens_snapshot exposure — HEALED commit `d6b80eef1`
- PK-4 TZ-aware bounds + reconnect-storm + 5 V1.0.X backlog items — ACKNOWLEDGED
- 10 KDS test failures (TZ regression, c2613cab0) — explicit out-of-scope per prompt

---

## Cross-cutting attestations

| Attestation | Status |
|---|---|
| Frozen-zone diff over KDS surface | **0 lines** (en.json + fr.json + ar.json + KdsOrderLine.vue NOT in frozen list per CLAUDE.md §7) |
| NF525 chain integrity | **UNCHANGED** (no fiscal touch) |
| KdsAllergens + KdsAriaI18n Vitest | **19/19 PASS** post-heal |
| JSON parse fr/en/ar | OK |
| TZ-aware bounds (Wave 3 KDS Adversarial P0) | INTACT (KdsSyncService.php:65-94 not touched) |
| AR locale allergen modal | INTACT (already correct, 7/7 keys verified) |
| Z-1 + PK findings | NOT re-counted (cross-checked per advisor guidance) |

---

## Key novel insight (RED framing)

The audit chain Z-1 → PK-1..4 → Wave C revealed a **pattern of consistent EN-locale neglect** at the i18n leaf level. Z-1 caught 1 leak (kds_status_conflict at en.json:1263). Wave C uncovered 7 SIBLINGS in the kds_allergens_modal_* namespace at en.json:1078, 1079, 1083-1086, 1263. RED-team escalation: the EN locale appears to have undergone mechanical key extraction without translator review. Pre-EN-cloud-tenant deploy, an audit of ALL en.json `kds_*` keys (and likely `oss_*` / `pos_*` siblings) is recommended as a Wave-D follow-up.

---

## Files persisted

- This STATUS doc : `reports/audit/kds-deeper-2026-05-18/synthesis/STATUS.md`
- 3 specialist JSONs : `reports/audit/kds-deeper-2026-05-18/round-1/KD-{1,2,3}/<role>.json`

## Next-step recommendation (for Wave C convergence orchestrator)

1. Commit the 3 healed files (en.json + fr.json + ar.json + KdsOrderLine.vue) as a single PR-quality patch with message referencing KD-RED3-01 (P0 EN allergen modal) + KD-UX2-03 (aria localization) + KD-UX2-04 (dead fallback drop).
2. Cross-check with parallel Wave C OSS deeper + Loyalty cross-surface masters for any en.json sibling-leak findings (likely candidates: `oss_*` + `loyalty_*` namespaces).
3. Promote KD-RED3-02 / KD-A1-02 (observability) and KD-A1-04 / KD-RED3-03 (outbox routing) onto V1.0.X heal-light docket alongside PK-4's existing 5-item backlog.
4. Pre-EN-cloud-tenant deploy gate: full `en.json` `kds_*` + `oss_*` + `pos_*` audit pass.
