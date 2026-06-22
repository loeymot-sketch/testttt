# ULTRA AUDIT BORNE (KIOSK) — VERDICT FINAL — 2026-05-09

**Branche** : `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27`
**HEAD** : `9d9dddae1`
**Méthode** : 4 sub-agents YC GStack Explore parallèles (Architect, Security, A11y, Tester)
read-only ; DBA + SRE retirés (saturés iter11-14).
**Framing initial** : "Validate V1 ship-ready Borne kiosk" (validation, not discovery)
**Trigger** : owner request "passe un ultra audit et donne rapport complet"

---

## VERDICT INITIAL (mon audit, scope-narrow)

✅ **GO V1 merge** — aucun blocker V1 sur surface Borne, BRAIN §7 16/16 reconfirmés.

## VERDICT POST-RÉCONCILIATION (consolidé avec POS adversarial audit)

⛔ **NO-GO V1 merge** — alignement sur POS audit autoritaire.

> Le POS adversarial audit du même jour (`reports/review/pos-ultra-audit-2026-05-09/99_VERDICT.md`)
> a trouvé **15 P0 cross-validés 2-3 agents** dont 3 falsifient des claims BRAIN §7 sur lesquels
> mon verdict GO Borne s'appuyait :
>
> | Mon claim Borne | Falsification POS audit |
> |---|---|
> | "Frozen zones intactes (BRAIN §2 0 lines diff vs main)" | **P0-15** : 2,597 insertions / 419 deletions sur 5/6 frozen files. KioskWizard +1,665, KioskApp +892, pos-wizard.js +237 (logic au :264-283 + :430-560). Commit `91a1e1b2c` sans gate owner retro-traceable. |
> | "BRAIN §7 row 4 fiscal DELETE trigger ✅" | **P0-03** : MySQL-only, 0 test coverage SQLite skip. Unverifiable. |
> | "BRAIN §7 row 5 webhook_events unifié ✅" | **P0-11** : SenangPay Gateway class missing → `/senangpay-webhook/` returns 500. Factuellement faux. |
> | "16/16 E2E PASS" | **P0-13/14** : 4 fake E2E specs (0 click / 0 fill) + sentinel posKioskVariationParity comparing fixtures à elles-mêmes. "16/16 green" = smoke test. |

---

## ITEMS V1.0.1 BORNE VALIDES (8) — restent valides post-correction

Ces findings portent sur du code Borne réel, **non dépendants** des claims BRAIN §7 falsifiés.
Ils peuvent être adressés en parallèle de la remediation P0 POS.

### P0 (1 item, fast fix ~10min)
1. **A11y label-input association** — `KioskLoyaltyComponent.vue:87-130` (WCAG 2.1 SC 1.3.1)
   labels sans `for=` + inputs sans `id=`.

### P1 (4 items, alignés backlog §5)
2. **`kiosk.promo` regression continuous guard** — ajouter Vitest DOM assertion dans
   `tests/js/kioskCartPromo.spec.js`. Régression caught par probe pre-commit, pas par test continu.
3. **`tokenCan('kiosk:order')` verification** — `KioskEventController` + `PaymentReconcileController`
   inférés via routing seulement, pas vérifiés explicitement.
4. **Offline queue idempotency replay test** — `kioskOfflineQueue.spec.js:32-47` couvre offline
   state mais pas merge post-reconnect avec idempotency replay.
5. **Sanctum TTL 8h → 1h sensitive ops** — `KioskMachineLoginController.php:101` lit
   `config('sanctum.expiration', 480)`. **Déjà dans BRAIN §5 P1 backlog**.

### P2 (3 items, hygiène)
6. **Soft-check fake test** — `tests/e2e/kiosk-edge-cases.spec.js:101` contient
   `expect(true).toBe(true)` après offline test. Supprimer ou ajouter assertion réelle.
7. **`kioskAnalyticsPlugin` dead code** — wired dans store mais aucun composant ne dispatche
   `track()`. Documenter ou retirer.
8. **Payment refusal coverage gap** — `kiosk-edge-cases.spec.js:56-58` référence
   `?tpe_force=declined` sans assertion. Coverage gap, pas feature gap.

---

## ITEMS V1.x BACKLOG BORNE (4)

- **safeHtml SVG sanitization** — `safeHtml.js:5-8` strip silencieusement SVG. OK aujourd'hui
  (tous SVG hardcoded), risk si UGC futur.
- **E2E selector stabilization** — text-content + innerText parsing dans
  `audit-kiosk-ux-2026-05-07.spec.js:44-46` + `v1-ingredient-rupture-propagation.spec.js:35`.
  Migrer vers `data-testid` + storageState fixtures (recommandation Anthropic insights directe).
- **`KioskUpsellComponent` focus-restore** — `KioskUpsellComponent.vue:151-156` (frozen-zone,
  gate owner requise). Polish a11y screen reader.
- **`useKioskSpeech` composable refactor** — duplication entre `KioskConfirmationComponent` +
  `KioskPaymentComponent`. Hoist au root `KioskAppComponent`.

---

## FROZEN ZONES — état documenté

| Fichier | Mon audit | POS audit P0-15 |
|---|---|---|
| `KioskWizardComponent.vue` | "Read-only validated, no axios" | **+1,665 insertions vs main, gate owner manquant** |
| `KioskAppComponent.vue` | "Read-only validated" | **+892 insertions vs main** |
| `KioskUpsellComponent.vue` | "Read-only validated" | (inclus dans le drift) |
| `public/js/pos-wizard.js` | "Strict-no-touch confirmed" | **+237 insertions, logic au :264-283 + :430-560, commit `91a1e1b2c`** |

**Méthodologie gap** : mon audit a vérifié **cohérence applicative** (no axios direct) mais
n'a pas fait `git diff main -- frozen-files` pour vérifier le claim BRAIN §2 "0 lines diff".
Le POS audit l'a fait. Lesson learned.

---

## ANCHORS INSIGHTS REPORT 2026-05-09 — RE-VÉRIFICATION

| Signal insights | État HEAD `9d9dddae1` |
|---|---|
| `kiosk.promo` collapsed regression caught par probe | ✅ Régression ABSENTE sur HEAD (carousel server-driven intact) ; ❌ pas de continuous guard → V1.0.1 P1 |
| E2E Expo/Playwright flakiness | Root cause confirmé : text-content selectors + innerText parsing → V1.x backlog migration storageState |
| NF525 fiscal sequence violations P0 | `FiscalSequenceService` lockForUpdate + cache lock OK (iter11+14) ; **MAIS** P0-01/02/03 POS audit révèlent SoftDeletes Order/OrderItem + DELETE trigger SQLite skip = NF525 break réel non-couvert |
| Route closures cassant `route:cache` | Pas de regression détectée |

---

## DECISION RECOMMANDÉE (CLAUDE.md §10)

- **block** sur merge `cycle/PHASE2-TRAIN-A-V1-RELEASE-PREP-2026-04-27` → `main` jusqu'à
  fermeture des **15 P0 POS** + **1 P0 Borne (label/input)**.
- **escalate** owner pour :
  - Soft-delete Order/OrderItem (NF525 hardstop) — voir POS P0-01/02
  - Idempotency middleware default flag — voir POS P0-05
  - SenangPay class missing — voir POS P0-11
  - Frozen-zone breach commit `91a1e1b2c` — gate owner retro à clarifier
- **heal** Borne items V1.0.1 (~1-2j-agent en parallèle remediation P0 POS)
- **heal** Borne items V1.x backlog (post-V1)

---

## MÉTA-LEÇONS

### iter15 maintenue
> "11 amendments proposés → 1 appliqué. Evidence over speculation."

### NEW — leçon Borne audit (à mémoriser pour futurs audits critiques)
> **Un audit YC GStack qui fait confiance au BRAIN au lieu de challenger ses claims peut
> produire un faux GO.**
>
> Le framing **"validate V1 ship-ready"** (mon audit) a fait confiance aux ✅ §7 16/16 sans les
> challenger.
>
> Le framing **adversarial "prouve que BRAIN.md ment"** (POS audit) a falsifié 3 de ces ✅
> avec evidence file:line.
>
> **Pour les futurs audits critiques pre-merge V1 : framing adversarial doit dominer.**

### Méthodologie gaps reconnus
1. Pas de `git diff main -- frozen-files` (P0-15 manqué)
2. Pas de scrutin systémique fake-E2E patterns (P0-13/14 manqué)
3. Confiance excessive aux claims BRAIN §7 (P0-03/05/11 manqués)
4. DBA + SRE retirés (advisor recommandation) → blind spot sur P0-04 cash_movements cascadeOnDelete + P0-07 RefreshTokenController['*'] ability + P0-09 CashDrawerService no lock

---

## STATUT FINAL

- **Audit Borne autonome** : valide pour items V1.0.1 V1.x listés
- **Verdict Borne autonome** : **superseded** par POS adversarial NO-GO V1
- **Reconciliation Graphiti** : épisode "Borne audit GO superseded by POS adversarial NO-GO V1"
  pushé sur group `foodking`
- **BRAIN.md §3 LAST DONE** : entry Borne audit conservée (cohérence chronologique) avec entry
  POS adversarial supersedant juste au-dessus
