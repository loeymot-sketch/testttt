# RUN — V14 T16 HARDWARE_DRAWER_NFC — 2026-04-20

## Statut

**PASSED** — périmètre T16 livré ; validations demandées exécutées.

## Fichiers livrés

| Fichier | Action |
|---------|--------|
| `app/Services/Hardware/EscPosCommandBuilder.php` | `openDrawerCommand()` + `openDrawer()` délégué |
| `app/Services/Hardware/EscPosPrinterService.php` | `openDrawer(?int $printerId, int $branchId): array` (pas d’exception) |
| `app/Http/Controllers/Admin/Pos/CashDrawerController.php` | **NEW** — POST cash drawer |
| `app/Http/Controllers/Admin/Pos/CustomerNfcLookupController.php` | **NEW** — POST lookup NFC (query `User::role('Customer')` — morph Spatie sur `User`) |
| `app/Models/Customer.php` | **NEW** — sous-classe `User` + `nfc_uid` fillable |
| `database/migrations/2026_04_20_220000_add_nfc_uid_to_customers.php` | **NEW** — `users.nfc_uid` + unique `(branch_id, nfc_uid)` |
| `routes/api.php` | Routes `pos/cash-drawer/open`, `pos/customers/lookup-by-nfc` |
| `resources/js/services/posNfc.js` | **NEW** — Web NFC (`scanOnce` : handlers avant `scan()`) |
| `resources/js/store/modules/posCustomer.js` | **NEW** — `lookupByNfc` |
| `resources/js/store/index.js` | Enregistrement module `posCustomer` |
| `resources/js/languages/en.json` | 4 clés i18n |
| `resources/js/languages/fr.json` | 4 clés i18n |
| `resources/js/languages/ar.json` | 4 clés i18n |
| `tests/Feature/EscPosOpenDrawerTest.php` | **NEW** — 3 tests |
| `tests/Feature/CustomerNfcLookupTest.php` | **NEW** — 3 tests |
| `tests/js/posNfc.spec.js` | **NEW** — 3 tests |
| `tests/Feature/PrinterServiceTest.php` | Adapté à la nouvelle signature `openDrawer` |

## Endpoints

- `POST /api/admin/pos/cash-drawer/open` — body optionnel `{ "printer_id": <int> }` — permission `pos`
- `POST /api/admin/pos/customers/lookup-by-nfc` — body `{ "nfc_uid": "<string ≤64>" }` — permission `pos`

## Delta schéma DB

- Table `users` : colonne `nfc_uid` VARCHAR(64) nullable ; index unique `users_branch_nfc_uid_unique` sur `(branch_id, nfc_uid)`.

## Tests exécutés

| Suite | Résultat |
|-------|----------|
| `php artisan test tests/Feature/EscPosOpenDrawerTest.php tests/Feature/CustomerNfcLookupTest.php` | **6/6** OK |
| `php artisan test tests/Feature/PrinterServiceTest.php` (régression T15) | **9/9** OK |
| `npx vitest run tests/js/posNfc.spec.js` | **3/3** OK |
| `npx vitest run tests/js/pos*.spec.js` | **117/117** OK (nombre total courant du dépôt, pas de régression) |
| `php artisan test --filter='Pos|Order|Pricing|Floorplan|Printer'` | **381 passed**, **3 failed** (hors régression T16 — voir ci-dessous) |

## Régression PHP (filtre large)

Échecs **pré-existants** / hors périmètre T16 :

1. `Tests\Feature\DispatchAfterCommitTest` — 2 scénarios rollback (sentinelle dispatch-after-commit connue).
2. `Tests\Feature\Orders\OrderAllergenSnapshotComposedTest::sentinel base item plus extra with milk should snapshot lait` — dette back / allergène (FINDING_BACK_DEFERRED).

Aucun nouvel échec attribuable aux fichiers T16.

## Notes techniques

- Sélection d’imprimante « reçu » : champ `station = 'receipt'` (le schéma `printers.type` reste `escpos_tcp` / etc., aligné T15).
- Lookup NFC : utilisation de `User::role('Customer')` car les rôles Spatie sont attachés au morph `App\Models\User`, pas à la sous-classe `Customer`.
- `EscPosPrinterService::openDrawer` envoie uniquement `openDrawerCommand()` (sans `init()`), conforme au cahier des charges T16.

## Risques / TODO résiduels

- UI POS (boutons tiroir / scan NFC) non branchée dans cette livraison — i18n et services prêts pour intégration `PosComponent` / paiement dans un cycle ultérieur.
- Web NFC : support navigateur limité (Chrome/Edge, contexte sécurisé).

## Validation post-exéc

- Développeur : exécuter `.cursor/hooks/post-execute.sh` si présent et mettre à jour `reports/post_execute_latest.log` avec `EXECUTE_DELEGATION: foodking-routine-implementer` selon convention projet.
