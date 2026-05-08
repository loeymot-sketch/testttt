# ADR-007 — Kiosk locale policy : FR-lock V1

**Status :** Accepted (orchestrator decision iter2 `2026-05-08`, Option A)
**Branche :** `claude/blissful-mclean-c915c2`
**Auteur :** Claude orchestrateur (mode Architect Frontend FoodKing — YC GStack HEAL P0)
**Reviewers :** sub-agent A11y ultra-deep audit + advisor reconcile

> ADR = Architecture Decision Record. Documente les décisions structurantes avec leur **contexte, alternatives considérées, conséquences**.

---

## Contexte

Le sub-agent A11y a identifié une **cascade de contradiction architecturale** sur la borne kiosk :

- `resources/js/i18n.js:5` — `KIOSK_LOCALE = 'fr'` immuable
- `resources/js/i18n.js:65 ensureKioskLocale()` — force `fr` sur chaque nav `/kiosk/*`
- `resources/js/router/index.js:163-169` — invoque `ensureKioskLocale()` dans `beforeEach`
- `KioskIdleScreenComponent.vue:14-33` — rendait un sélecteur de langue FR/EN/AR avec `setLocale(lang)` + `window.location.reload()` → **dead UI** : la locale repassait à `fr` immédiatement après reload (via `ensureKioskLocale` au prochain navigate)
- `KioskIdleScreenComponent.vue:181-188 voiceLang` — mappait `currentLocale → fr-FR / en-US / ar-SA`, mais en runtime kiosk la valeur était **toujours `fr-FR`** (dead branches `en-US` / `ar-SA`)
- `resources/js/languages/ar.json` — clés `kiosk.voice.consent_*`, `kiosk.builder.*`, `kiosk.admin.*` absentes (cohérent avec un FR-lock effectif, mais non documenté → fallback silencieux sur `fr`)

Cette contradiction crée une dette UX (sélecteur visible mais inopérant), une dette code (branches mortes), et une dette docs (contrat fallback `ar.json` partial implicite).

## Décision

**Option A — FR-lock policy V1.** Le kiosk reste en français immuable :

1. `KIOSK_LOCALE = 'fr'` reste immuable.
2. `ensureKioskLocale()` continue de reforcer `fr` sur chaque nav `/kiosk/*`.
3. Le sélecteur de langue dans `KioskIdleScreenComponent.vue` est **désactivé** (`v-if="false"`) avec marqueur `[FR-LOCK 2026-05-08 ADR-007]`.
4. Le computed `voiceLang` retourne **toujours** la constante `'fr-FR'` (dead branches `en-US` / `ar-SA` supprimées explicitement).
5. La méthode `changeLanguage(lang)` devient un no-op marqué `[FR-LOCK]`, conservée dans le call-graph pour permettre la réactivation V2 sans diff structurel.
6. Les imports `setLocale` / `getCurrentLocale` et la data `enabledLanguages` / `loadSettings` (`data.kiosk_languages_enabled`) restent en place : le sélecteur est **hidden, pas supprimé**. Smallest correct change wins.
7. `ar.json` reste partial : fallback automatique sur `fr` via `vue-i18n` `fallbackLocale: DEFAULT_LOCALE`. Aucune retraduction massive en V1.

## Rationale

- **Marché V1 = France.** Conformité fiscale **NF525** + audit logs en français. Multi-locale réel n'apporte pas de valeur métier sur ce périmètre.
- **Cohérent avec design intent.** `ensureKioskLocale()` traduit déjà la décision « kiosk borne = fr immuable ». La présence du sélecteur dans l'UI était un vestige PHASE-37 incohérent.
- **Effort multi-locale réel = 1-2j hors scope V1** :
  - Move complet des clés `kiosk.*` dans `ar.json` (~150 strings)
  - Styles RTL complets sur tous les wizards (frozen-zones — bloque la réactivation immédiate)
  - Scope `setLocale` stable (sessionStorage par device, pas localStorage global)
  - Tests régression RTL sur les 5 écrans Phase 3
- **Voice flow `fr-FR` acceptable** sur kiosk France (Web Speech API supporte bien le `fr-FR` natif).

## Alternatives rejetées

### Option B — Multi-locale réel
- **Coût** : 1-2j ingénieur + audit RTL complet + retraduction massive `ar.json`.
- **Risk** : touche les frozen-zones wizards (KioskWizard / Categories / Upsell / PromoCarousel / OrderSummary / ProductList) pour les styles RTL.
- **Hors scope V1** : aucune demande métier formalisée sur le marché France.

### Option C — Laisser le bug
- **Dette UX** : sélecteur visible mais dead → confusion utilisateur final.
- **Dette code** : 2 branches mortes (`en-US`, `ar-SA`) dans `voiceLang`.
- **Dette docs** : `ar.json` partial sans contrat explicite → fallback silencieux.
- Rejected : un audit suivant re-flagguerait le même bug.

## Conséquences

- ✅ Sélecteur UI désactivé sur kiosk idle (admin peut le réactiver via flag `KIOSK_LOCALE` mutable + suppression `v-if="false"` si la policy se relâche).
- ✅ `voiceLang` toujours `'fr-FR'` (cohérent runtime + tests régression possibles).
- ✅ `ar.json` partial = fallback `fr` automatique (config `vue-i18n` existante).
- ✅ Aucune frozen-zone touchée.
- ✅ Path de réactivation V2 préservé : imports, data, méthodes restent en place.
- ⚠️ Le drawer A11y `KsA11ySettings.vue` expose **encore** un sélecteur FR/EN/AR (cf. `kioskA11ySettingsDrawer.spec.js:55-71` — radios `kiosk-a11y-lang-{fr,en,ar}` qui dispatchent `kioskSettings/setLocale`). Sous FR-lock cette dispatch est défaite par `ensureKioskLocale()` à la prochaine nav. **Phase 2 ci-dessous**.

## Phase 2 — Known follow-up (BACKLOG immédiat)

`KsA11ySettings.vue` est la **prochaine surface FR-lock** à traiter :

- **Symptôme** : 3 radios FR/EN/AR qui dispatchent `kioskSettings/setLocale` → reset par `ensureKioskLocale()` à la nav suivante.
- **Action recommandée** :
  - Soit masquer le bloc langue du drawer (`v-if="false"` + commentaire `[FR-LOCK]`)
  - Soit garder le bloc visible mais désactiver les radios (visual feedback `cursor: not-allowed` + tooltip « V1 France only »)
- **Décision orchestrateur reportée** : à trancher dans une prochaine itération HEAL ; ne **pas** étendre silencieusement la présente PR.

## BACKLOG V2 — Multi-locale réel

Quand la policy sera relâchée (demande métier formalisée hors France) :

1. Déverrouiller `KIOSK_LOCALE` (passer en `let` + lecture device-scoped sessionStorage).
2. Compléter `ar.json` clés `kiosk.voice.consent_*` / `kiosk.builder.*` / `kiosk.admin.*` (~150 strings).
3. Audit RTL complet sur frozen wizards.
4. Réactiver le sélecteur idle (`v-if="enabledLanguages.length > 1"`).
5. Réactiver la logique `changeLanguage` (retirer le no-op `[FR-LOCK]`).
6. Restaurer le switch `voiceLang` (`fr/en/ar → fr-FR/en-US/ar-SA`).
7. Aligner `KsA11ySettings` sur la nouvelle policy.
8. Tests régression RTL sur les 5 écrans Phase 3 + tous les wizards.

---

## Fichiers touchés (cycle 2026-05-08 iter2)

- `resources/js/i18n.js` — commentaire policy ajouté en tête de `KIOSK_LOCALE`
- `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` :
  - Sélecteur `<div class="kiosk-lang-selector">` → `v-if="false"` + commentaire `[FR-LOCK]`
  - `voiceLang` computed → constante `'fr-FR'`
  - `changeLanguage(lang)` méthode → no-op `[FR-LOCK]`
- `tests/js/kioskIdleScreen.spec.js` — créé : 2 tests régression FR-lock
- `docs/ADR/ADR-2026-05-09-kiosk-locale-policy.md` — ce document
