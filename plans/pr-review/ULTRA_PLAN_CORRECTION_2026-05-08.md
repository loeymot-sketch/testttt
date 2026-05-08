# Ultra Plan Correction — YC GStack Deep Reasoning
**Date :** 2026-05-08
**Branche :** `claude/blissful-mclean-c915c2` (HEAD `884f04b75`)
**Méthode :** GStack 7 phases × 6 rôles virtuels FoodKing
**Trigger :** post-ultra-review 4-batch (P0/P1 fixés). Items P2/P3 + sibling tasks + Phase B à planifier.

---

## §0 — Méta : pourquoi ce plan ?

**Constat post-ultra-review :**
- ✅ 4 batches P0/P1 healed (commit `884f04b75`)
- 🟠 **2 sibling tasks critiques spawned** (out-of-scope cycle initial mais réels) → bugs UX/observabilité en prod
- 🟡 **5 findings P2 WARN** identifiés mais non-fixés (perf + UX + coverage)
- 🟢 **5 findings P3 NIT** documentés (polish + doc-trace)
- 🔵 **4 Phase B plans** à structurer avant gate owner

**Décision orchestrateur :** un cycle de heal-light Wave A+B+C avant merge évite la dette technique. Phase B reste BACKLOG owner-decision.

**Risque si on saute :**
- A1 (i18n confirmation) : 25 jours de UX broken sur écran post-commande kiosk → users voient des keys raw
- A2 (pusher-ack 404) : observability metrics silencieusement cassées → on découvrira en prod
- A3 (N+1 burger) : impact négligeable en V1 mais croît avec le volume — dette qui devient critique en v2

**Coût total :** 4-5h cumulatives (executable par 1 orchestrateur + 2-3 sub-agents en parallèle) vs ~2 semaines en dette si BACKLOG.

---

## §1 — Les 6 rôles virtuels GStack appliqués

Pour chaque finding, je fais valider par les rôles concernés :

| Rôle | Mission | Quand intervient |
|---|---|---|
| **Architect** | Coherence globale, frontière entre couches, dépendances | Sibling tasks A1+A2, refactor A3 |
| **Security** | Auth, BranchScope, validation, defense-in-depth | A4 mic consent, GET kiosk-theme threat model |
| **A11y** | WCAG 2.1+, screen-reader, keyboard, motion | A4 voice consent, B1 plans doc |
| **DBA** | Migrations, indices, FK, perf queries | A3 N+1, A6 polymorphisme |
| **Tester** | Coverage, edge cases, integration | A6 UpsellPreviewPage spec, A2 fix verification |
| **SRE** | Observability, deploy, rollback, monitoring | A2 pusher-ack, A7 cleanup, C2 build |

---

## §2 — Wave A — Heal critique (2-3h, parallélisable 3 sub-agents)

### A1 — i18n path bug `kiosk.confirmation.*` 🔴 P1 critique

**🎯 Goal**
Fix 34 `$t('kiosk.confirmation.*')` calls qui rendent raw keys au lieu de traductions sur l'écran de confirmation post-commande kiosk.

**❓ Why (raisonnement deep)**
- **Impact UX direct** : à chaque commande validée, le client voit `"kiosk.confirmation.title"` au lieu de `"Commande confirmée !"`. Sur 1000 commandes/jour/kiosk × ~5 kiosks = **~5000 expositions/jour** à du texte raw.
- **CLAUDE.md non-négociable #4** : "Real evidence is more important than confidence" — on a empiriquement vérifié `$t('kiosk.confirmation.title') === 'kiosk.confirmation.title'` (key string brute).
- **Origine** : commit `660c9341c` (April 13, 25 jours) a introduit le namespace `kiosk.wizard.confirmation.*` MAIS le `KioskConfirmationComponent` appelle encore `kiosk.confirmation.*`. Pré-existant à cette PR.
- **Cette PR a aggravé** : nouvelles keys Wave Alpha (CSAT + ETA + total points) ajoutées sous `kiosk.wizard.confirmation.*` matchant la convention buggy.
- **Scope** : `KioskConfirmationComponent.vue` lignes ~95-180 + `fr.json` + `en.json` + `ar.json`.

**🛠 How (3 options, deep trade-off)**
- **Option 1 (recommended)** : Move keys `kiosk.wizard.confirmation.*` → `kiosk.confirmation.*` dans fr/en/ar.json. Aucun code touché, juste i18n. **Risk min**, fix complet.
- **Option 2** : Renommer tous les `$t('kiosk.confirmation.*')` → `$t('kiosk.wizard.confirmation.*')` dans le composant. Touche le code Vue. **Risk medium**, plus chirurgical mais composant est sensible.
- **Option 3** : Ajouter `kiosk.confirmation.*` AS ALIAS de `kiosk.wizard.confirmation.*`. **Risk hidden**, pollue le namespace, pas durable.

**Décision : Option 1.** Modif i18n only = aucun risque code, vérifié par diff stat (3 fichiers JSON, ~30 lignes déplacées par fichier).

**⚠️ Risques + mitigations**
- ⚠️ Si une autre surface (POS / dashboard) référence `kiosk.wizard.confirmation.*` → break. **Mitigation** : `grep -r "kiosk.wizard.confirmation"` pre-fix pour cataloguer toutes les usages, garder un alias temporaire si nécessaire.
- ⚠️ ar.json partial — peut manquer les keys `confirmation`. **Mitigation** : audit avant move, accepter fallback fr en français si ar manque (déjà conventionné).

**✅ Acceptance**
- [ ] `grep -rn "kiosk.confirmation" resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue` → toutes les calls résolvent vers fr/en
- [ ] Mount KioskConfirmationComponent en vitest avec stub i18n → assert title rendered != raw key
- [ ] Manual probe via Claude_in_Chrome `/kiosk/confirmation` (post smoke test) → screenshot validation
- [ ] `grep -r "kiosk.wizard.confirmation" resources/js/` → 0 result (post-move)

**⏱ Effort** : 30min (mostly i18n move + 1 nouveau test)

**Rôles** : Architect ✅ + Tester ✅

---

### A2 — Fix `/api/api/internal/pusher-ack` 404 🔴 P1 observability

**🎯 Goal**
Corriger l'URL absolue `/api/internal/pusher-ack` dans `eventContract.js:80` qui produit `/api/api/internal/pusher-ack` 404 (axios baseURL est `/api`).

**❓ Why (raisonnement deep)**
- **Pusher ACK metrics silently broken** depuis l'introduction du pattern dans `app.js:56` (`axios.defaults.baseURL = API_URL + '/api'`).
- **Même classe de bug** que celui fixé pour `kioskThemeManager.js` dans Wave 4 (commit `30551044e`).
- **Impact SRE** : on perd les ACK Pusher → impossible de mesurer le taux de delivery realtime → CV1 fiscal compliance audit log peut être incomplet.
- **CLAUDE.md non-négociable #5** : "Real evidence is more important than confidence" — il y a probablement d'AUTRES occurrences cachées du même pattern.

**🛠 How**
1. **grep audit complet** : `grep -rn "axios\.\(get\|post\|put\|delete\)\(['\"]\/api\/" resources/js/` → catalogue.
2. **Fix par file** : remplacer `/api/<path>` → `<path>` (relative).
3. **Update tests correspondants** : assertion sur la nouvelle URL relative.
4. **Add lint rule** (BACKLOG B) : ESLint rule custom ou regex pre-commit hook qui interdit `axios.X('/api/`.

**⚠️ Risques + mitigations**
- ⚠️ Si on rate une occurrence → silent 404 continue. **Mitigation** : grep exhaustif + audit visuel + ajout test régression.
- ⚠️ Si une URL est intentionnellement absolue (cross-domain) → break. **Mitigation** : audit ligne par ligne avec contexte, garder `https://...` absolutes.

**✅ Acceptance**
- [ ] `grep -rn "axios\.\(get\|post\|put\|delete\)\(['\"]\/api\/" resources/js/` → 0 result (sauf justifications documentées)
- [ ] `eventContract.js:80` URL fixed
- [ ] Tests existant pour pusher-ack passent (ou nouveau test ajouté)
- [ ] Manual smoke : DevTools network tab → POST `pusher-ack` retourne 200/204, pas 404

**⏱ Effort** : 20min (audit + 1-3 fix + tests)

**Rôles** : Architect ✅ + SRE ✅ + Tester ✅

---

### A3 — `RuleBasedStrategy` N+1 queries hoist 🟡 P2 perf

**🎯 Goal**
Hoister `branchAvailableItemsQuery::pluck` une seule fois par invocation `recommend()` (actuellement 4× sur le hot path burger).

**❓ Why (raisonnement deep)**
- **Impact mesuré** : 10-14 queries/request burger en cold-start. SQLite test `:memory:` reste rapide mais en MySQL prod avec branch_id filter + indexes, chaque pluck = ~5-10ms × 4 = 20-40ms inutile.
- **CLAUDE.md priorité #6** : "Architecture is more important than local convenience" — le hot path doit être optimisé maintenant pour ne pas degrader avec le volume V2.
- **DBA role** : N+1 patterns sont la cause #1 de perf regression Laravel. Eager-loading + cache lookup.

**🛠 How**
```php
// AVANT (RuleBasedStrategy.php:262)
private function branchAvailableItemsQuery(int $branchId)
{
    $availableIds = ItemBranchAvailability::where('branch_id', $branchId)
        ->where('is_available', true)
        ->pluck('item_id'); // exécuté 4× dans recommend()
    // ...
}

// APRÈS
public function recommend(...) {
    // Hoist une seule fois
    $this->cachedAvailableItemIds = ItemBranchAvailability::where('branch_id', $branchId)
        ->where('is_available', true)
        ->pluck('item_id')
        ->toArray();
    // ... 3 heuristiques utilisent $this->cachedAvailableItemIds
    $this->cachedAvailableItemIds = null; // reset après recommend()
}
```

**⚠️ Risques + mitigations**
- ⚠️ Strategy stateful = anti-pattern (sub-agent 1 a noté "pure function"). **Mitigation** : passer en argument plutôt que stocker dans `$this`. Ou utiliser memoization avec key cache scoped au request.
- ⚠️ Race condition si même instance utilisée concurremment. **Mitigation** : Laravel container `bind` (pas singleton) garantit nouvelle instance par injection. Vérifié dans AppServiceProvider.

**✅ Acceptance**
- [ ] Profiling avant/après : Bash `time` sur `php artisan test --filter UpsellRecommendationTest` → -10-15% wall-clock
- [ ] DB Query log assertion `<= 5 queries` au lieu de 10-14
- [ ] Tests existants pass sans modif

**⏱ Effort** : 45min (refactor + verify queries count)

**Rôles** : Architect ✅ + DBA ✅ + Tester ✅

---

### A4 — `KioskVoiceOrderingButton` pre-flight consent 🟡 P2 UX privacy

**🎯 Goal**
Ajouter un dialog pre-flight explicite avant `SpeechRecognition.start()` pour kiosk public space privacy compliance.

**❓ Why (raisonnement deep)**
- **Privacy CLAUDE.md priorité #7** : kiosk = espace public. RGPD article 7 (consent) implique un click explicite pour activer captation audio.
- **Browser implicit prompt** existe mais :
  - Chrome/Edge : 1× par origine (acceptable)
  - Safari : peut auto-deny silently (FAIL)
  - HTTPS-only (ok mais à vérifier prod)
- **A11y role** : un screen-reader user peut activer le mic accidentellement sans s'en rendre compte. Pre-flight dialog = WCAG-friendly.
- **Sub-agent 3 finding** : POC label give some grace, but kiosk PROD sera concerné.

**🛠 How**
```vue
<!-- KioskVoiceOrderingButton.vue -->
<template>
  <div>
    <button @click="onMicClick">{{ micLabel }}</button>
    <KioskMicConsentDialog
      v-if="showConsent"
      @confirm="onConsentGranted"
      @cancel="onConsentDenied"
    />
  </div>
</template>
<script>
data() {
  return { showConsent: false, hasGrantedThisSession: false };
}
methods: {
  onMicClick() {
    if (!this.hasGrantedThisSession) {
      this.showConsent = true;
    } else {
      this.startListening();
    }
  },
  onConsentGranted() {
    this.hasGrantedThisSession = true;
    this.showConsent = false;
    this.startListening();
  }
}
</script>
```

**⚠️ Risques + mitigations**
- ⚠️ UX friction (1× click supplémentaire la première fois) — acceptable pour privacy.
- ⚠️ State reset au reload — par session OK.
- ⚠️ Si on stocke en localStorage → tracking implicite. **Mitigation** : session-scoped only.

**✅ Acceptance**
- [ ] 1er click micro affiche pre-flight dialog
- [ ] Cancel → no `SpeechRecognition.start()` appelé
- [ ] Confirm → start + flag session-scoped
- [ ] Tests vitest : 3 nouveaux tests (initial consent + grant flow + deny flow)

**⏱ Effort** : 1h (nouveau dialog + tests + UI integration)

**Rôles** : Security ✅ + A11y ✅ + Tester ✅

---

### A5 — `[Vue warn]` noisy `tr()` helper 🟢 P3 cosmétique

**🎯 Goal**
Supprimer le `[Vue warn]: Property "$t" was accessed during render but is not defined on instance` qui pollue les logs vitest pour `KioskVoiceOrderingDialog` + `KioskVoiceOrderingButton`.

**❓ Why**
- Test logs noisy = signal-to-noise ratio dégradé pour future debug.
- Pas un bug fonctionnel mais un anti-pattern Vue 2/3 (lecture conditionnelle de `this.$t`).

**🛠 How**
Remplacer le pattern :
```js
tr(key, fallback) {
  if (typeof this.$t === 'function') {
    const value = this.$t(key);
    return value === key ? fallback : value;
  }
  return fallback;
}
```
Par injection-based pattern :
```js
inject: {
  $i18nFn: { default: () => null }
}
methods: {
  tr(key, fallback) {
    const fn = this.$i18nFn || this.$options.methods?.$t;
    if (typeof fn === 'function') {
      const value = fn(key);
      return value === key ? fallback : value;
    }
    return fallback;
  }
}
```

**Alternative simple** : ajouter `errorCaptured` ou simplement `try/catch`.

**⚠️ Risques** : Très bas. C'est cosmetique.

**✅ Acceptance** : `npx vitest run` → 0 `[Vue warn]` lines.

**⏱ Effort** : 15min

**Rôles** : Tester ✅

---

### A6 — UpsellPreviewPage tests vitest 🟡 P2 coverage gap

**🎯 Goal**
Ajouter `tests/js/UpsellPreviewPage.spec.js` (admin tool 394 LOC actuellement 0 test).

**❓ Why**
- Sub-agent 3 a noté "no spec exists today, relies on manual QA".
- Component ~120 LOC script + 4 méthodes (loadBranches, addLine, removeLine, runPreview) + 2 form validators.
- Risk : régression silencieuse en V2.

**🛠 How**
7 tests minimum :
1. `mount()` + branches load (POST `/branch/lists`)
2. Cart add/remove lignes
3. `canSubmit` validator (item_id integer + quantity ≥ 1)
4. POST `/api/admin/upsell-preview` emission avec body correct
5. Result render (recommendations + latency + cart_size)
6. Empty state si no recommendations
7. Error path 422 / 500

Mocks : axios stubs (similaires à `KioskThemeManagerPage.spec.js` pattern).

**✅ Acceptance** : 7 tests verts + couvre toutes les méthodes.

**⏱ Effort** : 45min

**Rôles** : Tester ✅

---

### A7 — `storage/media-library/temp/` cleanup 🟢 P3

**🎯 Goal**
Retirer 3 binaires accidentellement commités Wave Alpha + ajouter `.gitignore`.

**❓ Why**
- ~660 KB de binaires upload temp dans git history.
- Pas critique mais salit l'historique.
- Réplicabilité dev : si un autre dev fait un upload test, ces fichiers vont aussi apparaître.

**🛠 How**
```bash
# Retirer du current HEAD (pas de rebase, juste delete)
git rm storage/media-library/temp/2fc309a37d37cad1dbf848fb1f5de310
git rm "storage/media-library/temp/osKSkwzlznf8RzRQZBXZv4HNqbTe31UN/Kq1U7VhUFDgL3iaGDP4LYaDT6DUVjy0Xthumb.png"
git rm "storage/media-library/temp/osKSkwzlznf8RzRQZBXZv4HNqbTe31UN/cri7jrxyAyaEAvaBRQC8h4hs4GPm80uE.png"

# Ajouter .gitignore
cat >> .gitignore <<EOF

# Media library temp uploads (CV1 cleanup 2026-05-08)
storage/media-library/temp/
EOF

git add .gitignore && git commit -m "chore: gitignore media-library/temp + remove accidental commits"
```

**⚠️ Risques** : Aucun (delete un fichier qui n'est pas référencé code).

**✅ Acceptance** : `git ls-tree -r HEAD | grep storage/media-library/temp` → empty.

**⏱ Effort** : 5min

**Rôles** : SRE ✅

---

## §3 — Wave B — P3 NIT polish (1h, parallélisable 2 sub-agents)

### B1 — Plans V1x-3/V1x-6 status flip + footnote orientation

```diff
- [ ] Pending gate + decision
+ [x] Executed — Option A (V1x-3) / Decision B (V1x-6)
```

+ V1x-3 footnote orientation : "Baseline 64px portrait 1080×1920 only ; landscape 1920×1080 → ~90px. Kiosk borne deployment doctrine = portrait."

**Effort** : 5min

---

### B2 — Lazy-load consistency router modules

```diff
// kioskThemeAdminRoutes.js + upsellPreviewRoutes.js (eager)
- import KioskThemeManagerPage from '../../components/admin/kioskTheme/KioskThemeManagerPage.vue';
+ const KioskThemeManagerPage = () => import(/* webpackChunkName: "admin-theme" */ '../../components/admin/kioskTheme/KioskThemeManagerPage.vue');
```

Réduit le main bundle. Effort 10min.

---

### B3 — Lint Vue + ESLint + PHPStan run

```bash
npx eslint resources/js/components/frontend/kiosk/builder/ \
            resources/js/components/admin/kioskTheme/ \
            resources/js/components/admin/upsellPreview/ 2>&1 | tee plans/pr-review/eslint-report.txt

./vendor/bin/phpstan analyse \
  app/Http/Controllers/Admin/UpsellPreviewController.php \
  app/Services/Recommendation/ 2>&1 | tee plans/pr-review/phpstan-report.txt
```

Si erreurs → fix. Effort 20-30min.

---

### B4 — Architecture Decision Record (ADR) pour les 4 décisions design

Créer `docs/ADR/ADR-2026-05-08-kiosk-design.md` avec :
- Décision V1x-3 Option A (orientation portrait)
- Décision V1x-6 Decision B (3 templates aria)
- Décision V2-4 voice flag default OFF
- Décision V2-5 themes pull-at-boot (vs push real-time)

**Effort** : 25min

---

## §4 — Wave C — Final shipping (30min, séquentiel)

### C1 — Smoke test live full kiosk + admin (Claude_in_Chrome)
- `/kiosk/idle` + `/kiosk/cart` (avec items seedés cette fois)
- `/admin/upsell-preview` flow complet
- `/admin/kiosk-themes` switch theme
- `/kiosk/burger-builder-poc` keyboard test (Tab + Delete + Arrow)

### C2 — Tests cumulative final
```
npx vitest run                                      → expect 570+/570+
php artisan test --filter "Frontend|Admin|Recommendation" → expect 32+/32+
npm run production                                  → expect Mix compiled
```

### C3 — Push branch + create PR
```bash
git push -u origin claude/blissful-mclean-c915c2
gh pr create \
  --title "Kiosk Design Execution — 21 items + ultra-review heal" \
  --body "$(cat plans/pr-review/PR_DESCRIPTION.md)"
```

### C4 — Graphiti push final
Push final state cycle closed.

---

## §5 — Wave D — Phase B BACKLOG (post-merge owner-decision)

### D1 — V2-2 Phase B drag-drop wizard integration
- **Frozen** : `KioskWizardComponent.vue` (1659 LOC)
- **Approche** : wrapper slot ou `<component :is>` conditionnel via feature flag
- **Effort** : 2j-agent
- **Gate explicit owner requis**

### D2 — V2-3 Phase B kiosk surface upsell carousel
- **Frozen** : `KioskUpsellComponent.vue`
- **Approche** : injection résultats backend `POST /api/upsell-recommendations` dans le carousel post-cart
- **Effort** : 1j-agent
- **Gate**

### D3 — V2-4 Phase B voice intent parsing wizard
- **Frozen** : `KioskWizardComponent.vue`
- **Approche** : query param `?voice_intent=X` parsé en mounted() pour pré-remplir cart
- **Effort** : 2-3j-agent
- **Gate**

### D4 — V2-5 Phase 3 polish + Playwright themes
- **Non-frozen**
- **Approche** : i18n strings staff + Playwright snapshots themes appliqués (Halloween/Christmas)
- **Effort** : 0.5-1j-agent
- **Pas de gate**

---

## §6 — Risk register consolidé post-plan

| Item | Niveau | Mitigation déjà en place | Action plan |
|---|---|---|---|
| A1 i18n confirmation | P1 | Sub-agent identifié, fix Option 1 sans risque | Wave A1 |
| A2 pusher-ack 404 | P1 | Audit grep + 1-3 fix | Wave A2 |
| A3 N+1 queries | P2 | Hoist + container scope | Wave A3 |
| A4 mic privacy | P2 | Pre-flight dialog session-scoped | Wave A4 |
| A5 Vue warn | P3 | Inject-based helper | Wave A5 |
| A6 UpsellPreviewPage no test | P2 | 7 tests pattern | Wave A6 |
| A7 storage/temp binaries | P3 | gitignore + delete | Wave A7 |
| B1-B4 polish | P3 | Doc + lint | Wave B |
| Phase B frozen integration | Owner | Plans D1-D4 documentés | Wave D (post-merge) |

---

## §7 — Acceptance globale post-plan

- [ ] Wave A : 7 items healed (A1-A7)
- [ ] Wave B : 4 items polish (B1-B4)
- [ ] Wave C : tests+build+push+PR
- [ ] Wave D : 4 plans Phase B documentés (BACKLOG owner-decision)
- [ ] **Vitest 580+/580+** (au moins 580 tests cumulés post Wave A)
- [ ] **PHPUnit 32+/32+** (au moins +0 régression)
- [ ] **Build production** OK
- [ ] **0 régression Wave A+B**
- [ ] **Frozen-zones 24/24** intact
- [ ] **CV1 fiscal compliance** intact
- [ ] **Branche** poussée + **PR** créée
- [ ] **Graphiti** push final

---

## §8 — Effort total cumulé

| Wave | Items | Effort | Parallélisable | Wall-clock |
|---|---|---|---|---|
| A | 7 | 4h | ✅ 3 sub-agents | ~1.5h |
| B | 4 | 1h15 | ✅ 2 sub-agents | ~40min |
| C | 4 | 30min | ❌ séquentiel | 30min |
| D | 4 plans | 1h docs | ✅ 2 sub-agents | ~30min |
| **TOTAL** | **19** | **6h45** | — | **~3h30 wall-clock** |

---

## §9 — Décision orchestrateur

**GO** sur l'exécution Wave A immédiate (mode auto autorisé, P1 sibling tasks critiques + P2 perf/UX justifient).

**STOP** Wave D (Phase B BACKLOG) — owner gate explicit requis car touche frozen-zones.

**Wave B+C** suivent dès Wave A complete + tests verts.

— *Le plan est fait. L'exécution peut commencer. La discipline GStack garantit qu'on n'oublie rien et qu'on ne brise rien.*
