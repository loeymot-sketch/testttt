# V14 #10 — T15 — `P14_HARDWARE_PRINTER_ESC_POS`

## Header

```
TASK_ID: V14_10_T15_HARDWARE_PRINTER_ESC_POS
WAVE: C-β — Finalisation caisse opérateur (sub-vague β)
GATE_REFERENCE: aucun (surface neuve, pas de zone gelée NF525, OrderService, PaymentService)
PRIMARY_MODEL: GPT-5.4 (foodking-complex-implementer)
RUNNER_MODE: single-session
PARALLEL_WITH: V14_11_T19_POS_TABLE_FLOORPLAN, V14_12_T21_POS_RECEIPT_REDESIGN
DEPENDS_ON: aucun
SEVERITY: P0
EFFORT_EST: 1 j
```

## Contexte

La caisse réelle FoodKing nécessite une imprimante thermique 80mm ESC/POS (réseau ou USB) pour fonctionner en restaurant. Aujourd'hui le POS web n'a **aucun service backend** dédié à l'impression (`grep` confirme : 0 fichier dans `app/Services/Hardware/`, 0 migration `printers`, 0 modèle `Printer`).

**Existant à NE PAS dupliquer** :
- `resources/js/helpers/kioskPrinter.js` (332 LOC) : helper ESC/POS **côté Kiosk Electron** — utilisable comme **référence** pour le builder de commandes ESC/POS, mais ne couvre PAS le POS web sans Electron bridge.
- `resources/js/services/kioskHardware.js` : pont Electron `window.borne` — **hors scope POS web**.
- `PaymentComponent.vue` : appelle déjà `openDrawer()` via `kioskHardware` pour le tiroir caisse — **NE PAS y toucher** (frozen + territoire indirect T17/C9).

T15 livre une **surface neuve** : un service backend ESC/POS qui parle TCP socket port 9100 (mode réseau standard imprimantes thermiques), une table `printers` de configuration par branche, un helper frontend `posPrinter.js` qui appelle le backend (mode SAAS multi-tenant), et une intégration **différée** (la liaison "auto-print après paiement" sera faite en T22 E2E ou dans un cycle ultérieur après validation manuelle imprimante réelle).

## SUBSYSTEMS_TOUCHED

- `database/migrations/2026_04_20_xxxxxx_create_printers_table.php` (CREATE)
- `app/Models/Printer.php` (CREATE)
- `app/Services/Hardware/EscPosPrinterService.php` (CREATE — service principal)
- `app/Services/Hardware/EscPosCommandBuilder.php` (CREATE — builder commandes ESC/POS)
- `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php` (CREATE — transport TCP socket)
- `app/Services/Hardware/PrinterTransport/PrinterTransportInterface.php` (CREATE — interface pour mock testabilité)
- `app/Services/Hardware/PrinterTransport/NullPrinterTransport.php` (CREATE — fallback noop pour CI/dev sans imprimante)
- `app/Http/Controllers/Admin/PrinterController.php` (CREATE — CRUD config + endpoint test print)
- `app/Http/Requests/Admin/PrinterRequest.php` (CREATE — validation)
- `app/Http/Resources/PrinterResource.php` (CREATE)
- `routes/api.php` (EDIT minimal — bloc routes `printers/*` sous middleware admin)
- `resources/js/services/posPrinter.js` (CREATE — helper FE qui POST au backend)
- `tests/Feature/PrinterServiceTest.php` (CREATE — 6 cas avec NullTransport)
- `tests/Feature/PrinterControllerTest.php` (CREATE — 4 cas CRUD)
- `tests/js/posPrinter.spec.js` (CREATE — 3 cas helper FE)

## SUBSYSTEMS_OFF_LIMITS

- `app/Services/OrderService.php`, `FrontendOrderService.php` (LOCK_B frozen)
- `app/Services/Pricing/PricingService.php` (frozen — pricing SSOT)
- `app/Services/PaymentService.php` (frozen — gate C9 dispatch-after-commit pendant)
- `app/Services/Fiscal/*` (frozen — chaîne audit NF525)
- `resources/js/components/admin/pos/PaymentComponent.vue` (frozen — territoire idempotency T17)
- `resources/js/components/admin/pos/ReceiptComponent.vue` (territoire T21 en parallèle)
- `resources/js/components/admin/pos/PosComponent.vue` (territoire T19 en parallèle)
- `resources/js/services/kioskHardware.js` (Electron Kiosk, hors scope POS web)
- `resources/js/helpers/kioskPrinter.js` (Kiosk, lecture seule pour référence)

## INVARIANTS_AT_RISK

1. **Multi-tenant strict** : config `printers` scopée `branch_id` ; impossible de lire/imprimer sur une imprimante d'une autre branche. BranchScope obligatoire sur le modèle.
2. **Pas d'effet sur les commandes / paiements** : T15 = surface neuve. Aucun appel à OrderService, PaymentService, PricingService, FiscalSequenceService.
3. **Testabilité CI** : `EscPosPrinterService` accepte une interface `PrinterTransportInterface` injectée (DIP). En tests, on injecte `NullPrinterTransport` qui capture les bytes envoyés sans socket. Aucun socket réseau ouvert en CI.
4. **Timeout safe** : transport TCP avec `stream_set_timeout` 2s + try/catch. Une imprimante éteinte ne doit JAMAIS bloquer une requête HTTP > 3s.
5. **Pas de binaire stocké en DB** : payload ESC/POS construit à la volée à chaque impression. La DB stocke uniquement la config (ip, port, type, station).
6. **Permissions admin** : routes CRUD imprimantes derrière middleware `admin` + permission `printer.manage` (à vérifier dans le système de perms existant ; sinon middleware `auth:admin` minimum).
7. **Pas de secret en clair** : si une imprimante demande auth (rare en ESC/POS pur, mais possible via proxy), stocker en `encrypted` cast Laravel.
8. **Migration idempotente** + rollback `dropIfExists`.
9. **i18n** : ajouter UNIQUEMENT les clés UI nouvelles (config printer label, test print button, error messages) dans `fr.json` / `en.json` / `ar.json`. Aucune autre modification i18n.

## TÂCHES À EXÉCUTER

### 1. Migration `printers`

```php
Schema::create('printers', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('branch_id');
    $table->string('name', 80);                                  // ex: "Caisse 1 — bar"
    $table->string('type', 16)->default('escpos_tcp');           // escpos_tcp | escpos_usb (futur) | browser_html
    $table->string('host', 64)->nullable();                      // IP ou hostname
    $table->unsignedSmallInteger('port')->default(9100);
    $table->string('station', 32)->nullable();                   // 'receipt' | 'kitchen_hot' | 'kitchen_cold' | 'bar'
    $table->unsignedTinyInteger('width_chars')->default(48);     // 80mm = 48 chars, 58mm = 32 chars
    $table->unsignedTinyInteger('status')->default(1);           // 1=ACTIVE, 0=INACTIVE
    $table->json('options')->nullable();                         // codepage, density, cut, etc.
    $table->timestamps();

    $table->index(['branch_id', 'status'], 'printers_branch_status_idx');
    $table->index(['branch_id', 'station'], 'printers_branch_station_idx');
    $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
});
```

`down()` : `Schema::dropIfExists('printers');`

### 2. Modèle `Printer`

```php
namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;

class Printer extends Model
{
    protected $table = 'printers';
    protected $fillable = [
        'branch_id', 'name', 'type', 'host', 'port',
        'station', 'width_chars', 'status', 'options',
    ];
    protected $casts = [
        'id'          => 'integer',
        'branch_id'   => 'integer',
        'port'        => 'integer',
        'width_chars' => 'integer',
        'status'      => 'integer',
        'options'     => 'array',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::addGlobalScope(new BranchScope());
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
```

### 3. Interface `PrinterTransportInterface`

```php
namespace App\Services\Hardware\PrinterTransport;

interface PrinterTransportInterface
{
    /**
     * Send raw ESC/POS bytes to the physical device.
     * Must NEVER block more than ~2s. Returns true if accepted, false on failure.
     */
    public function send(string $bytes, array $config): bool;

    /** Last error string for diagnostics. */
    public function lastError(): ?string;
}
```

### 4. `TcpPrinterTransport` (production)

```php
final class TcpPrinterTransport implements PrinterTransportInterface
{
    private ?string $lastError = null;

    public function send(string $bytes, array $config): bool
    {
        $host = $config['host'] ?? null;
        $port = (int) ($config['port'] ?? 9100);
        if (! $host) { $this->lastError = 'missing_host'; return false; }

        $errno = 0; $errstr = '';
        $sock = @fsockopen($host, $port, $errno, $errstr, 2.0);
        if (! $sock) {
            $this->lastError = "tcp_open_failed: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($sock, 2);
        $written = @fwrite($sock, $bytes);
        @fclose($sock);
        if ($written === false || $written < strlen($bytes)) {
            $this->lastError = 'tcp_write_partial';
            return false;
        }
        return true;
    }

    public function lastError(): ?string { return $this->lastError; }
}
```

### 5. `NullPrinterTransport` (tests + dev sans imprimante)

```php
final class NullPrinterTransport implements PrinterTransportInterface
{
    public array $sent = [];
    public function send(string $bytes, array $config): bool
    {
        $this->sent[] = ['bytes' => $bytes, 'config' => $config];
        return true;
    }
    public function lastError(): ?string { return null; }
}
```

### 6. `EscPosCommandBuilder` (s'inspirer de `kioskPrinter.js` lignes ~30-200 pour les commandes ESC/POS)

```php
namespace App\Services\Hardware;

final class EscPosCommandBuilder
{
    public const ESC = "\x1B";
    public const GS  = "\x1D";
    public const LF  = "\x0A";

    public static function init(): string         { return self::ESC . '@'; }
    public static function alignLeft(): string    { return self::ESC . 'a' . "\x00"; }
    public static function alignCenter(): string  { return self::ESC . 'a' . "\x01"; }
    public static function alignRight(): string   { return self::ESC . 'a' . "\x02"; }
    public static function bold(bool $on): string { return self::ESC . 'E' . ($on ? "\x01" : "\x00"); }
    public static function doubleSize(bool $on): string { return self::GS . '!' . ($on ? "\x11" : "\x00"); }
    public static function feed(int $lines = 1): string { return str_repeat(self::LF, max(1, $lines)); }
    public static function cut(): string          { return self::GS . 'V' . "\x00"; }
    public static function openDrawer(): string   { return self::ESC . 'p' . "\x00\x19\xFA"; } // pin 2

    /**
     * Build a generic receipt block of "label .... value" padded on `widthChars` columns.
     */
    public static function lineKV(string $label, string $value, int $widthChars = 48): string
    {
        $maxLabel = max(1, $widthChars - strlen($value) - 1);
        $label = mb_substr($label, 0, $maxLabel);
        $padding = $widthChars - mb_strlen($label) - mb_strlen($value);
        return $label . str_repeat(' ', max(1, $padding)) . $value . self::LF;
    }
}
```

### 7. `EscPosPrinterService`

```php
namespace App\Services\Hardware;

use App\Models\Printer;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Support\Facades\Log;

final class EscPosPrinterService
{
    public function __construct(private readonly PrinterTransportInterface $transport) {}

    /**
     * Send a pre-built ESC/POS payload to the given printer.
     */
    public function sendRaw(Printer $printer, string $bytes): bool
    {
        $config = [
            'host' => $printer->host,
            'port' => $printer->port,
        ];
        $ok = $this->transport->send($bytes, $config);
        if (! $ok) {
            Log::warning('[EscPosPrinterService] print failed', [
                'printer_id' => $printer->id,
                'branch_id'  => $printer->branch_id,
                'error'      => $this->transport->lastError(),
            ]);
        }
        return $ok;
    }

    /** Convenience : test print page (header + "OK" + cut). */
    public function testPrint(Printer $printer): bool
    {
        $b = '';
        $b .= EscPosCommandBuilder::init();
        $b .= EscPosCommandBuilder::alignCenter();
        $b .= EscPosCommandBuilder::bold(true);
        $b .= "FOODKING POS\n";
        $b .= EscPosCommandBuilder::bold(false);
        $b .= "Test print OK\n";
        $b .= EscPosCommandBuilder::lineKV('Printer', $printer->name, $printer->width_chars ?? 48);
        $b .= EscPosCommandBuilder::lineKV('Date', now()->toDateTimeString(), $printer->width_chars ?? 48);
        $b .= EscPosCommandBuilder::feed(3);
        $b .= EscPosCommandBuilder::cut();
        return $this->sendRaw($printer, $b);
    }

    public function openDrawer(Printer $printer): bool
    {
        return $this->sendRaw($printer, EscPosCommandBuilder::init() . EscPosCommandBuilder::openDrawer());
    }
}
```

Bind in a service provider OR directly via container resolution :

```php
// app/Providers/AppServiceProvider.php register()
$this->app->bind(\App\Services\Hardware\PrinterTransport\PrinterTransportInterface::class, function ($app) {
    if (app()->environment('testing')) {
        return new \App\Services\Hardware\PrinterTransport\NullPrinterTransport();
    }
    return new \App\Services\Hardware\PrinterTransport\TcpPrinterTransport();
});
```

### 8. Controller + routes

```php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PrinterRequest;
use App\Http\Resources\PrinterResource;
use App\Models\Printer;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Http\Request;

class PrinterController extends Controller
{
    public function __construct(private readonly EscPosPrinterService $printer) {}

    public function index(Request $request)
    {
        $list = Printer::orderBy('id', 'desc')->paginate((int) $request->get('per_page', 20));
        return PrinterResource::collection($list);
    }
    public function store(PrinterRequest $request)
    {
        $branchId = (int) ($request->user()->branch_id ?? $request->validated('branch_id'));
        $data = $request->validated();
        $data['branch_id'] = $branchId;
        return new PrinterResource(Printer::create($data));
    }
    public function show(Printer $printer)  { return new PrinterResource($printer); }
    public function update(PrinterRequest $request, Printer $printer)
    {
        $printer->update($request->validated());
        return new PrinterResource($printer);
    }
    public function destroy(Printer $printer)
    {
        $printer->delete();
        return response()->noContent();
    }

    public function testPrint(Printer $printer)
    {
        $ok = $this->printer->testPrint($printer);
        return response()->json(['ok' => $ok], $ok ? 200 : 502);
    }
}
```

Routes (sous middleware `auth:admin` ou équivalent existant) :
```php
Route::prefix('printers')->group(function () {
    Route::get('/',           [PrinterController::class, 'index']);
    Route::post('/',          [PrinterController::class, 'store']);
    Route::get('/{printer}',  [PrinterController::class, 'show']);
    Route::put('/{printer}',  [PrinterController::class, 'update']);
    Route::delete('/{printer}', [PrinterController::class, 'destroy']);
    Route::post('/{printer}/test-print', [PrinterController::class, 'testPrint']);
});
```

### 9. Helper FE `posPrinter.js`

```javascript
import axios from 'axios';

/**
 * Fire-and-forget test print on a configured printer.
 * Returns { ok: boolean, status: number, error?: string }.
 */
export async function testPrint(printerId) {
    try {
        const { data, status } = await axios.post(`/api/admin/printers/${printerId}/test-print`);
        return { ok: !!(data && data.ok), status };
    } catch (e) {
        return {
            ok: false,
            status: e?.response?.status || 0,
            error: e?.response?.data?.message || e?.message || 'unknown',
        };
    }
}

/** List configured printers for current branch. */
export async function listPrinters() {
    const { data } = await axios.get('/api/admin/printers');
    return Array.isArray(data?.data) ? data.data : (Array.isArray(data) ? data : []);
}
```

### 10. Tests

**`tests/Feature/PrinterServiceTest.php`** — 6 cas avec `NullPrinterTransport` :
1. `testPrint()` retourne true et le NullTransport capture des bytes commençant par `ESC@`.
2. `testPrint()` capture bien le nom de l'imprimante dans les bytes.
3. `openDrawer()` envoie bien la séquence `ESC p 0`.
4. `sendRaw()` propage `false` si transport échoue (NullTransport variant qui retourne false).
5. Multi-tenant : Printer scoped sur branch_id (BranchScope) — query Printer en tant qu'opérateur branche A ne retourne pas la branche B.
6. Width chars 32 vs 48 produit bien des paddings différents dans `lineKV`.

**`tests/Feature/PrinterControllerTest.php`** — 4 cas :
1. POST création imprimante (admin) → 201 + DB row.
2. GET index retourne les imprimantes de la branche courante uniquement.
3. POST `/test-print/{id}` → 200 si NullTransport ok.
4. PUT update station → DB updated.

**`tests/js/posPrinter.spec.js`** — 3 cas :
1. `testPrint(id)` POST sur la bonne URL et parse `data.ok` true.
2. `testPrint(id)` retourne `{ok:false}` sur 502.
3. `listPrinters()` parse `data.data` (Laravel resource collection wrapper).

### 11. Régression

```bash
php artisan migrate
php artisan test --filter='Printer'
php artisan test --filter='Pos|Order|Pricing'   # confirme aucune régression
npx vitest run tests/js/posPrinter.spec.js tests/js/PosComponent.spec.js
```
→ Tous verts. Les 3 échecs préexistants documentés (DispatchAfterCommit, AllergenSnapshot) restent acceptables et hors scope.

## ACCEPTANCE

- [ ] Migration `printers` exécutée en local + rollback OK
- [ ] 6/6 tests `PrinterServiceTest` verts (NullTransport)
- [ ] 4/4 tests `PrinterControllerTest` verts
- [ ] 3/3 tests Vitest `posPrinter.spec.js` verts
- [ ] `php artisan test --filter='Pos|Order|Pricing'` — 0 NOUVELLE régression introduite (les 3 préexistants tolérés)
- [ ] `EscPosPrinterService::testPrint($printer)` callable depuis tinker en local sans crasher
- [ ] `BranchScope` bien actif sur Printer (test sentinel multi-tenant inclus)
- [ ] Aucune dépendance externe ESC/POS lib ajoutée (composer.lock inchangé sauf bindings internes) — implémentation pure PHP

## NON-GOALS (explicite)

- **PAS** d'auto-print après paiement dans T15 (deferred to T22 E2E ou cycle ultérieur après validation imprimante réelle)
- **PAS** de support WebUSB direct côté FE (mode SAAS = backend impression, plus simple multi-tenant)
- **PAS** d'intégration KDS automatique (les "tickets cuisine" auront leur propre dispatcher dans un cycle futur)
- **PAS** de modification de PaymentComponent.vue, ReceiptComponent.vue, kioskHardware.js, kioskPrinter.js
- **PAS** de modification de OrderService, PricingService, PaymentService, FiscalSequenceService

## REPORT_FILE

`reports/execution/RUN_V14_T15_HARDWARE_PRINTER_ESC_POS_2026-04-20.md`

À l'arrivée du PASSED, ajouter un bref résumé : statut, schéma DB, endpoints, tests verts (X/X), fichiers modifiés, TODOs résiduels (ex : auto-print différé, integration manuelle imprimante réelle, ESC/POS USB futur).
