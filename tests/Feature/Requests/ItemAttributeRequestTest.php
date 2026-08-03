<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\ItemAttributeRequest;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ItemAttributeRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_product_composer_selection_constraints(): void
    {
        $payload = [
            'name' => 'Viandes premium',
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
            'status' => Status::ACTIVE,
        ];

        $validator = Validator::make($payload, (new ItemAttributeRequest)->rules());

        $this->assertFalse(
            $validator->fails(),
            json_encode($validator->errors()->toArray(), JSON_PRETTY_PRINT)
        );
    }

    public function test_rejects_max_select_below_min_select(): void
    {
        $payload = [
            'name' => 'Viandes premium',
            'min_select' => 3,
            'max_select' => 2,
            'allow_repeat' => false,
            'status' => Status::ACTIVE,
        ];

        $validator = Validator::make($payload, (new ItemAttributeRequest)->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('max_select', $validator->errors()->toArray());
    }
}
