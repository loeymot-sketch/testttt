# PROPOSAL — KioskAppComponent.vue — Offline conflict CTA button text "Voir" + toast strings hardcoded French, bypass $t()

**ID** : PROP-KioskAppComponent-015
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

Several user-facing strings are hardcoded French literals instead of `$t()`-routed, against the pattern used by surrounding code (L37, L45, L62, L77–78, etc. all use `$t()`):

| Location | Line | String |
|---|---|---|
| Conflict CTA button | L107 | `Voir` |
| Quota exceeded toast | L350 | `File saturée. Veuillez relancer la borne.` |
| Auth retry success toast | L380 | `Session rafraîchie automatiquement` |
| Auth failed toast (no-retry) | L362 | `Borne déconnectée. Reconnexion en cours…` |
| Auth failed toast (other) | L364 | `` `Borne déconnectée (${url \|\| 'API'}). Reconnexion en cours…` `` |
| Conflict cancel success | L843 | `Commande retirée de la file d'attente.` |
| Conflict force success | L862 | `La commande sera réessayée au prochain cycle.` |
| Availability flip toast (single) | L715 | `` `${itemName} indisponible — ${reason}` `` / `` `${itemName} indisponible` `` |
| Availability flip toast (anonymous) | L716 | `Un article vient de devenir indisponible` |
| Settings load console.warn | L507 | `[KioskApp] Failed to load settings into globalState:` (console-only — non-customer-facing, not affected) |

ADR-007 (cited at L182, L474–478) declares the kiosk runtime FR-immutable. Under that policy, *this is acceptable* — but it is inconsistent with the rest of the file that uses `$t()` for every customer-facing string. The drift suggests these strings were added across multiple cycles (`iter15-mega-fix`, `round-4 fix`, `KIOSK-K3.5`) and no later sweep brought them into i18n.

### Personas impacted
- **Customer (FR)** : zero impact today (the literals are correct FR).
- **Customer (AR / RTL via `[dir="rtl"]` CSS hooks at L1304–1307)** : same gap as PROP-007 — RTL pipeline partly wired but locale strings bleeding French.
- **Future V2 multi-locale tenant** : retroactive sweep effort grows linearly with each new hardcoded literal.

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
French customer: zero impact. AR customer (hypothetical V2): mixed-language toasts.

### Cashier perspective
None.

### Owner perspective
ADR-007 says FR-immutable. If owner reaffirms, this is KEEP-AS-IS. If owner intends V1.0.x or V2 to add locales, fixing now is cheap.

### Multi-tenant-future
Cumulative debt.

### Adversarial dispute (challenge yourself)
- **False positive under ADR-007?** Yes — if FR-immutable is binding, these literals are correct. The proposal is conditional on owner intent.
- **Pattern drift cost?** Real — when a future dev sweeps i18n, they have 10+ literals to find/replace in just *this* file. Each cycle of fixes adds more.
- **Goal cares?** No, not at V1.

## Proposed change

Conditional — only if owner overturns ADR-007 OR commits to V1.0.x i18n sweep. Otherwise KEEP-AS-IS.

```diff
-          'Voir'
+          {{ $t('kiosk.app.offline_conflict_cta_view') }}

-      this.$refs.toast?.show('File saturée. Veuillez relancer la borne.', 'error', 6000);
+      this.$refs.toast?.show(this.$t('kiosk.app.offline_quota_exceeded'), 'error', 6000);

-      this.$refs.toast?.show('Session rafraîchie automatiquement', 'warning', 2500);
+      this.$refs.toast?.show(this.$t('kiosk.app.auth_session_refreshed'), 'warning', 2500);

-      const label = reason === 'no-retry'
-        ? 'Borne déconnectée. Reconnexion en cours…'
-        : `Borne déconnectée (${url || 'API'}). Reconnexion en cours…`;
+      const label = reason === 'no-retry'
+        ? this.$t('kiosk.app.auth_disconnected_retrying_noretry')
+        : this.$t('kiosk.app.auth_disconnected_retrying', { url: url || 'API' });

-      this.$refs.toast?.show('Commande retirée de la file d\'attente.', 'success', 3000);
+      this.$refs.toast?.show(this.$t('kiosk.app.offline_entry_canceled'), 'success', 3000);

-      this.$refs.toast?.show('La commande sera réessayée au prochain cycle.', 'success', 3000);
+      this.$refs.toast?.show(this.$t('kiosk.app.offline_entry_forced'), 'success', 3000);

-      const label = itemName
-        ? (reason ? `${itemName} indisponible — ${reason}` : `${itemName} indisponible`)
-        : 'Un article vient de devenir indisponible';
+      let label;
+      if (itemName && reason) label = this.$t('kiosk.app.item_unavailable_with_reason', { name: itemName, reason });
+      else if (itemName)     label = this.$t('kiosk.app.item_unavailable_named', { name: itemName });
+      else                    label = this.$t('kiosk.app.item_unavailable_anonymous');
```

Plus ~8 new keys in `resources/lang/fr/kiosk.php` (or JSON equivalent) carrying the same literal strings.

Total source LOC delta : **+20 / -10 = +10 net** in component + 8 i18n keys.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| All keys present, FR locale | Pixel-identical UX | Identical |
| Key missing | Toast shows the key string — visible regression | N/A |
| Locale switched (V2) | Strings localize | Hardcoded FR everywhere |
| Frozen-zone regression | LOW — additive routing + i18n entries | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤15 LOC, single concern? **YES (+10 net LOC, 8 keys)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)**

## Owner recommendation

- [ ] APPLY-WITH-LOCK (acceptable IF ADR-007 will be revisited for V1.0.x i18n)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [x] **KEEP-AS-IS** (recommended — ADR-007 is current policy; revisit when locale switch is on the roadmap)

**Pre-condition for APPLY** : owner reaffirms or overturns ADR-007 / iter15-P1a. If FR-immutable stands, no fix needed.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L107, L350, L362, L364, L380, L715, L716, L843, L862 (hardcoded literals)
- ADR-007 / iter15-P1a (cited inline at L182)
- Sibling: PROP-KioskAppComponent-007 (theme toggle aria-label)
