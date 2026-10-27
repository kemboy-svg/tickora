<?php

namespace App\Http\Requests\Organizer;

use App\Support\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OnboardingRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'country_code' => ['required', 'string', 'size:2', 'alpha'],
            'default_currency' => ['required', 'string', Rule::in(Currency::allowed())],
            'description' => ['nullable', 'string', 'max:2000'],
            'support_email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array{name: string, country_code: string, default_currency: string, description: string|null, support_email: string|null}
     */
    public function toData(): array
    {
        return [
            'name' => trim($this->string('name')->toString()),
            'country_code' => strtoupper($this->string('country_code')->toString()),
            'default_currency' => $this->string('default_currency')->toString(),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'support_email' => $this->filled('support_email') ? $this->string('support_email')->toString() : null,
        ];
    }
}
