# PR-07 — `env()` runtime hors `config/` (casse sous `config:cache`) — CLOUD-PREP

**Gravité (mandat owner)** : P2 (cloud-prep — nul en local non-caché ; bloquant cloud).
**Risque d'exécution** : ⚠️ PARTIEL — 1 fix sûr maintenant ; le « sweep » complet = refactor séparé, dont **1 cas NF525-FROZEN**.

---

## §1 — Problématique + cause racine
`env()` appelé hors `config/` retourne le **défaut** (pas la valeur `.env`) une fois `php artisan config:cache` exécuté → bugs silencieux en prod/cloud. On a déjà corrigé `staffOnlyMode` aujourd'hui. **Découverte adversariale : il reste 35 `env()` runtime hors `config/`**, dont des hot-paths (devise/date à CHAQUE rendu) et **1 NF525-critique frozen**.

## §2 — TOUS les fichiers concernés (vérifiés)
**Fix sûr maintenant (PR-07 strict) :**
- **MODIFIER** `resources/views/master.blade.php:184` (`env('KIOSK_USE_POS_WIZARD')` → `config('kiosk.use_pos_wizard')`)
- **MODIFIER** `config/kiosk.php` (ajouter `use_pos_wizard => filter_var(env('KIOSK_USE_POS_WIZARD', true), BOOL)` — **défaut true**)
- **SUPPRIMER** `resources/views/master.blade.php.bak.w1b` (backup stale, 0 référence) + `resources/views/.DS_Store`
- **Consommateur (vérifier vert) :** `resources/js/router/modules/kioskRoutes.js:177` ; `tests/e2e/06-staff-only-routing.spec.js:73-79` ; `tests/Feature/StaffOnlyRoutingTest.php:25`

**Sweep cloud-prep (DOC/backlog — NE PAS faire dans PR-07) — 35 env() :**
- `app/Libraries/AppLibrary.php:24,32,40,48,56,289,298,299,308,313,404,432` (DATE/TIME/CURRENCY — **hot-path chaque rendu**)
- `app/Http/Resources/{OrderItemResource:52,SettingResource:95}` ; `OtpManagerService:76` ; `LanguageService:112` ; controllers DEMO (Signup:62, ItemController:137, SiteController:34) ; `Nexmo:43` ; `HealthzController:131` ; `InstallerController:29,135` ; `WizardPerItemDemo:30`
- 🔴 **`app/Services/Fiscal/AuditLogService.php:273`** `env('FISCAL_AUDIT_SECRET_BRANCH_'.$id)` — **NF525-CRITIQUE + FROZEN §7** : sous config:cache → null → **secret HMAC change → chaîne d'audit cassée**. → cloud-blocker prioritaire, **LOCK + gate owner**, NE PAS toucher dans PR-07.
- `app/Providers/AppServiceProvider.php:331,441,464` — `env()` en **boot** = exception Laravel légitime (avant cache) → laisser.

## §3 — Solution + raisonnement fort
**PR-07 strict = (a) le seul blade env() restant + (b) nettoyage.** Le « sweep » des 35 est un **refactor cloud-prep séparé** (hot-paths sensibles), pas ce PR.
- `kioskUsePosWizard` est un **vrai switch de comportement** (kioskRoutes.js:177 choisit `KioskPosWizardComponent` vs `KioskWizardComponent`) → **PAS mort**. Clé config **défaut true** obligatoire (sinon spec 06 casse + le wizard kiosk swappe en prod).
- `.bak.w1b` : 0 référence, Laravel ne compile que `*.blade.php` → suppression sûre.
**Raisonnement** : changer le mécanisme env→config est neutre en dev (config non cachée) ET correct en prod (cachée) **ssi** la valeur résolue reste `true`. Avec `.env=true` + défaut `true` → true partout.

## §4 — Simulation d'impact
- Dev (config non cachée) : `config('kiosk.use_pos_wizard')` lit `.env`=true → identique. Spec 06 vert.
- Prod (cachée) : lit la clé config (true) au lieu de env()→null → **corrige** le swap silencieux du wizard.
- Suppression `.bak.w1b`/`.DS_Store` : aucun effet runtime.

## §5 — ⚔️ Analyse adversariale (effets négatifs)
| # | Effet | Preuve | Sévérité |
|---|---|---|---|
| 🔴 N1 | **`AuditLogService:273` casse la chaîne HMAC** sous config:cache (NF525). | AuditLogService.php:273 (frozen §7) | **CRITIQUE cloud** → LOCK+gate, hors PR-07 |
| N2 | Si la clé config défaut = false → spec 06 casse + **wizard kiosk swappe en prod**. | kioskRoutes.js:177 ; spec 06:73 | HIGH → défaut **true** obligatoire |
| N3 | Le « sweep » naïf des 35 = gros refactor hot-path (AppLibrary devise/date) → **ne PAS** le plier dans PR-07. | AppLibrary.php:24-432 | MEDIUM (scope) |
| 🔴 N4 | **Lancer `config:cache` sur la boîte LIVE** casserait les 35 env() sur le serveur en cours (prix/dates faux). | bootstrap/cache absent ; analogue `dump-autoload` interdit §3ter | **CRITIQUE** → ne jamais faire en live |
| ✅ | `.bak.w1b` suppression sûre (0 ref) ; JS `process.env.MIX_*` = build-time, pas un bug runtime. | grep bak.w1b = 0 ; bootstrap.js:302 | — |

## §6 — Ajustements pour ZÉRO effet négatif
1. Clé config **défaut true** : `'use_pos_wizard' => filter_var(env('KIOSK_USE_POS_WIZARD', true), BOOL)` (corriger le typo → `KIOSK_USE_POS_WIZARD`), garder `.env=true`.
2. **Jamais `config:cache` sur la boîte live** ; vérifier en test avec env unset (assert `config('kiosk.use_pos_wizard')===true`) OU checkout isolé/CI.
3. Scope (c) « sweep » = **inventaire/backlog cloud-prep**, pas ce PR.
4. `AuditLogService:273` → ticket cloud-blocker séparé sous LOCK (frozen).

## §7 — NE PAS toucher / RESPECTER
- ❌ `app/Services/Fiscal/AuditLogService.php:273` — FROZEN NF525, LOCK+gate. Flag, ne pas fixer.
- ❌ `AppServiceProvider` boot-guards (331/441/464) — env() en boot légitime, laisser.
- ❌ `resources/js/bootstrap.js` `process.env.MIX_*` — build-time, pas runtime.
- ❌ **Jamais `config:cache` sur le serveur dev en cours.**
- ❌ Ne pas élargir le payload `window.foodkingConfig` ni toucher d'autres clés.
- Frozen/NF525/PricingService intouchés.

## §8 — Acceptation + rollback
- **Accept** : test (env unset) prouve `config('kiosk.use_pos_wizard')===true` ; spec 06:73 vert ; `.bak.w1b`/`.DS_Store` supprimés ; `git diff` = 2 fichiers + 2 deletions. Sweep des 35 documenté en backlog cloud, `AuditLogService` flaggé LOCK.
- **Rollback** : revert master.blade.php + config/kiosk.php (par fichier) ; restaurer le `.bak` depuis git si besoin.
