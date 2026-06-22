# Plan Train 5/6 - Nettoyage FR/demo + E2E + hardware release - 2026-04-27

Scope:
- Nettoyer residus visibles et seeders sans casser historique.
- Prouver les flows finaux sur navigateur et hardware.

## 1. Mission L1 - French runtime and demo data dry-run

TASK_ID: `FK-REM-L1-FR-RUNTIME-DEMO-DRYRUN`

Objectif:
- Corriger ce que l'utilisateur voit.
- Produire une commande dry-run avant toute purge.

Allowlist:

```text
app/Console/Commands/CleanupDemoDataCommand.php
app/Services/BranchService.php
database/seeders/CurrencyTableSeeder.php
tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php
reports/audit/FK_REM_L1_FR_RUNTIME_DEMO_DRYRUN.md
```

Actions:
- Liste branches demo/Faker.
- Liste users rattaches branches demo.
- Liste categories/items fake/inactifs.
- Liste devises non EUR.
- Liste languages non V1.
- Aucune suppression par defaut.

Validation:

```bash
php artisan foodking:cleanup-demo-data --dry-run
php artisan test tests/Feature/Sentinels/FrenchRuntimeNoBangladeshDemoDataSentinelTest.php
```

## 2. Mission L2 - Seeders FR V1

TASK_ID: `FK-REM-L2-SEEDERS-FR-V1`

Objectif:
- `migrate:fresh --seed` ne recree plus les donnees Bangladesh/demo visibles.

Allowlist:

```text
database/seeders/UserTableSeeder.php
database/seeders/OrderAddressTableSeeder.php
database/seeders/OrderTableSeederVersionTwo.php
database/seeders/KdsOrderTableSeeder.php
database/seeders/CurrencyTableSeeder.php
database/seeders/LanguageTableSeeder.php
tests/Feature/Sentinels/FrenchSeedersNoLegacyDemoSentinelTest.php
```

Regles:
- `EUR` doit etre devise principale.
- `fr` actif; `en` fallback technique si necessaire.
- Pas de `Dhaka Bangladesh`, `+880`, `BDT` dans seed V1.
- Donnees demo si utiles doivent etre francaises.

## 3. Mission L3 - Payment gateways quarantine

TASK_ID: `FK-REM-L3-PAYMENT-GATEWAYS-QUARANTINE`

Objectif:
- Une route de gateway absente ne doit jamais casser `route:list`.
- Gateways non-France desactivees par config, pas supprimees brutalement.

Allowlist:

```text
config/payment.php
routes/api.php
app/Providers/RouteServiceProvider.php
app/Http/PaymentGateways/Routes/senangpay.php
tests/Feature/Sentinels/PaymentGatewayRoutesNoMissingClassesSentinelTest.php
reports/audit/FK_REM_L3_PAYMENT_GATEWAYS_QUARANTINE.md
```

Validation:

```bash
php artisan route:list --path=payment
php artisan test tests/Feature/Sentinels/PaymentGatewayRoutesNoMissingClassesSentinelTest.php
```

## 4. Mission L4 - I18n bundle FR-first

TASK_ID: `FK-REM-L4-I18N-FR-FIRST`

Objectif:
- La V1 client charge FR en priorite.
- `bn/de/ar` ne sont pas dans le runtime client si langues desactivees.

Allowlist:

```text
resources/js/i18n.js
resources/js/languages/fr.json
resources/js/languages/en.json
lang/fr/*
lang/en/*
tests/js/i18nAuditTool.spec.js
reports/i18n/*
```

Interdictions:
- Ne pas supprimer physiquement `lang/en`.
- Ne pas supprimer fichiers langue hors gate; d'abord retirer imports runtime.

Validation:
- Bundle ne charge pas `bn.json` pour V1 FR.
- FR keys principales presentes.

## 5. Mission E1 - Playwright flows full sync

TASK_ID: `FK-REM-E1-PLAYWRIGHT-FULL-SYNC`

Flows obligatoires:

1. Admin modifie produit/prix/image -> POS et kiosk voient update.
2. Admin met item rupture -> kiosk affiche rupture, POS bloque/override.
3. Kiosk commande cash -> POS live list voit -> KDS voit apres accept.
4. POS commande emporter sans client -> pas de Client ID manuel -> ticket.
5. POS commande livraison avec adresse libre -> frais 5 EUR fallback.
6. POS commande livraison avec distance 5.01 km simulee -> 6 EUR.
7. KDS bump preparation/ready -> OSS update.
8. POS handover -> OSS retire.
9. Queue: deux commandes meme branche -> numeros distincts.
10. Kiosk ne peut pas acceder admin.

Allowlist:

```text
tests/Playwright/sync-flow-admin-edits-kiosk-sees.spec.js
tests/Playwright/sync-flow-kiosk-orders-pos-sees.spec.js
tests/Playwright/sync-flow-pos-order-walkin-delivery.spec.js
tests/Playwright/sync-flow-kds-bump-oss-update.spec.js
tests/Playwright/sync-flow-kiosk-admin-locked.spec.js
reports/e2e/FK_REM_E1_PLAYWRIGHT_FULL_SYNC_2026-04-27.md
```

Validation:

```bash
npm run production
npx playwright test tests/Playwright/sync-flow-admin-edits-kiosk-sees.spec.js
npx playwright test tests/Playwright/sync-flow-kiosk-orders-pos-sees.spec.js
npx playwright test tests/Playwright/sync-flow-pos-order-walkin-delivery.spec.js
npx playwright test tests/Playwright/sync-flow-kds-bump-oss-update.spec.js
npx playwright test tests/Playwright/sync-flow-kiosk-admin-locked.spec.js
```

## 6. Mission E2 - Payment simulation

TASK_ID: `FK-REM-E2-PAYMENT-SIMULATION`

Objectif:
- Prouver cash/card/deferred/refused flows sans vrai TPE.

Tests:
- POS cash: montant recu >= total.
- POS card: note/reference requise.
- Kiosk cash: pending/accept selon regle existante.
- Kiosk card: pending until confirm.
- Refused payment: no order finalization.
- Duplicate transaction ref rejected.

Allowlist:

```text
tests/Feature/Payment/*
tests/Feature/PaymentConfirmCrossBranchTest.php
tests/js/paymentComponent401Retry.spec.js
tests/js/kioskPaymentRetryGate.spec.js
reports/audit/FK_REM_E2_PAYMENT_SIMULATION.md
```

## 7. Mission H1 - Hardware lab UAT

TASK_ID: `FK-REM-H1-HARDWARE-LAB-UAT`

Gate:
- `HG-HARDWARE-LAB-SIGNOFF`.

Checklist:
- Borne physique en mode plein ecran.
- Commande complete borne.
- Impression ticket client.
- Caisse voit commande live.
- KDS cuisine recoit ticket.
- TPE simulation ou vrai terminal selon decision.
- Ticket cuisine.
- Handover POS.
- Reboot reseau: banner degrade coherent.

Artefacts:

```text
reports/hardware/FK_REM_H1_HARDWARE_LAB_UAT_2026-04-27.md
reports/hardware/photos_or_notes/*
docs/gates/GATE_HARDWARE_LAB_SIGNOFF_2026-04-27.md
```

## 8. Mission R1 - Release report GO/NO-GO

TASK_ID: `FK-REM-R1-RELEASE-GO-NOGO`

Inputs:
- Train 0..6 reports.
- PHPUnit full.
- Vitest full.
- Playwright full.
- Hardware lab.
- Safety-check.
- `git diff --check`.

Output:

```text
reports/release/FOODKING_V1_GO_NOGO_2026-04-27.md
```

Decision:
- `GO`: deploy/canary possible.
- `NO-GO`: blockers P0/P1 list.

## 9. Interdictions globales cleanup/release

1. Pas de `rm -rf`.
2. Pas de purge DB sans backup + gate.
3. Pas de suppression `reports/`, `missions/`, `docs/gates/`, `memory/`.
4. Pas de bypass browser/security.
5. Pas de paiement reel sans confirmation action-time.
6. Pas de stockage mot de passe/carte.

## 10. Closeout attendu

Rapport:

```text
reports/audit/FK_REM_TRAIN3_CLEANUP_E2E_RELEASE_CLOSEOUT_2026-04-27.md
```

Le systeme est pret seulement si:
- demo cleanup runtime OK;
- seeders FR OK;
- payment route list OK;
- Playwright full OK;
- hardware lab signe;
- release report GO.
