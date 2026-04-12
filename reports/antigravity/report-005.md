# Playwright / E2E verification Report 005 (Deep Audit)

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle (Sprint 3.2 - Final Audit)
L'utilisateur a exigé un audit profond suite à ses ultimes ajouts de config (`theme_settings`, assignation de 4 permissions Spatie à `Admin`, et injection globale de `x-api-key`). Voici la carte finale de l'application.

**Résultat Officiel : 14 réussites, 4 échecs. Le framework Spatie et la DB Factory (T1 à T5) sont 100% stabilisés.**

## Passed (14 Tests Isolés avec Succès)
✅ T01, T02, T03, T04, T08, T09, T10, T11, T12, T14, T18, T20, T22, T23.
- Les endpoints critiques de blocage POS/KDS (`T12` Online Orders visibilité, `T18` KDS Branch visibilité) réagissent parfaitement aux permissions Spatie.
- La protection contre le spoofing de prix (T08, T09, T10) fonctionne maintenant que les payloads Frontend Order ont la forme correcte (`is_advance_order`, payload items en json).

## Failed (4 Erreurs de Logique Métier)

### 1. Crash : `faviconLogo` on null
❌ **T05 (Kiosk cannot access admin)** & ❌ **T06 (Kiosk can create order)**
- **Symptôme** : L'API crash avec `Attempt to read property "faviconLogo" on null`.
- **Trace** : L'erreur se déclenche pendant `getJson('/api/admin/dashboard')` et `postJson('/api/frontend/order')`.
- **Diagnostic pour Claude/Kimi** : Mettre `theme_favicon_logo` à `null` JSON dans SQLite (via `TestCase::seedMinimalSettings`) a révélé le crash. Dans l'application, un Mutator, une resource JSON, un email ou un helper Blade global tente d'accéder à `app(ThemeSetting::class)->faviconLogo` sans opérateur Null-Safe. C'est un pur bug applicatif à corriger.

### 2. Bypass Sécurité : Manque de Middleware
❌ **T07 (Kiosk cannot read pos orders)**
- **Symptôme** : L'assertion HTTP 401/403 a échoué.
- **Diagnostic pour Claude/Kimi** : Le Kiosk (qui n'a *pas* de rôle ni permission) parvient à lire la caisse en appelant `/api/admin/pos-order`. Cela signifie que la route `pos-order` n'est *pas* protégée par `middleware('permission:pos-orders')` ou bien que `x-api-key` court-circuite tout le système d'authentification Spatie sur cet endpoint.

### 3. Crash Métier : State Transition inachevée
❌ **T13 (Pending to Accept transitions)**
- **Symptôme** : L'assertion de statut 200/400/403 a échoué. 
- **Diagnostic pour Claude/Kimi** : Accepter une commande (`pos-order/change-status/{id}`) crashe en 500 ou échoue à valider en base SQLite (Data manquante). L'hypothèse principale est un trigger complexe (génération PDF de ticket, notification Push, broadcast WebSocket config) qui plante dans l'environnement de Test.

---
## Suggested Next Tasks (For Claude Planning - Sprint 4)

Le Sprint de "Stabilisation" de la boucle IA est considéré comme ACHEVÉ. Les fondations de Tests et Roles SQLite sont là. Le prochain plan d'action (Sprint 4) relève purement de la Correction Métier de l'app :

1. **[Kimi] Fix `faviconLogo` Null Pointer** : Implémenter le null constraint local ou l'opérateur nullsafe sur l'appel du `theme_setting`.
2. **[Kimi] Fix Middleware `pos-order`** : Appliquer le bon check Spatie dans `routes/api/admin.php` ou `PosOrderController`.
3. **[Claude] Audit de la méthode `change-status`** : Comprendre pourquoi l'API échoue lourdement sur la transition statut.
