# PR-02 — Dégradation sync VISIBLE (KDS + POS + OSS)

**Gravité (mandat owner)** : **P0** — « silencieux = grave ». La cuisine/le comptoir ne savent pas qu'ils sont en retard quand le push tombe.
**Risque d'exécution** : FAIBLE (fichiers KDS/POS non-frozen ; flags additifs).

---

## §1 — Problématique + cause racine
Le bandeau « mode polling » du KDS est masqué dès `appEnv === 'local'`. Or **la vraie boîte Le Cayenne tourne aussi en `APP_ENV=local`** → quand soketi tombe en plein service, la cuisine n'a **aucun indice visuel** qu'elle est en repli ~30-60s.
- `KitchenDisplaySystemComponent.vue:1314-1321` computed `kdsHideFallbackBannerInLocalDev()` → `return appEnv === 'local'`.
- Usages : `:40` (`fallback-mode` vers `KdsV2Grid` → `KdsStatusBanner`) et `:70` (div legacy `data-testid=kds-sync-mode-banner`).

**⚔️ Découverte adversariale clé** : le **même masquage existe sur 2 autres surfaces** (pas que KDS) :
- `PosOrdersTrackerComponent.vue:478-485` — `isDevEnv` masque le warn temps-réel POS (**user-facing**).
- `ConnectionStatusBanner.vue:73-83,89` — `isDevEnv` masque le bandeau offline (monté sur OSS + KDS legacy).
→ **Fixer juste KDS ≠ régler la dégradation silencieuse.**

## §2 — TOUS les fichiers concernés (vérifiés)
**À MODIFIER :**
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` (computed 1314-1321 + usages 40, 70)
- `resources/views/master.blade.php` (exposer le flag, à côté de `FK_KDS_V2_DEFAULT_ENABLED` ligne ~233 ; `appEnv` ligne 189)
- `config/kds.php` (nouvelle clé `show_fallback_banner`, miroir de `v2_default_enabled:24-28`)
- **(option surface partagée)** `PosOrdersTrackerComponent.vue:478-485` + `ConnectionStatusBanner.vue:73-83`
**À CRÉER :**
- 1 spec Playwright : V2 + WS down → `KdsStatusBanner` « SYNC · LOCAL » visible (comble le trou de couverture).
**Lus (prop chain — ne pas restructurer) :** `KdsV2Grid.vue:26`, `KdsStatusBanner.vue:101-108,132-208`.

## §3 — Solution + raisonnement fort (design opt-out, PAS opt-in)
⚠️ Le « flip default-true en remplaçant appEnv » est un **piège** : ça ramène le bruit sur **toutes** les machines dev (soketi volontairement off en dev → bandeau permanent).
**Design correct (fail-safe-to-visible)** : nouvelle clé `KDS_SHOW_FALLBACK_BANNER` (défaut **true**) dans `config/kds.php`, exposée `window.FK_KDS_SHOW_FALLBACK_BANNER` dans `master.blade.php` (même pattern que `FK_KDS_V2_DEFAULT_ENABLED`). Le computed devient :
`masquer SEULEMENT si appEnv==='local' ET FK_KDS_SHOW_FALLBACK_BANNER === false`.
→ **Boîte restaurant** (pas d'override) : bandeau VISIBLE. **Dev** : ajoute `KDS_SHOW_FALLBACK_BANNER=false` dans son `.env`. L'état dangereux (dégradation silencieuse) est **opt-out**, jamais opt-in. Renommer le computed (`kdsSuppressFallbackBanner`) car « InLocalDev » ne décrit plus.
**Raisonnement** : le défaut doit rendre l'état risqué VISIBLE ; seul le dev choisit le silence. Conserver l'exclusion `'testing'` (CI Playwright en `local` mais suites `testing` voient le bandeau).

## §4 — Simulation d'impact
- Boîte Le Cayenne, soketi down → `wsConnected=false` + pas d'override → `KdsStatusBanner` affiche « SYNC · LOCAL » → la cuisine SAIT. ✅
- Machine dev (override `=false`) → silence comme avant. ✅
- CI Playwright (`local`, pas d'override) → bandeau visible mais specs en OR-locator → restent verts.

## §5 — ⚔️ Analyse adversariale (effets négatifs)
| # | Effet | Preuve | Sévérité |
|---|---|---|---|
| N1 | « default-true en remplaçant appEnv » ramène le **bruit dev** (bandeau permanent en dev). | comment 1301-1313 ; ConnectionStatusBanner.vue:73-83 | HIGH (design) → corrigé par l'opt-out §3 |
| N2 | Surfaces POS+OSS **encore silencieuses** si on ne fixe que KDS. | PosOrdersTrackerComponent.vue:478 ; ConnectionStatusBanner.vue:73 | HIGH (couverture) |
| N3 | **Trou de test** : aucun spec n'exerce le fallback V2 (le testid `kds-sync-mode-banner` n'existe QUE dans le legacy `v-else:70`, jamais en V2). Le fix réel est non vérifié par la CI. | abuse-C-kds.spec.js:369 passe car V2 omet le testid | MEDIUM |
| ✅ | **Pas de double-bandeau** (V2 ne monte que `KdsStatusBanner` ; legacy gère déjà l'exclusivité). **Pas de casse layout** (`.ws-reconnect-banner` in-flow 6px ; `KdsStatusBanner` height 32px, reserveRightGutter). **Aucun spec vert ne casse** (6 specs en OR-locator / soft record). | KdsV2Grid.vue:22-27 ; KdsStatusBanner.vue:132-208 | — |

## §6 — Ajustements pour ZÉRO effet négatif
1. **Discriminateur opt-out** (clé `KDS_SHOW_FALLBACK_BANNER` défaut true ; masquer seulement si `local AND flag===false`).
2. **Ajouter le spec V2 manquant** (WS down → `KdsStatusBanner` visible) — sinon fix non vérifié.
3. **Décider le périmètre POS/OSS explicitement** : soit (a) UN flag partagé `FK_SHOW_DEGRADED_BANNERS` consommé par KDS + `PosOrdersTrackerComponent:478` + `ConnectionStatusBanner:73`, soit (b) scoper PR-02 à KDS + ouvrir un suivi nommé POS/OSS. Ne pas laisser « KDS fixé » se lire « dégradation réglée ».

## §7 — NE PAS toucher / RESPECTER
- Ne pas restructurer la prop chain `KdsV2Grid:26 → KdsStatusBanner:101-108` (déjà correcte) ; juste lui passer le bon `fallbackMode`.
- Conserver l'exclusion `'testing'` (ne pas régresser le bandeau en suites CI `testing`).
- Ne pas supprimer le testid legacy `kds-sync-mode-banner:70` (6 specs s'y ancrent).
- Ne PAS transformer le `console.warn` OSS (`OssSyncService.js:261-263`) en bandeau dans ce PR (scope creep, surface différente).
- Aucun fichier frozen/NF525 ; aucun impact fiscal.

## §8 — Acceptation + rollback
- **Accept** : capture KDS réelle, soketi down, sur boîte « restaurant » → bandeau « SYNC · LOCAL » VISIBLE (lue + analysée) ; dev override → silence ; nouveau spec V2 vert ; `tests/Feature/Kds/*` + 6 specs existants verts ; décision POS/OSS tranchée.
- **Rollback** : `KDS_SHOW_FALLBACK_BANNER=false` (retour comportement actuel) ou revert du computed (1 fichier). Aucune logique métier touchée.
