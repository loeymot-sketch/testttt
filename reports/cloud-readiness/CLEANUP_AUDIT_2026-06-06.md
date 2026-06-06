# FoodKing POS — Audit de nettoyage (adversarial-verified)
**2026-06-06 · 3 agents cartographes + 2 agents adversaires (RED) · worktree pre-cloud-exec**

Méthode : 3 agents read-only ont cartographié (routing/redirect, pages site-web, candidats nettoyage) ; 2 agents adversaires hostiles ont tenté de PROUVER que chaque suppression casserait le POS/KDS/borne/admin. Tout est cité `file:line`, vérifié par grep. Rien n'est supprimé sans preuve d'absence d'impact.

---

## 1. PROBLÈME #2 (redirect + pages site-web) — CAUSE RACINE TROUVÉE

**Les deux symptômes (login → `/home`, ET pages site-web visibles) ont la MÊME cause :** le drapeau `staff_only_mode` était **`false`** au runtime sur le cloud (`config(features.staff_only_mode)=false`, `STAFF_ONLY_MODE` absent du `.env` live). Ce drapeau est le mécanisme *intégré* du logiciel pour le mode « caisse seule, pas de site client » : il redirige toutes les routes client vers /login, masque le chrome client, et fait atterrir le login sur l'écran métier.

- Le redirect par rôle est **déjà codé** (`LoginController.php:174-179` + colonne `roles.landing_url`) et la **DB live est déjà bien semée** : Admin→dashboard, Chef→kitchen-display-system, POS Operator→pos ; tous les rôles ont le grant `dashboard` (pas de risque de boucle/lockout).
- `/home` n'était qu'un **fallback legacy** (`LoginComponent.vue:174`) qui ne se déclenche QUE si `staff_only_mode=false`.

**→ CORRIGÉ (Wave 1, live 2026-06-06)** : `STAFF_ONLY_MODE=true` + `config:cache`. Prouvé : `/home`→/login, `/offers`(connecté)→/admin/dashboard, login admin→/admin/dashboard (plus jamais /home).

### Option « supprimer le code » (au-delà du drapeau)
Le drapeau rend les pages **inaccessibles** (objectif atteint, réversible, zéro risque). Si suppression PHYSIQUE du code voulue, les agents adversaires ont établi qu'elle est SÛRE **uniquement avec** ces edits que le plan naïf oubliait (sinon build cassé / liens morts) :
- **Build-breakers** : `store/index.js` (imports+registrations+vuex-persist des 5 modules client — casse app.js ET pos-app.js), `authRoutes.js` (imports Signup/Guest).
- **Liens morts dans le code GARDÉ** : `BackendNavbarComponent.vue:444` (logout staff → route supprimée, casse CHAQUE déconnexion), `:7` + `BackendMenuComponent.vue:8` (logo admin), `NotFoundComponent.vue:6`, `DefaultComponent.vue:110/72`.
- Recommandation superviseur : **garder le drapeau comme solution primaire** (c'est la couture conçue, sûre, réversible). Hard-delete = polish optionnel, à faire seulement si tu veux le bundle plus léger — je le ferai sur ton accord, proprement, avec re-test e2e.

---

## 2. SAFE À SUPPRIMER — vérifié 0 référence (P1, HIGH confidence)

### A. 9 composants Vue admin orphelins (importés nulle part, WIP mai 2026)
`CustomerStatsComponent.vue`, `StockLowAlertsWidget.vue`, `TopCustomersComponent.vue` (admin/dashboard) · `IngredientAvailabilityToggleComponent.vue` (admin/ingredients) · `ItemPhotoUpload.vue`, `composer/StepEditorComponent.vue`, `composer/StepPreviewComponent.vue`, `wizard/ProductCreateWizardComponent.vue` (admin/items) · `TimeSlot/TimeSlotComponent.vue` (admin/settings). Aucun `require.context`/glob auto-register → 0 risque runtime.

### B. Seeders démo/morts (référencés seulement en commentaires)
`GrillHouseMenuSeeder.php`, `GrillHouseMenuImagesSeeder.php`, `CompleteFrenchMenuSeeder.php` (resto démo « GrillHouse » de la template ; throw-on-run) + 7 seeders 0-référence : `MenuTruncateTableSeeder`, `NotificationAlertTableSeederTwo`, `NotificationTableSeederTwo`, `SiteTableSeederOne`, `PaymentGatewayTableSeederVersionTwo`, `SmsGatewayTableSeederVersionTwo`, `PermissionTableSeederVersionTwo`. ⚠️ GARDER `OrderTableSeederVersionTwo` (utilisé par `FreshOrderSeed.php:34`).

### C. Fichiers `.bak`/scratch trackés
`webpack.mix.js.bak.w1b`, `resources/views/master.blade.php.bak.w1b`, `tmp_e2e_01.js`, `public/images/theme/_archive_pre_le_cayenne_v2/*.png.bak` (3), `reports/.../lifecycle-driver.php.bak`.

### D. Screenshots scratch à la racine (P2) + artefacts dev (P3)
~9 PNG trackés (`adv-A-*.png`, `tmp-z-report*.png`, etc.) + ~20 non-trackés → supprimer + `.gitignore`. `.playwright-mcp/` (84 .yml, pas dans .gitignore) → ignore+purge. `_archive/`, `audit-*-2026-05-03/` (+ zip 2.2 Mo), exports divers → détritus de session.

---

## 3. ⚠️ NE PAS supprimer à l'aveugle — étude approfondie requise (UNCERTAIN)

**Backend e-commerce câblé que la BORNE partage** (la borne passe par `FrontendOrderController`, `MenuController::kiosk`, `LoyaltyController`, `PromoController`, `CouponController`, `kioskUpsell` — `routes/api.php`). Donc **Order/Payment/Pricing/Coupon/Upsell/Loyalty = GARDER** (supprimer casserait la borne + fausserait NF525) :
- Delivery-boy (controllers + `DeliveryBoyCashSession/Movement` sous BranchScope, NF525-adjacent), Subscriber/newsletter, SMS gateway, passerelles paiement étrangères (Paypal/Stripe/Senangpay — V1=SumUp/espèces), CMS (Page/Slider/Offer/Cookies).
- Ces éléments ne sont **pas visibles** par l'utilisateur (le drapeau masque déjà le front). Leur retrait = chirurgie routes+permissions+FK dans un système fiscal → bénéfice faible, risque élevé. **Recommandation : laisser, ou étudier dans une passe dédiée owner-gated.**

## 4. 🚫 NE JAMAIS toucher (frozen §7)
`pos-wizard.js`/`.css`/`admin-pos-v4.blade.php`, `Kiosk*Component.vue`, `PaymentComponent.vue`, `PosV5TrancheRow.vue`, `app/Services/Fiscal/*`, `BranchScope`, `PricingService`, `OrderStateMachine`, `IdempotencyKeyMiddleware`, suite `tests/`.

---

## Recommandation d'exécution (ordre risque croissant)
1. **Wave 1 drapeau = FAIT** (résout #2 fonctionnellement, live, prouvé).
2. P1 catégories C+D (junk/scratch) — zéro surface fonctionnelle.
3. P1 catégories A+B (composants orphelins + seeders morts) — re-grep chaque basename juste avant suppression, puis `npm run prod` pour confirmer build propre.
4. Hard-delete du site-client (option §1) — seulement sur accord, avec les edits adversariaux + re-test e2e.
5. UNCERTAIN §3 — laisser / passe dédiée.
