<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

class ValidJsonOrder implements Rule
{
    public $message = '';
    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {

        $requestItems = json_decode($value, true);
        if (!is_array($requestItems) || count($requestItems) == 0) {
            $this->message = 'The :attribute must be a valid JSON array and cannot be empty.';
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