<?php

namespace Tests\Feature\Settings;

use App\Http\Requests\CurrencyRequest;
use App\Http\Requests\TaxRequest;
use App\Models\Currency;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * S6-01 — Tax & Currency UPDATE must not self-collide on the unique rule.
 *
 * Root cause: the unique rule ignored a NON-existent route param key
 * (`tax.id` / `currency.id`) instead of the real route-model-bound param
 * (`tax` / `currency`, per routes/api.php `/{tax}` `/{currency}` +
 * `update(TaxRequest, Tax $tax)`), so `ignore(null)` left the current row
 * inside the uniqueness check → every UPDATE returned 422 "code/name already
 * taken" even when the value was unchanged.
 *
 * The "still rejects another row" tests are the guardrail: the fix must not
 * weaken uniqueness, only stop the self-collision.
 */
class TaxCurrencyUpdateUniqueIgnoreTest extends TestCase
{
    use RefreshDatabase;

    private function stubRouteParam(string $key, $model): \Closure
    {
        return fn () => new class($key, $model) {
            public function __construct(public string $k, public $m) {}

            public function parameter($key, $default = null)
            {
                return $key === $this->k ? $this->m : $default;
            }
        };
    }

    public function test_tax_update_with_unchanged_code_does_not_self_collide(): void
    {
        $tax = Tax::create(['name' => 'TVA 20', 'code' => 'TVA20', 'tax_rate' => 20, 'type' => 1, 'status' => 1]);

        $request = TaxRequest::create("/api/admin/setting/tax/{$tax->id}", 'PUT', [
            'name' => 'TVA 20 (renommee)', 'code' => 'TVA20', 'tax_rate' => 20, 'status' => 1,
        ]);
        $request->setRouteResolver($this->stubRouteParam('tax', $tax));

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'Tax UPDATE keeping its own code must pass: ' . json_encode($validator->errors()->all())
        );
    }

    public function test_tax_update_still_rejects_a_code_taken_by_another_row(): void
    {
        Tax::create(['name' => 'A', 'code' => 'AAA', 'tax_rate' => 10, 'type' => 1, 'status' => 1]);
        $b = Tax::create(['name' => 'B', 'code' => 'BBB', 'tax_rate' => 20, 'type' => 1, 'status' => 1]);

        $request = TaxRequest::create("/api/admin/setting/tax/{$b->id}", 'PUT', [
            'name' => 'B', 'code' => 'AAA', 'tax_rate' => 20, 'status' => 1,
        ]);
        $request->setRouteResolver($this->stubRouteParam('tax', $b));

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->passes(), 'Tax B taking Tax A code must still be rejected by the unique rule');
        $this->assertArrayHasKey('code', $validator->errors()->toArray());
    }

    public function test_currency_update_with_unchanged_name_does_not_self_collide(): void
    {
        $currency = Currency::create(['name' => 'Euro', 'symbol' => 'E', 'code' => 'EUR', 'is_cryptocurrency' => 0, 'exchange_rate' => 1]);

        $request = CurrencyRequest::create("/api/admin/setting/currency/{$currency->id}", 'PUT', [
            'name' => 'Euro', 'symbol' => 'E', 'code' => 'EUR', 'is_cryptocurrency' => 0, 'exchange_rate' => 1,
        ]);
        $request->setRouteResolver($this->stubRouteParam('currency', $currency));

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue(
            $validator->passes(),
            'Currency UPDATE keeping its own name must pass: ' . json_encode($validator->errors()->all())
        );
    }

    public function test_currency_update_still_rejects_a_name_taken_by_another_row(): void
    {
        Currency::create(['name' => 'Euro', 'symbol' => 'E', 'code' => 'EUR', 'is_cryptocurrency' => 0, 'exchange_rate' => 1]);
        $b = Currency::create(['name' => 'Dollar', 'symbol' => 'D', 'code' => 'USD', 'is_cryptocurrency' => 0, 'exchange_rate' => 1]);

        $request = CurrencyRequest::create("/api/admin/setting/currency/{$b->id}", 'PUT', [
            'name' => 'Euro', 'symbol' => 'D', 'code' => 'USD', 'is_cryptocurrency' => 0, 'exchange_rate' => 1,
        ]);
        $request->setRouteResolver($this->stubRouteParam('currency', $b));

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertFalse($validator->passes(), 'Currency B taking Currency A name must still be rejected');
        $this->assertArrayHasKey('name', $validator->errors()->toArray());
    }
}
