# W-E AUTH / RBAC — Validation profonde (GOAL 100%) — 2026-06-10

- Branche : `heal/we-auth-validation-2026-06-10` (worktree dédié, base `heal/pre-cloud-exec-2026-06-05` @ d3cad4195)
- Serveur : http://127.0.0.1:8766 (clone jetable `foodking_e2e`, cache redis partagé)
- Spec : `tests/e2e/zz-auth-rbac-2026-06-10.spec.js` (serial, 8 tests)
- Résultat : **Cycle 1 = 8/8 PASS · Cycle 2 = 8/8 PASS (convergé)**
- Frozen-zone diff : **0 ligne**. NF525 chain : **CHAIN OK** (seuls ajouts = `user.login` admin, append normal).
- Captures JPEG q70 : `captures/cycle-1/` + `captures/cycle-2/` (12 chacune), analysées via Read.
- Fixtures E2E créées puis **purgées** : users `e2e-we-lock@test.fr`, `e2e-we-rbac@test.fr` (rôle POS Operator), machine kiosk `e2e-we-kiosk` (id 13), token `kiosk:order` sur admin. **admin@lecayenne.fr et kiosk-lecayenne JAMAIS lockés.**

## Tableau parcours → statut → preuve

| # | Parcours | Statut | Preuve |
|---|----------|--------|--------|
| E1a | Login admin OK (FR) → logout → re-login | ✅ PASS | login 201 → /admin/dashboard → logout → re-login 201 ; page FR (Bon Retour/Email/Mot De Passe/Connexion) ; `e1a-*.jpg` |
| E1b | Mauvais mot de passe (user dédié) → msg FR générique, pas de fuite « user exists » | ✅ PASS | HTTP 400 « Identifiants invalides ou compte bloqué » ; corps **identique** user-existant vs ghost (anti-énumération) ; `e1b-1-wrong-password-fr.jpg` |
| E1c | Lockout sur user dédié → 429 → purge | ✅ PASS | seed bucket `login-lockout` au plafond (env e2e=500) → 1 tentative réelle → **HTTP 429** ; UI affiche le blocage ; purge → login 201. ⚠ message EN (voir AUTH-E1C-EN) ; `e1c-1-lockout-ui.jpg` |
| E2a | Page /kiosk/login | ✅ PASS | auto-login borne (IP de confiance) → redirige `/kiosk/idle` « Bienvenue ! » FR, branding Cayenne ; `e2a-1-kiosk-login-page.jpg` |
| E2b | Kiosk login machine — mauvais mot de passe / diagnostic F3 | ✅ PASS (test) / ⚠ **defect divulgué** | actif+wrong == username-inconnu (générique 400) ✅ ; **inactif+wrong → « Cette borne est désactivée… » ≠ générique** → fuite d'état (voir AUTH-F3-SPINE) ; relogin 201 |
| E3a | RBAC UI : POS Operator → /admin/administrators + /admin/settings/site | ✅ PASS | les 2 routes **redirigent vers /admin/dashboard** (guard `handlePermissionDenied`), nav admin-only absent du menu, **0 fuite, 0 page blanche** ; `e3a-2/3-*.jpg` |
| E3b | API : token kiosk:order → GET /api/admin/pos-order | ✅ PASS | **HTTP 403 `{"error":"token_ability_insufficient"}`** (middleware `BlockKioskTokenFromAdminRoutes`) ; sans token → 401 |
| E4 | Logout → back button → pas de dashboard | ✅ PASS | back → page login vide (Bon Retour) ; accès direct /admin/dashboard → redirige /login ; `e4-1/2-*.jpg` |

## Findings P0–P3

### AUTH-F3-SPINE — P2 — fuite d'état borne (anti-énumération absente sur la spine)
- **Fichier** : `app/Http/Controllers/Auth/KioskMachineLoginController.php:66-83` (spine `heal/pre-cloud-exec-2026-06-05`)
- **Comportement** : l'ordre des contrôles est `status machine` (l.66-70) → `user lié` (l.72-77) → **`Hash::check` (l.79-83)**. Un appelant non-authentifié envoyant un **mauvais mot de passe** sur une borne **inactive** reçoit `kiosk_machine_inactive` (« Cette borne est désactivée… ») au lieu du message générique. Cela divulgue l'existence + l'état d'une borne **sans connaître le mot de passe** → énumération de usernames/bornes.
- **Preuve** : `api-evidence-cycle{1,2}.json` → `e2b_inactive_wrong_pw.body` ≠ `e2b_active_wrong_pw.body`.
- **État du fix F3** : **ABSENT sur la spine. PRÉSENT sur le checkout principal `testttt`** : `KioskMachineLoginController.php:65-71` porte `[F3 heal 2026-06-09]` — `Hash::check` AVANT tout contrôle d'état, tous les autres échecs → `credentials_invalid` générique.
- **Fix disponible (1 bloc, déjà mergé sur main, non-frozen)** : déplacer le bloc `Hash::check` (l.79-83) juste après le lookup not-found (l.59-63), avant les contrôles status/user. Ne touche aucune zone gelée. → relève de la **réconciliation des branches fragmentées** (pre-cloud-exec vs main), pas de cette vague test.

### AUTH-E1C-EN — P3 — message de lockout en anglais (viole mandat FR ADR-007)
- **Fichier** : `app/Providers/RouteServiceProvider.php` (limiter `login-lockout`, ~l.262) : message hardcodé `"Too many login attempts. Please try again later."` retourné en 429.
- **Preuve** : `e1c-1-lockout-ui.jpg` (bandeau EN sur page sinon 100% FR) + `e1c_lockout_429` evidence.
- **Note** : le limiter kiosk (`kiosk-login`) a le même souci (« Too many kiosk login attempts… »). Cosmétique mais incohérent avec le reste FR.

## Observations (non-defect)
- POS Operator voit le **dashboard complet avec KPIs CA** comme landing de refus — conforme au modèle « staff landing » existant, hors scope strict E3 (les routes admin ciblées sont bien refusées).
- E2a : sur box de confiance la borne s'auto-authentifie (IP `127.0.0.1` trusted) → comportement V1 LOCAL attendu, pas un bypass.
- Console 401 pendant E1a/E4 = rafale de widgets dashboard après logout (attendu) ; 400 E1b / 429 E1c = attendus.

## Verdict
Tous les parcours auth/RBAC **fonctionnent et sont sûrs** (anti-énumération login admin OK, lockout OK, RBAC UI OK, blocage token kiosk→admin OK, session post-logout OK). **1 P2 documenté** (F3 absent sur la spine — fix trivial déjà sur main) + **1 P3** (message lockout EN). Aucun P0/P1.
