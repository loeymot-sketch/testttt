<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;
use Smartisan\Settings\Facades\Settings;
use PragmaRX\Countries\Package\Countries;

class ValidPhone implements Rule
{
    public $message = '';
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct() {}

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        $raw = trim((string) $value);

        // [Sprint 5A Z9-P0-02] Reject Sprint 2B legacy sentinel placeholders.
        if (str_starts_with($raw, 'PENDING_')) {
            $this->message = 'The :attribute is a legacy placeholder. Please update the profile with a real phone number.';

            return false;
        }

        // [Sprint 5A Z9-P0-01] Strict E.164 path when the caller used the
        // explicit international prefix. The previous rule only counted digits
        // (8..15) which let synthetic sequences like 12345678 pass — the
        // Sprint 2B commit subject claimed "E.164 required" without backing it.
        if (str_starts_with($raw, '+')) {
            if (!preg_match('/^\+[1-9]\d{7,14}$/', $raw)) {
                $this->message = 'The :attribute must be a valid E.164 international number (e.g. +33612345678).';

                return false;
            }

            return true;
        }

        $digitsOnly = preg_replace('/\D+/', '', $raw);
        $len        = strlen($digitsOnly);

        // National format: tighten minimum to 9 digits to reject obvious 8-digit
        // test sequences while accepting all valid FR national numbers (9-10).
        $expected = (int) Settings::group('site')->get('site_default_phone_digit_length');
        $minLen   = max(9, $expected > 0 ? $expected - 1 : 9);
        $maxLen   = min(15, $expected > 0 ? $expected + 2 : 15);

        if ($len < $minLen || $len > $maxLen) {
            $this->message = 'The :attribute must be between ' . $minLen . ' and ' . $maxLen . ' digits (national number, without country code), or use the international + prefix (e.g. +33612345678).';

            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->message;
    }
}