<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\Concerns\NormalizesLocalTimes;
use App\Models\Event;
use App\Models\Organizer;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * A new promo code. Validity times are wall-clock times in the timezone
 * the organiser's browser reports, converted to UTC here.
 */
class PromoCodeRequest extends FormRequest
{
    use NormalizesLocalTimes;

    /**
     * @return list<string>
     */
    protected function localTimeFields(): array
    {
        return ['starts_at', 'ends_at'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $datetime = 'date_format:'.LocalTime::INPUT_FORMAT;

        return [
            'code' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_type' => ['required', 'in:percent,fixed'],
            'percent' => ['required_if:discount_type,percent', 'nullable', 'numeric', 'min:0.01', 'max:100'],
            'amount' => ['required_if:discount_type,fixed', 'nullable', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'event' => ['nullable', 'string', 'max:26'],
            'starts_at' => ['nullable', $datetime],
            'ends_at' => ['nullable', $datetime],
            'timezone' => ['required', 'timezone:all'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'max_per_customer' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_format' => 'Choose a valid date and time for the :attribute.',
            'code.regex' => 'Use letters, numbers, dashes and underscores only.',
            'amount.regex' => 'Enter the amount as a number, e.g. 100 or 99.50.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $start = $this->utc('starts_at');
                $end = $this->utc('ends_at');

                if ($start !== null && $end !== null && $end->lte($start)) {
                    $validator->errors()->add('ends_at', 'The code must stop working after it starts.');
                }
            },
        ];
    }

    /**
     * @return array{code: string, discount_type: string, percent: string|null, amount: string|null, event: Event|null, starts_at: CarbonImmutable|null, ends_at: CarbonImmutable|null, max_redemptions: int|null, max_per_customer: int|null}
     */
    public function toData(Organizer $organizer): array
    {
        $event = null;

        if ($this->filled('event')) {
            $event = Event::query()
                ->forOrganizer($organizer)
                ->where('public_id', $this->string('event')->toString())
                ->first();

            if ($event === null) {
                throw ValidationException::withMessages(['event' => 'Choose one of your events.']);
            }
        }

        return [
            'code' => $this->string('code')->toString(),
            'discount_type' => $this->string('discount_type')->toString(),
            'percent' => $this->filled('percent') ? (string) $this->input('percent') : null,
            'amount' => $this->filled('amount') ? $this->string('amount')->toString() : null,
            'event' => $event,
            'starts_at' => $this->utc('starts_at'),
            'ends_at' => $this->utc('ends_at'),
            'max_redemptions' => $this->filled('max_redemptions') ? $this->integer('max_redemptions') : null,
            'max_per_customer' => $this->filled('max_per_customer') ? $this->integer('max_per_customer') : null,
        ];
    }

    private function utc(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return LocalTime::toUtc($value, $this->string('timezone')->toString());
    }
}
