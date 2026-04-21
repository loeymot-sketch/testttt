# V14 #12 — T21 — `P14_POS_RECEIPT_REDESIGN`

## Header

```
TASK_ID: V14_12_T21_POS_RECEIPT_REDESIGN
WAVE: C-β — Finalisation caisse opérateur (sub-vague β)
GATE_REFERENCE: aucun (extension Branch + ReceiptComponent + OrderDetailsResource exposition uniquement, pas de mutation NF525)
PRIMARY_MODEL: Composer (foodking-routine-implementer) — assemblage présentation
RUNNER_MODE: single-session
PARALLEL_WITH: V14_10_T15_HARDWARE_PRINTER_ESC_POS, V14_11_T19_POS_TABLE_FLOORPLAN
DEPENDS_ON: aucun (HTML preview only — l'integration ESC/POS T15 viendra après)
SEVERITY: P1
EFFORT_EST: 0.5 j
```

## Contexte

Le reçu POS actuel (`resources/js/components/admin/pos/ReceiptComponent.vue`, 264 LOC) est correct fonctionnellement et déjà conforme à un certain niveau NF525 grâce au backend (`tax_lines` exposés via `OrderDetailsResource::buildTaxLines()` couvre CGI art. 242 nonies A — vérifié par `tests/Feature/PosReceiptTaxLinesTest.php`).

**Ce qui manque pour conformité NF525 + ergonomie pro** (gap T21) :
1. **En-tête légal** : SIRET et n° TVA intra de la branche **NE SONT PAS** dans le modèle `Branch` actuel (`grep` confirme : pas de champ `siret`, `vat_intra`, `tva_intra` dans `app/Models/Branch.php` lignes 14-18 fillable)
2. **N° caisse / opérateur** : actuellement absent du reçu
3. **N° ticket NF525** : `fiscal_sequence_no` existe en DB (`OrderService` + `FiscalSequenceService`) mais N'EST PAS exposé dans `OrderDetailsResource` ni rendu sur le ticket
4. **Hash chaîne audit** : `audit_logs` existe (`AuditLogService`) mais le **fingerprint du dernier audit log de la commande** n'est pas exposé sur le ticket
5. **Multi-tender visuel** : si la commande a plusieurs paiements (cash + CB + TR), le reçu actuel ne les affiche pas séparément
6. **Largeur 80mm** : design actuel `max-w-[340px]` correspond à du 58mm ; besoin d'une variante 80mm (≈ 480px)
7. **Footer NF525** : URL self-care, mentions légales, hash extract

T21 livre une **extension non-destructive du reçu HTML** :
- Pas de touch backend pricing/payment (frozen)
- Ajout de champs **lecture seule** dans `Branch` + `OrderDetailsResource`
- Refonte CSS + structure de `ReceiptComponent.vue` (responsive 58/80mm)
- Helper FE partagé `posReceiptBuilder.js` (pour préparation future intégration T15)

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_20_xxxxx_add_fiscal_identity_to_branches.php` (CREATE — siret, vat_intra, register_id, legal_footer)
- `app/Models/Branch.php` (EDIT — ajouter 4 champs au $fillable + casts)
- `app/Http/Resources/OrderDetailsResource.php` (EDIT — exposer `fiscal_sequence_no`, `audit_chain_fingerprint`, `pos_register_id`, `operator_name`, `payments_breakdown[]`)
- `app/Services/Receipt/ReceiptDataService.php` (CREATE — assemble les données enrichies pour la vue ; pas de mutation, lecture seule)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (EDIT — refonte structure avec NF525 footer + multi-tender + variantes 58/80mm)
- `resources/js/helpers/posReceiptBuilder.js` (CREATE — pure-functions de formatting pour usage futur ESC/POS bridging)
- `resources/js/languages/fr.json` / `en.json` / `ar.json` (EDIT — clés UI receipt nouvelles uniquement : `label.siret`, `label.vat_intra`, `label.register_id`, `label.operator`, `label.fiscal_ticket_no`, `label.audit_fingerprint`, `label.legal_mentions`, `label.tendered_breakdown`)
- `tests/Feature/Branch/BranchFiscalIdentityTest.php` (CREATE — 3 cas)
- `tests/Feature/PosReceiptFiscalExposureTest.php` (CREATE — 4 cas exposition resource)
- `tests/js/posReceiptBuilder.spec.js` (CREATE — 5 cas helper FE)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php`, `FrontendOrderService.php` (LOCK_B frozen)
- `app/Services/Pricing/PricingService.php` (frozen — pricing SSOT)
- `app/Services/PaymentService.php` (frozen — gate C9)
- `app/Services/Fiscal/FiscalSequenceService.php` (frozen — chaîne fiscale)
- `app/Services/Fiscal/AuditLogService.php` (lecture seule autorisée pour `audit_chain_fingerprint`, JAMAIS d'écriture)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen)
- `resources/js/components/admin/pos/ItemComponent.vue` (frozen)
- `resources/js/components/admin/pos/PosComponent.vue` (territoire T19 en parallèle)
- `app/Services/Hardware/*` (territoire T15 en parallèle — T21 ne dépend PAS de T15)
- `resources/js/services/posPrinter.js` (territoire T15)
- `resources/js/components/admin/posOrders/PosOrderReceiptComponent.vue` (variante alternative — out of scope ; on aligne uniquement le reçu post-paiement principal)
- `resources/js/helpers/kioskPrinter.js` (Kiosk Electron — référence lecture seule)

## INVARIANTS_AT_RISK

1. **Pricing SSOT** : T21 N'EFFECTUE AUCUN CALCUL de prix/TVA. Lecture pure depuis `order.tax_lines`, `order.subtotal_without_tax_currency_price`, `order.total_currency_price`, etc. Aucune méthode `compute*` ajoutée côté FE/BE.
2. **NF525 non-mutation** : `fiscal_sequence_no` et `audit_chain_fingerprint` sont exposés en lecture pure. Aucune écriture dans `audit_logs` ni dans `FiscalSequenceService` depuis T21.
3. **Backward compat** : si une commande legacy n'a pas de `fiscal_sequence_no` (ancienne donnée pré-NF525), le ticket affiche "—" ou ne rend pas la ligne (pas de crash). Idem si `payments` est vide → fallback sur `pos_payment_method` actuel.
4. **Multi-tenant** : `Branch` siret/vat_intra accessibles uniquement via le scope branche courante (déjà géré par BranchScope existant).
5. **Hash audit ne fuite PAS de secret** : `audit_chain_fingerprint` = derniers 12 caractères du `signature` HMAC ou un hash dérivé non-réversible. JAMAIS le HMAC complet, JAMAIS la clé. Implémentation : `substr(hash('sha256', $auditLog->signature . $auditLog->id), 0, 12)`.
6. **Migration idempotente** : `Schema::hasColumn` defensive. Rollback dropColumn safe.
7. **i18n** : 8 nouvelles clés exactes dans fr/en/ar. Pas de touch à d'autres clés.
8. **Pas de breaking visuel** : la ré-architecture du composant doit préserver `printObj`, le selector `#print`, le wrapper `modal-dialog`, la directive `v-print`. Les tests Vitest/Playwright existants qui ciblent ces éléments doivent continuer de passer.
9. **CSS impression** : `@media print` hide les contrôles ; les variants `.receipt-58mm` / `.receipt-80mm` ont des `width` correctement spécifiés en `mm` (pas `px`) pour rendu imprimante fidèle.
10. **Lecture audit_logs sécurisée** : si la table `audit_logs` n'est pas remplie pour la commande (ex : preuves non générées), le champ retourne `null` sans crash.

## TÂCHES À EXÉCUTER

### 1. Migration `add_fiscal_identity_to_branches`

```php
return new class extends Migration {
    public function up(): void {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'siret')) {
                $table->string('siret', 14)->nullable()->after('zone');
            }
            if (! Schema::hasColumn('branches', 'vat_intra')) {
                $table->string('vat_intra', 16)->nullable()->after('siret');
            }
            if (! Schema::hasColumn('branches', 'register_id')) {
                $table->string('register_id', 32)->nullable()->after('vat_intra'); // n° caisse logique de la branche
            }
            if (! Schema::hasColumn('branches', 'legal_footer')) {
                $table->string('legal_footer', 255)->nullable()->after('register_id');
            }
        });
    }
    public function down(): void {
        Schema::table('branches', function (Blueprint $table) {
            foreach (['legal_footer', 'register_id', 'vat_intra', 'siret'] as $col) {
                if (Schema::hasColumn('branches', $col)) $table->dropColumn($col);
            }
        });
    }
};
```

### 2. Modèle `Branch` (EDIT — ajouter 4 champs)

```php
protected $fillable = [
    'name', 'email', 'phone', 'latitude', 'longitude',
    'city', 'state', 'zip_code', 'address', 'zone', 'status',
    'available_locales',
    'siret', 'vat_intra', 'register_id', 'legal_footer', // T21
];
protected $casts = [
    /* existing */
    'siret'        => 'string',
    'vat_intra'    => 'string',
    'register_id'  => 'string',
    'legal_footer' => 'string',
];
```

### 3. `OrderDetailsResource` (EDIT — exposer NF525 + multi-tender + opérateur)

Ajouter dans le tableau `toArray()` (après les champs existants, sans rien retirer) :
```php
'fiscal_sequence_no'      => $this->fiscal_sequence_no ?? null,
'audit_chain_fingerprint' => $this->buildAuditFingerprint(), // private helper, voir ci-dessous
'pos_register_id'         => optional($this->branch)->register_id,
'pos_siret'               => optional($this->branch)->siret,
'pos_vat_intra'           => optional($this->branch)->vat_intra,
'pos_legal_footer'        => optional($this->branch)->legal_footer,
'operator_name'           => optional($this->user)->name ?? null,
'payments_breakdown'      => $this->buildPaymentsBreakdown(),
```

Helpers privés (à mettre dans la classe `OrderDetailsResource`) :
```php
private function buildAuditFingerprint(): ?string
{
    try {
        $log = \App\Models\AuditLog::where('order_id', $this->id)
            ->orderByDesc('id')->first();
        if (! $log || ! $log->signature) return null;
        return substr(hash('sha256', (string) $log->signature . '|' . $log->id), 0, 12);
    } catch (\Throwable $e) {
        return null;
    }
}

private function buildPaymentsBreakdown(): array
{
    if (! $this->relationLoaded('payments') && method_exists($this, 'payments')) {
        $payments = $this->payments()->get();
    } else {
        $payments = $this->payments ?? collect();
    }
    return $payments->map(function ($p) {
        return [
            'method'           => $p->method ?? $p->payment_method ?? null,
            'amount'           => (float) ($p->amount ?? 0),
            'currency_amount'  => $p->currency_amount ?? null,
            'change_amount'    => (float) ($p->change_amount ?? 0),
            'reference'        => $p->reference ?? null,
        ];
    })->values()->toArray();
}
```

**ATTENTION** : si la table `payments` ou la relation `payments()` n'existe pas dans le modèle `Order` actuel, fallback gracieux (le helper retourne `[]`). Vérifier d'abord avec `Schema::hasTable('payments')` ou `method_exists($order, 'payments')`. **NE PAS créer la relation** si elle n'existe pas (out of scope T21). Dans ce cas, exposer `payments_breakdown` = synthèse depuis `pos_payment_method` + `pos_received_amount` + `cash_back_amount` (1 entrée).

### 4. `ReceiptDataService` (CREATE — backend lecture seule)

```php
namespace App\Services\Receipt;

use App\Models\Order;

final class ReceiptDataService
{
    /**
     * Assemble all data needed by the printed receipt for an order.
     * Pure read. No mutation. No pricing computation.
     */
    public function buildForOrder(int $orderId): array
    {
        $order = Order::with(['branch', 'user'])->findOrFail($orderId);
        return [
            'order_id'              => $order->id,
            'order_serial_no'       => $order->order_serial_no,
            'fiscal_sequence_no'    => $order->fiscal_sequence_no ?? null,
            'pos_register_id'       => optional($order->branch)->register_id,
            'pos_siret'             => optional($order->branch)->siret,
            'pos_vat_intra'         => optional($order->branch)->vat_intra,
            'pos_legal_footer'      => optional($order->branch)->legal_footer,
            'operator_name'         => optional($order->user)->name,
            'created_at'            => $order->created_at?->toIso8601String(),
        ];
    }
}
```

(Ce service est optionnel pour V1 mais facilite l'intégration future T15 — la résource fait déjà le travail pour le rendu HTML.)

### 5. Helper FE `posReceiptBuilder.js`

Pure functions, faciles à tester ; futures conversions ESC/POS string s'inspireront.

```javascript
/**
 * Format a payment line for the multi-tender breakdown.
 * Backward-compatible with order.pos_payment_method when payments[] is empty.
 */
export function formatPaymentsBreakdown(order) {
    if (Array.isArray(order?.payments_breakdown) && order.payments_breakdown.length > 0) {
        return order.payments_breakdown.map((p) => ({
            method: String(p.method ?? '').toUpperCase(),
            amount: Number(p.amount || 0),
            currency_amount: p.currency_amount ?? null,
            change_amount: Number(p.change_amount || 0),
            reference: p.reference ?? null,
        }));
    }
    if (order?.pos_payment_method) {
        return [{
            method: String(order.pos_payment_method).toUpperCase(),
            amount: Number(order.total || 0),
            currency_amount: order.total_currency_price ?? null,
            change_amount: Number(order.cash_back_amount || 0),
            reference: null,
        }];
    }
    return [];
}

/**
 * Build the NF525 footer block (lines : ticket #, fingerprint, legal mentions).
 */
export function buildNf525Footer(order) {
    const lines = [];
    if (order?.fiscal_sequence_no) lines.push({ key: 'fiscal_ticket_no', value: order.fiscal_sequence_no });
    if (order?.audit_chain_fingerprint) lines.push({ key: 'audit_fingerprint', value: order.audit_chain_fingerprint });
    if (order?.pos_legal_footer) lines.push({ key: 'legal_mentions', value: order.pos_legal_footer });
    return lines;
}

/**
 * Compute the receipt CSS width class from a paper width (in mm).
 * Defaults to 58mm if unknown.
 */
export function receiptWidthClass(paperWidthMm) {
    const w = Number(paperWidthMm || 58);
    if (w >= 76) return 'receipt-80mm';
    return 'receipt-58mm';
}
```

### 6. `ReceiptComponent.vue` (EDIT — extension structure + CSS)

Modifications ciblées, **non destructrices** :
- Garder `id="print"`, `printObj`, `v-print`, le wrapper `modal-dialog`, la modale Bootstrap
- Remplacer `max-w-[340px]` par `:class="receiptWidthClass(paperWidthMm)"` lié à un computed (utiliser le helper). Default 58mm.
- Ajouter en-tête (au-dessus du nom société) : SIRET / TVA intra / n° caisse / opérateur (chaque ligne `v-if` la valeur exposée par `order`)
- Modifier le bloc paiement : itérer `formatPaymentsBreakdown(order)` et afficher chaque ligne avec son montant. Si une seule entrée + change_amount > 0, afficher cash + change comme aujourd'hui (backward compat visuel).
- Ajouter footer NF525 avant "thank you" : itérer `buildNf525Footer(order)` et afficher chaque clé avec sa traduction.
- CSS : ajouter classes `.receipt-58mm { width: 58mm; }` `.receipt-80mm { width: 80mm; }` dans `@media print` ET en preview écran (avec un `min-width` adapté).

### 7. i18n — 8 clés exactes dans fr.json / en.json / ar.json

```json
{
  "label": {
    "siret": "SIRET",
    "vat_intra": "TVA intra",
    "register_id": "N° caisse",
    "operator": "Opérateur",
    "fiscal_ticket_no": "N° ticket NF525",
    "audit_fingerprint": "Empreinte audit",
    "legal_mentions": "Mentions légales",
    "tendered_breakdown": "Détail paiement"
  }
}
```
EN : `Cashier ID`, `Operator`, `Receipt #`, `Audit fingerprint`, `Legal notice`, `Payment breakdown`, etc. (traduction conventionnelle)
AR : équivalents arabes (consulter les fichiers existants pour le ton)

### 8. Tests Feature

`tests/Feature/Branch/BranchFiscalIdentityTest.php` 3 cas :
1. Branch fillable accepte siret/vat_intra/register_id/legal_footer ; create + retrieve.
2. Migration up/down idempotente : up→down→up sans erreur.
3. Branch sans ces champs (legacy) → ils sont nuls, pas de crash.

`tests/Feature/PosReceiptFiscalExposureTest.php` 4 cas :
1. `OrderDetailsResource` expose `fiscal_sequence_no`, `pos_siret`, `pos_register_id`, `operator_name`, `payments_breakdown` quand l'order + branche les ont.
2. Sur une order legacy (sans `fiscal_sequence_no`), la resource expose `null` au lieu de crasher.
3. `audit_chain_fingerprint` = chaîne 12 chars hex si `audit_logs` contient un log signé pour l'order.
4. `audit_chain_fingerprint` = `null` si aucun audit log.

### 9. Tests Vitest helper

`tests/js/posReceiptBuilder.spec.js` 5 cas :
1. `formatPaymentsBreakdown` retourne array depuis `payments_breakdown` quand présent.
2. `formatPaymentsBreakdown` fallback sur `pos_payment_method` quand `payments_breakdown` vide → 1 entrée.
3. `formatPaymentsBreakdown` retourne `[]` si rien.
4. `buildNf525Footer` retourne lignes pour fiscal_sequence_no + fingerprint + legal_footer présents.
5. `receiptWidthClass(58)` → `'receipt-58mm'` ; `receiptWidthClass(80)` → `'receipt-80mm'` ; `receiptWidthClass(undefined)` → `'receipt-58mm'`.

### 10. Régression

```bash
php artisan migrate
php artisan test --filter='Branch|Receipt|Pos|Order'
npx vitest run tests/js/posReceiptBuilder.spec.js tests/js/PosComponent.spec.js
```
→ Tous verts. Les 3 échecs préexistants documentés (DispatchAfterCommit, AllergenSnapshot) tolérés.

## ACCEPTANCE

- [ ] Migration ajoute 4 colonnes nullable + rollback OK
- [ ] `Branch::$fillable` étendu sans casser tests existants
- [ ] `OrderDetailsResource` expose 8 nouveaux champs (4 branche + 4 commande/operator/payments)
- [ ] `audit_chain_fingerprint` = 12 chars hex dérivés sans exposer la signature complète ni la clé
- [ ] `ReceiptComponent.vue` : NF525 footer rendu, multi-tender rendu, variant 58/80mm avec helper
- [ ] Helper `posReceiptBuilder.js` : 3 fonctions pures, 5/5 tests verts
- [ ] 3/3 Feature `BranchFiscalIdentityTest`
- [ ] 4/4 Feature `PosReceiptFiscalExposureTest`
- [ ] 5/5 Vitest `posReceiptBuilder.spec.js`
- [ ] **AUCUNE écriture** dans audit_logs / FiscalSequenceService / OrderService / PricingService / PaymentService (lecture seule)
- [ ] i18n 8 clés exactes ajoutées dans fr/en/ar
- [ ] 0 régression sur `php artisan test --filter='Pos|Order|Pricing|Receipt'` (3 préexistants tolérés)
- [ ] Tests Vitest existants (`PosComponent.spec.js` etc.) restent verts

## NON-GOALS (explicite)

- **PAS** d'intégration ESC/POS dans T21 (reste HTML preview + browser print) ; T15 fournira le pont ESC/POS dans un cycle ultérieur
- **PAS** de modification de `OrderService`, `PaymentService`, `PricingService`, `FiscalSequenceService`, `AuditLogService` (lecture seule sur audit_logs)
- **PAS** de création de relation `payments()` si elle n'existe pas (fallback sur pos_payment_method)
- **PAS** de touch sur `PosOrderReceiptComponent.vue` (variante alternative, hors scope)
- **PAS** de modification du `kioskPrinter.js` (Kiosk Electron, hors scope)
- **PAS** de modification de `PosComponent.vue`, `PaymentComponent.vue`, `ItemComponent.vue`
- **PAS** de hash chain mining ou validation côté FE (déjà côté backend)

## REPORT_FILE

`reports/execution/RUN_V14_T21_POS_RECEIPT_REDESIGN_2026-04-20.md`
