<?php

namespace App\Http\Requests\Organizer;

use App\Actions\Events\EventData;
use App\Enums\EventVisibility;
use App\Http\Requests\Concerns\NormalizesLocalTimes;
use App\Models\Category;
use App\Support\Currency;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Event details as typed by the organiser. Times arrive as wall-clock
 * values in the event's own timezone and are converted to UTC here.
 * Authorisation happens in the SaveEvent action.
 */
class EventRequest extends FormRequest
{
    public const MAX_IMAGE_KB = 8192;

    use NormalizesLocalTimes;

    /**
     * @return list<string>
     */
    protected function localTimeFields(): array
    {
        return ['starts_at', 'ends_at', 'sales_starts_at', 'sales_ends_at'];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $datetime = 'date_format:'.LocalTime::INPUT_FORMAT;

        return [
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', 'string', Rule::exists('categories', 'slug')],
            'summary' => ['nullable', 'string', 'max:280'],
            'description' => ['nullable', 'string', 'max:10000'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3', Rule::in(Currency::allowed())],
            'starts_at' => ['required', $datetime],
            'ends_at' => ['required', $datetime],
            'sales_starts_at' => ['nullable', $datetime],
            'sales_ends_at' => ['nullable', $datetime],
            'visibility' => ['required', Rule::enum(EventVisibility::class)],
            'refund_policy' => ['nullable', 'string', 'max:5000'],
            'min_age' => ['nullable', 'integer', 'min:0', 'max:21'],
            'venue.name' => ['required', 'string', 'max:150'],
            'venue.address_line' => ['required', 'string', 'max:255'],
            'venue.city' => ['required', 'string', 'max:100'],
            'venue.region' => ['nullable', 'string', 'max:100'],
            'venue.postal_code' => ['nullable', 'string', 'max:20'],
            'venue.country_code' => ['required', 'string', 'size:2', 'alpha'],
            // Photos can be added when creating; afterwards they are managed
            // on the event's own image endpoints.
            'images' => ['sometimes', 'array', 'max:'.self::maxImages()],
            'images.*' => self::imageRules(),
        ];
    }

    /**
     * Rules for one uploaded event photo. SVG is not accepted (the image
     * rule excludes it), and every file is re-encoded after validation.
     *
     * @return list<string>
     */
    public static function imageRules(): array
    {
        return [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:'.self::MAX_IMAGE_KB,
            'dimensions:min_width=600,min_height=300',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function imageMessages(): array
    {
        return [
            'images.max' => 'You can add up to :max images.',
            'images.*.uploaded' => 'An image failed to upload. It may be bigger than the server allows.',
            'images.*.file' => 'An image failed to upload. Please try again.',
            'images.*.image' => 'Upload JPG, PNG or WebP images.',
            'images.*.mimes' => 'Upload JPG, PNG or WebP images.',
            'images.*.max' => 'Each image must be 8 MB or smaller.',
            'images.*.dimensions' => 'Images must be at least 600 × 300 pixels.',
        ];
    }

    public static function maxImages(): int
    {
        $max = config('tickora.max_event_images', 8);

        return is_numeric($max) && (int) $max > 0 ? (int) $max : 8;
    }

    /**
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $files = $this->file('images');

        return is_array($files) ? array_values($files) : [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_format' => 'Choose a valid date and time for the :attribute.',
            ...self::imageMessages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'starts_at' => 'start time',
            'ends_at' => 'end time',
            'sales_starts_at' => 'sales start',
            'sales_ends_at' => 'sales end',
            'venue.name' => 'venue name',
            'venue.address_line' => 'address',
            'venue.city' => 'city',
            'venue.country_code' => 'country',
            'min_age' => 'minimum age',
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

                $starts = $this->utc('starts_at');
                $ends = $this->utc('ends_at');
                $salesStart = $this->utc('sales_starts_at');
                $salesEnd = $this->utc('sales_ends_at');

                if ($starts !== null && $ends !== null && $ends->lte($starts)) {
                    $validator->errors()->add('ends_at', 'The event must end after it starts.');
                }

                if ($salesStart !== null && $salesEnd !== null && $salesEnd->lte($salesStart)) {
                    $validator->errors()->add('sales_ends_at', 'Sales must end after they start.');
                }

                if ($salesEnd !== null && $ends !== null && $salesEnd->gt($ends)) {
                    $validator->errors()->add('sales_ends_at', 'Sales must end before the event does.');
                }
            },
        ];
    }

    public function toData(): EventData
    {
        $timezone = $this->string('timezone')->toString();
        $category = Category::query()->where('slug', $this->string('category')->toString())->firstOrFail();

        return new EventData(
            title: trim($this->string('title')->toString()),
            categoryId: $category->id,
            summary: $this->filled('summary') ? trim($this->string('summary')->toString()) : null,
            description: $this->filled('description') ? $this->string('description')->toString() : null,
            timezone: $timezone,
            currency: strtoupper($this->string('currency')->toString()),
            startsAt: $this->utc('starts_at') ?? CarbonImmutable::now(),
            endsAt: $this->utc('ends_at') ?? CarbonImmutable::now(),
            salesStartsAt: $this->utc('sales_starts_at'),
            salesEndsAt: $this->utc('sales_ends_at'),
            visibility: EventVisibility::from($this->string('visibility')->toString()),
            refundPolicy: $this->filled('refund_policy') ? $this->string('refund_policy')->toString() : null,
            minAge: $this->filled('min_age') ? $this->integer('min_age') : null,
            venue: [
                'name' => trim($this->string('venue.name')->toString()),
                'address_line' => trim($this->string('venue.address_line')->toString()),
                'city' => trim($this->string('venue.city')->toString()),
                'country_code' => strtoupper($this->string('venue.country_code')->toString()),
                'region' => $this->filled('venue.region') ? $this->string('venue.region')->toString() : null,
                'postal_code' => $this->filled('venue.postal_code') ? $this->string('venue.postal_code')->toString() : null,
            ],
        );
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
