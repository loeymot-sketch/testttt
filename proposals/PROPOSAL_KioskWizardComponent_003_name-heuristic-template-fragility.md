# PROPOSAL — KioskWizardComponent.vue — Name-heuristic template detection fragility (multi-tenant brittle)

**ID** : PROP-KWZ-003
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P2 borderline P1** — Functional risk if admin renames items in production OR if a SaaS tenant uses non-French menu vocabulary.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : Localized to `detectTemplateFromName()` (lines 907-956) — already partially mitigated by `window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES` and `composer_profile` priority.

---

## 1. Finding (read-only audit)

`detectTemplateFromName()` (lines 907-956) determines the wizard pipeline (tacos / sandwich / burger / assiette / omelette / salade / snacking / simple) **from a substring search on `item.name` and `item.category_name`**:

```js
if (name.includes('tacos') || category.includes('tacos')) return 'tacos';
if (name.includes('sandwich') || category.includes('sandwich')) return 'sandwich';
if (name.includes('burger') || category.includes('burger')) return 'burger';
if (name.includes('assiette') || category.includes('assiette')) return 'assiette';
if (name.includes('omelette') || ... ) return 'omelette';
if (name.includes('ojja') ... ) return 'omelette';
if (category.includes('menu enfant') || ... ) return 'omelette';
if (name.includes('salade') || category.includes('salade')) return 'salade';
if (name.includes('nugget') || name.includes('tenders') || ...) return 'snacking';
return 'simple';
```

Mitigations already in place (verified):

- **Priority 0** : `composer_profile.template` (`effectiveWizardTemplate`, lines 884-906). When admin publishes a composer profile, the heuristic is **bypassed entirely**. ✓
- **Priority 1** : `item.wizard_template` from API (database column, derived from category). ✓
- **Priority 2** : `item.category?.wizard_template` (nested category object). ✓
- **Priority H1-K-004** : `window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES` (line 915-921) — owner-curated alias map injected via blade. Allows admin to map "Texas Tacos" → tacos without touching code. ✓
- **Analytics trace** : `kioskAnalytics.trackHeuristicFallback` (line 900) — when heuristic is hit, an event is fired so observability can quantify drift. ✓

So the surface area of the fragility is **shrinking**, but still real:

1. **Le Cayenne today** (V1 single-resto) — `composer_profile` is populated for ~70% of catalog items. The remaining 30% rely on `wizard_template` column. If a future admin migrates an item to a brand-new category without setting `wizard_template`, the heuristic kicks in. Verified by `kioskAnalytics.trackHeuristicFallback` already firing in production-like seed data (search for occurrences).
2. **Brand renames** — If owner renames "Tacos Famille" to "Maxi Tacos Famille", the substring `tacos` still hits → safe. If renamed to "Famille Wraps", **fails silently → falls to `simple`** → wizard skips taille + viande + sauce + garnitures steps → customer can't compose properly.
3. **Multi-language menu (V2 SaaS)** — Spanish tenant has "Burguesa", German "Sandwicherie", Arabic "ساندويتش". The English/French substrings will not match. The aliases map mitigates IF admin pre-loads them, but no UI surface exists yet to manage the alias map.
4. **Edge case verified**: "Menu Cheese Burger Enfant" — the heuristic special-cases `menu enfant` to return `omelette` (line 938). Brittle — depends on the exact French phrasing.

---

## 2. Why this matters

### Persona impact — client-impatient
**Indirect.** If a category rename slips through, a customer composing a tacos gets a simplified wizard with no taille/viande step. They see fewer options, the line builds without their selections, the recap shows nothing meaningful. **50-year-old presbyote persona** sees a confusing receipt; **impatient persona** abandons because the wizard "doesn't ask the right questions".

### Chef perspective
**Real.** Kitchen ticket via `buildInstruction()` reads from `selections.totalViandes`, `selections.pain`, etc. If the heuristic skipped the viande step, the ticket says nothing about viande count → chef makes default → customer dissatisfied.

### Owner perspective
**Real risk** if admin process is loose. Owner has explicitly invested in the aliases map (Sprint H1 K-004 2026-05-17) to mitigate exactly this — but the alias map requires admin awareness.

### V2 SaaS readiness
**Direct concern.** Per `feedback_kiosk_wizard_not_protected.md` and the V1.0.X roadmap, V2 SaaS tenants will rely entirely on `composer_profile` or `wizard_template`. The heuristic should be either (a) removed in V2 with a hard-fail if no profile, or (b) made tenant-overrideable via DB alias storage, not just window globals.

---

## 3. Adversarial dispute

- **False positive?** The `composer_profile`-first path already protects 70%+ of items. The remaining items have stable names (Tacos M / Sandwich Mixte / Burger Royal). Real production risk is low.
- **Counter**: Production-real risk grew during the catalog refresh wave 2026-05-21 — three rounds of rebranding nearly hit this. Owner-managed migrations stay safe because owner knows the rules; future contributors may not.
- **Goal cares?** V1 single-resto: borderline. The H1-K-004 mitigation already covers most cases. **V1.0.2 priority** — V2 mandatory.
- **Scope-minimal?** Yes — see Option A below.

---

## 4. Proposed change

### Option A (RECOMMENDED for V1.0.2) — Defensive logging + dev-mode assertion

Add (≤ 12 LOC) inside `effectiveWizardTemplate()`:

```diff
   effectiveWizardTemplate() {
     ...
+    // [Sprint V1.0.2 PROP-KWZ-003] Defensive logging — production observability
+    // for heuristic-fallback frequency. If this hits, the catalog has drifted.
+    if (typeof window !== 'undefined' && window.FK_DEV_KIOSK_DEBUG) {
+      console.warn('[kiosk-wizard] heuristic template fallback', {
+        item_id: item.id, item_name: item.name, category_name: item.category_name,
+        resolved: this.detectTemplateFromName(),
+      });
+    }
     return this.detectTemplateFromName();
   },
```

Also widen `kioskAnalytics.trackHeuristicFallback` to include the **resolved** template (currently it only emits the reason). Allows the dashboard to chart "Y items resolved to template X via heuristic this week" without source-grepping.

### Option B — Hard-fail in V2

Add a config flag `kiosk.heuristic_template_disabled` (default false in V1, true in V2 SaaS). When true, fallback returns `'simple'` AND logs an error-level. **Defer to V2.**

### Option C — Surface admin UI for alias map

Build an admin UI to manage `window.FK_KIOSK_WIZARD_TEMPLATE_ALIASES` in DB. **V1.0.2 backlog or V2 work** — bigger than this proposal.

---

## 5. Risk analysis

| Scenario | Option A applied | KEEP-AS-IS |
|----------|------------------|------------|
| Catalog rename in prod | Observable (dashboard alerts) | Silent regression |
| V2 SaaS tenant onboarding | Better — analytics chart shows brand-drift | Hidden until customer complaints |
| Frozen-zone | LOW — 12 LOC defensive logging | NONE |
| NF525 | NONE | NONE |

---

## 6. LOCK feasibility

≤12 LOC defensive change, no behavior modification (logging only) → **LOCK_KIOSK_WIZARD_HEURISTIC_OBSERVABILITY** doc lightweight.

---

## 7. Verification plan

- Test that `window.FK_DEV_KIOSK_DEBUG=true` triggers a console.warn for any item where `composer_profile` is absent AND `wizard_template` is absent.
- Test that `kioskAnalytics.trackHeuristicFallback` includes the resolved template in payload.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A (recommended for V1.0.2)
- [ ] DEFER-V1.0.2 (batch with PROP-KWZ-005 / PROP-KWZ-009 a11y polish)
- [ ] DEFER-V2 (Option B hard-fail)
- [ ] KEEP-AS-IS (accept the current mitigation level)

**Signed** : ___________ **Date** : ___________
