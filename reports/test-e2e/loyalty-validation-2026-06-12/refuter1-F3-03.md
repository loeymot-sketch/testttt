# REFUTER n°1 — F3-03 (LoyaltySetupService ne dispatch pas SettingsUpdated)

Date: 2026-06-12 · Harnais :8767 / foodking_e2e · Rôle: réfutation adversariale indépendante

## Étape 1 — file:line (grep/Read) : CONFIRMÉ
- `app/Services/LoyaltySetupService.php` méthode `update()` (set au niveau ligne 32) :
  `Settings::group('loyalty_setup')->set($request->validated());` — AUCUN `SettingsUpdated::dispatch`.
  Ni `use App\Events\SettingsUpdated` dans le fichier.
- Contrôleur appelant `app/Http/Controllers/Admin/LoyaltySetupController.php::update` : ne dispatch pas non plus (vérifié Read complet).
- Pattern frère confirmé par grep : `OrderSetupController.php:37`, `CurrencyController.php:38/50/62`,
  `CompanyController.php:36`, `TaxController.php:39/51/63`, `SiteController.php:40` → tous `SettingsUpdated::dispatch([...])` (Wave 5G R9 2026-05-17).
- `app/Enums/EventType.php:38` : `SETTINGS_UPDATED = 'settings.updated'` (dans `all()` ligne 77).

## Étape 2 — repro live :8767 : REPRODUIT
- Login `POST /api/auth/login` (x-api-key e2e) → token admin OK.
- Baseline tinker (foodking_e2e): `DomainEvent::where('event_type','settings.updated')->count()` = **18** (dernier id 9191).
- `PUT /api/admin/setting/loyalty-setup` body `{"loyalty_points_per_euro":1,"loyalty_points_for_1_euro_discount":100,"loyalty_min_redeem_points":50}` → **HTTP 200**, data renvoyée.
- Recount après 2s : **18** → **0 nouvelle row** settings.updated.
- **Contrôle positif** (prouve que le bus outbox marche dans CE harnais, pas un flake) :
  `PUT /api/admin/setting/order-setup` (mêmes valeurs) → HTTP 200 → recount = **19**,
  dernière row id 9206 payload `{"branch_id":1,"changed_keys":["order_setup"]}`.
- Conclusion repro : loyalty_setup échappe au bus alors que le chemin outbox est vivant → repro de l'agent CONFIRMÉE.

## Étape 3 — impact réel (sévérité)
- Backend = toujours frais : `app/Http/Controllers/Frontend/LoyaltyController.php:204,383,511-514,1007`
  lisent `Settings::group('loyalty_setup')` À CHAQUE requête → accrual/redeem/validation min_redeem
  calculés avec le NOUVEAU barème dès le save. **Aucun point/euro faux n'est jamais persisté.**
- Borne écran fidélité : `KioskLoyaltyComponent.vue:420-431` refetch `frontend/loyalty/config` à CHAQUE `mounted()`
  (chaque entrée dans l'écran), pas seulement au boot → staleness fenêtre ≈ une session déjà ouverte.
- Seule surface vraiment reload-bound : `KioskConfirmationComponent.vue:211` lit `lists?.loyalty_points_per_euro`
  (lists cachées au boot de la page) → l'APERÇU « points gagnés » peut être périmé jusqu'au reload borne ;
  le crédit réel reste correct (serveur).
- POS : redeem calculé serveur (LoyaltyController:383) ; affichage potentiellement périmé seulement.
- Changement de barème = opération admin rare, mono-poste V1 LOCAL, auto-guérison au remount/reload.

## Étape 4 — dedup : NON-DUP
- grep reports/+plans/ : seule mention = la lane F3 elle-même (`F3-sync-setup.md:56`, même campagne).
- K6-loyalty-cascade (05-23) et SYS-G (05-28) mentionnent LoyaltySetupService mais PAS ce gap (0 hit SettingsUpdated).
- Pas dans release/v1 lots A-H ni dashboard-deep 06-08.

## VERDICT
- **refuted = false** — le finding est réel, file:line exact, reproduit avec contrôle positif.
- **corrected_sev = P3** (downgrade depuis P2) : la formulation « Borne et POS gardent l'ancien taux jusqu'à reload »
  est sur-cotée — le seul effet est de l'affichage périmé (aperçu points/seuils) auto-guéri au remount,
  les calculs serveur étant toujours frais. Gap de cohérence avec le pattern Wave 5G R9 = vrai, impact = polish.
- Recommandation de l'agent (dispatch après set()) reste valide telle quelle.
