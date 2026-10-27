<?php

namespace App\Http\Requests\Organizer;

use App\Enums\TicketTypeStatus;
use App\Models\Event;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Ticket type details. The price is a decimal string in the event's
 * currency ("850" or "850.00"); sales windows are wall-clock times in the
 * event's timezone.
 */
class TicketTypeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $datetime = 'date_format:'.LocalTime::INPUT_FORMAT;

        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'quantity_total' => ['required', 'integer', 'min:1', 'max:1000000'],
            'min_per_order' => ['required', 'integer', 'min:1', 'max:100'],
            'max_per_order' => ['required', 'integer', 'min:1', 'max:100', 'gte:min_per_order'],
            'sales_starts_at' => ['nullable', $datetime],
            'sales_ends_at' => ['nullable', $datetime],
            'status' => ['required', Rule::enum(TicketTypeStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price.regex' => 'Enter the price as a number, e.g. 850 or 850.50. Use 0 for free tickets.',
            'max_per_order.gte' => 'The maximum per order must be at least the minimum.',
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

                $start = $this->utc('sales_starts_at');
                $end = $this->utc('sales_ends_at');

                if ($start !== null && $end !== null && $end->lte($start)) {
                    $validator->errors()->add('sales_ends_at', 'Sales must end after they start.');
                }
            },
        ];
    }

    /**
     * @return array{name: string, description: string|null, price: string, quantity_total: int, min_per_order: int, max_per_order: int, sales_starts_at: CarbonImmutable|null, sales_ends_at: CarbonImmutable|null, status: TicketTypeStatus}
     */
    public function toData(): array
    {
        return [
            'name' => trim($this->string('name')->toString()),
            'description' => $this->filled('description') ? $this->string('description')->toString() : null,
            'price' => $this->string('price')->toString(),
            'quantity_total' => $this->integer('quantity_total'),
            'min_per_order' => $this->integer('min_per_order'),
            'max_per_order' => $this->integer('max_per_order'),
            'sales_starts_at' => $this->utc('sales_starts_at'),
            'sales_ends_at' => $this->utc('sales_ends_at'),
            'status' => TicketTypeStatus::from($this->string('status')->toString()),
        ];
    }

    private function event(): Event
    {
        $event = $this->route('managedEvent');

        abort_unless($event instanceof Event, 404);

        return $event;
    }

    private function utc(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return LocalTime::toUtc($value, $this->event()->timezone);
    }
}
