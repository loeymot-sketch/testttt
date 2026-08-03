# AUDIT P-MEGA-20 (W8.A.1) — K-6 `branch_mismatch` / kiosk — baseline READONLY

- **Date** : 2026-04-20
- **HEAD référencé** : `8070bc357`
- **Plan** : `plans/PLAN_P_MEGA_W8_2026-04-20.md` section W8.A
- **Mode** : lecture seule
- **Subagent** : `explore` very thorough

---

## Résumé exécutif

**Verdict** : GAP sécurité / observabilité — correctifs requis (alignement K-6.2). Les routes `/api/frontend/kiosk-event` et `/api/frontend/kiosk/event` appliquent bien `auth:sanctum`, `abilities:kiosk:order` et `throttle:30,1` ; l'`user_id` en base reste celui du token. En revanche, **`KioskEventController::store()` n'implémente pas l'enforcement K-6.2** : la chaîne `details` affiche `branch=` à partir du **payload** s'il est présent, contredisant le commentaire « toujours lu depuis KioskMachine ». Le canal Monolog **`security` existe** mais **n'est pas utilisé** par ce contrôleur pour les mismatchs. Les tests `KioskEventBranchIsolationTest` cristallisent l'ancien comportement (forgé visible dans `branch=`). Pas de `KioskEventBranchSpoofingTest` comme au référentiel p93. Recommandation : `foodking-complex-implementer` (zone `branch_id` + auth-adjacent).

---

## 1. Périmètre cartographié

### 1.1 Contrôleurs Kiosk

| Fichier | Rôle | LOC |
|---------|------|-----|
| `app/Http/Controllers/Frontend/KioskEventController.php` | POST `/kiosk-event` + `/kiosk/event` | ~262 |
| `app/Http/Controllers/Auth/KioskMachineLoginController.php` | Login borne + token Sanctum `kiosk:order` | ~116 |
| `app/Http/Controllers/Admin/KioskMachineController.php` | CRUD bornes (admin) | hors hot path |
| `app/Http/Controllers/Admin/KioskSetupController.php` | Setup admin | hors hot path |

Frontend kiosk « branch serveur » explicite (pattern sain via `KioskMachine`) : `MenuController.php`, `PricingPreviewController.php`, `PromoController.php`, `UpsellController.php`, `LoyaltyController.php`.

### 1.2 Routes API (préfixe `frontend`, middleware `installed,apiKey,localization`)

| Méthode | Chemin | Middleware | Contrôleur |
|---------|--------|------------|------------|
| POST | `/api/frontend/kiosk-event` | `auth:sanctum`, `abilities:kiosk:order`, `throttle:30,1` | `KioskEventController@store` |
| POST | `/api/frontend/kiosk/event` | idem | idem |
| GET | `/api/frontend/menu` | `auth:sanctum`, `throttle:kiosk-menu`, `kiosk.locale` | `MenuController@kiosk` |
| POST | `/api/frontend/pricing/preview` | `auth:sanctum`, `throttle:60,1`, `kiosk.locale` | `PricingPreviewController` |
| POST | `/api/frontend/order` | `auth:sanctum`, `throttle:kiosk-orders` | `OrderController@store` |
| POST | `/api/auth/kiosk-login` | `throttle:login-lockout` | `KioskMachineLoginController@login` |

### 1.3 Logging (`config/logging.php`)

- Canal **`security`** : présent — daily, `storage/logs/security.log`, 90 jours, niveau info (lignes ~128-146)
- **KioskEventController n'appelle PAS `Log::channel('security')`** pour les mismatchs

### 1.4 Tests existants

| Fichier | Couverture |
|---------|------------|
| `tests/Feature/KioskSecurity/KioskEventAbilityTest.php` | Alias + `kiosk:order` + 401 |
| `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` | **Attend** branch forgé dans `branch=` (à corriger) |
| `tests/Feature/KioskEventTest.php` | Happy path |
| **Absent** | `KioskEventBranchSpoofingTest` (référence p93) |

---

## 2. Vulnérabilités identifiées

### V1 — Intégrité logs / `branch` non autoritaire (MEDIUM)

**OWASP** : A09:2021 — Security Logging and Monitoring Failures.

```php
$request->input('branch_id', $machine?->branch_id ?? 'unknown')
```

Si client envoie `branch_id` forgé, `details` reflète le forgé. Pas d'élévation de privilège sur `user_id`, mais **corrélation SOC faussée**.

**Écart vs p93** : `REPORT_TASK15_SECURITY_K6_2026-04-20.md` décrit un bloc `[K-6.2]` avec `serverBranchId`, `branchMismatch`, log `security` — **absent ici**.

### V2 — Pas d'alerte structurée `branch_mismatch` sur canal `security` (MEDIUM)

**OWASP** : A09 (détection/réponse). Canal configuré mais non utilisé.

### V3 — Divergence doc/implémentation (LOW)

### V4 — Tests qui cristallisent l'ancien comportement (LOW dette)

`KioskEventBranchIsolationTest::test_analytics_event_with_forged_branch_id_logs_real_branch_A` assert `branch=<branchBId>` au lieu du serveur-autoritaire.

### V5 — Routes sans `abilities:kiosk:order` au niveau route (LOW)

`MenuController@kiosk`, `LoyaltyController@scan` refont `tokenCan` dans le contrôleur. Cohérence "fail-closed middleware" non systématique.

### V6 — Cross-tenant data leak via `branch_id` sur KioskEvent (informational)

`ActionLog.user_id` reste l'utilisateur du token. Pas d'IDOR sur ce flux ; risque = intégrité logs.

### V7 — Throttle `kiosk-orders` IP-only (informational pour W8.A, scope W8.B)

Clé `->by($request->ip())` sans `user_id` discrimination.

---

## 3. Recommandations implémentation

1. **Source de vérité** : `KioskMachine::where('user_id', $user->id)->first()->branch_id` (cast int)
2. **`details`** : afficher `branch=<serverBranchId>` toujours (ou `branch_server=`)
3. **Forensic** : si `claimedBranchId` ≠ `serverBranchId`, méta structurée `branch_id_claimed`
4. **Alerte** : `Log::channel('security')->warning(...)` avec clés stables `event=kiosk.branch_mismatch`, `server_branch_id`, `claimed_branch_id`, `user_id`, `machine_id`, `route`, `request_id`
5. **Décision produit** : 200 maintenu (observabilité) vs 422 (breaking) — gate humain

### Fichiers EXECUTE probables

- `app/Http/Controllers/Frontend/KioskEventController.php` (write bloc K-6.2)
- `tests/Feature/KioskPhase7/KioskEventBranchIsolationTest.php` (aligner assertions)
- `tests/Feature/KioskSecurity/KioskEventBranchSpoofingTest.php` (NEW)
- `config/logging.php` (no-op si `security` déjà OK)

**OFF-LIMITS** : OrderService, FrontendOrderService, migrations branches, composants Vue W5.

---

## 4. Tests sentinelles requis

### Minimum (DoD)

1. **Match/absence** : token branche A, pas de `branch_id` ou `branch_id == A` → pas de log `security`, `details` contient `branch=A`
2. **Mismatch** : token branche A, `branch_id = B` → log `security` + `branch=A` autoritaire dans `ActionLog`

### Cas additionnels (p93)

- Couverture des deux routes (`/kiosk-event` et `/kiosk/event`)
- `Log::fake()` + assertion canal `security`
- Hardware types : canal `hardware` ne masque pas l'alerte `security`

---

## 5. Estimation effort + moteur

| Volet | Estimation |
|-------|------------|
| Prod (KioskEventController K-6.2) | ~60-100 LOC |
| Tests (2 fichiers) | ~120-180 LOC |

**Moteur** : `foodking-complex-implementer` (GPT-5.4) — `branch_id` + auth-adjacent.

---

## 6. Gates humaines à déclarer

- **HARD GATE** : modification KioskEventController (zone branch_id + logs sécurité)
- **HARD GATE** : changement sémantique HTTP (200 vs 422 sur mismatch) — décision produit
- Coordination ops si parsing `ActionLog.details` legacy

---

## 7. Risques résiduels post-fix

- Parsing legacy `branch=` peut casser → période double-écriture ou doc migration
- Volume logs `security` si front bugué → sample/rate limit
- Spoofing `branch_id` sur création commande (FrontendOrderService) NON couvert ici (OFF-LIMITS W8) — audit séparé requis

---

## 8. Routes kiosk énumérées (DoD)

`POST /api/auth/kiosk-login`, `POST /api/frontend/kiosk-event`, `POST /api/frontend/kiosk/event`, `GET /api/frontend/menu`, `POST /api/frontend/pricing/preview`, `POST /api/frontend/promo/validate`, `GET /api/frontend/upsell`, `POST /api/frontend/loyalty/scan`, `POST /api/frontend/order`, `GET /api/frontend/item/kiosk-upsell`, `POST /api/frontend/device-token/kiosk`.

---

## 9. Synthèse

Renforcer K-6.2 dans `KioskEventController`, utiliser le canal `security` déjà présent, mettre à jour les tests Feature pour que `branch=` soit serveur-autoritaire et que les mismatchs soient détectables sans ambiguïté opérationnelle.
